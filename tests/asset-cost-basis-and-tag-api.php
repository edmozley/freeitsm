<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * GH #403 (net / gross purchase cost) and the asset-tag API gaps found building the
 * API script library, through the real endpoints.
 *
 *   1. purchase_cost_basis: create / update / clear over REST and the screen's endpoint,
 *      refused when it is not net or gross, never changes the amount, shown in the list
 *      the screen reads, recorded in history.
 *   2. asset_tag: returned by GET, filterable with ?asset_tag=, changed by PATCH (it was
 *      silently ignored), and a clashing tag is 409 with NOTHING else in the request written.
 *
 * Disposable install only - creates and deletes ZZ fixtures, forges a session:
 *   FREEITSM_TEST_DATABASE=<db> FREEITSM_TEST_URL=http://localhost/<install> php tests/asset-cost-basis-and-tag-api.php
 */
chdir(__DIR__ . '/..');
$expected = getenv('FREEITSM_TEST_DATABASE');
$base = rtrim((string)getenv('FREEITSM_TEST_URL'), '/');
if (!$expected || !$base) { echo "Set FREEITSM_TEST_DATABASE and FREEITSM_TEST_URL.\n"; exit(1); }
require_once 'config.php';
require_once 'includes/functions.php';
$conn = connectToDatabase();
if ($conn->query('SELECT DATABASE()')->fetchColumn() !== $expected) { echo "Wrong database.\n"; exit(1); }
$sessDir = rtrim(getenv('FREEITSM_SESS_DIR') ?: (ini_get('session.save_path') ?: sys_get_temp_dir()), '/\\');

$pass = 0; $fail = 0;
$check = function (string $label, bool $ok, string $why = '') use (&$pass, &$fail) {
    echo ($ok ? '  PASS  ' : '  FAIL  ') . $label . ($ok || $why === '' ? '' : "  [$why]") . "\n";
    $ok ? $pass++ : $fail++;
};
$tag = 'zzcb' . bin2hex(random_bytes(4));
$adminId = 0; $keyId = 0; $assets = []; $sessFile = '';

