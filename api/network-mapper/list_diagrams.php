<?php
/**
 * API: List Network Mapper diagrams.
 *
 * Returns the "current" (= leaf, no children) version of each diagram chain.
 * A chain is a series of versions linked by parent_diagram_id. The leaf is
 * the editable version; older versions are historical records.
 *
 * Each returned row carries:
 *   - id, title, description, version_label, author name, created_at, updated_at
 *   - node_count, connector_count for the leaf version
 *   - version_count for the whole chain
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
    $conn = connectToDatabase();

    // Leaf versions = rows that are not the parent of any other row
    $stmt = $conn->query(
        "SELECT d.id, d.parent_diagram_id, d.title, d.description, d.version_label,
                d.created_by_analyst_id, a.full_name AS author_name,
                d.created_datetime, d.updated_datetime
           FROM network_diagrams d
      LEFT JOIN analysts a ON a.id = d.created_by_analyst_id
          WHERE d.id NOT IN (SELECT parent_diagram_id FROM network_diagrams WHERE parent_diagram_id IS NOT NULL)
       ORDER BY d.updated_datetime DESC"
    );
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$rows) {
        echo json_encode(['success' => true, 'diagrams' => []]);
        exit;
    }

    // Per-row counts: nodes + connectors for this leaf, plus length of the chain
    $nodeCount = $conn->prepare("SELECT COUNT(*) FROM network_diagram_nodes WHERE diagram_id = ?");
    $connCount = $conn->prepare("SELECT COUNT(*) FROM network_diagram_connectors WHERE diagram_id = ?");

    foreach ($rows as &$r) {
        $r['id'] = (int)$r['id'];
        $r['parent_diagram_id'] = $r['parent_diagram_id'] !== null ? (int)$r['parent_diagram_id'] : null;
        $nodeCount->execute([$r['id']]);
        $r['node_count'] = (int)$nodeCount->fetchColumn();
        $connCount->execute([$r['id']]);
        $r['connector_count'] = (int)$connCount->fetchColumn();
        $r['version_count'] = countVersionsInChain($conn, $r['id']);
    }

    echo json_encode(['success' => true, 'diagrams' => $rows]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

/** Walk back to the root of the chain, then count members. Capped to avoid pathological loops. */
function countVersionsInChain($conn, $leafId) {
    $rootId = $leafId;
    $cursor = $leafId;
    $hops = 0;
    while ($hops < 200) {
        $stmt = $conn->prepare("SELECT parent_diagram_id FROM network_diagrams WHERE id = ?");
        $stmt->execute([$cursor]);
        $parent = $stmt->fetchColumn();
        if (!$parent) break;
        $rootId = (int)$parent;
        $cursor = $rootId;
        $hops++;
    }
    // Count members of the subtree rooted at $rootId
    $count = 0;
    $queue = [$rootId];
    $hops = 0;
    while ($queue && $hops < 500) {
        $next = [];
        $place = implode(',', array_fill(0, count($queue), '?'));
        $stmt = $conn->prepare("SELECT id FROM network_diagrams WHERE parent_diagram_id IN ($place)");
        $stmt->execute($queue);
        $count += count($queue);
        $children = $stmt->fetchAll(PDO::FETCH_COLUMN);
        if (!$children) break;
        $queue = array_map('intval', $children);
        $hops++;
    }
    return $count;
}
