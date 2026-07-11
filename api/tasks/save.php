<?php
/**
 * API: Tasks — Create or update a task.
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

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    echo json_encode(['success' => false, 'error' => 'Invalid JSON']);
    exit;
}

try {
    $conn = connectToDatabase();
    // The UI already sends canonical keys (status/priority by name, *_id links, tags[]).
    $res = TasksService::saveTask($conn, ActorContext::fromSession($conn), $input);
    echo json_encode([
        'success' => true,
        'message' => $res['created'] ? 'Task created' : 'Task updated',
        'id'      => $res['id'],
    ]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
