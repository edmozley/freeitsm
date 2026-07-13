<?php
/**
 * API Endpoint: Delete task status
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
requireCapabilityJson(Cap::TASKS_STATUSES);   // Tasks settings tab — see docs/design/rbac.md

try {
    $input = json_decode(file_get_contents('php://input'), true);
    $id = (int)($input['id'] ?? 0);

    if (!$id) throw new Exception('Status ID is required');

    $conn = connectToDatabase();

    $isDefault = (int) $conn->query("SELECT is_default FROM task_statuses WHERE id = " . (int)$id)->fetchColumn();
    if ($isDefault === 1) {
        throw new Exception('Cannot delete the default status. Set another status as default first.');
    }

    $checkStmt = $conn->prepare("SELECT COUNT(*) FROM tasks WHERE status_id = ?");
    $checkStmt->execute([$id]);
    $count = (int)$checkStmt->fetchColumn();

    if ($count > 0) {
        throw new Exception("Cannot delete: this status is used by $count task(s). Reassign them or set the status to inactive instead.");
    }

    $name = $conn->query("SELECT name FROM task_statuses WHERE id = " . (int)$id)->fetchColumn() ?: null;
    $stmt = $conn->prepare("DELETE FROM task_statuses WHERE id = ?");
    $stmt->execute([$id]);

    wf_emit('task_status', 'deleted', (int)$id, $name);
    echo json_encode(['success' => true]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
