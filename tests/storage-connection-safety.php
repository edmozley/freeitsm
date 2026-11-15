<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * The S3 connection's safety (storage series S1, S2, S4 - PR #318 review follow-ups):
 *   S1  plain http:// to a public address is warned about (the files cross the internet
 *       unencrypted); a LAN or local server is not. A warning, never a block.
 *   S2  server-side encryption: "provider default" sends nothing, "AES-256" sends
 *       x-amz-server-side-encryption on every object written (and it is signed).
 *   S4  a failed connection Test is audited - endpoint, bucket, step and error, never the
 *       secret.
 *
 * Settings and log rows in one transaction that is rolled back. The live half needs
 * FREEITSM_TEST_S3_* (skipped without them).
 *   FREEITSM_TEST_DATABASE=<throwaway db> php tests/storage-connection-safety.php
 */

$root = dirname(__DIR__);
require_once "$root/config.php";
require_once "$root/includes/functions.php";
require_once "$root/includes/services/storage.php";
require_once "$root/includes/services/files_storage.php";
require_once "$root/includes/storage/s3_driver.php";

$pass = 0; $fail = 0;
function ok(string $what, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  PASS  {$what}\n"; }
    else       { $fail++; echo "  FAIL  {$what}" . ($detail !== '' ? "  <- {$detail}" : '') . "\n"; }
}

echo "\nThe S3 connection's safety\n" . str_repeat('=', 72) . "\n";
$conn = connectToDatabase();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
if (getenv('FREEITSM_TEST_DATABASE') !== (string)$conn->query('SELECT DATABASE()')->fetchColumn()) {
    exit("Set FREEITSM_TEST_DATABASE to the throwaway database config.php points at - this test writes (rolled back).\n");
}

echo "\nS1: plain http to a public address\n";
$warn = function (string $ep): bool { return storageEndpointInsecure($ep); };
ok('http:// to a public address is warned about', $warn('http://8.8.8.8:9000') === true);
ok('...not a private (LAN) address', $warn('http://192.168.1.10:9000') === false && $warn('http://10.6.5.13') === false);
ok('...not the server itself, nor a name that is only local', $warn('http://127.0.0.1:9000') === false && $warn('http://localhost:9000') === false && $warn('http://minio:9000') === false);
ok('...not https', $warn('https://8.8.8.8') === false);

echo "\nS2: server-side encryption\n";
$hdr = function (array $cfg): array {
    $m = new ReflectionMethod(S3Driver::class, 'objectHeaders');
    $m->setAccessible(true);
    return $m->invoke(new S3Driver(['endpoint' => 'https://s3.example.test', 'bucket' => 'b', 'access_key_id' => 'k', 'secret' => 's'] + $cfg, 'tickets'));
};
ok('provider default: no encryption header', $hdr([]) === [] && $hdr(['sse' => '']) === []);
ok('AES-256: x-amz-server-side-encryption on every object written', $hdr(['sse' => 'AES256']) === ['x-amz-server-side-encryption' => 'AES256']);
ok('anything else is not sent', $hdr(['sse' => 'aws:kms; drop']) === []);

