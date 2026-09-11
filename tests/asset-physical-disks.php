<?php
/**
 * Physical disks reported by the inventory agent (discussion #97).
 *
 * The agent has sent `disks.physical` — model, serial, size, media type,
 * interface — since its first version, and api/external/system-info/submit/
 * read only `disks.logical` and dropped the rest. This drives the REAL ingest
 * endpoint over HTTP with a real agent-shaped payload and checks what actually
 * lands in the database, then reads it back the way the asset screen does.
 *
 * ⚠️ Touches the database. Everything it makes is prefixed ZZPD and removed in
 * the cleanup at the bottom, including on failure — an asset, an API key and
 * whatever rows the endpoint writes against them.
 *
 * Run:  php tests/asset-physical-disks.php
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';

$pass = 0; $fail = 0;
function ok(string $what, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  PASS  {$what}\n"; }
    else       { $fail++; echo "  FAIL  {$what}" . ($detail ? "  <- {$detail}" : '') . "\n"; }
}

echo "\nPhysical disks from the inventory agent (#97)\n" . str_repeat('=', 70) . "\n";

$conn = connectToDatabase();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// The endpoint is reached over HTTP because that is how the agent reaches it —
// auth header, JSON body and all. A direct include would skip the half of this
// that has historically gone wrong.
$base   = getenv('FREEITSM_BASE_URL') ?: 'http://localhost/freeitsm-app';
$host   = 'ZZPD-AGENT-01';
$apikey = 'ZZPD-' . bin2hex(random_bytes(8));

$madeKeyId  = null;
$madeAssets = [];

/** POST JSON to an endpoint with the agent's Authorization header. */
function agentPost(string $url, string $key, array $payload): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Authorization: ' . $key],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    return ['code' => $code, 'body' => (string)$body, 'json' => json_decode((string)$body, true), 'err' => $err];
}

