<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * System -> Storage (#284) over HTTP: the connection (tested before it is saved, never
 * sent back), a store switched to S3 and moved by the job, the place locked while in use,
 * and Files using the same connection.
 *
 * Needs FREEITSM_TEST_DATABASE, FREEITSM_TEST_URL and an S3 endpoint
 * (FREEITSM_TEST_S3_ENDPOINT/KEY/SECRET/BUCKET; skipped without). Puts settings back.
 */

$root = dirname(__DIR__);
require_once "$root/config.php";
require_once "$root/includes/functions.php";
require_once "$root/includes/services/storage.php";
require_once "$root/includes/services/files_storage.php";

$pass = 0; $fail = 0;
function ok(string $label, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; printf("  PASS %-70s\n", $label); }
    else       { $fail++; printf("  FAIL %-70s %s\n", $label, $detail); }
}

echo "\nSystem -> Storage\n" . str_repeat('=', 72) . "\n";
$conn = connectToDatabase();
$dbName = (string)$conn->query('SELECT DATABASE()')->fetchColumn();
$s3 = getenv('FREEITSM_TEST_S3_ENDPOINT');
if (getenv('FREEITSM_TEST_DATABASE') !== $dbName || !getenv('FREEITSM_TEST_URL') || !$s3) {
    echo "\nSKIPPED: needs FREEITSM_TEST_DATABASE, FREEITSM_TEST_URL and FREEITSM_TEST_S3_*.\n\n$pass passed, $fail failed\n";
    exit($fail ? 1 : 0);
}
$base = rtrim((string)getenv('FREEITSM_TEST_URL'), '/') . '/';
$sessDir = rtrim(ini_get('session.save_path') ?: sys_get_temp_dir(), '/\\');
$admin = (int)$conn->query('SELECT id FROM analysts WHERE is_admin = 1 ORDER BY id LIMIT 1')->fetchColumn();
$csrf = bin2hex(random_bytes(16));
$cleanup = [];
$mkSess = function (int $id) use ($sessDir, $csrf, &$cleanup): string {
    $sid = bin2hex(random_bytes(16));
    file_put_contents("$sessDir/sess_$sid", 'analyst_id|i:' . $id . ';csrf_token|s:' . strlen($csrf) . ':"' . $csrf . '";');
    $cleanup[] = fn() => @unlink("$sessDir/sess_$sid");
    return $sid;
};
$api = function (string $sid, ?array $post = null, string $path = 'api/system/storage.php') use ($base, $csrf): array {
    $ch = curl_init($base . $path);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60, CURLOPT_COOKIE => 'PHPSESSID=' . $sid,
                            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-CSRF-Token: ' . $csrf]]);
    if ($post !== null) { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($post)); }
    $body = (string)curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, json_decode($body, true), $body];
};
$snap = $conn->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key LIKE 'storage\\_%' OR setting_key LIKE 'files\\_s3\\_%' OR setting_key LIKE 'files\\_storage\\_%'")->fetchAll(PDO::FETCH_KEY_PAIR);

