<?php
/**
 * vCenter connection test (GH #445) - includes/vcenter_diagnose.php against a
 * stand-in vCenter that this script runs itself, over real HTTPS.
 *
 *   php tests/vcenter-connection-test.php
 *
 * Needs no database and no real vCenter. It makes a throwaway certificate
 * authority and server certificate, starts two small TLS servers on 127.0.0.1
 * (one answers like vSphere 8 with /api, one like vSphere 6.7 with only /rest),
 * and runs vcenterDiagnose() against them for each failure the test is there to
 * explain: a pasted URL, a name that does not resolve, a closed port, an
 * untrusted certificate, an older vCenter, a wrong password, a user who sees no
 * VMs - and the one where everything works.
 *
 * The control for every check: each scenario asserts the step that should fail
 * AND that every step after it was skipped, so a check that silently passes
 * would show up as the wrong first red line.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$root = dirname(__DIR__);
require_once $root . '/includes/i18n.php';
require_once $root . '/includes/vcenter_diagnose.php';

$pass = 0; $fail = 0;
function ok(bool $c, string $what) { global $pass, $fail; if ($c) { $pass++; echo "  ok   $what\n"; } else { $fail++; echo "  FAIL $what\n"; } }
function statuses(array $r): array { $o = []; foreach ($r['checks'] as $c) $o[$c['key']] = $c['status']; return $o; }
function check(array $r, string $key): array { foreach ($r['checks'] as $c) if ($c['key'] === $key) return $c; return []; }

// ── a throwaway CA and a server certificate for "localhost" ─────────────────
$tmp = sys_get_temp_dir() . '/zz_vctest_' . bin2hex(random_bytes(4));
mkdir($tmp);
$cnf = $tmp . '/openssl.cnf';
file_put_contents($cnf, "[req]\ndistinguished_name=dn\n[dn]\n[v3_ca]\nbasicConstraints=critical,CA:TRUE\nkeyUsage=critical,keyCertSign,cRLSign\n[v3_srv]\nbasicConstraints=CA:FALSE\nsubjectAltName=DNS:localhost,IP:127.0.0.1\nextendedKeyUsage=serverAuth\n");
$o = ['config' => $cnf, 'digest_alg' => 'sha256', 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
$caKey = openssl_pkey_new($o);
$caCsr = openssl_csr_new(['commonName' => 'ZZ Test VMCA'], $caKey, $o);
$caCrt = openssl_csr_sign($caCsr, null, $caKey, 2, $o + ['x509_extensions' => 'v3_ca'], 1);
$srvKey = openssl_pkey_new($o);
$srvCsr = openssl_csr_new(['commonName' => 'localhost'], $srvKey, $o);
$srvCrt = openssl_csr_sign($srvCsr, $caCrt, $caKey, 2, $o + ['x509_extensions' => 'v3_srv'], 2);
openssl_x509_export($caCrt, $caPem);
openssl_x509_export($srvCrt, $srvPem);
openssl_pkey_export($srvKey, $srvKeyPem, null, $o);
file_put_contents($tmp . '/ca.pem', $caPem);
file_put_contents($tmp . '/server.pem', $srvPem . $srvKeyPem);

// ── the stand-in vCenter ─────────────────────────────────────────────────────
// One request per connection (cURL opens a new one for each handle here).
$stub = <<<'PHP'
<?php
[$_, $port, $pem, $mode] = $argv;
$ctx = stream_context_create(['ssl' => ['local_cert' => $pem, 'verify_peer' => false]]);
$srv = stream_socket_server("tls://127.0.0.1:$port", $e, $es, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $ctx);
if (!$srv) { fwrite(STDERR, "listen failed: $es\n"); exit(1); }
echo "ready\n"; fflush(STDOUT);
while (true) {
    $c = @stream_socket_accept($srv, 600);
    if (!$c) continue;
    $head = '';
    while (($l = fgets($c)) !== false) { $head .= $l; if ($l === "\r\n") break; }
    if (!preg_match('#^(\w+) (\S+)#', $head, $m)) { fclose($c); continue; }
    [$all, $method, $path] = $m;
    $auth = preg_match('/^authorization: basic (\S+)/im', $head, $a) ? base64_decode($a[1]) : null;
    $token = preg_match('/^vmware-api-session-id: (\S+)/im', $head, $t) ? $t[1] : null;
    $good = $token === 'zz-session-7f3a9c';
    $send = function ($code, $body = '') use ($c) {
        $txt = [200 => 'OK', 201 => 'Created', 204 => 'No Content', 401 => 'Unauthorized', 404 => 'Not Found'][$code] ?? 'X';
        fwrite($c, "HTTP/1.1 $code $txt\r\nContent-Type: application/json\r\nContent-Length: " . strlen($body) . "\r\nConnection: close\r\n\r\n$body");
    };
    $p = $mode === 'rest' ? '/rest' : '/api';
    $sessionPath = $mode === 'rest' ? '/rest/com/vmware/cis/session' : '/api/session';
    $wrap = fn($v) => $mode === 'rest' ? json_encode(['value' => $v]) : json_encode($v);
    if ($path === $sessionPath && $method === 'POST') {
        if ($auth === 'zzuser@vsphere.local:zz-right' || $auth === 'zzempty@vsphere.local:zz-right') $send(201, $wrap('zz-session-7f3a9c' . ($auth[2] === 'e' ? '' : '')));
        else $send(401, json_encode(['error_type' => 'UNAUTHENTICATED', 'messages' => []]));
    } elseif ($path === $sessionPath && $method === 'DELETE') {
        $send($good ? 204 : 401);
    } elseif (!$good) {
        $send(404, '{"error_type":"NOT_FOUND"}');
    } elseif ($path === "$p/appliance/system/version") {
        $send(200, $wrap(['product' => 'VMware vCenter Server', 'version' => '8.0.2', 'build' => '22617221']));
    } elseif ($path === "$p/vcenter/host") {
        $send(200, $wrap([['host' => 'host-10', 'name' => 'esx01.zz.test'], ['host' => 'host-11', 'name' => 'esx02.zz.test']]));
    } elseif ($path === "$p/vcenter/cluster") {
        $send(200, $wrap([['cluster' => 'domain-c8', 'name' => 'ZZ Cluster']]));
    } elseif ($path === "$p/vcenter/datastore") {
        $send(200, $wrap([['datastore' => 'ds-1', 'name' => 'zz-ds', 'capacity' => 2199023255552, 'free_space' => 1099511627776]]));
    } elseif ($path === "$p/vcenter/vm") {
        $empty = getenv('ZZ_EMPTY') === '1';
        $send(200, $wrap($empty ? [] : [
            ['vm' => 'vm-1', 'name' => 'zz-web', 'power_state' => 'POWERED_ON', 'cpu_count' => 4, 'memory_size_MiB' => 8192],
            ['vm' => 'vm-2', 'name' => 'zz-db', 'power_state' => 'POWERED_ON', 'cpu_count' => 8, 'memory_size_MiB' => 32768],
            ['vm' => 'vm-3', 'name' => 'zz-old', 'power_state' => 'POWERED_OFF', 'cpu_count' => 2, 'memory_size_MiB' => 4096],
        ]));
    } else {
        $send(404, '{"error_type":"NOT_FOUND"}');
    }
    fclose($c);
}
PHP;
file_put_contents($tmp . '/stub.php', $stub);

$procs = [];
$start = function (int $port, string $mode, array $env = []) use ($tmp, &$procs) {
    $p = proc_open([PHP_BINARY, $tmp . '/stub.php', (string)$port, $tmp . '/server.pem', $mode], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env + getenv());
    $line = fgets($pipes[1]);
    if (trim((string)$line) !== 'ready') { echo "stub on $port did not start: " . stream_get_contents($pipes[2]) . "\n"; exit(1); }
    $procs[] = $p;
};
$portNew = 28443; $portOld = 28444; $portEmpty = 28445; $portClosed = 28449;
$start($portNew, 'api');
$start($portOld, 'rest');
$start($portEmpty, 'api', ['ZZ_EMPTY' => '1']);

// Verify certificates against the throwaway CA, as an install would against its bundle.
define('SSL_VERIFY_PEER', true);
define('SSL_CA_BUNDLE', $tmp . '/ca.pem');

try {
    echo "\n1. Everything works\n";
    $r = vcenterDiagnose("localhost:$portNew", 'zzuser@vsphere.local', 'zz-right');
    ok($r['overall'] === 'ok', 'overall ok (got ' . $r['overall'] . ')');
    ok(statuses($r) === ['address' => 'ok', 'dns' => 'ok', 'connect' => 'ok', 'certificate' => 'ok', 'api' => 'ok', 'signin' => 'ok', 'version' => 'ok', 'inventory' => 'ok', 'signout' => 'ok'], 'all nine steps ok');
    ok($r['summary']['vms'] === 3 && $r['summary']['vms_on'] === 2 && $r['summary']['vcpus'] === 14, 'totals: 3 VMs, 2 running, 14 vCPUs');
    ok($r['summary']['memory_gb'] == 44.0 && $r['summary']['storage_tb'] == 2.0 && $r['summary']['storage_free_tb'] == 1.0, 'totals: 44 GB memory, 2 TB storage, 1 TB free');
    ok(strpos(check($r, 'version')['detail'], '8.0.2') !== false, 'version read');
    ok(strpos(check($r, 'certificate')['detail'], 'ZZ Test VMCA') !== false, 'certificate issuer shown');
    $everything = json_encode($r);
    ok(strpos($everything, 'zz-session-7f3a9c') === false, 'session id never appears in the result');
    ok(strpos($everything, 'zz-right') === false, 'password never appears in the result');
    ok(stripos($everything, 'Basic ') === false, 'no Authorization header value in the result');
    ok(count(check($r, 'inventory')['exchanges']) === 4, 'inventory shows its four requests');

    echo "\n2. A pasted URL is cut back to the server's name\n";
    $r = vcenterDiagnose("https://localhost:$portNew/ui/", 'zzuser@vsphere.local', 'zz-right');
    ok(check($r, 'address')['status'] === 'warn', 'address warns');
    ok(strpos(check($r, 'address')['detail'], "https://localhost:$portNew.") !== false, 'calls https://localhost:port, not https://https://...');
    ok(check($r, 'signin')['status'] === 'ok', 'and still signs in');
    ok(vcenterBaseUrl("https://localhost:$portNew/ui/") === "https://localhost:$portNew", 'vcenterBaseUrl() strips scheme and path (the sync uses it)');
    ok(vcenterBaseUrl('vc.example.com') === 'https://vc.example.com', 'vcenterBaseUrl() of a plain name is unchanged');
    ok(vcenterNormaliseServer('not a host!') === null, 'nonsense is refused');

    $expectSkip = function (array $r, string $failedAt) {
        $seen = false; $okk = true;
        foreach ($r['checks'] as $c) {
            if ($c['key'] === $failedAt) { $seen = true; $okk = $okk && $c['status'] === 'fail'; continue; }
            if ($seen) $okk = $okk && $c['status'] === 'skip';
            elseif ($c['status'] === 'fail') $okk = false;
        }
        return $seen && $okk;
    };

    echo "\n3. A name that does not resolve\n";
    $r = vcenterDiagnose('vcenter.zz.invalid', 'zzuser@vsphere.local', 'zz-right');
    ok($expectSkip($r, 'dns'), 'first red line is the name lookup, the rest skipped');
    ok(check($r, 'dns')['fix'] !== '', 'says what to do');

    echo "\n4. A closed port\n";
    $r = vcenterDiagnose("127.0.0.1:$portClosed", 'zzuser@vsphere.local', 'zz-right');
    ok($expectSkip($r, 'connect'), 'first red line is the connection');
    ok(check($r, 'dns')['status'] === 'ok', 'an IP address needs no lookup');

    echo "\n5. A certificate this server does not trust\n";
    // Runs in a child process: SSL_CA_BUNDLE is a constant, and the child uses
    // the shipped bundle, which does not hold the throwaway CA.
    $child = $tmp . '/child.php';
    file_put_contents($child, '<?php require ' . var_export($root . '/includes/i18n.php', true) . '; require ' . var_export($root . '/includes/vcenter_diagnose.php', true) . '; define("SSL_VERIFY_PEER", true); define("SSL_CA_BUNDLE", sslResolveCaBundle()); echo json_encode(vcenterDiagnose("localhost:' . $portNew . '", "zzuser@vsphere.local", "zz-right"));');
    $r = json_decode((string)shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($child)), true) ?: ['checks' => []];
    ok($expectSkip($r, 'certificate'), 'first red line is the certificate');
    ok(strpos(check($r, 'certificate')['detail'] ?? '', 'ZZ Test VMCA') !== false, 'names who issued it, read without trusting it');
    ok(strpos(check($r, 'certificate')['fix'] ?? '', '/certs/download.zip') !== false, 'points at vCenter\'s certificate download');
    ok(strpos(json_encode($r), 'zz-right') === false, 'no credentials were sent to read the certificate');

    echo "\n6. An older vCenter with only /rest\n";
    $r = vcenterDiagnose("localhost:$portOld", 'zzuser@vsphere.local', 'zz-right');
    ok(check($r, 'api')['status'] === 'fail', 'vCenter API fails: the sync needs /api');
    ok(check($r, 'signin')['status'] === 'ok' && check($r, 'inventory')['status'] === 'ok', 'but sign-in and inventory still run through /rest');
    ok($r['summary']['vms'] === 3, 'and the /rest {value: ...} answers are unwrapped');

    echo "\n7. A wrong password\n";
    $r = vcenterDiagnose("localhost:$portNew", 'zzuser@vsphere.local', 'zz-wrong');
    ok($expectSkip($r, 'signin'), 'first red line is the sign-in');
    ok(strpos(check($r, 'signin')['fix'], 'administrator@vsphere.local') !== false, 'suggests the user@domain form');
    ok(strpos(json_encode($r), 'zz-wrong') === false, 'the wrong password is not echoed either');

    echo "\n8. A user who sees no VMs\n";
    $r = vcenterDiagnose("localhost:$portEmpty", 'zzempty@vsphere.local', 'zz-right');
    ok(check($r, 'inventory')['status'] === 'warn', 'inventory warns');
    ok(strpos(check($r, 'inventory')['fix'], 'Read-only') !== false, 'says which role to give');
    ok($r['overall'] === 'warn', 'overall is a warning, not a failure');
} finally {
    foreach ($procs as $p) { proc_terminate($p); proc_close($p); }
    array_map('unlink', glob($tmp . '/*'));
    @rmdir($tmp);
}

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
