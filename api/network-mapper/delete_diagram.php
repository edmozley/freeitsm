<?php
/**
 * API: Delete a single diagram (a single version row).
 *
 * POST { id }
 *
 * Cascades to nodes + connectors. Does NOT delete other versions in the chain
 * — only the requested version is removed. If you delete a middle version,
 * its child's parent_diagram_id is left dangling and gets nulled by the FK's
 * ON DELETE SET NULL, which breaks the chain at that point. Generally users
 * should only delete leaves; the UI should make that the default action.
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
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $id = isset($data['id']) ? (int)$data['id'] : 0;
    if ($id <= 0) throw new Exception('id is required');

    $conn = connectToDatabase();
    $stmt = $conn->prepare("DELETE FROM network_diagrams WHERE id = ?");
    $stmt->execute([$id]);

    if ($stmt->rowCount() === 0) throw new Exception('Diagram not found');
    echo json_encode(['success' => true]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
