<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * The bell's (i) panel - "why is this here?" (5.8.0) - through its real endpoint
 * (api/notifications/explain.php, backed by includes/notification_explain.php).
 *
 *   1. a workflow's change: done by the workflow (automatic), how = workflow, why you =
 *      in the team it was handed to, the ticket history's Team line by System, the run
 *   2. a hand assignment: done by the person, how = on screen, why you = assigned to you,
 *      the history's Owner line by that person
 *   3. it is private: somebody else's notification is 404; no session is 401
 *   4. a ticket you can no longer see keeps its history hidden ("no_access")
 *   5. a row written before 5.8.0 says so (recorded = false)
 *
 * Disposable install only - creates and deletes ZZ fixtures, forges sessions:
 *   FREEITSM_TEST_DATABASE=<db> FREEITSM_TEST_URL=http://localhost/<install> php tests/notification-explain.php
 */
chdir(__DIR__ . '/..');
$expected = getenv('FREEITSM_TEST_DATABASE');
$base = rtrim((string)getenv('FREEITSM_TEST_URL'), '/');
if (!$expected || !$base) { echo "Set FREEITSM_TEST_DATABASE and FREEITSM_TEST_URL.\n"; exit(1); }
require_once 'config.php';
require_once 'includes/functions.php';
require_once 'includes/service_context.php';
require_once 'includes/services/tickets.php';
$conn = connectToDatabase();
if ($conn->query('SELECT DATABASE()')->fetchColumn() !== $expected) { echo "Wrong database.\n"; exit(1); }
$sessDir = rtrim(getenv('FREEITSM_SESS_DIR') ?: (ini_get('session.save_path') ?: sys_get_temp_dir()), '/\\');

$pass = 0; $fail = 0;
$check = function (string $label, bool $ok, string $why = '') use (&$pass, &$fail) {
    echo ($ok ? '  PASS  ' : '  FAIL  ') . $label . ($ok || $why === '' ? '' : "  [$why]") . "\n";
    $ok ? $pass++ : $fail++;
};
$tag = 'zznx' . bin2hex(random_bytes(3));
$S = ['analysts' => [], 'team' => 0, 'ticket' => 0, 'workflow' => 0, 'sessions' => []];
$session = function (int $analystId, string $name) use ($sessDir, $tag, &$S): string {
    $sid = $tag . $analystId;
    file_put_contents($sessDir . '/sess_' . $sid, 'analyst_id|i:' . $analystId . ';analyst_name|s:' . strlen($name) . ':"' . $name . '";csrf_token|s:1:"x";');
    $S['sessions'][] = $sessDir . '/sess_' . $sid;
    return $sid;
};
$post = function (string $sid, string $path, array $body) use ($base): array {
    $ch = curl_init($base . '/' . $path);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_TIMEOUT => 30, CURLOPT_COOKIE => 'PHPSESSID=' . $sid,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-CSRF-Token: x'], CURLOPT_POSTFIELDS => json_encode($body)]);
    return json_decode((string)curl_exec($ch), true) ?? [];
};
$explain = function (?string $sid, int $id) use ($base): array {
    $ch = curl_init($base . '/api/notifications/explain.php?id=' . $id);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30] + ($sid ? [CURLOPT_COOKIE => 'PHPSESSID=' . $sid] : []));
    $raw = (string)curl_exec($ch);
    return [(int)curl_getinfo($ch, CURLINFO_HTTP_CODE), json_decode($raw, true)['explain'] ?? null, $raw];
};
$latestRow = function (int $analystId) use ($conn, &$S): int {
    $st = $conn->prepare("SELECT id FROM notifications WHERE entity_type = 'ticket' AND entity_id = ? AND analyst_id = ? ORDER BY id DESC LIMIT 1");
    $st->execute([$S['ticket'], $analystId]);
    return (int)$st->fetchColumn();
};
$markRead = function () use ($conn, &$S) { $conn->prepare("UPDATE notifications SET read_datetime = UTC_TIMESTAMP() WHERE entity_type = 'ticket' AND entity_id = ?")->execute([$S['ticket']]); };   // by reference: $S["ticket"] is set later

