<?php
/**
 * API: save_field_layout
 *
 * Atomically replaces the configurable Change-form layout. Expects:
 *   {
 *     sections: [{ id?: int, name: string, display_order: int }],
 *     fields:   [{ key: string, section_id: int, display_order: int,
 *                  is_visible: bool }]
 *   }
 *
 * Behaviour:
 *   - All operations run inside a single transaction.
 *   - Sections with `id` are updated; sections without `id` are inserted.
 *     Any section in the DB whose id is NOT present in the request is
 *     deleted (ON DELETE CASCADE drops its change_field_layout rows;
 *     callers MUST move those fields to a kept section first).
 *   - Fields are upserted on field_key (unique index uq_cfl_field_key).
 *     Fields not in the request are left alone (we don't expose a "delete
 *     a field" operation — the field catalogue is hardcoded in
 *     get_field_layout.php so the set of keys is bounded).
 *   - Returns the same shape as get_field_layout.php so the client can
 *     refresh its state in one round trip.
 */
session_start(['read_and_close' => true]);
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/_field_catalogue.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body)) {
    echo json_encode(['success' => false, 'error' => 'Invalid JSON body']);
    exit;
}

$sectionsIn = isset($body['sections']) && is_array($body['sections']) ? $body['sections'] : null;
$fieldsIn   = isset($body['fields'])   && is_array($body['fields'])   ? $body['fields']   : null;
if ($sectionsIn === null || $fieldsIn === null) {
    echo json_encode(['success' => false, 'error' => 'Missing sections or fields']);
    exit;
}

// Basic shape validation. Reject early with a clear message so the UI can
// surface it rather than getting a confusing SQL error mid-transaction.
foreach ($sectionsIn as $i => $s) {
    if (!isset($s['name']) || !is_string($s['name']) || trim($s['name']) === '') {
        echo json_encode(['success' => false, 'error' => "Section #{$i}: name is required"]);
        exit;
    }
    if (mb_strlen($s['name']) > 100) {
        echo json_encode(['success' => false, 'error' => "Section #{$i}: name too long (max 100 chars)"]);
        exit;
    }
}
foreach ($fieldsIn as $i => $f) {
    if (!isset($f['key']) || !is_string($f['key']) || $f['key'] === '') {
        echo json_encode(['success' => false, 'error' => "Field #{$i}: key is required"]);
        exit;
    }
    if (!isset($f['section_id'])) {
        echo json_encode(['success' => false, 'error' => "Field #{$i} ({$f['key']}): section_id is required"]);
        exit;
    }
}

try {
    $conn = connectToDatabase();
    $conn->beginTransaction();

    // ----- Sections -----
    // Step 1: gather kept ids (anything we'll keep/update)
    // Step 2: delete any existing section that isn't in the kept set
    //         (its layout rows cascade)
    // Step 3: update existing, insert new — mapping local "tempIds" so
    //         field rows can reference newly-created sections
    $keepIds = [];
    foreach ($sectionsIn as $s) {
        if (!empty($s['id']) && (int)$s['id'] > 0) {
            $keepIds[] = (int)$s['id'];
        }
    }

    // Delete sections not in keepIds
    if (count($keepIds) > 0) {
        $placeholders = implode(',', array_fill(0, count($keepIds), '?'));
        $delStmt = $conn->prepare("DELETE FROM change_field_sections WHERE id NOT IN ($placeholders)");
        $delStmt->execute($keepIds);
    } else {
        // No kept sections: delete everything
        $conn->exec("DELETE FROM change_field_sections");
    }

    // Track tempId → real DB id so field rows in the same payload can refer
    // to brand-new sections by their negative tempId.
    $idMap = [];

    $updStmt = $conn->prepare(
        "UPDATE change_field_sections SET name = ?, display_order = ? WHERE id = ?"
    );
    $insStmt = $conn->prepare(
        "INSERT INTO change_field_sections (name, display_order) VALUES (?, ?)"
    );

    foreach ($sectionsIn as $s) {
        $name = trim($s['name']);
        $order = (int)($s['display_order'] ?? 0);
        if (!empty($s['id']) && (int)$s['id'] > 0) {
            $updStmt->execute([$name, $order, (int)$s['id']]);
        } else {
            // tempId is negative or missing — the client uses negative ids
            // for sections it just created so it can reference them in
            // field assignments in the same request.
            $insStmt->execute([$name, $order]);
            $newId = (int)$conn->lastInsertId();
            if (isset($s['id']) && (int)$s['id'] < 0) {
                $idMap[(int)$s['id']] = $newId;
            }
        }
    }

    // ----- Fields -----
    // FIELD_CATALOGUE comes from _field_catalogue.php (shared with the
    // read endpoint) — it's the bounded set of keys the change form
    // supports. Anything outside the catalogue is silently dropped.
    $allowedKeys = array_keys(FIELD_CATALOGUE);

    // Build the set of valid section ids after our section updates so we
    // can reject field rows that point at deleted / unknown sections.
    $sidStmt = $conn->query("SELECT id FROM change_field_sections");
    $validSectionIds = array_map('intval', array_column($sidStmt->fetchAll(PDO::FETCH_ASSOC), 'id'));

    $fieldUpsert = $conn->prepare(
        "INSERT INTO change_field_layout (field_key, section_id, display_order, is_visible)
         VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            section_id = VALUES(section_id),
            display_order = VALUES(display_order),
            is_visible = VALUES(is_visible)"
    );

    foreach ($fieldsIn as $f) {
        $key = $f['key'];
        if (!in_array($key, $allowedKeys, true)) {
            // Silently skip unknown keys rather than failing the whole save
            continue;
        }
        $sid = (int)$f['section_id'];
        if ($sid < 0 && isset($idMap[$sid])) {
            $sid = $idMap[$sid]; // resolved newly-inserted section
        }
        if (!in_array($sid, $validSectionIds, true)) {
            throw new Exception("Field {$key} references unknown section_id {$sid}");
        }
        $order = (int)($f['display_order'] ?? 0);
        $visible = !empty($f['is_visible']) ? 1 : 0;
        $fieldUpsert->execute([$key, $sid, $order, $visible]);
    }

    $conn->commit();

    // Return the fresh layout so callers can replace their local copy.
    $sectionsOut = $conn->query(
        "SELECT id, name, display_order FROM change_field_sections ORDER BY display_order, id"
    )->fetchAll(PDO::FETCH_ASSOC);
    foreach ($sectionsOut as &$s) {
        $s['id'] = (int)$s['id'];
        $s['display_order'] = (int)$s['display_order'];
    }
    unset($s);

    $fieldsOut = $conn->query(
        "SELECT field_key, section_id, display_order, is_visible
         FROM change_field_layout
         ORDER BY section_id, display_order, id"
    )->fetchAll(PDO::FETCH_ASSOC);

    $fieldsResp = [];
    foreach ($fieldsOut as $r) {
        $key = $r['field_key'];
        if (!array_key_exists($key, FIELD_CATALOGUE)) continue;
        $fieldsResp[] = [
            'key'           => $key,
            'label'         => FIELD_CATALOGUE[$key],
            'section_id'    => (int)$r['section_id'],
            'display_order' => (int)$r['display_order'],
            'is_visible'    => (bool)$r['is_visible'],
        ];
    }

    echo json_encode([
        'success'  => true,
        'sections' => $sectionsOut,
        'fields'   => $fieldsResp,
    ]);

} catch (Exception $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