$conn->beginTransaction();
try {
    $conn->exec("DELETE FROM system_settings WHERE setting_key LIKE 'storage\\_%'");
    storeReset();
    storeSaveSetting($conn, 'storage_s3_bucket', 'zz-b');
    storeSaveSetting($conn, 'storage_s3_endpoint', 'http://8.8.8.8:9000');
    storeSaveSetting($conn, 'storage_s3_sse', 'AES256');
    storeReset();
    ok('the one connection carries the choice', (storageS3Connection($conn)['sse'] ?? null) === 'AES256');
    $d = StorageService::describe($conn);
    ok('System -> Storage shows the choice and the http warning', ($d['connection']['sse'] ?? null) === 'AES256' && ($d['connection']['insecure'] ?? null) === true, json_encode($d['connection']));

    echo "\nS4: a failed Test is audited\n";
    $logs = fn() => (int)$conn->query("SELECT COUNT(*) FROM system_logs WHERE log_type = 'storage' AND details LIKE '%test_failed%'")->fetchColumn();
    $before = $logs();
    $secret = 'zz-very-secret-' . bin2hex(random_bytes(4));
    $t = StorageService::test($conn, ['endpoint' => 'http://127.0.0.1:9', 'bucket' => 'zz-test', 'access_key_id' => 'zzkey', 'secret' => $secret], 990000001);
    ok('CONTROL: the test fails', $t['ok'] === false);
    ok('System -> Storage: the failed Test is in the log', $logs() === $before + 1);
    $row = (string)$conn->query("SELECT details FROM system_logs WHERE log_type = 'storage' AND details LIKE '%test_failed%' ORDER BY id DESC LIMIT 1")->fetchColumn();
    ok('...with the endpoint, bucket and step - never the secret', str_contains($row, '127.0.0.1') && str_contains($row, 'zz-test') && !str_contains($row, $secret), $row);
    $fBefore = (int)$conn->query("SELECT COUNT(*) FROM files_audit WHERE action = 'settings' AND detail LIKE '%test_failed%'")->fetchColumn();
    FilesStorageService::testAudited($conn, 990000001, ['endpoint' => 'http://127.0.0.1:9', 'bucket' => 'zz-test', 'access_key_id' => 'zzkey', 'secret' => $secret]);
    $fRow = (string)$conn->query("SELECT detail FROM files_audit WHERE action = 'settings' AND detail LIKE '%test_failed%' ORDER BY id DESC LIMIT 1")->fetchColumn();
    ok('Files -> Storage: the failed Test is in the Files audit, without the secret',
       (int)$conn->query("SELECT COUNT(*) FROM files_audit WHERE action = 'settings' AND detail LIKE '%test_failed%'")->fetchColumn() === $fBefore + 1 && !str_contains($fRow, $secret), $fRow);

    $ep = getenv('FREEITSM_TEST_S3_ENDPOINT') ?: '';
    if ($ep === '') {
        echo "  SKIP  live S3 (set FREEITSM_TEST_S3_*)\n";
    } else {
        echo "\nS2 against the test S3 server\n";
        $d = new S3Driver(['endpoint' => $ep, 'bucket' => (string)getenv('FREEITSM_TEST_S3_BUCKET'), 'access_key_id' => (string)getenv('FREEITSM_TEST_S3_KEY'),
                           'secret' => (string)getenv('FREEITSM_TEST_S3_SECRET'), 'path_style' => true, 'prefix' => 'zz-sse-' . bin2hex(random_bytes(3)) . '/', 'sse' => 'AES256'], 'tickets');
        $tmp = tempnam(sys_get_temp_dir(), 'zzs');
        file_put_contents($tmp, 'encrypted at rest');
        try {
            $d->putFile('a.txt', $tmp);
            $back = tempnam(sys_get_temp_dir(), 'zzb');
            $d->readTo('a.txt', $back);
            ok('a file stored with AES-256 reads back', file_get_contents($back) === 'encrypted at rest');
            @unlink($back);
            $d->delete('a.txt');
        } catch (Throwable $e) {
            // A provider that cannot encrypt (a test server with no key configured) refuses
            // the header it UNDERSTOOD - never a signature failure, which would mean the
            // header was sent unsigned. The Test on the page shows the same refusal.
            $m = $e->getMessage();
            ok('AES-256 refused by a provider that cannot do it - clearly, and not as a signature failure',
               str_contains($m, 'HTTP 400') && !str_contains($m, 'SignatureDoesNotMatch'), $m);
        } finally { @unlink($tmp); }
    }
} catch (Throwable $e) {
    ok('no unexpected error', false, get_class($e) . ': ' . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    if ($conn->inTransaction()) $conn->rollBack();
    storeReset();
}

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
