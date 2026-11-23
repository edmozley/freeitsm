<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * Assets: the custom field endpoints keep to the analyst's companies, as every other
 * per-asset endpoint does (api/assets/get_asset_custom_fields.php, save_asset_custom_fields.php,
 * set_asset_field_set.php -> analystCanAccessAsset()).
 *
 * Adds a second company (so the install is multi-company for the run), an analyst limited
 * to the first, a field, a field set and one asset in each company, drives the REAL
 * endpoints over HTTP with a forged session, and removes everything in `finally`.
 *
 * Run: FREEITSM_TEST_DATABASE=<throwaway db> FREEITSM_TEST_URL=http://localhost/<that install> php tests/asset-custom-fields-company.php
 */
chdir(__DIR__ . '/..');
$pass = 0; $fail = 0;
function check(string $what, bool $ok, string $detail = ''): void { global $pass, $fail; echo ($ok ? '  [PASS] ' : '  [FAIL] ') . $what . ($ok || $detail === '' ? '' : "  <- $detail") . "\n"; $ok ? $pass++ : $fail++; }

$expected = getenv('FREEITSM_TEST_DATABASE');
$base = rtrim((string)getenv('FREEITSM_TEST_URL'), '/');
if (!$expected || !$base) { echo "(skipped: set FREEITSM_TEST_DATABASE and FREEITSM_TEST_URL)\n"; exit(0); }
require_once 'config.php';
require_once 'includes/functions.php';
require_once 'includes/services/asset_fields.php';
$conn = connectToDatabase();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
if ($conn->query('SELECT DATABASE()')->fetchColumn() !== $expected) { echo "(skipped: config.php does not use $expected)\n"; exit(0); }

