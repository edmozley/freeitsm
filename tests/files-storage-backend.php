<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * Files storage: which store a stored file is read from.
 *
 * Written with the S3 storage option. Files now reads and writes through a
 * storage driver chosen in Files -> Settings -> Storage, and while files are
 * being moved to a new location every one not yet copied must still be read
 * from the old one - a file that disappears mid-move is the failure this
 * guards. Also: with no new settings saved, nothing changes (the default
 * folder, as before), and a store that cannot be reached is a 503 the person
 * can understand, not a white page.
 *
 * Everything runs inside ONE transaction that is rolled back, settings rows
 * included. Bytes go to two temporary folders that are removed at the end.
 *
 * Run: FREEITSM_TEST_DATABASE=<db> php tests/files-storage-backend.php
 */

$root = dirname(__DIR__);
require_once "$root/config.php";
require_once "$root/includes/functions.php";
require_once "$root/includes/encryption.php";
require_once "$root/includes/files/storage.php";

$pass = 0; $fail = 0;
function ok(string $label, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; printf("  PASS %-70s\n", $label); }
    else       { $fail++; printf("  FAIL %-70s %s\n", $label, $detail); }
}
function setSetting(PDO $conn, string $k, ?string $v): void {
    $conn->prepare("DELETE FROM system_settings WHERE setting_key = ?")->execute([$k]);
    if ($v !== null) $conn->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?)")->execute([$k, $v]);
    filesStorageReset();
}
function rmTree(string $d): void {
    if (!is_dir($d)) return;
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($d, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $f) {
        $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
    }
    rmdir($d);
}

$conn = connectToDatabase();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$dbName = (string)$conn->query('SELECT DATABASE()')->fetchColumn();
if (getenv('FREEITSM_TEST_DATABASE') !== $dbName) {
    echo "SKIPPED: set FREEITSM_TEST_DATABASE to the database this may write to (this one is $dbName).\n";
    exit(0);
}

echo "\nFiles storage backend\n" . str_repeat('=', 72) . "\n";
$a = sys_get_temp_dir() . '/zz-active-' . bin2hex(random_bytes(4));
$b = sys_get_temp_dir() . '/zz-previous-' . bin2hex(random_bytes(4));
mkdir($a); mkdir($b);
$conn->beginTransaction();
try {
    foreach (['files_storage_driver', 'files_storage_root', 'files_storage_previous'] as $k) setSetting($conn, $k, null);
    $d = filesActiveStore($conn);
    ok('no settings means local driver at the default root', $d instanceof LocalDriver && $d->label() === filesDefaultStorageRoot(), get_class($d) . ' ' . $d->label());

    setSetting($conn, 'files_storage_root', $a);
    setSetting($conn, 'files_storage_previous', encryptValue(json_encode(['driver' => 'local', 'root' => $b])));
    ok('previous config decodes', (filesPreviousStorageConfig($conn)['root'] ?? '') === $b);

    $k1 = '2026/11/08/' . str_repeat('1', 32) . '.bin';
    $k2 = '2026/11/08/' . str_repeat('2', 32) . '.bin';
    $k3 = '2026/11/08/' . str_repeat('3', 32) . '.bin';
    $conn->prepare("DELETE FROM storage_migration_items WHERE store = 'files' AND object_key IN (?, ?, ?)")->execute([$k1, $k2, $k3]);
    $ins = $conn->prepare("INSERT INTO storage_migration_items (store, object_key, size_bytes, state) VALUES ('files', ?, 5, ?)");
    $ins->execute([$k1, 'pending']);
    $ins->execute([$k2, 'verified']);

    ok('unverified key reads from the previous store', filesStoreFor($conn, $k1)->label() === $b, filesStoreFor($conn, $k1)->label());
    ok('verified key reads from the active store', filesStoreFor($conn, $k2)->label() === $a);
    ok('key with no migration row reads from the active store', filesStoreFor($conn, $k3)->label() === $a);

    // Serving bytes: the store decides, and the status says what happened.
    @mkdir(dirname("$b/$k1"), 0777, true);
    file_put_contents("$b/$k1", 'hello');
    ob_start();
    $began = false;
    $st = filesServeBytes($conn, ['storage_path' => $k1], 1, 3, function () use (&$began) { $began = true; });
    $out = ob_get_clean();
    ok('bytes come from the store that holds them', $st === 0 && $out === 'ell' && $began, "status $st, out '$out'");

    ob_start();
    $st = filesServeBytes($conn, ['storage_path' => $k3], 0, 1, fn() => null);
    ob_end_clean();
    ok('missing stored bytes give 410', $st === 410, "status $st");

    ob_start();
    $st = filesServeBytes($conn, ['storage_path' => '../../config.php'], 0, 1, fn() => null);
    ob_end_clean();
    ok('control: a key we could not have written gives 404', $st === 404, "status $st");

    // A previous-location setting that will not decrypt (key replaced, row damaged):
    // a clear 503 for the person, not a 500.
    setSetting($conn, 'files_storage_previous', 'ENC:' . base64_encode('damaged'));
    ob_start();
    $st = -1;
    try { $st = filesServeBytes($conn, ['storage_path' => $k1], 0, 1, fn() => null); } catch (Throwable $e) { $st = get_class($e); }
    ob_end_clean();
    ok('an undecryptable storage setting gives 503, not an error', $st === 503, var_export($st, true));

    setSetting($conn, 'files_storage_previous', null);
    setSetting($conn, 'files_storage_driver', 's3');
    setSetting($conn, 'files_s3_endpoint', 'http://127.0.0.1:9');
    setSetting($conn, 'files_s3_bucket', 'zz');
    setSetting($conn, 'files_s3_access_key_id', 'zz');
    setSetting($conn, 'files_s3_secret', encryptValue('zz'));
    setSetting($conn, 'files_s3_path_style', '1');
    ok('s3 settings give an S3 driver', filesActiveStore($conn) instanceof S3Driver);
    ob_start();
    $began = false;
    $st = filesServeBytes($conn, ['storage_path' => $k2], 0, 1, function () use (&$began) { $began = true; });
    ob_end_clean();
    ok('download returns 503 when the store is unavailable', $st === 503 && !$began, "status $st");
} catch (Throwable $e) {
    ok('no unexpected error', false, get_class($e) . ': ' . $e->getMessage() . ' @' . $e->getLine());
} finally {
    $conn->rollBack();
    filesStorageReset();
    rmTree($a); rmTree($b);
}

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
