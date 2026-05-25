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

    // Walk descendants for the count (depth-first, capped to avoid runaway loops)
    $countDescendants = function (PDO $conn, int $rootId): int {
        $total = 0;
        $stack = [$rootId];
        $hops  = 0;
        while ($stack && $hops < 10000) {
            $cur = array_pop($stack);
            $s = $conn->prepare("SELECT id FROM cmdb_objects WHERE parent_id = ?");
            $s->execute([$cur]);
            foreach ($s->fetchAll(PDO::FETCH_COLUMN) as $childId) {
                $total++;
                $stack[] = (int)$childId;
            }
            $hops++;
        }
        return $total;
    };
    $descendants = $countDescendants($conn, $id);

    $stmt = $conn->prepare("DELETE FROM cmdb_objects WHERE id = ?");
    $stmt->execute([$id]);

    echo json_encode(['success' => true, 'deleted_descendants' => $descendants]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
