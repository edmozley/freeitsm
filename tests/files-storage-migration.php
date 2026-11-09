<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * Moving Files' stored bytes from one location to another (the storage_migrate job).
 *
 * Written with the S3 storage option, where changing Files -> Settings ->
 * Storage moves every stored file in the background. The failures this guards
 * would all lose files: a copy marked done that never arrived or arrived
 * damaged, a file uploaded during the move being sent back to the old place,
 * and "Remove old copies" deleting something that was never copied.
 *
 * Local folder to local folder (two temporary folders) - the job does not care
 * which driver is on either side, and S3 is covered by tests/storage-s3-live.php.
 * Everything runs inside ONE transaction that is rolled back.
 *
 * Run: FREEITSM_TEST_DATABASE=<db> php tests/files-storage-migration.php
 */

$root = dirname(__DIR__);
require_once "$root/config.php";
require_once "$root/includes/functions.php";
require_once "$root/includes/files/audit.php";
require_once "$root/includes/files/storage.php";
require_once "$root/includes/files/storage_migration.php";
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
function put(string $dir, string $key, string $bytes): void {
    @mkdir(dirname("$dir/$key"), 0777, true);
    file_put_contents("$dir/$key", $bytes);
}
function stateOf(PDO $conn, string $key): string {
    $s = $conn->prepare("SELECT CONCAT(state, '/', attempts) FROM storage_migration_items WHERE store = 'files' AND object_key = ?");
    $s->execute([$key]);
    return (string)$s->fetchColumn();
}
function queue(PDO $conn, string $key, int $size, ?string $sha): void {
    $conn->prepare("INSERT INTO storage_migration_items (store, object_key, size_bytes, sha256, state) VALUES ('files', ?, ?, ?, 'pending')")
         ->execute([$key, $size, $sha]);
}

$conn = connectToDatabase();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$dbName = (string)$conn->query('SELECT DATABASE()')->fetchColumn();
if (getenv('FREEITSM_TEST_DATABASE') !== $dbName) {
    echo "SKIPPED: set FREEITSM_TEST_DATABASE to the database this may write to (this one is $dbName).\n";
    exit(0);
}