$api = function (string $method, string $path, ?array $body = null) use ($base, &$key): array {
    $ch = curl_init($base . '/api/v1/index.php?path=' . rawurlencode(strtok($path, '?')) . (str_contains($path, '?') ? '&' . substr($path, strpos($path, '?') + 1) : ''));
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $key, 'Content-Type: application/json']]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    $raw = (string)curl_exec($ch);
    return [(int)curl_getinfo($ch, CURLINFO_HTTP_CODE), json_decode($raw, true), $raw];
};
$ui = function (string $path, array $body) use ($base, $tag): array {
    $ch = curl_init($base . '/' . $path);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($body), CURLOPT_TIMEOUT => 30,
        CURLOPT_COOKIE => 'PHPSESSID=' . $tag, CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-CSRF-Token: x']]);
    return json_decode((string)curl_exec($ch), true) ?? [];
};
$row = function (int $id) use ($conn): array {
    $st = $conn->prepare('SELECT * FROM assets WHERE id = ?'); $st->execute([$id]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: [];
};

try {
    $conn->prepare("INSERT INTO analysts (username, password_hash, full_name, email, is_active, is_admin, can_access_all_modules, can_access_all_tenants) VALUES (?, '!', 'ZZ cost basis', ?, 1, 1, 1, 1)")
        ->execute([$tag, $tag . '@example.invalid']);
    $adminId = (int)$conn->lastInsertId();
    $key = 'fitsm_' . bin2hex(random_bytes(24));
    $conn->prepare('INSERT INTO api_keys (name, key_prefix, key_hash, analyst_id, permissions, rate_limit_per_minute, active, created_datetime) VALUES (?, ?, ?, ?, ?, 1000, 1, UTC_TIMESTAMP())')
        ->execute([$tag, substr($key, 0, 14), hash('sha256', $key), $adminId, json_encode(['assets' => ['read', 'create', 'update']])]);
    $keyId = (int)$conn->lastInsertId();
    $sessFile = $sessDir . '/sess_' . $tag;
    file_put_contents($sessFile, 'analyst_id|i:' . $adminId . ';csrf_token|s:1:"x";');

    echo "== 1. purchase_cost_basis\n";
    [$s, $d] = $api('POST', '/assets', ['hostname' => "ZZ-$tag-A", 'purchase_cost' => 1190, 'purchase_cost_basis' => 'gross', 'asset_tag' => "ZZ-$tag-1"]);
    $a = (int)($d['data']['id'] ?? 0); if ($a) $assets[] = $a;
    $check('create with gross', $s === 201 && ($d['data']['lifecycle']['purchase_cost_basis'] ?? null) === 'gross', "$s");
    $check('...stored as gross, amount untouched', ($row($a)['purchase_cost_basis'] ?? '') === 'gross' && (float)$row($a)['purchase_cost'] === 1190.0);
    [$s, $d] = $api('POST', '/assets', ['hostname' => "ZZ-$tag-B", 'purchase_cost' => 1000]);
    $b = (int)($d['data']['id'] ?? 0); if ($b) $assets[] = $b;
    $check('an asset created without a basis is not specified (null), not guessed', $s === 201 && array_key_exists('purchase_cost_basis', $d['data']['lifecycle'] ?? []) && $d['data']['lifecycle']['purchase_cost_basis'] === null);
    [$s, $d] = $api('PATCH', "/assets/$b", ['purchase_cost_basis' => 'NET']);
    $check('PATCH net (any case) is stored lower-case', $s === 200 && ($row($b)['purchase_cost_basis'] ?? '') === 'net', "$s");
    $check('...and the amount is not recalculated', (float)$row($b)['purchase_cost'] === 1000.0);
    [$s, $d] = $api('PATCH', "/assets/$b", ['purchase_cost_basis' => 'incl']);
    $check('anything but net / gross is 422, not guessed', $s === 422 && ($row($b)['purchase_cost_basis'] ?? '') === 'net', "$s");
    [$s] = $api('PATCH', "/assets/$b", ['purchase_cost_basis' => null]);
    $check('null clears it back to not specified', $s === 200 && $row($b)['purchase_cost_basis'] === null, "$s");
    $r = $ui('api/assets/update_asset_field.php', ['asset_id' => $b, 'field' => 'purchase_cost_basis', 'value' => 'gross']);
    $check('the Financial tab\'s endpoint sets it', ($r['success'] ?? false) === true && ($row($b)['purchase_cost_basis'] ?? '') === 'gross', json_encode($r));
    $st = $conn->prepare("SELECT old_value, new_value FROM asset_history WHERE asset_id = ? AND field_name = 'purchase_cost_basis' ORDER BY id");
    $st->execute([$b]); $h = $st->fetchAll(PDO::FETCH_ASSOC);
    $check('each change is in the history under its field key', count($h) === 3 && $h[2]['new_value'] === 'gross', json_encode($h));
    $ch = curl_init($base . '/api/assets/get_assets.php'); curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIE => 'PHPSESSID=' . $tag]);
    $list = json_decode((string)curl_exec($ch), true);
    $mine = array_values(array_filter($list['assets'] ?? [], fn($x) => (int)$x['id'] === $b));
    $check('the list the screen reads carries purchase_cost_basis', isset($mine[0]) && ($mine[0]['purchase_cost_basis'] ?? null) === 'gross', isset($mine[0]) ? json_encode(array_keys($mine[0])) : 'asset not in list');

    echo "== 2. asset_tag over REST\n";
    [$s, $d] = $api('GET', "/assets/$a");
    $check('GET returns asset_tag', ($d['data']['asset_tag'] ?? null) === "ZZ-$tag-1", json_encode($d['data']['asset_tag'] ?? null));
    [$s, $d] = $api('GET', '/assets?asset_tag=' . rawurlencode("ZZ-$tag-1"));
    $check('?asset_tag= finds exactly that asset', $s === 200 && count($d['data'] ?? []) === 1 && (int)$d['data'][0]['id'] === $a, "$s " . count($d['data'] ?? []));
    [$s, $d] = $api('PATCH', "/assets/$b", ['asset_tag' => "ZZ-$tag-2"]);
    $check('PATCH changes the tag (it was silently ignored before)', $s === 200 && ($row($b)['asset_tag'] ?? '') === "ZZ-$tag-2" && ($d['data']['asset_tag'] ?? '') === "ZZ-$tag-2", "$s");
    $check('...and does not list it under ignored', !in_array('asset_tag', $d['data']['ignored'] ?? [], true));
    [$s, $d] = $api('PATCH', "/assets/$b", ['asset_tag' => "ZZ-$tag-1", 'order_number' => 'ZZ-SHOULD-NOT-SAVE']);
    $check('a clashing tag is 409', $s === 409, "$s");
    $check('...and nothing else in that request was written', ($row($b)['order_number'] ?? null) !== 'ZZ-SHOULD-NOT-SAVE' && ($row($b)['asset_tag'] ?? '') === "ZZ-$tag-2");
    [$s] = $api('PATCH', "/assets/$b", ['asset_tag' => '']);
    $check('an empty tag clears it', $s === 200 && empty($row($b)['asset_tag']), "$s");
} catch (Throwable $e) {
    $check('no exception', false, get_class($e) . ': ' . $e->getMessage());
} finally {
    foreach ($assets as $id) {
        $conn->prepare('DELETE FROM asset_history WHERE asset_id = ?')->execute([$id]);
        $conn->prepare('DELETE FROM assets WHERE id = ?')->execute([$id]);
    }
    if ($keyId) $conn->prepare('DELETE FROM api_keys WHERE id = ?')->execute([$keyId]);
    if ($adminId) {
        $conn->prepare('DELETE FROM user_preferences WHERE analyst_id = ?')->execute([$adminId]);
        $conn->prepare('DELETE FROM analysts WHERE id = ?')->execute([$adminId]);
    }
    if ($sessFile !== '' && is_file($sessFile)) unlink($sessFile);
}
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
