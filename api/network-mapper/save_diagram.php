<?php
/**
 * API: Save the editable (current) diagram — metadata, nodes, and connectors.
 *
 * POST {
 *   id,              // required — the diagram id to update
 *   title,           // optional — leaves unchanged if omitted
 *   description,     // optional — leaves unchanged if omitted (pass '' to clear)
 *   version_label,   // optional
 *   nodes:      [{ id?, tempId?, cmdb_object_id, x, y, size, icon_override }],
 *   connectors: [{ id?, tempId?, from_node_id, to_node_id,
 *                  cmdb_relationship_id, label, line_style }]
 * }
 *
 * Refuses to save if the diagram is not the leaf of its chain — old versions
 * are read-only. Replaces nodes + connectors transactionally (delete + reinsert)
 * with temp-to-real id mapping for the connector references.
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

    // Refuse saves to non-leaf versions (read-only history)
    $check = $conn->prepare("SELECT COUNT(*) FROM network_diagrams WHERE parent_diagram_id = ?");
    $check->execute([$id]);
    if ((int)$check->fetchColumn() > 0) {
        throw new Exception('This is a historical version and is read-only. Create a new version to edit.');
    }

    $conn->beginTransaction();

    // Update metadata (only the fields the caller explicitly sent)
    $sets = [];
    $params = [];
    if (array_key_exists('title', $data)) {
        $t = trim((string)$data['title']);
        if ($t === '') throw new Exception('Title cannot be empty');
        if (mb_strlen($t) > 255) throw new Exception('Title too long (max 255 chars)');
        $sets[] = 'title = ?';
        $params[] = $t;
    }
    if (array_key_exists('description', $data)) {
        $d = trim((string)$data['description']);
        $sets[] = 'description = ?';
        $params[] = $d === '' ? null : $d;
    }
    if (array_key_exists('version_label', $data)) {
        $v = trim((string)$data['version_label']);
        if (mb_strlen($v) > 50) throw new Exception('Version label too long (max 50 chars)');
        $sets[] = 'version_label = ?';
        $params[] = $v === '' ? null : $v;
    }
    if (array_key_exists('paper_size', $data)) {
        // Whitelist — keep stored values within the set the UI knows how to
        // render. Empty/null means "no overlay".
        $allowedSizes = ['A4', 'A3', 'A2', 'Letter', 'Tabloid'];
        $ps = $data['paper_size'];
        $ps = ($ps === null || $ps === '') ? null : (string)$ps;
        if ($ps !== null && !in_array($ps, $allowedSizes, true)) {
            throw new Exception('Invalid paper_size');
        }
        $sets[] = 'paper_size = ?';
        $params[] = $ps;
    }
    if (array_key_exists('paper_orientation', $data)) {
        $allowedOrients = ['portrait', 'landscape'];
        $po = $data['paper_orientation'];
        $po = ($po === null || $po === '') ? null : (string)$po;
        if ($po !== null && !in_array($po, $allowedOrients, true)) {
            throw new Exception('Invalid paper_orientation');
        }
        $sets[] = 'paper_orientation = ?';
        $params[] = $po;
    }
    // Header/footer override slots. NULL = inherit the org-wide default;
    // anything else (incl. empty string '') = explicit override. Empty string
    // is meaningful — it lets the user blank a slot that the org default
    // would otherwise populate. Each slot is capped at 200 chars to match
    // the schema VARCHAR(200) and the save_branding.php cap so per-diagram
    // overrides can't exceed what the org-wide page allows.
    $brandFields = ['header_left', 'header_center', 'header_right',
                    'footer_left', 'footer_center', 'footer_right'];
    foreach ($brandFields as $bf) {
        if (!array_key_exists($bf, $data)) continue;
        $v = $data[$bf];
        if ($v === null) {
            $sets[] = "$bf = ?";
            $params[] = null;
        } else {
            $s = (string)$v;
            if (mb_strlen($s) > 200) {
                throw new Exception("'$bf' too long (max 200 characters)");
            }
            $sets[] = "$bf = ?";
            $params[] = $s;
        }
    }
    $sets[] = 'updated_datetime = UTC_TIMESTAMP()';
    $params[] = $id;
    $upd = $conn->prepare("UPDATE network_diagrams SET " . implode(', ', $sets) . " WHERE id = ?");
    $upd->execute($params);

    // Replace nodes — delete then reinsert. Build temp->real id map so the
    // connectors arriving in the same payload can reference newly-created nodes.
    $conn->prepare("DELETE FROM network_diagram_connectors WHERE diagram_id = ?")->execute([$id]);
    $conn->prepare("DELETE FROM network_diagram_nodes WHERE diagram_id = ?")->execute([$id]);

    $nodeIdMap = [];
    $nodes = is_array($data['nodes'] ?? null) ? $data['nodes'] : [];
    $insNode = $conn->prepare(
        "INSERT INTO network_diagram_nodes (diagram_id, cmdb_object_id, x, y, size, icon_override)
         VALUES (?, ?, ?, ?, ?, ?)"
    );
    foreach ($nodes as $i => $n) {
        $oldRef = $n['id'] ?? ($n['tempId'] ?? "_idx_$i");
        $cmdbId = isset($n['cmdb_object_id']) ? (int)$n['cmdb_object_id'] : 0;
        if ($cmdbId <= 0) continue; // nodes must bind to a CMDB object
        $insNode->execute([
            $id,
            $cmdbId,
            (int)($n['x'] ?? 0),
            (int)($n['y'] ?? 0),
            (string)($n['size'] ?? 'medium'),
            isset($n['icon_override']) && $n['icon_override'] !== '' ? (string)$n['icon_override'] : null,
        ]);
        $nodeIdMap[$oldRef] = (int)$conn->lastInsertId();
    }

    // Replace connectors, translating temp/real refs through the node id map
    $conns = is_array($data['connectors'] ?? null) ? $data['connectors'] : [];
    $insConn = $conn->prepare(
        "INSERT INTO network_diagram_connectors (diagram_id, from_node_id, to_node_id, cmdb_relationship_id, label, line_style)
         VALUES (?, ?, ?, ?, ?, ?)"
    );
    foreach ($conns as $c) {
        $fromRef = $c['from_node_id'] ?? null;
        $toRef   = $c['to_node_id']   ?? null;
        $from = $nodeIdMap[$fromRef] ?? null;
        $to   = $nodeIdMap[$toRef]   ?? null;
        if (!$from || !$to) continue; // can't resolve; skip silently
        $insConn->execute([
            $id,
            $from,
            $to,
            isset($c['cmdb_relationship_id']) && $c['cmdb_relationship_id'] !== '' && $c['cmdb_relationship_id'] !== null ? (int)$c['cmdb_relationship_id'] : null,
            isset($c['label']) && $c['label'] !== '' ? (string)$c['label'] : null,
            isset($c['line_style']) && $c['line_style'] !== '' ? (string)$c['line_style'] : 'solid',
        ]);
    }

    $conn->commit();
    echo json_encode(['success' => true, 'id' => $id]);
} catch (Exception $e) {
    if (isset($conn) && $conn->inTransaction()) $conn->rollBack();
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
