<?php
/**
 * API: Tasks — Delete a task and its subtasks
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
    echo json_encode(['success' => false, 'error' => 'Missing task ID']);
    exit;
}

try {
    $conn = connectToDatabase();

    // Subtasks and comments cascade-delete via FK constraints
    $stmt = $conn->prepare("DELETE FROM tasks WHERE id = ?");
    $stmt->execute([$id]);

    if ($stmt->rowCount() === 0) {
        echo json_encode(['success' => false, 'error' => 'Task not found']);
        exit;
    }

    echo json_encode(['success' => true, 'message' => 'Task deleted']);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
