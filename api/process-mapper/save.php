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

$input = json_decode(file_get_contents('php://input'), true);
$id    = (int)($input['id'] ?? 0);
$title = trim($input['title'] ?? '');
$steps = $input['steps'] ?? [];
$conns = $input['connectors'] ?? [];

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

    // Replace steps: delete old, insert new
    $conn->prepare("DELETE FROM process_connectors WHERE process_id = ?")->execute([$id]);
    $conn->prepare("DELETE FROM process_steps WHERE process_id = ?")->execute([$id]);

    // Map old step IDs/tempIds to new real IDs
    $idMap = [];
    $stepInsert = $conn->prepare("INSERT INTO process_steps (process_id, type, label, description, x, y, width, height, color) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");

    foreach ($steps as $i => $s) {
        $oldId = $s['id'] ?? ($s['tempId'] ?? $i);
        $stepInsert->execute([
            $id,
            $s['type'] ?? 'process',
            $s['label'] ?? '',
            $s['description'] ?? '',
            (int)($s['x'] ?? 0),
            (int)($s['y'] ?? 0),
            (int)($s['width'] ?? 160),
            (int)($s['height'] ?? 80),
            $s['color'] ?? '#0078d4'
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

    $conn->commit();
    echo json_encode(['success' => true, 'id' => $id]);
} catch (Exception $e) {
    if (isset($conn) && $conn->inTransaction()) $conn->rollBack();
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
