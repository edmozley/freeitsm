<?php
/**
 * API: Tasks — Reorder after drag-and-drop
 * POST — JSON body with {task_id, new_status, positions: [{id, board_position}, ...]}
 */
session_start();
require_once '../../config.php';
require_once '../../includes/functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$taskId = isset($input['task_id']) ? (int)$input['task_id'] : 0;
$newStatus = $input['new_status'] ?? '';
$positions = $input['positions'] ?? [];

if (!$taskId || !$newStatus) {
    echo json_encode(['success' => false, 'error' => 'Missing task_id or new_status']);
    exit;
}

try {
    $conn = connectToDatabase();
    $conn->beginTransaction();

    // Update the moved task's status
    $completedSql = $newStatus === 'Done'
        ? ", completed_datetime = COALESCE(completed_datetime, UTC_TIMESTAMP())"
        : ", completed_datetime = NULL";

    $stmt = $conn->prepare(
        "UPDATE tasks SET status = ?, updated_datetime = UTC_TIMESTAMP(){$completedSql} WHERE id = ?"
    );
    $stmt->execute([$newStatus, $taskId]);

    // Update board positions for all tasks in the affected columns
    $posStmt = $conn->prepare("UPDATE tasks SET board_position = ? WHERE id = ?");
    foreach ($positions as $pos) {
        $posStmt->execute([(int)$pos['board_position'], (int)$pos['id']]);
    }

    $conn->commit();
    echo json_encode(['success' => true, 'message' => 'Task reordered']);

} catch (Exception $e) {
    $conn->rollBack();
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
