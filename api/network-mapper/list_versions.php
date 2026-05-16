<?php
/**
 * API: List all versions in the chain containing the requested diagram.
 *
 * GET ?id=<diagram_id>
 *
 * Walks back to the root then forward, returning each version in chain order
 * (oldest first). Used by the version-switcher dropdown in the editor.
 */
session_start();
require_once '../../config.php';
require_once '../../includes/functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

try {
    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if ($id <= 0) throw new Exception('id is required');

    $conn = connectToDatabase();

    // Walk back to root
    $rootId = $id;
    $cursor = $id;
    $hops = 0;
    while ($hops < 200) {
        $stmt = $conn->prepare("SELECT parent_diagram_id FROM network_diagrams WHERE id = ?");
        $stmt->execute([$cursor]);
        $parent = $stmt->fetchColumn();
        if ($parent === false) throw new Exception('Diagram not found');
        if (!$parent) break;
        $rootId = (int)$parent;
        $cursor = $rootId;
        $hops++;
    }

    // Walk forward from root collecting all members of the chain (DFS)
    $allIds = [$rootId];
    $queue = [$rootId];
    $hops = 0;
    while ($queue && $hops < 500) {
        $place = implode(',', array_fill(0, count($queue), '?'));
        $stmt = $conn->prepare("SELECT id FROM network_diagrams WHERE parent_diagram_id IN ($place)");
        $stmt->execute($queue);
        $children = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        if (!$children) break;
        $allIds = array_merge($allIds, $children);
        $queue = $children;
        $hops++;
    }

    $place = implode(',', array_fill(0, count($allIds), '?'));
    $stmt = $conn->prepare(
        "SELECT d.id, d.parent_diagram_id, d.title, d.description, d.version_label,
                d.created_by_analyst_id, a.full_name AS author_name,
                d.created_datetime, d.updated_datetime,
                (SELECT COUNT(*) FROM network_diagrams ch WHERE ch.parent_diagram_id = d.id) AS child_count
           FROM network_diagrams d
      LEFT JOIN analysts a ON a.id = d.created_by_analyst_id
          WHERE d.id IN ($place)
       ORDER BY d.created_datetime"
    );
    $stmt->execute($allIds);
    $versions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($versions as &$v) {
        $v['id'] = (int)$v['id'];
        $v['parent_diagram_id'] = $v['parent_diagram_id'] !== null ? (int)$v['parent_diagram_id'] : null;
        $v['child_count'] = (int)$v['child_count'];
        $v['is_current'] = $v['child_count'] === 0;
    }

    echo json_encode(['success' => true, 'versions' => $versions, 'root_id' => $rootId]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
