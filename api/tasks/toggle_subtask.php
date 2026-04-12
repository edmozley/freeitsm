<?php
/**
 * API: Tasks — Toggle subtask status between To Do and Done
 * POST — JSON body with {id}
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
$id = isset($input['id']) ? (int)$input['id'] : 0;

if (!$id) {
    echo json_encode(['success' => false, 'error' => 'Missing subtask ID']);
    exit;
}

try {
    $conn = connectToDatabase();

    // Get current status and parent
    $stmt = $conn->prepare("SELECT status, parent_task_id FROM tasks WHERE id = ?");
    $stmt->execute([$id]);
    $task = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$task) {
        echo json_encode(['success' => false, 'error' => 'Subtask not found']);
        exit;
    }

    $newStatus = $task['status'] === 'Done' ? 'To Do' : 'Done';
    $completedDt = $newStatus === 'Done' ? date('Y-m-d H:i:s') : null;

    $stmt = $conn->prepare("UPDATE tasks SET status = ?, completed_datetime = ?, updated_datetime = UTC_TIMESTAMP() WHERE id = ?");
    $stmt->execute([$newStatus, $completedDt, $id]);

    // Update parent's updated_datetime
    if ($task['parent_task_id']) {
        $stmt = $conn->prepare("UPDATE tasks SET updated_datetime = UTC_TIMESTAMP() WHERE id = ?");
        $stmt->execute([$task['parent_task_id']]);
    }

    echo json_encode(['success' => true, 'new_status' => $newStatus]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
