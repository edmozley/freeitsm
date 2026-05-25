<?php
/**
 * API: Tasks — Get module settings
 * Returns tasks_* keys from system_settings (prefix stripped).
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
    $conn = connectToDatabase();

    $stmt = $conn->prepare("SELECT setting_key, setting_value FROM system_settings WHERE setting_key LIKE 'tasks_%'");
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $settings = [];
    foreach ($rows as $row) {
        $key = substr($row['setting_key'], strlen('tasks_'));
        $settings[$key] = $row['setting_value'];
    }

    // Defaults for keys that haven't been saved yet
    if (!isset($settings['calendar_span_mode'])) {
        $settings['calendar_span_mode'] = 'deadline';
    }

    // card_fields — which extras show on board cards. Stored as JSON;
    // always returned as a complete object so callers needn't merge defaults.
    $cardDefaults = [
        'priority'    => 1,
        'assignee'    => 1,
        'team'        => 0,
        'start_date'  => 0,
        'due_date'    => 1,
        'description' => 0,
        'subtasks'    => 1,
        'links'       => 1,
    ];
    $cardFields = $cardDefaults;
    if (isset($settings['card_fields'])) {
        $decoded = json_decode($settings['card_fields'], true);
        if (is_array($decoded)) {
            foreach ($cardDefaults as $k => $v) {
                $cardFields[$k] = empty($decoded[$k]) ? 0 : 1;
            }
        }
    }
    $settings['card_fields'] = $cardFields;

    // tag_settings — where tags appear, and whether they can be created inline.
    // Always returned complete so callers needn't merge defaults.
    $tagDefaults = [
        'allow_create'     => 0,
        'surface_card'     => 1,
        'surface_filter'   => 1,
        'surface_search'   => 1,
        'surface_calendar' => 0,
    ];
    $tagSettings = $tagDefaults;
    if (isset($settings['tag_settings'])) {
        $decoded = json_decode($settings['tag_settings'], true);
        if (is_array($decoded)) {
            foreach ($tagDefaults as $k => $v) {
                $tagSettings[$k] = empty($decoded[$k]) ? 0 : 1;
            }
        }
    }
    $settings['tag_settings'] = $tagSettings;

    echo json_encode(['success' => true, 'settings' => $settings]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
