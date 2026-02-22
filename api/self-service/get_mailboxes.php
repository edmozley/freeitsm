<?php
/**
 * API: Get active mailboxes for self-service ticket creation
 * GET - Returns mailbox id, name, and email address only
 */
session_start();
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/encryption.php';

header('Content-Type: application/json');

if (!isset($_SESSION['ss_user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

try {
    $conn = connectToDatabase();

    $stmt = $conn->prepare(
        "SELECT id, name, target_mailbox FROM target_mailboxes WHERE is_active = 1 ORDER BY name"
    );
    $stmt->execute();
    $mailboxes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Decrypt the target_mailbox column (it's stored encrypted)
    foreach ($mailboxes as &$mb) {
        $mb['target_mailbox'] = decryptValue($mb['target_mailbox']);
    }

    echo json_encode(['success' => true, 'mailboxes' => $mailboxes]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
