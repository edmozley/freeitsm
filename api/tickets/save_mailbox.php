<?php
/**
 * API Endpoint: Save (create/update) target mailbox
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

// Get POST data
$data = json_decode(file_get_contents('php://input'), true);

if (!$data) {
    echo json_encode(['success' => false, 'error' => 'Invalid request data']);
    exit;
}

// Validate required fields
$requiredFields = ['name', 'azure_tenant_id', 'azure_client_id', 'oauth_redirect_uri', 'target_mailbox'];
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
    $azure_tenant_id = encryptValue($data['azure_tenant_id']);
    $azure_client_id = encryptValue($data['azure_client_id']);
    $azure_client_secret = $data['azure_client_secret'] ?? '';
    $oauth_redirect_uri = encryptValue($data['oauth_redirect_uri']);
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

    // Validate action values
    $allowedRejectedActions = ['delete', 'move_to_deleted', 'mark_read'];
    $allowedImportedActions = ['delete', 'move_to_folder'];
    if (!in_array($rejected_action, $allowedRejectedActions)) $rejected_action = 'delete';
    if (!in_array($imported_action, $allowedImportedActions)) $imported_action = 'delete';

    if ($id) {
        // Update existing mailbox
        // If azure_client_secret is empty or just asterisks, don't update it
        if (empty($azure_client_secret) || preg_match('/^\*+/', $azure_client_secret)) {
            $sql = "UPDATE target_mailboxes SET
                        name = ?, azure_tenant_id = ?, azure_client_id = ?,
                        oauth_redirect_uri = ?, oauth_scopes = ?, imap_server = ?,
                        imap_port = ?, imap_encryption = ?, target_mailbox = ?,
                        email_folder = ?, max_emails_per_check = ?, mark_as_read = ?,
                        rejected_action = ?, imported_action = ?, imported_folder = ?,
                        is_active = ?
                    WHERE id = ?";
            $params = [
                $name, $azure_tenant_id, $azure_client_id,
                $oauth_redirect_uri, $oauth_scopes, $imap_server,
                $imap_port, $imap_encryption, $target_mailbox,
                $email_folder, $max_emails_per_check, $mark_as_read,
                $rejected_action, $imported_action, $imported_folder,
                $is_active, $id
            ];
        } else {
            $azure_client_secret = encryptValue($azure_client_secret);
            $sql = "UPDATE target_mailboxes SET
                        name = ?, azure_tenant_id = ?, azure_client_id = ?,
                        azure_client_secret = ?, oauth_redirect_uri = ?, oauth_scopes = ?,
                        imap_server = ?, imap_port = ?, imap_encryption = ?,
                        target_mailbox = ?, email_folder = ?, max_emails_per_check = ?,
                        mark_as_read = ?, rejected_action = ?, imported_action = ?,
                        imported_folder = ?, is_active = ?
                    WHERE id = ?";
            $params = [
                $name, $azure_tenant_id, $azure_client_id,
                $azure_client_secret, $oauth_redirect_uri, $oauth_scopes,
                $imap_server, $imap_port, $imap_encryption,
                $target_mailbox, $email_folder, $max_emails_per_check,
                $mark_as_read, $rejected_action, $imported_action,
                $imported_folder, $is_active, $id
            ];
        }

        $stmt = $conn->prepare($sql);
        $stmt->execute($params);

        echo json_encode([
            'success' => true,
            'message' => 'Mailbox updated successfully',
            'id' => $id
        ]);
    } else {
        // Insert new mailbox
        if (empty($azure_client_secret)) {
            echo json_encode(['success' => false, 'error' => 'Client secret is required for new mailboxes']);
            exit;
        }

        $azure_client_secret = encryptValue($azure_client_secret);

        $sql = "INSERT INTO target_mailboxes (
                    name, azure_tenant_id, azure_client_id, azure_client_secret,
                    oauth_redirect_uri, oauth_scopes, imap_server, imap_port,
                    imap_encryption, target_mailbox, email_folder, max_emails_per_check,
                    mark_as_read, rejected_action, imported_action, imported_folder,
                    is_active
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

        $stmt = $conn->prepare($sql);
        $stmt->execute([
            $name, $azure_tenant_id, $azure_client_id, $azure_client_secret,
            $oauth_redirect_uri, $oauth_scopes, $imap_server, $imap_port,
            $imap_encryption, $target_mailbox, $email_folder, $max_emails_per_check,
            $mark_as_read, $rejected_action, $imported_action, $imported_folder,
            $is_active
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
