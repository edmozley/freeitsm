<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * Domains - internal scope (GH #203, slice 1): an internal domain never leaves
 * the network.
 *
 *   php tests/domains-internal-scope.php
 *   FREEITSM_TEST_DATABASE=<a throwaway database> php tests/domains-internal-scope.php
 *
 * Every route Domains has out of the server is traced: HTTP (DNS-over-HTTPS,
 * RDAP, IANA, crt.sh), WHOIS on port 43, TLS connections, and this server's own
 * resolver. An internal domain may use the last one and nothing else - not on
 * add, not on Check now, not in the scheduled run, not through the watches.
 *
 * PART 1 needs no database: the gate, the DNS-only check and the health states.
 * PART 2 writes domains and settings, so it runs only when
 * FREEITSM_TEST_DATABASE names the database config.php points at - never the
 * real one by accident. Its rows are named zz-... and removed at the end, and
 * every setting it changes is put back.
 *
 * Controls: the public check run IS seen making a TLS connection, and the
 * scheduled run IS seen asking a registry for a public domain - so an empty
 * trace for the internal one means "nothing went out", not "the trace is blind".
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/domains/checks.php';
require_once __DIR__ . '/../includes/domains/settings.php';

$pass = 0; $fail = 0; $skip = 0;
function ok(string $what, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  PASS  {$what}\n"; }
    else       { $fail++; echo "  FAIL  {$what}" . ($detail !== '' ? "  <- {$detail}" : '') . "\n"; }
}
function skip(string $what, string $why): void { global $skip; $skip++; echo "  SKIP  {$what} ({$why})\n"; }

/** Everything in the trace that is NOT this server's own resolver. */
$outside = fn(array $t) => array_values(array_filter($t, fn($u) => !str_starts_with($u, 'dns-system://')));
$trace   = function (callable $fn) { $GLOBALS['DOMAIN_HTTP_TRACE'] = []; $fn(); $t = $GLOBALS['DOMAIN_HTTP_TRACE']; unset($GLOBALS['DOMAIN_HTTP_TRACE']); return $t; };

$row = fn(string $name, string $scope) => ['domain_name' => $name, 'scope' => $scope, 'purpose' => 'primary', 'registry_statuses' => '',
    'expiry_date' => null, 'transfer_lock' => null, 'registry_lock' => 0, 'renewal_mode' => 'auto', 'nameservers' => '',
    'dnssec' => null, 'dkim_selectors' => '', 'ssl_hosts' => ''];

echo "\nDomains - internal scope (GH #203)\n" . str_repeat('=', 72) . "\n";

// ------------------------------------------------------------------ PART 1
echo "\nPART 1 - the gate, the DNS-only check, health\n";

ok('a public domain may leave the network', domainMayLeaveNetwork($row('example.com', 'public')));
ok('an internal domain may not', !domainMayLeaveNetwork($row('intranet.corp', 'internal')));
ok('a row from before the column existed counts as public', domainMayLeaveNetwork(['domain_name' => 'example.com']));

$threw = false;
try { domainRunChecks($row('intranet.corp', 'internal'), ['domain_dns_resolver' => 'google']); }
catch (LogicException) { $threw = true; }
ok('the PUBLIC check run refuses an internal domain outright, whatever the resolver setting', $threw);

// A public name every resolver knows (localhost is no good: on Windows it is a
// hosts-file name with no DNS record), and one that cannot exist.
$t = $trace(fn() => $GLOBALS['res'] = domainRunInternalChecks($row('example.com', 'internal')));
$res = $GLOBALS['res'];
$keys = array_column($res['findings'], 'key');
ok('internal check: a name that resolves is a pass', in_array('int_resolves', $keys, true), implode(',', $keys));
ok('...the run completed and found no problem', $res['completed'] && !$res['problem']);
ok('...and nothing but this server\'s resolver was asked', !$outside($t) && $t !== [], json_encode($t));

$missing = 'zz-int-' . bin2hex(random_bytes(4)) . '.invalid';
$t = $trace(fn() => $GLOBALS['res'] = domainRunInternalChecks($row($missing, 'internal') + ['ssl_hosts' => '']));
$res = $GLOBALS['res'];
$keys = array_column($res['findings'], 'key');
ok('a name with no address is a confirmed problem', in_array('int_no_address', $keys, true) && $res['problem'], implode(',', $keys));
ok('...which is a COMPLETED check - a fresh observation, not "unknown"', $res['completed']);
ok('...no www is assumed (only the name itself was asked)', !preg_grep('#www\.#', $t), json_encode($t));
ok('...and no TLS, WHOIS or HTTP call was made', !$outside($t), json_encode($outside($t)));

$t = $trace(fn() => domainRunChecks($row('example.com', 'public'), ['domain_dns_resolver' => 'system', 'domain_ssl_warn_days' => 21]));
if (!preg_grep('#^dns-system://example\.com/A$#', $t)) {
    skip('CONTROL: the public run is seen connecting', "this machine's resolver did not answer");
} else {
    ok('CONTROL: the public run of the same code IS seen making TLS connections - the trace is not blind',
       (bool)preg_grep('#^tls://#', $t), json_encode($t));
}

// Health, worked out at read time. Interval 60 minutes: stale after 120.
$now  = strtotime('2026-10-29 12:00:00 UTC');
$at   = fn(int $minsAgo) => gmdate('Y-m-d H:i:s', $now - $minsAgo * 60);
$h    = fn(array $cols) => domainHealth($cols + ['health_status' => null, 'health_completed_datetime' => null, 'health_healthy_datetime' => null, 'health_incomplete' => 0], 60, $now);

ok('never completed: not checked, nothing known', ($x = $h([]))['state'] === 'not_checked' && $x['known'] === null);
ok('completed and healthy 10 minutes ago: healthy', $h(['health_status' => 'healthy', 'health_completed_datetime' => $at(10), 'health_healthy_datetime' => $at(10)])['state'] === 'healthy');
$p = $h(['health_status' => 'problem', 'health_completed_datetime' => $at(10), 'health_healthy_datetime' => $at(500)]);
ok('a confirmed problem 10 minutes ago: a current problem, with the last healthy date kept', $p['state'] === 'problem' && $p['last_healthy'] === $at(500));
$i = $h(['health_status' => 'problem', 'health_completed_datetime' => $at(10), 'health_incomplete' => 1]);
ok('a problem, then a check that could not complete: not checked - and the problem is still known', $i['state'] === 'not_checked' && $i['known'] === 'problem' && $i['since_incomplete']);
$s = $h(['health_status' => 'healthy', 'health_completed_datetime' => $at(121), 'health_healthy_datetime' => $at(121)]);
ok('healthy, but last completed over twice the interval ago: stale, never a pass', $s['state'] === 'stale' && $s['known'] === 'healthy');
ok('just inside twice the interval: still healthy', $h(['health_status' => 'healthy', 'health_completed_datetime' => $at(119)])['state'] === 'healthy');

// ------------------------------------------------------------------ PART 2
echo "\nPART 2 - every path through the service, the endpoints' rules and the scheduled run\n";
$conn = connectToDatabase();
$dbName = $conn->query('SELECT DATABASE()')->fetchColumn();
$expected = getenv('FREEITSM_TEST_DATABASE');
if (!$expected || $expected !== $dbName) {
    skip('PART 2', 'set FREEITSM_TEST_DATABASE to the throwaway database config.php points at (it writes domains and settings)');
} elseif (!domainScopeReady($conn)) {
    skip('PART 2', 'run Database Verification on this database first');
} else {
    require_once __DIR__ . '/../includes/services/domains.php';
    require_once __DIR__ . '/../includes/domains/watch.php';
    require_once __DIR__ . '/../includes/domains/scheduler.php';
    $admin = (int)$conn->query("SELECT id FROM analysts WHERE is_active = 1 ORDER BY id LIMIT 1")->fetchColumn();
    $ctx = new ActorContext(actorId: $admin, companyScope: null, source: 'ui', actorName: 'test');

    $keys = ['domain_lookup_mode', 'domain_checks_enabled', 'domain_ct_watch', 'domain_lookalike_scan', 'domain_dns_resolver', 'domain_internal_interval'];
    $savedSettings = [];
    foreach ($keys as $k) {
        $st = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ?");
        $st->execute([$k]);
        $savedSettings[$k] = $st->fetchColumn();
    }
    $set = function (string $k, ?string $v) use ($conn) {
        if ($v === null || $v === false) { $conn->prepare("DELETE FROM system_settings WHERE setting_key = ?")->execute([$k]); return; }
        $conn->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")->execute([$k, $v]);
    };
    $enabled = $conn->query("SELECT id FROM domains WHERE monitoring_enabled = 1 AND domain_name NOT LIKE 'zz-%'")->fetchAll(PDO::FETCH_COLUMN);
    $made = [];
    try {
        // Everything switched ON that could send a name outside - the worst case.
        // The resolver is Google, so any route that forgot the gate would show.
        foreach (['domain_lookup_mode' => 'scheduled', 'domain_checks_enabled' => '1', 'domain_ct_watch' => '1',
                  'domain_lookalike_scan' => '1', 'domain_dns_resolver' => 'google', 'domain_internal_interval' => '60'] as $k => $v) $set($k, $v);
        domainSettings($conn, true);
        // Only our domains are monitored while the scheduled run is traced.
        if ($enabled) $conn->exec("UPDATE domains SET monitoring_enabled = 0 WHERE id IN (" . implode(',', array_map('intval', $enabled)) . ")");

        $iname = 'zz-int-' . bin2hex(random_bytes(3)) . '.corp.invalid';
        $id = DomainsService::createDomain($conn, $ctx, ['domain_name' => $iname, 'scope' => 'internal'], null);
        $made[] = $id;
        $stored = $conn->query("SELECT scope FROM domains WHERE id = $id")->fetchColumn();
        ok('an internal domain is stored with scope internal', $stored === 'internal', (string)$stored);

        $t = $trace(fn() => $GLOBALS['lk'] = DomainsService::refreshLookup($conn, $ctx, $id));
        ok('Refresh: refused as internal, no registry asked', !$GLOBALS['lk']['ok'] && !empty($GLOBALS['lk']['internal']) && $t === [], json_encode($t));
        ok('...and not recorded as a lookup failure', $conn->query("SELECT last_lookup_error FROM domains WHERE id = $id")->fetchColumn() === null);

        $t = $trace(fn() => $GLOBALS['ck'] = DomainsService::runChecks($conn, $ctx, $id));
        ok('Check now: only this server\'s resolver, even with Google chosen install-wide', !$outside($t) && $t !== [], json_encode($outside($t)));
        $d = $conn->query("SELECT * FROM domains WHERE id = $id")->fetch(PDO::FETCH_ASSOC);
        ok('...a confirmed problem (the name has no address) is stored as a completed check',
           $d['health_status'] === 'problem' && $d['health_completed_datetime'] !== null && (int)$d['health_incomplete'] === 0, json_encode(array_intersect_key($d, array_flip(['health_status', 'health_completed_datetime', 'health_incomplete']))));
        ok('...with no grade, no certificate and no healthy date', $d['security_grade'] === null && $d['ssl_expiry_date'] === null && $d['health_healthy_datetime'] === null);
        ok('...and the service hands back the health', ($GLOBALS['ck']['health']['state'] ?? null) === 'problem', json_encode($GLOBALS['ck']['health'] ?? null));
        ok('...the change of health is in the history', (bool)$conn->query("SELECT 1 FROM domain_audit WHERE domain_id = $id AND field_name = 'health_status' AND new_value = 'problem'")->fetchColumn());

        $t = $trace(fn() => $GLOBALS['ct'] = domainCtScan($conn, $id, $iname));
        ok('CT scan: refused inside the function itself, crt.sh not asked', !$GLOBALS['ct']['ok'] && $t === [], json_encode($t));
        $t = $trace(fn() => $GLOBALS['la'] = domainLookalikeScan($conn, $id, $iname, 'google', 5));
        ok('look-alike scan: refused, no look-alike asked of anyone', !empty($GLOBALS['la']['internal']) && $t === [], json_encode($t));

        // The scheduled run, with everything due: the internal domain is checked
        // again (interval passed) and nothing else happens to it.
        $conn->exec("UPDATE domains SET last_check_datetime = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 2 HOUR), last_lookup_datetime = NULL WHERE id = $id");
        $t = $trace(fn() => $GLOBALS['run'] = domainScheduledRun($conn, 60, true));
        $run = $GLOBALS['run'];
        ok('scheduled run: the internal domain is checked on its interval', $run['checks'] === 1, json_encode($run));
        ok('...not looked up, not watched', $run['lookups'] === 0 && $run['lookup_errors'] === 0 && $run['ct'] === 0 && $run['lookalikes'] === 0, json_encode($run));
        ok('...and nothing but this server\'s resolver was asked', !$outside($t), json_encode($outside($t)));

        // CONTROL for the scheduled run: a public domain IS looked up at a registry.
        $pid = DomainsService::createDomain($conn, $ctx, ['domain_name' => 'zz-pub-' . bin2hex(random_bytes(3)) . '.com', 'scope' => 'public', 'purpose' => 'parked'], null);
        $made[] = $pid;
        $set('domain_ct_watch', '0'); $set('domain_lookalike_scan', '0'); domainSettings($conn, true);
        $t = $trace(fn() => domainScheduledRun($conn, 60, false));
        ok('CONTROL: the same run IS seen asking a registry for a public domain', (bool)preg_grep('#rdap|iana|whois://#i', $t), json_encode(array_slice($t, 0, 5)));

        // A resolver failure after a known problem: the problem stays known.
        $conn->exec("UPDATE domains SET health_incomplete = 1 WHERE id = $id");
        $h = domainHealth($conn->query("SELECT * FROM domains WHERE id = $id")->fetch(PDO::FETCH_ASSOC), 60);
        ok('a later check that could not complete leaves the known problem on show', $h['state'] === 'not_checked' && $h['known'] === 'problem');

        // The list and the attention rule.
        require_once __DIR__ . '/../includes/domains/read.php';
        $listed = array_values(array_filter(domainListRows($conn, $admin, ['scope' => 'internal']), fn($r) => (int)$r['id'] === $id));
        ok('the register filters by scope, and shapes the health', $listed && $listed[0]['scope'] === 'internal' && $listed[0]['health']['known'] === 'problem');
        ok('...an internal problem needs attention as "health", never as "grade"', $listed && in_array('health', $listed[0]['attention'], true) && !in_array('grade', $listed[0]['attention'], true), json_encode($listed[0]['attention'] ?? null));

        // Switching it public again: the public paths open up, as they should.
        DomainsService::updateDomain($conn, $ctx, $id, ['scope' => 'public']);
        ok('switched back to public, the gate opens', domainIdMayLeaveNetwork($conn, $id));
    } finally {
        foreach ($made as $mid) {
            foreach (['domain_audit', 'domain_alerts_sent', 'domain_lookalikes', 'domain_certificates'] as $tbl) {
                try { $conn->prepare("DELETE FROM $tbl WHERE domain_id = ?")->execute([$mid]); } catch (Throwable) {}
            }
            $conn->prepare("DELETE FROM domains WHERE id = ? AND domain_name LIKE 'zz-%'")->execute([$mid]);
        }
        if ($enabled) $conn->exec("UPDATE domains SET monitoring_enabled = 1 WHERE id IN (" . implode(',', array_map('intval', $enabled)) . ")");
        foreach ($savedSettings as $k => $v) $set($k, $v === false ? null : $v);
        echo "  (cleaned up: " . count($made) . " test domains removed, " . count($enabled) . " monitoring flags and " . count($savedSettings) . " settings restored)\n";
    }
}

echo "\n" . str_repeat('=', 72) . "\n$pass passed, $fail failed" . ($skip ? ", $skip skipped" : '') . "\n";
exit($fail ? 1 : 0);
