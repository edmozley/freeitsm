<?php
/**
 * API: Tasks — Toggle subtask status between To Do and Done
 * POST — JSON body with {id}
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireModuleAccessJson('tasks');

$input = json_decode(file_get_contents('php://input'), true);
$id = isset($input['id']) ? (int)$input['id'] : 0;

if (!$id) {
    echo json_encode(['success' => false, 'error' => 'Missing subtask ID']);
    exit;
}

try {
    $conn = connectToDatabase();

    // Get current status (joined to lookup) and parent
    $stmt = $conn->prepare(
        "SELECT ts.name AS status, ts.is_closed AS status_is_closed, t.parent_task_id
         FROM tasks t
         LEFT JOIN task_statuses ts ON ts.id = t.status_id
         WHERE t.id = ?"
    );
    $stmt->execute([$id]);
    $task = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$task) {
        echo json_encode(['success' => false, 'error' => 'Subtask not found']);
        exit;
    }

    // Toggle: closed -> "To Do", anything else -> "Done"
    $newStatusName = $task['status_is_closed'] ? 'To Do' : 'Done';
    $newStatusStmt = $conn->prepare("SELECT id, is_closed FROM task_statuses WHERE name = ? LIMIT 1");
    $newStatusStmt->execute([$newStatusName]);
    $newStatusRow = $newStatusStmt->fetch(PDO::FETCH_ASSOC);
    if (!$newStatusRow) {
        echo json_encode(['success' => false, 'error' => "Status '$newStatusName' not configured"]);
        exit;
    }
    $newStatusId = (int)$newStatusRow['id'];
    $completedDt = $newStatusRow['is_closed'] ? date('Y-m-d H:i:s') : null;

    $stmt = $conn->prepare("UPDATE tasks SET status_id = ?, completed_datetime = ?, updated_datetime = UTC_TIMESTAMP() WHERE id = ?");
    $stmt->execute([$newStatusId, $completedDt, $id]);
    $newStatus = $newStatusName;

    // Update parent's updated_datetime
    if ($task['parent_task_id']) {
        $stmt = $conn->prepare("UPDATE tasks SET updated_datetime = UTC_TIMESTAMP() WHERE id = ?");
        $stmt->execute([$task['parent_task_id']]);
    }

    echo json_encode(['success' => true, 'new_status' => $newStatus]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
