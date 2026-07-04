<?php
/**
 * API: Delete a single diagram (a single version row).
 *
 * POST { id }
 *
 * Removes nodes + connectors explicitly in a transaction rather than relying
 * on FK cascades — installs grown via Database Verification had NO network
 * foreign keys at all (db_verify adds FKs separately from columns), so the
 * cascades this endpoint assumed silently didn't exist there and deletes left
 * orphaned nodes/connectors behind. Does NOT delete other versions in the
 * chain — only the requested version is removed. If you delete a middle
 * version, its child's parent_diagram_id is nulled (FK SET NULL / db_verify
 * backfill), which breaks the chain at that point. Generally users should
 * only delete leaves; the UI should make that the default action.
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
    $conn->beginTransaction();
    try {
        $conn->prepare("DELETE FROM network_diagram_connectors WHERE diagram_id = ?")->execute([$id]);
        $conn->prepare("DELETE FROM network_diagram_nodes WHERE diagram_id = ?")->execute([$id]);
        $stmt = $conn->prepare("DELETE FROM network_diagrams WHERE id = ?");
        $stmt->execute([$id]);
        if ($stmt->rowCount() === 0) throw new Exception('Diagram not found');
        $conn->commit();
    } catch (Exception $e) {
        if ($conn->inTransaction()) $conn->rollBack();
        throw $e;
    }
    echo json_encode(['success' => true]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