try {
    // ---- Fixtures ----------------------------------------------------------
    $conn->prepare("INSERT INTO apikeys (apikey, label, active) VALUES (?, 'ZZPD test key', 1)")
         ->execute([$apikey]);
    $madeKeyId = (int)$conn->lastInsertId();

    // ---- A first report creates the asset and its drives -------------------
    $payload = [
        'hostname'     => $host,
        'manufacturer' => 'ZZPD Systems',
        'model'        => 'ZZPD Box',
        'service_tag'  => 'ZZPD-SERIAL-1',
        'disks'        => [
            'logical'  => [
                ['drive' => 'C:', 'label' => 'Windows', 'file_system' => 'NTFS',
                 'size_bytes' => 500107862016, 'free_bytes' => 120000000000, 'used_percent' => 76.0],
            ],
            'physical' => [
                ['model' => 'Samsung SSD 980 1TB', 'serial' => 'S5GXNX0T123456',
                 'size_bytes' => 1000204886016, 'media_type' => 'SSD', 'interface' => 'SCSI'],
                // No serial — a USB enclosure or a VM disk. Still worth a row.
                ['model' => 'ZZPD Virtual Disk', 'serial' => null,
                 'size_bytes' => 68719476736, 'media_type' => 'Fixed hard disk media', 'interface' => 'IDE'],
                // Entirely empty. Must be skipped rather than stored as a blank row.
                ['model' => '', 'serial' => '   ', 'size_bytes' => null,
                 'media_type' => '', 'interface' => ''],
            ],
        ],
    ];

    $res = agentPost($base . '/api/external/system-info/submit/', $apikey, $payload);
    ok('the ingest endpoint accepted the report', $res['code'] === 200,
       'HTTP ' . $res['code'] . ' ' . $res['err'] . ' ' . substr($res['body'], 0, 200));
    // ⚠️ A PHP fatal is served as HTTP 200, so the body is checked too.
    ok('the response is not a PHP fatal', !preg_match('/Fatal error|Uncaught/i', $res['body']),
       substr($res['body'], 0, 200));

    $assetId = isset($res['json']['asset_id']) ? (int)$res['json']['asset_id'] : 0;
    if ($assetId) { $madeAssets[] = $assetId; }
    ok('the report created an asset', $assetId > 0, $res['body']);

    ok('the response counts the physical disks it stored',
       ($res['json']['physical_disks_synced'] ?? null) === 2,
       'got ' . var_export($res['json']['physical_disks_synced'] ?? null, true));

    $rows = [];
    if ($assetId) {
        $q = $conn->prepare("SELECT model, serial, size_bytes, media_type, interface_type
                               FROM asset_physical_disks WHERE asset_id = ? ORDER BY size_bytes DESC");
        $q->execute([$assetId]);
        $rows = $q->fetchAll(PDO::FETCH_ASSOC);
    }

    ok('two rows landed, the empty one skipped', count($rows) === 2, 'got ' . count($rows));

    // 🔑 THE SERIAL IS THE WHOLE POINT of the feature. Asserted by value, not
    // by "a row exists" — a NULL here would pass a row count and fail the user.
    ok('the serial number is stored verbatim',
       ($rows[0]['serial'] ?? null) === 'S5GXNX0T123456',
       var_export($rows[0]['serial'] ?? null, true));
    ok('the model is stored', ($rows[0]['model'] ?? null) === 'Samsung SSD 980 1TB');
    ok('the size is stored', (int)($rows[0]['size_bytes'] ?? 0) === 1000204886016);
    ok('media type and interface are stored',
       ($rows[0]['media_type'] ?? null) === 'SSD' && ($rows[0]['interface_type'] ?? null) === 'SCSI');

    // A drive with no serial keeps its row and stores NULL, not ''. The screen
    // says "No serial reported" off exactly this distinction.
    ok('a drive with no serial is kept, with NULL rather than an empty string',
       count($rows) === 2 && $rows[1]['serial'] === null && $rows[1]['model'] === 'ZZPD Virtual Disk',
       var_export($rows[1] ?? null, true));

    // ---- A second report replaces, never duplicates ------------------------
    // The agent runs on a schedule; the commonest failure mode for a
    // delete-and-reinsert table is that the delete is forgotten and every run
    // doubles the list.
    $payload['disks']['physical'] = [
        ['model' => 'ZZPD Replacement SSD', 'serial' => 'ZZPD-NEW-SERIAL',
         'size_bytes' => 2000398934016, 'media_type' => 'SSD', 'interface' => 'SCSI'],
    ];
    $res2 = agentPost($base . '/api/external/system-info/submit/', $apikey, $payload);
    ok('the second report was accepted', $res2['code'] === 200 && !preg_match('/Fatal error|Uncaught/i', $res2['body']),
       'HTTP ' . $res2['code'] . ' ' . substr($res2['body'], 0, 200));
    ok('the second report reused the same asset',
       (int)($res2['json']['asset_id'] ?? 0) === $assetId);

    $q = $conn->prepare("SELECT model, serial FROM asset_physical_disks WHERE asset_id = ?");
    $q->execute([$assetId]);
    $after = $q->fetchAll(PDO::FETCH_ASSOC);
    ok('re-reporting replaces the drives rather than adding to them',
       count($after) === 1 && $after[0]['serial'] === 'ZZPD-NEW-SERIAL',
       count($after) . ' rows: ' . json_encode($after));

    // ---- last_seen, the other half of #97 ----------------------------------
    $q = $conn->prepare("SELECT first_seen, last_seen FROM assets WHERE id = ?");
    $q->execute([$assetId]);
    $seen = $q->fetch(PDO::FETCH_ASSOC);
    ok('the report stamped first_seen and last_seen',
       !empty($seen['first_seen']) && !empty($seen['last_seen']),
       json_encode($seen));

    // ---- The screen's own read path ----------------------------------------
    // get_asset_disks.php is what the Storage section calls. Driven with a
    // forged session, because an endpoint that works only for the test harness
    // is not the endpoint the user gets.
    $analystId = (int)$conn->query("SELECT id FROM analysts WHERE id = 1")->fetchColumn();
    if ($analystId) {
        $sid = 'zzpd' . bin2hex(random_bytes(4));
        $sessDir = ini_get('session.save_path') ?: 'c:/wamp64/tmp';
        $sessFile = rtrim($sessDir, "/\\") . '/sess_' . $sid;
        @file_put_contents($sessFile, 'analyst_id|i:' . $analystId . ';analyst_name|s:13:"Administrator";is_admin|i:1;');

        $ch = curl_init($base . '/api/assets/get_asset_disks.php?asset_id=' . $assetId);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_COOKIE         => 'PHPSESSID=' . $sid,
            CURLOPT_TIMEOUT        => 20,
        ]);
        $body = (string)curl_exec($ch);
        curl_close($ch);
        @unlink($sessFile);

        $data = json_decode($body, true);
        ok('get_asset_disks.php returns the physical drives to the screen',
           !empty($data['success']) && count($data['physical_disks'] ?? []) === 1
           && ($data['physical_disks'][0]['serial'] ?? null) === 'ZZPD-NEW-SERIAL',
           substr($body, 0, 300));
        ok('it still returns the logical volumes alongside them',
           count($data['disks'] ?? []) === 1 && ($data['disks'][0]['drive'] ?? null) === 'C:',
           substr($body, 0, 300));
    }

} finally {
    // ---- Cleanup -----------------------------------------------------------
    // Children first: the FK is a plain RESTRICT, so the asset will not go
    // while anything still points at it.
    foreach ($madeAssets as $id) {
        foreach (['asset_physical_disks', 'asset_disks', 'asset_network_adapters', 'asset_devices'] as $t) {
            try { $conn->prepare("DELETE FROM {$t} WHERE asset_id = ?")->execute([$id]); } catch (Throwable $e) {}
        }
        try { $conn->prepare("DELETE FROM software_inventory_detail WHERE host_id = ?")->execute([$id]); } catch (Throwable $e) {}
        try { $conn->prepare("DELETE FROM assets WHERE id = ? AND hostname LIKE 'ZZPD-%'")->execute([$id]); } catch (Throwable $e) {}
    }
    // Belt and braces: anything ZZPD-shaped this run left behind.
    try { $conn->exec("DELETE FROM assets WHERE hostname LIKE 'ZZPD-%' AND id NOT IN (SELECT asset_id FROM asset_physical_disks)"); } catch (Throwable $e) {}
    if ($madeKeyId) {
        try { $conn->prepare("DELETE FROM api_rate_limits WHERE apikey_id = ?")->execute([$madeKeyId]); } catch (Throwable $e) {}
        try { $conn->prepare("DELETE FROM apikeys WHERE id = ?")->execute([$madeKeyId]); } catch (Throwable $e) {}
    }
}

echo str_repeat('-', 70) . "\n";
echo "  {$pass} passed, {$fail} failed\n\n";
exit($fail === 0 ? 0 : 1);
