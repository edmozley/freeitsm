<?php
/**
 * API Endpoint: Get all target mailboxes
 */
session_start();
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

    $sql = "SELECT id, name, azure_tenant_id, azure_client_id, azure_client_secret,
                   oauth_redirect_uri, oauth_scopes, imap_server, imap_port, imap_encryption,
                   target_mailbox, email_folder, max_emails_per_check, mark_as_read,
                   is_active, created_datetime, last_checked_datetime,
                   CASE WHEN token_data IS NOT NULL AND token_data != '' THEN 1 ELSE 0 END as is_authenticated
            FROM target_mailboxes
            ORDER BY name";

    $stmt = $conn->prepare($sql);
    $stmt->execute();
    $mailboxes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Convert fields to proper types
    foreach ($mailboxes as &$mailbox) {
        // Decrypt encrypted columns
        $mailbox = decryptMailboxRow($mailbox);

        // Convert numeric fields to integers
        $mailbox['id'] = (int)$mailbox['id'];
        $mailbox['imap_port'] = (int)$mailbox['imap_port'];
        $mailbox['max_emails_per_check'] = (int)$mailbox['max_emails_per_check'];

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
