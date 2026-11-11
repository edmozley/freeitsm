<?php
/**
 * System -> Help assistant (wiki Help-Assistant-Design): the install-wide switch,
 * who has it, the level it is at, and the index.
 *
 * GET  -> {enabled, level, analysts: [{id, name, username, on}], index: {...}}
 * POST {"action": "save", "enabled": true, "analysts": [1, 4, 9]}
 *      Saves the switch and exactly who has it (every active analyst not listed
 *      is turned off). Switching it on with no index yet starts a build.
 * POST {"action": "rebuild"}  -> starts a rebuild in the background
 *
 * Administrators only, like every System endpoint.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/admin_api_guard.php';
require_once '../../includes/help_assistant.php';

header('Content-Type: application/json');

/** Everything the page shows. */
function helpAssistantAdminState(PDO $conn): array
{
    $analysts = $conn->query("SELECT id, full_name, username, help_assistant FROM analysts WHERE COALESCE(is_active, 1) = 1 ORDER BY full_name, username")->fetchAll(PDO::FETCH_ASSOC);
    return [
        'success'  => true,
        'enabled'  => helpAssistantEnabled($conn),
        'level'    => helpAssistantLevel($conn)['level'],
        'analysts' => array_map(fn($a) => ['id' => (int)$a['id'], 'name' => (string)$a['full_name'], 'username' => (string)$a['username'], 'on' => (bool)$a['help_assistant']], $analysts),
        'index'    => helpAssistantIndexStatus($conn),
    ];
}

try {
    $conn = connectToDatabase();
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    if (!helpAssistantReady($conn)) {
        echo json_encode(['success' => false, 'error' => 'Run System -> Database Verification first - it adds what the help assistant needs.']);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        echo json_encode(helpAssistantAdminState($conn), JSON_UNESCAPED_UNICODE);
        exit;
    }

    $in = json_decode(file_get_contents('php://input'), true);
    if (!is_array($in)) throw new InvalidArgumentException('Invalid JSON');

    $started = false;
    if (($in['action'] ?? '') === 'rebuild') {
        $started = helpAssistantStartRebuild($conn);
    } elseif (($in['action'] ?? '') === 'save') {
        $on = array_values(array_unique(array_filter(array_map('intval', (array)($in['analysts'] ?? [])), fn($id) => $id > 0)));
        $conn->beginTransaction();
        helpAssistantSetSetting($conn, 'help_assistant_enabled', !empty($in['enabled']) ? '1' : '0');
        $conn->exec("UPDATE analysts SET help_assistant = 0 WHERE COALESCE(is_active, 1) = 1");
        if ($on) {
            $conn->prepare("UPDATE analysts SET help_assistant = 1 WHERE COALESCE(is_active, 1) = 1 AND id IN (" . implode(',', array_fill(0, count($on), '?')) . ")")->execute($on);
        }
        $conn->commit();
        // Switched on with nothing to search yet: build it now rather than leave
        // the window answering "nothing found" to everything.
        if (!empty($in['enabled']) && !helpAssistantIndexStatus($conn)['entries']) $started = helpAssistantStartRebuild($conn);
    } else {
        throw new InvalidArgumentException('Unknown action');
    }

    echo json_encode(helpAssistantAdminState($conn) + ['rebuild_started' => $started], JSON_UNESCAPED_UNICODE);
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} catch (Throwable $e) {
    if (isset($conn) && $conn->inTransaction()) $conn->rollBack();
    error_log('help assistant settings: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Something went wrong.']);
}
