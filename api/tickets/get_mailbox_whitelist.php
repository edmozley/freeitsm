<?php
/**
 * API Endpoint: Get whitelist entries for a mailbox
 * GET: ?mailbox_id=N
 */
session_start();
require_once '../../config.php';
require_once '../../includes/functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$mailboxId = $_GET['mailbox_id'] ?? null;

if (!$mailboxId) {
    echo json_encode(['success' => false, 'error' => 'Mailbox ID is required']);
    exit;
}

try {
    $conn = connectToDatabase();

    $stmt = $conn->prepare("SELECT id, entry_type, entry_value FROM mailbox_email_whitelist WHERE mailbox_id = ? ORDER BY entry_type, entry_value");
    $stmt->execute([$mailboxId]);
    $entries = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'entries' => $entries]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
