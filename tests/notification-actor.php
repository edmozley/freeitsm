<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * GH #365: the bell names what REALLY made a change, and records it.
 *
 * Before 5.8.0 the bell took its "by" name from whoever was signed in for the web
 * request. A workflow that assigned a team inside somebody's request was announced
 * as "Handed to your team by" them - and they, being "the actor", were left out of
 * their own team's notification. Through the real endpoints:
 *
 *   1. a workflow assigns a team inside Nguyen's request: the row says kind workflow,
 *      the workflow's id and name, source workflow, no analyst - and Nguyen, who is in
 *      the team, IS told (it was not his action)
 *   2. Nguyen assigns the ticket by hand: kind analyst, his id, source screen
 *   3. the REST API, a key acting as Nguyen: kind api, his id, the key's id
 *   4. never-tell-me-about-my-own-action still holds for a person
 *   5. the mailbox import acts as FreeITSM (source mail_import), and a workflow
 *      inside it still names itself
 *   6. a merged (unread) row carries the LATEST event's actor, not the first's
 *
 * Disposable install only - creates and deletes ZZ fixtures, forges a session:
 *   FREEITSM_TEST_DATABASE=<db> FREEITSM_TEST_URL=http://localhost/<install> php tests/notification-actor.php
 */
chdir(__DIR__ . '/..');
$expected = getenv('FREEITSM_TEST_DATABASE');
$base = rtrim((string)getenv('FREEITSM_TEST_URL'), '/');
if (!$expected || !$base) { echo "Set FREEITSM_TEST_DATABASE and FREEITSM_TEST_URL.\n"; exit(1); }
require_once 'config.php';
require_once 'includes/functions.php';
require_once 'includes/service_context.php';
require_once 'includes/services/tickets.php';
require_once 'includes/notification_actor.php';
$conn = connectToDatabase();
if ($conn->query('SELECT DATABASE()')->fetchColumn() !== $expected) { echo "Wrong database.\n"; exit(1); }
$sessDir = rtrim(getenv('FREEITSM_SESS_DIR') ?: (ini_get('session.save_path') ?: sys_get_temp_dir()), '/\\');

$pass = 0; $fail = 0;
$check = function (string $label, bool $ok, string $why = '') use (&$pass, &$fail) {
    echo ($ok ? '  PASS  ' : '  FAIL  ') . $label . ($ok || $why === '' ? '' : "  [$why]") . "\n";
    $ok ? $pass++ : $fail++;
};
$tag = 'zzna' . bin2hex(random_bytes(3));
$S = ['analysts' => [], 'team' => 0, 'ticket' => 0, 'workflow' => 0, 'key' => 0];
$sid = $tag . 'sess';
$sessFile = $sessDir . '/sess_' . $sid;

