<?php
/* 🔴 NEVER OVER THE WEB - it writes to the database. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * Ticket checklists on the shared engine (GitHub #271): the same step types as
 * tasks - descriptions, Pass / Fail / Not applicable with a note on Fail, the
 * server refusing a tick with an empty required value - and the step's note
 * written by the server, not the browser.
 *
 * Part 1 goes through the REAL endpoint (api/tickets/ticket_checklists.php) as a
 * ZZ analyst with a forged session. Part 2 is the workflow path, at service level.
 *
 *   FREEITSM_TEST_DATABASE=<throwaway db> FREEITSM_TEST_URL=http://localhost/<that install> php tests/ticket-checklists.php
 *
 * Runs Database Verify first (the new columns). Everything it makes is removed in `finally`.
 */

chdir(__DIR__ . '/..');
$pass = 0; $fail = 0;
function check(string $what, bool $ok): void { global $pass, $fail; echo ($ok ? '  [PASS] ' : '  [FAIL] ') . $what . "\n"; $ok ? $pass++ : $fail++; }

$expected = getenv('FREEITSM_TEST_DATABASE');
$url = rtrim((string)getenv('FREEITSM_TEST_URL'), '/');
if (!$expected || !$url) { echo "(skipped: set FREEITSM_TEST_DATABASE and FREEITSM_TEST_URL)\n"; exit(0); }
require_once 'config.php';
require_once 'includes/functions.php';
require_once 'includes/services/checklists.php';
$conn = connectToDatabase();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
if ($conn->query('SELECT DATABASE()')->fetchColumn() !== $expected) { echo "(skipped: config.php does not use $expected)\n"; exit(0); }
exec(escapeshellarg(PHP_BINARY) . ' scripts/db_verify_cli.php --apply 2>&1', $o, $code);
if ($code !== 0) { check('Database Verify ran', false); exit(1); }

$sessDir = rtrim((string)(getenv('FREEITSM_SESS_DIR') ?: 'C:/wamp64/tmp'), '/');
$tag = 'zz271' . bin2hex(random_bytes(3));
$ticketId = (int)$conn->query("SELECT id FROM tickets ORDER BY id LIMIT 1")->fetchColumn();
$noteMark = (int)$conn->query("SELECT COALESCE(MAX(id), 0) FROM ticket_notes")->fetchColumn();
$analystId = 0; $tplId = 0; $sessFile = '';

