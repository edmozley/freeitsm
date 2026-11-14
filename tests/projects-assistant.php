<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * The Projects AI assistant's conversation (includes/projects/assistant_chat.php),
 * which shares its history, opening and memory with the CMDB assistant through
 * includes/ai_conversation.php (GH #313 slice 4).
 *
 *   FREEITSM_TEST_DATABASE=<throwaway db> php tests/projects-assistant.php
 *
 * Against the stand-in OpenAI-compatible server tests/ai-compatible/mock.php
 * (model 'script'), which this test starts - nothing paid, nothing leaves the machine:
 *   - opening an empty conversation greets; opening again adds nothing; after
 *     eight hours it catches up ('resume');
 *   - a propose_* tool call becomes a pending proposal; the next turn's history
 *     says what it became ("[Proposed: ... - pending]", then "- dismissed");
 *   - the memory: past 24 unsummarised messages the older ones are folded into the
 *     thread's summary, and the next turn's prompt carries it;
 *   - "is AI set up?" is aiConfigured() - a keyless custom endpoint counts;
 *   - no provider: 'not_configured'; Start again clears the conversation.
 * A ZZPA project is made and removed by id in finally, with its conversation; the
 * Projects AI settings are put back exactly.
 *
 * @wiki Projects-Internals-7-Alerts-Reports-and-AI
 */
chdir(__DIR__ . '/..');
$expected = getenv('FREEITSM_TEST_DATABASE');
if (!$expected) { echo "(skipped: set FREEITSM_TEST_DATABASE)\n"; exit(0); }
require_once 'config.php';
require_once 'includes/functions.php';
require_once 'includes/i18n.php';
require_once 'includes/ai_settings.php';
require_once 'includes/projects/assistant_chat.php';
$conn = connectToDatabase();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
if ($conn->query('SELECT DATABASE()')->fetchColumn() !== $expected) { echo "(skipped: config.php does not use $expected)\n"; exit(0); }

$pass = 0; $fail = 0;
function check(string $what, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  [PASS] $what\n"; }
    else     { $fail++; echo "  [FAIL] $what" . ($detail !== '' ? " - $detail" : '') . "\n"; }
}
$LOG = __DIR__ . '/ai-compatible/last-request.json';
/** The requests the stand-in received since the last reset. */
function sentRequests(): array { global $LOG; return is_file($LOG) ? (json_decode((string)file_get_contents($LOG), true) ?: []) : []; }
/** Every message text of the last request, joined. */
function lastPrompt(): string { $all = sentRequests(); $r = end($all) ?: []; return implode("\n", (array)($r['texts'] ?? [])); }

if (!projectChatReady($conn)) { echo "(skipped: run Database Verification - the assistant's tables are missing)\n"; exit(0); }

$port = 19400 + random_int(0, 299);
$server = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", 'tests/ai-compatible/mock.php'],
    [1 => ['file', sys_get_temp_dir() . "/prj-assist-$port.out", 'w'], 2 => ['file', sys_get_temp_dir() . "/prj-assist-$port.err", 'w']], $pipes, getcwd());
$keys = aiSettingsKeys('projects_ai');
$place = implode(',', array_fill(0, count($keys), '?'));
$st = $conn->prepare("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ($place)");
$st->execute(array_values($keys));
$snapshot = $st->fetchAll(PDO::FETCH_KEY_PAIR);
$useModel = function (string $m) use ($conn, $port) {
    aiSettingsSave($conn, 'projects_ai', ['provider' => 'openai_compatible', 'model' => $m, 'api_key' => '', 'clear_api_key' => true, 'compatible_base_url' => "http://127.0.0.1:$port/v1"]);
};

$admin = (int)$conn->query("SELECT id FROM analysts WHERE is_admin = 1 AND is_active = 1 ORDER BY id LIMIT 1")->fetchColumn();
$_SESSION = ['analyst_id' => $admin, 'analyst_name' => 'ZZPA test', 'is_admin' => 1];
$ctx = ActorContext::fromSession($conn);
$projectId = null;