$post = function (string $path, array $body) use ($base, $sid): array {
    $ch = curl_init($base . '/' . $path);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_TIMEOUT => 30, CURLOPT_COOKIE => 'PHPSESSID=' . $sid,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-CSRF-Token: x'], CURLOPT_POSTFIELDS => json_encode($body)]);
    return json_decode((string)curl_exec($ch), true) ?? [];
};
$rowFor = function (int $analystId) use ($conn, &$S): ?array {
    $st = $conn->prepare("SELECT * FROM notifications WHERE entity_type = 'ticket' AND entity_id = ? AND analyst_id = ? AND read_datetime IS NULL ORDER BY id DESC LIMIT 1");
    $st->execute([$S['ticket'], $analystId]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
};
$markRead = function () use ($conn, &$S) {   // so the next step writes a fresh row rather than merging
    $conn->prepare("UPDATE notifications SET read_datetime = UTC_TIMESTAMP() WHERE entity_type = 'ticket' AND entity_id = ?")->execute([$S['ticket']]);
};

try {
    $cols = $conn->query("SHOW COLUMNS FROM notifications")->fetchAll(PDO::FETCH_COLUMN);
    $check('the bell table has the actor columns (run Database Verification)', !array_diff(['actor_kind', 'actor_analyst_id', 'source', 'workflow_id', 'api_key_id'], $cols));

    $mk = function (string $suffix, string $name) use ($conn, $tag, &$S): int {
        $conn->prepare("INSERT INTO analysts (username, password_hash, full_name, email, is_active, is_admin, can_access_all_modules, can_access_all_tenants) VALUES (?, '!', ?, ?, 1, 1, 1, 1)")
             ->execute([$tag . $suffix, $name, $tag . $suffix . '@example.invalid']);
        return $S['analysts'][] = (int)$conn->lastInsertId();
    };
    $nguyen = $mk('n', 'ZZ Nguyen');
    $member = $mk('m', 'ZZ Member');
    $conn->prepare('INSERT INTO teams (name) VALUES (?)')->execute(["ZZ $tag Team A"]);
    $S['team'] = (int)$conn->lastInsertId();
    foreach ([$nguyen, $member] as $a) $conn->prepare('INSERT INTO analyst_teams (analyst_id, team_id) VALUES (?, ?)')->execute([$a, $S['team']]);
    $tenant = (int)$conn->query('SELECT id FROM tenants ORDER BY is_default DESC, id LIMIT 1')->fetchColumn();
    $S['ticket'] = TicketsService::createTicket($conn, ActorContext::system('Test'), $tenant,
        ['subject' => "ZZ $tag", 'description' => 'x', 'requester_email' => "$tag.req@example.invalid", 'requester_name' => 'ZZ Requester'], null, 'ZZ');
    $wfName = "ZZ $tag route to Team A";
    $conn->prepare("INSERT INTO workflows (name, trigger_event, conditions, actions, is_active) VALUES (?, 'ticket.priority_changed', ?, ?, 1)")->execute([
        $wfName, json_encode([['field' => 'ticket.id', 'op' => 'in', 'value' => [(string)$S['ticket']]]]),
        json_encode([['type' => 'assign_ticket_team', 'args' => ['ticket_id' => '{{ticket.id}}', 'team_id' => (string)$S['team']]]]),
    ]);
    $S['workflow'] = (int)$conn->lastInsertId();
    file_put_contents($sessFile, 'analyst_id|i:' . $nguyen . ';analyst_name|s:9:"ZZ Nguyen";csrf_token|s:1:"x";');
    $prios = $conn->query('SELECT id FROM ticket_priorities ORDER BY id LIMIT 3')->fetchAll(PDO::FETCH_COLUMN);
    $cur = (int)$conn->query('SELECT COALESCE(priority_id, 0) FROM tickets WHERE id = ' . $S['ticket'])->fetchColumn();
    $other = fn() => (int)current(array_filter($prios, fn($p) => (int)$p !== (int)$conn->query('SELECT COALESCE(priority_id, 0) FROM tickets WHERE id = ' . $S['ticket'])->fetchColumn()));

    echo "== 1. A workflow, inside Nguyen's request\n";
    $r = $post('api/tickets/assign_ticket.php', ['ticket_id' => $S['ticket'], 'priority_id' => $other()]);
    $check('the priority change went through', ($r['success'] ?? false) === true, json_encode($r));
    $m = $rowFor($member);
    $check('the team member is told the ticket was handed to the team', $m && $m['event_type'] === 'ticket.team_assigned', json_encode($m['event_type'] ?? null));
    $check('...by the WORKFLOW: kind workflow, its id and its name', $m && $m['actor_kind'] === 'workflow' && (int)$m['workflow_id'] === $S['workflow'] && $m['actor_name'] === $wfName, json_encode([$m['actor_kind'] ?? null, $m['workflow_id'] ?? null, $m['actor_name'] ?? null]));
    $check('...from source workflow, with no analyst named', $m && $m['source'] === 'workflow' && $m['actor_analyst_id'] === null);
    $n = $rowFor($nguyen);
    $check('Nguyen, who is in the team, IS told - it was not his action', $n && $n['event_type'] === 'ticket.team_assigned' && $n['actor_kind'] === 'workflow', json_encode($n['event_type'] ?? 'no row'));

    echo "== 2. Nguyen assigns it by hand\n";
    $markRead();
    $r = $post('api/tickets/assign_ticket.php', ['ticket_id' => $S['ticket'], 'assigned_analyst_id' => $member]);
    $m = $rowFor($member);
    $check('the member is told it was assigned to them', $m && $m['event_type'] === 'ticket.assigned', json_encode($m['event_type'] ?? null));
    $check('...by a PERSON: kind analyst, Nguyen\'s id, source screen', $m && $m['actor_kind'] === 'analyst' && (int)$m['actor_analyst_id'] === $nguyen && $m['source'] === 'screen' && $m['actor_name'] === 'ZZ Nguyen', json_encode([$m['actor_kind'] ?? null, $m['actor_analyst_id'] ?? null, $m['source'] ?? null]));

    echo "== 3. The REST API, a key acting as Nguyen\n";
    $markRead();
    $key = 'fitsm_' . bin2hex(random_bytes(24));
    $conn->prepare('INSERT INTO api_keys (name, key_prefix, key_hash, analyst_id, permissions, rate_limit_per_minute, active, created_datetime) VALUES (?, ?, ?, ?, ?, 1000, 1, UTC_TIMESTAMP())')
         ->execute([$tag, substr($key, 0, 14), hash('sha256', $key), $nguyen, json_encode(['tickets' => ['read', 'update']])]);
    $S['key'] = (int)$conn->lastInsertId();
    $statuses = $conn->query('SELECT id FROM ticket_statuses WHERE is_closed = 0 ORDER BY id LIMIT 3')->fetchAll(PDO::FETCH_COLUMN);
    $curStatus = (int)$conn->query('SELECT COALESCE(status_id, 0) FROM tickets WHERE id = ' . $S['ticket'])->fetchColumn();
    $newStatus = (int)current(array_filter($statuses, fn($x) => (int)$x !== $curStatus));
    $ch = curl_init($base . '/api/v1/index.php?path=' . rawurlencode('/tickets/' . $S['ticket']));
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => 'PATCH', CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $key, 'Content-Type: application/json'], CURLOPT_POSTFIELDS => json_encode(['status_id' => $newStatus])]);
    $raw = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $check('the API status change went through', $code === 200, "$code " . substr((string)$raw, 0, 200));
    $m = $rowFor($member);
    $check('the owner is told the status changed', $m && $m['event_type'] === 'ticket.status_changed', json_encode($m['event_type'] ?? null));
    $check('...through the API: kind api, Nguyen\'s id, the key\'s id, source api', $m && $m['actor_kind'] === 'api' && (int)$m['actor_analyst_id'] === $nguyen && (int)$m['api_key_id'] === $S['key'] && $m['source'] === 'api', json_encode([$m['actor_kind'] ?? null, $m['actor_analyst_id'] ?? null, $m['api_key_id'] ?? null]));

    echo "== 4. Your own action is still not news to you\n";
    $markRead();
    $post('api/tickets/assign_ticket.php', ['ticket_id' => $S['ticket'], 'assigned_analyst_id' => $nguyen]);
    $check('Nguyen assigning it to himself writes nothing to his bell', $rowFor($nguyen) === null);
    // "Reassigned" goes to watchers, not the previous owner (GH #277) - so a watcher shows the rule
    // still lets other people hear about a person's action, credited to that person.
    $conn->prepare('INSERT INTO ticket_watchers (ticket_id, analyst_id, added_by_id, created_datetime) VALUES (?, ?, ?, UTC_TIMESTAMP())')->execute([$S['ticket'], $member, $nguyen]);
    $markRead();
    $post('api/tickets/assign_ticket.php', ['ticket_id' => $S['ticket'], 'assigned_analyst_id' => $member]);
    $post('api/tickets/assign_ticket.php', ['ticket_id' => $S['ticket'], 'assigned_analyst_id' => $nguyen]);
    $check('...while a watcher is told, credited to Nguyen as a person', ($x = $rowFor($member)) && $x['actor_kind'] === 'analyst' && (int)$x['actor_analyst_id'] === $nguyen, json_encode([$x['event_type'] ?? null, $x['actor_kind'] ?? null]));
    $conn->prepare('DELETE FROM ticket_watchers WHERE ticket_id = ?')->execute([$S['ticket']]);

    echo "== 5. The mailbox import, and a workflow inside it\n";
    $mail = NotificationActor::run(['kind' => 'system', 'source' => 'mail_import'], function () {
        $outer = NotificationActor::current();
        $inner = NotificationActor::run(['kind' => 'workflow', 'name' => 'W', 'workflow_id' => 9, 'source' => 'workflow'], fn() => NotificationActor::current());
        return [$outer, $inner, NotificationActor::current()];
    });
    $check('mail import acts as FreeITSM, source mail_import', $mail[0]['kind'] === 'system' && $mail[0]['source'] === 'mail_import' && !NotificationActor::isPerson($mail[0]));
    $check('a workflow inside it names itself, and the import resumes after', $mail[1]['kind'] === 'workflow' && (int)$mail[1]['workflow_id'] === 9 && $mail[2]['source'] === 'mail_import');
    $check('the mailbox endpoint really pushes it', str_contains((string)file_get_contents('api/tickets/check_mailbox_email.php'), "NotificationActor::push(['kind' => 'system', 'id' => null, 'name' => null, 'source' => 'mail_import'])"));

    echo "== 6. A merged row describes its latest event\n";
    $markRead();
    $post('api/tickets/assign_ticket.php', ['ticket_id' => $S['ticket'], 'assigned_analyst_id' => $member]);   // by hand
    $r = $post('api/tickets/assign_ticket.php', ['ticket_id' => $S['ticket'], 'priority_id' => $other()]);       // workflow fires, team unchanged
    $conn->prepare('UPDATE tickets SET assigned_team_id = NULL WHERE id = ?')->execute([$S['ticket']]);
    $r = $post('api/tickets/assign_ticket.php', ['ticket_id' => $S['ticket'], 'priority_id' => $other()]);       // workflow assigns the team again
    $m = $rowFor($member);
    $check('the member has ONE merged row', $m && (int)$m['event_count'] >= 2, json_encode($m['event_count'] ?? null));
    $check('...whose actor is the latest event\'s (the workflow), not the first\'s (Nguyen)', $m && $m['event_type'] === 'ticket.team_assigned' && $m['actor_kind'] === 'workflow' && $m['actor_analyst_id'] === null, json_encode([$m['event_type'] ?? null, $m['actor_kind'] ?? null, $m['actor_analyst_id'] ?? null]));
} catch (Throwable $e) {
    $check('no exception', false, get_class($e) . ': ' . $e->getMessage() . ' @' . $e->getLine());
} finally {
    $t = (int)$S['ticket'];
    if ($t) foreach (['notifications' => "entity_type = 'ticket' AND entity_id = $t", 'ticket_audit' => "ticket_id = $t", 'ticket_notes' => "ticket_id = $t", 'tickets' => "id = $t"] as $tbl => $w) $conn->exec("DELETE FROM $tbl WHERE $w");
    if ($S['workflow']) { $conn->exec('DELETE FROM workflow_executions WHERE workflow_id = ' . $S['workflow']); $conn->exec('DELETE FROM workflows WHERE id = ' . $S['workflow']); }
    if ($S['key']) $conn->exec('DELETE FROM api_keys WHERE id = ' . $S['key']);
    if ($S['team']) { $conn->exec('DELETE FROM analyst_teams WHERE team_id = ' . $S['team']); $conn->exec('DELETE FROM teams WHERE id = ' . $S['team']); }
    foreach ($S['analysts'] as $a) {
        foreach (['notifications', 'user_preferences', 'analyst_teams'] as $tbl) $conn->exec("DELETE FROM $tbl WHERE analyst_id = $a");
        try { $conn->exec("DELETE FROM api_request_log WHERE api_key_id = " . (int)$S['key']); } catch (Throwable $e) { /* no log table on this install */ }
        $conn->exec("DELETE FROM analysts WHERE id = $a");
    }
    if (is_file($sessFile)) unlink($sessFile);
}
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
