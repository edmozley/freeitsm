<?php
/**
 * API Endpoint: Delete task priority
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/rbac.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireModuleAccessJson('tasks');
requireCapabilityJson(Cap::TASKS_PRIORITIES);   // Tasks settings tab — see docs/design/rbac.md

try {
    $input = json_decode(file_get_contents('php://input'), true);
    $id = (int)($input['id'] ?? 0);

    if (!$id) throw new Exception('Priority ID is required');

    $conn = connectToDatabase();

    $isDefault = (int) $conn->query("SELECT is_default FROM task_priorities WHERE id = " . (int)$id)->fetchColumn();
    if ($isDefault === 1) {
        throw new Exception('Cannot delete the default priority. Set another priority as default first.');
    }

    $checkStmt = $conn->prepare("SELECT COUNT(*) FROM tasks WHERE priority_id = ?");
    $checkStmt->execute([$id]);
    $count = (int)$checkStmt->fetchColumn();

    if ($count > 0) {
        throw new Exception("Cannot delete: this priority is used by $count task(s). Reassign them or set the priority to inactive instead.");
    }

    $name = $conn->query("SELECT name FROM task_priorities WHERE id = " . (int)$id)->fetchColumn() ?: null;
    $stmt = $conn->prepare("DELETE FROM task_priorities WHERE id = ?");
    $stmt->execute([$id]);

    wf_emit('task_priority', 'deleted', (int)$id, $name);
    echo json_encode(['success' => true]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
