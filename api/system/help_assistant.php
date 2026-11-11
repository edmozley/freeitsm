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
 * Matching by meaning (step 4, includes/help_vectors.php):
 * POST {"action": "save_vectors", "enabled": true, "provider": "openai", "model": "...",
 *       "api_key": "" (blank or **** = keep the saved one), azure_*, compatible_base_url}
 *      Saved through aiSettingsSave(), which ENCRYPTS the key. Only providers that can
 *      embed are accepted. The first time it is switched on, vectors are built.
 * POST {"action": "models", provider, api_key?, azure_*, compatible_base_url}
 *      -> the provider's embedding models, for the dropdown (a blank key uses the saved one)
 * POST {"action": "test_vectors"}  -> embeds one sentence with the saved settings
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
        'vectors'  => helpVectorsStatus($conn),
    ];
}

/**
 * The embedding settings posted, completed from what is saved: a blank (or ****)
 * key means the saved one. Never echoed back.
 */
function helpAssistantPostedVectorCfg(PDO $conn, array $in): array
{
    $saved = helpVectorsConfig($conn);
    $provider = (string)($in['provider'] ?? '');
    if (!in_array($provider, AI_EMBEDDING_PROVIDERS, true)) throw new InvalidArgumentException('Choose OpenAI, Azure OpenAI or your own model server - those are the providers that can make embeddings.');
    $key = (string)($in['api_key'] ?? '');
    return [
        'provider' => $provider,
        'model'    => trim((string)($in['model'] ?? $saved['model'])),
        'api_key'  => isMaskedNoChangeValue($key) ? (string)$saved['api_key'] : trim($key),
        'azure_endpoint'      => trim((string)($in['azure_endpoint'] ?? $saved['azure_endpoint'])),
        'azure_deployment'    => trim((string)($in['azure_deployment'] ?? $saved['azure_deployment'])),
        'azure_api_version'   => trim((string)($in['azure_api_version'] ?? $saved['azure_api_version'])),
        'compatible_base_url' => trim((string)($in['compatible_base_url'] ?? $saved['compatible_base_url'])),
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
    } elseif (($in['action'] ?? '') === 'save_vectors') {
        $enabled = !empty($in['enabled']);
        if ($enabled) {
            $cfg = helpAssistantPostedVectorCfg($conn, $in);
            if ($cfg['model'] === '') throw new InvalidArgumentException('Choose a model.');
            // Through the shared AI settings, which encrypt the key (aiSettingsSave()).
            aiSettingsSave($conn, HELP_VECTORS_NS, [
                'provider' => $cfg['provider'], 'model' => $cfg['model'], 'api_key' => (string)($in['api_key'] ?? ''),
                'azure_endpoint' => $cfg['azure_endpoint'], 'azure_deployment' => $cfg['azure_deployment'], 'azure_api_version' => $cfg['azure_api_version'],
                'compatible_base_url' => $cfg['compatible_base_url'],
            ]);
        }
        helpVectorsSet($conn, 'help_vectors_enabled', $enabled ? '1' : '0');
        // The first time: build them now. A CHANGE of model is not started here - the
        // page warns and offers Rebuild, because it may cost money.
        if ($enabled && helpVectorsActiveModel($conn) === null && helpVectorsUsable(helpVectorsConfig($conn))) $started = helpAssistantStartRebuild($conn);
    } elseif (($in['action'] ?? '') === 'models') {
        $r = aiEmbeddingModels(helpAssistantPostedVectorCfg($conn, $in));
        echo json_encode(['success' => true, 'models' => $r['models'], 'error' => $r['error']]);
        exit;
    } elseif (($in['action'] ?? '') === 'test_vectors') {
        $cfg = helpVectorsConfig($conn);
        if (!helpVectorsUsable($cfg)) throw new InvalidArgumentException('Save a provider, model and key first.');
        $t = microtime(true);
        $r = aiEmbedBatch($cfg, ['How do I create a ticketing department?']);
        echo json_encode(['success' => $r['error'] === null, 'error' => $r['error'], 'dimensions' => $r['error'] === null ? count($r['vectors'][0]) : null,
                          'ms' => (int)round((microtime(true) - $t) * 1000), 'model' => aiEmbeddingModelId($cfg)]);
        exit;
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
