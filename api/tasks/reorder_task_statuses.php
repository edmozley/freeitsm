<?php
/**
 * API Endpoint: Reorder task statuses (board column order)
 * POST { order: [statusId, statusId, ...] } — rewrites display_order to match.
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
    $order = $input['order'] ?? [];
    if (!is_array($order) || !$order) throw new Exception('No order provided');

    $conn = connectToDatabase();
    $stmt = $conn->prepare("UPDATE task_statuses SET display_order = ? WHERE id = ?");
    $pos = 10;
    foreach ($order as $id) {
        $id = (int)$id;
        if ($id > 0) {
            $stmt->execute([$pos, $id]);
            $pos += 10;
        }
    }

    echo json_encode(['success' => true]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