try {
    for ($i = 0; $i < 40 && !@fsockopen('127.0.0.1', $port); $i++) usleep(100000);
    check('the stand-in server started', (bool)@fsockopen('127.0.0.1', $port));
    @unlink($LOG);
    $useModel('script');

    echo "Is AI set up?\n";
    check('a keyless custom endpoint counts as set up (aiConfigured, not "is there a key")', projectAiReady($conn));

    $projectId = ProjectsService::createProject($conn, $ctx, ['name' => 'ZZPA assistant test'], null);
    $project = ProjectsService::loadRow($conn, $projectId);
    $thread = fn() => projectChatThread($conn, $projectId, $admin, false);

    echo "Opening\n";
    $r = projectChatOpen($conn, $ctx, $project);
    $m = $r['messages'];
    check('an empty conversation gets a greeting', count($m) === 1 && $m[0]['role'] === 'assistant' && $m[0]['kind'] === 'open', json_encode($m));
    check('...asked for as a first opening', str_contains(lastPrompt(), 'opened the assistant on this project for the first time'));
    $r = projectChatOpen($conn, $ctx, $project);
    check('opening again adds nothing (the history is the welcome)', count($r['messages']) === 1);
    $conn->prepare("UPDATE project_ai_messages SET created_datetime = UTC_TIMESTAMP() - INTERVAL 9 HOUR WHERE thread_id = ?")->execute([(int)$thread()['id']]);
    $r = projectChatOpen($conn, $ctx, $project);
    check('after eight hours it catches up', count($r['messages']) === 2 && $r['messages'][1]['kind'] === 'resume', json_encode(array_column($r['messages'], 'kind')));

    echo "Proposals and what they became\n";
    $r = projectChatTurn($conn, $ctx, $project, 'chat', "Plan it\nCALL propose_stage {\"name\":\"ZZPA Stage one\"}");
    $last = end($r['messages']);
    check('the question and the answer are stored', count($r['messages']) === 4 && $r['messages'][2]['role'] === 'user');
    check('a propose_* call becomes a pending proposal', count($last['proposals']) === 1 && $last['proposals'][0]['type'] === 'stage' && $last['proposals'][0]['status'] === 'pending', json_encode($last['proposals']));
    check('...and the model is told it waits for the user', str_contains($last['content'], 'Proposed (waiting for the user to apply)'), $last['content']);
    projectChatTurn($conn, $ctx, $project, 'chat', 'And then?');
    check('the next turn\'s history says what it became (pending)', str_contains(lastPrompt(), '[Proposed: ') && str_contains(lastPrompt(), 'ZZPA Stage one') && str_contains(lastPrompt(), ' - pending]'));
    projectChatApply($conn, $ctx, $project, (int)$last['id'], [0], true);
    projectChatTurn($conn, $ctx, $project, 'chat', 'Fine.');
    check('...and once dismissed, says dismissed', str_contains(lastPrompt(), ' - dismissed]'));
    check('the prompt asks for the analyst\'s language', str_contains(lastPrompt(), 'Write in British English'));

    echo "The memory\n";
    $tid = (int)$thread()['id'];
    $ins = $conn->prepare("INSERT INTO project_ai_messages (thread_id, role, kind, content, created_datetime) VALUES (?, ?, 'chat', ?, UTC_TIMESTAMP())");
    for ($i = 0; $i < 20; $i++) $ins->execute([$tid, $i % 2 ? 'assistant' : 'user', "ZZPA filler $i"]);
    $before = (int)$conn->query("SELECT COUNT(*) FROM project_ai_messages WHERE thread_id = $tid")->fetchColumn();
    projectChatTurn($conn, $ctx, $project, 'chat', 'Remember all that.');
    $th = $thread();
    $n = (int)$conn->query("SELECT COUNT(*) FROM project_ai_messages WHERE thread_id = $tid")->fetchColumn();
    $upto = (int)$conn->query("SELECT id FROM project_ai_messages WHERE thread_id = $tid ORDER BY id DESC LIMIT 1 OFFSET " . PROJECT_CHAT_RECENT)->fetchColumn();
    check("past 24 unsummarised messages ($n) the older ones are folded into the summary", $before > 24 && $th['summary'] === '- MEMORY: folded', (string)$th['summary']);
    check('...up to the message before the latest twelve', (int)$th['summary_upto_id'] === $upto, $th['summary_upto_id'] . ' vs ' . $upto);
    projectChatTurn($conn, $ctx, $project, 'chat', 'What do you remember?');
    check('the next turn\'s prompt carries the memory', str_contains(lastPrompt(), "WHAT EARLIER CONVERSATION ESTABLISHED (your memory):\n- MEMORY: folded"));
    check('...and only the latest twelve messages word for word', !str_contains(lastPrompt(), 'ZZPA filler 0'));
    $calls = count(sentRequests());
    projectChatSummarise($conn, $tid, aiSettingsLoad($conn, 'projects_ai'));
    check('a fold with too little new does not call the AI', count(sentRequests()) === $calls);

    echo "Problems\n";
    aiSettingsSave($conn, 'projects_ai', ['provider' => 'openai', 'model' => 'gpt-4o', 'api_key' => '', 'clear_api_key' => true]);
    $e = '';
    try { projectChatTurn($conn, $ctx, $project, 'chat', 'Hello?'); } catch (RuntimeException $x) { $e = $x->getMessage(); }
    check('no provider set up: not_configured', $e === 'not_configured', $e);
    check('...and "is AI set up?" says no', !projectAiReady($conn));

    echo "Start again\n";
    projectChatClear($conn, $ctx, $project);
    check('Start again clears the conversation', $thread() === null && (int)$conn->query("SELECT COUNT(*) FROM project_ai_messages WHERE thread_id = $tid")->fetchColumn() === 0);
} catch (Throwable $e) {
    check('no unexpected error', false, get_class($e) . ': ' . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    if ($projectId) {
        $tids = array_map('intval', $conn->query("SELECT id FROM project_ai_threads WHERE project_id = " . (int)$projectId)->fetchAll(PDO::FETCH_COLUMN));
        if ($tids) {
            $conn->exec("DELETE FROM project_ai_messages WHERE thread_id IN (" . implode(',', $tids) . ")");
            $conn->exec("DELETE FROM project_ai_threads WHERE id IN (" . implode(',', $tids) . ")");
        }
        try { ProjectsService::deleteProject($conn, $ctx, $projectId); } catch (Throwable $e) { echo "  cleanup: " . $e->getMessage() . "\n"; }
    }
    $conn->prepare("DELETE FROM system_settings WHERE setting_key IN ($place)")->execute(array_values($keys));
    $re = $conn->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?)");
    foreach ($snapshot as $k => $v) $re->execute([$k, $v]);
    if (is_resource($server)) proc_terminate($server);
    @unlink($LOG);
    $left = (int)$conn->query("SELECT COUNT(*) FROM projects WHERE name LIKE 'ZZPA %'")->fetchColumn();
    check('cleanup: no ZZPA project is left', $left === 0);
}
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
