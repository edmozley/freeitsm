<?php
/**
 * API Endpoint: Delete an asset location.
 *
 * Refuses to delete a node that still has children — the caller must remove or
 * re-parent the children first. This keeps the tree intact and avoids silently
 * wiping a whole branch. (The DB self-ref FK is RESTRICT as a backstop.)
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
    $id = !empty($data['id']) ? (int)$data['id'] : null;
    if (!$id) {
        throw new Exception('Missing location id');
    }

    $conn = connectToDatabase();

    $childStmt = $conn->prepare("SELECT COUNT(*) FROM asset_locations WHERE parent_id = ?");
    $childStmt->execute([$id]);
    if ((int)$childStmt->fetchColumn() > 0) {
        throw new Exception('This location has sub-locations. Delete or move them first.');
    }

    $name = $conn->query("SELECT name FROM asset_locations WHERE id = " . (int)$id)->fetchColumn() ?: null;
    $stmt = $conn->prepare("DELETE FROM asset_locations WHERE id = ?");
    $stmt->execute([$id]);

    wf_emit('asset_location', 'deleted', (int)$id, $name);
    echo json_encode(['success' => true]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
