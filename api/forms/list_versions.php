<?php
/**
 * API: List every version in the chain containing the requested form
 * (#442). Walks back to the root (parent_form_id IS NULL) then forward
 * collecting children. Returns the chain in version_number order
 * (oldest first) so the dropdown can show v1 → v2 → v3 in sequence.
 *
 * GET ?id=<form_id>
 * Returns { success, versions: [{ id, parent_form_id, title,
 *           description, version_number, created_by_name,
 *           created_date, modified_by_name, modified_date,
 *           is_current }] }
 *
 * is_current is true for exactly one row — the leaf with no children.
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
    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if ($id <= 0) throw new Exception('id is required');

    $conn = connectToDatabase();

    // Walk back to root. Cap at 500 hops as a runaway guard — we should
    // never see chains that deep but it's cheaper than risking a
    // pathological loop if a cycle ever got into the data.
    $rootId = $id;
    $cursor = $id;
    $hops   = 0;
    while ($hops < 500) {
        $stmt = $conn->prepare("SELECT parent_form_id FROM forms WHERE id = ?");
        $stmt->execute([$cursor]);
        $parent = $stmt->fetchColumn();
        if ($parent === false) throw new Exception('Form not found');
        if (!$parent) break;
        $rootId = (int)$parent;
        $cursor = $rootId;
        $hops++;
    }

    // Walk forward from root (BFS) collecting every member of the chain.
    $allIds = [$rootId];
    $queue  = [$rootId];
    $hops   = 0;
    while ($queue && $hops < 1000) {
        $place = implode(',', array_fill(0, count($queue), '?'));
        $stmt = $conn->prepare("SELECT id FROM forms WHERE parent_form_id IN ($place)");
        $stmt->execute($queue);
        $children = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        if (!$children) break;
        $allIds = array_merge($allIds, $children);
        $queue  = $children;
        $hops++;
    }

    $place = implode(',', array_fill(0, count($allIds), '?'));
    $stmt = $conn->prepare(
        "SELECT f.id, f.parent_form_id, f.title, f.description, f.version_number,
                f.created_by,  ca.full_name AS created_by_name,
                DATE_FORMAT(f.created_date,  '%Y-%m-%d %H:%i:%s') AS created_date,
                f.modified_by, ma.full_name AS modified_by_name,
                DATE_FORMAT(f.modified_date, '%Y-%m-%d %H:%i:%s') AS modified_date,
                (SELECT COUNT(*) FROM forms ch WHERE ch.parent_form_id = f.id) AS child_count
           FROM forms f
      LEFT JOIN analysts ca ON ca.id = f.created_by
      LEFT JOIN analysts ma ON ma.id = f.modified_by
          WHERE f.id IN ($place)
       ORDER BY f.version_number, f.id"
    );
    $stmt->execute($allIds);
    $versions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($versions as &$v) {
        $v['id']             = (int)$v['id'];
        $v['parent_form_id'] = $v['parent_form_id'] !== null ? (int)$v['parent_form_id'] : null;
        $v['version_number'] = (int)$v['version_number'];
        $v['is_current']     = ((int)$v['child_count']) === 0;
        unset($v['child_count']);
    }

    echo json_encode([
        'success'  => true,
        'versions' => $versions,
        'root_id'  => $rootId,
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
