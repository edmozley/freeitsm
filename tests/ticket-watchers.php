<?php
/* 🔴 NEVER OVER THE WEB - it writes to the database. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * Watching a ticket (GitHub #277): the rules, who is notified of what, the
 * per-install choice of events, the "reassigned" wording, the browser endpoint
 * (forged session) and the REST API (real keys).
 *
 *   FREEITSM_TEST_DATABASE=<throwaway db> FREEITSM_TEST_URL=http://localhost/<that install> php tests/ticket-watchers.php
 *
 * Runs Database Verify first. Makes three ZZ analysts, two keys and their watches
 * and notifications; all removed in `finally`, with the ticket's assignee and the
 * events setting put back.
 */

chdir(__DIR__ . '/..');
$pass = 0; $fail = 0;
function check(string $what, bool $ok): void { global $pass, $fail; echo ($ok ? '  [PASS] ' : '  [FAIL] ') . $what . "\n"; $ok ? $pass++ : $fail++; }

$expected = getenv('FREEITSM_TEST_DATABASE');
$base = rtrim((string)getenv('FREEITSM_TEST_URL'), '/');
if (!$expected || !$base) { echo "(skipped: set FREEITSM_TEST_DATABASE and FREEITSM_TEST_URL)\n"; exit(0); }
require_once 'config.php';
require_once 'includes/functions.php';
require_once 'includes/tenancy.php';
require_once 'includes/services/ticket_watchers.php';
require_once 'includes/notifications_router.php';
$conn = connectToDatabase();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
if ($conn->query('SELECT DATABASE()')->fetchColumn() !== $expected) { echo "(skipped: config.php does not use $expected)\n"; exit(0); }
exec(escapeshellarg(PHP_BINARY) . ' scripts/db_verify_cli.php --apply 2>&1', $o, $code);
if ($code !== 0) { check('Database Verify ran', false); exit(1); }

