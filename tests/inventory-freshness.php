<?php
/* 🔴 NEVER OVER THE WEB. A test writes to the real tables and only tidies up if it runs
   to the end. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * GH #200 Part B: every agent section records what it came to - succeeded, empty, skipped
 * or failed - through the SAME service the REST API uses (the #194 follow-up), and the
 * asset page can read it back.
 *
 * Real HTTP against the agent's three endpoints and the page's status endpoint. Everything
 * is prefixed ZZIF and removed in `finally`: one asset (made by the first agent report),
 * two applications put in the catalogue first (so nothing is "discovered" and no workflow
 * fires), one agent key, one forged admin session. The stale-days setting is snapshotted
 * and put back.
 *
 * Run:  php tests/inventory-freshness.php   (FREEITSM_BASE_URL, default http://localhost/freeitsm-app)
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/services/asset_inventory.php';

$pass = 0; $fail = 0;
function ok(string $what, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  PASS  {$what}\n"; }
    else       { $fail++; echo "  FAIL  {$what}" . ($detail ? "  <- {$detail}" : '') . "\n"; }
}

echo "\nInventory freshness: one implementation, an outcome per section\n" . str_repeat('=', 70) . "\n";

$conn = connectToDatabase();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
if (!AssetInventoryService::hasOutcomeColumns($conn)) { fwrite(STDERR, "Run Database Verification first.\n"); exit(1); }

$base    = getenv('FREEITSM_BASE_URL') ?: 'http://localhost/freeitsm-app';
$host    = 'ZZIF-HOST-01';
$apikey  = 'ZZIF-' . bin2hex(random_bytes(8));
$sid     = 'zzif' . bin2hex(random_bytes(4));
$sessFile = rtrim(ini_get('session.save_path') ?: 'c:/wamp64/tmp', "/\\") . '/sess_' . $sid;
$madeKeyId = null; $assetId = 0; $appIds = [];
$settingBefore = false;

function post(string $url, string $key, array $payload): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: ' . $key],
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30,
    ]);
    $body = (string)curl_exec($ch);
    return ['body' => $body, 'json' => json_decode($body, true)];
}

try {
    $conn->prepare("INSERT INTO apikeys (apikey, label, active) VALUES (?, 'ZZIF test key', 1)")->execute([$apikey]);
    $madeKeyId = (int)$conn->lastInsertId();
    foreach (['ZZIF App One', 'ZZIF App Two'] as $name) {
        $conn->prepare("INSERT INTO software_inventory_apps (display_name, publisher, source) VALUES (?, 'ZZIF', 'manual')")->execute([$name]);
        $appIds[] = (int)$conn->lastInsertId();
    }
    $sysUrl = $base . '/api/external/system-info/submit/';
    $devUrl = $base . '/api/external/device-manager/submit/';
    $swUrl  = $base . '/api/external/software-inventory/submit/';

    $software = [['display_name' => 'ZZIF App One', 'publisher' => 'ZZIF', 'display_version' => '1.0'],
                 ['display_name' => 'ZZIF App Two', 'publisher' => 'ZZIF', 'display_version' => '2.0']];
    $full = [
        'report_version' => 2, 'collection_failures' => new stdClass(), 'hostname' => $host,
        'disks' => [
            'logical'  => [['drive' => 'C:', 'label' => 'Windows', 'file_system' => 'NTFS', 'size_bytes' => 1000, 'free_bytes' => 400, 'used_percent' => 60]],
            'physical' => [['model' => 'ZZIF SSD', 'serial' => 'ZZIF-1', 'size_bytes' => 1000, 'media_type' => 'SSD', 'interface' => 'NVMe']],
        ],
        // Two adapters sharing a MAC (Hyper-V does this): both kept, told apart.
        'network_adapters' => [['name' => 'ZZIF NIC', 'mac_address' => '00:11:22:33:44:55', 'ip_addresses' => ['fe80::1', '10.0.0.9'], 'dhcp_enabled' => true],
                               ['name' => 'ZZIF vNIC', 'mac_address' => '00:11:22:33:44:55', 'ip_addresses' => ['10.0.0.10']]],
        'software' => $software,
    ];
    $section = function (string $name) use ($conn, &$assetId) {
        $st = $conn->prepare("SELECT x.* FROM asset_inventory_sections x JOIN asset_inventory_sources s ON s.id = x.inventory_source_id
            WHERE s.asset_id = ? AND s.source = 'agent' AND x.section_name = ?");
        $st->execute([$assetId, $name]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: [];
    };
    $count = function (string $table, string $col = 'asset_id', string $where = '') use ($conn, &$assetId): int {
        return (int)$conn->query("SELECT COUNT(*) FROM {$table} WHERE {$col} = {$assetId}{$where}")->fetchColumn();
    };

    // ---- A full report: every section succeeded, through the shared service -------------
    $r = post($sysUrl, $apikey, $full);
    $assetId = (int)($r['json']['asset_id'] ?? 0);
    ok('a version 2 report is accepted', $assetId > 0 && ($r['json']['status'] ?? '') === 'ok', $r['body']);
    $src = $conn->query("SELECT source_device_ref, tenant_id FROM asset_inventory_sources WHERE asset_id = $assetId AND source = 'agent'")->fetch(PDO::FETCH_ASSOC);
    ok('the agent is linked as a source of this asset', $src && $src['source_device_ref'] === 'asset:' . $assetId, json_encode($src));
    foreach (['software', 'disks', 'physical_disks', 'network_adapters'] as $name) {
        $s = $section($name);
        ok("$name: succeeded, observed now, imported by no analyst", ($s['last_outcome'] ?? '') === 'succeeded' && $s['observed_at'] !== null
            && $s['imported_by_id'] === null && $s['fingerprint'] !== null, json_encode($s));
    }
    ok('volumes carry an identity now (the drive letter) and their observation time',
        (int)$conn->query("SELECT COUNT(*) FROM asset_disks WHERE asset_id = $assetId AND source = 'agent' AND external_id = 'C:' AND observed_at IS NOT NULL")->fetchColumn() === 1);
    ok('two adapters sharing a MAC are both kept', $count('asset_network_adapters') === 2
        && (int)$conn->query("SELECT COUNT(DISTINCT external_id) FROM asset_network_adapters WHERE asset_id = $assetId")->fetchColumn() === 2);
    ok('...and the first IPv4 address is the one kept', $conn->query("SELECT ip_address FROM asset_network_adapters WHERE asset_id = $assetId AND name = 'ZZIF NIC'")->fetchColumn() === '10.0.0.9');
    ok('software attached to the existing catalogue rows (nothing discovered)', $count('software_inventory_detail', 'host_id', " AND source = 'agent' AND app_id IN ({$appIds[0]}, {$appIds[1]})") === 2);
    ok('...in UTC, as the agent always wrote it', abs(strtotime((string)$conn->query("SELECT created_at FROM software_inventory_detail WHERE host_id = $assetId LIMIT 1")->fetchColumn() . ' UTC') - time()) < 120);

    // ---- Rows written before volumes had an identity are replaced, not left beside --------
    $conn->prepare("INSERT INTO asset_disks (asset_id, drive, source) VALUES (?, 'Q:', 'agent')")->execute([$assetId]);
    sleep(1);
    post($sysUrl, $apikey, $full);
    ok('an old agent volume with no identity is replaced by the next report', $count('asset_disks', 'asset_id', " AND source = 'agent'") === 1);
    ok('...and the volume row itself survived (updated in place)', (int)$conn->query("SELECT COUNT(*) FROM asset_disks WHERE asset_id = $assetId AND drive = 'C:'")->fetchColumn() === 1);

    // ---- Device Manager -------------------------------------------------------------------
    $r = post($devUrl, $apikey, ['report_version' => 2, 'hostname' => $host, 'devices' => [['device_class' => 'Display', 'device_name' => 'ZZIF GPU', 'status' => 'OK', 'driver_date' => '2026-01-02']]]);
    ok('devices: applied and recorded', ($r['json']['devices_synced'] ?? 0) === 1 && ($section('devices')['last_outcome'] ?? '') === 'succeeded', $r['body']);

    // ---- A failed collection keeps the data and its observation time ----------------------
    $firstObserved = $section('disks')['observed_at'];
    sleep(1);
    $failed = $full; $failed['disks']['logical'] = []; $failed['collection_failures'] = ['disks' => 'Could not read volumes: RPC server unavailable'];
    $r = post($sysUrl, $apikey, $failed);
    $s = $section('disks');
    ok('a failed section is recorded as failed, with its error', ($s['last_outcome'] ?? '') === 'failed' && str_contains((string)$s['last_error'], 'RPC'), json_encode($s));
    ok('🔴 ...its volumes were KEPT', $count('asset_disks', 'asset_id', " AND source = 'agent'") === 1);
    ok('🔴 ...and their observation time did NOT move (old data never looks new)', $s['observed_at'] === $firstObserved, $s['observed_at'] . ' vs ' . $firstObserved);
    ok('...the response says partial and names it', ($r['json']['status'] ?? '') === 'partial' && in_array('disks', $r['json']['left_unchanged'] ?? [], true), $r['body']);
    ok('...while the other sections still applied', ($section('software')['last_outcome'] ?? '') === 'succeeded' && $section('software')['observed_at'] > $firstObserved);

    // ---- Left out = skipped ---------------------------------------------------------------
    $skipped = $full; unset($skipped['network_adapters']);
    post($sysUrl, $apikey, $skipped);
    ok('a section left out of a version 2 report is recorded as skipped', ($section('network_adapters')['last_outcome'] ?? '') === 'skipped');
    ok('...and its adapters were kept', $count('asset_network_adapters') === 2);
    ok('a success after a failure clears the error', ($section('disks')['last_outcome'] ?? '') === 'succeeded' && $section('disks')['last_error'] === null);

    // ---- Empty from a version 2 agent is real; from an older agent it is not --------------
    $old = ['hostname' => $host, 'software' => [], 'disks' => ['logical' => $full['disks']['logical']]];
    $r = post($sysUrl, $apikey, $old);
    ok('🔴 an OLDER agent\'s empty software list is skipped - the list is kept',
        $count('software_inventory_detail', 'host_id', " AND source = 'agent'") === 2 && ($section('software')['last_outcome'] ?? '') === 'skipped', $r['body']);
    $empty = $full; $empty['software'] = [];
    post($sysUrl, $apikey, $empty);
    ok('a version 2 agent\'s empty software list is believed: "reported empty"',
        ($section('software')['last_outcome'] ?? '') === 'empty' && $count('software_inventory_detail', 'host_id', " AND source = 'agent'") === 0);

    // ---- The older software-only endpoint goes through the same service -------------------
    $r = post($swUrl, $apikey, ['Hostname' => $host, 'Software' => [['Display Name' => 'ZZIF App One', 'Publisher' => 'ZZIF', 'Display Version' => '1.1']]]);
    ok('the software-only endpoint applies through the service', ($r['json']['inserted_details'] ?? -1) === 1 && ($section('software')['last_outcome'] ?? '') === 'succeeded', $r['body']);
    $r = post($swUrl, $apikey, ['Hostname' => $host, 'Software' => []]);
    ok('🔴 ...and its empty list is skipped, not a wipe', $count('software_inventory_detail', 'host_id', " AND source = 'agent'") === 1
        && in_array('software', $r['json']['left_unchanged'] ?? [], true), $r['body']);

    // ---- Device Manager failure -----------------------------------------------------------
    $r = post($devUrl, $apikey, ['report_version' => 2, 'hostname' => $host, 'devices' => [], 'collection_failures' => ['devices' => 'Get-PnpDevice failed']]);
    ok('a failed Device Manager collection keeps the devices and is recorded',
        $count('asset_devices') === 1 && ($section('devices')['last_outcome'] ?? '') === 'failed' && ($r['json']['devices_left_unchanged'] ?? false) === true, $r['body']);

    // ---- What the asset page reads ----------------------------------------------------------
    file_put_contents($sessFile, 'analyst_id|i:1;analyst_name|s:13:"Administrator";is_admin|i:1;');
    $ch = curl_init($base . '/api/assets/get_asset_inventory_status.php?asset_id=' . $assetId);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIE => 'PHPSESSID=' . $sid, CURLOPT_TIMEOUT => 20]);
    $body = (string)curl_exec($ch);
    $st = json_decode($body, true);
    $agent = $st['sources'][0] ?? [];
    ok('the status endpoint lists the agent, with no device reference shown', ($st['success'] ?? false) && ($agent['source'] ?? '') === 'agent' && $agent['source_device_ref'] === null, $body);
    ok('...each section\'s outcome and times', ($agent['sections']['devices']['last_outcome'] ?? '') === 'failed'
        && ($agent['sections']['devices']['last_error'] ?? '') === 'Get-PnpDevice failed' && str_ends_with((string)($agent['sections']['disks']['observed_at'] ?? ''), 'Z'), $body);
    ok('...and the rows each source holds', ($st['rows']['agent']['devices'] ?? 0) === 1 && ($st['rows']['agent']['network_adapters'] ?? 0) === 2, $body);

    // ---- The staleness window ---------------------------------------------------------------
    $settingBefore = $conn->query("SELECT setting_value FROM system_settings WHERE setting_key = 'asset_inventory_stale_days'")->fetchColumn();
    $expected = ($settingBefore !== false && (int)$settingBefore >= 1 && (int)$settingBefore <= 365) ? (int)$settingBefore : 7;
    ok('stale after 7 days by default (or as set)', AssetInventoryService::staleDays($conn) === $expected);
    $conn->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES ('asset_inventory_stale_days', '30') ON DUPLICATE KEY UPDATE setting_value = '30'")->execute();
    ok('...a setting of 30 is honoured', AssetInventoryService::staleDays($conn) === 30);
    $conn->exec("UPDATE system_settings SET setting_value = '0' WHERE setting_key = 'asset_inventory_stale_days'");
    ok('...a nonsense value falls back to 7', AssetInventoryService::staleDays($conn) === 7);

} finally {
    if ($settingBefore === false) {
        $conn->exec("DELETE FROM system_settings WHERE setting_key = 'asset_inventory_stale_days'");
    } else {
        $conn->prepare("UPDATE system_settings SET setting_value = ? WHERE setting_key = 'asset_inventory_stale_days'")->execute([$settingBefore]);
    }
    if ($assetId) {
        try { $conn->prepare("DELETE FROM asset_inventory_sources WHERE asset_id = ?")->execute([$assetId]); } catch (Throwable $e) {}
        foreach (['asset_physical_disks', 'asset_disks', 'asset_network_adapters', 'asset_devices', 'asset_history'] as $t) {
            try { $conn->prepare("DELETE FROM {$t} WHERE asset_id = ?")->execute([$assetId]); } catch (Throwable $e) {}
        }
        try { $conn->prepare("DELETE FROM software_inventory_detail WHERE host_id = ?")->execute([$assetId]); } catch (Throwable $e) {}
        try { $conn->prepare("DELETE FROM software_inventory_log WHERE host_id = ?")->execute([$assetId]); } catch (Throwable $e) {}
        try { $conn->prepare("DELETE FROM assets WHERE id = ? AND hostname LIKE 'ZZIF-%'")->execute([$assetId]); } catch (Throwable $e) {}
    }
    foreach ($appIds as $appId) {
        try { $conn->prepare("DELETE FROM software_inventory_detail WHERE app_id = ?")->execute([$appId]); } catch (Throwable $e) {}
        try { $conn->prepare("DELETE FROM software_inventory_apps WHERE id = ?")->execute([$appId]); } catch (Throwable $e) {}
    }
    if ($madeKeyId) {
        try { $conn->prepare("DELETE FROM api_rate_limits WHERE apikey_id = ?")->execute([$madeKeyId]); } catch (Throwable $e) {}
        try { $conn->prepare("DELETE FROM apikeys WHERE id = ?")->execute([$madeKeyId]); } catch (Throwable $e) {}
    }
    @unlink($sessFile);
    $left = (int)$conn->query("SELECT COUNT(*) FROM assets WHERE hostname LIKE 'ZZIF-%'")->fetchColumn()
          + (int)$conn->query("SELECT COUNT(*) FROM software_inventory_apps WHERE display_name LIKE 'ZZIF %'")->fetchColumn();
    echo "  cleanup: {$left} ZZIF rows left\n";
}

echo str_repeat('-', 70) . "\n";
echo "  {$pass} passed, {$fail} failed\n\n";
exit($fail === 0 ? 0 : 1);
