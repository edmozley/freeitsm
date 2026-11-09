<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * Files -> Settings -> Storage: saving a local folder or S3-compatible storage.
 *
 * Written with the S3 storage option. The settings page holds a cloud secret,
 * and decides where every file's bytes go, so: the secret must never travel
 * back to the browser, saving the form without retyping it must keep it,
 * S3 settings must be proven to work before they are saved, and a change of
 * location must queue every stored file exactly once for the move - a file
 * missed here is a file nobody can open after the old copies are removed.
 *
 * Everything runs inside ONE transaction that is rolled back. The S3 checks
 * use FREEITSM_TEST_S3_* (see tests/storage-s3-live.php) and are skipped
 * without them.
 *
 * Run: FREEITSM_TEST_DATABASE=<db> php tests/files-storage-settings.php
 */

$root = dirname(__DIR__);
require_once "$root/config.php";
require_once "$root/includes/functions.php";
require_once "$root/includes/files/audit.php";
require_once "$root/includes/files/storage.php";
require_once "$root/includes/services/files_storage.php";

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
function threw(callable $fn): ?Throwable { try { $fn(); return null; } catch (Throwable $e) { return $e; } }

$conn = connectToDatabase();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$dbName = (string)$conn->query('SELECT DATABASE()')->fetchColumn();
if (getenv('FREEITSM_TEST_DATABASE') !== $dbName) {
    echo "SKIPPED: set FREEITSM_TEST_DATABASE to the database this may write to (this one is $dbName).\n";
    exit(0);
}
$analystId = (int)$conn->query("SELECT id FROM analysts ORDER BY id LIMIT 1")->fetchColumn();
$ep = getenv('FREEITSM_TEST_S3_ENDPOINT') ?: '';
$s3 = ['endpoint' => $ep, 'region' => 'us-east-1', 'bucket' => getenv('FREEITSM_TEST_S3_BUCKET') ?: '',
       'access_key_id' => getenv('FREEITSM_TEST_S3_KEY') ?: '', 'secret' => getenv('FREEITSM_TEST_S3_SECRET') ?: '',
       'prefix' => 'zz-' . bin2hex(random_bytes(4)), 'path_style' => true];

