<?php
/**
 * API: get_field_layout
 *
 * Returns the configurable layout for the Change form:
 *   - sections: [{ id, name, display_order }] sorted by display_order
 *   - fields:   [{ key, label, section_id, display_order, is_visible }]
 *               sorted by (section_id, display_order)
 *
 * `fields` is the intersection of the FIELD_CATALOGUE constant below (the
 * hardcoded list of supported field keys with their human labels) and the
 * rows in `change_field_layout`. The catalogue is the source of truth for
 * "what fields exist on the change form" — the DB only stores placement
 * (section + order) and visibility.
 *
 * Used by:
 *   - change-management/settings/ — Form fields tab (admin reorder/toggle)
 *   - change-management/index.php  — renders the change editor form
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

try {
    $conn = connectToDatabase();

    $sectionsStmt = $conn->query(
        "SELECT id, name, display_order FROM change_field_sections ORDER BY display_order, id"
    );
    $sections = $sectionsStmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($sections as &$s) {
        $s['id'] = (int)$s['id'];
        $s['display_order'] = (int)$s['display_order'];
    }
    unset($s);

    $fieldsStmt = $conn->query(
        "SELECT field_key, section_id, display_order, is_visible
         FROM change_field_layout
         ORDER BY section_id, display_order, id"
    );
    $rows = $fieldsStmt->fetchAll(PDO::FETCH_ASSOC);

    $fields = [];
    foreach ($rows as $r) {
        $key = $r['field_key'];
        if (!array_key_exists($key, FIELD_CATALOGUE)) {
            // Field key in DB that the app doesn't know about. Skip rather
            // than fail — leaves an orphan row that the admin can later
            // remove or that we'll prune via a migration.
            continue;
        }
        $fields[] = [
            'key'           => $key,
            'label'         => FIELD_CATALOGUE[$key],
            'section_id'    => (int)$r['section_id'],
            'display_order' => (int)$r['display_order'],
            'is_visible'    => (bool)$r['is_visible'],
        ];
    }

    // Surface any catalogue entries that aren't in the layout yet (e.g.
    // a newly added field_key) so the admin can place them — they'll be
    // included as orphans the settings UI can show under a default section.
    $placed = array_column($fields, 'key');
    $unplaced = [];
    foreach (FIELD_CATALOGUE as $key => $label) {
        if (!in_array($key, $placed, true)) {
            $unplaced[] = [
                'key'   => $key,
                'label' => $label,
            ];
        }
    }

    echo json_encode([
        'success'  => true,
        'sections' => $sections,
        'fields'   => $fields,
        'unplaced' => $unplaced,
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
