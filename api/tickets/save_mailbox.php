<?php
/**
 * API Endpoint: Save (create/update) target mailbox
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/rbac.php';
require_once '../../includes/encryption.php';

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
$provider = $data['provider'] ?? 'microsoft';
// Basic IMAP authenticates with a username + password (no OAuth), so it needs none
// of the Azure/Google client id/secret/redirect fields.
$isImap = ($provider === 'imap');
// App-only (client credentials) doesn't use the interactive sign-in flow, so it
// needs no redirect URI / OAuth scopes — only the client id/secret + target mailbox.
$isAppOnly = ($provider === 'microsoft') && (($data['auth_mode'] ?? 'delegated') === 'app_only');
if ($isImap) {
    $requiredFields = ['name', 'target_mailbox', 'imap_server', 'imap_username', 'smtp_server'];
} else {
    $requiredFields = ['name', 'azure_client_id', 'target_mailbox'];
    if (!$isAppOnly) {
        $requiredFields[] = 'oauth_redirect_uri';
    }
    if ($provider === 'microsoft') {
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
    $provider = $data['provider'] ?? 'microsoft';
    $oauth_redirect_uri_plain = trim($data['oauth_redirect_uri'] ?? '');
    $redirectPath = parse_url($oauth_redirect_uri_plain, PHP_URL_PATH) ?: '';
    // The redirect URI only matters for the interactive sign-in flow — skip the
    // callback checks for app-only and basic-IMAP mailboxes (neither redirects anywhere).
    if (!$isAppOnly && !$isImap) {
        if ($provider === 'google' && !preg_match('#(^|/)google_oauth_callback\.php$#i', $redirectPath)) {
            echo json_encode([
                'success' => false,
                'error' => 'Google Workspace mailboxes must use google_oauth_callback.php as the OAuth redirect URI.'
            ]);
            exit;
        }
        if ($provider === 'microsoft' && !preg_match('#(^|/)oauth_callback\.php$#i', $redirectPath)) {
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

    // Validate action values
    $allowedRejectedActions = ['delete', 'move_to_deleted', 'mark_read'];
    $allowedImportedActions = ['delete', 'move_to_folder'];
    if (!in_array($rejected_action, $allowedRejectedActions)) $rejected_action = 'delete';
    if (!in_array($imported_action, $allowedImportedActions)) $imported_action = 'delete';

    // Authentication mode: 'delegated' (interactive sign-in, reads /me) or 'app_only'
    // (client credentials, reads /users/<target_mailbox>). App-only is Microsoft only.
    $auth_mode = (($data['auth_mode'] ?? 'delegated') === 'app_only' && $provider === 'microsoft') ? 'app_only' : 'delegated';

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
        'provider'             => $provider,
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
        'auth_mode'            => $auth_mode,
    ];

    $secretProvided   = !(empty($azure_client_secret)  || preg_match('/^\*+/', $azure_client_secret));
    $passwordProvided = !(empty($imap_password_plain)   || preg_match('/^\*+/', $imap_password_plain));
    if ($secretProvided)   $cols['azure_client_secret'] = encryptValue($azure_client_secret);
    if ($passwordProvided) $cols['imap_password']        = encryptValue($imap_password_plain);

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
        if ($invalidateAuth) {
            $conn->prepare("UPDATE target_mailboxes SET authenticated_as = NULL WHERE id = ?")->execute([$id]);
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
