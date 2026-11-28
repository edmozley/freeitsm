<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * The Files recycle bin on an install that has not run Database Verification since
 * upgrading: no files_purge_pending table (includes/services/files_recycle.php).
 *
 * Nothing may fail for want of it. Delete permanently, Empty and the retention job
 * delete the stored files at once, as before the table existed; storage that cannot
 * be reached is logged loudly (the bytes cannot be queued); the job's retry finds
 * nothing to do; the settings tab says "unknown", never a false 0.
 *
 *   FREEITSM_TEST_DATABASE=<throwaway db> php tests/files-recycle-before-verification.php
 *
 * The table is RENAMED away for the run and renamed back in finally (its rows, if
 * any, go and come back with it). Everything else is ZZ fixtures in one transaction
 * that is rolled back; stored files go to a temporary folder.
 */

$root = dirname(__DIR__);
require_once "$root/config.php";
require_once "$root/includes/functions.php";
require_once "$root/includes/encryption.php";
require_once "$root/includes/services/files_recycle.php";

$pass = 0; $fail = 0;
function ok(string $label, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; printf("  PASS %-70s\n", $label); }
    else       { $fail++; printf("  FAIL %-70s %s\n", $label, $detail); }
}

$conn = connectToDatabase();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$dbName = (string)$conn->query('SELECT DATABASE()')->fetchColumn();
if (getenv('FREEITSM_TEST_DATABASE') !== $dbName) {
    echo "SKIPPED: set FREEITSM_TEST_DATABASE to the database this may write to (this one is $dbName).\n";
    exit(0);
}
$tableThere = fn(string $t): bool => (bool)$conn->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = " . $conn->quote($t))->fetchColumn();
if (!$tableThere('files_purge_pending')) {
    echo "SKIPPED: files_purge_pending is missing already - run Database Verification, then this test.\n";
    exit(0);
}

