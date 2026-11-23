<?php
/* 🔴 NEVER OVER THE WEB. A test writes to the real tables and only tidies up if it runs
   to the end. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * GH #200 part D: the review list, and merging two assets.
 *
 * 🔴 The guard: every schema column that refers to an asset is in
 * AssetMergeService::REFERENCES. A column left out would keep pointing at a deleted
 * asset, or be deleted with it by ON DELETE CASCADE - so a merge would quietly lose it.
 *
 * Then a real merge through the page's endpoint as a forged admin, checking that
 * everything both records had survives on the kept one, that duplicates are not
 * doubled, and that the removed asset's QR label, REST id and page link still find
 * the kept one. Everything is prefixed ZZMG and removed in `finally`.
 *
 * Run:  php tests/asset-merge.php   (FREEITSM_BASE_URL, default http://localhost/freeitsm-app)
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/tenancy.php';
require_once __DIR__ . '/../includes/asset_labels.php';
require_once __DIR__ . '/../includes/services/asset_merge.php';

$pass = 0; $fail = 0;
function ok(string $what, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  PASS  {$what}\n"; }
    else       { $fail++; echo "  FAIL  {$what}" . ($detail ? "  <- {$detail}" : '') . "\n"; }
}

echo "\nReview and merge\n" . str_repeat('=', 70) . "\n";

// ---- The guard: no asset reference left out -------------------------------------------
$schema = require __DIR__ . '/../includes/db_verify_schema.php';
$known = [];
foreach (AssetMergeService::REFERENCES as $r) { $known[$r[0] . '.' . $r[1]] = true; }
foreach (AssetMergeService::NOT_REFERENCES as $r) { $known[$r] = true; }
$missing = [];
foreach ($schema as $table => $cols) {
    if ($table === 'assets') { continue; }
    foreach (array_keys($cols) as $col) {
        if (preg_match('/(^|_)asset_id$|^host_id$/', $col) && !isset($known["$table.$col"])) { $missing[] = "$table.$col"; }
    }
}
ok('🔴 every column that refers to an asset is handled by a merge', !$missing, implode(', ', $missing));
$stale = array_filter(array_keys($known), fn($k) => !isset($schema[explode('.', $k)[0]][explode('.', $k)[1]]));
ok('...and every entry is a real column', !$stale, implode(', ', $stale));

$conn = connectToDatabase();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$base = getenv('FREEITSM_BASE_URL') ?: 'http://localhost/freeitsm-app';
$default = getDefaultTenantId($conn);
$otherTenant = (int)$conn->query("SELECT id FROM tenants WHERE id <> $default ORDER BY id LIMIT 1")->fetchColumn();
$admin = 1;
$sid = 'zzmg' . bin2hex(random_bytes(4));
$csrf = bin2hex(random_bytes(32));
$sessFile = rtrim(ini_get('session.save_path') ?: 'c:/wamp64/tmp', "/\\") . '/sess_' . $sid;
$assets = []; $appIds = []; $keyId = 0; $source = 'zzmg' . bin2hex(random_bytes(3));
$serial = 'ZZMG-' . strtoupper(bin2hex(random_bytes(4)));

function page(string $base, string $sid, string $csrf, string $path, ?array $post = null, bool $raw = false) {
    $ch = curl_init($base . '/' . $path);
    $opts = [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIE => 'PHPSESSID=' . $sid, CURLOPT_TIMEOUT => 30, CURLOPT_HEADER => $raw];
    if ($post !== null) {
        $opts += [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($post),
                  CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-CSRF-Token: ' . $csrf]];
    }
    curl_setopt_array($ch, $opts);
    $body = (string)curl_exec($ch);
    return $raw ? $body : (json_decode($body, true) ?? ['raw' => substr($body, 0, 300)]);
}

try {
    file_put_contents($sessFile, 'analyst_id|i:1;analyst_name|s:13:"Administrator";is_admin|i:1;csrf_token|s:64:"' . $csrf . '";');
    $mk = function (string $name, ?string $tag, array $extra = []) use ($conn, &$assets): int {
        $cols = array_merge(['hostname' => $name, 'service_tag' => $tag], $extra);
        $conn->prepare('INSERT INTO assets (' . implode(', ', array_keys($cols)) . ') VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')')->execute(array_values($cols));
        return $assets[] = (int)$conn->lastInsertId();
    };
    $keep = $mk('ZZMG-KEEP', $serial, ['model' => 'Keep model', 'last_seen' => gmdate('Y-m-d H:i:s')]);
    $gone = $mk('ZZMG-GONE', $serial, ['model' => 'Gone model', 'manufacturer' => 'ZZ Make', 'asset_tag' => 'ZZMG-T1',
        'qr_token' => bin2hex(random_bytes(8)), 'first_seen' => '2020-01-01 00:00:00', 'last_seen' => '2021-01-01 00:00:00']);
    $peer = $mk('ZZMG-PEER', null);
    $token = $conn->query("SELECT qr_token FROM assets WHERE id = $gone")->fetchColumn();

    // What the gone asset has: some only it has, some the kept one has too.
    $conn->prepare("INSERT INTO asset_history (asset_id, field_name, new_value, created_datetime) VALUES (?, 'zzmg', 'gone history', UTC_TIMESTAMP())")->execute([$gone]);
    foreach ([$keep, $gone] as $a) {
        $conn->prepare('INSERT INTO users_assets (analyst_id, asset_id, assigned_datetime) VALUES (?, ?, UTC_TIMESTAMP())')->execute([$admin, $a]);
        $conn->prepare("INSERT INTO asset_disks (asset_id, drive, source, external_id) VALUES (?, 'C:', 'agent', 'C:')")->execute([$a]);
        $conn->prepare("INSERT INTO asset_inventory_sources (asset_id, tenant_id, source, source_device_ref) VALUES (?, ?, 'agent', ?)")->execute([$a, $default, 'asset:' . $a]);
    }
    $conn->prepare("INSERT INTO asset_disks (asset_id, drive, source, external_id) VALUES (?, 'D:', 'agent', 'D:')")->execute([$gone]);
    $conn->prepare("INSERT INTO asset_inventory_sources (asset_id, tenant_id, source, source_device_ref) VALUES (?, ?, ?, 'ZZMG-DEV')")->execute([$gone, $default, $source]);
    foreach (['App One', 'App Two'] as $n) {
        $conn->prepare("INSERT INTO software_inventory_apps (display_name, publisher, source) VALUES (?, 'ZZMG', 'manual')")->execute(['ZZMG ' . $n]);
        $appIds[] = (int)$conn->lastInsertId();
    }
    foreach ([[$keep, 0], [$gone, 0], [$gone, 1]] as [$a, $i]) {
        $conn->prepare("INSERT INTO software_inventory_detail (host_id, app_id, source) VALUES (?, ?, 'agent')")->execute([$a, $appIds[$i]]);
    }
    $conn->prepare("INSERT INTO asset_physical_disks (asset_id, model) VALUES (?, 'ZZMG drive')")->execute([$gone]);
    $conn->prepare("INSERT INTO asset_connections (asset_id, connected_asset_id, source, connected_at) VALUES (?, ?, 'manual', UTC_TIMESTAMP())")->execute([$peer, $gone]);
    $conn->prepare("INSERT INTO asset_connections (asset_id, connected_asset_id, source, connected_at) VALUES (?, ?, 'manual', UTC_TIMESTAMP())")->execute([$keep, $gone]);

    // ---- The review list finds them ----------------------------------------------------------
    $list = page($base, $sid, $csrf, 'api/assets/review_list.php');
    $case = null;
    foreach ($list['items'] ?? [] as $it) { if ($it['kind'] === 'shared_serial' && in_array($gone, [$it['asset_id'], $it['other_asset_id']], true)) { $case = $it; } }
    ok('the review list finds two assets sharing a serial', $case !== null && ($case['detail']['serial'] ?? '') === $serial, json_encode($list['items'] ?? $list));
    $st = page($base, $sid, $csrf, 'api/assets/get_asset_inventory_status.php?asset_id=' . $gone);
    ok('...and the asset\'s own page says so', count($st['review'] ?? []) === 1, json_encode($st['review'] ?? $st));

    $r = page($base, $sid, $csrf, 'api/assets/review_decide.php', ['id' => $case['id'], 'decision' => 'different']);
    $again = page($base, $sid, $csrf, 'api/assets/review_list.php');
    ok('"Different" takes it off the list, and it stays off when the list looks again', ($r['success'] ?? false) === true
        && !array_filter($again['items'] ?? [], fn($i) => $i['id'] === $case['id']), json_encode($r));
    $r = page($base, $sid, $csrf, 'api/assets/review_decide.php', ['id' => $case['id'], 'decision' => 'open']);
    ok('Reopen brings it back', ($r['success'] ?? false) === true && $conn->query('SELECT status FROM asset_review_items WHERE id = ' . (int)$case['id'])->fetchColumn() === 'open');
    $r = page($base, $sid, $csrf, 'api/assets/review_decide.php', ['id' => $case['id'], 'decision' => 'create']);
    ok('"Create" is refused for a pair of assets', ($r['success'] ?? true) === false);

    // ---- Refusals ------------------------------------------------------------------------------
    $r = page($base, $sid, $csrf, 'api/assets/review_merge.php', ['id' => $case['id'], 'keep_asset_id' => $keep, 'merge_asset_id' => $peer]);
    ok('a merge of assets the case is not about is refused', ($r['success'] ?? true) === false, json_encode($r));
    if ($otherTenant) {
        $far = $mk('ZZMG-FAR', null, ['tenant_id' => $otherTenant]);
        $refused = false;
        try { AssetMergeService::merge($conn, new ActorContext($admin), $keep, $far); } catch (ServiceError $e) { $refused = str_contains($e->getMessage(), 'same company'); }
        ok('🔴 two assets in different companies are never merged', $refused);
    }

    // ---- The merge -----------------------------------------------------------------------------
    $r = page($base, $sid, $csrf, 'api/assets/review_merge.php', ['id' => $case['id'], 'keep_asset_id' => $keep, 'merge_asset_id' => $gone]);
    ok('the merge succeeds through the page', ($r['success'] ?? false) === true, json_encode($r));
    ok('the merged asset is gone', !$conn->query("SELECT 1 FROM assets WHERE id = $gone")->fetchColumn());
    $q = fn(string $sql) => $conn->query($sql)->fetchColumn();
    ok('its history moved to the kept asset', (int)$q("SELECT COUNT(*) FROM asset_history WHERE asset_id = $keep AND field_name = 'zzmg'") === 1);
    ok('...with one row saying what was merged', (int)$q("SELECT COUNT(*) FROM asset_history WHERE asset_id = $keep AND field_name = 'merged' AND old_value LIKE 'ZZMG-GONE%'") === 1);
    ok('🔴 a holder both had is not doubled', (int)$q("SELECT COUNT(*) FROM users_assets WHERE asset_id = $keep AND analyst_id = $admin") === 1);
    ok('volumes: the kept C: stays, the other\'s D: moves, no duplicate C:', (int)$q("SELECT COUNT(*) FROM asset_disks WHERE asset_id = $keep") === 2
        && (int)$q("SELECT COUNT(*) FROM asset_disks WHERE asset_id = $keep AND external_id = 'D:'") === 1);
    ok('software: the app both had once, the app only it had moved', (int)$q("SELECT COUNT(*) FROM software_inventory_detail WHERE host_id = $keep") === 2);
    ok('physical drives moved (the kept asset had none)', (int)$q("SELECT COUNT(*) FROM asset_physical_disks WHERE asset_id = $keep") === 1);
    ok('inventory links: the agent keeps one link, the REST tool\'s link moved', (int)$q("SELECT COUNT(*) FROM asset_inventory_sources WHERE asset_id = $keep AND source = 'agent'") === 1
        && (int)$q("SELECT COUNT(*) FROM asset_inventory_sources WHERE asset_id = $keep AND source = " . $conn->quote($source)) === 1);
    ok('connections: the peer\'s now points at the kept asset; the one between the two is gone',
        (int)$q("SELECT COUNT(*) FROM asset_connections WHERE asset_id = $peer AND connected_asset_id = $keep") === 1
        && (int)$q("SELECT COUNT(*) FROM asset_connections WHERE asset_id = $keep AND connected_asset_id = $keep") === 0);
    $after = $conn->query("SELECT model, manufacturer, asset_tag, first_seen, last_seen FROM assets WHERE id = $keep")->fetch(PDO::FETCH_ASSOC);
    ok('the kept record\'s own values win; empty ones are filled from the other', $after['model'] === 'Keep model' && $after['manufacturer'] === 'ZZ Make' && $after['asset_tag'] === 'ZZMG-T1', json_encode($after));
    ok('...and first seen widens to the older record', $after['first_seen'] === '2020-01-01 00:00:00');
    ok('the review case is closed as merged', $q('SELECT status FROM asset_review_items WHERE id = ' . (int)$case['id']) === 'merged');

    // ---- What still finds the kept asset --------------------------------------------------------
    ok('🔴 the merged asset\'s QR label finds the kept one', assetIdForToken($conn, (string)$token) === $keep);
    $loc = page($base, $sid, $csrf, 'asset-management/?asset_id=' . $gone, null, true);
    ok('a link to the merged asset opens the kept one', (bool)preg_match('/^Location: \?asset_id=' . $keep . '\s*$/mi', $loc), substr($loc, 0, 200));
    ok('AssetMergeService::current() follows the merge', AssetMergeService::current($conn, $gone) === $keep && AssetMergeService::current($conn, $keep) === $keep);

    $key = 'fitsm_' . bin2hex(random_bytes(24));
    $conn->prepare('INSERT INTO api_keys (name, key_prefix, key_hash, analyst_id, permissions) VALUES (?, ?, ?, ?, ?)')
        ->execute(['ZZMG key', substr($key, 0, 12), hash('sha256', $key), $admin, json_encode(['asset_inventory' => ['read', 'create', 'delete']])]);
    $keyId = (int)$conn->lastInsertId();
    $conn->prepare('INSERT INTO asset_inventory_source_owners (tenant_id, source, owner_analyst_id, created_datetime) VALUES (?, ?, ?, UTC_TIMESTAMP())')->execute([$default, $source, $admin]);
    $ch = curl_init($base . '/api/v1/index.php?path=' . rawurlencode("/assets/$gone/inventory"));
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $key],
        CURLOPT_POSTFIELDS => json_encode(['schema_version' => 1, 'source' => $source, 'source_device_ref' => 'ZZMG-DEV', 'observed_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'sections' => ['disks' => ['complete' => false, 'items' => [['external_id' => 'E:', 'drive' => 'E:']]]]])]);
    $res = json_decode((string)curl_exec($ch), true);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    ok('🔴 a REST tool still reporting to the merged asset\'s id lands on the kept one', $code === 200 && ($res['data']['asset_id'] ?? 0) === $keep, $code . ' ' . json_encode($res));

} finally {
    foreach (array_reverse($assets) as $id) {
        foreach (['asset_connections' => 'asset_id', 'asset_review_items' => 'asset_id', 'asset_history' => 'asset_id', 'users_assets' => 'asset_id',
                  'asset_disks' => 'asset_id', 'asset_physical_disks' => 'asset_id', 'software_inventory_detail' => 'host_id', 'asset_merges' => 'into_asset_id'] as $t => $c) {
            try { $conn->prepare("DELETE FROM $t WHERE $c = ?")->execute([$id]); } catch (Throwable $e) {}
        }
        try { $conn->prepare('DELETE FROM asset_connections WHERE connected_asset_id = ?')->execute([$id]); } catch (Throwable $e) {}
        try { $conn->prepare('DELETE FROM asset_review_items WHERE other_asset_id = ?')->execute([$id]); } catch (Throwable $e) {}
        try { $conn->prepare('DELETE s FROM asset_inventory_sections s JOIN asset_inventory_sources b ON b.id = s.inventory_source_id WHERE b.asset_id = ?')->execute([$id]); } catch (Throwable $e) {}
        try { $conn->prepare('DELETE FROM asset_inventory_sources WHERE asset_id = ?')->execute([$id]); } catch (Throwable $e) {}
        try { $conn->prepare("DELETE FROM assets WHERE id = ? AND hostname LIKE 'ZZMG-%'")->execute([$id]); } catch (Throwable $e) {}
    }
    try { $conn->prepare("DELETE FROM asset_review_items WHERE match_key LIKE ?")->execute(['serial:' . strtolower($serial) . ':%']); } catch (Throwable $e) {}
    try { $conn->exec("DELETE FROM asset_merges WHERE merged_hostname LIKE 'ZZMG-%'"); } catch (Throwable $e) {}
    foreach ($appIds as $app) { try { $conn->prepare('DELETE FROM software_inventory_apps WHERE id = ?')->execute([$app]); } catch (Throwable $e) {} }
    try { $conn->prepare('DELETE FROM asset_inventory_source_owners WHERE source = ?')->execute([$source]); } catch (Throwable $e) {}
    if ($keyId) {
        try { $conn->prepare('DELETE FROM api_key_rate_limits WHERE api_key_id = ?')->execute([$keyId]); } catch (Throwable $e) {}
        try { $conn->prepare('DELETE FROM api_keys WHERE id = ?')->execute([$keyId]); } catch (Throwable $e) {}
    }
    @unlink($sessFile);
    echo '  cleanup: ' . (int)$conn->query("SELECT COUNT(*) FROM assets WHERE hostname LIKE 'ZZMG-%'")->fetchColumn() . " ZZMG assets left\n";
}

echo str_repeat('-', 70) . "\n";
echo "  {$pass} passed, {$fail} failed\n\n";
exit($fail === 0 ? 0 : 1);
