<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * The Zabbix integration (System → Integrations → Zabbix), driven through the real
 * endpoint with events shaped exactly as its media type script sends them.
 *
 *   php tests/zabbix-integration.php
 *
 * Proves: the token is required (and a GET refused); a problem opens one incident
 * with the mapped priority, linked to the CMDB item named like the host; a repeat
 * adds to that ticket instead of opening another; a recovery adds a message and
 * resolves it; an unknown host still raises one, unlinked; what Zabbix sends is
 * stored as text, never markup; and every event is logged.
 *
 * tests/zabbix/run.php does the same against a REAL Zabbix in Docker - the proof
 * that its macros and its recovery pairing line up with this endpoint.
 *
 * Runs against the real database. Everything is named ZZ and removed at the end:
 * the tickets it raised (with their messages, history and CMDB links), the event
 * rows, a CMDB item and the requester. The Zabbix settings, the ticket counter
 * and the auto-acknowledge workflow (paused, so nobody is emailed) are put back.
 * Needs the app at http://localhost/<folder>/ (or FREEITSM_URL).
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/integrations/zabbix.php';

$pass = 0; $fail = 0;
function ok(string $what, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  PASS  {$what}\n"; }
    else       { $fail++; echo "  FAIL  {$what}" . ($detail !== '' ? "  <- {$detail}" : '') . "\n"; }
}

$conn = connectToDatabase();
if (!zabbixReady($conn)) { echo "Run Database Verification first (zabbix_events is missing).\n"; exit(1); }
$base = rtrim(getenv('FREEITSM_URL') ?: 'http://localhost/' . basename(dirname(__DIR__)) . '/', '/') . '/';
$endpoint = $base . 'api/external/zabbix/alert.php';
$tag = 'zz' . bin2hex(random_bytes(3));

function send(string $url, ?array $body, string $method = 'POST'): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_CUSTOMREQUEST => $method,
                            CURLOPT_HTTPHEADER => ['Content-Type: application/json']]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    $raw = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    return [$code, json_decode((string)$raw, true) ?: ['raw' => (string)$raw]];
}

// --- Snapshots of everything this touches that is not its own -------------
$settingKeys = ['zabbix_token', 'zabbix_requester_email', 'zabbix_requester_name', 'zabbix_tenant_id', 'zabbix_priority_map',
                'zabbix_resolve_status_id', 'zabbix_team_id', 'zabbix_origin_id', 'zabbix_link_cmdb'];
$savedSettings = [];
foreach ($settingKeys as $k) {
    $s = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ?"); $s->execute([$k]);
    $savedSettings[$k] = $s->fetchColumn();
}
$counters = $conn->query("SELECT counter_key, next_value FROM ticket_number_counters")->fetchAll(PDO::FETCH_KEY_PAIR);
$paused = $conn->query("SELECT id FROM workflows WHERE is_active = 1 AND trigger_event = 'ticket.created'")->fetchAll(PDO::FETCH_COLUMN);
$tickets = []; $problems = []; $cmdbId = null;

