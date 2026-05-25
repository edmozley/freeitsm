<?php
/**
 * API Endpoint: Delete service impact level
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

try {
    $input = json_decode(file_get_contents('php://input'), true);
    $id = (int)($input['id'] ?? 0);

    if (!$id) throw new Exception('Impact level ID is required');

    $conn = connectToDatabase();

    $isDefault = (int) $conn->query("SELECT is_default FROM service_impact_levels WHERE id = " . (int)$id)->fetchColumn();
    if ($isDefault === 1) {
        throw new Exception('Cannot delete the default impact level. Set another level as default first.');
    }

    $checkStmt = $conn->prepare("SELECT COUNT(*) FROM status_incident_services WHERE impact_level_id = ?");
    $checkStmt->execute([$id]);
    $count = (int)$checkStmt->fetchColumn();

    if ($count > 0) {
        throw new Exception("Cannot delete: this impact level is used on $count incident-service link(s). Reassign them or set the level to inactive instead.");
    }

    $stmt = $conn->prepare("DELETE FROM service_impact_levels WHERE id = ?");
    $stmt->execute([$id]);

    echo json_encode(['success' => true]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
