<?php
/* 🔴 NEVER OVER THE WEB. A test writes to the real tables and only tidies up if it runs
   to the end. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * GH #200 part D: manufacturer and model names. "ZZNM Maker Inc." and "ZZNM Maker Ltd"
 * shown and counted as "ZZNM Maker" - in the asset list, its search, and a dashboard
 * chart - while each asset keeps the value that came in.
 *
 * Real HTTP as a forged admin. Two ZZNM assets, a temporary chart and the mappings are
 * removed in `finally`.
 *
 * Run:  php tests/asset-name-mappings.php   (FREEITSM_BASE_URL, default http://localhost/freeitsm-app)
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

echo "\nManufacturer and model names\n" . str_repeat('=', 70) . "\n";

$conn = connectToDatabase();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$base = getenv('FREEITSM_BASE_URL') ?: 'http://localhost/freeitsm-app';
$sid = 'zznm' . bin2hex(random_bytes(4));
$csrf = bin2hex(random_bytes(32));
$sessFile = rtrim(ini_get('session.save_path') ?: 'c:/wamp64/tmp', "/\\") . '/sess_' . $sid;
$assets = []; $widgetId = 0;
$active = getActiveTenantId($conn, 1);
$store = $active === getDefaultTenantId($conn) ? null : $active;

function page(string $base, string $sid, string $csrf, string $path, ?array $post = null): array {
    $ch = curl_init($base . '/api/assets/' . $path);
    $opts = [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIE => 'PHPSESSID=' . $sid, CURLOPT_TIMEOUT => 30];
    if ($post !== null) {
        $opts += [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($post), CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-CSRF-Token: ' . $csrf]];
    }
    curl_setopt_array($ch, $opts);
    $body = (string)curl_exec($ch);
    return json_decode($body, true) ?? ['raw' => substr($body, 0, 300)];
}

try {
    file_put_contents($sessFile, 'analyst_id|i:1;analyst_name|s:13:"Administrator";is_admin|i:1;csrf_token|s:64:"' . $csrf . '";');
    foreach (['ZZNM-1' => 'ZZNM Maker Inc.', 'ZZNM-2' => 'ZZNM Maker Ltd'] as $host => $make) {
        $conn->prepare('INSERT INTO assets (hostname, manufacturer, tenant_id) VALUES (?, ?, ?)')->execute([$host, $make, $store]);
        $assets[] = (int)$conn->lastInsertId();
    }
    $conn->exec("INSERT INTO asset_dashboard_widgets (title, chart_type, aggregate_property, is_status_filterable, is_active) VALUES ('ZZNM chart', 'bar', 'manufacturer', 0, 0)");
    $widgetId = (int)$conn->lastInsertId();

    $r1 = page($base, $sid, $csrf, 'name_mappings.php', ['action' => 'save', 'field' => 'manufacturer', 'from' => 'ZZNM Maker Inc.', 'to' => 'ZZNM Maker']);
    $r2 = page($base, $sid, $csrf, 'name_mappings.php', ['action' => 'save', 'field' => 'manufacturer', 'from' => 'zznm maker ltd', 'to' => 'ZZNM Maker']);
    ok('two spellings are mapped to one name (case does not matter)', ($r1['success'] ?? false) && ($r2['success'] ?? false), json_encode([$r1, $r2]));
    $r = page($base, $sid, $csrf, 'name_mappings.php', ['action' => 'save', 'field' => 'manufacturer', 'from' => 'ZZNM Maker', 'to' => 'ZZNM Maker']);
    ok('a name cannot be shown as itself', ($r['success'] ?? true) === false);
    $r = page($base, $sid, $csrf, 'name_mappings.php', ['action' => 'save', 'field' => 'manufacturer', 'from' => 'ZZNM Other', 'to' => 'ZZNM Maker Inc.']);
    ok('...nor as a name that is itself mapped (no chains)', ($r['success'] ?? true) === false);

    $list = page($base, $sid, $csrf, 'get_assets.php?search=' . rawurlencode('ZZNM Maker'));
    $mine = array_values(array_filter($list['assets'] ?? [], fn($a) => in_array((int)$a['id'], $assets, true)));
    ok('the asset list shows both as ZZNM Maker', count($mine) === 2 && $mine[0]['manufacturer_display'] === 'ZZNM Maker' && $mine[1]['manufacturer_display'] === 'ZZNM Maker', json_encode($mine));
    ok('...while each keeps the value that came in', in_array('ZZNM Maker Ltd', array_column($mine, 'manufacturer'), true));
    $chart = page($base, $sid, $csrf, 'get_widget_data.php?widget_id=' . $widgetId);
    $i = array_search('ZZNM Maker', $chart['labels'] ?? [], true);
    ok('🔴 a chart by manufacturer counts them as ONE slice of 2', $i !== false && (int)$chart['values'][$i] === 2
        && !in_array('ZZNM Maker Inc.', $chart['labels'] ?? [], true), json_encode($chart));
    ok('the stored manufacturer is unchanged in the database', $conn->query('SELECT manufacturer FROM assets WHERE id = ' . $assets[0])->fetchColumn() === 'ZZNM Maker Inc.');

    $all = page($base, $sid, $csrf, 'name_mappings.php');
    foreach (array_filter($all['mappings'] ?? [], fn($m) => str_starts_with($m['from_value'], 'ZZNM') || str_starts_with($m['from_value'], 'zznm')) as $m) {
        page($base, $sid, $csrf, 'name_mappings.php', ['action' => 'delete', 'id' => (int)$m['id']]);
    }
    $chart = page($base, $sid, $csrf, 'get_widget_data.php?widget_id=' . $widgetId);
    ok('deleting the mappings undoes it completely', in_array('ZZNM Maker Inc.', $chart['labels'] ?? [], true) && in_array('ZZNM Maker Ltd', $chart['labels'] ?? [], true));

} finally {
    $conn->exec("DELETE FROM asset_name_mappings WHERE from_value LIKE 'ZZNM%'");
    if ($widgetId) { $conn->prepare('DELETE FROM asset_dashboard_widgets WHERE id = ?')->execute([$widgetId]); }
    foreach ($assets as $id) {
        try { $conn->prepare('DELETE FROM asset_review_items WHERE asset_id = ? OR other_asset_id = ?')->execute([$id, $id]); } catch (Throwable $e) {}
        $conn->prepare("DELETE FROM assets WHERE id = ? AND hostname LIKE 'ZZNM-%'")->execute([$id]);
    }
    @unlink($sessFile);
    echo '  cleanup: ' . (int)$conn->query("SELECT COUNT(*) FROM assets WHERE hostname LIKE 'ZZNM-%'")->fetchColumn() . ' ZZNM assets, '
        . (int)$conn->query("SELECT COUNT(*) FROM asset_name_mappings WHERE from_value LIKE 'ZZNM%'")->fetchColumn() . " mappings left\n";
}

echo str_repeat('-', 70) . "\n";
echo "  {$pass} passed, {$fail} failed\n\n";
exit($fail === 0 ? 0 : 1);
