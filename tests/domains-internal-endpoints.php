<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * Domains - HTTPS endpoints on internal domains (GH #203 slice 2).
 *
 *   php tests/domains-internal-endpoints.php                     parts 1 and 2
 *   FREEITSM_TEST_DATABASE=<a throwaway database> php tests/...  part 3 as well
 *
 * PART 1 - the rules with no connection: network ranges, ports, trust profiles.
 * PART 2 - real TLS. It makes a throwaway certificate authority and four small TLS
 *          servers on 127.0.0.1 (a good certificate, one inside its warning window,
 *          one for the wrong name, and a closed port), and checks endpoints against
 *          them. A pretend DNS answer points the test names at 127.0.0.1, because a
 *          Windows server's resolver does not answer for localhost. Every refusal
 *          is proved to make NO connection, from the trace.
 * PART 3 - through DomainsService on the test database: saving endpoints (and what
 *          is refused), the check storing results and the domain's health, the
 *          certificate expiry feeding the existing alerts, and a person without the
 *          permission not probing. Its rows are named zz-... and removed at the end.
 *
 * Controls throughout: the good endpoint really is healthy, and really is connected
 * to, so a refusal means "refused", not "broken".
 *
 * @wiki Domains-Developer-Guide
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/domains/settings.php';

$pass = 0; $fail = 0; $skip = 0;
function ok(string $what, bool $cond, string $detail = ''): void
{
    global $pass, $fail;
    if ($cond) { $pass++; echo "  PASS  $what\n"; }
    else       { $fail++; echo "  FAIL  $what" . ($detail !== '' ? "  <- $detail" : '') . "\n"; }
}
/** Runs $fn and returns every connection Domains made meanwhile. */
function traced(callable $fn): array
{
    $GLOBALS['DOMAIN_HTTP_TRACE'] = [];
    try { $fn(); } finally { $t = $GLOBALS['DOMAIN_HTTP_TRACE']; unset($GLOBALS['DOMAIN_HTTP_TRACE']); }
    return $t;
}

// ------------------------------------------------------------------ PART 1
echo "PART 1 - network ranges, ports and trust profiles\n";
ok('a range is stored in its canonical form', domainCidrNormalise('10.20.30.40/16') === '10.20.0.0/16');
ok('IPv6 ranges work', domainCidrNormalise('fd00::1/8') === 'fd00::/8');
ok('nonsense and impossible masks are refused', domainCidrNormalise('banana') === null && domainCidrNormalise('10.0.0.0/33') === null);
ok('control: an address inside a range is inside', domainIpInCidr('172.31.255.1', '172.16.0.0/12'));
ok('the next address along is outside', !domainIpInCidr('172.32.0.1', '172.16.0.0/12'));
ok('an IPv4 address is never inside an IPv6 range', !domainIpInCidr('10.0.0.1', 'fc00::/7'));
ok('networks are refused when not a range', (function () { try { domainSettingValidate('domain_internal_networks', '10.0.0.0/8, nope'); return false; } catch (InvalidArgumentException) { return true; } })());
ok('networks are cleaned and de-duplicated', domainSettingValidate('domain_internal_networks', "10.1.2.3/8\n10.0.0.0/8 192.168.0.0/16") === '10.0.0.0/8,192.168.0.0/16');
ok('ports are sorted and checked', domainSettingValidate('domain_internal_ports', '8443, 443 443') === '443,8443');
ok('a port out of range is refused', (function () { try { domainSettingValidate('domain_internal_ports', '70000'); return false; } catch (InvalidArgumentException) { return true; } })());
ok('nothing is permitted until somebody chooses: no networks by default', domainInternalNetworks(['domain_internal_networks' => '']) === []);
ok('443 is the only port out of the box', domainInternalPorts(domainSettingDefinitions() ? ['domain_internal_ports' => domainSettingDefinitions()['domain_internal_ports'][0]] : []) === [443]);
ok('an emptied port list is no ports, not "back to 443"', domainInternalPorts(['domain_internal_ports' => '']) === []);

// ---- a throwaway CA and certificates, made here
$cfgFile = null;
foreach ([getenv('OPENSSL_CONF'), dirname(PHP_BINARY) . '/extras/ssl/openssl.cnf', '/usr/lib/ssl/openssl.cnf', '/etc/ssl/openssl.cnf'] as $c) if ($c && is_file($c)) { $cfgFile = $c; break; }
$dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'zz-ep-' . bin2hex(random_bytes(3));
mkdir($dir);
/** An OpenSSL config whose leaf section names $host, so the certificate carries it as a SAN. */
$leafCfg = function (string $host) use ($dir, $cfgFile): string {
    $f = "$dir/leaf-" . md5($host) . '.cnf';
    file_put_contents($f, ($cfgFile ? file_get_contents($cfgFile) : "[ req ]\ndistinguished_name = dn\n[ dn ]\n")
        . "\n[ zz_leaf ]\nbasicConstraints = CA:FALSE\nsubjectAltName = DNS:$host\n[ zz_ca ]\nbasicConstraints = critical, CA:TRUE\nkeyUsage = critical, keyCertSign, cRLSign\n");
    return $f;
};
$caCfg = $leafCfg('ca.invalid');
$caKey = openssl_pkey_new(['config' => $caCfg, 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
$caCert = openssl_csr_sign(openssl_csr_new(['commonName' => 'ZZ Test Internal CA'], $caKey, ['config' => $caCfg, 'digest_alg' => 'sha256']),
                           null, $caKey, 30, ['config' => $caCfg, 'digest_alg' => 'sha256', 'x509_extensions' => 'zz_ca'], 1);
openssl_x509_export($caCert, $caPem);
$serial = 2;
/** A certificate for $host from the test CA, valid $days days, written with its key for a server. */
$leaf = function (string $host, int $days) use ($dir, $leafCfg, $caKey, $caCert, &$serial): string {
    $cfg = $leafCfg($host);
    $k = openssl_pkey_new(['config' => $cfg, 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    $x = openssl_csr_sign(openssl_csr_new(['commonName' => $host], $k, ['config' => $cfg, 'digest_alg' => 'sha256']),
                          $caCert, $caKey, $days, ['config' => $cfg, 'digest_alg' => 'sha256', 'x509_extensions' => 'zz_leaf'], $serial++);
    openssl_x509_export($x, $pem);
    openssl_pkey_export($k, $kp, null, ['config' => $cfg]);
    $f = "$dir/" . md5($host . $days) . '.pem';
    file_put_contents($f, $pem . $kp);
    return $f;
};

ok('control: a CA certificate makes a trust profile', (function () use ($caPem) { $p = domainTrustProfileParse($caPem); return str_contains($p['summary'], 'ZZ Test Internal CA'); })());
ok('a private key is refused, with the reason', (function () use ($caKey) { openssl_pkey_export($caKey, $kp, null, ['config' => $GLOBALS['caCfg'] ?? null]); try { domainTrustProfileParse($kp); return false; } catch (InvalidArgumentException $e) { return str_contains($e->getMessage(), 'private key'); } })());
ok('text that is not a certificate is refused', (function () { try { domainTrustProfileParse('hello'); return false; } catch (InvalidArgumentException) { return true; } })());

// ------------------------------------------------------------------ PART 2
echo "\nPART 2 - real TLS against throwaway local servers\n";
$domain = 'zz-ep.test';
$host   = 'app.zz-ep.test';
$ports  = ['good' => 47431, 'soon' => 47432, 'wrong' => 47433, 'closed' => 47439];
$certs  = ['good' => $leaf($host, 365), 'soon' => $leaf($host, 10), 'wrong' => $leaf('other.zz-ep.test', 365)];
$server = "$dir/server.php";
file_put_contents($server, '<?php
// Throwaway TLS servers for tests/domains-internal-endpoints.php: port => cert file, for a few seconds.
$map = json_decode($argv[1], true);
$socks = [];
foreach ($map as $port => $pem) {
    $ctx = stream_context_create(["ssl" => ["local_cert" => $pem, "verify_peer" => false, "allow_self_signed" => true]]);
    $s = stream_socket_server("ssl://127.0.0.1:$port", $e, $m, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $ctx);
    if ($s) $socks[] = $s;
}
echo count($socks), "\n";
$end = time() + (int)$argv[2];
while (time() < $end) {
    $r = $socks; $w = null; $x = null;
    // GH #243: a request line gets an HTTP answer by path; a bare certificate check sends
    // nothing and simply leaves. /moved points outside, to prove a redirect is not followed.
    if (@stream_select($r, $w, $x, 1) > 0) foreach ($r as $s) {
        $c = @stream_socket_accept($s, 1);
        if (!$c) continue;
        stream_set_timeout($c, 2);
        $line = (string)@fgets($c, 1024);
        if (preg_match("#^GET (\S+)#", $line, $q)) {
            $code = ["/ok" => "200 OK", "/moved" => "301 Moved Permanently"][$q[1]] ?? "404 Not Found";
            @fwrite($c, "HTTP/1.1 $code
Location: https://example.org/
Content-Length: 0
Connection: close

");
        }
        fclose($c);
    }
}');
$map = [$ports['good'] => $certs['good'], $ports['soon'] => $certs['soon'], $ports['wrong'] => $certs['wrong']];
$proc = proc_open([PHP_BINARY, $server, json_encode($map), '60'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
$started = trim((string)fgets($pipes[1]));
ok('control: the three test servers are listening', $started === '3', "started $started");

// Pretend DNS: the test names are at 127.0.0.1; "two" also has an address outside.
$GLOBALS['DOMAIN_ENDPOINT_RESOLVER'] = function (string $h, string $t) {
    $map = ['app.zz-ep.test' => ['127.0.0.1'], 'two.zz-ep.test' => ['127.0.0.1', '8.8.8.8']];
    return ['ok' => true, 'nxdomain' => !isset($map[$h]), 'records' => $t === 'A' ? ($map[$h] ?? []) : [], 'error' => null];
};
$nets = ['127.0.0.0/8'];
$allPorts = array_values($ports);
$check = function (array $ep, array $networks, array $permitted, ?string $ca) use ($domain, &$r) {
    return traced(function () use ($ep, $domain, $networks, $permitted, $ca, &$r) { $r = domainCheckEndpoint($ep + ['warn_days' => 21], $domain, $networks, $permitted, $ca); });
};

$t = $check(['host' => $host, 'port' => $ports['good']], $nets, $allPorts, $caPem);
ok('control: a good certificate from the internal CA is healthy', $r['completed'] && $r['status'] === 'healthy', json_encode($r));
ok('...connected to the resolved address, with the host name kept for SNI', $t === ["tls://$host:{$ports['good']}@127.0.0.1"], json_encode($t));
ok('...and the certificate is recorded', ($r['cert']['hostname_match'] ?? null) === true && ($r['cert']['chain_valid'] ?? null) === true);

$t = $check(['host' => $host, 'port' => $ports['good']], $nets, $allPorts, null);
ok('the same certificate against the server\'s normal list: untrusted, never accepted', $r['completed'] && $r['status'] === 'problem' && $r['detail'] === 'untrusted', json_encode($r));

$check(['host' => $host, 'port' => $ports['soon']], $nets, $allPorts, $caPem);
ok('10 days left with a 21-day window: expiring', $r['completed'] && $r['status'] === 'expiring' && $r['params']['days'] <= 10, json_encode($r));

$check(['host' => $host, 'port' => $ports['wrong']], $nets, $allPorts, $caPem);
ok('a certificate for another name: wrong name, a problem', $r['completed'] && $r['status'] === 'problem' && $r['detail'] === 'wrong_name', json_encode($r));

$check(['host' => $host, 'port' => $ports['closed']], $nets, $allPorts, $caPem);
ok('nothing listening: a confirmed problem, "nothing answered"', $r['completed'] && $r['status'] === 'problem' && $r['detail'] === 'no_answer', json_encode($r));

echo "  - refusals: none of these may connect anywhere\n";
$t = $check(['host' => $host, 'port' => $ports['good']], [], $allPorts, $caPem);
ok('no network permitted (the default): not checked, no connection', !$r['completed'] && $r['detail'] === 'no_networks' && $t === [], json_encode($t));
$t = $check(['host' => $host, 'port' => $ports['good']], ['10.0.0.0/8'], $allPorts, $caPem);
ok('an address outside the permitted networks: refused, no connection', !$r['completed'] && $r['detail'] === 'network_refused' && $r['params']['address'] === '127.0.0.1' && $t === [], json_encode($t));
$t = $check(['host' => 'two.zz-ep.test', 'port' => $ports['good']], $nets, $allPorts, $caPem);
ok('a name with ONE address outside: refused outright, not probed at the allowed one', !$r['completed'] && $r['detail'] === 'network_refused' && $r['params']['address'] === '8.8.8.8' && $t === [], json_encode($t));
$t = $check(['host' => $host, 'port' => $ports['good']], $nets, [443], $caPem);
ok('a port that is not permitted: refused before any DNS or connection', !$r['completed'] && $r['detail'] === 'port_refused' && $t === [], json_encode($t));
$t = $check(['host' => 'example.org', 'port' => $ports['good']], $nets, $allPorts, $caPem);
ok('a host that is not this domain or under it: refused, no connection', !$r['completed'] && $r['detail'] === 'host_refused' && $t === [], json_encode($t));
$t = $check(['host' => 'missing.zz-ep.test', 'port' => $ports['good']], $nets, $allPorts, $caPem);
ok('a name with no address: a confirmed problem, no connection', $r['completed'] && $r['detail'] === 'no_address' && $t === [], json_encode($t));

echo "
PART 2b - the optional HTTP check (GH #243)
";
$t = $check(['host' => $host, 'port' => $ports['good'], 'http_path' => '/ok'], $nets, $allPorts, $caPem);
ok('a page answering the expected 200: healthy', $r['status'] === 'healthy', json_encode($r));
ok('...asked once, at the resolved address, after the certificate', $t === ["tls://$host:{$ports['good']}@127.0.0.1", "https://$host:{$ports['good']}/ok@127.0.0.1"], json_encode($t));
$check(['host' => $host, 'port' => $ports['good'], 'http_path' => '/missing'], $nets, $allPorts, $caPem);
ok('a page answering 404 when 200 is expected: a problem saying so', $r['status'] === 'problem' && $r['detail'] === 'http_status' && $r['params']['status'] === 404 && $r['params']['expected'] === '200', json_encode($r));
$t = $check(['host' => $host, 'port' => $ports['good'], 'http_path' => '/moved', 'http_expect' => '301, 302'], $nets, $allPorts, $caPem);
ok('a redirect that is expected: healthy', $r['status'] === 'healthy', json_encode($r));
ok('...and never followed - nothing else is asked', count(array_filter($t, fn($x) => str_starts_with($x, 'https://'))) === 1 && !array_filter($t, fn($x) => str_contains($x, 'example.org')), json_encode($t));
$check(['host' => $host, 'port' => $ports['good'], 'http_path' => '/moved'], $nets, $allPorts, $caPem);
ok('a redirect that is not expected: a problem', $r['detail'] === 'http_status' && $r['params']['status'] === 301, json_encode($r));
$t = $check(['host' => $host, 'port' => $ports['good'], 'http_path' => '/ok'], $nets, $allPorts, null);
ok('an untrusted certificate is the problem - the page is never asked', $r['detail'] === 'untrusted' && !array_filter($t, fn($x) => str_starts_with($x, 'https://')), json_encode($t));
ok('expected codes are read tidily, 200 by default', domainHttpExpect(' 204,200 200') === [200, 204] && domainHttpExpect('') === [200] && domainHttpExpect('abc') === [200]);

// ------------------------------------------------------------------ PART 3
echo "\nPART 3 - through DomainsService on the test database\n";
$conn = connectToDatabase();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$dbName = $conn->query('SELECT DATABASE()')->fetchColumn();
$expected = getenv('FREEITSM_TEST_DATABASE');
if (!$expected || $expected !== $dbName) {
    $skip++; echo "  SKIP  PART 3 - set FREEITSM_TEST_DATABASE to the throwaway database config.php points at (this one is $dbName)\n";
} elseif (!domainEndpointsReady($conn) || !domainScopeReady($conn)) {
    $skip++; echo "  SKIP  PART 3 - run Database Verification on this database first\n";
} else {
    require_once __DIR__ . '/../includes/services/domains.php';
    $admin = (int)$conn->query("SELECT a.id FROM analysts a WHERE a.is_active = 1 ORDER BY a.id LIMIT 1")->fetchColumn();
    $ctx = new ActorContext(actorId: $admin, companyScope: null, source: 'ui', actorName: 'test');
    $nobody = new ActorContext(actorId: 2000000000, companyScope: null, source: 'ui', actorName: 'nobody');   // holds no permission
    $saved = [];
    foreach (['domain_internal_networks', 'domain_internal_ports'] as $k) {
        $st = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ?");
        $st->execute([$k]);
        $saved[$k] = $st->fetchColumn();
    }
    $made = []; $profileId = null;
    try {
        domainSettingWrite($conn, 'domain_internal_networks', '127.0.0.0/8');
        domainSettingWrite($conn, 'domain_internal_ports', implode(',', $allPorts));
        domainSettings($conn, true);
        $iname = 'zz-ep-' . bin2hex(random_bytes(3)) . '.test';
        $id = DomainsService::createDomain($conn, $ctx, ['domain_name' => $iname, 'scope' => 'internal'], null);
        $made[] = $id;
        $pub = DomainsService::createDomain($conn, $ctx, ['domain_name' => 'zz-pub-' . bin2hex(random_bytes(3)) . '.com', 'scope' => 'public', 'purpose' => 'parked'], null);
        $made[] = $pub;
        $GLOBALS['DOMAIN_ENDPOINT_RESOLVER'] = function (string $h, string $t) use ($iname) {
            return ['ok' => true, 'nxdomain' => false, 'records' => $t === 'A' && str_ends_with($h, $iname) ? ['127.0.0.1'] : [], 'error' => null];
        };
        $refused = function (array $in) use ($conn, $ctx) { try { DomainsService::saveEndpoint($conn, $ctx, $in); return false; } catch (ServiceError $e) { return $e->getMessage(); } };
        ok('an endpoint on a PUBLIC domain is refused', (bool)$refused(['domain_id' => $pub, 'host' => 'zz.example.com', 'port' => 443]));
        ok('an address instead of a name is refused', (bool)$refused(['domain_id' => $id, 'host' => '127.0.0.1', 'port' => 443]));
        ok('a host outside the domain is refused', (bool)$refused(['domain_id' => $id, 'host' => 'intranet.example.org', 'port' => 443]));
        ok('a port out of range is refused', (bool)$refused(['domain_id' => $id, 'host' => "app.$iname", 'port' => 70000]));

        $profileId = DomainsService::saveTrustProfile($conn, $ctx, ['name' => 'zz-test CA', 'ca_pem' => $caPem])['id'];
        $good = DomainsService::saveEndpoint($conn, $ctx, ['domain_id' => $id, 'host' => "app.$iname", 'port' => $ports['good'], 'warn_days' => 21, 'trust_profile_id' => $profileId]);
        ok('control: a good endpoint is saved', (int)$good['id'] > 0);
        ok('the same host and port twice is refused', (bool)$refused(['domain_id' => $id, 'host' => "app.$iname", 'port' => $ports['good']]));
        // GH #243: the optional HTTP check's path and codes.
        if (DomainsService::httpCheckReady($conn)) {
            ok('a path without a leading / is refused', (bool)$refused(['domain_id' => $id, 'host' => "h1.$iname", 'port' => $ports['good'], 'http_path' => 'health']));
            ok('a path with a space is refused', (bool)$refused(['domain_id' => $id, 'host' => "h1.$iname", 'port' => $ports['good'], 'http_path' => '/a b']));
            ok('a code that is not a status code is refused', (bool)$refused(['domain_id' => $id, 'host' => "h1.$iname", 'port' => $ports['good'], 'http_path' => '/ok', 'http_expect' => '200, 999']));
            $h = DomainsService::saveEndpoint($conn, $ctx, ['domain_id' => $id, 'host' => "h2.$iname", 'port' => $ports['good'], 'http_path' => '/ok', 'http_expect' => '204 200']);
            ok('a valid HTTP check is stored, its codes tidied (control)', $h['http_path'] === '/ok' && $h['http_expect'] === '200, 204', json_encode($h));
            $h2 = DomainsService::saveEndpoint($conn, $ctx, ['domain_id' => $id, 'host' => "h3.$iname", 'port' => $ports['good'], 'http_expect' => '204']);
            ok('codes without a path store nothing', $h2['http_path'] === null && $h2['http_expect'] === null);
        }
        ok('a trust profile in use cannot be deleted', (function () use ($conn, $profileId) { try { DomainsService::deleteTrustProfile($conn, $profileId); return false; } catch (ServiceError) { return true; } })());

        // Our certificates are for app.zz-ep.test, so this endpoint's name does not match - except the
        // good server, which is checked under the real name by a host-matching certificate below.
        $t = traced(fn() => $GLOBALS['res'] = DomainsService::runChecks($conn, null, $id));
        $ep = $conn->query("SELECT * FROM domain_endpoints WHERE id = " . (int)$good['id'])->fetch(PDO::FETCH_ASSOC);
        ok('the scheduled run (no person) probes the endpoint, at its resolved address', in_array("tls://app.$iname:{$ports['good']}@127.0.0.1", $t, true), json_encode($t));
        ok('...stores a completed result (wrong name: the test certificate is for another host)', $ep['status'] === 'problem' && $ep['detail_key'] === 'wrong_name' && $ep['last_completed_datetime'] !== null, json_encode($ep));
        $d = $conn->query("SELECT health_status, ssl_expiry_date FROM domains WHERE id = $id")->fetch(PDO::FETCH_ASSOC);
        ok('...the domain\'s health follows its endpoint', $d['health_status'] === 'problem', json_encode($d));
        ok('...the certificate\'s expiry feeds the existing certificate alerts and calendar', $d['ssl_expiry_date'] !== null);
        ok('...and the change is in the history, labelled by host:port', (bool)$conn->query("SELECT 1 FROM domain_audit WHERE domain_id = $id AND field_name = 'endpoint_status' AND new_value LIKE '%:{$ports['good']}: problem'")->fetchColumn());

        $t = traced(fn() => DomainsService::runChecks($conn, $nobody, $id));
        ok('Check now by somebody without the permission: no connection to the endpoint', !array_filter($t, fn($x) => str_starts_with($x, 'tls://')), json_encode($t));
        ok('...and the endpoint keeps its last result', $conn->query("SELECT status FROM domain_endpoints WHERE id = " . (int)$good['id'])->fetchColumn() === 'problem');

        domainSettingWrite($conn, 'domain_internal_networks', '10.0.0.0/8');
        domainSettings($conn, true);
        traced(fn() => DomainsService::runChecks($conn, null, $id));
        $ep = $conn->query("SELECT * FROM domain_endpoints WHERE id = " . (int)$good['id'])->fetch(PDO::FETCH_ASSOC);
        ok('network taken off: not checked, and the last known result is kept beside it', (int)$ep['incomplete'] === 1 && $ep['detail_key'] === 'network_refused' && $ep['status'] === 'problem', json_encode($ep));

        DomainsService::deleteEndpoint($conn, $ctx, (int)$good['id']);
        ok('an endpoint can be removed', !$conn->query("SELECT 1 FROM domain_endpoints WHERE id = " . (int)$good['id'])->fetchColumn());
        DomainsService::deleteTrustProfile($conn, $profileId);
        $profileId = null;
        ok('...after which its trust profile can be deleted', true);
    } finally {
        foreach ($made as $m) {
            $conn->prepare("DELETE FROM domain_endpoints WHERE domain_id = ?")->execute([$m]);
            DomainsService::deleteDomain($conn, $ctx, $m);
        }
        if ($profileId) $conn->prepare("DELETE FROM domain_trust_profiles WHERE id = ?")->execute([$profileId]);
        foreach ($saved as $k => $v) {
            if ($v === false) $conn->prepare("DELETE FROM system_settings WHERE setting_key = ?")->execute([$k]);
            else domainSettingWrite($conn, $k, (string)$v);
        }
    }
}

unset($GLOBALS['DOMAIN_ENDPOINT_RESOLVER']);
proc_terminate($proc);
foreach (glob("$dir/*") as $f) @unlink($f);
@rmdir($dir);

echo "\n$pass passed, $fail failed" . ($skip ? ", $skip skipped" : '') . "\n";
exit($fail ? 1 : 0);
