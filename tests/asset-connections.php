<?php
/* 🔴 NEVER OVER THE WEB. A test writes to the real tables and only tidies up if it runs
   to the end. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * GH #200 part C: connected assets.
 *
 * A person's confirmation and a tool's observation are separate rows; a tool only opens
 * and closes its own; nothing is disconnected by silence; a serial nobody has (or two
 * assets share) is kept for review, never created. Both assets' history says so.
 *
 * Real HTTP: the page's endpoints as a forged admin, and the REST API with a real key.
 * Everything is prefixed ZZCN and removed in `finally`.
 *
 * Run:  php tests/asset-connections.php   (FREEITSM_BASE_URL, default http://localhost/freeitsm-app)
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/tenancy.php';
require_once __DIR__ . '/../includes/services/asset_type_layout.php';

$pass = 0; $fail = 0;
function ok(string $what, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  PASS  {$what}\n"; }
    else       { $fail++; echo "  FAIL  {$what}" . ($detail ? "  <- {$detail}" : '') . "\n"; }
}

echo "\nConnected assets\n" . str_repeat('=', 70) . "\n";

$conn = connectToDatabase();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$base = getenv('FREEITSM_BASE_URL') ?: 'http://localhost/freeitsm-app';
$default = getDefaultTenantId($conn);
$otherTenant = (int)$conn->query("SELECT id FROM tenants WHERE id <> $default ORDER BY id LIMIT 1")->fetchColumn();
$admin = (int)$conn->query('SELECT MIN(id) FROM analysts WHERE is_admin = 1 AND is_active = 1')->fetchColumn();

$sid = 'zzcn' . bin2hex(random_bytes(4));
$csrf = bin2hex(random_bytes(32));
$sessFile = rtrim(ini_get('session.save_path') ?: 'c:/wamp64/tmp', "/\\") . '/sess_' . $sid;
$assets = []; $keyId = 0; $source = 'zzcn' . bin2hex(random_bytes(3));

function page(string $base, string $sid, string $csrf, string $path, ?array $post = null): array {
    $ch = curl_init($base . '/api/assets/' . $path);
    $opts = [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIE => 'PHPSESSID=' . $sid, CURLOPT_TIMEOUT => 20];
    if ($post !== null) {
        $opts += [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($post),
                  CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-CSRF-Token: ' . $csrf]];
    }
    curl_setopt_array($ch, $opts);
    $body = (string)curl_exec($ch);
    return json_decode($body, true) ?? ['raw' => $body];
}

try {
    file_put_contents($sessFile, 'analyst_id|i:' . $admin . ';analyst_name|s:13:"Administrator";is_admin|i:1;csrf_token|s:64:"' . $csrf . '";');
    $mk = function (string $name, ?string $serial = null, ?int $tenant = null) use ($conn, &$assets): int {
        $conn->prepare('INSERT INTO assets (hostname, service_tag, tenant_id) VALUES (?, ?, ?)')->execute([$name, $serial, $tenant]);
        return $assets[] = (int)$conn->lastInsertId();
    };
    $pc1 = $mk('ZZCN-PC1'); $pc2 = $mk('ZZCN-PC2');
    $mon = $mk('ZZCN-MON', 'ZZCN-MON-SERIAL');
    $mk('ZZCN-TWIN1', 'ZZCN-TWIN-SERIAL'); $mk('ZZCN-TWIN2', 'ZZCN-TWIN-SERIAL');
    $far = $otherTenant ? $mk('ZZCN-FAR', null, $otherTenant) : 0;

    $key = 'fitsm_' . bin2hex(random_bytes(24));
    $conn->prepare('INSERT INTO api_keys (name, key_prefix, key_hash, analyst_id, permissions) VALUES (?, ?, ?, ?, ?)')
        ->execute(['ZZCN key', substr($key, 0, 12), hash('sha256', $key), $admin, json_encode(['asset_inventory' => ['read', 'create', 'delete'], 'assets' => ['read']])]);
    $keyId = (int)$conn->lastInsertId();
    $api = function (string $method, string $path, ?array $body = null) use ($base, $key): array {
        $ch = curl_init($base . '/api/v1/index.php?path=' . rawurlencode($path));
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $key]]
            + ($body !== null ? [CURLOPT_POSTFIELDS => json_encode($body)] : []));
        $raw = (string)curl_exec($ch);
        return [(int)curl_getinfo($ch, CURLINFO_HTTP_CODE), json_decode($raw, true)];
    };
    $at = fn(int $ago) => gmdate('Y-m-d\TH:i:s\Z', time() - $ago);
    $report = fn(int $asset, string $observed, bool $complete, array $items) => $api('POST', "/assets/$asset/inventory",
        ['schema_version' => 1, 'source' => $source, 'source_device_ref' => 'ZZCN-' . $asset, 'observed_at' => $observed,
         'sections' => ['connections' => ['complete' => $complete, 'items' => $items]]]);
    $open = fn(int $host, string $src) => (int)$conn->query("SELECT COUNT(*) FROM asset_connections WHERE asset_id = $host AND source = " . $conn->quote($src) . " AND disconnected_at IS NULL")->fetchColumn();
    $rest = 'rest:' . $source;

    ok('Connected is a tab every type shows unless its layout says otherwise', in_array('connected', AssetTypeLayout::TABS, true));

    // ---- A person connects ------------------------------------------------------------------
    $r = page($base, $sid, $csrf, 'connect_asset.php', ['asset_id' => $pc1, 'other_asset_id' => $mon]);
    ok('a person connects the monitor to PC1', ($r['success'] ?? false) === true, json_encode($r));
    $fromMon = page($base, $sid, $csrf, 'get_asset_connections.php?asset_id=' . $mon);
    ok('...and it shows from the MONITOR\'s end too', ($fromMon['current'][0]['other']['id'] ?? 0) === $pc1 && ($fromMon['current'][0]['rows'][0]['manual'] ?? false) === true
        && ($fromMon['current'][0]['rows'][0]['reported_here'] ?? true) === false, json_encode($fromMon));
    $r = page($base, $sid, $csrf, 'connect_asset.php', ['asset_id' => $mon, 'other_asset_id' => $pc1]);
    ok('confirming the same pair again (from the other end) is refused', ($r['success'] ?? true) === false, json_encode($r));
    $r = page($base, $sid, $csrf, 'connect_asset.php', ['asset_id' => $pc1, 'other_asset_id' => $pc1]);
    ok('an asset cannot be connected to itself', ($r['success'] ?? true) === false);
    if ($far) {
        $r = page($base, $sid, $csrf, 'connect_asset.php', ['asset_id' => $pc1, 'other_asset_id' => $far]);
        ok('🔴 two assets in different companies cannot be connected', ($r['success'] ?? true) === false && str_contains((string)($r['error'] ?? ''), 'same company'), json_encode($r));
    }
    $hist = (int)$conn->query("SELECT COUNT(*) FROM asset_history WHERE field_name = 'connection' AND asset_id IN ($pc1, $mon) AND new_value LIKE '%confirmed by a person%'")->fetchColumn();
    ok('the connection is in BOTH assets\' history', $hist === 2, "$hist");

    // ---- A tool observes ------------------------------------------------------------------
    [$code, $r] = $report($pc2, $at(3600), true, [['serial' => 'ZZCN-MON-SERIAL', 'name' => 'Monitor']]);
    ok('a REST tool reports the monitor on PC2, matched by serial', $code === 200 && ($r['data']['sections']['connections']['added'] ?? 0) === 1, $code . ' ' . json_encode($r));
    $fromMon = page($base, $sid, $csrf, 'get_asset_connections.php?asset_id=' . $mon);
    $others = array_map(fn($g) => $g['other']['id'], $fromMon['current'] ?? []);
    sort($others);
    ok('🔴 the person\'s view and the tool\'s are shown BESIDE each other, not one over the other', $others === [$pc1, $pc2], json_encode($fromMon));
    ok('...the person\'s row is untouched', $open($pc1, 'manual') === 1);
    [$code] = $report($pc2, $at(1800), false, []);
    ok('🔴 a PARTIAL report that does not mention it ends nothing', $code === 200 && $open($pc2, $rest) === 1);
    [$code] = $report($pc2, $at(1700), true, [['connected_asset_id' => $mon]]);
    $last = $conn->query("SELECT last_observed_at FROM asset_connections WHERE asset_id = $pc2 AND source = " . $conn->quote($rest) . " AND disconnected_at IS NULL")->fetchColumn();
    ok('a report by FreeITSM id updates the same row\'s last observation', $code === 200 && $open($pc2, $rest) === 1 && strtotime($last . ' UTC') > time() - 1800, (string)$last);
    [$code] = $report($pc2, $at(1600), true, []);
    ok('a COMPLETE report without it ends the tool\'s row', $code === 200 && $open($pc2, $rest) === 0);
    ok('...and never the person\'s', $open($pc1, 'manual') === 1);
    $hist = (int)$conn->query("SELECT COUNT(*) FROM asset_history WHERE field_name = 'connection' AND asset_id IN ($pc2, $mon) AND old_value LIKE '%observed by $source%'")->fetchColumn();
    ok('the disconnection is in both assets\' history', $hist === 2, "$hist");
    [$code] = $report($pc2, $at(1500), false, [['connected_asset_id' => $mon]]);
    [$code] = $report($pc2, $at(1400), false, [['connected_asset_id' => $mon, 'connected' => false]]);
    ok('connected: false ends it, even in a partial report', $code === 200 && $open($pc2, $rest) === 0);
    [$code, $r] = $report($pc2, $at(1300), true, [['connected_asset_id' => $pc2]]);
    ok('a tool cannot connect an asset to itself', $code === 422);
    if ($far) {
        [$code, $r] = $report($pc2, $at(1200), true, [['connected_asset_id' => $far]]);
        ok('🔴 a tool cannot name an asset in another company', $code === 422, $code . ' ' . json_encode($r));
    }

    // ---- What cannot be matched is kept for review ----------------------------------------
    [$code, $r] = $report($pc2, $at(1100), false, [['serial' => 'ZZCN-NOBODY', 'model' => 'Dell P2422H'], ['serial' => 'ZZCN-TWIN-SERIAL']]);
    $un = $r['data']['sections']['connections']['unmatched'] ?? [];
    ok('serials nobody has, or two assets share, come back as unmatched', $code === 200 && in_array('ZZCN-NOBODY', $un, true) && in_array('ZZCN-TWIN-SERIAL', $un, true), json_encode($r));
    $kinds = $conn->query("SELECT kind FROM asset_review_items WHERE asset_id = $pc2 AND status = 'open' ORDER BY kind")->fetchAll(PDO::FETCH_COLUMN);
    ok('...and are kept for review (unmatched, ambiguous) - no asset was created', $kinds === ['ambiguous_match', 'unmatched_peripheral']
        && (int)$conn->query("SELECT COUNT(*) FROM assets WHERE service_tag = 'ZZCN-NOBODY'")->fetchColumn() === 0, json_encode($kinds));
    $report($pc2, $at(1000), false, [['serial' => 'ZZCN-NOBODY']]);
    ok('seen again, it is still one case', (int)$conn->query("SELECT COUNT(*) FROM asset_review_items WHERE asset_id = $pc2 AND kind = 'unmatched_peripheral'")->fetchColumn() === 1);
    $nobody = $mk('ZZCN-NEWMON', 'ZZCN-NOBODY');
    $report($pc2, $at(900), false, [['serial' => 'ZZCN-NOBODY']]);
    ok('once an asset has that serial, the next report connects it and the case settles itself',
        $conn->query("SELECT status FROM asset_review_items WHERE asset_id = $pc2 AND kind = 'unmatched_peripheral'")->fetchColumn() === 'resolved'
        && (int)$conn->query("SELECT COUNT(*) FROM asset_connections WHERE asset_id = $pc2 AND connected_asset_id = $nobody AND disconnected_at IS NULL")->fetchColumn() === 1);

    // ---- Disconnecting ------------------------------------------------------------------------
    $toolRow = (int)$conn->query("SELECT id FROM asset_connections WHERE asset_id = $pc2 AND connected_asset_id = $nobody AND disconnected_at IS NULL")->fetchColumn();
    $r = page($base, $sid, $csrf, 'disconnect_asset.php', ['connection_id' => $toolRow]);
    ok('🔴 a person cannot end a TOOL\'s row (the tool reports it gone)', ($r['success'] ?? true) === false, json_encode($r));
    $manualRow = (int)$conn->query("SELECT id FROM asset_connections WHERE asset_id = $pc1 AND source = 'manual' AND disconnected_at IS NULL")->fetchColumn();
    $r = page($base, $sid, $csrf, 'disconnect_asset.php', ['connection_id' => $manualRow]);
    ok('a person disconnects their confirmation', ($r['success'] ?? false) === true && $open($pc1, 'manual') === 0, json_encode($r));
    $fromPc1 = page($base, $sid, $csrf, 'get_asset_connections.php?asset_id=' . $pc1);
    ok('...and it moves to the earlier connections, with who ended it', ($fromPc1['current'] ?? null) === [] && ($fromPc1['history'][0]['disconnected_by'] ?? '') !== '', json_encode($fromPc1));

    // ---- REST read, and unlink ------------------------------------------------------------------
    [$code, $r] = $api('GET', "/assets/$mon/connections");
    ok('GET /assets/{id}/connections lists current and earlier connections', $code === 200 && isset($r['data']['current'], $r['data']['history']) && count($r['data']['history']) >= 2, $code . ' ' . json_encode($r));
    [$code, $r] = $api('DELETE', "/assets/$pc2/inventory/$source");
    ok('unlinking the source removes its connection rows on that asset', $code === 200 && (int)$conn->query("SELECT COUNT(*) FROM asset_connections WHERE asset_id = $pc2 AND source = " . $conn->quote($rest))->fetchColumn() === 0, $code . ' ' . json_encode($r));

} finally {
    foreach ($assets as $id) {
        foreach (['asset_connections' => 'asset_id', 'asset_review_items' => 'asset_id', 'asset_history' => 'asset_id'] as $t => $c) {
            try { $conn->prepare("DELETE FROM $t WHERE $c = ?")->execute([$id]); } catch (Throwable $e) {}
        }
        try { $conn->prepare('DELETE FROM asset_connections WHERE connected_asset_id = ?')->execute([$id]); } catch (Throwable $e) {}
        try { $conn->prepare('DELETE s FROM asset_inventory_sections s JOIN asset_inventory_sources b ON b.id = s.inventory_source_id WHERE b.asset_id = ?')->execute([$id]); } catch (Throwable $e) {}
        try { $conn->prepare('DELETE FROM asset_inventory_sources WHERE asset_id = ?')->execute([$id]); } catch (Throwable $e) {}
        try { $conn->prepare("DELETE FROM assets WHERE id = ? AND hostname LIKE 'ZZCN-%'")->execute([$id]); } catch (Throwable $e) {}
    }
    try { $conn->prepare('DELETE FROM asset_inventory_source_owners WHERE source = ?')->execute([$source]); } catch (Throwable $e) {}
    if ($keyId) {
        try { $conn->prepare('DELETE FROM api_key_rate_limits WHERE api_key_id = ?')->execute([$keyId]); } catch (Throwable $e) {}
        try { $conn->prepare('DELETE FROM api_keys WHERE id = ?')->execute([$keyId]); } catch (Throwable $e) {}
    }
    @unlink($sessFile);
    echo '  cleanup: ' . (int)$conn->query("SELECT COUNT(*) FROM assets WHERE hostname LIKE 'ZZCN-%'")->fetchColumn() . " ZZCN assets left\n";
}

echo str_repeat('-', 70) . "\n";
echo "  {$pass} passed, {$fail} failed\n\n";
exit($fail === 0 ? 0 : 1);
