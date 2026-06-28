<?php
/**
 * API Endpoint: Get all target mailboxes
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

try {
    $conn = connectToDatabase();

    $sql = "SELECT id, name, provider, azure_tenant_id, azure_client_id, azure_client_secret,
                   oauth_redirect_uri, oauth_scopes, imap_server, imap_port, imap_encryption,
                   target_mailbox, auth_mode, authenticated_as, email_folder, max_emails_per_check, mark_as_read,
                   rejected_action, imported_action, imported_folder,
                   is_active, tenant_id, created_datetime, last_checked_datetime,
                   CASE WHEN token_data IS NOT NULL AND token_data != '' THEN 1 ELSE 0 END as is_authenticated
            FROM target_mailboxes
            ORDER BY name";

    $stmt = $conn->prepare($sql);
    $stmt->execute();
    $mailboxes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Convert fields to proper types
    foreach ($mailboxes as &$mailbox) {
        // Decrypt encrypted columns. Guard per-row so a single undecryptable
        // value (e.g. a field that was truncated by an undersized column) can't
        // blank out the entire mailbox list — flag that row instead.
        try {
            $mailbox = decryptMailboxRow($mailbox);
            $mailbox['decrypt_error'] = false;
        } catch (Exception $e) {
            $mailbox['decrypt_error'] = true;
            $mailbox['decrypt_error_message'] = $e->getMessage();
        }

        // Convert numeric fields to integers
        $mailbox['id'] = (int)$mailbox['id'];
        $mailbox['imap_port'] = (int)$mailbox['imap_port'];
        $mailbox['max_emails_per_check'] = (int)$mailbox['max_emails_per_check'];
        // Multi-tenancy: the company this mailbox is pinned to (null = shared intake).
        $mailbox['tenant_id'] = ($mailbox['tenant_id'] === null) ? null : (int)$mailbox['tenant_id'];

        // Convert bit fields to booleans
        $mailbox['is_active'] = (bool)$mailbox['is_active'];
        $mailbox['mark_as_read'] = (bool)$mailbox['mark_as_read'];
        $mailbox['is_authenticated'] = (bool)$mailbox['is_authenticated'];

        // Mask client secret for display (show only last 4 chars)
        if (!empty($mailbox['azure_client_secret'])) {
            $mailbox['azure_client_secret_masked'] = '****' . substr($mailbox['azure_client_secret'], -4);
        } else {
            $mailbox['azure_client_secret_masked'] = '';
        }

        // Default + normalise the auth mode.
        $mailbox['auth_mode'] = ($mailbox['auth_mode'] ?? 'delegated') === 'app_only' ? 'app_only' : 'delegated';

        // Compute a clear "where is this reading from?" status for the UI so it's
        // obvious which inbox a mailbox actually pulls — and flags a wrong account.
        $target  = strtolower(trim((string) ($mailbox['target_mailbox'] ?? '')));
        $authedAs = strtolower(trim((string) ($mailbox['authenticated_as'] ?? '')));
        if ($mailbox['provider'] === 'google') {
            $mailbox['auth_status'] = $mailbox['is_authenticated'] ? 'ok' : 'unauthenticated';
        } elseif ($mailbox['auth_mode'] === 'app_only') {
            $mailbox['auth_status'] = 'app_only';            // always reads the target directly
        } elseif (!$mailbox['is_authenticated']) {
            $mailbox['auth_status'] = 'unauthenticated';     // delegated, never signed in
        } elseif ($authedAs === '') {
            $mailbox['auth_status'] = 'unverified';           // signed in before we recorded who
        } elseif ($authedAs === $target) {
            $mailbox['auth_status'] = 'ok';                   // reading the right inbox
        } else {
            $mailbox['auth_status'] = 'mismatch';             // ⚠ reading the WRONG inbox
        }
    }

    echo json_encode([
        'success' => true,
        'mailboxes' => $mailboxes
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}

?>
