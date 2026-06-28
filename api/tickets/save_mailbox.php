<?php
/**
 * API Endpoint: Save (create/update) target mailbox
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/encryption.php';

header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

// Get POST data
$data = json_decode(file_get_contents('php://input'), true);

if (!$data) {
    echo json_encode(['success' => false, 'error' => 'Invalid request data']);
    exit;
}

// Validate required fields — tenant ID only required for Microsoft.
$provider = $data['provider'] ?? 'microsoft';
// App-only (client credentials) doesn't use the interactive sign-in flow, so it
// needs no redirect URI / OAuth scopes — only the client id/secret + target mailbox.
$isAppOnly = ($provider === 'microsoft') && (($data['auth_mode'] ?? 'delegated') === 'app_only');
$requiredFields = ['name', 'azure_client_id', 'target_mailbox'];
if (!$isAppOnly) {
    $requiredFields[] = 'oauth_redirect_uri';
}
if ($provider === 'microsoft') {
    $requiredFields[] = 'azure_tenant_id';
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
    // callback checks for app-only mailboxes (they never redirect anywhere).
    if (!$isAppOnly) {
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
    $oauth_scopes = $data['oauth_scopes'] ?? 'openid email offline_access Mail.Read Mail.ReadWrite Mail.Send';
    $imap_server = encryptValue($data['imap_server'] ?? 'outlook.office365.com');
    $imap_port = $data['imap_port'] ?? 993;
    $imap_encryption = $data['imap_encryption'] ?? 'ssl';
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

    if ($id) {
        // Update existing mailbox
        // If azure_client_secret is empty or just asterisks, don't update it
        if (empty($azure_client_secret) || preg_match('/^\*+/', $azure_client_secret)) {
            $sql = "UPDATE target_mailboxes SET
                        name = ?, provider = ?, azure_tenant_id = ?, azure_client_id = ?,
                        oauth_redirect_uri = ?, oauth_scopes = ?, imap_server = ?,
                        imap_port = ?, imap_encryption = ?, target_mailbox = ?,
                        email_folder = ?, max_emails_per_check = ?, mark_as_read = ?,
                        rejected_action = ?, imported_action = ?, imported_folder = ?,
                        is_active = ?, tenant_id = ?, auth_mode = ?
                    WHERE id = ?";
            $params = [
                $name, $provider, $azure_tenant_id, $azure_client_id,
                $oauth_redirect_uri, $oauth_scopes, $imap_server,
                $imap_port, $imap_encryption, $target_mailbox,
                $email_folder, $max_emails_per_check, $mark_as_read,
                $rejected_action, $imported_action, $imported_folder,
                $is_active, $tenant_id, $auth_mode, $id
            ];
        } else {
            $azure_client_secret = encryptValue($azure_client_secret);
            $sql = "UPDATE target_mailboxes SET
                        name = ?, provider = ?, azure_tenant_id = ?, azure_client_id = ?,
                        azure_client_secret = ?, oauth_redirect_uri = ?, oauth_scopes = ?,
                        imap_server = ?, imap_port = ?, imap_encryption = ?,
                        target_mailbox = ?, email_folder = ?, max_emails_per_check = ?,
                        mark_as_read = ?, rejected_action = ?, imported_action = ?,
                        imported_folder = ?, is_active = ?, tenant_id = ?, auth_mode = ?
                    WHERE id = ?";
            $params = [
                $name, $provider, $azure_tenant_id, $azure_client_id,
                $azure_client_secret, $oauth_redirect_uri, $oauth_scopes,
                $imap_server, $imap_port, $imap_encryption,
                $target_mailbox, $email_folder, $max_emails_per_check,
                $mark_as_read, $rejected_action, $imported_action,
                $imported_folder, $is_active, $tenant_id, $auth_mode, $id
            ];
        }

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
        // Insert new mailbox
        if (empty($azure_client_secret)) {
            echo json_encode(['success' => false, 'error' => 'Client secret is required for new mailboxes']);
            exit;
        }

        $azure_client_secret = encryptValue($azure_client_secret);

        $sql = "INSERT INTO target_mailboxes (
                    name, provider, azure_tenant_id, azure_client_id, azure_client_secret,
                    oauth_redirect_uri, oauth_scopes, imap_server, imap_port,
                    imap_encryption, target_mailbox, email_folder, max_emails_per_check,
                    mark_as_read, rejected_action, imported_action, imported_folder,
                    is_active, tenant_id, auth_mode
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

        $stmt = $conn->prepare($sql);
        $stmt->execute([
            $name, $provider, $azure_tenant_id, $azure_client_id, $azure_client_secret,
            $oauth_redirect_uri, $oauth_scopes, $imap_server, $imap_port,
            $imap_encryption, $target_mailbox, $email_folder, $max_emails_per_check,
            $mark_as_read, $rejected_action, $imported_action, $imported_folder,
            $is_active, $tenant_id, $auth_mode
        ]);

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