echo "\nFiles recycle bin before Database Verification (no files_purge_pending)\n" . str_repeat('=', 72) . "\n";
$hidden = 'zz_files_purge_pending_' . bin2hex(random_bytes(4));
$store = sys_get_temp_dir() . '/zz-rbv-' . bin2hex(random_bytes(4));
mkdir($store);
$U1 = 990000001;   // Modify on the ZZ folder; an id nobody has
$set = function (string $k, ?string $v) use ($conn): void {
    $conn->prepare("DELETE FROM system_settings WHERE setting_key = ?")->execute([$k]);
    if ($v !== null) $conn->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?)")->execute([$k, $v]);
    filesStorageReset();
};
/** A file with one version, its bytes in the temporary store; deleted $daysAgo days ago. */
$binned = function (int $folder, string $name, int $daysAgo = 0) use ($conn, $store, $U1): array {
    $key = '2026/11/21/' . bin2hex(random_bytes(16)) . '.bin';
    @mkdir(dirname("$store/$key"), 0777, true);
    file_put_contents("$store/$key", "bytes of $name");
    $conn->prepare("INSERT INTO files_items (folder_id, name, size_bytes, deleted_by, deleted_datetime)
                    VALUES (?, ?, 1, ?, UTC_TIMESTAMP() - INTERVAL ? DAY)")->execute([$folder, $name, $U1, $daysAgo]);
    $id = (int)$conn->lastInsertId();
    $conn->prepare("INSERT INTO files_versions (item_id, version_no, storage_path, size_bytes) VALUES (?, 1, ?, 1)")->execute([$id, $key]);
    FilesAcl::reset();
    return ['id' => $id, 'key' => $key];
};
$gone = function (int $id) use ($conn): bool {
    $s = $conn->prepare("SELECT COUNT(*) FROM files_items WHERE id = ?");
    $s->execute([$id]);
    return (int)$s->fetchColumn() === 0;
};

$renamed = false;
try {
    // DDL commits on its own: done before the transaction, undone after it.
    $conn->exec("RENAME TABLE files_purge_pending TO `$hidden`");
    $renamed = true;
    $conn->beginTransaction();
    foreach (['files_storage_driver', 'files_storage_previous', 'files_storage_cleanup', 'files_recycle_bin_days', 'files_recycle_bin_since'] as $k) $set($k, null);
    $set('files_storage_root', $store);
    // Whatever this database already has in its bin is not due in here (rolled back).
    $conn->exec("UPDATE files_folders SET deleted_datetime = UTC_TIMESTAMP() + INTERVAL 1 DAY WHERE deleted_datetime IS NOT NULL");
    $conn->exec("UPDATE files_items SET deleted_datetime = UTC_TIMESTAMP() + INTERVAL 1 DAY WHERE deleted_datetime IS NOT NULL");

    $conn->exec("INSERT INTO files_folders (parent_id, name) VALUES (NULL, 'ZZ Before verification')");
    $folder = (int)$conn->lastInsertId();
    $conn->prepare("INSERT INTO files_permissions (folder_id, principal_type, principal_id, level) VALUES (?, 'analyst', ?, ?)")->execute([$folder, $U1, FilesAcl::MODIFY]);
    FilesAcl::reset();

    $d = FilesRecycleService::describe($conn);
    ok('the settings tab: waiting and refused stored files are unknown, never a false 0', $d['pending_bytes'] === null && $d['refused_bytes'] === null, json_encode($d));

    $a = $binned($folder, 'zz-a.txt');
    $p = FilesRecycleService::purge($conn, $U1, 'item', $a['id']);
    ok('Delete permanently works: the rows go, and the stored file at once', $gone($a['id']) && !is_file("$store/{$a['key']}") && $p['pending'] === 0, json_encode($p));

    // Storage that cannot be reached: nothing to queue in - the person's purge still
    // succeeds, and the log says loudly that the bytes need removing by hand.
    $set('files_storage_previous', encryptValue(json_encode([
        'driver' => 's3', 'endpoint' => 'http://127.0.0.1:9', 'region' => 'us-east-1', 'bucket' => 'zz',
        'access_key_id' => 'zz', 'secret' => 'zz', 'prefix' => '', 'path_style' => true])));
    $b = $binned($folder, 'zz-b.txt');
    $logFile = tempnam(sys_get_temp_dir(), 'zzlog');
    $prevLog = ini_set('error_log', $logFile);
    try {
        $p = FilesRecycleService::purge($conn, $U1, 'item', $b['id']);
    } finally {
        ini_set('error_log', $prevLog === false ? '' : $prevLog);
    }
    $log = (string)file_get_contents($logFile);
    @unlink($logFile);
    ok('storage down: the purge still succeeds for the person', $gone($b['id']) && $p['pending'] === 1, json_encode($p));
    ok('...and the log says the stored file was NOT deleted and cannot be queued', str_contains($log, 'NOT deleted and cannot be queued: run Database Verification'), $log);
    $set('files_storage_previous', null);

    $r = FilesRecycleService::retryPendingBytes($conn, time() + 30);
    ok('the job\'s retry finds nothing queued, and does not fail', $r === ['deleted' => 0, 'still_pending' => 0, 'refused' => 0], json_encode($r));

    $c1 = $binned($folder, 'zz-c1.txt');
    $c2 = $binned($folder, 'zz-c2.txt');
    $r = FilesRecycleService::emptyBin($conn, $U1, time() + 30);
    ok('Empty works: every entry and its stored file go', $r['entries'] >= 2 && $r['failed'] === 0 && $gone($c1['id']) && $gone($c2['id'])
       && !is_file("$store/{$c1['key']}") && !is_file("$store/{$c2['key']}"), json_encode($r));

    $old = $binned($folder, 'zz-old.txt', 40);
    $new = $binned($folder, 'zz-new.txt', 10);
    FilesRecycleService::saveRetention($conn, $U1, 30);
    $set('files_recycle_bin_since', gmdate('Y-m-d H:i:s', time() - 60 * 86400));   // switched on 60 days ago
    $r = FilesRecycleService::purgeExpired($conn, time() + 30);
    ok('retention works: the 40-day-old entry and its stored file go', $gone($old['id']) && !is_file("$store/{$old['key']}") && $r['failed'] === 0, json_encode($r));
    ok('...the 10-day-old one stays', !$gone($new['id']) && is_file("$store/{$new['key']}"));
} catch (Throwable $e) {
    ok('no unexpected exception', false, get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    if ($conn->inTransaction()) $conn->rollBack();
    if ($renamed && !$tableThere('files_purge_pending')) $conn->exec("RENAME TABLE `$hidden` TO files_purge_pending");
    filesStorageReset();
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($store, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
    @rmdir($store);
}
ok('files_purge_pending is back', $tableThere('files_purge_pending') && !$tableThere($hidden));

echo str_repeat('-', 72) . "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
