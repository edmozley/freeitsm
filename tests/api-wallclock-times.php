<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * REST API: change windows and ticket scheduled work are wall-clock times (GH #375).
 *
 *   FREEITSM_TEST_DATABASE=<db> FREEITSM_TEST_URL=http://localhost/<that install> php tests/api-wallclock-times.php
 *
 * The app shows a change's work, outage and PIR times and a ticket's scheduled work
 * exactly as typed (formatNaiveDateTime()). The REST API read them as UTC instants:
 * it converted any Z or offset to UTC before storing ("20:46-05:00" became 01:46
 * the next day on the calendar) and returned every value with a Z. Now, as for task
 * scheduling and the calendar API, a Z or offset is refused (422), a plain local
 * time is stored as sent, and it comes back without a Z.
 *
 * Part 1 checks serviceWallClockDateTime() in-process. Part 2 goes over real HTTP
 * and needs BOTH variables pointing at a TEST install; it writes one ZZ API key, one
 * ZZ change (deleted through DELETE /changes/{id}) and one ZZ ticket, all removed
 * at the end, and pauses the install's change.* and ticket.* workflows for the run.
 * Control: on the old code the refusals are 201/200 and the times come back
 * shifted and with a Z.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/service_context.php';

$pass = 0; $fail = 0;
/**
 * Records and prints one PASS or FAIL line.
 *
 * @param string $what  what is being checked, in a few words
 * @param bool $cond  true when the check passed
 * @param string $detail  shown after a failure - the values that disagreed
 */
function ok(string $what, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  PASS  {$what}\n"; }
    else       { $fail++; echo "  FAIL  {$what}" . ($detail ? "  <- {$detail}" : '') . "\n"; }
}

echo "\nWall-clock times in the REST API (GH #375)\n" . str_repeat('=', 70) . "\n";

echo "Part 1 - serviceWallClockDateTime()\n";
$parse = function ($v) { try { return serviceWallClockDateTime($v, 'f'); } catch (ServiceError $e) { return 'ERR'; } };
ok('a local time with T is kept as sent', $parse('2026-11-16T20:46:00') === '2026-11-16 20:46:00');
ok('HH:MM gets :00', $parse('2026-11-16 20:46') === '2026-11-16 20:46:00');
ok('a bare date is midnight', $parse('2026-11-16') === '2026-11-16 00:00:00');
ok('fractions of a second are dropped', $parse('2026-11-16T20:46:00.000') === '2026-11-16 20:46:00');
ok('Z is refused', $parse('2026-11-17T01:46:00Z') === 'ERR');
ok('an offset is refused', $parse('2026-11-16T20:46:00-05:00') === 'ERR');
ok('a compact offset is refused', $parse('2026-11-16T20:46:00+0100') === 'ERR');
ok('a date that does not exist is refused', $parse('2026-02-30 10:00') === 'ERR');
ok('null and empty are null', $parse(null) === null && $parse('') === null);

$conn = connectToDatabase();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$dbName = $conn->query('SELECT DATABASE()')->fetchColumn();
$base = rtrim((string)(getenv('FREEITSM_TEST_URL') ?: getenv('FREEITSM_BASE_URL')), '/');
if (getenv('FREEITSM_TEST_DATABASE') !== $dbName || $base === '') {
    echo "\nPart 2 SKIPPED: set FREEITSM_TEST_DATABASE (this database is $dbName) and FREEITSM_TEST_URL to the install that uses it.\n";
    echo "\n$pass passed, $fail failed\n";
    exit($fail ? 1 : 0);
}

$admin = (int)$conn->query("SELECT id FROM analysts WHERE is_active = 1 AND is_admin = 1 AND can_access_all_tenants = 1 ORDER BY id LIMIT 1")->fetchColumn();
if (!$admin) { echo "SKIPPED: no active administrator with access to every company.\n"; exit(0); }

$tag = 'zzwc' . bin2hex(random_bytes(3));
$keyId = 0; $changeId = 0; $ticketId = 0;
$raw = 'zzk_' . bin2hex(random_bytes(20));
$call = function (string $method, string $path, ?array $body = null) use ($base, &$raw): array {
    $ch = curl_init("$base/api/v1$path");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => array_merge(["Authorization: Bearer $raw"], $body !== null ? ['Content-Type: application/json'] : [])]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    $out = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    return [$code, json_decode((string)$out, true) ?: ['raw' => $out]];
};
$col = function (string $table, string $column, int $id) use ($conn) {
    $s = $conn->prepare("SELECT `$column` FROM `$table` WHERE id = ?"); $s->execute([$id]); return $s->fetchColumn();
};
$err = fn(array $r): string => json_encode($r['error'] ?? $r);

