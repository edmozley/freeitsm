<?php
/**
 * API Endpoint: Delete ticket status
 * Refuses if the status is in use by any ticket, or if it's the default.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

try {
    $input = json_decode(file_get_contents('php://input'), true);
    $id = (int)($input['id'] ?? 0);

    if (!$id) {
        throw new Exception('Status ID is required');
    }

    $conn = connectToDatabase();

    // Don't allow deleting the default
    $isDefault = (int) $conn->query("SELECT is_default FROM ticket_statuses WHERE id = " . (int)$id)->fetchColumn();
    if ($isDefault === 1) {
        throw new Exception('Cannot delete the default status. Set another status as default first.');
    }

    // Refuse if in use by any ticket
    $checkStmt = $conn->prepare("SELECT COUNT(*) FROM tickets WHERE status_id = ?");
    $checkStmt->execute([$id]);
    $count = (int)$checkStmt->fetchColumn();

    if ($count > 0) {
        throw new Exception("Cannot delete: this status is used by $count ticket(s). Reassign them to a different status first.");
    }

    $stmt = $conn->prepare("DELETE FROM ticket_statuses WHERE id = ?");
    $stmt->execute([$id]);

    echo json_encode(['success' => true]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
