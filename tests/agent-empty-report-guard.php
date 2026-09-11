<?php
/**
 * An empty agent report must not empty the machine's inventory.
 *
 * 🔴 THE BUG THIS GUARDS. Both ingest endpoints wipe an asset's hardware tables
 * and reinsert on every report. The DELETE ran unconditionally while the INSERT
 * was guarded by `!empty(...)`, so a report that collected nothing for a
 * category emptied that category and left it empty — a WMI blip taking 226
 * Device Manager rows with it, with no error anywhere, until the next good run.
 * Any client holding an API key could do it deliberately with `{"disks":{}}`.
 *
 * An empty list now means "I did not find out", not "there is nothing there":
 * the wipe is skipped and the section is NAMED in the response, because looking
 * like a successful sync of nothing is the other half of the bug.
 *
 * ⚠️ Touches the database. Everything it makes is prefixed ZZEG and removed in
 * the cleanup at the bottom, including on failure.
 *
 * Run:  php tests/agent-empty-report-guard.php
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';

$pass = 0; $fail = 0;
function ok(string $what, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  PASS  {$what}\n"; }
    else       { $fail++; echo "  FAIL  {$what}" . ($detail ? "  <- {$detail}" : '') . "\n"; }
}

echo "\nAn empty report must not empty the inventory\n" . str_repeat('=', 70) . "\n";

$conn = connectToDatabase();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$base   = getenv('FREEITSM_BASE_URL') ?: 'http://localhost/freeitsm-app';
$host   = 'ZZEG-HOST-01';
$apikey = 'ZZEG-' . bin2hex(random_bytes(8));
$madeKeyId = null; $assetId = 0;

function post(string $url, string $key, array $payload): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: ' . $key],
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20,
    ]);
    $body = (string)curl_exec($ch); curl_close($ch);
    return ['body' => $body, 'json' => json_decode($body, true)];
}

$full = [
    'hostname' => $host,
    'disks' => [
        'logical'  => [['drive' => 'C:', 'label' => 'Windows', 'file_system' => 'NTFS',
                        'size_bytes' => 500107862016, 'free_bytes' => 120000000000, 'used_percent' => 76.0]],
        'physical' => [['model' => 'ZZEG SSD', 'serial' => 'ZZEG-SERIAL-1',
                        'size_bytes' => 512105932800, 'media_type' => 'SSD', 'interface' => 'SCSI']],
    ],
    'network_adapters' => [['name' => 'ZZEG NIC', 'mac_address' => '00:11:22:33:44:55',
                            'ip_addresses' => ['10.0.0.9'], 'dhcp_enabled' => true]],
];

try {
    $conn->prepare("INSERT INTO apikeys (apikey, label, active) VALUES (?, 'ZZEG test key', 1)")->execute([$apikey]);
    $madeKeyId = (int)$conn->lastInsertId();

    $sysUrl = $base . '/api/external/system-info/submit/';
    $devUrl = $base . '/api/external/device-manager/submit/';

    // ---- A good report populates everything --------------------------------
    $r = post($sysUrl, $apikey, $full);
    $assetId = (int)($r['json']['asset_id'] ?? 0);
    ok('a full report was accepted', $assetId > 0, $r['body']);

    post($devUrl, $apikey, ['hostname' => $host, 'devices' => [
        ['device_class' => 'Display adapters', 'device_name' => 'ZZEG GPU', 'status' => 'OK'],
        ['device_class' => 'System devices',   'device_name' => 'ZZEG Bus', 'status' => 'OK'],
    ]]);

    $count = function (string $table, string $col) use ($conn, &$assetId): int {
        return (int)$conn->query("SELECT COUNT(*) FROM {$table} WHERE {$col} = {$assetId}")->fetchColumn();
    };

    $before = [
        'asset_disks'            => $count('asset_disks', 'asset_id'),
        'asset_physical_disks'   => $count('asset_physical_disks', 'asset_id'),
        'asset_network_adapters' => $count('asset_network_adapters', 'asset_id'),
        'asset_devices'          => $count('asset_devices', 'asset_id'),
    ];
    ok('the full report populated all four tables',
       $before['asset_disks'] === 1 && $before['asset_physical_disks'] === 1
       && $before['asset_network_adapters'] === 1 && $before['asset_devices'] === 2,
       json_encode($before));

    // ---- 🔴 THE ONE THAT MATTERS: an empty report ---------------------------
    // Exactly what a WMI failure produces, and exactly what a hostile client
    // would send to wipe somebody's inventory.
    $r = post($sysUrl, $apikey, ['hostname' => $host, 'disks' => [], 'network_adapters' => []]);
    ok('an empty report is still accepted (it carries real asset fields)',
       ($r['json']['status'] ?? '') === 'ok', $r['body']);

    $after = [
        'asset_disks'            => $count('asset_disks', 'asset_id'),
        'asset_physical_disks'   => $count('asset_physical_disks', 'asset_id'),
        'asset_network_adapters' => $count('asset_network_adapters', 'asset_id'),
    ];
    ok('🔴 VOLUMES SURVIVED an empty report', $after['asset_disks'] === 1, json_encode($after));
    ok('🔴 DRIVES SURVIVED an empty report', $after['asset_physical_disks'] === 1, json_encode($after));
    ok('🔴 ADAPTERS SURVIVED an empty report', $after['asset_network_adapters'] === 1, json_encode($after));

    // Silence was the other half of the bug.
    $lu = $r['json']['left_unchanged'] ?? [];
    ok('the response NAMES what it left alone',
       in_array('disks', $lu, true) && in_array('physical_disks', $lu, true)
       && in_array('network_adapters', $lu, true),
       json_encode($lu));

    // ---- The same for Device Manager ---------------------------------------
    $r = post($devUrl, $apikey, ['hostname' => $host, 'devices' => []]);
    ok('🔴 DEVICES SURVIVED an empty report', $count('asset_devices', 'asset_id') === 2,
       'device rows: ' . $count('asset_devices', 'asset_id'));
    ok('the device response says it left the list alone',
       ($r['json']['devices_left_unchanged'] ?? null) === true, $r['body']);

    // A payload with the keys missing entirely, not merely empty.
    post($sysUrl, $apikey, ['hostname' => $host]);
    post($devUrl, $apikey, ['hostname' => $host]);
    ok('absent keys are treated the same as empty ones',
       $count('asset_disks', 'asset_id') === 1 && $count('asset_devices', 'asset_id') === 2);

    // ---- A REAL report must still replace, not accumulate ------------------
    // The guard must not have turned delete-and-reinsert into append-forever.
    $changed = $full;
    $changed['disks']['logical'] = [
        ['drive' => 'C:', 'label' => 'Windows', 'file_system' => 'NTFS',
         'size_bytes' => 500107862016, 'free_bytes' => 90000000000, 'used_percent' => 82.0],
        ['drive' => 'D:', 'label' => 'Data', 'file_system' => 'NTFS',
         'size_bytes' => 250000000000, 'free_bytes' => 200000000000, 'used_percent' => 20.0],
    ];
    post($sysUrl, $apikey, $changed);
    ok('a real report still REPLACES rather than accumulating',
       $count('asset_disks', 'asset_id') === 2, 'rows: ' . $count('asset_disks', 'asset_id'));

    post($devUrl, $apikey, ['hostname' => $host, 'devices' => [
        ['device_class' => 'Display adapters', 'device_name' => 'ZZEG GPU v2', 'status' => 'OK'],
    ]]);
    ok('a real device report still replaces the whole list',
       $count('asset_devices', 'asset_id') === 1, 'rows: ' . $count('asset_devices', 'asset_id'));

} finally {
    if ($assetId) {
        foreach (['asset_physical_disks', 'asset_disks', 'asset_network_adapters', 'asset_devices', 'asset_disk_hide_rules'] as $t) {
            try { $conn->prepare("DELETE FROM {$t} WHERE asset_id = ?")->execute([$assetId]); } catch (Throwable $e) {}
        }
        try { $conn->prepare("DELETE FROM software_inventory_detail WHERE host_id = ?")->execute([$assetId]); } catch (Throwable $e) {}
        try { $conn->prepare("DELETE FROM assets WHERE id = ? AND hostname LIKE 'ZZEG-%'")->execute([$assetId]); } catch (Throwable $e) {}
    }
    if ($madeKeyId) {
        try { $conn->prepare("DELETE FROM api_rate_limits WHERE apikey_id = ?")->execute([$madeKeyId]); } catch (Throwable $e) {}
        try { $conn->prepare("DELETE FROM apikeys WHERE id = ?")->execute([$madeKeyId]); } catch (Throwable $e) {}
    }
    $left = (int)$conn->query("SELECT COUNT(*) FROM assets WHERE hostname LIKE 'ZZEG-%'")->fetchColumn();
    echo "  cleanup: {$left} ZZEG assets left\n";
}

echo str_repeat('-', 70) . "\n";
echo "  {$pass} passed, {$fail} failed\n\n";
exit($fail === 0 ? 0 : 1);
