<?php
/* 🔴 NEVER OVER THE WEB. A test writes to the real tables and only tidies up if it runs
   to the end. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * GH #199: the Hyper-V collector, end to end, without a Hyper-V estate.
 *
 * tests/hyperv-collector/Mock-HyperV.ps1 runs the REAL scripts/Invoke-HyperVInventory.ps1
 * with the Hyper-V and Failover Clustering cmdlets replaced by functions describing a small
 * synthetic estate. Its reports then go through the REAL ingest (VirtualEstateService) on a
 * throwaway connection. So this proves the collector and FreeITSM agree about the report -
 * the one thing a unit test on either side alone cannot. It does NOT prove the collector
 * against real Hyper-V: the cmdlets' real output can only be seen on a real estate.
 *
 * Windows only (needs powershell.exe); skipped elsewhere.
 *
 * Run:  php tests/hyperv-collector.php
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/services/virtual_estate.php';

$pass = 0; $fail = 0;
function ok(string $what, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  PASS  {$what}\n"; }
    else       { $fail++; echo "  FAIL  {$what}" . ($detail ? "  <- {$detail}" : '') . "\n"; }
}
echo "\nHyper-V collector against mocked cmdlets (GH #199)\n" . str_repeat('=', 70) . "\n";
if (PHP_OS_FAMILY !== 'Windows') { echo "  SKIP  needs Windows PowerShell\n"; exit(0); }

$conn = connectToDatabase();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'zzhv-' . bin2hex(random_bytes(3));
@mkdir($dir);
$keyFile = getenv('ProgramData') . '\\FreeITSM\\redact.key';
$keyExisted = is_file($keyFile);
$cids = [];
$mock = realpath(__DIR__ . '/hyperv-collector/Mock-HyperV.ps1');

function runMock(string $mock, array $args): array {
    $cmd = 'powershell.exe -NoProfile -ExecutionPolicy Bypass -File ' . escapeshellarg($mock) . ' ' . implode(' ', $args) . ' 2>&1';
    exec($cmd, $out, $code);
    return [$code, implode("\n", $out)];
}

try {
    $mkConn = function () use ($conn, &$cids): int {
        $conn->prepare("INSERT INTO virt_connections (name, source_kind, created_datetime) VALUES (?, 'hyperv', UTC_TIMESTAMP())")->execute(['ZZHV-' . bin2hex(random_bytes(3))]);
        return $cids[] = (int)$conn->lastInsertId();
    };

    // ---- A plain report ------------------------------------------------------------------
    [$code, $out] = runMock($mock, ['-Out', escapeshellarg("$dir\\plain.json")]);
    $r = json_decode((string)@file_get_contents("$dir\\plain.json"), true);
    ok('the collector runs and writes a report', $code === 0 && is_array($r), "exit $code: " . substr($out, 0, 300));
    ok('...naming the host it could not read', ($r['unreadable'][0]['name'] ?? '') === 'HV-DENIED');
    $reportedVms = array_column($r['sections']['vms']['items'] ?? [], null, 'name');
    ok('guest OS comes from the matching VM, not the host OS or the first KVP component',
        ($reportedVms['VM-HA1']['os_name'] ?? null) === 'Microsoft Windows Server 2019 Standard');
    ok('a Linux guest is read independently of the Windows host',
        ($reportedVms['VM-PLAIN']['os_name'] ?? null) === 'Ubuntu 24.04 LTS');
    ok('malformed, unrelated and blank KVP data leaves the guest OS explicitly unknown',
        isset($reportedVms['VM-S1']) && array_key_exists('os_name', $reportedVms['VM-S1']) && $reportedVms['VM-S1']['os_name'] === null);
    $cid = $mkConn();
    $res = VirtualEstateService::submit($conn, $cid, $r);
    ok('🔴 FreeITSM accepts the collector\'s report as it is', $res['outcome'] === 'applied', json_encode($res['sections']));
    $q = fn(string $sql) => $conn->query($sql)->fetchColumn();
    ok('...a cluster, three hosts (by hardware UUID), three VMs', (int)$q("SELECT COUNT(*) FROM virt_clusters WHERE connection_id = $cid") === 1
        && (int)$q("SELECT COUNT(*) FROM virt_hosts WHERE connection_id = $cid AND identity_kind = 'source'") === 3
        && (int)$q("SELECT COUNT(*) FROM virt_vms WHERE connection_id = $cid") === 3);
    $ha = $conn->query("SELECT * FROM virt_vms WHERE connection_id = $cid AND name = 'VM-HA1'")->fetch(PDO::FETCH_ASSOC);
    ok('the clustered VM is highly available, its role known, both nodes its possible owners', (int)$ha['is_clustered'] === 1 && $ha['cluster_role_id'] !== null
        && count(json_decode((string)$ha['possible_owners_json'], true) ?: []) === 2, json_encode($ha));
    ok('...and the plain one is not', (int)$q("SELECT is_clustered FROM virt_vms WHERE connection_id = $cid AND name = 'VM-PLAIN'") === 0);
    ok('disks with their sizes, adapters with their addresses', (int)$q("SELECT size_bytes FROM virt_vm_disks d JOIN virt_vms v ON v.id = d.vm_id WHERE v.id = {$ha['id']}") === 136365211648
        && $q("SELECT ip_addresses FROM virt_vm_networks WHERE vm_id = {$ha['id']}") === '10.0.0.6, fe80::1');
    ok('the cluster\'s quorum is recorded', $q("SELECT quorum_type FROM virt_clusters WHERE connection_id = $cid") === 'NodeAndFileShareMajority');
    ok('the existing ingest stores both guest OS names', $ha['os_name'] === 'Microsoft Windows Server 2019 Standard'
        && $q("SELECT os_name FROM virt_vms WHERE connection_id = $cid AND name = 'VM-PLAIN'") === 'Ubuntu 24.04 LTS');

    // Optional KVP access must not turn an otherwise readable host into an unreadable one.
    [$code, $out] = runMock($mock, ['-Out', escapeshellarg("$dir\\guest-denied.json"), '-NoGuestKvp']);
    $denied = json_decode((string)@file_get_contents("$dir\\guest-denied.json"), true);
    $deniedVms = array_column($denied['sections']['vms']['items'] ?? [], null, 'name');
    ok('a refused KVP read keeps the host and VM inventory, with an unknown guest OS', $code === 0
        && count($denied['sections']['hosts']['items'] ?? []) === 3 && count($deniedVms) === 3
        && count($denied['unreadable'] ?? []) === 1 && ($denied['unreadable'][0]['name'] ?? '') === 'HV-DENIED'
        && isset($deniedVms['VM-HA1']) && array_key_exists('os_name', $deniedVms['VM-HA1']) && $deniedVms['VM-HA1']['os_name'] === null
        && ($deniedVms['VM-PLAIN']['os_name'] ?? null) === 'Ubuntu 24.04 LTS');

    // ---- Redacted ------------------------------------------------------------------------
    [$code] = runMock($mock, ['-Out', escapeshellarg("$dir\\redacted.json"), '-Redact']);
    $raw = (string)@file_get_contents("$dir\\redacted.json");
    $leaks = [];
    foreach (['CL-MOCK', 'HV-N1', 'HV-DENIED', 'VM-HA1', '10.0.0.6', '00155D010203', 'ClusterStorage', 'vSwitch-Prod', 'aaaaaaaa-0000', '4c4c4544-0031', 'Access is denied', 'ZZ-GUEST-FQDN.example.invalid', php_uname('n')] as $secret) {
        if (stripos($raw, $secret) !== false) { $leaks[] = $secret; }
    }
    ok('🔴 -Redact leaves no name, address, MAC, path, identifier or error text', $code === 0 && $raw !== '' && !$leaks, implode(', ', $leaks));
    $cid2 = $mkConn();
    $res = VirtualEstateService::submit($conn, $cid2, json_decode($raw, true));
    ok('...and the redacted report is still a valid one', $res['outcome'] === 'applied' && (int)$q("SELECT COUNT(*) FROM virt_vms WHERE connection_id = $cid2") === 3);
    runMock($mock, ['-Out', escapeshellarg("$dir\\redacted2.json"), '-Redact']);
    $again = json_decode((string)@file_get_contents("$dir\\redacted2.json"), true);
    ok('...with the same placeholder for the same VM every run', ($again['sections']['vms']['items'][0]['name'] ?? 'a') === (json_decode($raw, true)['sections']['vms']['items'][0]['name'] ?? 'b'));

    // ---- One report per cluster ------------------------------------------------------------
    [$code] = runMock($mock, ['-Out', escapeshellarg("$dir\\per.json"), '-ReportPer', 'Cluster']);
    $files = glob("$dir\\per-*.json");
    ok('-ReportPer Cluster writes one report per cluster and one for the standalone hosts', $code === 0 && count($files) === 2, implode(',', array_map('basename', $files)));
    $cid3 = $mkConn();
    $outcomes = [];
    foreach ($files as $f) { $outcomes[] = VirtualEstateService::submit($conn, $cid3, json_decode(file_get_contents($f), true))['outcome']; }
    ok('...each applies on its own scope, together making the whole estate', $outcomes === ['applied', 'applied'] && (int)$q("SELECT COUNT(*) FROM virt_vms WHERE connection_id = $cid3") === 3, implode(',', $outcomes));

    // ---- Sending, as the scheduled task does ---------------------------------------------------
    $cid4 = $mkConn();
    $conn->beginTransaction(); $key = VirtualEstateService::mintKey($conn, 1, $cid4); $conn->commit();
    $base = getenv('FREEITSM_BASE_URL') ?: 'http://localhost/freeitsm-app';
    [$code, $out] = runMock($mock, ['-ApiUrl', escapeshellarg($base), '-ConnectionId', (string)$cid4, '-ApiKey', escapeshellarg($key), '-ReportPer', 'Cluster']);
    ok('🔴 the collector sends its reports with the connection\'s key, and they apply', $code === 0 && substr_count($out, ': applied') === 2
        && (int)$q("SELECT COUNT(*) FROM virt_vms WHERE connection_id = $cid4") === 3, "exit $code: " . substr($out, 0, 400));
    $kid = (int)$q("SELECT api_key_id FROM virt_connections WHERE id = $cid4");
    // ---- -TestAccess -------------------------------------------------------------------------
    [$code, $out] = runMock($mock, ['-TestAccess']);
    ok('-TestAccess names each refused read and exits 2, sending nothing', $code === 2 && str_contains($out, 'REFUSED') && str_contains($out, 'HV-DENIED'), "exit $code");
} catch (Throwable $e) {
    $fail++;
    echo "  FAIL  unexpected: " . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . "\n";
} finally {
    foreach ($cids as $id) {
        try { $conn->prepare('DELETE FROM job_runs WHERE job_key = ?')->execute(['virt_push_' . $id]); } catch (Throwable) {}
        try { $k = $conn->query('SELECT api_key_id FROM virt_connections WHERE id = ' . (int)$id)->fetchColumn(); if ($k) { $conn->prepare('DELETE FROM api_key_rate_limits WHERE api_key_id = ?')->execute([$k]); $conn->prepare('DELETE FROM api_keys WHERE id = ?')->execute([$k]); } } catch (Throwable) {}
        try { $conn->prepare("DELETE FROM virt_connections WHERE id = ? AND name LIKE 'ZZHV-%'")->execute([$id]); } catch (Throwable) {}
    }
    array_map('unlink', glob("$dir\\*") ?: []);
    @rmdir($dir);
    if (!$keyExisted && is_file($keyFile)) { @unlink($keyFile); @rmdir(dirname($keyFile)); }   // the redaction key this test made
}
echo str_repeat('-', 70) . "\n  {$pass} passed, {$fail} failed\n";
exit($fail ? 1 : 0);
