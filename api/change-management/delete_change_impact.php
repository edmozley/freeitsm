<?php
/**
 * API Endpoint: Delete change impact
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
        throw new Exception('Impact ID is required');
    }

    $conn = connectToDatabase();

    $isDefault = (int) $conn->query("SELECT is_default FROM change_impacts WHERE id = " . (int)$id)->fetchColumn();
    if ($isDefault === 1) {
        throw new Exception('Cannot delete the default impact. Set another impact as default first.');
    }

    $checkStmt = $conn->prepare("SELECT COUNT(*) FROM changes WHERE impact_id = ?");
    $checkStmt->execute([$id]);
    $count = (int)$checkStmt->fetchColumn();

    if ($count > 0) {
        throw new Exception("Cannot delete: this impact is used by $count change(s). Reassign them or set the impact to inactive instead.");
    }

    $name = $conn->query("SELECT name FROM change_impacts WHERE id = " . (int)$id)->fetchColumn() ?: null;
    $stmt = $conn->prepare("DELETE FROM change_impacts WHERE id = ?");
    $stmt->execute([$id]);

    wf_emit('change_impact', 'deleted', (int)$id, $name);
    echo json_encode(['success' => true]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
