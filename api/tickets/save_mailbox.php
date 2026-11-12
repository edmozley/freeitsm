<?php
/**
 * API Endpoint: Save (create/update) target mailbox
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/rbac.php';
require_once '../../includes/encryption.php';

// Changes data: never on a GET, which a link on another site can send (csrf.php).
csrfRequireWriteMethod();

header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireModuleAccessJson('tickets');
requireCapabilityJson(Cap::TICKETS_MAILBOXES);   // settings tab — see docs/design/rbac.md

// Get POST data
$data = json_decode(file_get_contents('php://input'), true);

if (!$data) {
    echo json_encode(['success' => false, 'error' => 'Invalid request data']);
    exit;
}

// Validate required fields — tenant ID only required for Microsoft.
// The provider comes from the form: an unknown one is refused (until 4.0 any text was
// stored as given). None at all is Microsoft, as before.
$provider = MailboxProvider::tryFrom((string)($data['provider'] ?? 'microsoft'));
if ($provider === null) {
    echo json_encode(['success' => false, 'error' => 'Unknown mailbox provider']);
    exit;
}
// Basic IMAP authenticates with a username + password (no OAuth), so it needs none
// of the Azure/Google client id/secret/redirect fields.
$isImap = ($provider === MailboxProvider::Imap);
// App-only (client credentials) doesn't use the interactive sign-in flow, so it
// needs no redirect URI / OAuth scopes — only the client id/secret + target mailbox.
$isAppOnly = $provider->supportsAppOnly() && (($data['auth_mode'] ?? 'delegated') === 'app_only');
if ($isImap) {
    $requiredFields = ['name', 'target_mailbox', 'imap_server', 'imap_username', 'smtp_server'];
} else {
    $requiredFields = ['name', 'azure_client_id', 'target_mailbox'];
    if (!$isAppOnly) {
        $requiredFields[] = 'oauth_redirect_uri';
    }
    if ($provider === MailboxProvider::Microsoft) {
        $requiredFields[] = 'azure_tenant_id';
    }
}
foreach ($requiredFields as $field) {
    if (empty($data[$field])) {
        echo json_encode(['success' => false, 'error' => "Missing required field: $field"]);
        exit;
    }
}

try {
    $conn = connectToDatabase();

    $id = $data['id'] ?? null;
    $name = $data['name'];
    // $provider: the MailboxProvider validated at the top.
    $oauth_redirect_uri_plain = trim($data['oauth_redirect_uri'] ?? '');
    $redirectPath = parse_url($oauth_redirect_uri_plain, PHP_URL_PATH) ?: '';
    // The redirect URI only matters for the interactive sign-in flow — skip the
    // callback checks for app-only and basic-IMAP mailboxes (neither redirects anywhere).
    if (!$isAppOnly && !$isImap) {
        if ($provider === MailboxProvider::Google && !preg_match('#(^|/)google_oauth_callback\.php$#i', $redirectPath)) {
            echo json_encode([
                'success' => false,
                'error' => 'Google Workspace mailboxes must use google_oauth_callback.php as the OAuth redirect URI.'
            ]);
            exit;
        }
        if ($provider === MailboxProvider::Microsoft && !preg_match('#(^|/)oauth_callback\.php$#i', $redirectPath)) {
            echo json_encode([
                'success' => false,
                'error' => 'Microsoft 365 mailboxes must use oauth_callback.php as the OAuth redirect URI.'
            ]);
            exit;
        }
    }

    $azure_tenant_id = encryptValue($data['azure_tenant_id'] ?? '');
    $azure_client_id = encryptValue($data['azure_client_id']);
    $azure_client_secret = $data['azure_client_secret'] ?? '';
    $oauth_redirect_uri = encryptValue($oauth_redirect_uri_plain);
    $oauth_scopes = $data['oauth_scopes'] ?? 'openid email offline_access User.Read Mail.Read Mail.ReadWrite Mail.Send';
    $imap_server = encryptValue($data['imap_server'] ?? 'outlook.office365.com');
    $imap_port = $data['imap_port'] ?? 993;
    $imap_encryption = $data['imap_encryption'] ?? 'ssl';
    // Basic IMAP / SMTP credentials + transport (empty/defaults for OAuth providers).
    $imap_username = encryptValue($data['imap_username'] ?? '');
    $imap_password_plain = $data['imap_password'] ?? '';
    $smtp_server = encryptValue($data['smtp_server'] ?? '');
    $smtp_port = $data['smtp_port'] ?? 587;
    $smtp_encryption = $data['smtp_encryption'] ?? 'tls';
    // Sending credentials, for providers that issue a different login for SMTP
    // than for IMAP. The UI asks the question the way an online checkout asks
    // about a billing address: a toggle that says "same as the IMAP login",
    // with the two fields revealed only when it is turned off.
    $smtp_username_plain = $data['smtp_username'] ?? '';
    $smtp_password_plain = $data['smtp_password'] ?? '';
    $target_mailbox = encryptValue($data['target_mailbox']);
    $email_folder = $data['email_folder'] ?? 'INBOX';
    $max_emails_per_check = $data['max_emails_per_check'] ?? 10;
    $mark_as_read = isset($data['mark_as_read']) ? ($data['mark_as_read'] ? 1 : 0) : 0;
    $rejected_action = $data['rejected_action'] ?? 'delete';
    $imported_action = $data['imported_action'] ?? 'delete';
    $imported_folder = $data['imported_folder'] ?? null;
    $is_active = isset($data['is_active']) ? ($data['is_active'] ? 1 : 0) : 1;

    // Multi-tenancy: the company this mailbox is pinned to. Empty/0/absent means
    // "shared intake" (NULL → inbound routed by sender domain). Validate it's a
    // real company; if not, fall back to NULL rather than risk a bad FK.
    $tenant_id = $data['tenant_id'] ?? null;
    if ($tenant_id === '' || $tenant_id === 0 || $tenant_id === '0') {
        $tenant_id = null;
    }
    if ($tenant_id !== null) {
        $tenant_id = (int) $tenant_id;
        $tCheck = $conn->prepare("SELECT COUNT(*) FROM tenants WHERE id = ?");
        $tCheck->execute([$tenant_id]);
        if ((int) $tCheck->fetchColumn() === 0) {
            $tenant_id = null;
        }
    }

    // The ticket origin stamped on tickets this mailbox opens (#79). Stored as an
    // ID, so renaming the origin later can't break it — which is the whole bug
    // this replaces. Empty/absent = don't set an origin.
    //
    // A mailbox pinned to a company may use that company's own origins as well as
    // the global ones. A shared-intake mailbox (no company) may use ONLY global
    // origins: its tickets land in whichever company the sender's domain matches,
    // so one company's private origin could otherwise end up stamped on another
    // company's ticket. Global origins always exist, so every mailbox can always
    // set one — there is no mailbox left unable to record an origin.
    $default_origin_id = $data['default_origin_id'] ?? null;
    if ($default_origin_id === '' || $default_origin_id === 0 || $default_origin_id === '0') {
        $default_origin_id = null;
    }
    if ($default_origin_id !== null) {
        $default_origin_id = (int) $default_origin_id;
        $oCheck = $conn->prepare(
            "SELECT COUNT(*) FROM ticket_origins
              WHERE id = ? AND (tenant_id IS NULL" . ($tenant_id !== null ? " OR tenant_id = ?" : "") . ")"
        );
        $oCheck->execute($tenant_id !== null ? [$default_origin_id, $tenant_id] : [$default_origin_id]);
        if ((int) $oCheck->fetchColumn() === 0) {
            echo json_encode(['success' => false, 'error' => 'That ticket origin is not available for this mailbox']);
            exit;
        }
    }

    // Validate action values
    $allowedRejectedActions = ['delete', 'move_to_deleted', 'mark_read'];
    // 'mark_read' (GH #269): mark it read and leave it where it is, for a mailbox people
    // also read in Thunderbird or Outlook. handleEmailAfterProcessing() always handled it
    // for every provider; only this list and the dropdown left it out.
    $allowedImportedActions = ['delete', 'move_to_folder', 'mark_read'];
    if (!in_array($rejected_action, $allowedRejectedActions)) $rejected_action = 'delete';
    if (!in_array($imported_action, $allowedImportedActions)) $imported_action = 'delete';

    // Authentication mode: 'delegated' (interactive sign-in, reads /me) or 'app_only'
    // (client credentials, reads /users/<target_mailbox>). App-only is Microsoft only.
    $auth_mode = (($data['auth_mode'] ?? 'delegated') === 'app_only' && $provider->supportsAppOnly()) ? 'app_only' : 'delegated';

    // On edit: if the target address OR auth mode changed, the previously-authenticated
    // identity no longer applies. We clear it so a stale token can't keep reading the OLD
    // inbox (the exact "changed address but didn't re-auth" trap) — it forces re-auth /
    // surfaces the mismatch banner.
    $invalidateAuth = false;
    if ($id) {
        $oldStmt = $conn->prepare("SELECT target_mailbox, auth_mode FROM target_mailboxes WHERE id = ?");
        $oldStmt->execute([$id]);
        if ($old = $oldStmt->fetch(PDO::FETCH_ASSOC)) {
            $oldTarget = strtolower(trim((string) decryptValue($old['target_mailbox'])));
            $newTarget = strtolower(trim((string) ($data['target_mailbox'] ?? '')));
            if ($oldTarget !== $newTarget || ($old['auth_mode'] ?? 'delegated') !== $auth_mode) {
                $invalidateAuth = true;
            }
        }
    }

    // All columns except the two credential secrets (client secret / IMAP password),
    // which are only written when a real new value was supplied — a blank or masked
    // (****) value means "leave the stored one untouched".
    $cols = [
        'name'                 => $name,
        'provider'             => $provider->value,
        'azure_tenant_id'      => $azure_tenant_id,
        'azure_client_id'      => $azure_client_id,
        'oauth_redirect_uri'   => $oauth_redirect_uri,
        'oauth_scopes'         => $oauth_scopes,
        'imap_server'          => $imap_server,
        'imap_port'            => $imap_port,
        'imap_encryption'      => $imap_encryption,
        'imap_username'        => $imap_username,
        'smtp_server'          => $smtp_server,
        'smtp_port'            => $smtp_port,
        'smtp_encryption'      => $smtp_encryption,
        'target_mailbox'       => $target_mailbox,
        'email_folder'         => $email_folder,
        'max_emails_per_check' => $max_emails_per_check,
        'mark_as_read'         => $mark_as_read,
        'rejected_action'      => $rejected_action,
        'imported_action'      => $imported_action,
        'imported_folder'      => $imported_folder,
        'is_active'            => $is_active,
        'tenant_id'            => $tenant_id,
        'default_origin_id'    => $default_origin_id,
        'auth_mode'            => $auth_mode,
    ];

    // Confidential mailbox (discussion #62) - an HR mailbox whose tickets must be
    // confidential the moment they land. Only when sent, and only once Database
    // Verification has added the column.
    require_once '../../includes/ticket_sensitivity.php';
    if (array_key_exists('default_sensitivity', $data) && ticketSensitivityReady($conn)) {
        $cols['default_sensitivity'] = ticketSensitivityNormalise($data['default_sensitivity']);
    }

    // The department its new tickets start in (GH #315): blank = the company's default.
    // Only when sent, and only once Database Verification has added the column.
    if (array_key_exists('department_id', $data) && $conn->query("SHOW COLUMNS FROM target_mailboxes LIKE 'department_id'")->fetch()) {
        $deptId = (int) ($data['department_id'] ?? 0) ?: null;
        if ($deptId !== null) {
            $dCheck = $conn->prepare("SELECT is_active FROM departments WHERE id = ?");
            $dCheck->execute([$deptId]);
            $dActive = $dCheck->fetchColumn();
            // An inactive department may stay on a mailbox that already has it, never be newly chosen.
            $kept = false;
            if ($id) { $k = $conn->prepare("SELECT department_id FROM target_mailboxes WHERE id = ?"); $k->execute([$id]); $kept = (int) $k->fetchColumn() === $deptId; }
            if ($dActive === false || ((int) $dActive !== 1 && !$kept)) {
                echo json_encode(['success' => false, 'error' => 'Choose an active department']);
                exit;
            }
        }
        $cols['department_id'] = $deptId;
    }

    // This mailbox's own oversized-email rules (GH #170): null = use the defaults.
    // Validated through the same function that reads them, and stored in MB.
    // Only once Database Verification has added the column.
    if (array_key_exists('oversize_policy', $data)) {
        $hasCol = (bool)$conn->query("SHOW COLUMNS FROM target_mailboxes LIKE 'oversize_policy'")->fetch();
        if ($hasCol) {
            require_once '../../includes/attachment_limits.php';
            if (is_array($data['oversize_policy'])) {
                $p = oversizeNormalisePolicy($data['oversize_policy']);
                if ($p['alert'] && $p['alert_to'] === '') {
                    echo json_encode(['success' => false, 'error' => 'Large emails: give at least one valid address for the alert, or switch the alert off.']);
                    exit;
                }
                $cols['oversize_policy'] = json_encode(oversizePolicyForStorage($p), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            } else {
                $cols['oversize_policy'] = null;
            }
        }
    }

    // Save a copy of sent mail (GH #270), Basic IMAP only. Only when sent, and only once
    // Database Verification has added the columns - an older API client that does not
    // know them leaves them as they are.
    if (array_key_exists('save_sent_copy', $data) && $conn->query("SHOW COLUMNS FROM target_mailboxes LIKE 'save_sent_copy'")->fetch()) {
        $cols['save_sent_copy'] = !empty($data['save_sent_copy']) ? 1 : 0;
        $sf = trim((string)($data['sent_folder'] ?? ''));
        $cols['sent_folder'] = $sf !== '' ? mb_substr($sf, 0, 255) : null;
    }

    $secretProvided   = !(empty($azure_client_secret)  || preg_match('/^\*+/', $azure_client_secret));
    $passwordProvided = !(empty($imap_password_plain)   || preg_match('/^\*+/', $imap_password_plain));
    if ($secretProvided)   $cols['azure_client_secret'] = encryptValue($azure_client_secret);
    if ($passwordProvided) $cols['imap_password']        = encryptValue($imap_password_plain);

    /* SMTP credentials.

       🔴 Only touched when the caller actually SENT the toggle. An API client
       written against the previous shape of this endpoint does not know these
       columns exist, and treating its silence as "same as IMAP" would erase a
       working SMTP login — after which sending falls back to the IMAP credentials
       and carries on looking healthy, which is the worst way for a credential to
       disappear. Absent means unchanged, exactly as a blank password does. */
    if (array_key_exists('smtp_same_as_imap', $data)) {
        if (!empty($data['smtp_same_as_imap'])) {
            // The toggle is on: sending uses the IMAP login, so both columns go
            // back to empty rather than lingering as credentials nothing reads.
            $cols['smtp_username'] = '';
            $cols['smtp_password'] = '';
        } else {
            $cols['smtp_username'] = encryptValue(trim($smtp_username_plain));
            // …and the password only when one was typed, so reopening the mailbox
            // and saving it does not blank a stored password. Same masked-value
            // guard as imap_password above.
            if (!(empty($smtp_password_plain) || preg_match('/^\*+/', $smtp_password_plain))) {
                $cols['smtp_password'] = encryptValue($smtp_password_plain);
            }
        }
    }

    if ($id) {
        // Update existing mailbox — write every provided column.
        $setParts = [];
        $params = [];
        foreach ($cols as $col => $val) {
            $setParts[] = "$col = ?";
            $params[] = $val;
        }
        $params[] = $id;

        $sql = "UPDATE target_mailboxes SET " . implode(', ', $setParts) . " WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);

        // Drop the stale authenticated identity if the address / auth mode changed.
        //
        // The TOKEN has to go too, not just the identity. Delegated and app-only tokens
        // are not interchangeable: an app-only token presented at /me is refused by
        // Graph ("/me request is only valid with delegated authentication flow"), and a
        // delegated token cannot act on /users/<addr>. Leaving the old one behind means
        // the mailbox looks authenticated, so nothing prompts for a fresh sign-in, and
        // every send fails with an error describing the wrong problem entirely.
        //
        // Clearing it is safe in both directions: app-only simply mints a new one on
        // its next use, and delegated correctly reports "not authenticated" until
        // somebody signs in — which is exactly what a mode change should require.
        if ($invalidateAuth) {
            $conn->prepare("UPDATE target_mailboxes SET authenticated_as = NULL, authenticated_addresses = NULL, token_data = NULL WHERE id = ?")
                 ->execute([$id]);
        }

        echo json_encode([
            'success' => true,
            'message' => 'Mailbox updated successfully',
            'id' => $id,
            'reauth_required' => $invalidateAuth
        ]);
    } else {
        // Insert new mailbox — require the relevant credential up front.
        if ($isImap) {
            if (!$passwordProvided) {
                echo json_encode(['success' => false, 'error' => 'IMAP password is required for new mailboxes']);
                exit;
            }
        } elseif (!$secretProvided) {
            echo json_encode(['success' => false, 'error' => 'Client secret is required for new mailboxes']);
            exit;
        }

        // azure_client_secret is NOT NULL — always give it a value (empty for IMAP).
        if (!isset($cols['azure_client_secret'])) {
            $cols['azure_client_secret'] = encryptValue('');
        }

        $columns = array_keys($cols);
        $placeholders = implode(', ', array_fill(0, count($columns), '?'));
        $sql = "INSERT INTO target_mailboxes (" . implode(', ', $columns) . ") VALUES ($placeholders)";
        $stmt = $conn->prepare($sql);
        $stmt->execute(array_values($cols));

        $newId = $conn->lastInsertId();

        echo json_encode([
            'success' => true,
            'message' => 'Mailbox created successfully',
            'id' => $newId
        ]);
    }

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}

?>
