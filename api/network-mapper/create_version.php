<?php
/**
 * API: Save the current diagram as a new version. Clones nodes + connectors
 * forward into a new diagram row that is chained to the supplied parent.
 *
 * POST { parent_diagram_id, title, description, version_label }
 *
 * The new row becomes the leaf (the editable "current") of its chain. The
 * parent row is now a frozen historical record.
 *
 * Returns { id } of the new version.
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
    $parentId    = isset($data['parent_diagram_id']) ? (int)$data['parent_diagram_id'] : 0;
    $title       = trim((string)($data['title'] ?? ''));
    $description = trim((string)($data['description'] ?? ''));
    $versionLabel = trim((string)($data['version_label'] ?? ''));

    if ($parentId <= 0) throw new Exception('parent_diagram_id is required');
    if ($title === '') throw new Exception('Title is required');

    $conn = connectToDatabase();

    // Verify the parent exists and refuse if it already has children — we only
    // version forward from the leaf to keep the chain linear (no branching in v1).
    $check = $conn->prepare(
        "SELECT (SELECT COUNT(*) FROM network_diagrams WHERE parent_diagram_id = ?) AS child_count"
    );
    $check->execute([$parentId]);
    $childCount = (int)$check->fetchColumn();
    if ($childCount > 0) {
        throw new Exception('Can only create a new version from the current (leaf) version of the chain');
    }

    // Pull the parent's paper + header/footer overrides so the new version
    // inherits the same page setup and branding — analysts don't want to
    // re-pick A3 landscape or re-type a per-diagram footer override on every
    // version fork.
    $papStmt = $conn->prepare(
        "SELECT paper_size, paper_orientation,
                header_left, header_center, header_right,
                footer_left, footer_center, footer_right
           FROM network_diagrams WHERE id = ?"
    );
    $papStmt->execute([$parentId]);
    $paper = $papStmt->fetch(PDO::FETCH_ASSOC) ?: [
        'paper_size' => null, 'paper_orientation' => null,
        'header_left' => null, 'header_center' => null, 'header_right' => null,
        'footer_left' => null, 'footer_center' => null, 'footer_right' => null,
    ];

    $conn->beginTransaction();

    $ins = $conn->prepare(
        "INSERT INTO network_diagrams
              (parent_diagram_id, title, description, version_label,
               paper_size, paper_orientation,
               header_left, header_center, header_right,
               footer_left, footer_center, footer_right,
               created_by_analyst_id, created_datetime, updated_datetime)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())"
    );
    $ins->execute([
        $parentId,
        $title,
        $description === '' ? null : $description,
        $versionLabel === '' ? null : $versionLabel,
        $paper['paper_size'],
        $paper['paper_orientation'],
        $paper['header_left'],
        $paper['header_center'],
        $paper['header_right'],
        $paper['footer_left'],
        $paper['footer_center'],
        $paper['footer_right'],
        (int)$_SESSION['analyst_id'],
    ]);
    $newId = (int)$conn->lastInsertId();

    // Clone nodes — keep a map from old id -> new id so we can rewrite connectors
    $nodeIdMap = [];
    $oldNodes = $conn->prepare("SELECT id, cmdb_object_id, x, y, size, icon_override FROM network_diagram_nodes WHERE diagram_id = ?");
    $oldNodes->execute([$parentId]);
    $insNode = $conn->prepare(
        "INSERT INTO network_diagram_nodes (diagram_id, cmdb_object_id, x, y, size, icon_override)
         VALUES (?, ?, ?, ?, ?, ?)"
    );
    foreach ($oldNodes->fetchAll(PDO::FETCH_ASSOC) as $n) {
        $insNode->execute([
            $newId,
            (int)$n['cmdb_object_id'],
            (int)$n['x'],
            (int)$n['y'],
            $n['size'],
            $n['icon_override'],
        ]);
        $nodeIdMap[(int)$n['id']] = (int)$conn->lastInsertId();
    }

    // Clone connectors with translated node ids
    $oldConns = $conn->prepare("SELECT from_node_id, to_node_id, cmdb_relationship_id, label, line_style FROM network_diagram_connectors WHERE diagram_id = ?");
    $oldConns->execute([$parentId]);
    $insConn = $conn->prepare(
        "INSERT INTO network_diagram_connectors (diagram_id, from_node_id, to_node_id, cmdb_relationship_id, label, line_style)
         VALUES (?, ?, ?, ?, ?, ?)"
    );
    foreach ($oldConns->fetchAll(PDO::FETCH_ASSOC) as $c) {
        $from = $nodeIdMap[(int)$c['from_node_id']] ?? null;
        $to   = $nodeIdMap[(int)$c['to_node_id']]   ?? null;
        if (!$from || !$to) continue; // shouldn't happen but defensive
        $insConn->execute([
            $newId,
            $from,
            $to,
            $c['cmdb_relationship_id'] !== null ? (int)$c['cmdb_relationship_id'] : null,
            $c['label'],
            $c['line_style'],
        ]);
    }

    $conn->commit();
    echo json_encode(['success' => true, 'id' => $newId]);
} catch (Exception $e) {
    if (isset($conn) && $conn->inTransaction()) $conn->rollBack();
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
