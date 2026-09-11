<?php
/**
 * Hiding physical drives (discussion #97).
 *
 * 🔴 THE TEST THAT MATTERS is "a hidden drive stays hidden after the agent
 * reports again". asset_physical_disks is cleared and rewritten on every run
 * and the row ids are reissued, so any implementation that remembers a disk by
 * id or by a column on its row passes every other test here and then fails
 * silently, hours later, on a customer's scheduled task. It is exercised by
 * genuinely re-posting to the real ingest endpoint, not by simulating one.
 *
 * ⚠️ Touches the database. Everything it makes is prefixed ZZDH and removed in
 * the cleanup at the bottom, including on failure.
 *
 * Run:  php tests/asset-disk-hiding.php
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/asset_disk_visibility.php';

$pass = 0; $fail = 0;
function ok(string $what, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  PASS  {$what}\n"; }
    else       { $fail++; echo "  FAIL  {$what}" . ($detail ? "  <- {$detail}" : '') . "\n"; }
}

echo "\nHiding physical drives (#97)\n" . str_repeat('=', 70) . "\n";

$conn = connectToDatabase();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$base    = getenv('FREEITSM_BASE_URL') ?: 'http://localhost/freeitsm-app';
$hostA   = 'ZZDH-HOST-A';
$hostB   = 'ZZDH-HOST-B';
$apikey  = 'ZZDH-' . bin2hex(random_bytes(8));
$sid     = 'zzdh' . bin2hex(random_bytes(4));
$sessDir = ini_get('session.save_path') ?: 'c:/wamp64/tmp';
$sessFile = rtrim($sessDir, "/\\") . '/sess_' . $sid;

$madeKeyId = null; $madeAssets = [];

/** The shape both hosts report: one real drive, one virtual disk to hide. */
function payloadFor(string $host): array {
    return [
        'hostname' => $host,
        'disks' => [
            'logical'  => [['drive' => 'C:', 'label' => 'Windows', 'file_system' => 'NTFS',
                            'size_bytes' => 500107862016, 'free_bytes' => 120000000000, 'used_percent' => 76.0]],
            'physical' => [
                ['model' => 'ZZDH Real SSD', 'serial' => 'ZZDH-' . $host,
                 'size_bytes' => 512105932800, 'media_type' => 'SSD', 'interface' => 'SCSI'],
                ['model' => 'ZZDH Virtual Disk', 'serial' => null,
                 'size_bytes' => 32901120, 'media_type' => 'Fixed hard disk media', 'interface' => 'SCSI'],
            ],
        ],
    ];
}

function agentPost(string $url, string $key, array $payload): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: ' . $key],
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20,
    ]);
    $body = (string)curl_exec($ch); curl_close($ch);
    return ['body' => $body, 'json' => json_decode($body, true)];
}

/** Call an app endpoint as a signed-in analyst. */
function asAnalyst(string $url, string $sid, ?array $post = null): array {
    $ch = curl_init($url);
    $opts = [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIE => 'PHPSESSID=' . $sid, CURLOPT_TIMEOUT => 20];
    if ($post !== null) {
        $opts[CURLOPT_POST] = true;
        $opts[CURLOPT_POSTFIELDS] = json_encode($post);
        $opts[CURLOPT_HTTPHEADER] = ['Content-Type: application/json'];
    }
    curl_setopt_array($ch, $opts);
    $body = (string)curl_exec($ch); curl_close($ch);
    return ['body' => $body, 'json' => json_decode($body, true)];
}

/** The physical disks the SCREEN would draw, with their hidden flags. */
function drivesFor(string $base, string $sid, int $assetId): array {
    $r = asAnalyst($base . '/api/assets/get_asset_disks.php?asset_id=' . $assetId, $sid);
    return $r['json']['physical_disks'] ?? [];
}
function hiddenFlag(array $drives, string $model) {
    foreach ($drives as $d) if ($d['model'] === $model) return $d['hidden'];
    return 'ABSENT';
}

