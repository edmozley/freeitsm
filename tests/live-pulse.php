<?php
/* 🔴 NEVER OVER THE WEB - it writes to the database. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * Live updates (GitHub #267): every signature moves when somebody changes what it stands
 * for, stays still when nothing changed, and says nothing about what the analyst cannot
 * see. Then the real endpoint (forged session): hashes only, read-only, signed-out refused.
 *
 *   FREEITSM_TEST_DATABASE=<throwaway db> FREEITSM_TEST_URL=http://localhost/<that install> php tests/live-pulse.php
 *
 * Makes a ZZ task, a note, a history row, a notification and a ZZ analyst; touches the
 * first ticket's updated time (put back). All removed in finally.
 *
 * @wiki Live-Updates
 */
chdir(__DIR__ . '/..');
$pass = 0; $fail = 0;
function check(string $what, bool $ok, string $detail = ''): void { global $pass, $fail; echo ($ok ? '  [PASS] ' : '  [FAIL] ') . $what . ($ok || $detail === '' ? '' : "  <- $detail") . "\n"; $ok ? $pass++ : $fail++; }

$expected = getenv('FREEITSM_TEST_DATABASE');
$base = rtrim((string)getenv('FREEITSM_TEST_URL'), '/');
if (!$expected || !$base) { echo "(skipped: set FREEITSM_TEST_DATABASE and FREEITSM_TEST_URL)\n"; exit(0); }
require_once 'config.php';
require_once 'includes/functions.php';
require_once 'includes/tenancy.php';
require_once 'includes/live_pulse.php';
$conn = connectToDatabase();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
if ($conn->query('SELECT DATABASE()')->fetchColumn() !== $expected) { echo "(skipped: config.php does not use $expected)\n"; exit(0); }
exec(escapeshellarg(PHP_BINARY) . ' scripts/db_verify_cli.php --apply 2>&1', $o, $code);
if ($code !== 0) { check('Database Verify ran', false); exit(1); }

$sessDir = rtrim((string)(getenv('FREEITSM_SESS_DIR') ?: 'C:/wamp64/tmp'), '/');
$tag = 'zz267' . bin2hex(random_bytes(3));
$ticketId = (int)$conn->query("SELECT id FROM tickets WHERE deleted_datetime IS NULL ORDER BY id LIMIT 1")->fetchColumn();
$oldUpdated = $conn->query("SELECT updated_datetime FROM tickets WHERE id = $ticketId")->fetchColumn();
$me = 0; $taskId = 0; $noteId = 0; $auditId = 0; $notifId = 0; $sess = '';
$sig = function (array $topics) use ($conn, &$me) { return livePulseSignatures($conn, $me, $topics); };

