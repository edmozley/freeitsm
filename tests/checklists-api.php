<?php
/* 🔴 NEVER OVER THE WEB - it writes to the database. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * REST API v1 - checklists on tickets and tasks (GitHub #273), through the real
 * front controller with real API keys.
 *
 *   FREEITSM_TEST_DATABASE=<throwaway db> FREEITSM_TEST_URL=http://localhost/<that install> php tests/checklists-api.php
 *
 * Checks: both GETs return the same shape; permissions (read-only key cannot
 * write, a key without the resource gets 403); a step from another ticket is 404;
 * the server's rules come through as 422s; a tick / result is recorded as the
 * key's analyst and marked "via the API" (ticket note, task step history).
 * Runs Database Verify first. Everything it makes is removed in `finally`.
 */

chdir(__DIR__ . '/..');
$pass = 0; $fail = 0;
function check(string $what, bool $ok): void { global $pass, $fail; echo ($ok ? '  [PASS] ' : '  [FAIL] ') . $what . "\n"; $ok ? $pass++ : $fail++; }

$expected = getenv('FREEITSM_TEST_DATABASE');
$base = rtrim((string)getenv('FREEITSM_TEST_URL'), '/');
if (!$expected || !$base) { echo "(skipped: set FREEITSM_TEST_DATABASE and FREEITSM_TEST_URL)\n"; exit(0); }
require_once 'config.php';
require_once 'includes/functions.php';
require_once 'includes/services/checklists.php';
$conn = connectToDatabase();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
if ($conn->query('SELECT DATABASE()')->fetchColumn() !== $expected) { echo "(skipped: config.php does not use $expected)\n"; exit(0); }
exec(escapeshellarg(PHP_BINARY) . ' scripts/db_verify_cli.php --apply 2>&1', $o, $code);
if ($code !== 0) { check('Database Verify ran', false); exit(1); }

$tag = 'zz273' . bin2hex(random_bytes(3));
$ticketId = (int)$conn->query("SELECT id FROM tickets ORDER BY id LIMIT 1")->fetchColumn();
$otherTicket = (int)$conn->query("SELECT id FROM tickets WHERE id <> $ticketId ORDER BY id LIMIT 1")->fetchColumn();
$noteMark = (int)$conn->query("SELECT COALESCE(MAX(id), 0) FROM ticket_notes")->fetchColumn();
$st = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'tasks_checklists_enabled'");
$st->execute(); $oldTasksOn = $st->fetchColumn();
$actor = 0; $tplId = 0; $taskId = 0; $keyIds = [];

