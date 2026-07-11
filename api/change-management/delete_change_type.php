<?php
/**
 * API Endpoint: Delete change type
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireModuleAccessJson('changes');

try {
    $input = json_decode(file_get_contents('php://input'), true);
    $id = (int)($input['id'] ?? 0);

    if (!$id) {
        throw new Exception('Type ID is required');
    }

    $conn = connectToDatabase();

    $isDefault = (int) $conn->query("SELECT is_default FROM change_types WHERE id = " . (int)$id)->fetchColumn();
    if ($isDefault === 1) {
        throw new Exception('Cannot delete the default change type. Set another type as default first.');
    }

    $checkStmt = $conn->prepare("SELECT COUNT(*) FROM changes WHERE change_type_id = ?");
    $checkStmt->execute([$id]);
    $count = (int)$checkStmt->fetchColumn();

    if ($count > 0) {
        throw new Exception("Cannot delete: this change type is used by $count change(s). Reassign them or set the type to inactive instead.");
    }

    $name = $conn->query("SELECT name FROM change_types WHERE id = " . (int)$id)->fetchColumn() ?: null;
    $stmt = $conn->prepare("DELETE FROM change_types WHERE id = ?");
    $stmt->execute([$id]);

    wf_emit('change_type', 'deleted', (int)$id, $name);
    echo json_encode(['success' => true]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