try {
    $mk = function (string $suffix, string $name) use ($conn, $tag, &$S): int {
        $conn->prepare("INSERT INTO analysts (username, password_hash, full_name, email, is_active, is_admin, can_access_all_modules, can_access_all_tenants) VALUES (?, '!', ?, ?, 1, 1, 1, 1)")
             ->execute([$tag . $suffix, $name, $tag . $suffix . '@example.invalid']);
        return $S['analysts'][] = (int)$conn->lastInsertId();
    };
    $nguyen = $mk('n', 'ZZ Nguyen');
    $member = $mk('m', 'ZZ Member');
    $other  = $mk('o', 'ZZ Other');
    $conn->prepare('INSERT INTO teams (name) VALUES (?)')->execute(["ZZ $tag Team A"]);
    $S['team'] = (int)$conn->lastInsertId();
    $conn->prepare('INSERT INTO analyst_teams (analyst_id, team_id) VALUES (?, ?)')->execute([$member, $S['team']]);
    $tenant = (int)$conn->query('SELECT id FROM tenants ORDER BY is_default DESC, id LIMIT 1')->fetchColumn();
    $S['ticket'] = TicketsService::createTicket($conn, ActorContext::system('Test'), $tenant,
        ['subject' => "ZZ $tag", 'description' => 'x', 'requester_email' => "$tag.req@example.invalid", 'requester_name' => 'ZZ Requester'], null, 'ZZ');
    $wfName = "ZZ $tag route to Team A";
    $conn->prepare("INSERT INTO workflows (name, trigger_event, conditions, actions, is_active) VALUES (?, 'ticket.priority_changed', ?, ?, 1)")->execute([
        $wfName, json_encode([['field' => 'ticket.id', 'op' => 'in', 'value' => [(string)$S['ticket']]]]),
        json_encode([['type' => 'assign_ticket_team', 'args' => ['ticket_id' => '{{ticket.id}}', 'team_id' => (string)$S['team']]]]),
    ]);
    $S['workflow'] = (int)$conn->lastInsertId();
    $sN = $session($nguyen, 'ZZ Nguyen'); $sM = $session($member, 'ZZ Member'); $sO = $session($other, 'ZZ Other');
    $prio = (int)$conn->query('SELECT id FROM ticket_priorities ORDER BY id DESC LIMIT 1')->fetchColumn();

    echo "== 1. A workflow's change\n";
    $post($sN, 'api/tickets/assign_ticket.php', ['ticket_id' => $S['ticket'], 'priority_id' => $prio]);
    $id1 = $latestRow($member);
    [$code, $x, $raw] = $explain($sM, $id1);
    $check('the member can explain their row', $code === 200 && $x !== null, "$code $raw");
    $check('done by the workflow, and it says so', ($x['actor']['kind'] ?? null) === 'workflow' && ($x['actor']['name'] ?? null) === $wfName && ($x['actor']['source'] ?? null) === 'workflow');
    $check('why you: in the team it was handed to', ($x['reason'] ?? null) === 'team_member', json_encode($x['reason'] ?? null));
    $check('the ticket history\'s Team line, by System', ($x['history']['field'] ?? null) === 'Team' && ($x['history']['by_kind'] ?? null) === 'system', json_encode($x['history'] ?? $x['history_note'] ?? null));
    $check('the workflow run is listed', in_array($wfName, array_column($x['workflow_runs'] ?? [], 'name'), true));
    $check('a new row says it was recorded (5.8.0)', ($x['recorded'] ?? null) === true);

    echo "== 2. A hand assignment\n";
    $markRead();
    $post($sN, 'api/tickets/assign_ticket.php', ['ticket_id' => $S['ticket'], 'assigned_analyst_id' => $member]);
    $post($sN, 'api/tickets/log_ticket_audit.php', ['ticket_id' => $S['ticket'], 'field_name' => 'Owner', 'old_value' => null, 'new_value' => 'ZZ Member']);
    [$code, $x] = $explain($sM, $latestRow($member));
    $check('done by Nguyen, on screen', ($x['actor']['kind'] ?? null) === 'analyst' && ($x['actor']['name'] ?? null) === 'ZZ Nguyen' && ($x['actor']['source'] ?? null) === 'screen');
    $check('why you: it was assigned to you', ($x['reason'] ?? null) === 'new_owner', json_encode($x['reason'] ?? null));
    $check('the history\'s Owner line, by Nguyen', ($x['history']['field'] ?? null) === 'Owner' && ($x['history']['by_name'] ?? null) === 'ZZ Nguyen', json_encode($x['history'] ?? $x['history_note'] ?? null));

    echo "== 3. Private\n";
    [$code] = $explain($sO, $id1);
    $check('somebody else\'s notification is 404', $code === 404, (string)$code);
    [$code] = $explain(null, $id1);
    $check('no session is 401', $code === 401, (string)$code);

    echo "== 4. A ticket you can no longer see\n";
    // A second company to move the ticket into - made for the test when the install has only one.
    $tenants = $conn->query('SELECT id FROM tenants ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
    if (count($tenants) < 2) {
        $conn->prepare('INSERT INTO tenants (name) VALUES (?)')->execute(["ZZ $tag company"]);
        $S['tenant'] = (int)$conn->lastInsertId();
        $tenants[] = $S['tenant'];
    }
    if (count($tenants) >= 2) {
        $away = (int)current(array_filter($tenants, fn($t) => (int)$t !== $tenant));
        $conn->prepare('UPDATE analysts SET can_access_all_tenants = 0, is_admin = 0 WHERE id = ?')->execute([$member]);
        $conn->prepare('UPDATE tickets SET tenant_id = ? WHERE id = ?')->execute([$away, $S['ticket']]);
        [$code, $x] = $explain($sM, $id1);
        $check('the row still explains itself, but the history is hidden', $code === 200 && array_key_exists('history', (array)$x) && $x['history'] === null && ($x['history_note'] ?? null) === 'no_access', json_encode([$x['history'] ?? null, $x['history_note'] ?? null]));
        $conn->prepare('UPDATE tickets SET tenant_id = ? WHERE id = ?')->execute([$tenant, $S['ticket']]);
    } else {
        echo "  (skipped - this install has one company)\n";
    }

    echo "== 5. A row from before 5.8.0\n";
    $conn->prepare("UPDATE notifications SET actor_kind = NULL, actor_analyst_id = NULL, source = NULL, workflow_id = NULL, reason = NULL WHERE id = ?")->execute([$id1]);
    $conn->prepare('UPDATE analysts SET can_access_all_tenants = 1, is_admin = 1 WHERE id = ?')->execute([$member]);
    [$code, $x] = $explain($sM, $id1);
    $check('it says only a name was kept', $code === 200 && ($x['recorded'] ?? null) === false && array_key_exists('reason', (array)$x) && $x['reason'] === null, json_encode([$code, $x['recorded'] ?? 'unset', $x['reason'] ?? 'unset', $x['actor'] ?? null]));
    $check('...and still finds the history line by time', ($x['history']['field'] ?? null) === 'Team', json_encode($x['history'] ?? $x['history_note'] ?? null));
} catch (Throwable $e) {
    $check('no exception', false, get_class($e) . ': ' . $e->getMessage() . ' @' . $e->getLine());
} finally {
    $t = (int)$S['ticket'];
    if ($t) foreach (['notifications' => "entity_type = 'ticket' AND entity_id = $t", 'ticket_audit' => "ticket_id = $t", 'ticket_notes' => "ticket_id = $t", 'tickets' => "id = $t"] as $tbl => $w) $conn->exec("DELETE FROM $tbl WHERE $w");
    if ($S['workflow']) { $conn->exec('DELETE FROM workflow_executions WHERE workflow_id = ' . $S['workflow']); $conn->exec('DELETE FROM workflows WHERE id = ' . $S['workflow']); }
    if ($S['team']) { $conn->exec('DELETE FROM analyst_teams WHERE team_id = ' . $S['team']); $conn->exec('DELETE FROM teams WHERE id = ' . $S['team']); }
    foreach ($S['analysts'] as $a) {
        foreach (['notifications', 'user_preferences', 'analyst_teams'] as $tbl) $conn->exec("DELETE FROM $tbl WHERE analyst_id = $a");
        $conn->exec("DELETE FROM analysts WHERE id = $a");
    }
    foreach ($S['sessions'] as $f) if (is_file($f)) unlink($f);
    if (!empty($S['tenant'])) $conn->exec('DELETE FROM tenants WHERE id = ' . (int)$S['tenant']);
}
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