echo "\nFiles storage migration\n" . str_repeat('=', 72) . "\n";
$new = sys_get_temp_dir() . '/zz-new-' . bin2hex(random_bytes(4));
$old = sys_get_temp_dir() . '/zz-old-' . bin2hex(random_bytes(4));
mkdir($new); mkdir($old);
$conn->beginTransaction();
try {
    foreach (['files_storage_driver', 'files_storage_cleanup'] as $k) setSetting($conn, $k, null);
    setSetting($conn, 'files_storage_root', $new);
    setSetting($conn, 'files_storage_previous', encryptValue(json_encode(['driver' => 'local', 'root' => $old])));
    $conn->exec("DELETE FROM storage_migration_items WHERE store = 'files'");

    $k = fn(string $c) => '2026/11/08/' . str_repeat($c, 32) . '.bin';
    put($old, $k('1'), 'one!!');  queue($conn, $k('1'), 5, hash('sha256', 'one!!'));
    put($old, $k('2'), 'two!!');  queue($conn, $k('2'), 5, null);                       // no hash recorded
    put($old, $k('3'), 'bad!!');  queue($conn, $k('3'), 5, hash('sha256', 'other'));    // damaged
    queue($conn, $k('4'), 5, null);                                                   // old copy missing

    $r = storageMigrateBatch($conn, time() - 1);
    ok('deadline stops the batch', $r['copied'] === 0 && stateOf($conn, $k('1')) === 'pending/0', json_encode($r));

    // The NEW store is down: the batch stops at once and charges no file an attempt
    // (otherwise an hour-long outage turns every file 'failed').
    foreach (['files_storage_driver' => 's3', 'files_s3_endpoint' => 'http://127.0.0.1:9', 'files_s3_bucket' => 'zz',
              'files_s3_access_key_id' => 'zz', 'files_s3_secret' => encryptValue('zz'), 'files_s3_path_style' => '1'] as $sk => $sv) setSetting($conn, $sk, $sv);
    $r = storageMigrateBatch($conn, time() + 60);
    ok('an unreachable store stops the batch without charging attempts', $r['copied'] === 0 && $r['failed'] === 0 && !empty($r['unavailable'])
        && stateOf($conn, $k('1')) === 'pending/0' && stateOf($conn, $k('2')) === 'pending/0', json_encode($r) . ' ' . stateOf($conn, $k('1')));
    setSetting($conn, 'files_storage_driver', null);

    ok('files are staged in the local working folder, not the system temp folder', storageMigrateTempDir($conn) === filesStorageRoot($conn) . '/_incoming'
        && is_dir(storageMigrateTempDir($conn)), storageMigrateTempDir($conn));

    ok('reads come from the old store before copying', filesStoreFor($conn, $k('1'))->label() === $old);
    $r = storageMigrateBatch($conn, time() + 60);
    ok('pending rows are copied and verified', is_file("$new/" . $k('1')) && stateOf($conn, $k('1')) === 'verified/0' && stateOf($conn, $k('2')) === 'verified/0', json_encode($r));
    ok('a missing hash is recorded from the copy', (string)$conn->query("SELECT sha256 FROM storage_migration_items WHERE object_key = '" . $k('2') . "'")->fetchColumn() === hash('sha256', 'two!!'));
    ok('reads switch to the new store as each row verifies', filesStoreFor($conn, $k('1'))->label() === $new);
    ok('wrong hash fails the row and leaves it unread from the new store', stateOf($conn, $k('3')) === 'pending/1' && !is_file("$new/" . $k('3')) && filesStoreFor($conn, $k('3'))->label() === $old, stateOf($conn, $k('3')));
    ok('control: a missing old copy fails rather than marking verified', stateOf($conn, $k('4')) === 'pending/1', stateOf($conn, $k('4')));
    ok('the batch reports what happened', $r['copied'] === 2 && $r['failed'] === 2 && $r['done'] === false, json_encode($r));

    for ($i = 0; $i < 4; $i++) storageMigrateBatch($conn, time() + 60);
    ok('a row failing five times becomes failed', stateOf($conn, $k('3')) === 'failed/5', stateOf($conn, $k('3')));

    // A file uploaded during the move goes straight to the new store.
    $k5 = $k('5');
    $tmp = tempnam(sys_get_temp_dir(), 'zz');
    file_put_contents($tmp, 'five!');
    filesActiveStore($conn)->putFile($k5, $tmp, true);
    ok('upload during migration lands on the active store only', stateOf($conn, $k5) === '' && filesStoreFor($conn, $k5)->label() === $new && is_file("$new/$k5") && !is_file("$old/$k5"));

    // Removing old copies: only verified rows, then the move ends.
    $conn->exec("DELETE FROM storage_migration_items WHERE object_key IN ('" . $k('3') . "', '" . $k('4') . "')");
    setSetting($conn, 'files_storage_cleanup', '1');
    $r = storageMigrateBatch($conn, time() + 60);
    ok('cleanup deletes old copies and ends the migration', !is_file("$old/" . $k('1')) && !is_file("$old/" . $k('2'))
        && is_file("$new/" . $k('1')) && filesPreviousStorageConfig($conn) === null && filesSetting($conn, 'files_storage_cleanup') === ''
        && (int)$conn->query("SELECT COUNT(*) FROM storage_migration_items WHERE store = 'files'")->fetchColumn() === 0 && $r['done'], json_encode($r));
    ok('control: cleanup never touched a file it did not copy', is_file("$old/" . $k('3')));

    // Old and new turn out to be one place (another name for the same folder): the
    // copies verify against themselves, so cleanup must end the move WITHOUT deleting.
    $k6 = $k('6');
    put($new, $k6, 'six!!');
    setSetting($conn, 'files_storage_previous', encryptValue(json_encode(['driver' => 'local', 'root' => $new . '/.'])));
    $conn->exec("DELETE FROM storage_migration_items WHERE store = 'files'");
    queue($conn, $k6, 5, hash('sha256', 'six!!'));
    storageMigrateBatch($conn, time() + 60);
    setSetting($conn, 'files_storage_cleanup', '1');
    $r = storageMigrateBatch($conn, time() + 60);
    ok('cleanup never deletes when old and new are the same place', is_file("$new/$k6") && filesPreviousStorageConfig($conn) === null && $r['done'], json_encode($r));

    // A move must never be stuck for ever: a file whose old copy is truly gone can be
    // skipped, and a move can end keeping every old copy.
    setSetting($conn, 'files_storage_previous', encryptValue(json_encode(['driver' => 'local', 'root' => $old])));
    $conn->exec("DELETE FROM storage_migration_items WHERE store = 'files'");
    $k7 = $k('7'); $k8 = $k('8');
    put($old, $k8, 'eight');
    queue($conn, $k7, 5, null);                    // old copy missing
    queue($conn, $k8, 5, hash('sha256', 'nope')); // old copy there, but damaged
    for ($i = 0; $i < 5; $i++) storageMigrateBatch($conn, time() + 60);
    FilesStorageService::migrationAction($conn, 0, 'skip_missing');
    ok('skip_missing skips only files whose old copy is gone', stateOf($conn, $k7) === 'skipped/5' && stateOf($conn, $k8) === 'failed/5', stateOf($conn, $k7) . ' ' . stateOf($conn, $k8));
    $e = null;
    try { FilesStorageService::migrationAction($conn, 0, 'finish_keep'); } catch (Throwable $x) { $e = $x; }
    ok('control: finish_keep refuses while a file failed with its old copy present', $e instanceof InvalidArgumentException && filesPreviousStorageConfig($conn) !== null);
    $conn->exec("DELETE FROM storage_migration_items WHERE object_key = '$k8'");
    FilesStorageService::migrationAction($conn, 0, 'finish_keep');
    ok('finish_keep ends the move and deletes nothing', filesPreviousStorageConfig($conn) === null && is_file("$old/$k8")
        && (int)$conn->query("SELECT COUNT(*) FROM storage_migration_items WHERE store = 'files'")->fetchColumn() === 0);
} catch (Throwable $e) {
    ok('no unexpected error', false, get_class($e) . ': ' . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    $conn->rollBack();
    filesStorageReset();
    foreach ([$new, $old] as $dir) {
        if (!is_dir($dir)) continue;
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($dir);
    }
}

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
