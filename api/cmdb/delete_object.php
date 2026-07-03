<?php
/**
 * API: Delete a CMDB object.
 * Cascade-deletes all descendants per the design's ontological-dependency
 * parent semantics (FK is ON DELETE CASCADE on parent_id, value_object_id,
 * and from/to_object_id). Returns the count of descendants that went with it
 * so the UI can confirm the right thing was removed.
 *
 * The UI is expected to warn before calling this when child_count > 0.
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

    $check = $conn->prepare("SELECT id FROM cmdb_objects WHERE id = ?");
    $check->execute([$id]);
    if (!$check->fetchColumn()) throw new Exception('Object not found');

    // Collect the descendant tree (depth-first, cycle-safe, capped)
    $ids = [$id];
    $stack = [$id];
    $seen = [$id => true];
    $hops = 0;
    $childStmt = $conn->prepare("SELECT id FROM cmdb_objects WHERE parent_id = ?");
    while ($stack && $hops < 10000) {
        $cur = array_pop($stack);
        $childStmt->execute([$cur]);
        foreach ($childStmt->fetchAll(PDO::FETCH_COLUMN) as $childId) {
            $childId = (int)$childId;
            if (isset($seen[$childId])) continue;
            $seen[$childId] = true;
            $ids[] = $childId;
            $stack[] = $childId;
        }
        $hops++;
    }
    $descendants = count($ids) - 1;

    // Delete children explicitly rather than relying on FK cascades — installs
    // grown via Database Verify had NO CMDB foreign keys at all (db_verify adds
    // FKs separately from columns), so the cascades this endpoint assumed
    // (properties, relationships, ticket links, descendants) silently didn't
    // exist there and deletes left orphans behind.
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $conn->beginTransaction();
    try {
        // object_ref values pointing at anything in the tree -> NULL (the FK's SET NULL rule)
        $conn->prepare("UPDATE cmdb_object_properties SET value_object_id = NULL WHERE value_object_id IN ($ph)")->execute($ids);
        $conn->prepare("DELETE FROM cmdb_object_properties WHERE object_id IN ($ph)")->execute($ids);
        $conn->prepare("DELETE FROM cmdb_object_relationships WHERE from_object_id IN ($ph) OR to_object_id IN ($ph)")->execute(array_merge($ids, $ids));
        $conn->prepare("DELETE FROM ticket_cmdb_objects WHERE cmdb_object_id IN ($ph)")->execute($ids);
        foreach (array_reverse($ids) as $oid) {
            $conn->prepare("DELETE FROM cmdb_objects WHERE id = ?")->execute([$oid]);
        }
        $conn->commit();
    } catch (Exception $e) {
        if ($conn->inTransaction()) $conn->rollBack();
        throw $e;
    }

    echo json_encode(['success' => true, 'deleted_descendants' => $descendants]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
