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
    // Where time is offered (GH #112). Defaults to everywhere: the feature is
    // additive, and an empty Time section on a task nobody logs time against
    // costs nothing, whereas defaulting to off would hide a requested feature
    // behind a setting nobody knew to look for.
    if (!isset($settings['time_scope'])) {
        $settings['time_scope'] = 'both';
    }
    // Per-person completion on a task (GH #89). OFF by default, and the opposite
    // call to time_scope's above for a reason: what was asked for was to SEE who
    // else is on a task, which needs no setting and is always on. A tick box each
    // is a heavier, more procedural thing, so it is offered rather than imposed.
    if (!isset($settings['collaborator_completion'])) {
        $settings['collaborator_completion'] = '0';
    }

    // card_fields — which extras show on board cards. Stored as JSON;
    // always returned as a complete object so callers needn't merge defaults.
    //
    // `priority` is the one entry that is NOT a boolean: it holds a placement —
    // off / dot / pill / border — because discussion #108 asked to choose how
    // priority reads on a card, not merely whether it appears. It stays inside
    // card_fields rather than becoming a second setting so that "how priority
    // shows on a card" has one home. Legacy rows hold the old 1/0 and are read
    // as dot/off, which is exactly what those installs render today.
    $cardDefaults = [
        'assignee'    => 1,
        'team'        => 0,
        'start_date'  => 0,
        'due_date'    => 1,
        'description' => 0,
        'subtasks'    => 1,
        'links'       => 1,
    ];
    $priorityStyles  = ['off', 'dot', 'pill', 'border'];
    $priorityDefault = 'dot';

    $cardFields = $cardDefaults;
    $cardFields['priority'] = $priorityDefault;
    if (isset($settings['card_fields'])) {
        $decoded = json_decode($settings['card_fields'], true);
        if (is_array($decoded)) {
            foreach ($cardDefaults as $k => $v) {
                $cardFields[$k] = empty($decoded[$k]) ? 0 : 1;
            }
            if (array_key_exists('priority', $decoded)) {
                $p = $decoded['priority'];
                if (is_string($p) && in_array($p, $priorityStyles, true)) {
                    $cardFields['priority'] = $p;
                } else {
                    $cardFields['priority'] = empty($p) ? 'off' : 'dot';
                }
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
