<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * The help assistant's vectors - matching by meaning (wiki Help-Assistant-Design,
 * step 4; includes/help_vectors.php, includes/ai_embeddings.php).
 *
 * Part 1, no database, no network: storing a vector; a model's identity (OpenAI and
 * Azure running the same model agree, a local server's do not); the URL, headers
 * and body for OpenAI, Azure (deployment and v1) and a compatible server; merging
 * the word and meaning rankings; the reply kinds with vectors; what counts as usable.
 *
 * Part 2, FREEITSM_TEST_DATABASE (a throwaway install, help index built): against
 * a FAKE embedding server this test starts itself (tests/support/fake-embedding-server.php,
 * PHP's built-in server - nothing paid, nothing outside the machine):
 * - building: every section gets a vector, then that model is in use
 * - asking: the reply merges words and meaning
 * - choosing another model: the OLD one keeps answering until the new one is built,
 *   then the switch, and the old vectors go
 * - a model that fails to build changes nothing
 * - the server going away: answers fall back to words, no error
 * The vectors, every setting it touches and the switch are put back in finally.
 *
 *   php tests/help-vectors.php
 *   FREEITSM_TEST_DATABASE=<the throwaway db> php tests/help-vectors.php
 *
 * @wiki Help-Assistant-Developer-Guide
 */
chdir(__DIR__ . '/..');
require_once 'includes/help_assistant.php';

$pass = 0; $fail = 0;
function check(string $what, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  [PASS] $what\n"; }
    else     { $fail++; echo "  [FAIL] $what" . ($detail !== '' ? " - $detail" : '') . "\n"; }
}

// ─── Part 1 ─────────────────────────────────────────────────────────────────
echo "Storing and identifying vectors\n";
$v = [0.125, -0.5, 0.0, 0.333333];
$back = aiVectorUnpack(aiVectorPack($v));
check('a vector survives storage (float32)', count($back) === 4 && abs($back[3] - 0.333333) < 1e-6 && $back[1] === -0.5);
check('OpenAI and Azure running the same model share an identity - the shipped vectors suit both',
    aiEmbeddingModelId(['provider' => 'openai', 'model' => 'text-embedding-3-small', 'dimensions' => 512]) === aiEmbeddingModelId(['provider' => 'azure', 'model' => 'text-embedding-3-small', 'dimensions' => 512]));
check('...a local server\'s model does not', aiEmbeddingModelId(['provider' => 'openai_compatible', 'model' => 'text-embedding-3-small']) !== aiEmbeddingModelId(['provider' => 'openai', 'model' => 'text-embedding-3-small']));
check('...and the length is part of it', aiEmbeddingModelId(['provider' => 'openai', 'model' => 'x', 'dimensions' => 512]) !== aiEmbeddingModelId(['provider' => 'openai', 'model' => 'x']));
check('the shipped identity is OpenAI text-embedding-3-small at 512', HELP_VECTORS_SHIPPED_ID === 'openai:text-embedding-3-small@512');

echo "\nTalking to each provider\n";
$oa = ['provider' => 'openai', 'api_key' => 'k1', 'model' => 'text-embedding-3-small', 'dimensions' => 512];
check('OpenAI: its URL, a Bearer key, the model and dimensions in the body',
    aiEmbeddingUrl($oa) === 'https://api.openai.com/v1/embeddings' && aiEmbeddingHeaders($oa) === ['Authorization: Bearer k1']
    && json_decode(aiEmbeddingPayload($oa, ['a', 'b']), true) === ['model' => 'text-embedding-3-small', 'input' => ['a', 'b'], 'dimensions' => 512]);
$az = ['provider' => 'azure', 'api_key' => 'k2', 'model' => 'text-embedding-3-small', 'azure_endpoint' => 'https://r.openai.azure.com/openai/deployments/x/chat/completions?api-version=1', 'azure_deployment' => 'emb', 'azure_api_version' => '2024-02-01'];
check('Azure: the deployment URL (a pasted Target URI cut back), an api-key header, no model in the body',
    aiEmbeddingUrl($az) === 'https://r.openai.azure.com/openai/deployments/emb/embeddings?api-version=2024-02-01'
    && aiEmbeddingHeaders($az) === ['api-key: k2'] && !isset(json_decode(aiEmbeddingPayload($az, ['a']), true)['model']), aiEmbeddingUrl($az));
check('Azure "v1": the model in the body, like OpenAI',
    aiEmbeddingUrl(['azure_api_version' => 'v1'] + $az) === 'https://r.openai.azure.com/openai/v1/embeddings' && isset(json_decode(aiEmbeddingPayload(['azure_api_version' => 'v1'] + $az, ['a']), true)['model']));
$cp = ['provider' => 'openai_compatible', 'model' => 'nomic-embed-text', 'compatible_base_url' => 'http://llm:11434/v1/chat/completions'];
check('a compatible server: its base URL (a pasted endpoint cut back), no key, no dimensions unless asked',
    aiEmbeddingUrl($cp) === 'http://llm:11434/v1/embeddings' && aiEmbeddingHeaders($cp) === [] && !isset(json_decode(aiEmbeddingPayload($cp, ['a']), true)['dimensions']), aiEmbeddingUrl($cp));
check('a provider that cannot embed is refused without a request', aiEmbedBatch(['provider' => 'anthropic'], ['x'])['error'] === 'anthropic cannot make embeddings');
check('usable: OpenAI needs a key; a compatible server an address; anthropic never',
    !helpVectorsUsable(['provider' => 'openai', 'model' => 'm', 'api_key' => '']) && helpVectorsUsable(['provider' => 'openai', 'model' => 'm', 'api_key' => 'k'])
    && helpVectorsUsable($cp) && !helpVectorsUsable(['provider' => 'anthropic', 'model' => 'm', 'api_key' => 'k']));

echo "\nMerging words and meaning\n";
$sec = fn(string $k, float $score = 1.0) => ['section_key' => $k, 'title' => $k, 'body' => "Body of $k.", 'module' => 'tickets', 'page' => 'p', 'anchor' => $k, 'score' => $score, 'snippet' => "s $k"];
$word = [$sec('a', 20), $sec('b', 15), $sec('c', 10)];
$byKey = ['a' => $sec('a'), 'b' => $sec('b'), 'c' => $sec('c'), 'd' => $sec('d')];
$m = helpMerge($word, null, $byKey);
check('no vectors: the word ranking, unchanged', array_column($m, 'section_key') === ['a', 'b', 'c'] && $m[0]['sim'] === null);
// 'a' is words' first choice only; 'd' is meaning's first choice only; 'b' is high in both.
$vec = ['ranked' => [['section_key' => 'd', 'sim' => 0.8], ['section_key' => 'b', 'sim' => 0.7], ['section_key' => 'c', 'sim' => 0.5]], 'lead' => 0.1];
$m = helpMerge($word, $vec, $byKey);
$keys = array_column($m, 'section_key');
check('merged: a section high in both comes first', $keys[0] === 'b', json_encode($keys));
check('...meaning counts double words: its first choice beats words\' first choice', array_search('d', $keys) < array_search('a', $keys), json_encode($keys));
check('...a section only meaning found is included, with a preview', in_array('d', $keys, true) && ($m[array_search('d', $keys)]['snippet'] ?? '') !== '');
check('...each carries its similarity and both positions', $m[0]['sim'] === 0.7 && $m[0]['word_rank'] === 1 && $m[0]['vec_rank'] === 1);

echo "\nThe reply, with vectors\n";
$r = fn(float $score, ?float $sim, ?int $w, ?int $v, float $lead = 0.0) => ['score' => $score, 'sim' => $sim, 'word_rank' => $w, 'vec_rank' => $v, 'lead' => $lead, 'module' => 'tickets'];
check('weak only when meaning AND words are weak', helpAssistantReplyKind([$r(5, 0.40, 0, 0)]) === 'weak' && helpAssistantReplyKind([$r(5, 0.60, 0, 1), $r(4, 0.5, 1, 0)]) !== 'weak');
check('clear: meaning\'s first choice well ahead', helpAssistantReplyKind([$r(10, 0.7, 2, 0, 0.12), $r(12, 0.6, 0, 1)]) === 'clear');
check('clear: first by words and by meaning at once', helpAssistantReplyKind([$r(10, 0.6, 0, 0, 0.01), $r(9, 0.59, 1, 1)]) === 'clear');
check('otherwise several (or module, from the asker\'s module)', helpAssistantReplyKind([$r(10, 0.6, 1, 2, 0.01), $r(9, 0.59, 0, 0)]) === 'several' && helpAssistantReplyKind([$r(10, 0.6, 1, 2, 0.01), $r(9, 0.59, 0, 0)], 'tickets') === 'module');

// ─── Part 2 ─────────────────────────────────────────────────────────────────
$expected = getenv('FREEITSM_TEST_DATABASE');
if (!$expected) { echo "\n  (skipped the database checks: set FREEITSM_TEST_DATABASE)\n\n$pass passed, $fail failed\n"; exit($fail ? 1 : 0); }
require_once 'config.php';
require_once 'includes/functions.php';
$conn = connectToDatabase();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
if ($conn->query('SELECT DATABASE()')->fetchColumn() !== $expected) { echo "\n  (skipped: config.php does not use $expected)\n\n$pass passed, $fail failed\n"; exit($fail ? 1 : 0); }
if (!(int)$conn->query("SELECT COUNT(*) FROM help_sections")->fetchColumn()) { check('the help index is built (php scripts/help_index_build.php)', false); exit(1); }

$port = 18700 + random_int(0, 999);
$logFile = sys_get_temp_dir() . "/fake-embed-$port.log";
@unlink($logFile);
$keys = ['help_vectors_enabled', 'help_vectors_model', 'help_vectors_active_cfg', 'help_embed_provider', 'help_embed_model', 'help_embed_api_key', 'help_embed_compatible_base_url'];
$saved = [];
foreach ($keys as $k) { $st = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ?"); $st->execute([$k]); $saved[$k] = $st->fetchColumn(); }
$conn->exec("DROP TABLE IF EXISTS zz_help_vectors_backup");
$conn->exec("CREATE TABLE zz_help_vectors_backup AS SELECT * FROM help_section_vectors");
$server = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", 'tests/support/fake-embedding-server.php'], [1 => ['file', sys_get_temp_dir() . "/fake-embed-$port.out", 'w'], 2 => ['file', sys_get_temp_dir() . "/fake-embed-$port.err", 'w']], $pipes, getcwd(), ['FAKE_EMBED_LOG' => $logFile] + getenv());
$use = function (string $model) use ($conn, $port): void {
    aiSettingsSave($conn, HELP_VECTORS_NS, ['provider' => 'openai_compatible', 'model' => $model, 'api_key' => '', 'clear_api_key' => true, 'compatible_base_url' => "http://127.0.0.1:$port/v1"]);
    helpVectorsSet($conn, 'help_vectors_enabled', '1');
    helpVectorsReady($conn, true);
};
$ask = fn(string $q) => helpAssistantAnswer($conn, helpIndexRenderAnalyst($conn), $q, 'en', null);
$sections = (int)$conn->query("SELECT COUNT(*) FROM help_sections")->fetchColumn();

try {
    for ($i = 0; $i < 40 && !@fsockopen('127.0.0.1', $port); $i++) usleep(100000);
    check('the fake embedding server started', (bool)@fsockopen('127.0.0.1', $port));
    $conn->exec("DELETE FROM help_section_vectors");
    $conn->exec("DELETE FROM system_settings WHERE setting_key IN ('help_vectors_model', 'help_vectors_active_cfg')");

    echo "\nBuilding\n";
    $use('fake-embed');
    check('a model is offered from the server\'s own list', aiEmbeddingModels(helpVectorsConfig($conn))['models'] === ['fake-embed', 'fake-embed-b']);
    check('nothing is in use before a build - words only', helpVectorsReady($conn, true) === null && helpAssistantLevel($conn)['level'] === 'search');
    $b = helpVectorsBuild($conn);
    check("every section gets a vector ($sections), then the model is in use",
        $b['embedded'] === $sections && $b['missing'] === 0 && $b['switched'] && helpVectorsActiveModel($conn) === 'compatible:fake-embed', json_encode($b));
    check('...in batches, not one request a section', count(file($logFile)) === (int)ceil($sections / HELP_VECTORS_BATCH), (string)count(file($logFile)));
    check('the level says so', helpAssistantLevel($conn)['level'] === 'search_meaning');
    $again = helpVectorsBuild($conn);
    check('a rebuild with nothing changed embeds nothing', $again['embedded'] === 0 && $again['switched']);

    echo "\nAsking\n";
    $a = $ask('how do I create a ticketing department');
    check('a reply merges words and meaning (results carry a similarity)', $a['results'] && $a['results'][0]['sim'] !== null, json_encode($a['results'][0] ?? null));

    echo "\nChoosing another model\n";
    $use('fake-embed-b');
    check('the old model keeps answering while the new one is not built',
        helpVectorsReady($conn, true) !== null && helpVectorsActiveModel($conn) === 'compatible:fake-embed' && $ask('create a department')['results'][0]['sim'] !== null);
    $st = helpVectorsStatus($conn);
    check('...and the page shows the switch is pending', $st['active'] === 'compatible:fake-embed' && $st['chosen'] === 'compatible:fake-embed-b' && $st['in_use']);
    $b = helpVectorsBuild($conn);
    check('rebuilding switches over, and the old vectors go',
        $b['switched'] && helpVectorsActiveModel($conn) === 'compatible:fake-embed-b'
        && (int)$conn->query("SELECT COUNT(DISTINCT model) FROM help_section_vectors")->fetchColumn() === 1, json_encode($b));

    echo "\nWhen things go wrong\n";
    $use('fake-broken');
    $b = helpVectorsBuild($conn);
    check('a model that fails to build changes nothing - the last one still answers',
        $b['error'] !== null && !$b['switched'] && helpVectorsActiveModel($conn) === 'compatible:fake-embed-b' && helpVectorsReady($conn, true) !== null, json_encode($b));
    proc_terminate($server);
    proc_close($server);
    $server = null;
    usleep(300000);
    $a = $ask('how do I create a ticketing department');
    check('the server going away: answers fall back to words, no error', $a['results'] && $a['results'][0]['sim'] === null && $a['results'][0]['score'] > 0);
    helpVectorsSet($conn, 'help_vectors_enabled', '0');
    check('switched off: words only', helpVectorsReady($conn, true) === null && helpAssistantLevel($conn)['level'] === 'search');
} catch (Throwable $e) {
    check('the database checks ran: ' . $e->getMessage(), false);
} finally {
    if ($server) { proc_terminate($server); proc_close($server); }
    $conn->exec("DELETE FROM help_section_vectors");
    $conn->exec("INSERT INTO help_section_vectors SELECT * FROM zz_help_vectors_backup");
    $conn->exec("DROP TABLE zz_help_vectors_backup");
    foreach ($saved as $k => $val) {
        if ($val === false) $conn->prepare("DELETE FROM system_settings WHERE setting_key = ?")->execute([$k]);
        else $conn->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")->execute([$k, $val]);
    }
    foreach (['log', 'out', 'err'] as $x) @unlink(sys_get_temp_dir() . "/fake-embed-$port." . $x);
}

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