echo "\nFiles storage settings\n" . str_repeat('=', 72) . "\n";
$a = sys_get_temp_dir() . '/zz-a-' . bin2hex(random_bytes(4));
$b = sys_get_temp_dir() . '/zz-b-' . bin2hex(random_bytes(4));
mkdir($a); mkdir($b);
$conn->beginTransaction();
try {
    foreach (['files_storage_driver', 'files_storage_previous', 'files_storage_cleanup', 'files_s3_endpoint', 'files_s3_bucket',
              'files_s3_access_key_id', 'files_s3_secret', 'files_s3_prefix', 'files_s3_path_style', 'files_s3_region'] as $k) setSetting($conn, $k, null);
    setSetting($conn, 'files_storage_root', $a);
    $conn->exec("DELETE FROM storage_migration_items WHERE store = 'files'");

    // Two stored paths, one shared by two versions (a copy shares bytes).
    $folder = (int)$conn->query("SELECT id FROM files_folders ORDER BY id LIMIT 1")->fetchColumn();
    if (!$folder) {
        $conn->prepare("INSERT INTO files_folders (name, created_by, created_datetime) VALUES ('ZZ storage test', ?, UTC_TIMESTAMP())")->execute([$analystId]);
        $folder = (int)$conn->lastInsertId();
    }
    $conn->prepare("INSERT INTO files_items (folder_id, name, size_bytes, mime_type, created_by, created_datetime) VALUES (?, 'ZZ one.txt', 5, 'text/plain', ?, UTC_TIMESTAMP())")->execute([$folder, $analystId]);
    $i1 = (int)$conn->lastInsertId();
    $conn->prepare("INSERT INTO files_items (folder_id, name, size_bytes, mime_type, created_by, created_datetime) VALUES (?, 'ZZ two.txt', 5, 'text/plain', ?, UTC_TIMESTAMP())")->execute([$folder, $analystId]);
    $i2 = (int)$conn->lastInsertId();
    $kA = '2026/11/08/' . str_repeat('d', 32) . '.bin';
    $kB = '2026/11/08/' . str_repeat('e', 32) . '.bin';
    $v = $conn->prepare("INSERT INTO files_versions (item_id, version_no, storage_path, size_bytes, mime_type, sha256, uploaded_by, uploaded_datetime) VALUES (?, 1, ?, 5, 'text/plain', NULL, ?, UTC_TIMESTAMP())");
    $v->execute([$i1, $kA, $analystId]);
    $v->execute([$i2, $kA, $analystId]);
    $conn->prepare("INSERT INTO files_versions (item_id, version_no, storage_path, size_bytes, mime_type, sha256, uploaded_by, uploaded_datetime) VALUES (?, 2, ?, 5, 'text/plain', NULL, ?, UTC_TIMESTAMP())")->execute([$i1, $kB, $analystId]);
    $total = (int)$conn->query("SELECT COUNT(DISTINCT storage_path) FROM files_versions")->fetchColumn();

    // The masked secret means "keep it"; a NEW secret that happens to start with "*" does not.
    ok('secret: empty and the mask keep the stored one', FilesStorageService::secretUnchanged('', 'abcd1234')
        && FilesStorageService::secretUnchanged('********', 'abcd1234') && FilesStorageService::secretUnchanged(maskSecret('abcd1234'), 'abcd1234'));
    ok('secret: a new one starting with * is a new secret', !FilesStorageService::secretUnchanged('*Kx9-new', 'abcd1234'));

    // The Test button must not become a way to make the server call the cloud
    // metadata service (which hands out the server's own credentials).
    foreach (['http://169.254.169.254', 'http://[fd00:ec2::254]'] as $meta) {
        $r = FilesStorageService::test(['endpoint' => $meta, 'bucket' => 'b', 'access_key_id' => 'k', 'secret' => 's', 'path_style' => true]);
        ok("metadata endpoint $meta is refused", $r['ok'] === false && str_contains((string)$r['error'], 'not allowed'), json_encode($r));
    }

    ok('describe says whether the background scheduler is running', is_bool(FilesStorageService::describe($conn)['scheduler_running'] ?? null));

    $e = threw(fn() => FilesStorageService::save($conn, $analystId, ['driver' => 'local', 'storage_root' => '/no/such/zz-folder', 'max_upload_mb' => 100]));
    ok('control: local folder that does not exist is refused', $e instanceof InvalidArgumentException, $e ? $e->getMessage() : 'saved');

    FilesStorageService::save($conn, $analystId, ['driver' => 'local', 'storage_root' => $a, 'max_upload_mb' => 123]);
    $n = (int)$conn->query("SELECT COUNT(*) FROM storage_migration_items WHERE store = 'files'")->fetchColumn();
    ok('same location saves without a migration', $n === 0 && filesSetting($conn, 'files_max_upload_mb') === '123' && filesPreviousStorageConfig($conn) === null);

    // The same folder typed another way is NOT a move: a move onto itself verifies every
    // file, and "Remove old copies" would then delete the only copies.
    FilesStorageService::save($conn, $analystId, ['driver' => 'local', 'storage_root' => $a . '/./', 'max_upload_mb' => 123]);
    $n = (int)$conn->query("SELECT COUNT(*) FROM storage_migration_items WHERE store = 'files'")->fetchColumn();
    ok('the same folder under another name is not a move', $n === 0 && filesPreviousStorageConfig($conn) === null, "$n rows queued");

    FilesStorageService::save($conn, $analystId, ['driver' => 'local', 'storage_root' => $b, 'max_upload_mb' => 123]);
    $n = (int)$conn->query("SELECT COUNT(*) FROM storage_migration_items WHERE store = 'files' AND state = 'pending'")->fetchColumn();
    ok('changing location queues every stored path once', $n === $total, "$n rows for $total paths");
    ok('the old location is remembered', realpath((string)(filesPreviousStorageConfig($conn)['root'] ?? '')) === realpath($a));
    ok('the new location is active', filesActiveStore($conn)->label() === $b);
    $d = FilesStorageService::describe($conn);
    ok('describe reports the move', ($d['migration']['pending'] ?? -1) === $total && ($d['migration']['total'] ?? -1) === $total, json_encode($d['migration'] ?? null));

    $e = threw(fn() => FilesStorageService::save($conn, $analystId, ['driver' => 'local', 'storage_root' => $a, 'max_upload_mb' => 123]));
    ok('second change while migrating is refused', $e instanceof InvalidArgumentException && str_contains($e->getMessage(), 'still running'), $e ? $e->getMessage() : 'saved');

    // An empty install: no stored files, so no move at all.
    setSetting($conn, 'files_storage_previous', null);
    $conn->exec("DELETE FROM storage_migration_items WHERE store = 'files'");
    $conn->exec("DELETE FROM files_versions");
    FilesStorageService::save($conn, $analystId, ['driver' => 'local', 'storage_root' => $a, 'max_upload_mb' => 123]);
    ok('empty install switches without a migration', filesPreviousStorageConfig($conn) === null && filesActiveStore($conn)->label() === $a);

    // An upload that picked its store just before someone saved a new location: its
    // bytes are in the OLD place, and its row commits after the move was queued.
    $used = filesStorageConfig($conn);
    $kx = '2026/11/08/' . str_repeat('f', 32) . '.bin';
    @mkdir(dirname("$a/$kx"), 0777, true);
    file_put_contents("$a/$kx", 'race!');
    FilesStorageService::save($conn, $analystId, ['driver' => 'local', 'storage_root' => $b, 'max_upload_mb' => 123]);
    FilesStorageService::queueIfMoved($conn, $used, $kx, 5, hash('sha256', 'race!'));
    $st = $conn->query("SELECT state FROM storage_migration_items WHERE store = 'files' AND object_key = '$kx'")->fetchColumn();
    ok('an upload that raced a save is queued and still readable', $st === 'pending' && filesStoreFor($conn, $kx)->label() === realpath($a), "state $st, reads from " . filesStoreFor($conn, $kx)->label());
    FilesStorageService::queueIfMoved($conn, filesStorageConfig($conn), '2026/11/08/' . str_repeat('9', 32) . '.bin', 1, null);
    ok('control: an upload to the current location is not queued', (int)$conn->query("SELECT COUNT(*) FROM storage_migration_items WHERE object_key LIKE '%9999.bin'")->fetchColumn() === 0);
    setSetting($conn, 'files_storage_previous', null);
    $conn->exec("DELETE FROM storage_migration_items WHERE store = 'files'");
    FilesStorageService::save($conn, $analystId, ['driver' => 'local', 'storage_root' => $a, 'max_upload_mb' => 123]);

    if ($ep === '') {
        echo "  SKIP S3 checks (no S3 test endpoint)\n";
    } else {
        $r = FilesStorageService::test($s3);
        ok('test connection passes with good credentials', $r['ok'] === true, json_encode($r));
        $r = FilesStorageService::test(['secret' => 'wrong'] + $s3);
        ok('bad credentials are refused with the failing step', $r['ok'] === false && in_array($r['step'], ['connect', 'write'], true) && $r['error'], json_encode($r));

        $e = threw(fn() => FilesStorageService::save($conn, $analystId, ['driver' => 's3', 'storage_root' => $a, 'max_upload_mb' => 123, 's3' => ['secret' => 'wrong'] + $s3]));
        ok('saving bad S3 settings is refused', $e instanceof InvalidArgumentException && filesSetting($conn, 'files_storage_driver') !== 's3', $e ? $e->getMessage() : 'saved');

        FilesStorageService::save($conn, $analystId, ['driver' => 's3', 'storage_root' => $a, 'max_upload_mb' => 123, 's3' => $s3]);
        ok('good S3 settings save', filesActiveStore($conn) instanceof S3Driver);
        $stored = $conn->query("SELECT setting_value FROM system_settings WHERE setting_key = 'files_s3_secret'")->fetchColumn();
        ok('the secret is stored encrypted', str_starts_with((string)$stored, 'ENC:'), (string)$stored);

        // (The test server may use the same string for key and secret, so look for the field, and for the stored ciphertext.)
        $desc = FilesStorageService::describe($conn);
        $json = json_encode($desc);
        ok('GET never returns the secret', !array_key_exists('secret', $desc['s3']) && !str_contains($json, (string)$stored)
            && ($s3['secret'] === $s3['access_key_id'] || !str_contains($json, $s3['secret'])) && $desc['s3']['secret_set'] === true, $json);

        FilesStorageService::save($conn, $analystId, ['driver' => 's3', 'storage_root' => $a, 'max_upload_mb' => 200, 's3' => ['secret' => maskSecret($s3['secret'])] + $s3]);
        filesStorageReset();
        ok('masked secret keeps the stored one', settingGetDecrypted($conn, 'files_s3_secret') === $s3['secret'] && filesSetting($conn, 'files_max_upload_mb') === '200');
    }
} catch (Throwable $e) {
    ok('no unexpected error', false, get_class($e) . ': ' . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    $conn->rollBack();
    filesStorageReset();
    foreach ([$a, $b] as $dir) {
        if (!is_dir($dir)) continue;
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($dir);
    }
}

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
