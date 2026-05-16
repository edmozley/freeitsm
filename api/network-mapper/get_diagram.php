<?php
/**
 * API: Get a single diagram version with all nodes and connectors hydrated.
 *
 * GET ?id=<diagram_id>
 *
 * Returns:
 *   diagram   { id, parent_diagram_id, title, description, version_label,
 *               author_id, author_name, created_at, updated_at, is_current }
 *   nodes     [{ id, cmdb_object_id, name, class_id, class_name, class_icon,
 *                is_planned, x, y, size, icon_override }]
 *   connectors[{ id, from_node_id, to_node_id, cmdb_relationship_id,
 *                label, line_style }]
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

    $stmt = $conn->prepare(
        "SELECT d.id, d.parent_diagram_id, d.title, d.description, d.version_label,
                d.created_by_analyst_id, a.full_name AS author_name,
                d.created_datetime, d.updated_datetime,
                (SELECT COUNT(*) FROM network_diagrams ch WHERE ch.parent_diagram_id = d.id) AS child_count
           FROM network_diagrams d
      LEFT JOIN analysts a ON a.id = d.created_by_analyst_id
          WHERE d.id = ?"
    );
    $stmt->execute([$id]);
    $d = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$d) throw new Exception('Diagram not found');

    $d['id'] = (int)$d['id'];
    $d['parent_diagram_id'] = $d['parent_diagram_id'] !== null ? (int)$d['parent_diagram_id'] : null;
    $d['child_count'] = (int)$d['child_count'];
    $d['is_current'] = $d['child_count'] === 0;

    // Nodes — each is bound to a CMDB object, so we hydrate name + class + icon.
    // icon_key lives on cmdb_icons (cmdb_classes only holds the FK), so a LEFT
    // JOIN turns it back into a flat string. node.icon_override (if set) wins
    // over the class default at render time.
    $nstmt = $conn->prepare(
        "SELECT n.id, n.cmdb_object_id, n.x, n.y, n.size, n.icon_override,
                o.name, o.is_planned,
                c.id AS class_id, c.name AS class_name, i.icon_key AS class_icon
           FROM network_diagram_nodes n
           JOIN cmdb_objects o ON o.id = n.cmdb_object_id
           JOIN cmdb_classes c ON c.id = o.class_id
      LEFT JOIN cmdb_icons   i ON i.id = c.icon_id
          WHERE n.diagram_id = ?
       ORDER BY n.id"
    );
    $nstmt->execute([$id]);
    $nodes = $nstmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($nodes as &$n) {
        $n['id'] = (int)$n['id'];
        $n['cmdb_object_id'] = (int)$n['cmdb_object_id'];
        $n['class_id'] = (int)$n['class_id'];
        $n['x'] = (int)$n['x'];
        $n['y'] = (int)$n['y'];
        $n['is_planned'] = (int)$n['is_planned'] === 1;
    }

    $cstmt = $conn->prepare(
        "SELECT id, from_node_id, to_node_id, cmdb_relationship_id, label, line_style
           FROM network_diagram_connectors
          WHERE diagram_id = ?
       ORDER BY id"
    );
    $cstmt->execute([$id]);
    $conns = $cstmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($conns as &$c) {
        $c['id'] = (int)$c['id'];
        $c['from_node_id'] = (int)$c['from_node_id'];
        $c['to_node_id']   = (int)$c['to_node_id'];
        $c['cmdb_relationship_id'] = $c['cmdb_relationship_id'] !== null ? (int)$c['cmdb_relationship_id'] : null;
    }

    echo json_encode(['success' => true, 'diagram' => $d, 'nodes' => $nodes, 'connectors' => $conns]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