try {
    $conn->exec("DELETE FROM system_settings WHERE setting_key LIKE 'storage\\_%' OR setting_key LIKE 'files\\_s3\\_%' OR setting_key LIKE 'files\\_storage\\_%'");
    $conn->exec("DELETE FROM storage_migration_items WHERE store IN ('tickets','changes','documents','recordings','warroom')");
    storeReset();
    $conn->prepare("INSERT INTO analysts (username, password_hash, full_name, email, is_active, is_admin, created_datetime) VALUES (?, 'x', 'ZZ Storage NonAdmin', 'zz-st@example.invalid', 1, 0, UTC_TIMESTAMP())")
         ->execute(['zzst' . bin2hex(random_bytes(3))]);
    $nonAdmin = (int)$conn->lastInsertId();
    $cleanup[] = fn() => $conn->prepare('DELETE FROM analysts WHERE id = ?')->execute([$nonAdmin]);
    $adminSid = $mkSess($admin);
    $userSid = $mkSess($nonAdmin);

    [$code] = $api($userSid);
    ok('a non-administrator gets 403', $code === 403, (string)$code);
    [$code, $j] = $api($adminSid);
    ok('an administrator: no connection yet, every store on its folder', $code === 200 && $j['connection'] === null
       && count($j['stores']) === 5 && array_unique(array_column($j['stores'], 'location')) === ['local'], substr(json_encode($j), 0, 200));

    // Files was given a connection on its own tab (#283): it shows here until saved here.
    $prefix = 'zz-page-' . bin2hex(random_bytes(3)) . '/';
    foreach (['endpoint' => $s3, 'region' => 'us-east-1', 'bucket' => getenv('FREEITSM_TEST_S3_BUCKET'), 'access_key_id' => getenv('FREEITSM_TEST_S3_KEY'),
              'secret' => encryptValue((string)getenv('FREEITSM_TEST_S3_SECRET')), 'prefix' => $prefix, 'path_style' => '1'] as $k => $v) {
        storeSaveSetting($conn, "files_s3_$k", (string)$v);
    }
    [$code, $j, $raw] = $api($adminSid);
    ok('the connection Files was given shows here, marked as such', ($j['connection_source'] ?? '') === 'files' && ($j['connection']['bucket'] ?? '') === getenv('FREEITSM_TEST_S3_BUCKET'));
    // (The test server's key ID and secret are the same word, so the field is checked, not the whole reply.)
    ok('the secret is never sent, only masked', ($j['connection']['secret'] ?? '') === maskSecret((string)getenv('FREEITSM_TEST_S3_SECRET'))
       && ($j['connection']['secret'] ?? '') !== (string)getenv('FREEITSM_TEST_S3_SECRET') && ($j['connection']['secret_set'] ?? false) === true);

    $connIn = ['endpoint' => $s3, 'region' => 'us-east-1', 'bucket' => getenv('FREEITSM_TEST_S3_BUCKET'), 'access_key_id' => getenv('FREEITSM_TEST_S3_KEY'),
               'secret' => '********', 'prefix' => $prefix, 'path_style' => true];
    [$code, $j] = $api($adminSid, ['action' => 'test', 'connection' => $connIn]);
    ok('test: the saved secret is used when the mask is sent back', $code === 200 && ($j['test']['ok'] ?? false) === true, json_encode($j));
    [$code, $j] = $api($adminSid, ['action' => 'save_connection', 'connection' => ['secret' => 'wrong'] + $connIn]);
    ok('save: refused when the test fails', $code === 400 && !empty($j['error']) && storeSetting($conn, 'storage_s3_bucket') === '', json_encode($j));
    [$code, $j] = $api($adminSid, ['action' => 'save_connection', 'connection' => ['endpoint' => 'http://169.254.169.254'] + $connIn]);
    ok('save: a link-local (cloud metadata) endpoint is refused', $code === 400, json_encode($j));
    [$code, $j] = $api($adminSid, ['action' => 'save_connection', 'connection' => $connIn]);
    storeReset();
    ok('save: works, and becomes the install\'s connection', $code === 200 && storeSetting($conn, 'storage_s3_bucket') === getenv('FREEITSM_TEST_S3_BUCKET')
       && storeSetting($conn, 'files_s3_bucket') === '', json_encode($j));
    [, $j] = $api($adminSid);
    ok('...and says so', ($j['connection_source'] ?? '') === 'storage');

    // Files' tab shows it read-only; Files' own switch uses it.
    [$code, $j] = $api($adminSid, null, 'api/files/settings.php?tab=storage');
    ok('Files -> Settings -> Storage: shows the shared connection, marked shared', $code === 200 && ($j['connection_shared'] ?? null) === true
       && ($j['s3']['bucket'] ?? '') === getenv('FREEITSM_TEST_S3_BUCKET'), substr(json_encode($j), 0, 300));

    // A store to S3: it moves through the job's batch.
    $tag = bin2hex(random_bytes(3));
    @mkdir(storeLocalRoot('warroom'), 0775, true);
    file_put_contents(storeLocalRoot('warroom') . "/zz-page-$tag.txt", 'page move ' . $tag);
    $cleanup[] = fn() => @unlink(storeLocalRoot('warroom') . "/zz-page-$tag.txt");
    [$code, $j] = $api($adminSid, ['action' => 'set_location', 'store' => 'warroom', 'location' => 's3']);
    ok('switch war-room attachments to S3: accepted, its files queued', $code === 200 && ($j['queued'] ?? 0) >= 1, json_encode($j));
    [, $j] = $api($adminSid);
    $w = array_values(array_filter($j['stores'], fn($s) => $s['store'] === 'warroom'))[0];
    ok('...the page shows the move', $w['location'] === 's3' && ($w['move']['pending'] ?? 0) >= 1, json_encode($w));
    [$code, $j] = $api($adminSid, ['action' => 'set_location', 'store' => 'warroom', 'location' => 'local']);
    ok('switching again during the move is refused', $code === 400, json_encode($j));
    storeMoveBatch($conn, 'warroom', time() + 60);
    storeReset();
    ok('the job copies it into the bucket', storeGetString($conn, 'warroom', "zz-page-$tag.txt") === 'page move ' . $tag
       && storeActive($conn, 'warroom')->size("zz-page-$tag.txt") !== null);
    $cleanup[] = fn() => storeDelete($conn, 'warroom', "zz-page-$tag.txt");

    // The place is locked while a store uses it; keys are not.
    [$code, $j] = $api($adminSid, ['action' => 'save_connection', 'connection' => ['prefix' => 'zz-other/'] + $connIn]);
    ok('another prefix while a store keeps files there: refused', $code === 400 && str_contains((string)($j['error'] ?? ''), 'back to the server folder'), json_encode($j));
    [$code, $j] = $api($adminSid, ['action' => 'save_connection', 'connection' => ['region' => 'us-east-1', 'path_style' => true] + $connIn]);
    ok('the same place with its keys: allowed', $code === 200, json_encode($j));

    [$code, $j] = $api($adminSid, ['action' => 'move_action', 'store' => 'warroom', 'move' => 'remove_old']);
    storeMoveBatch($conn, 'warroom', time() + 60);
    storeReset();
    ok('remove old copies: the move ends, the folder copy is gone', $code === 200 && storeMoveSummary($conn, 'warroom') === null
       && !is_file(storeLocalRoot('warroom') . "/zz-page-$tag.txt"), json_encode($j));
    [$code, $j] = $api($adminSid, ['action' => 'set_location', 'store' => 'nope', 'location' => 's3']);
    ok('an unknown store is a 400', $code === 400);
    [$code] = $api($adminSid, ['action' => 'nonsense']);
    ok('an unknown action is a 400', $code === 400);
} catch (Throwable $e) {
    ok('no unexpected error', false, get_class($e) . ': ' . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    foreach (array_reverse($cleanup) as $c) { try { $c(); } catch (Throwable $ignored) {} }
    $conn->exec("DELETE FROM storage_migration_items WHERE store IN ('tickets','changes','documents','recordings','warroom')");
    $conn->exec("DELETE FROM system_settings WHERE setting_key LIKE 'storage\\_%' OR setting_key LIKE 'files\\_s3\\_%' OR setting_key LIKE 'files\\_storage\\_%'");
    $i = $conn->prepare('INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?)');
    foreach ($snap as $k => $v) $i->execute([$k, $v]);
    storeReset();
}

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
