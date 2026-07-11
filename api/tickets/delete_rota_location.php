<?php
/**
 * API Endpoint: Delete rota location
 * Refuses if the location is in use by any rota entry, or if it's the default.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireModuleAccessJson('tickets');

try {
    $input = json_decode(file_get_contents('php://input'), true);
    $id = (int)($input['id'] ?? 0);

    if (!$id) {
        throw new Exception('Location ID is required');
    }

    $conn = connectToDatabase();

    $isDefault = (int) $conn->query("SELECT is_default FROM rota_locations WHERE id = " . (int)$id)->fetchColumn();
    if ($isDefault === 1) {
        throw new Exception('Cannot delete the default location. Set another location as default first.');
    }

    $checkStmt = $conn->prepare("SELECT COUNT(*) FROM ticket_rota_entries WHERE location_id = ?");
    $checkStmt->execute([$id]);
    $count = (int)$checkStmt->fetchColumn();

    if ($count > 0) {
        throw new Exception("Cannot delete: this location is used on $count rota entry/entries. Reassign them or set the location to inactive instead.");
    }

    $stmt = $conn->prepare("DELETE FROM rota_locations WHERE id = ?");
    $stmt->execute([$id]);

    echo json_encode(['success' => true]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
