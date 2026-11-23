<?php
/* 🔴 NEVER OVER THE WEB. A test writes to the real tables and only tidies up if it runs
   to the end. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * Moving an asset to another company takes its inventory links with it (GH #200 part B
 * follow-up).
 *
 * 🔴 THE BUG THIS GUARDS. asset_inventory_sources.tenant_id is what keeps one tool's device
 * to one asset per company. The move used to leave it on the OLD company, so a later report
 * there named this asset - now another company's - in its "already linked" refusal, and the
 * new company could link the same device to a second asset.
 *
 * Real HTTP through api/assets/move_to_company.php as a forged admin. Needs two companies.
 * Everything is prefixed ZZMV and removed in `finally`.
 *
 * Run:  php tests/asset-move-inventory-links.php   (FREEITSM_BASE_URL, default http://localhost/freeitsm-app)
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/tenancy.php';

$pass = 0; $fail = 0;
function ok(string $what, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  PASS  {$what}\n"; }
    else       { $fail++; echo "  FAIL  {$what}" . ($detail ? "  <- {$detail}" : '') . "\n"; }
}

echo "\nMoving an asset moves its inventory links\n" . str_repeat('=', 70) . "\n";

$conn = connectToDatabase();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$base = getenv('FREEITSM_BASE_URL') ?: 'http://localhost/freeitsm-app';
$default = getDefaultTenantId($conn);
$other = (int)$conn->query("SELECT id FROM tenants WHERE id <> $default ORDER BY id LIMIT 1")->fetchColumn();
if (!$other) { fwrite(STDERR, "Needs a second company.\n"); exit(1); }

$sid  = 'zzmv' . bin2hex(random_bytes(4));
$csrf = bin2hex(random_bytes(32));
$sessFile = rtrim(ini_get('session.save_path') ?: 'c:/wamp64/tmp', "/\\") . '/sess_' . $sid;
$assets = [];

function move(string $base, string $sid, string $csrf, int $assetId, int $tenantId): array {
    $ch = curl_init($base . '/api/assets/move_to_company.php');
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode(['asset_id' => $assetId, 'tenant_id' => $tenantId]),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-CSRF-Token: ' . $csrf],
        CURLOPT_COOKIE => 'PHPSESSID=' . $sid, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20]);
    $body = (string)curl_exec($ch);
    return ['body' => $body, 'json' => json_decode($body, true)];
}

try {
    file_put_contents($sessFile, 'analyst_id|i:1;analyst_name|s:13:"Administrator";is_admin|i:1;csrf_token|s:64:"' . $csrf . '";');
    $mk = function (string $name, ?int $tenant) use ($conn, &$assets): int {
        $conn->prepare('INSERT INTO assets (hostname, tenant_id) VALUES (?, ?)')->execute([$name, $tenant]);
        return $assets[] = (int)$conn->lastInsertId();
    };
    $link = function (int $assetId, int $tenant, string $source, string $ref) use ($conn): void {
        $conn->prepare('INSERT INTO asset_inventory_sources (asset_id, tenant_id, source, source_device_ref) VALUES (?, ?, ?, ?)')->execute([$assetId, $tenant, $source, $ref]);
    };
    $tenants = fn(int $assetId) => array_map('intval', $conn->query("SELECT tenant_id FROM asset_inventory_sources WHERE asset_id = $assetId")->fetchAll(PDO::FETCH_COLUMN));
    $assetTenant = fn(int $assetId) => $conn->query("SELECT COALESCE(tenant_id, $default) FROM assets WHERE id = $assetId")->fetchColumn();

    $x = $mk('ZZMV-X', null);                 // Default company
    $link($x, $default, 'agent', 'asset:' . $x);
    $link($x, $default, 'zzmv', 'DEV-1');
    $y = $mk('ZZMV-Y', $other);               // the other company already has DEV-2
    $link($y, $other, 'zzmv', 'DEV-2');
    $w = $mk('ZZMV-W', null);                 // ...and this one is DEV-2 in Default
    $link($w, $default, 'zzmv', 'DEV-2');

    // ---- A move takes the links with it ---------------------------------------------------
    $r = move($base, $sid, $csrf, $x, $other);
    ok('the asset moved', ($r['json']['success'] ?? false) === true && (int)$assetTenant($x) === $other, $r['body']);
    ok('🔴 ...and BOTH its links (agent and REST tool) moved to the new company', $tenants($x) === [$other, $other], json_encode($tenants($x)));

    // The old company no longer holds DEV-1, so its tool can link that device afresh.
    $z = $mk('ZZMV-Z', null);
    $free = true;
    try { $link($z, $default, 'zzmv', 'DEV-1'); } catch (PDOException $e) { $free = false; }
    ok('the old company can link the same device to one of its own assets', $free);

    // ---- A clash in the new company is refused, naming the asset -----------------------
    $r = move($base, $sid, $csrf, $w, $other);
    $err = (string)($r['json']['error'] ?? '');
    ok('🔴 a move into a company that already links the same device to another asset is REFUSED',
        ($r['json']['success'] ?? true) === false && str_contains($err, 'DEV-2') && str_contains($err, 'ZZMV-Y'), $r['body']);
    ok('...and nothing changed: the asset and its link stay put', (int)$assetTenant($w) === $default && $tenants($w) === [$default]);

    // ---- And back again ---------------------------------------------------------------------
    $conn->prepare("DELETE FROM asset_inventory_sources WHERE asset_id = ?")->execute([$z]);   // free DEV-1 in Default again
    $r = move($base, $sid, $csrf, $x, $default);
    ok('moving it back brings the links back', ($r['json']['success'] ?? false) === true && $tenants($x) === [$default, $default], $r['body']);

} finally {
    foreach ($assets as $id) {
        try { $conn->prepare('DELETE FROM asset_inventory_sources WHERE asset_id = ?')->execute([$id]); } catch (Throwable $e) {}
        try { $conn->prepare('DELETE FROM asset_history WHERE asset_id = ?')->execute([$id]); } catch (Throwable $e) {}
        try { $conn->prepare("DELETE FROM assets WHERE id = ? AND hostname LIKE 'ZZMV-%'")->execute([$id]); } catch (Throwable $e) {}
    }
    @unlink($sessFile);
    echo '  cleanup: ' . (int)$conn->query("SELECT COUNT(*) FROM assets WHERE hostname LIKE 'ZZMV-%'")->fetchColumn() . " ZZMV assets left\n";
}

echo str_repeat('-', 70) . "\n";
echo "  {$pass} passed, {$fail} failed\n\n";
exit($fail === 0 ? 0 : 1);
