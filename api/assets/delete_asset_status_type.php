<?php
/**
 * API Endpoint: Delete asset status type
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

requireModuleAccessJson('assets');

try {
    $data = json_decode(file_get_contents('php://input'), true);
    $id = $data['id'] ?? null;

    if (!$id) {
        throw new Exception('ID is required');
    }

    $conn = connectToDatabase();

    // Nullify any assets referencing this status before deleting
    $stmt = $conn->prepare("UPDATE assets SET asset_status_id = NULL WHERE asset_status_id = ?");
    $stmt->execute([$id]);

    $name = $conn->query("SELECT name FROM asset_status_types WHERE id = " . (int)$id)->fetchColumn() ?: null;
    $stmt = $conn->prepare("DELETE FROM asset_status_types WHERE id = ?");
    $stmt->execute([$id]);

    wf_emit('asset_status', 'deleted', (int)$id, $name);
    echo json_encode(['success' => true]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

?>
