<?php
/**
 * API Endpoint: Delete task tag
 * The ON DELETE CASCADE FK on task_tag_map removes it from any tasks.
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

try {
    $input = json_decode(file_get_contents('php://input'), true);
    $id = (int)($input['id'] ?? 0);
    if (!$id) throw new Exception('Tag ID is required');

    $conn = connectToDatabase();
    $name = $conn->query("SELECT name FROM task_tags WHERE id = " . (int)$id)->fetchColumn() ?: null;
    $stmt = $conn->prepare("DELETE FROM task_tags WHERE id = ?");
    $stmt->execute([$id]);

    wf_emit('task_tag', 'deleted', (int)$id, $name);
    echo json_encode(['success' => true]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
