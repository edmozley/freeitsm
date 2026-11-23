<?php
/* 🔴 NEVER OVER THE WEB. A test writes to the real tables and only tidies up if it runs
   to the end. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * GH #200 part D: protected values. A name, manufacturer or model a person set is never
 * overwritten by the agent; what the agent reports is kept beside it; Release hands it
 * back. A correction on a reporting asset is protected automatically unless switched off.
 *
 * Real HTTP: the agent's endpoint with a real agent key, and the page's endpoints as a
 * forged admin. Everything is prefixed ZZPV and removed in `finally`; the setting is put back.
 *
 * Run:  php tests/asset-protected-values.php   (FREEITSM_BASE_URL, default http://localhost/freeitsm-app)
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';

$pass = 0; $fail = 0;
function ok(string $what, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  PASS  {$what}\n"; }
    else       { $fail++; echo "  FAIL  {$what}" . ($detail ? "  <- {$detail}" : '') . "\n"; }
}

echo "\nProtected values\n" . str_repeat('=', 70) . "\n";

$conn = connectToDatabase();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$base = getenv('FREEITSM_BASE_URL') ?: 'http://localhost/freeitsm-app';
$apikey = 'ZZPV-' . bin2hex(random_bytes(8));
$serial = 'ZZPV' . strtoupper(bin2hex(random_bytes(4)));
$sid = 'zzpv' . bin2hex(random_bytes(4));
$csrf = bin2hex(random_bytes(32));
$sessFile = rtrim(ini_get('session.save_path') ?: 'c:/wamp64/tmp', "/\\") . '/sess_' . $sid;
$keyId = 0; $assetId = 0;
$settingBefore = $conn->query("SELECT setting_value FROM system_settings WHERE setting_key = 'asset_protect_on_edit'")->fetchColumn();

function agent(string $base, string $key, array $payload): array {
    $ch = curl_init($base . '/api/external/system-info/submit/');
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($payload), CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: ' . $key]]);
    $body = (string)curl_exec($ch);
    return json_decode($body, true) ?? ['raw' => $body];
}
function page(string $base, string $sid, string $csrf, string $path, ?array $post = null): array {
    $ch = curl_init($base . '/api/assets/' . $path);
    $opts = [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIE => 'PHPSESSID=' . $sid, CURLOPT_TIMEOUT => 20];
    if ($post !== null) {
        $opts += [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($post), CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-CSRF-Token: ' . $csrf]];
    }
    curl_setopt_array($ch, $opts);
    $body = (string)curl_exec($ch);
    return json_decode($body, true) ?? ['raw' => substr($body, 0, 300)];
}

try {
    file_put_contents($sessFile, 'analyst_id|i:1;analyst_name|s:13:"Administrator";is_admin|i:1;csrf_token|s:64:"' . $csrf . '";');
    $conn->prepare("INSERT INTO apikeys (apikey, label, active) VALUES (?, 'ZZPV test key', 1)")->execute([$apikey]);
    $keyId = (int)$conn->lastInsertId();
    $conn->exec("DELETE FROM system_settings WHERE setting_key = 'asset_protect_on_edit'");   // the default: on
    $report = fn(string $host, string $make, string $model) => agent($base, $apikey, ['hostname' => $host, 'service_tag' => $serial, 'manufacturer' => $make, 'model' => $model]);
    // By reference: the asset only exists after the first report below.
    $asset = function () use ($conn, &$assetId) { return $conn->query("SELECT hostname, manufacturer, model FROM assets WHERE id = $assetId")->fetch(PDO::FETCH_ASSOC); };
    $prot = function (string $f) use ($conn, &$assetId) { return $conn->query("SELECT * FROM asset_protected_fields WHERE asset_id = $assetId AND field_name = " . $conn->quote($f))->fetch(PDO::FETCH_ASSOC) ?: null; };

    $r = $report('ZZPV-HOST', 'HP', 'EliteBook 840');
    $assetId = (int)($r['asset_id'] ?? 0);
    ok('the agent reports a new machine', $assetId > 0, json_encode($r));

    // ---- A correction sticks ------------------------------------------------------------
    $r = page($base, $sid, $csrf, 'update_asset_field.php', ['asset_id' => $assetId, 'field' => 'manufacturer', 'value' => 'Hewlett-Packard']);
    ok('a person corrects the manufacturer, and it is protected automatically', ($r['success'] ?? false) === true && ($r['protected'] ?? false) === true && $prot('manufacturer') !== null, json_encode($r));
    $report('ZZPV-HOST', 'HP', 'EliteBook 850');
    ok('🔴 the agent\'s next report does NOT overwrite it', $asset()['manufacturer'] === 'Hewlett-Packard', json_encode($asset()));
    ok('...what the agent reported is kept beside it', ($prot('manufacturer')['reported_value'] ?? '') === 'HP' && ($prot('manufacturer')['reported_source'] ?? '') === 'the agent');
    ok('...while an unprotected field still follows the agent', $asset()['model'] === 'EliteBook 850');
    $st = page($base, $sid, $csrf, 'get_asset_inventory_status.php?asset_id=' . $assetId);
    ok('the asset page is told what is protected and what the agent reports', ($st['protected']['manufacturer']['reported_value'] ?? '') === 'HP', json_encode($st['protected'] ?? $st));

    // ---- The name ---------------------------------------------------------------------------
    $r = page($base, $sid, $csrf, 'protect_field.php', ['asset_id' => $assetId, 'field' => 'hostname', 'protect' => true]);
    $report('ZZPV-RENAMED', 'HP', 'EliteBook 850');
    ok("🔴 a protected name is not renamed by the agent (matched by serial)", ($r["success"] ?? false) === true && $asset()["hostname"] === "ZZPV-HOST", json_encode($r) . json_encode($asset()));
    ok('...and the name it reported is shown beside it', ($prot('hostname')['reported_value'] ?? '') === 'ZZPV-RENAMED');

    // ---- Release -----------------------------------------------------------------------------
    $r = page($base, $sid, $csrf, 'protect_field.php', ['asset_id' => $assetId, 'field' => 'manufacturer', 'protect' => false]);
    $report('ZZPV-RENAMED', 'HP', 'EliteBook 850');
    ok('Release hands it back: the next report writes the agent\'s value', ($r['success'] ?? false) === true && $prot('manufacturer') === null && $asset()['manufacturer'] === 'HP', json_encode($asset()));
    ok('protecting and releasing are in the history', (int)$conn->query("SELECT COUNT(*) FROM asset_history WHERE asset_id = $assetId AND field_name IN ('protected','released')")->fetchColumn() >= 3);
    $r = page($base, $sid, $csrf, 'protect_field.php', ['asset_id' => $assetId, 'field' => 'service_tag', 'protect' => true]);
    ok('the serial cannot be protected (matching depends on it)', ($r['success'] ?? true) === false);

    // ---- The setting -----------------------------------------------------------------------
    $conn->exec("INSERT INTO system_settings (setting_key, setting_value) VALUES ('asset_protect_on_edit', '0')");
    $r = page($base, $sid, $csrf, 'update_asset_field.php', ['asset_id' => $assetId, 'field' => 'model', 'value' => 'ZZ corrected model']);
    ok('with the setting off, a correction is saved but not protected', ($r['success'] ?? false) === true && ($r['protected'] ?? true) === false && $prot('model') === null, json_encode($r));

} finally {
    $conn->exec("DELETE FROM system_settings WHERE setting_key = 'asset_protect_on_edit'");
    if ($settingBefore !== false) {
        $conn->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES ('asset_protect_on_edit', ?)")->execute([$settingBefore]);
    }
    if ($assetId) {
        foreach (['asset_protected_fields', 'asset_history', 'asset_disks', 'asset_network_adapters', 'asset_physical_disks', 'asset_review_items'] as $t) {
            try { $conn->prepare("DELETE FROM $t WHERE asset_id = ?")->execute([$assetId]); } catch (Throwable $e) {}
        }
        try { $conn->prepare('DELETE FROM asset_inventory_sources WHERE asset_id = ?')->execute([$assetId]); } catch (Throwable $e) {}
        try { $conn->prepare("DELETE FROM assets WHERE id = ? AND hostname LIKE 'ZZPV-%'")->execute([$assetId]); } catch (Throwable $e) {}
    }
    if ($keyId) {
        try { $conn->prepare('DELETE FROM api_rate_limits WHERE apikey_id = ?')->execute([$keyId]); } catch (Throwable $e) {}
        try { $conn->prepare('DELETE FROM apikeys WHERE id = ?')->execute([$keyId]); } catch (Throwable $e) {}
    }
    @unlink($sessFile);
    echo '  cleanup: ' . (int)$conn->query("SELECT COUNT(*) FROM assets WHERE hostname LIKE 'ZZPV-%'")->fetchColumn() . " ZZPV assets left\n";
}

echo str_repeat('-', 70) . "\n";
echo "  {$pass} passed, {$fail} failed\n\n";
exit($fail === 0 ? 0 : 1);