$sessDir = rtrim(ini_get('session.save_path') ?: 'C:/wamp64/tmp', '/\\');
$made = ['tenant' => 0, 'analyst' => 0, 'assets' => [], 'field' => 0, 'set' => 0, 'type' => 0];
$sessFile = null;
try {
    $home = (int)$conn->query('SELECT MIN(id) FROM tenants')->fetchColumn();
    $conn->prepare('INSERT INTO tenants (name) VALUES (?)')->execute(['ZZAF Other company']);
    $other = $made['tenant'] = (int)$conn->lastInsertId();

    $conn->prepare("INSERT INTO analysts (username, password_hash, full_name, email, is_active, is_admin, can_access_all_modules, can_access_all_tenants) VALUES ('zzafanalyst', '!', 'ZZAF analyst', 'zzaf@example.invalid', 1, 0, 1, 0)")->execute();
    $analyst = $made['analyst'] = (int)$conn->lastInsertId();
    $conn->prepare('INSERT INTO analyst_tenant_access (analyst_id, tenant_id) VALUES (?, ?)')->execute([$analyst, $home]);

    $admin = (int)$conn->query('SELECT MIN(id) FROM analysts WHERE is_admin = 1')->fetchColumn();
    $ctx = new ActorContext($admin, null, 'ui', 'en', 'ZZAF');
    $conn->prepare('INSERT INTO asset_types (name, is_active) VALUES (?, 1)')->execute(['ZZAF type']);
    $type = $made['type'] = (int)$conn->lastInsertId();
    $field = AssetFieldsService::saveField($conn, $ctx, ['label' => 'ZZAF recovery key', 'field_type' => 'text']);
    $made['field'] = (int)$field['id'];
    $set = $made['set'] = (int)AssetFieldsService::saveSet($conn, $ctx, ['name' => 'ZZAF security'])['id'];
    AssetFieldsService::setSetFields($conn, $set, [['field_id' => $made['field']]]);

    $ins = $conn->prepare('INSERT INTO assets (hostname, asset_type_id, tenant_id) VALUES (?, ?, ?)');
    $ins->execute(['ZZAF-OTHER-DC01', $type, $other]); $otherAsset = $made['assets'][] = (int)$conn->lastInsertId();
    $ins->execute(['ZZAF-HOME-PC01', $type, $home]);   $homeAsset  = $made['assets'][] = (int)$conn->lastInsertId();
    foreach ([$otherAsset, $homeAsset] as $id) {
        AssetFieldsService::attachSetToAsset($conn, $ctx, $id, $set);
    }
    AssetFieldsService::saveValues($conn, $ctx, $otherAsset, [$field['field_key'] => 'ZZAF-SECRET-482911']);

    $sid = 'zzaf' . bin2hex(random_bytes(6));
    $sessFile = "$sessDir/sess_$sid";
    file_put_contents($sessFile, 'analyst_id|i:' . $analyst . ';analyst_username|s:11:"zzafanalyst";analyst_name|s:4:"ZZAF";csrf_token|s:1:"x";');
    $http = function (string $path, ?array $post = null) use ($base, $sid) {
        $ch = curl_init("$base/$path");
        $o = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_COOKIE => "PHPSESSID=$sid", CURLOPT_HTTPHEADER => ['X-CSRF-Token: x', 'Content-Type: application/json']];
        if ($post !== null) { $o[CURLOPT_POST] = true; $o[CURLOPT_POSTFIELDS] = json_encode($post); }
        curl_setopt_array($ch, $o);
        return (string)curl_exec($ch);
    };
    $value = function (int $id) use ($conn, $field) {
        $st = $conn->prepare('SELECT COUNT(*) FROM asset_field_values WHERE asset_id = ? AND field_id = ? AND value_text = ?');
        $st->execute([$id, (int)$field['id'], 'ZZAF-SECRET-482911']);
        return (int)$st->fetchColumn() === 1;
    };
    $attached = fn(int $id) => (int)$conn->query("SELECT COUNT(*) FROM asset_field_set_assets WHERE asset_id = $id AND set_id = $set")->fetchColumn() === 1;

    echo "An analyst limited to one company, on the other company's asset\n";
    check('CONTROL: the asset history endpoint already refuses it', !str_contains($http("api/assets/get_asset_history.php?asset_id=$otherAsset"), '"success":true'));
    $r = $http("api/assets/get_asset_custom_fields.php?asset_id=$otherAsset");
    check('Reading its custom fields is refused', !str_contains($r, 'ZZAF-SECRET') && !str_contains($r, '"success":true'), substr($r, 0, 160));
    $r = $http('api/assets/save_asset_custom_fields.php', ['asset_id' => $otherAsset, 'values' => [$field['field_key'] => 'changed']]);
    check('Overwriting a value is refused, and the value is unchanged', !str_contains($r, '"success":true') && $value($otherAsset), substr($r, 0, 160));
    $r = $http('api/assets/set_asset_field_set.php', ['asset_id' => $otherAsset, 'set_id' => $set, 'action' => 'detach']);
    check('Removing its field set is refused, and the set is still attached', !str_contains($r, '"success":true') && $attached($otherAsset), substr($r, 0, 160));

    echo "...while their own company's asset works\n";
    check('Reading its custom fields works', str_contains($http("api/assets/get_asset_custom_fields.php?asset_id=$homeAsset"), 'ZZAF recovery key'));
    check('Saving a value works', str_contains($http('api/assets/save_asset_custom_fields.php', ['asset_id' => $homeAsset, 'values' => [$field['field_key'] => 'ok']]), '"success":true'));
    check('Removing the field set works', str_contains($http('api/assets/set_asset_field_set.php', ['asset_id' => $homeAsset, 'set_id' => $set, 'action' => 'detach']), '"success":true') && !$attached($homeAsset));
} catch (Throwable $e) {
    check('no exception', false, $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    if ($made['assets']) {
        $in = implode(',', $made['assets']);
        foreach (['asset_field_values', 'asset_field_set_assets', 'asset_field_not_applicable', 'asset_history'] as $tb) {
            try { $conn->exec("DELETE FROM $tb WHERE asset_id IN ($in)"); } catch (Throwable $e) {}
        }
        $conn->exec("DELETE FROM assets WHERE id IN ($in)");
    }
    if ($made['set'])   { $conn->exec('DELETE FROM asset_field_set_fields WHERE set_id = ' . $made['set']); $conn->exec('DELETE FROM asset_field_sets WHERE id = ' . $made['set']); }
    if ($made['field']) { $conn->exec('DELETE FROM asset_field_set_fields WHERE field_id = ' . $made['field']); $conn->exec('DELETE FROM asset_fields WHERE id = ' . $made['field']); }
    if ($made['type'])  $conn->exec('DELETE FROM asset_types WHERE id = ' . $made['type']);
    if ($made['analyst']) {
        foreach (['analyst_tenant_access', 'user_preferences'] as $tb) { try { $conn->exec("DELETE FROM $tb WHERE analyst_id = " . $made['analyst']); } catch (Throwable $e) {} }
        $conn->exec('DELETE FROM analysts WHERE id = ' . $made['analyst']);
    }
    if ($made['tenant']) $conn->exec('DELETE FROM tenants WHERE id = ' . $made['tenant']);
    if ($sessFile) @unlink($sessFile);
    $left = (int)$conn->query("SELECT (SELECT COUNT(*) FROM assets WHERE hostname LIKE 'ZZAF-%') + (SELECT COUNT(*) FROM tenants WHERE name LIKE 'ZZAF %') + (SELECT COUNT(*) FROM analysts WHERE username LIKE 'zzaf%') + (SELECT COUNT(*) FROM asset_fields WHERE label LIKE 'ZZAF %')")->fetchColumn();
    check('cleanup: nothing named ZZAF is left', $left === 0, "$left left");
}
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