$call = function (string $method, array $body) use ($url, $tag): array {
    $u = "$url/api/tickets/ticket_checklists.php" . ($method === 'GET' ? '?' . http_build_query($body) : '');
    $ch = curl_init($u);
    $o = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60,
          CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-CSRF-Token: x', 'Cookie: PHPSESSID=' . $tag]];
    if ($method === 'POST') { $o[CURLOPT_POST] = true; $o[CURLOPT_POSTFIELDS] = json_encode($body); }
    curl_setopt_array($ch, $o);
    $raw = (string)curl_exec($ch);
    return json_decode($raw, true) ?: ['success' => false, 'error' => 'not JSON: ' . substr($raw, 0, 200)];
};
$notesSince = fn() => $conn->query("SELECT analyst_id, note_text FROM ticket_notes WHERE ticket_id = $ticketId AND id > $noteMark ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);

try {
    if (!$ticketId) { check('there is a ticket to work on', false); exit(1); }
    $conn->prepare("INSERT INTO analysts (username, password_hash, full_name, email, is_active, is_admin, can_access_all_modules, can_access_all_tenants) VALUES (?, '!', 'ZZ 271', ?, 1, 1, 1, 1)")
         ->execute([$tag, "$tag@example.invalid"]);
    $analystId = (int)$conn->lastInsertId();
    $sessFile = "$sessDir/sess_$tag";
    file_put_contents($sessFile, 'analyst_id|i:' . $analystId . ';analyst_username|s:' . strlen($tag) . ':"' . $tag . '";analyst_name|s:6:"ZZ 271";csrf_token|s:1:"x";');

    $conn->exec("INSERT INTO checklist_templates (title, category, scope, closure_mode, is_active) VALUES ('ZZ #271 checks', 'ZZ', 'ticket', 'block', 1)");
    $tplId = (int)$conn->lastInsertId();
    $si = $conn->prepare("INSERT INTO checklist_template_items (template_id, title, description, is_mandatory, requires_input, input_placeholder, sort_order, result_mode) VALUES (?, ?, ?, 1, ?, ?, ?, ?)");
    $si->execute([$tplId, 'Plain step', null, 0, null, 1, 'none']);
    $si->execute([$tplId, 'Asset tag', null, 1, 'The tag', 2, 'none']);
    $si->execute([$tplId, 'Backups verified', 'Open the backup console and check last night\'s job.', 0, null, 3, 'pass_fail']);

    echo "Attaching (the real endpoint)\n";
    $r = $call('POST', ['action' => 'attach_template', 'ticket_id' => $ticketId, 'template_id' => $tplId]);
    check('a template attaches' . (empty($r['success']) ? ' - ' . ($r['error'] ?? '') : ''), !empty($r['success']));
    $chkId = (int)($r['checklist_id'] ?? 0);
    $lists = $call('GET', ['action' => 'get_ticket_checklists', 'ticket_id' => $ticketId])['checklists'] ?? [];
    $mine = array_values(array_filter($lists, fn($c) => (int)$c['id'] === $chkId))[0] ?? null;
    $items = $mine['items'] ?? [];
    $byTitle = array_column($items, null, 'title');
    check('its steps arrive, in order', array_column($items, 'title') === ['Plain step', 'Asset tag', 'Backups verified']);
    check('the result step asks for a result (pass_fail), the others do not', ($byTitle['Backups verified']['result_mode'] ?? '') === 'pass_fail' && ($byTitle['Plain step']['result_mode'] ?? '') === 'none');
    check('the step\'s description is copied', ($byTitle['Backups verified']['description'] ?? '') === 'Open the backup console and check last night\'s job.');
    check('the checklist records who attached it', (int)$conn->query("SELECT created_by_id FROM ticket_checklists WHERE id = $chkId")->fetchColumn() === $analystId);
    $plain = (int)$byTitle['Plain step']['id']; $tagStep = (int)$byTitle['Asset tag']['id']; $res = (int)$byTitle['Backups verified']['id'];

    echo "\nTicking and the server's rules\n";
    $r = $call('POST', ['action' => 'toggle_item', 'item_id' => $tagStep, 'completed' => 1, 'response_value' => '  ']);
    check('a step that asks for a value is refused without one - by the server', empty($r['success']) && str_contains((string)($r['error'] ?? ''), 'needs a value'));
    $r = $call('POST', ['action' => 'toggle_item', 'item_id' => $tagStep, 'completed' => 1, 'response_value' => 'AT-1234']);
    check('...and accepted with one', !empty($r['success']) && ($r['response_value'] ?? '') === 'AT-1234');
    $r = $call('POST', ['action' => 'toggle_item', 'item_id' => $res, 'completed' => 1]);
    check('a result step cannot just be ticked', empty($r['success']) && str_contains((string)($r['error'] ?? ''), 'Record a result'));
    $out = array_column(ChecklistsService::outstandingMandatorySteps($conn, $ticketId), 'step');
    check('a mandatory result step with no result is outstanding at closure', in_array('Backups verified', $out, true));

    echo "\nResults\n";
    $r = $call('POST', ['action' => 'set_result', 'item_id' => $res, 'result' => 'fail', 'exception_note' => '']);
    check('Fail with no note is refused', empty($r['success']) && str_contains((string)($r['error'] ?? ''), 'exception note'));
    $r = $call('POST', ['action' => 'set_result', 'item_id' => $res, 'result' => 'fail', 'exception_note' => 'Job 42 failed overnight']);
    check('Fail with a note is saved, and ticks the step', !empty($r['success']) && ($r['result'] ?? '') === 'fail' && (int)($r['is_completed'] ?? 0) === 1);
    $out = array_column(ChecklistsService::outstandingMandatorySteps($conn, $ticketId), 'step');
    check('a Fail counts as answered - it does not hold up closing', !in_array('Backups verified', $out, true));
    $r = $call('POST', ['action' => 'set_result', 'item_id' => $plain, 'result' => 'pass']);
    check('a plain step refuses a result', empty($r['success']));
    $r = $call('POST', ['action' => 'set_result', 'item_id' => $res, 'result' => 'maybe']);
    check('an unknown result is refused', empty($r['success']));
    $r = $call('POST', ['action' => 'toggle_item', 'item_id' => $res, 'completed' => 0]);
    $row = $conn->query("SELECT is_completed, result, exception_note FROM ticket_checklist_items WHERE id = $res")->fetch(PDO::FETCH_ASSOC);
    check('Clear (untick) removes the result and its note', !empty($r['success']) && (int)$row['is_completed'] === 0 && $row['result'] === null && $row['exception_note'] === null);
    $r = $call('POST', ['action' => 'set_result', 'item_id' => $res, 'result' => 'na']);
    check('Not applicable is saved without a note', !empty($r['success']) && ($r['result'] ?? '') === 'na');

    echo "\nThe step's note on the ticket - written by the server\n";
    $notes = $notesSince();
    $texts = array_column($notes, 'note_text');
    $has = fn(string $needle) => (bool)array_filter($texts, fn($t) => str_contains($t, $needle));
    check('a tick with a value: "completed ... Response: AT-1234"', $has('✅ Checklist step completed: [ZZ #271 checks] "Asset tag" — Response: AT-1234'));
    check('a Fail: "failed ... Exception: ..."', $has('❌ Checklist step failed: [ZZ #271 checks] "Backups verified" — Exception: Job 42 failed overnight'));
    check('Clear: "reopened"', $has('↩️ Checklist step reopened: [ZZ #271 checks] "Backups verified"'));
    check('Not applicable', $has('➖ Checklist step not applicable: [ZZ #271 checks] "Backups verified"'));
    check('a refused tick wrote no note (control)', !$has('"Plain step"'));
    check('each note names the analyst', $notes && !array_filter($notes, fn($n) => (int)$n['analyst_id'] !== $analystId));

    echo "\nA workflow attaching and ticking (service level)\n";
    $wf = new ActorContext(0, null, 'workflow', 'en', 'Workflow');
    check('a workflow skips a template already on the ticket', ChecklistsService::attachTemplateToTicket($conn, $wf, $ticketId, $tplId, true) === null);
    $wfChk = (int)ChecklistsService::attachTemplateToTicket($conn, $wf, $ticketId, $tplId);
    check('...and attaches it, naming no analyst (it used to name analyst #1)', $wfChk > 0 && $conn->query("SELECT created_by_id FROM ticket_checklists WHERE id = $wfChk")->fetchColumn() === null);
    $wfPlain = (int)$conn->query("SELECT id FROM ticket_checklist_items WHERE ticket_checklist_id = $wfChk AND title = 'Plain step'")->fetchColumn();
    ChecklistsService::toggleTicketItem($conn, $wf, $wfPlain, true, null);
    $last = $conn->query("SELECT analyst_id, note_text FROM ticket_notes WHERE ticket_id = $ticketId AND id > $noteMark ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    check('a workflow\'s tick is noted as System (no analyst)', $last && $last['analyst_id'] === null && str_contains($last['note_text'], '"Plain step"'));

    echo "\nRemoving\n";
    $r = $call('POST', ['action' => 'remove_checklist', 'checklist_id' => $chkId]);
    check('removing a checklist takes its steps with it', !empty($r['success'])
        && (int)$conn->query("SELECT COUNT(*) FROM ticket_checklist_items WHERE ticket_checklist_id = $chkId")->fetchColumn() === 0);
} finally {
    $ids = $conn->query("SELECT id FROM ticket_checklists WHERE template_id = " . (int)$tplId)->fetchAll(PDO::FETCH_COLUMN);
    foreach ($ids as $id) ChecklistsService::removeTicketChecklist($conn, (int)$id);
    $conn->exec("DELETE FROM ticket_notes WHERE ticket_id = $ticketId AND id > $noteMark AND note_text LIKE '%ZZ #271 checks%'");
    if ($tplId) { $conn->exec("DELETE FROM checklist_template_items WHERE template_id = $tplId"); $conn->exec("DELETE FROM checklist_templates WHERE id = $tplId");
                  try { $conn->exec("DELETE FROM checklist_template_versions WHERE template_id = $tplId"); } catch (Throwable) {} }
    if ($analystId) $conn->exec("DELETE FROM analysts WHERE id = $analystId");
    if ($sessFile) @unlink($sessFile);
}
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