try {
    $conn->prepare("INSERT INTO analysts (username, password_hash, full_name, email, is_active, is_admin, can_access_all_modules, can_access_all_tenants) VALUES (?, '!', 'ZZ live', ?, 1, 1, 1, 1)")
         ->execute([$tag, "$tag@example.invalid"]);
    $me = (int)$conn->lastInsertId();
    $sess = "$sessDir/sess_$tag";
    file_put_contents($sess, 'analyst_id|i:' . $me . ';analyst_username|s:' . strlen($tag) . ':"' . $tag . '";analyst_name|s:7:"ZZ live";csrf_token|s:1:"x";');

    echo "Nothing changed, nothing moves\n";
    $a = $sig(['tickets', "ticket:$ticketId", 'tasks', 'notifications']);
    $b = $sig(['tickets', "ticket:$ticketId", 'tasks', 'notifications']);
    check('two looks in a row give the same signatures', $a === $b);
    check('every topic answered with a hash', count(array_filter($a, fn($v) => is_string($v) && strlen($v) === 32)) === 4, json_encode($a));

    echo "\nSomebody else changes a ticket\n";
    $conn->prepare("UPDATE tickets SET updated_datetime = DATE_ADD(UTC_TIMESTAMP(), INTERVAL 1 DAY) WHERE id = ?")->execute([$ticketId]);
    $c = $sig(['tickets', "ticket:$ticketId", 'tasks']);
    check('a status / priority / assignee change (updated time) moves the list', $c['tickets'] !== $a['tickets']);
    check('...and that ticket', $c["ticket:$ticketId"] !== $a["ticket:$ticketId"]);
    check('...but not the tasks board', $c['tasks'] === $a['tasks']);
    $conn->prepare("INSERT INTO ticket_notes (ticket_id, analyst_id, note_text, is_internal, created_datetime) VALUES (?, NULL, ?, 1, UTC_TIMESTAMP())")->execute([$ticketId, "$tag note"]);
    $noteId = (int)$conn->lastInsertId();
    $d = $sig(['tickets', "ticket:$ticketId"]);
    check('a note moves the list and the ticket', $d['tickets'] !== $c['tickets'] && $d["ticket:$ticketId"] !== $c["ticket:$ticketId"]);
    $conn->prepare("INSERT INTO ticket_audit (ticket_id, analyst_id, field_name, old_value, new_value, created_datetime) VALUES (?, NULL, 'ZZ live', 'a', 'b', UTC_TIMESTAMP())")->execute([$ticketId]);
    $auditId = (int)$conn->lastInsertId();
    $e = $sig(["ticket:$ticketId"]);
    check('a history row (a change that only audits) moves the ticket', $e["ticket:$ticketId"] !== $d["ticket:$ticketId"]);

    echo "\nTasks\n";
    $t0 = $sig(['tasks']);
    $conn->prepare("INSERT INTO tasks (title, status_id, created_by_id, created_datetime, updated_datetime) VALUES (?, (SELECT id FROM task_statuses ORDER BY id LIMIT 1), ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())")->execute(["$tag task", $me]);
    $taskId = (int)$conn->lastInsertId();
    $t1 = $sig(['tasks', "task:$taskId"]);
    check('a new task moves the board', $t1['tasks'] !== $t0['tasks']);
    $conn->prepare("INSERT INTO task_comments (task_id, analyst_id, comment, created_datetime) VALUES (?, NULL, ?, UTC_TIMESTAMP())")->execute([$taskId, "$tag c"]);
    $t2 = $sig(["task:$taskId"]);
    check('a comment moves the open task', $t2["task:$taskId"] !== $t1["task:$taskId"]);
    $conn->prepare("DELETE FROM task_comments WHERE task_id = ?")->execute([$taskId]);
    $conn->prepare("DELETE FROM tasks WHERE id = ?")->execute([$taskId]);
    $t3 = $sig(['tasks', "task:$taskId"]);
    check('deleting it moves the board back off', $t3['tasks'] !== $t1['tasks']);
    check('...and the deleted task answers null', $t3["task:$taskId"] === null);
    $taskId = 0;

    echo "\nThe bell\n";
    $n0 = $sig(['notifications']);
    $conn->prepare("INSERT INTO notifications (analyst_id, event_type, entity_type, entity_id, title, created_datetime, updated_datetime) VALUES (?, 'ticket.note_added', 'ticket', ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())")
         ->execute([$me, $ticketId, "$tag bell"]);
    $notifId = (int)$conn->lastInsertId();
    check('a new notification moves the bell', $sig(['notifications'])['notifications'] !== $n0['notifications']);

    echo "\nWhat it will not say\n";
    $x = $sig(['ticket:999999999', 'task:999999999', 'bogus', 'ticket:abc']);
    check('a ticket or task that does not exist is null', array_key_exists('ticket:999999999', $x) && $x['ticket:999999999'] === null && array_key_exists('task:999999999', $x) && $x['task:999999999'] === null);
    check('a topic it does not know is left out', !array_key_exists('bogus', $x) && !array_key_exists('ticket:abc', $x));
    check('no more than ' . LIVE_PULSE_MAX_TOPICS . ' topics a request', count($sig(array_map(fn($i) => "ticket:$i", range(1, 40)))) === LIVE_PULSE_MAX_TOPICS);
    check('the heartbeat is 15 seconds unless the analyst chose otherwise', livePulseSeconds($conn, $me) === 15);
    $conn->prepare("INSERT INTO user_preferences (analyst_id, preference_key, preference_value) VALUES (?, 'live_updates', '0')")->execute([$me]);
    check('...and 0 (off) when they switched it off', livePulseSeconds($conn, $me) === 0);

    echo "\nThe endpoint\n";
    $get = function (string $q, bool $signedIn = true) use ($base, $tag): array {
        $ch = curl_init("$base/api/system/pulse.php?$q");
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_HTTPHEADER => $signedIn ? ['Cookie: PHPSESSID=' . $tag] : []]);
        $raw = (string)curl_exec($ch);
        return [(int)curl_getinfo($ch, CURLINFO_HTTP_CODE), json_decode($raw, true), $raw];
    };
    [$code, $j, $raw] = $get('topics=' . rawurlencode("tickets,ticket:$ticketId,notifications"));
    check("answers a hash per topic ($code)", $code === 200 && strlen((string)($j['sig']['tickets'] ?? '')) === 32 && isset($j['sig']["ticket:$ticketId"]), substr($raw, 0, 200));
    check('...and nothing but hashes - no ids, times or counts', !preg_match('/\d{4}-\d{2}-\d{2}|"(id|count|marker)"/', $raw));
    [$code] = $get('topics=tickets', false);
    check("signed out is refused ($code)", $code === 401);
} catch (Throwable $e) {
    check('no exception', false, $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    if ($noteId)  $conn->exec("DELETE FROM ticket_notes WHERE id = $noteId");
    if ($auditId) $conn->exec("DELETE FROM ticket_audit WHERE id = $auditId");
    if ($notifId) $conn->exec("DELETE FROM notifications WHERE id = $notifId");
    if ($taskId)  { $conn->exec("DELETE FROM task_comments WHERE task_id = $taskId"); $conn->exec("DELETE FROM tasks WHERE id = $taskId"); }
    if ($me) { $conn->exec("DELETE FROM user_preferences WHERE analyst_id = $me"); $conn->exec("DELETE FROM notifications WHERE analyst_id = $me"); $conn->exec("DELETE FROM analysts WHERE id = $me"); }
    if ($ticketId) $conn->prepare("UPDATE tickets SET updated_datetime = ? WHERE id = ?")->execute([$oldUpdated ?: null, $ticketId]);
    if ($sess) @unlink($sess);
}
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