$request = function (string $method, string $path, string $key, ?array $body = null) use ($base): array {
    $ch = curl_init($base . '/api/v1/index.php?path=' . rawurlencode($path));
    $o = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_CUSTOMREQUEST => $method,
          CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $key, 'Content-Type: application/json']];
    if ($body !== null) $o[CURLOPT_POSTFIELDS] = json_encode($body);
    curl_setopt_array($ch, $o);
    $raw = (string)curl_exec($ch);
    return [(int)curl_getinfo($ch, CURLINFO_HTTP_CODE), json_decode($raw, true), $raw];
};
$setTasksOn = function (string $v) use ($conn) {
    $conn->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES ('tasks_checklists_enabled', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")->execute([$v]);
};

try {
    if (!$ticketId || !$otherTicket) { check('there are two tickets to work on', false); exit(1); }
    $conn->prepare("INSERT INTO analysts (username, password_hash, full_name, email, is_active, is_admin, can_access_all_modules, can_access_all_tenants) VALUES (?, '!', 'ZZ API 273', ?, 1, 1, 1, 1)")
         ->execute([$tag, "$tag@example.invalid"]);
    $actor = (int)$conn->lastInsertId();
    $keys = [];
    foreach (['full' => ['ticket_checklists' => ['read', 'update'], 'task_checklists' => ['read', 'update']],
              'read' => ['ticket_checklists' => ['read'], 'task_checklists' => ['read']],
              'none' => ['tickets' => ['read']]] as $kind => $perms) {
        $keys[$kind] = 'fitsm_' . bin2hex(random_bytes(24));
        $conn->prepare('INSERT INTO api_keys (name, key_prefix, key_hash, analyst_id, permissions) VALUES (?, ?, ?, ?, ?)')
             ->execute([$tag . $kind, substr($keys[$kind], 0, 12), hash('sha256', $keys[$kind]), $actor, json_encode($perms)]);
        $keyIds[] = (int)$conn->lastInsertId();
    }

    $conn->exec("INSERT INTO checklist_templates (title, category, scope, closure_mode, is_active) VALUES ('ZZ #273 checks', 'ZZ', 'both', 'block', 1)");
    $tplId = (int)$conn->lastInsertId();
    $si = $conn->prepare("INSERT INTO checklist_template_items (template_id, title, description, is_mandatory, requires_input, input_placeholder, sort_order, result_mode) VALUES (?, ?, ?, 1, ?, ?, ?, ?)");
    $si->execute([$tplId, 'Plain step', null, 0, null, 1, 'none']);
    $si->execute([$tplId, 'Asset tag', null, 1, 'The tag', 2, 'none']);
    $si->execute([$tplId, 'Backups verified', 'Check last night\'s job.', 0, null, 3, 'pass_fail']);
    $ctx = new ActorContext($actor, null, 'ui', 'en', 'ZZ API 273');
    $tChk = (int)ChecklistsService::attachTemplateToTicket($conn, $ctx, $ticketId, $tplId);
    $setTasksOn('1');
    $conn->prepare("INSERT INTO tasks (title, created_by_id, created_datetime, updated_datetime) VALUES (?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())")->execute(["ZZ #273 task", $actor]);
    $taskId = (int)$conn->lastInsertId();
    ChecklistsService::attachTemplateToTask($conn, $ctx, $taskId, $tplId);

    echo "Reading\n";
    [$c, $j] = $request('GET', "/tickets/$ticketId/checklists", $keys['read']);
    $mine = array_values(array_filter($j['data'] ?? [], fn($x) => $x['id'] === $tChk))[0] ?? null;
    check("GET /tickets/{id}/checklists answers 200 ($c)", $c === 200 && $mine !== null);
    $steps = array_column($mine['steps'] ?? [], null, 'title');
    check('steps say what they ask for: tick, value, result', ($steps['Plain step']['asks_for'] ?? '') === 'tick'
        && ($steps['Asset tag']['asks_for'] ?? '') === 'value' && ($steps['Backups verified']['asks_for'] ?? '') === 'result');
    check('...with the value prompt, the description and the closure', ($steps['Asset tag']['value_prompt'] ?? '') === 'The tag'
        && ($steps['Backups verified']['description'] ?? '') === 'Check last night\'s job.' && ($mine['closure'] ?? '') === 'block');
    [$c2, $j2] = $request('GET', "/tasks/$taskId/checklists", $keys['read']);
    $taskList = $j2['data'][0] ?? null;
    check("GET /tasks/{id}/checklists answers 200 ($c2)", $c2 === 200 && $taskList !== null);
    check('...in exactly the same shape as a ticket\'s', $taskList && array_keys($taskList) === array_keys($mine)
        && array_keys($taskList['steps'][0]) === array_keys($mine['steps'][0]));
    [$c, $j] = $request('GET', "/tickets/$ticketId/checklists", $keys['none']);
    check("a key without ticket_checklists gets 403 ($c)", $c === 403);

    echo "\nAnswering steps\n";
    $tag2 = (int)$steps['Asset tag']['id']; $res = (int)$steps['Backups verified']['id'];
    [$c] = $request('PATCH', "/tickets/$ticketId/checklists/steps/$tag2", $keys['read'], ['completed' => true, 'value' => 'AT-1']);
    check("a read-only key cannot answer a step ($c)", $c === 403);
    [$c] = $request('PATCH', "/tickets/$otherTicket/checklists/steps/$tag2", $keys['full'], ['completed' => true, 'value' => 'AT-1']);
    check("a step addressed through another ticket is 404 ($c)", $c === 404);
    [$c, $j] = $request('PATCH', "/tickets/$ticketId/checklists/steps/$tag2", $keys['full'], ['completed' => true]);
    check("a value step without a value is 422 missing_field ($c)", $c === 422 && ($j['error']['code'] ?? '') === 'missing_field');
    [$c, $j] = $request('PATCH', "/tickets/$ticketId/checklists/steps/$tag2", $keys['full'], ['completed' => true, 'value' => 'AT-4471']);
    check("...and 200 with one, returning the step ($c)", $c === 200 && ($j['data']['completed'] ?? null) === true
        && ($j['data']['value'] ?? '') === 'AT-4471' && ($j['data']['completed_by'] ?? '') === 'ZZ API 273');
    [$c, $j] = $request('PATCH', "/tickets/$ticketId/checklists/steps/$res", $keys['full'], ['completed' => true, 'result' => 'pass']);
    check("both completed and result is 422 invalid_field ($c)", $c === 422 && ($j['error']['code'] ?? '') === 'invalid_field');
    [$c, $j] = $request('PATCH', "/tickets/$ticketId/checklists/steps/$res", $keys['full'], ['completed' => 'yes']);
    check("completed that is not true / false is 422 ($c)", $c === 422);
    [$c, $j] = $request('PATCH', "/tickets/$ticketId/checklists/steps/$res", $keys['full'], ['result' => 'fail']);
    check("a fail without a note is 422 ($c)", $c === 422 && ($j['error']['code'] ?? '') === 'missing_field');
    [$c, $j] = $request('PATCH', "/tickets/$ticketId/checklists/steps/$res", $keys['full'], ['result' => 'fail', 'note' => 'Job 42 failed']);
    check("a fail with a note is 200, completed, with its note ($c)", $c === 200 && ($j['data']['result'] ?? '') === 'fail'
        && ($j['data']['note'] ?? '') === 'Job 42 failed' && ($j['data']['completed'] ?? null) === true);
    $note = $conn->query("SELECT analyst_id, note_text FROM ticket_notes WHERE ticket_id = $ticketId AND id > $noteMark AND note_text LIKE '%Backups verified%' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    check('the ticket\'s note is the key\'s analyst\'s, marked "via the API"', $note && (int)$note['analyst_id'] === $actor && str_ends_with($note['note_text'], '(via the API)'));

    echo "\nTasks\n";
    $tStep = (int)array_column($taskList['steps'], null, 'title')['Backups verified']['id'];
    [$c, $j] = $request('PATCH', "/tasks/$taskId/checklists/steps/$tStep", $keys['full'], ['result' => 'na']);
    check("a task step answered Not applicable ($c)", $c === 200 && ($j['data']['result'] ?? '') === 'na');
    $ev = $conn->query("SELECT after_json FROM task_checklist_item_events WHERE item_id = $tStep ORDER BY id DESC LIMIT 1")->fetchColumn();
    check('the task step history records it was via the API', ($ev ? json_decode($ev, true)['via'] ?? '' : '') === 'api');
    [$c] = $request('PATCH', "/tasks/$taskId/checklists/steps/$res", $keys['full'], ['result' => 'na']);
    check("a ticket's step addressed as a task step is 404 ($c)", $c === 404);
    $setTasksOn('0');
    [$c, $j] = $request('GET', "/tasks/$taskId/checklists", $keys['full']);
    check("with checklists on tasks off, GET is an empty list ($c)", $c === 200 && ($j['data'] ?? null) === []);
    [$c, $j] = $request('PATCH', "/tasks/$taskId/checklists/steps/$tStep", $keys['full'], ['result' => 'pass']);
    check("...and PATCH is 422 disabled ($c)", $c === 422 && ($j['error']['code'] ?? '') === 'disabled');
} finally {
    foreach ($conn->query("SELECT id FROM ticket_checklists WHERE template_id = " . (int)$tplId)->fetchAll(PDO::FETCH_COLUMN) as $id) ChecklistsService::removeTicketChecklist($conn, (int)$id);
    if ($taskId) {
        try { $conn->exec("DELETE FROM task_checklist_item_events WHERE task_id = $taskId"); } catch (Throwable) {}
        ChecklistsService::deleteForTasks($conn, [$taskId]);
        try { $conn->exec("DELETE FROM task_events WHERE task_id = $taskId"); } catch (Throwable) {}   // the "checklist attached" history row
        try { $conn->exec("DELETE FROM task_audit WHERE task_id = $taskId"); } catch (Throwable) {}
        $conn->exec("DELETE FROM tasks WHERE id = $taskId");
    }
    $conn->exec("DELETE FROM ticket_notes WHERE id > $noteMark AND note_text LIKE '%ZZ #273 checks%'");
    if ($tplId) { $conn->exec("DELETE FROM checklist_template_items WHERE template_id = $tplId"); $conn->exec("DELETE FROM checklist_templates WHERE id = $tplId"); }
    foreach ($keyIds as $id) { try { $conn->exec("DELETE FROM api_key_rate_limits WHERE api_key_id = $id"); } catch (Throwable) {} $conn->exec("DELETE FROM api_keys WHERE id = $id"); }
    if ($actor) $conn->exec("DELETE FROM analysts WHERE id = $actor");
    if ($oldTasksOn === false) $conn->exec("DELETE FROM system_settings WHERE setting_key = 'tasks_checklists_enabled'");
    else $setTasksOn((string)$oldTasksOn);
}
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