try {
    $conn->prepare("INSERT INTO apikeys (apikey, label, active) VALUES (?, 'ZZDH test key', 1)")->execute([$apikey]);
    $madeKeyId = (int)$conn->lastInsertId();

    $analystId = (int)$conn->query("SELECT id FROM analysts WHERE id = 1")->fetchColumn();
    file_put_contents($sessFile, 'analyst_id|i:' . $analystId . ';analyst_name|s:13:"Administrator";is_admin|i:1;');

    $ingest = $base . '/api/external/system-info/submit/';
    $a = agentPost($ingest, $apikey, payloadFor($hostA));
    $b = agentPost($ingest, $apikey, payloadFor($hostB));
    $assetA = (int)($a['json']['asset_id'] ?? 0);
    $assetB = (int)($b['json']['asset_id'] ?? 0);
    if ($assetA) $madeAssets[] = $assetA;
    if ($assetB) $madeAssets[] = $assetB;
    ok('two test machines reported in', $assetA > 0 && $assetB > 0, $a['body'] . $b['body']);

    // ---- Nothing is hidden to begin with -----------------------------------
    $drives = drivesFor($base, $sid, $assetA);
    ok('both drives come back, neither hidden',
       count($drives) === 2 && hiddenFlag($drives, 'ZZDH Virtual Disk') === false,
       json_encode($drives));

    // ---- The count that the dialog puts in front of the user ---------------
    $c = asAnalyst($base . '/api/assets/count_matching_disks.php?asset_id=' . $assetA
                   . '&model=' . rawurlencode('ZZDH Virtual Disk') . '&size_bytes=32901120', $sid);
    ok('"others like this" counts the OTHER machine, not this drive',
       ($c['json']['drives'] ?? null) === 2 && ($c['json']['others'] ?? null) === 1,
       $c['body']);

    // ---- Hide here only ----------------------------------------------------
    $r = asAnalyst($base . '/api/assets/save_disk_hide_rule.php', $sid, [
        'asset_id' => $assetA, 'model' => 'ZZDH Virtual Disk', 'size_bytes' => '32901120',
        'hidden' => true, 'everywhere' => false,
    ]);
    ok('hiding on one asset succeeds', !empty($r['json']['success']), $r['body']);
    ok('the virtual disk is hidden here', hiddenFlag(drivesFor($base, $sid, $assetA), 'ZZDH Virtual Disk') === true);
    ok('the real drive is untouched', hiddenFlag(drivesFor($base, $sid, $assetA), 'ZZDH Real SSD') === false);
    ok('the OTHER machine is unaffected', hiddenFlag(drivesFor($base, $sid, $assetB), 'ZZDH Virtual Disk') === false);

    // ---- 🔴 THE ONE THAT MATTERS ------------------------------------------
    // Re-report machine A. Every physical disk row is deleted and reinserted
    // with new ids. A `hidden` column on the row, or a rule keyed on the row id,
    // would come back visible here and nowhere else.
    $idsBefore = $conn->query("SELECT GROUP_CONCAT(id ORDER BY id) FROM asset_physical_disks WHERE asset_id = {$assetA}")->fetchColumn();
    agentPost($ingest, $apikey, payloadFor($hostA));
    $idsAfter = $conn->query("SELECT GROUP_CONCAT(id ORDER BY id) FROM asset_physical_disks WHERE asset_id = {$assetA}")->fetchColumn();

    ok('the re-report really did reissue the row ids (the trap is live)',
       $idsBefore !== $idsAfter, "before {$idsBefore}, after {$idsAfter}");
    ok('🔴 THE DRIVE IS STILL HIDDEN AFTER THE AGENT REPORTS AGAIN',
       hiddenFlag(drivesFor($base, $sid, $assetA), 'ZZDH Virtual Disk') === true,
       'a hide that does not survive an inventory run is a hide that does not work');

    // ---- Show it again -----------------------------------------------------
    $r = asAnalyst($base . '/api/assets/save_disk_hide_rule.php', $sid, [
        'asset_id' => $assetA, 'model' => 'ZZDH Virtual Disk', 'size_bytes' => '32901120',
        'hidden' => false, 'everywhere' => false,
    ]);
    ok('showing it again succeeds', !empty($r['json']['success']), $r['body']);
    ok('it is visible again', hiddenFlag(drivesFor($base, $sid, $assetA), 'ZZDH Virtual Disk') === false);

    // ---- Hide everywhere ---------------------------------------------------
    $r = asAnalyst($base . '/api/assets/save_disk_hide_rule.php', $sid, [
        'asset_id' => $assetA, 'model' => 'ZZDH Virtual Disk', 'size_bytes' => '32901120',
        'hidden' => true, 'everywhere' => true,
    ]);
    ok('hiding everywhere succeeds', !empty($r['json']['success']), $r['body']);
    ok('hidden on this asset', hiddenFlag(drivesFor($base, $sid, $assetA), 'ZZDH Virtual Disk') === true);
    ok('hidden on the OTHER asset too', hiddenFlag(drivesFor($base, $sid, $assetB), 'ZZDH Virtual Disk') === true);

    // ---- Size is part of the match, exactly --------------------------------
    // A drive with the same model at a different size must stay visible. This
    // is the "predictable beats clever" rule from the serial decision: no
    // wildcard, so nothing is hidden that the user did not point at.
    $conn->prepare("INSERT INTO asset_physical_disks (asset_id, model, serial, size_bytes, media_type, interface_type)
                    VALUES (?, 'ZZDH Virtual Disk', NULL, 99999999, 'Fixed hard disk media', 'SCSI')")->execute([$assetB]);
    $drivesB = drivesFor($base, $sid, $assetB);
    $bigOne = null;
    foreach ($drivesB as $d) if ($d['model'] === 'ZZDH Virtual Disk' && (string)$d['size_bytes'] === '99999999') $bigOne = $d;
    ok('same model at a DIFFERENT size stays visible',
       $bigOne !== null && $bigOne['hidden'] === false,
       json_encode($bigOne));

    // ---- Hiding twice does not stack ---------------------------------------
    asAnalyst($base . '/api/assets/save_disk_hide_rule.php', $sid, [
        'asset_id' => $assetA, 'model' => 'ZZDH Virtual Disk', 'size_bytes' => '32901120',
        'hidden' => true, 'everywhere' => true,
    ]);
    $ruleCount = (int)$conn->query("SELECT COUNT(*) FROM asset_disk_hide_rules WHERE model = 'ZZDH Virtual Disk'")->fetchColumn();
    ok('pressing Hide twice writes one rule, not two', $ruleCount === 1, "got {$ruleCount}");

    // ---- Deleting the asset takes its own rules with it --------------------
    $conn->prepare("INSERT INTO asset_disk_hide_rules (asset_id, model, size_bytes) VALUES (?, 'ZZDH Scoped', 1)")->execute([$assetB]);
    $conn->prepare("DELETE FROM asset_physical_disks WHERE asset_id = ?")->execute([$assetB]);
    $conn->prepare("DELETE FROM asset_disks WHERE asset_id = ?")->execute([$assetB]);
    $conn->prepare("DELETE FROM assets WHERE id = ?")->execute([$assetB]);
    $madeAssets = array_values(array_diff($madeAssets, [$assetB]));
    $orphans = (int)$conn->query("SELECT COUNT(*) FROM asset_disk_hide_rules WHERE model = 'ZZDH Scoped'")->fetchColumn();
    ok('an asset-scoped rule cascades away with its asset', $orphans === 0, "got {$orphans}");

} finally {
    try { $conn->exec("DELETE FROM asset_disk_hide_rules WHERE model LIKE 'ZZDH%'"); } catch (Throwable $e) {}
    foreach ($madeAssets as $id) {
        foreach (['asset_physical_disks', 'asset_disks', 'asset_network_adapters', 'asset_devices', 'asset_disk_hide_rules'] as $t) {
            try { $conn->prepare("DELETE FROM {$t} WHERE asset_id = ?")->execute([$id]); } catch (Throwable $e) {}
        }
        try { $conn->prepare("DELETE FROM software_inventory_detail WHERE host_id = ?")->execute([$id]); } catch (Throwable $e) {}
        try { $conn->prepare("DELETE FROM assets WHERE id = ? AND hostname LIKE 'ZZDH-%'")->execute([$id]); } catch (Throwable $e) {}
    }
    if ($madeKeyId) {
        try { $conn->prepare("DELETE FROM api_rate_limits WHERE apikey_id = ?")->execute([$madeKeyId]); } catch (Throwable $e) {}
        try { $conn->prepare("DELETE FROM apikeys WHERE id = ?")->execute([$madeKeyId]); } catch (Throwable $e) {}
    }
    @unlink($sessFile);
    $left = (int)$conn->query("SELECT COUNT(*) FROM assets WHERE hostname LIKE 'ZZDH-%'")->fetchColumn();
    echo "  cleanup: {$left} ZZDH assets left\n";
}

echo str_repeat('-', 70) . "\n";
echo "  {$pass} passed, {$fail} failed\n\n";
exit($fail === 0 ? 0 : 1);
