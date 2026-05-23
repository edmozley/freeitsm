<?php
/**
 * Save (create or update) a process with its steps and connectors.
 *
 * For updates: replaces all steps and connectors (delete + re-insert).
 * Step IDs are re-assigned on save; the frontend reloads after saving.
 */
session_start();
require_once '../../config.php';
require_once '../../includes/functions.php';
header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$input  = json_decode(file_get_contents('php://input'), true);
$id     = (int)($input['id'] ?? 0);
$title  = trim($input['title'] ?? '');
$steps  = $input['steps'] ?? [];
$conns  = $input['connectors'] ?? [];
$groups = $input['groups'] ?? [];
$lanes  = $input['lanes']  ?? [];
$annotations = $input['annotations'] ?? [];

if (empty($title)) {
    echo json_encode(['success' => false, 'error' => 'Title is required']);
    exit;
}

try {
    $conn = connectToDatabase();
    $conn->beginTransaction();

    if ($id) {
        // Update existing
        $stmt = $conn->prepare("UPDATE processes SET title = ?, updated_datetime = UTC_TIMESTAMP() WHERE id = ?");
        $stmt->execute([$title, $id]);
    } else {
        // Create new
        $stmt = $conn->prepare("INSERT INTO processes (title, created_by, created_datetime, updated_datetime) VALUES (?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())");
        $stmt->execute([$title, $_SESSION['analyst_id']]);
        $id = (int)$conn->lastInsertId();
    }

    // Replace steps/connectors/groups/lanes: delete old, insert new.
    // Order matters: connectors FK to steps, steps FK to lanes (via lane_id).
    $conn->prepare("DELETE FROM process_connectors WHERE process_id = ?")->execute([$id]);
    $conn->prepare("DELETE FROM process_steps WHERE process_id = ?")->execute([$id]);
    $conn->prepare("DELETE FROM process_groups WHERE process_id = ?")->execute([$id]);
    $conn->prepare("DELETE FROM process_lanes WHERE process_id = ?")->execute([$id]);
    // process_annotations shipped in #334 — defensive try/catch so older installs
    // that haven't run db_verify don't take a hard fail when saving.
    try { $conn->prepare("DELETE FROM process_annotations WHERE process_id = ?")->execute([$id]); } catch (Exception $e) {}

    // Insert lanes first so we can map old IDs/tempIds -> new real IDs for step.lane_id.
    $laneIdMap = [];
    $laneInsert = $conn->prepare("INSERT INTO process_lanes (process_id, label, color, color2, display_order, height) VALUES (?, ?, ?, ?, ?, ?)");
    foreach ($lanes as $li => $l) {
        $oldLaneRef = $l['id'] ?? ($l['tempId'] ?? "_idx_$li");
        $lColor2 = $l['color2'] ?? null;
        if ($lColor2 === '') $lColor2 = null;
        $laneInsert->execute([
            $id,
            $l['label'] ?? '',
            $l['color'] ?? '#f5f7fa',
            $lColor2,
            (int)($l['display_order'] ?? $li),
            (int)($l['height'] ?? 180),
        ]);
        $laneIdMap[$oldLaneRef] = (int)$conn->lastInsertId();
    }

    // Insert groups BEFORE steps so we can map old IDs/tempIds -> real IDs for step.group_id.
    $groupIdMap = [];
    $groupInsert = $conn->prepare("INSERT INTO process_groups (process_id, label, color, color2, x, y, width, height) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    foreach ($groups as $gi => $g) {
        $oldGroupRef = $g['id'] ?? ($g['tempId'] ?? "_idx_$gi");
        $gColor2 = $g['color2'] ?? null;
        if ($gColor2 === '') $gColor2 = null;
        $groupInsert->execute([
            $id,
            $g['label'] ?? '',
            $g['color'] ?? '#e3f2fd',
            $gColor2,
            (int)($g['x'] ?? 0),
            (int)($g['y'] ?? 0),
            (int)($g['width'] ?? 240),
            (int)($g['height'] ?? 160),
        ]);
        $groupIdMap[$oldGroupRef] = (int)$conn->lastInsertId();
    }

    // Map old step IDs/tempIds to new real IDs. Steps reference lanes via lane_id
    // and groups via group_id; both refs are translated through the maps above.
    $idMap = [];
    $stepInsert = $conn->prepare("INSERT INTO process_steps (process_id, type, label, description, url, x, y, width, height, color, color2, lane_id, group_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

    foreach ($steps as $i => $s) {
        $oldId = $s['id'] ?? ($s['tempId'] ?? $i);
        $color2 = $s['color2'] ?? null;
        if ($color2 === '') $color2 = null;
        $url = $s['url'] ?? null;
        if ($url === '') $url = null;
        $laneRef = $s['lane_id'] ?? null;
        $laneId = ($laneRef !== null && isset($laneIdMap[$laneRef])) ? $laneIdMap[$laneRef] : null;
        $groupRef = $s['group_id'] ?? null;
        $groupId = ($groupRef !== null && isset($groupIdMap[$groupRef])) ? $groupIdMap[$groupRef] : null;
        $stepInsert->execute([
            $id,
            $s['type'] ?? 'process',
            $s['label'] ?? '',
            $s['description'] ?? '',
            $url,
            (int)($s['x'] ?? 0),
            (int)($s['y'] ?? 0),
            (int)($s['width'] ?? 160),
            (int)($s['height'] ?? 80),
            $s['color'] ?? '#0078d4',
            $color2,
            $laneId,
            $groupId,
        ]);
        $idMap[$oldId] = (int)$conn->lastInsertId();
    }

    // Insert connectors with mapped IDs
    $connInsert = $conn->prepare("INSERT INTO process_connectors (process_id, from_step_id, to_step_id, label) VALUES (?, ?, ?, ?)");
    foreach ($conns as $c) {
        $fromOld = $c['from_step_id'] ?? 0;
        $toOld   = $c['to_step_id'] ?? 0;
        $fromNew = $idMap[$fromOld] ?? null;
        $toNew   = $idMap[$toOld] ?? null;
        if ($fromNew && $toNew) {
            $connInsert->execute([$id, $fromNew, $toNew, $c['label'] ?? '']);
        }
    }

    // Insert annotations (sticky notes). Defensive try/catch so older installs
    // that haven't run db_verify still save the rest of the diagram.
    try {
        $annInsert = $conn->prepare("INSERT INTO process_annotations (process_id, text, x, y, width, height, color, color2) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        foreach ($annotations as $a) {
            $aColor2 = $a['color2'] ?? null;
            if ($aColor2 === '') $aColor2 = null;
            $annInsert->execute([
                $id,
                $a['text'] ?? '',
                (int)($a['x'] ?? 0),
                (int)($a['y'] ?? 0),
                (int)($a['width']  ?? 180),
                (int)($a['height'] ?? 100),
                $a['color'] ?? '#fff59d',
                $aColor2,
            ]);
        }
    } catch (Exception $e) {}

    $conn->commit();
    echo json_encode(['success' => true, 'id' => $id]);
} catch (Exception $e) {
    if (isset($conn) && $conn->inTransaction()) $conn->rollBack();
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