echo "\nZabbix integration - through the real endpoint\n" . str_repeat('=', 72) . "\n";
try {
    if ($paused) $conn->exec("UPDATE workflows SET is_active = 0 WHERE id IN (" . implode(',', array_map('intval', $paused)) . ")");

    $conn->exec("DELETE FROM system_settings WHERE setting_key = 'zabbix_token'");
    $pid = fn(string $n) => $problems[] = "$tag-$n";
    $event = fn(string $problem, array $extra = []) => array_merge([
        'problem_id' => $problem, 'event_value' => '1', 'severity' => '5', 'host' => "$tag-web01.example.com",
        'trigger' => 'Disk space is low on /var', 'message' => 'Free space 3%', 'url' => 'https://zabbix.example/tr_events.php?eventid=1',
    ], $extra);

    echo "\nThe token\n";
    [$c] = send($endpoint, $event($pid('nokey')) + ['token' => 'anything']);
    ok('with no token made yet, every call is refused (403) - the integration is off', $c === 403, "HTTP $c");
    $token = zabbixRotateToken($conn);
    ok('the token is stored encrypted', str_starts_with((string)zabbixSetting($conn, 'zabbix_token'), 'ENC:') || !webhookEncryptionAvailableSafe());
    [$c] = send($endpoint, $event($pid('bad')) + ['token' => 'wrong-' . $token]);
    ok('a wrong token is refused (403)', $c === 403, "HTTP $c");
    [$c] = send($endpoint . '?token=' . $token, null, 'GET');
    ok('a GET is refused (405) - events are POSTed', $c === 405, "HTTP $c");
    $n = (int)$conn->query("SELECT COUNT(*) FROM zabbix_events WHERE problem_id IN ('$tag-nokey', '$tag-bad')")->fetchColumn();
    ok('...and none of those was processed or logged', $n === 0);

    zabbixSaveSettings($conn, ['requester_email' => "$tag@monitoring.invalid", 'requester_name' => 'ZZ Zabbix', 'link_cmdb' => true,
                               'resolve_status_id' => zabbixDefaultResolveStatusId($conn), 'priority_map' => zabbixDefaultPriorityMap($conn)]);
    $map = zabbixSettings($conn)['priority_map'];
    $class = (int)$conn->query("SELECT MIN(id) FROM cmdb_classes")->fetchColumn();
    $conn->prepare("INSERT INTO cmdb_objects (class_id, name, created_datetime, updated_datetime) VALUES (?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())")
         ->execute([$class, "$tag-web01"]);
    $cmdbId = (int)$conn->lastInsertId();

    echo "\nA problem opens one incident\n";
    $p1 = $pid('disk');
    [$c, $r] = send($endpoint, $event($p1) + ['token' => $token]);
    $tid = (int)($r['ticket_id'] ?? 0);
    if ($tid) $tickets[] = $tid;
    ok('a Disaster problem raises an incident', $c === 200 && ($r['action'] ?? '') === 'created' && $tid > 0, "HTTP $c " . json_encode($r));
    $t = $conn->prepare("SELECT t.subject, t.priority_id, u.email FROM tickets t LEFT JOIN users u ON u.id = t.user_id WHERE t.id = ?");
    $t->execute([$tid]); $row = $t->fetch(PDO::FETCH_ASSOC) ?: [];
    ok('...titled with the host and the problem', ($row['subject'] ?? '') === "[Zabbix] $tag-web01.example.com: Disk space is low on /var", $row['subject'] ?? '');
    ok('...at the priority mapped for Disaster', (int)($row['priority_id'] ?? 0) === (int)$map[5], ($row['priority_id'] ?? '?') . ' vs ' . $map[5]);
    ok('...from the configured requester', ($row['email'] ?? '') === "$tag@monitoring.invalid", $row['email'] ?? '');
    $l = $conn->prepare("SELECT COUNT(*) FROM ticket_cmdb_objects WHERE ticket_id = ? AND cmdb_object_id = ?"); $l->execute([$tid, $cmdbId]);
    ok('...linked to the CMDB item named like the host (by its short name)', (int)$l->fetchColumn() === 1);

    echo "\nA repeat goes on the same ticket\n";
    [$c, $r] = send($endpoint, $event($p1, ['message' => 'Free space 2%']) + ['token' => $token]);
    ok('the same problem again updates that ticket', ($r['action'] ?? '') === 'updated' && (int)($r['ticket_id'] ?? 0) === $tid, json_encode($r));
    $m = $conn->prepare("SELECT COUNT(*) FROM emails WHERE ticket_id = ?"); $m->execute([$tid]);
    ok('...as a second message on its thread', (int)$m->fetchColumn() === 2);
    $n = $conn->prepare("SELECT COUNT(*) FROM tickets WHERE subject = ? AND deleted_datetime IS NULL"); $n->execute(["[Zabbix] $tag-web01.example.com: Disk space is low on /var"]);
    ok('...and no second ticket', (int)$n->fetchColumn() === 1);

    echo "\nA recovery resolves it\n";
    [$c, $r] = send($endpoint, $event($p1, ['event_value' => '0', 'message' => 'Free space 40%']) + ['token' => $token]);
    ok('the recovery is matched to the ticket', ($r['action'] ?? '') === 'resolved' && (int)($r['ticket_id'] ?? 0) === $tid, json_encode($r));
    $s = $conn->prepare("SELECT status_id FROM tickets WHERE id = ?"); $s->execute([$tid]);
    ok('...which moves to the chosen status', (int)$s->fetchColumn() === (int)zabbixDefaultResolveStatusId($conn));
    $m = $conn->prepare("SELECT body_content FROM emails WHERE ticket_id = ? ORDER BY id DESC LIMIT 1"); $m->execute([$tid]);
    ok('...with a RECOVERED message on the thread', str_starts_with((string)$m->fetchColumn(), 'RECOVERED'));
    [$c, $r] = send($endpoint, $event($pid('never'), ['event_value' => '0']) + ['token' => $token]);
    ok('a recovery for a problem with no ticket opens nothing', ($r['action'] ?? '') === 'recovery_unmatched' && empty($r['ticket_id']), json_encode($r));

    echo "\nThe edges\n";
    $p2 = $pid('nohost');
    [$c, $r] = send($endpoint, $event($p2, ['host' => "$tag-nowhere-99", 'severity' => 'Warning']) + ['token' => $token]);
    $tid2 = (int)($r['ticket_id'] ?? 0); if ($tid2) $tickets[] = $tid2;
    ok('an unknown host still raises an incident', ($r['action'] ?? '') === 'created' && $tid2 > 0, json_encode($r));
    $l = $conn->prepare("SELECT COUNT(*) FROM ticket_cmdb_objects WHERE ticket_id = ?"); $l->execute([$tid2]);
    ok('...with nothing linked', (int)$l->fetchColumn() === 0);
    $s = $conn->prepare("SELECT priority_id FROM tickets WHERE id = ?"); $s->execute([$tid2]);
    ok('...and a severity sent by NAME ("Warning") mapped like its number', (int)$s->fetchColumn() === (int)$map[2]);

    $p3 = $pid('xss');
    [$c, $r] = send($endpoint, $event($p3, ['trigger' => '<img src=x onerror=alert(1)>', 'message' => '<script>alert(2)</script>', 'url' => 'javascript:alert(3)']) + ['token' => $token]);
    $tid3 = (int)($r['ticket_id'] ?? 0); if ($tid3) $tickets[] = $tid3;
    $b = $conn->prepare("SELECT body_content FROM emails WHERE ticket_id = ?"); $b->execute([$tid3]); $body = (string)$b->fetchColumn();
    ok('markup from Zabbix is stored as text', $tid3 > 0 && !str_contains($body, '<img') && !str_contains($body, '<script') && str_contains($body, '&lt;img'), substr($body, 0, 120));
    ok('...and a non-web link is dropped, not shown', !str_contains($body, 'javascript:'));

    [$c, $r] = send($endpoint, ['token' => $token, 'problem_id' => $pid('bare')]);
    ok('an event with no host is refused (400)', $c === 400, "HTTP $c");

    $logged = (int)$conn->query("SELECT COUNT(*) FROM zabbix_events WHERE problem_id LIKE '$tag-%'")->fetchColumn();
    ok('every event processed is in the log the settings page shows (6)', $logged === 6, (string)$logged);

    echo "\nThe settings page's API\n";
    $dir = rtrim(ini_get('session.save_path') ?: 'c:/wamp64/tmp', '/\\');
    $csrf = bin2hex(random_bytes(32));
    $sess = function (int $analystId) use ($dir, $csrf): string {
        $sid = 'zzzbx' . bin2hex(random_bytes(5));
        file_put_contents("$dir/sess_$sid", 'analyst_id|i:' . $analystId . ';analyst_name|s:7:"ZZ test";csrf_token|s:64:"' . $csrf . '";');
        return $sid;
    };
    $api = function (string $sid, string $method, ?array $body, string $qs = '') use ($base, $csrf): array {
        $ch = curl_init($base . 'api/system/zabbix_settings.php' . $qs);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_COOKIE => 'PHPSESSID=' . $sid,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-CSRF-Token: ' . $csrf]]);
        if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        $raw = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        return [$code, json_decode((string)$raw, true) ?: []];
    };
    $admin = (int)$conn->query("SELECT id FROM analysts WHERE is_active = 1 AND is_admin = 1 ORDER BY id LIMIT 1")->fetchColumn();
    $plain = (int)$conn->query("SELECT id FROM analysts WHERE COALESCE(is_active, 1) = 1 AND COALESCE(is_admin, 0) = 0 ORDER BY id LIMIT 1")->fetchColumn();
    $sA = $sess($admin); $sP = $sess($plain);
    [$c, $d] = $api($sA, 'GET', null, '?action=get');
    ok('an administrator reads the settings, with the token and the address to give Zabbix',
       $c === 200 && !empty($d['success']) && ($d['token'] ?? '') === $token && str_contains((string)($d['endpoint'] ?? ''), 'api/external/zabbix/alert.php'), "HTTP $c");
    [$c, $d2] = $api($sP, 'GET', null, '?action=get');
    ok('a non-administrator is refused - and never sees the token', ($c === 403 || empty($d2['success'])) && !isset($d2['token']), "HTTP $c");
    [$c] = $api($sA, 'GET', null, '?action=rotate');
    ok('a rotate sent as a GET (a link) is refused, and the token is unchanged', $c === 405 && zabbixToken($conn) === $token, "HTTP $c");
    $set = $d['settings'];
    [$c, $d3] = $api($sA, 'POST', ['action' => 'save', 'requester_email' => "$tag@monitoring.invalid", 'requester_name' => 'ZZ renamed',
                                   'tenant_id' => $set['tenant_id'], 'priority_map' => $set['priority_map'], 'resolve_status_id' => 0,
                                   'team_id' => 0, 'origin_id' => 0, 'link_cmdb' => true]);
    ok('POSITIVE CONTROL: the page\'s save works, with the token', !empty($d3['success']) && zabbixSettings($conn)['requester_name'] === 'ZZ renamed'
       && zabbixSettings($conn)['resolve_status_id'] === null, json_encode($d3));
    [$c, $d4] = $api($sA, 'POST', ['action' => 'save', 'requester_email' => 'not an address']);
    ok('...and a bad value is refused with a sentence', empty($d4['success']) && str_contains((string)($d4['error'] ?? ''), 'email'), json_encode($d4));
    echo "\nTry it (the page's test button)\n";
    [$c, $t1] = $api($sA, 'POST', ['action' => 'test', 'step' => 'problem', 'trigger' => 'Test alert from FreeITSM', 'message' => 'ZZ test']);
    if (!empty($t1['ticket_id'])) $tickets[] = (int)$t1['ticket_id'];
    ok('a test alert raises an incident through the real handler', !empty($t1['success']) && ($t1['action'] ?? '') === 'created' && !empty($t1['ticket_id'])
       && preg_match('/^freeitsm-test-[0-9a-f]{8}$/', (string)($t1['problem_id'] ?? '')), json_encode($t1));
    $ts = $conn->prepare("SELECT subject FROM tickets WHERE id = ?"); $ts->execute([(int)($t1['ticket_id'] ?? 0)]);
    ok('...titled so nobody mistakes it for a real one', (string)$ts->fetchColumn() === '[Zabbix] freeitsm-test: Test alert from FreeITSM');
    [$c, $t2] = $api($sA, 'POST', ['action' => 'test', 'step' => 'recovery', 'problem_id' => $t1['problem_id'] ?? '']);
    ok('...and its recovery resolves it', !empty($t2['success']) && ($t2['action'] ?? '') === 'resolved' && (int)($t2['ticket_id'] ?? 0) === (int)($t1['ticket_id'] ?? -1), json_encode($t2));
    [$c, $t3] = $api($sA, 'POST', ['action' => 'test', 'step' => 'recovery', 'problem_id' => $p1]);
    ok('the button cannot recover a REAL problem - only a test one it made', empty($t3['success']) && str_contains((string)($t3['error'] ?? ''), 'not a test'), json_encode($t3));
    [$c] = $api($sA, 'GET', null, '?action=test');
    ok('a test sent as a GET is refused (405)', $c === 405, "HTTP $c");
    $conn->exec("DELETE FROM zabbix_events WHERE problem_id = " . $conn->quote((string)($t1['problem_id'] ?? '-')));
    @unlink("$dir/sess_$sA"); @unlink("$dir/sess_$sP");
} finally {
    foreach ($tickets as $id) {
        $conn->prepare("DELETE FROM ticket_cmdb_objects WHERE ticket_id = ?")->execute([$id]);
        $conn->prepare("DELETE FROM emails WHERE ticket_id = ?")->execute([$id]);
        $conn->prepare("DELETE FROM ticket_audit WHERE ticket_id = ?")->execute([$id]);
        $conn->prepare("DELETE FROM search_documents WHERE ticket_id = ?")->execute([$id]);
        $conn->prepare("DELETE FROM tickets WHERE id = ? AND subject LIKE '[Zabbix] %'")->execute([$id]);
    }
    $conn->prepare("DELETE FROM zabbix_events WHERE problem_id LIKE ?")->execute(["$tag-%"]);
    if ($cmdbId) $conn->prepare("DELETE FROM cmdb_objects WHERE id = ? AND name LIKE 'zz%'")->execute([$cmdbId]);
    $conn->prepare("DELETE FROM users WHERE email = ?")->execute(["$tag@monitoring.invalid"]);
    foreach ($savedSettings as $k => $v) {
        $conn->prepare("DELETE FROM system_settings WHERE setting_key = ?")->execute([$k]);
        if ($v !== false) $conn->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?)")->execute([$k, $v]);
    }
    foreach ($counters as $k => $v) $conn->prepare("UPDATE ticket_number_counters SET next_value = ? WHERE counter_key = ?")->execute([$v, $k]);
    if ($paused) $conn->exec("UPDATE workflows SET is_active = 1 WHERE id IN (" . implode(',', array_map('intval', $paused)) . ")");
    $back = $paused ? (int)$conn->query("SELECT COUNT(*) FROM workflows WHERE is_active = 1 AND id IN (" . implode(',', array_map('intval', $paused)) . ")")->fetchColumn() : 0;
    $left = (int)$conn->query("SELECT (SELECT COUNT(*) FROM zabbix_events WHERE problem_id LIKE '$tag-%') + (SELECT COUNT(*) FROM tickets WHERE subject LIKE '[Zabbix] $tag-%')
                                    + (SELECT COUNT(*) FROM users WHERE email = '$tag@monitoring.invalid') + (SELECT COUNT(*) FROM cmdb_objects WHERE name = '$tag-web01')")->fetchColumn();
    echo "\nCleanup: " . ($left === 0 ? 'everything this run made is gone' : "$left ROWS LEFT - tag $tag")
       . ($paused ? '; ' . $back . ' of ' . count($paused) . ' paused workflow(s) back on' : '') . "\n";
}

/** True when the install can encrypt (no key = values stored readable, by design). */
function webhookEncryptionAvailableSafe(): bool {
    try { getEncryptionKey(); return true; } catch (Throwable $e) { return false; }
}

echo "\n" . str_repeat('=', 72) . "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
