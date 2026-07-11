<?php
/**
 * API: Tasks — Add a comment to a task.
 * Thin UI adapter over TasksService.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/services/tasks.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireModuleAccessJson('tasks');

try {
    $conn = connectToDatabase();
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $comment = trim((string)($input['comment'] ?? ''));
    $id = TasksService::createComment($conn, ActorContext::fromSession($conn), (int)($input['task_id'] ?? 0), $comment);
    echo json_encode([
        'success' => true,
        'comment' => [
            'id'               => $id,
            'comment'          => $comment,
            'analyst_name'     => $_SESSION['analyst_name'] ?? 'Analyst',
            'created_datetime' => gmdate('Y-m-d H:i:s'),
        ],
    ]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
