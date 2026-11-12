<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * A project's documents in REST v1, and the API's threat protection (GH #287).
 *
 * Over HTTP with real API keys, against the install FREEITSM_TEST_URL serves:
 *   - upload a file, add a link, list, retry with client_reference (200, and 409 for
 *     different content), attach an existing document, remove;
 *   - the content needs documents.download as well as projects.read; a document not
 *     on that project, a project the key's analyst cannot see (members-only) and an
 *     unknown project are all 404;
 *   - every API download is logged with the key and the bytes;
 *   - the download guard: alert, refuse at the limit, or switch the key off;
 *   - allowed addresses and allowed hours (refuse, and alert-only);
 *   - a new address raises an alert to administrators;
 *   - a key refused again and again is switched off (probing / enumeration);
 *   - an address sending bad keys is blocked.
 *
 * Writes a ZZPD analyst, keys, projects and documents; restores every setting and
 * removes everything in `finally` (including the bell rows it raised for administrators).
 *
 * Run: FREEITSM_TEST_DATABASE=<throwaway db> FREEITSM_TEST_URL=http://localhost/<that install> php tests/project-documents-api.php
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
require_once 'includes/services/projects.php';
require_once 'includes/services/documents.php';
require_once 'includes/api_security.php';
$conn = connectToDatabase();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
if ($conn->query('SELECT DATABASE()')->fetchColumn() !== $expected) { echo "(skipped: config.php does not use $expected)\n"; exit(0); }
if (!apiSecurityReady($conn) || !DocumentsService::referenceReady($conn)) { echo "(skipped: run Database Verification)\n"; exit(1); }

$started = $conn->query("SELECT UTC_TIMESTAMP()")->fetchColumn();
$made = ['analysts' => [], 'keys' => [], 'projects' => [], 'docs' => []];
$settingKeys = array_keys(API_SECURITY_DEFAULTS);
$oldSettings = [];
foreach ($settingKeys as $k) { $s = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ?"); $s->execute([$k]); $oldSettings[$k] = $s->fetchColumn(); }
$set = function (string $k, ?string $v) use ($conn) {
    if ($v === null || $v === false) $conn->prepare("DELETE FROM system_settings WHERE setting_key = ?")->execute([$k]);
    else $conn->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")->execute([$k, $v]);
};
$tmp = tempnam(sys_get_temp_dir(), 'zzpd');

// One API call. $file = path for a multipart upload.
$call = function (string $key, string $method, string $path, ?array $json = null, ?string $file = null, array $form = [], string $query = '') use ($base) {
    $ch = curl_init("$base/api/v1/index.php?path=" . rawurlencode($path) . ($query !== '' ? "&$query" : ''));
    $h = ['Authorization: Bearer ' . $key];
    $o = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_CUSTOMREQUEST => $method];
    if ($file !== null) { $o[CURLOPT_POSTFIELDS] = $form + ['file' => new CURLFile($file, 'text/plain', 'zzpd-plan.txt')]; }
    elseif ($json !== null) { $h[] = 'Content-Type: application/json'; $o[CURLOPT_POSTFIELDS] = json_encode($json); }
    $o[CURLOPT_HTTPHEADER] = $h;
    curl_setopt_array($ch, $o);
    $body = (string)curl_exec($ch);
    return [(int)curl_getinfo($ch, CURLINFO_HTTP_CODE), json_decode($body, true), $body];
};
$mkKey = function (int $analystId, array $perms, string $name) use ($conn, &$made) {
    $key = 'fitsm_' . bin2hex(random_bytes(24));
    $conn->prepare('INSERT INTO api_keys (name, key_prefix, key_hash, analyst_id, permissions, company_ids, active, created_datetime) VALUES (?, ?, ?, ?, ?, NULL, 1, UTC_TIMESTAMP())')
         ->execute([$name, substr($key, 0, 14), hash('sha256', $key), $analystId, json_encode($perms)]);
    $made['keys'][] = (int)$conn->lastInsertId();
    return [$key, (int)$conn->lastInsertId()];
};

try {
    // ── Set up ──────────────────────────────────────────────────────────────
    foreach ($settingKeys as $k) $set($k, null);   // the defaults
    $conn->exec("INSERT INTO analysts (username, password_hash, full_name, email, is_active, is_admin, can_access_all_modules, can_access_all_tenants) VALUES ('zzpdkey', '!', 'ZZPD integration', 'zzpdkey@example.invalid', 1, 0, 1, 1)");
    $an = $made['analysts'][] = (int)$conn->lastInsertId();
    $admin = (int)$conn->query("SELECT id FROM analysts WHERE is_admin = 1 AND is_active = 1 AND username NOT LIKE 'zz%' ORDER BY id LIMIT 1")->fetchColumn();
    $ctx = new ActorContext($an, null, 'ui');
    $actx = new ActorContext($admin, null, 'ui');
    $p1 = $made['projects'][] = ProjectsService::createProject($conn, $ctx, ['name' => 'ZZPD Network refresh', 'methodology' => 'staged'], null);
    $p2 = $made['projects'][] = ProjectsService::createProject($conn, $ctx, ['name' => 'ZZPD Other project', 'methodology' => 'staged'], null);
    $p3 = $made['projects'][] = ProjectsService::createProject($conn, $actx, ['name' => 'ZZPD HR restructure', 'methodology' => 'staged', 'visibility' => 'members', 'owner_analyst_id' => $admin], null);
    [$keyA, $idA] = $mkKey($an, ['projects' => ['read', 'update'], 'documents' => ['download']], 'ZZPD full');
    [$keyB, $idB] = $mkKey($an, ['projects' => ['read', 'update']], 'ZZPD no download');
    file_put_contents($tmp, "ZZPD design document\n" . str_repeat('x', 200));

    echo "Filing and reading documents\n";
    [$c, $j] = $call($keyA, 'POST', "/projects/$p1/documents", null, $tmp, ['title' => 'ZZPD Design', 'client_reference' => 'zzpd-run-1']);
    $docId = (int)($j['data']['id'] ?? 0); $made['docs'][] = $docId;
    check('upload a file: 201, with its sha256 and a content URL', $c === 201 && ($j['data']['sha256'] ?? '') === hash_file('sha256', $tmp) && str_ends_with((string)($j['data']['content_url'] ?? ''), "/documents/$docId/content"), "$c " . json_encode($j));
    [$c, $j] = $call($keyA, 'POST', "/projects/$p1/documents", null, $tmp, ['title' => 'ZZPD Design', 'client_reference' => 'zzpd-run-1']);
    check('the same upload again with its client_reference: 200, the same document', $c === 200 && (int)($j['data']['id'] ?? 0) === $docId, "$c " . json_encode($j));
    $tmp2 = $tmp . '.b'; file_put_contents($tmp2, 'something else');
    [$c, $j] = $call($keyA, 'POST', "/projects/$p1/documents", null, $tmp2, ['client_reference' => 'zzpd-run-1']);
    check('a different file with that reference: 409 reference_conflict', $c === 409 && ($j['error']['code'] ?? '') === 'reference_conflict', "$c " . json_encode($j));
    @unlink($tmp2);
    [$c, $j] = $call($keyA, 'POST', "/projects/$p1/documents", ['external_url' => 'https://example.com/zzpd-plan', 'title' => 'ZZPD Plan link']);
    $linkId = (int)($j['data']['id'] ?? 0); $made['docs'][] = $linkId;
    check('add a link: 201, kind link', $c === 201 && ($j['data']['kind'] ?? '') === 'link', "$c " . json_encode($j));
    [$c, $j] = $call($keyA, 'POST', "/projects/$p1/documents", ['external_url' => 'javascript:alert(1)']);
    check('a javascript: link is refused (422)', $c === 422, (string)$c);
    [$c, $j] = $call($keyA, 'GET', "/projects/$p1/documents");
    $ids = array_column($j['data'] ?? [], 'id');
    check('list: both, metadata only, with the total', $c === 200 && in_array($docId, $ids) && in_array($linkId, $ids) && ($j['meta']['total'] ?? 0) === 2 && !isset($j['data'][0]['storage_key']), json_encode($j));
    [$c, $j] = $call($keyA, 'GET', "/projects/$p1/documents", null, null, [], 'client_reference=zzpd-run-1');
    check('list ?client_reference= finds the upload', $c === 200 && count($j['data'] ?? []) === 1 && (int)$j['data'][0]['id'] === $docId);

    echo "Downloading\n";
    [$c, $j] = $call($keyB, 'GET', "/projects/$p1/documents/$docId/content");
    check('a key without documents.download is refused (403)', $c === 403, "$c " . json_encode($j));
    [$c, , $raw] = $call($keyA, 'GET', "/projects/$p1/documents/$docId/content");
    check('with it, the exact bytes', $c === 200 && $raw === file_get_contents($tmp), (string)$c);
    $log = $conn->prepare("SELECT api_key_id, bytes FROM document_access_log WHERE document_id = ? AND action = 'download' ORDER BY id DESC LIMIT 1");
    $log->execute([$docId]); $l = $log->fetch(PDO::FETCH_ASSOC);
    check('the download is logged with the key and the bytes', $l && (int)$l['api_key_id'] === $idA && (int)$l['bytes'] === filesize($tmp), json_encode($l));
    [$c, $j] = $call($keyA, 'GET', "/projects/$p1/documents/$linkId/content");
    check('a link returns its URL as JSON, never a redirect', $c === 200 && ($j['data']['external_url'] ?? '') === 'https://example.com/zzpd-plan', "$c " . json_encode($j));

    echo "What the key cannot reach\n";
    $r = DocumentsService::add($conn, $an, null, 'project', $p2, ['external_url' => 'https://example.com/zzpd-other', 'title' => 'ZZPD Other']);
    $otherId = $made['docs'][] = $r['id'];
    [$c] = $call($keyA, 'GET', "/projects/$p1/documents/$otherId/content");
    check('another project\'s document through this project: 404', $c === 404, (string)$c);
    [$c] = $call($keyA, 'GET', "/projects/$p3/documents");
    check('a members-only project the key\'s analyst is not on: 404', $c === 404, (string)$c);
    [$c] = $call($keyA, 'GET', "/projects/99999999/documents");
    check('an unknown project: 404', $c === 404, (string)$c);

    echo "Attaching and removing\n";
    [$c, $j] = $call($keyA, 'POST', "/projects/$p1/documents/$otherId/link");
    check('attach a document the analyst can already see: 201', $c === 201 && (int)($j['data']['id'] ?? 0) === $otherId, "$c " . json_encode($j));
    [$c, $j] = $call($keyA, 'DELETE', "/projects/$p1/documents/$otherId");
    check('remove it here: it lives on (still on the other project)', $c === 200 && ($j['data']['document_deleted'] ?? true) === false, "$c " . json_encode($j));
    [$c, $j] = $call($keyA, 'DELETE', "/projects/$p1/documents/$linkId");
    check('remove its only link: the document is gone', $c === 200 && ($j['data']['document_deleted'] ?? false) === true, "$c " . json_encode($j));

    echo "The download guard\n";
    $conn->exec("DELETE FROM api_key_events WHERE api_key_id = $idA");
    $conn->prepare("UPDATE api_keys SET download_alert_files = 2, download_limit_files = 3 WHERE id = ?")->execute([$idA]);   // 1 already today
    [$c] = $call($keyA, 'GET', "/projects/$p1/documents/$docId/content");   // 2nd: alert
    $ev = fn(string $e) => (int)$conn->query("SELECT COUNT(*) FROM api_key_events WHERE api_key_id = $idA AND event = " . $conn->quote($e))->fetchColumn();
    check('at the alert level: allowed, and administrators told once', $c === 200 && $ev('download_alert_alerted') === 1, (string)$c);
    [$c] = $call($keyA, 'GET', "/projects/$p1/documents/$docId/content");   // 3rd: at the limit, still allowed
    [$c, $j] = $call($keyA, 'GET', "/projects/$p1/documents/$docId/content");   // 4th: over
    check('over the limit: 429 download_limit, logged as refused', $c === 429 && ($j['error']['code'] ?? '') === 'download_limit' && $ev('download_limit') === 1, "$c " . json_encode($j));
    $set('api_download_limit_action', 'suspend');
    [$c, $j] = $call($keyA, 'GET', "/projects/$p1/documents/$docId/content");
    $k = $conn->query("SELECT active, suspended_reason FROM api_keys WHERE id = $idA")->fetch(PDO::FETCH_ASSOC);
    check('with "switch the key off": the key is off, and says why', $c === 403 && (int)$k['active'] === 0 && str_contains((string)$k['suspended_reason'], 'download'), "$c " . json_encode($k));
    [$c, $j] = $call($keyA, 'GET', "/projects/$p1/documents");
    check('...and every later request says it was switched off', $c === 403 && str_contains((string)($j['error']['message'] ?? ''), 'switched off'), "$c " . json_encode($j));
    $set('api_download_limit_action', null);
    $conn->exec("UPDATE api_keys SET active = 1, suspended_reason = NULL, download_alert_files = NULL, download_limit_files = NULL WHERE id = $idA");

    echo "Where and when\n";
    $conn->prepare("UPDATE api_keys SET allowed_ips = '203.0.113.7' WHERE id = ?")->execute([$idA]);
    [$c, $j] = $call($keyA, 'GET', "/projects/$p1/documents");
    check('from an address not on the list: 403 ip_not_allowed', $c === 403 && ($j['error']['code'] ?? '') === 'ip_not_allowed', "$c " . json_encode($j));
    $conn->prepare("UPDATE api_keys SET allowed_ips = ? WHERE id = ?")->execute(["127.0.0.0/8\n::1", $idA]);
    [$c] = $call($keyA, 'GET', "/projects/$p1/documents");
    check('from an address in an allowed range: through', $c === 200, (string)$c);
    $conn->exec("UPDATE api_keys SET allowed_ips = NULL WHERE id = $idA");
    $later = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('+3 hours');
    $window = json_encode(['days' => [1, 2, 3, 4, 5, 6, 7], 'from' => $later->format('H:00'), 'to' => $later->modify('+1 hour')->format('H:00'), 'tz' => 'UTC', 'mode' => 'refuse']);
    $conn->prepare("UPDATE api_keys SET allowed_hours = ? WHERE id = ?")->execute([$window, $idA]);
    [$c, $j] = $call($keyA, 'GET', "/projects/$p1/documents");
    check('outside its allowed hours: 403 outside_allowed_hours', $c === 403 && ($j['error']['code'] ?? '') === 'outside_allowed_hours', "$c " . json_encode($j));
    $conn->prepare("UPDATE api_keys SET allowed_hours = ? WHERE id = ?")->execute([str_replace('"refuse"', '"alert"', $window), $idA]);
    $before = $ev('outside_hours');
    [$c] = $call($keyA, 'GET', "/projects/$p1/documents");
    check('"alert only": allowed, and logged', $c === 200 && $ev('outside_hours') === $before + 1, (string)$c);
    $conn->exec("UPDATE api_keys SET allowed_hours = NULL WHERE id = $idA");
    check('an overnight window (22:00-06:00) counts from the day it starts',
        apiHoursAllowed(json_encode(['days' => [1], 'from' => '22:00', 'to' => '06:00', 'tz' => 'UTC']), new DateTimeImmutable('2026-11-10 03:00', new DateTimeZone('UTC')))   // Tue 03:00, after Monday
        && !apiHoursAllowed(json_encode(['days' => [1], 'from' => '22:00', 'to' => '06:00', 'tz' => 'UTC']), new DateTimeImmutable('2026-11-11 03:00', new DateTimeZone('UTC'))));

    echo "Watching for trouble\n";
    $conn->prepare("UPDATE api_keys SET last_used_ip = '198.51.100.9' WHERE id = ?")->execute([$idA]);
    [$c] = $call($keyA, 'GET', "/projects/$p1/documents");
    $bell = (int)$conn->query("SELECT COUNT(*) FROM notifications WHERE event_type = 'api.security' AND entity_id = $idA AND created_datetime >= " . $conn->quote($started))->fetchColumn();
    check('a key used from a new address: allowed, logged, administrators get a bell', $c === 200 && $ev('new_ip') === 1 && $bell >= 1, "$c new_ip=" . $ev('new_ip') . " bell=$bell");
    $set('api_refusal_alert', '3'); $set('api_refusal_limit', '5');
    for ($i = 0; $i < 5; $i++) $call($keyB, 'GET', '/projects/' . (99999990 + $i) . '/documents');
    $kb = $conn->query("SELECT active, suspended_reason FROM api_keys WHERE id = $idB")->fetch(PDO::FETCH_ASSOC);
    check('a key refused again and again (trying ids) is switched off', (int)$kb['active'] === 0 && str_contains((string)$kb['suspended_reason'], 'refused'), json_encode($kb));
    $set('api_refusal_alert', null); $set('api_refusal_limit', null);

    // Last: it blocks this machine's address for a while - the rows are removed in finally.
    $set('api_failed_auth_limit', '3'); $set('api_failed_auth_minutes', '15');
    for ($i = 0; $i < 3; $i++) $call('fitsm_zzpd_not_a_key_' . $i, 'GET', '/projects');
    [$c, $j] = $call($keyA, 'GET', "/projects/$p1/documents");
    check('an address that keeps sending bad keys is blocked, even with a good one', $c === 429 && ($j['error']['code'] ?? '') === 'address_blocked', "$c " . json_encode($j));
} catch (Throwable $e) {
    check('no exception', false, $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    $conn->exec("DELETE FROM api_key_events WHERE event IN ('auth_failed','address_blocked') AND created_datetime >= " . $conn->quote($started));
    foreach ($settingKeys as $k) $set($k, $oldSettings[$k]);
    $conn->exec("DELETE FROM notifications WHERE event_type = 'api.security' AND created_datetime >= " . $conn->quote($started));
    foreach ($made['docs'] as $d) {
        $st = $conn->prepare("SELECT storage_key FROM documents WHERE id = ?"); $st->execute([$d]);
        if ($sk = $st->fetchColumn()) @unlink(documentStoragePath((string)$sk));
        $conn->exec("DELETE FROM document_access_log WHERE document_id = " . (int)$d);
        $conn->exec("DELETE FROM document_links WHERE document_id = " . (int)$d);
        try { require_once 'includes/search/documents_index.php'; searchUnindexDocument($conn, (int)$d); } catch (Throwable) {}
        $conn->exec("DELETE FROM documents WHERE id = " . (int)$d);
    }
    foreach ($made['keys'] as $k) { $conn->exec("DELETE FROM api_key_events WHERE api_key_id = $k"); $conn->exec("DELETE FROM api_keys WHERE id = $k"); }
    foreach ($made['projects'] as $p) { try { ProjectsService::deleteProject($conn, new ActorContext($admin ?? 1, null, 'ui'), (int)$p); } catch (Throwable $e) { echo "  cleanup project: " . $e->getMessage() . "\n"; } }
    foreach ($made['analysts'] as $a) { $conn->exec("DELETE FROM user_preferences WHERE analyst_id = $a"); $conn->exec("DELETE FROM analysts WHERE id = $a"); }
    @unlink($tmp);
    $left = (int)$conn->query("SELECT (SELECT COUNT(*) FROM projects WHERE name LIKE 'ZZPD%') + (SELECT COUNT(*) FROM api_keys WHERE name LIKE 'ZZPD%') + (SELECT COUNT(*) FROM documents WHERE title LIKE 'ZZPD%') + (SELECT COUNT(*) FROM analysts WHERE username = 'zzpdkey')")->fetchColumn();
    check('cleanup: nothing named ZZPD is left', $left === 0, "$left left");
}
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
