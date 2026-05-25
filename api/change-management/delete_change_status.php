<?php
/**
 * API Endpoint: Delete change status
 * Refuses if the status is in use by any change/template, or if it's the default.
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

    $isDefault = (int) $conn->query("SELECT is_default FROM change_statuses WHERE id = " . (int)$id)->fetchColumn();
    if ($isDefault === 1) {
        throw new Exception('Cannot delete the default status. Set another status as default first.');
    }

    $checkStmt = $conn->prepare("SELECT COUNT(*) FROM changes WHERE status_id = ?");
    $checkStmt->execute([$id]);
    $count = (int)$checkStmt->fetchColumn();

    if ($count > 0) {
        throw new Exception("Cannot delete: this status is used by $count change(s). Reassign them or set the status to inactive instead.");
    }

    $stmt = $conn->prepare("DELETE FROM change_statuses WHERE id = ?");
    $stmt->execute([$id]);

    echo json_encode(['success' => true]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