$sessDir = rtrim((string)(getenv('FREEITSM_SESS_DIR') ?: 'C:/wamp64/tmp'), '/');
$tag = 'zz277' . bin2hex(random_bytes(3));
$ticketId = (int)$conn->query("SELECT id FROM tickets WHERE deleted_datetime IS NULL ORDER BY id LIMIT 1")->fetchColumn();
$oldAssignee = $conn->query("SELECT assigned_analyst_id FROM tickets WHERE id = $ticketId")->fetchColumn();
$s = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'ticket_watch_events'"); $s->execute();
$oldEvents = $s->fetchColumn();
$people = []; $keyIds = []; $sessions = [];
$mk = function (string $who, int $active = 1) use ($conn, $tag, &$people, $sessDir, &$sessions): int {
    $u = $tag . $who;
    $conn->prepare("INSERT INTO analysts (username, password_hash, full_name, email, is_active, is_admin, can_access_all_modules, can_access_all_tenants) VALUES (?, '!', ?, ?, ?, 1, 1, 1)")
         ->execute([$u, "ZZ $who", "$u@example.invalid", $active]);
    $id = $people[$who] = (int)$conn->lastInsertId();
    file_put_contents("$sessDir/sess_$u", 'analyst_id|i:' . $id . ';analyst_username|s:' . strlen($u) . ':"' . $u . '";analyst_name|s:' . strlen("ZZ $who") . ':"ZZ ' . $who . '";csrf_token|s:1:"x";');
    $sessions[] = "$sessDir/sess_$u";
    return $id;
};
$setEvents = fn(?string $v) => $v === null
    ? $conn->exec("DELETE FROM system_settings WHERE setting_key = 'ticket_watch_events'")
    : $conn->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES ('ticket_watch_events', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")->execute([$v]);
$latest = function (int $analystId) use ($conn, $ticketId): ?string {
    $s = $conn->prepare("SELECT event_type FROM notifications WHERE analyst_id = ? AND entity_type = 'ticket' AND entity_id = ? ORDER BY id DESC LIMIT 1");
    $s->execute([$analystId, $ticketId]);
    $v = $s->fetchColumn();
    return $v === false ? null : $v;
};
$fire = function (string $event, int $actor) use ($ticketId, $conn) {
    $_SESSION = ['analyst_id' => $actor, 'analyst_name' => 'ZZ actor'];
    $t = $conn->query("SELECT id, subject, assigned_analyst_id, owner_id FROM tickets WHERE id = $ticketId")->fetch(PDO::FETCH_ASSOC);
    $t['id'] = (int)$t['id']; $t['assigned_analyst_id'] = (int)$t['assigned_analyst_id'];
    notificationsHandleEvent($event, ['ticket' => $t, 'analyst_id' => $t['assigned_analyst_id']]);
};

try {
    $owner = $mk('owner'); $watcher = $mk('watcher'); $other = $mk('other'); $gone = $mk('gone', 0);
    $conn->prepare("UPDATE tickets SET assigned_analyst_id = ? WHERE id = ?")->execute([$owner, $ticketId]);
    $setEvents(null);

    echo "The rules\n";
    $wCtx = new ActorContext($watcher, null, 'ui', 'en', 'ZZ watcher');
    $oCtx = new ActorContext($owner, null, 'ui', 'en', 'ZZ owner');
    check('anyone who can see a ticket can watch it - even read-only', TicketWatchers::add($conn, $wCtx, $ticketId, $watcher, false)['added'] === true);
    check('watching twice is not a second watch', TicketWatchers::add($conn, $wCtx, $ticketId, $watcher, false)['added'] === false);
    $err = '';
    try { TicketWatchers::add($conn, $wCtx, $ticketId, $other, false); } catch (ServiceError $e) { $err = $e->errorCode; }
    check('adding a colleague without write access is refused', $err === 'read_only');
    check('...and allowed with it', TicketWatchers::add($conn, $oCtx, $ticketId, $other, true)['added'] === true);
    $err = '';
    try { TicketWatchers::add($conn, $oCtx, $ticketId, $gone, true); } catch (ServiceError $e) { $err = $e->errorCode; }
    check('an inactive analyst cannot be added', $err === 'invalid_field');
    $names = array_column(TicketWatchers::list($conn, $ticketId), 'name');
    check('the list shows who watches, oldest first', $names === ['ZZ watcher', 'ZZ other']);

    echo "\nWho is told\n";
    $fire('ticket.note_added', $other);
    check('a note: the assignee is told', $latest($owner) === 'ticket.note_added');
    check('...and the watcher', $latest($watcher) === 'ticket.note_added');
    check('...but never the watcher who wrote it', $latest($other) === null);
    $conn->exec("UPDATE notifications SET read_datetime = UTC_TIMESTAMP() WHERE entity_type = 'ticket' AND entity_id = $ticketId AND analyst_id IN ($owner, $watcher, $other)");
    $fire('ticket.assigned', $other);
    check('reassigned: the new assignee hears "assigned to you"', $latest($owner) === 'ticket.assigned');
    check('...a watcher hears "reassigned", not "assigned to you"', $latest($watcher) === 'ticket.reassigned');
    $conn->exec("UPDATE notifications SET read_datetime = UTC_TIMESTAMP() WHERE entity_type = 'ticket' AND entity_id = $ticketId AND analyst_id IN ($owner, $watcher, $other)");
    $before = $conn->query("SELECT COUNT(*) FROM notifications WHERE analyst_id = $watcher")->fetchColumn();
    $setEvents('ticket.reply_received,ticket.status_changed');
    $fire('ticket.note_added', $other);
    check('with notes unticked in Settings, the watcher is not told about a note', (int)$conn->query("SELECT COUNT(*) FROM notifications WHERE analyst_id = $watcher")->fetchColumn() === (int)$before);
    check('...while the assignee still is', $latest($owner) === 'ticket.note_added');
    $setEvents(null);
    check('never saved = every event', TicketWatchers::events($conn) === TicketWatchers::EVENTS);
    check('a reassignment has its own switch in each person\'s preferences', isset(NotificationsService::types()['ticket.reassigned']));

    echo "\nThe browser endpoint (forged session)\n";
    $http = function (string $who, string $method, array $body = [], string $q = '') use ($base, $tag): array {
        $ch = curl_init("$base/api/tickets/watchers.php$q");
        $o = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-CSRF-Token: x', 'Cookie: PHPSESSID=' . $tag . $who]];
        if ($method === 'POST') { $o[CURLOPT_POST] = true; $o[CURLOPT_POSTFIELDS] = json_encode($body); }
        curl_setopt_array($ch, $o);
        $raw = (string)curl_exec($ch);
        return [(int)curl_getinfo($ch, CURLINFO_HTTP_CODE), json_decode($raw, true) ?: ['raw' => substr($raw, 0, 200)]];
    };
    [$c, $j] = $http('watcher', 'GET', [], "?ticket_id=$ticketId");
    check("GET says I watch, and lists everyone ($c)", $c === 200 && ($j['watching'] ?? null) === true && count($j['watchers'] ?? []) === 2);
    [$c, $j] = $http('watcher', 'POST', ['action' => 'unwatch', 'ticket_id' => $ticketId]);
    check("Stop watching ($c)", $c === 200 && ($j['removed'] ?? null) === true && ($j['watching'] ?? null) === false);
    [$c, $j] = $http('watcher', 'POST', ['action' => 'watch_many', 'ticket_ids' => [$ticketId, 999999999]]);
    check("watch a selection: one watched, one I cannot see skipped ($c)", $c === 200 && ($j['added'] ?? 0) === 1 && ($j['skipped'] ?? 0) === 1);
    [$c, $j] = $http('watcher', 'GET', [], '?ticket_id=999999999');
    check("a ticket I cannot see is 404 ($c)", $c === 404);

    echo "\nThe REST API\n";
    $keys = [];
    foreach (['rw' => ['ticket_watchers' => ['read', 'create', 'delete']], 'ro' => ['ticket_watchers' => ['read']]] as $k => $perm) {
        $keys[$k] = 'fitsm_' . bin2hex(random_bytes(24));
        $conn->prepare('INSERT INTO api_keys (name, key_prefix, key_hash, analyst_id, permissions) VALUES (?, ?, ?, ?, ?)')
             ->execute([$tag . $k, substr($keys[$k], 0, 12), hash('sha256', $keys[$k]), $owner, json_encode($perm)]);
        $keyIds[] = (int)$conn->lastInsertId();
    }
    $api = function (string $method, string $path, string $key, ?array $body = null) use ($base): array {
        $ch = curl_init($base . '/api/v1/index.php?path=' . rawurlencode($path));
        $o = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_CUSTOMREQUEST => $method,
              CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $key, 'Content-Type: application/json']];
        if ($body !== null) $o[CURLOPT_POSTFIELDS] = json_encode($body);
        curl_setopt_array($ch, $o);
        $raw = (string)curl_exec($ch);
        return [(int)curl_getinfo($ch, CURLINFO_HTTP_CODE), json_decode($raw, true)];
    };
    [$c, $j] = $api('GET', "/tickets/$ticketId/watchers", $keys['ro']);
    check("GET /tickets/{id}/watchers ($c)", $c === 200 && count($j['data'] ?? []) === 2 && isset($j['data'][0]['analyst']['name']));
    [$c] = $api('POST', "/tickets/$ticketId/watchers", $keys['ro'], []);
    check("a read-only key cannot add ($c)", $c === 403);
    [$c, $j] = $api('POST', "/tickets/$ticketId/watchers", $keys['rw'], []);
    check("POST with no body watches as the key's analyst - 201 ($c)", $c === 201 && ($j['data']['analyst']['id'] ?? 0) === $owner);
    [$c] = $api('POST', "/tickets/$ticketId/watchers", $keys['rw'], []);
    check("...again is 200, not a second watch ($c)", $c === 200);
    [$c] = $api('DELETE', "/tickets/$ticketId/watchers/$owner", $keys['rw']);
    check("DELETE takes them off ($c)", $c === 200);
    [$c] = $api('DELETE', "/tickets/$ticketId/watchers/$owner", $keys['rw']);
    check("...and 404 when they were not watching ($c)", $c === 404);

    echo "\nMerge\n";
    require_once 'includes/ticket_merge.php';
    check('watchers move with a merge, once each (and come back with an unmerge)', (MERGE_MOVE_DEDUPE['ticket_watchers'] ?? '') === 'analyst_id');
} finally {
    $ids = implode(',', array_map('intval', $people ?: [0]));
    $conn->exec("DELETE FROM ticket_watchers WHERE analyst_id IN ($ids)");
    $conn->exec("DELETE FROM notifications WHERE analyst_id IN ($ids)");
    foreach ($keyIds as $id) { try { $conn->exec("DELETE FROM api_key_rate_limits WHERE api_key_id = $id"); } catch (Throwable) {} $conn->exec("DELETE FROM api_keys WHERE id = $id"); }
    $conn->prepare("UPDATE tickets SET assigned_analyst_id = ? WHERE id = ?")->execute([$oldAssignee ?: null, $ticketId]);
    $conn->exec("DELETE FROM analysts WHERE id IN ($ids)");
    foreach ($sessions as $f) @unlink($f);
    $setEvents($oldEvents === false ? null : $oldEvents);
}
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