// Never fire workflows: the writes below would run this install's change.* and
// ticket.* workflows against the ZZ records. Paused by id, switched back on in finally.
$paused = [];
try {
    $paused = array_map('intval', $conn->query("SELECT id FROM workflows WHERE is_active = 1 AND (trigger_event LIKE 'change.%' OR trigger_event LIKE 'ticket.%')")->fetchAll(PDO::FETCH_COLUMN));
} catch (PDOException $e) { /* no workflows table: nothing to fire */ }
if ($paused) $conn->exec("UPDATE workflows SET is_active = 0 WHERE id IN (" . implode(',', $paused) . ")");

try {
    $conn->prepare("INSERT INTO api_keys (name, key_prefix, key_hash, analyst_id, permissions, company_ids, active) VALUES (?, ?, ?, ?, ?, NULL, 1)")
         ->execute(["ZZ wall-clock $tag", substr($raw, 0, 12), hash('sha256', $raw), $admin,
                    json_encode(['changes' => ['read', 'create', 'update', 'delete'], 'tickets' => ['read', 'update']])]);
    $keyId = (int)$conn->lastInsertId();

    echo "\nPart 2 - changes\n";
    [$c, $r] = $call('POST', '/changes', ['title' => "ZZ change $tag", 'work_start_at' => '2026-11-16T20:46:00-05:00', 'work_end_at' => '2026-11-16T22:10:00-05:00']);
    if ($c === 201 && isset($r['data']['id'])) { $changeId = (int)$r['data']['id']; }
    ok('POST with an offset is refused (422), not shifted to UTC', $c === 422, "$c " . $err($r));

    if (!$changeId) {
        [$c, $r] = $call('POST', '/changes', ['title' => "ZZ change $tag", 'work_start_at' => '2026-11-16T20:46:00', 'work_end_at' => '2026-11-16T22:10:00']);
        $changeId = (int)($r['data']['id'] ?? 0);
        ok('  control: a local time is accepted (201)', $c === 201 && $changeId > 0, "$c " . $err($r));
    }
    ok('...stored exactly as sent', $col('changes', 'work_start_datetime', $changeId) === '2026-11-16 20:46:00', (string)$col('changes', 'work_start_datetime', $changeId));
    [$c, $r] = $call('GET', "/changes/$changeId");
    ok('...and returned without a Z', ($r['data']['schedule']['work_start_at'] ?? '') === '2026-11-16T20:46:00'
        && ($r['data']['schedule']['work_end_at'] ?? '') === '2026-11-16T22:10:00', json_encode($r['data']['schedule'] ?? $r));

    [$c, $r] = $call('PATCH', "/changes/$changeId", ['outage_start_at' => '2026-11-17T01:46:00Z']);
    ok('PATCH with Z is refused (422)', $c === 422, "$c " . $err($r));
    [$c, $r] = $call('PATCH', "/changes/$changeId", ['outage_start_at' => '2026-11-16 21:00', 'pir_actual_start_at' => '2026-11-16T20:50:00']);
    ok('  control: PATCH with local times is accepted', $c === 200, "$c " . $err($r));
    ok('...outage and PIR times stored as sent', $col('changes', 'outage_start_datetime', $changeId) === '2026-11-16 21:00:00'
        && $col('changes', 'pir_actual_start', $changeId) === '2026-11-16 20:50:00');
    [$c, $r] = $call('GET', "/changes/$changeId");
    ok('...and the PIR time comes back without a Z', ($r['data']['pir']['actual_start_at'] ?? '') === '2026-11-16T20:50:00', json_encode($r['data']['pir'] ?? null));

    [$c, $r] = $call('GET', '/changes?work_start_from=2026-11-16T20:00:00&work_start_to=2026-11-16T21:00:00&per_page=100');
    $ids = array_map('intval', array_column($r['data'] ?? [], 'id'));
    ok('the work_start filters compare local times', $c === 200 && in_array($changeId, $ids, true), "$c " . json_encode($ids));
    [$c, $r] = $call('GET', '/changes?work_start_from=2026-11-16T20:00:00Z');
    ok('...and refuse a Z (400)', $c === 400, "$c " . $err($r));

    echo "\nPart 2 - tickets\n";
    $conn->prepare("INSERT INTO tickets (ticket_number, subject, created_datetime) VALUES (?, ?, UTC_TIMESTAMP())")
         ->execute(['ZZ-' . strtoupper(bin2hex(random_bytes(4))), "ZZ ticket $tag"]);
    $ticketId = (int)$conn->lastInsertId();
    [$c, $r] = $call('PATCH', "/tickets/$ticketId", ['work_start_at' => '2026-11-17T01:46:00Z']);
    ok('PATCH work_start_at with Z is refused (422)', $c === 422, "$c " . $err($r));
    ok('...and nothing was stored', $col('tickets', 'work_start_datetime', $ticketId) === null, (string)$col('tickets', 'work_start_datetime', $ticketId));
    [$c, $r] = $call('PATCH', "/tickets/$ticketId", ['work_start_at' => '2026-11-16T20:46:00', 'work_end_at' => '2026-11-16T21:46:00']);
    ok('  control: a local time is accepted', $c === 200, "$c " . $err($r));
    ok('...stored as sent', $col('tickets', 'work_start_datetime', $ticketId) === '2026-11-16 20:46:00', (string)$col('tickets', 'work_start_datetime', $ticketId));
    [$c, $r] = $call('GET', "/tickets/$ticketId");
    ok('...and returned without a Z', ($r['data']['work_start_at'] ?? '') === '2026-11-16T20:46:00'
        && ($r['data']['work_end_at'] ?? '') === '2026-11-16T21:46:00', json_encode([$r['data']['work_start_at'] ?? null, $r['data']['work_end_at'] ?? null]));
    ok("...while created_at is still a UTC instant with a Z", str_ends_with((string)($r['data']['created_at'] ?? ''), 'Z'), (string)($r['data']['created_at'] ?? ''));
} finally {
    if ($changeId) {
        [$c] = $call('DELETE', "/changes/$changeId");
        if ($c >= 300) $conn->prepare("DELETE FROM changes WHERE id = ?")->execute([$changeId]);
    }
    if ($ticketId) {
        foreach (['ticket_audit', 'ticket_watchers'] as $t) {
            try { $conn->prepare("DELETE FROM `$t` WHERE ticket_id = ?")->execute([$ticketId]); } catch (PDOException $e) { /* table not on this install */ }
        }
        $conn->prepare("DELETE FROM tickets WHERE id = ?")->execute([$ticketId]);
    }
    if ($keyId) $conn->prepare("DELETE FROM api_keys WHERE id = ?")->execute([$keyId]);
    if ($paused) $conn->exec("UPDATE workflows SET is_active = 1 WHERE id IN (" . implode(',', $paused) . ")");
    $left = (int)$conn->query("SELECT (SELECT COUNT(*) FROM changes WHERE title = 'ZZ change $tag')
                                    + (SELECT COUNT(*) FROM tickets WHERE subject = 'ZZ ticket $tag')
                                    + (SELECT COUNT(*) FROM api_keys WHERE name = 'ZZ wall-clock $tag')")->fetchColumn();
    echo "\n";
    ok('everything the test wrote is gone', $left === 0, "$left rows left");
}

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
