<?php
/**
 * API: Tasks — Add a comment to a task
 * POST — JSON body with {task_id, comment}
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
$comment = trim($input['comment'] ?? '');

if (!$taskId || !$comment) {
    echo json_encode(['success' => false, 'error' => 'Missing task_id or comment']);
    exit;
}

try {
    $conn = connectToDatabase();
    $analystId = $_SESSION['analyst_id'];

    $stmt = $conn->prepare("INSERT INTO task_comments (task_id, analyst_id, comment) VALUES (?, ?, ?)");
    $stmt->execute([$taskId, $analystId, $comment]);

    // Update parent task's updated_datetime
    $stmt = $conn->prepare("UPDATE tasks SET updated_datetime = UTC_TIMESTAMP() WHERE id = ?");
    $stmt->execute([$taskId]);

    $newId = $conn->lastInsertId();
    $analystName = $_SESSION['analyst_name'] ?? 'Analyst';

    echo json_encode([
        'success' => true,
        'comment' => [
            'id' => (int)$newId,
            'comment' => $comment,
            'analyst_name' => $analystName,
            'created_datetime' => gmdate('Y-m-d H:i:s')
        ]
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
