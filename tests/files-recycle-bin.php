<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * The Files recycle bin (includes/services/files_recycle.php).
 *
 * What this guards, each of which would lose or leak something:
 *   - the bin showing a folder the person could never open (hidden means not found)
 *   - restoring with a lower level than deleting needs, or over a live name
 *   - restoring into a folder that is itself in the bin
 *   - Delete permanently removing a stored file a COPY still uses
 *   - Delete permanently leaving the old copy behind during a storage move
 *   - storage being down making a purge fail, or the bytes being forgotten
 *   - the retention job deleting anything before an admin chose a period
 *   - a deleted folder showing (or letting someone destroy) a subfolder that was
 *     never shared with them
 *   - a restored folder's earlier-deleted contents being purged at once by retention
 *
 * Folders, files and grants are ZZ fixtures; the analysts are ids nobody has.
 * Stored files go to temporary folders. Everything runs inside ONE transaction
 * that is rolled back, settings included.
 *
 * With FREEITSM_TEST_S3_{ENDPOINT,KEY,SECRET,BUCKET} set (the bucket must exist) it also
 * purges from real S3-compatible storage: S3 as the only location, and moves local -> S3
 * and S3 -> local. Objects go under a random zz- prefix and are removed afterwards.
 *
 * Run: FREEITSM_TEST_DATABASE=<db> php tests/files-recycle-bin.php
 */

$root = dirname(__DIR__);
require_once "$root/config.php";
require_once "$root/includes/functions.php";
require_once "$root/includes/encryption.php";
require_once "$root/includes/services/files_recycle.php";
require_once "$root/includes/storage/s3_driver.php";

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
function folder(PDO $conn, ?int $parent, string $name): int {
    $conn->prepare("INSERT INTO files_folders (parent_id, name) VALUES (?, ?)")->execute([$parent, $name]);
    FilesAcl::reset();
    return (int)$conn->lastInsertId();
}
function grant(PDO $conn, int $folder, int $analyst, int $level): void {
    $conn->prepare("INSERT INTO files_permissions (folder_id, principal_type, principal_id, level) VALUES (?, 'analyst', ?, ?)")->execute([$folder, $analyst, $level]);
    FilesAcl::reset();
}
/** A file with one version; $key reuses a stored file (a copy), else a new one is written. */
function item(PDO $conn, string $dir, int $folder, string $name, ?string $key = null): array {
    if ($key === null) {
        $key = '2026/11/09/' . bin2hex(random_bytes(16)) . '.bin';
        @mkdir(dirname("$dir/$key"), 0777, true);
        file_put_contents("$dir/$key", "bytes of $name");
    }
    $size = strlen("bytes of $name");
    $conn->prepare("INSERT INTO files_items (folder_id, name, size_bytes) VALUES (?, ?, ?)")->execute([$folder, $name, $size]);
    $id = (int)$conn->lastInsertId();
    $conn->prepare("INSERT INTO files_versions (item_id, version_no, storage_path, size_bytes) VALUES (?, 1, ?, ?)")->execute([$id, $key, $size]);
    return ['id' => $id, 'key' => $key];
}
function softDelete(PDO $conn, string $type, int $id, int $by, string $when = 'UTC_TIMESTAMP()'): void {
    $t = $type === 'folder' ? 'files_folders' : 'files_items';
    $conn->prepare("UPDATE $t SET deleted_by = ?, deleted_datetime = $when WHERE id = ?")->execute([$by, $id]);
    FilesAcl::reset();
}
function keys(array $entries): array {
    return array_map(fn($e) => $e['type'] . ':' . $e['name'], $entries);
}
function rowExists(PDO $conn, string $table, int $id): bool {
    $s = $conn->prepare("SELECT 1 FROM $table WHERE id = ?");
    $s->execute([$id]);
    return (bool)$s->fetchColumn();
}

$conn = connectToDatabase();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$dbName = (string)$conn->query('SELECT DATABASE()')->fetchColumn();
if (getenv('FREEITSM_TEST_DATABASE') !== $dbName) {
    echo "SKIPPED: set FREEITSM_TEST_DATABASE to the database this may write to (this one is $dbName).\n";
    exit(0);
}

echo "\nFiles recycle bin\n" . str_repeat('=', 72) . "\n";
$store = sys_get_temp_dir() . '/zz-rb-' . bin2hex(random_bytes(4));
$old   = sys_get_temp_dir() . '/zz-rb-old-' . bin2hex(random_bytes(4));
mkdir($store); mkdir($old);
$U1 = 990000001;   // Modify on ZZ Projects
$U2 = 990000002;   // View on ZZ Projects only
$U3 = 990000003;   // the only one with access to ZZ Finance / ZZ HR

$conn->beginTransaction();
try {
    foreach (['files_storage_driver', 'files_storage_previous', 'files_storage_cleanup', 'files_recycle_bin_days'] as $k) setSetting($conn, $k, null);
    setSetting($conn, 'files_storage_root', $store);

    $proj   = folder($conn, null, 'ZZ Projects');
    $sub    = folder($conn, $proj, 'ZZ Old');
    $hidden = folder($conn, null, 'ZZ Hidden');
    grant($conn, $proj, $U1, FilesAcl::MODIFY);
    grant($conn, $proj, $U2, FilesAcl::VIEW);
    grant($conn, $hidden, $U1, FilesAcl::FULL);

    $plan  = item($conn, $store, $proj, 'zz-plan.pdf');
    $copy  = item($conn, $store, $proj, 'zz-plan copy.pdf', $plan['key']);   // shares the stored file
    $inSub = item($conn, $store, $sub, 'zz-notes.txt');
    $early = item($conn, $store, $sub, 'zz-early.txt');
    $secret = item($conn, $store, $hidden, 'zz-secret.txt');
    $conn->prepare("INSERT INTO files_shortcuts (analyst_id, target_type, target_id, name) VALUES (?, 'item', ?, 'zz-shortcut')")->execute([$U1, $inSub['id']]);
    $shortcutId = (int)$conn->lastInsertId();

    // ── What is in the bin, and for whom ───────────────────────────────
    softDelete($conn, 'item', $early['id'], $U1);       // deleted on its own, before its folder
    softDelete($conn, 'folder', $sub, $U1);
    softDelete($conn, 'item', $plan['id'], $U1);
    softDelete($conn, 'folder', $hidden, $U1);

    $e1 = keys(FilesRecycleService::entries($conn, $U1));
    $e2 = FilesRecycleService::entries($conn, $U2);
    ok('U1 sees the deleted file, folder and the hidden folder they own',
       in_array('item:zz-plan.pdf', $e1, true) && in_array('folder:ZZ Old', $e1, true) && in_array('folder:ZZ Hidden', $e1, true), json_encode($e1));
    ok('files inside a deleted folder are not entries of their own',
       !in_array('item:zz-notes.txt', $e1, true) && !in_array('item:zz-early.txt', $e1, true), json_encode($e1));
    ok('U2 never sees a folder they could not open (hidden means not found)', !in_array('folder:ZZ Hidden', keys($e2), true), json_encode(keys($e2)));
    ok('U2 sees the rest, but may not change it', count($e2) === 2 && !$e2[0]['can_modify'] && !$e2[1]['can_modify'], json_encode($e2));
    $oldRow = array_values(array_filter(FilesRecycleService::entries($conn, $U1), fn($e) => $e['name'] === 'ZZ Old'))[0];
    ok('a deleted folder counts every file under it', $oldRow['files'] === 2, json_encode($oldRow));
    ok('no retention saved: no purge date shown', $oldRow['purge_at'] === null);

    try { FilesRecycleService::contents($conn, $U2, $hidden); ok('contents of a hidden folder refused', false); }
    catch (InvalidArgumentException $e) { ok('contents of a hidden folder refused', $e->getMessage() === 'Folder not found.', $e->getMessage()); }
    $c = FilesRecycleService::contents($conn, $U2, $sub);
    ok('contents lists the files inside a deleted folder', count($c['files']) === 2, json_encode($c));

    // ── Restore ────────────────────────────────────────────────────────
    try { FilesRecycleService::restore($conn, $U2, 'item', $plan['id']); ok('View is not enough to restore', false); }
    catch (InvalidArgumentException $e) { ok('View is not enough to restore', str_contains($e->getMessage(), 'permission'), $e->getMessage()); }
    try { FilesRecycleService::restore($conn, $U2, 'folder', $hidden); ok('a hidden entry is not found, not refused', false); }
    catch (InvalidArgumentException $e) { ok('a hidden entry is not found, not refused', $e->getMessage() === 'Folder not found.', $e->getMessage()); }

    try { FilesRecycleService::restore($conn, $U1, 'item', $early['id']); ok('a file whose folder is in the bin is blocked', false); }
    catch (FilesRecycleBlocked $e) { ok('a file whose folder is in the bin is blocked, naming the folder', ($e->first['id'] ?? 0) === $sub, json_encode($e->first)); }

    item($conn, $store, $proj, 'zz-plan.pdf');   // someone uploaded a new file with the same name
    $r = FilesRecycleService::restore($conn, $U1, 'item', $plan['id']);
    ok('a name clash restores as "Name (2)"', $r['renamed'] && $r['name'] === 'zz-plan (2).pdf', json_encode($r));
    ok('the restored file is live again', filesItem($conn, $plan['id']) !== null);

    $r = FilesRecycleService::restore($conn, $U1, 'folder', $sub);
    ok('restoring a folder brings its subtree back', isset(FilesAcl::tree($conn)[$sub]) && filesItem($conn, $inSub['id']) !== null);
    ok('a file deleted on its own before stays in the bin', in_array('item:zz-early.txt', keys(FilesRecycleService::entries($conn, $U1)), true));

    // ── Delete permanently ─────────────────────────────────────────────
    softDelete($conn, 'item', $copy['id'], $U1);
    ok('CONTROL: the shared stored file exists before the purge', is_file("$store/{$plan['key']}"));
    $p = FilesRecycleService::purge($conn, $U1, 'item', $copy['id']);
    ok('purging a copy removes its rows', !rowExists($conn, 'files_items', $copy['id']));
    ok('...but not the stored file the original still uses', is_file("$store/{$plan['key']}") && $p['bytes'] === 0, json_encode($p));

    softDelete($conn, 'folder', $sub, $U1);
    ok('CONTROL: the folder\'s stored files exist before the purge', is_file("$store/{$inSub['key']}") && is_file("$store/{$early['key']}"));
    try { FilesRecycleService::purge($conn, $U2, 'folder', $sub); ok('View is not enough to purge', false); }
    catch (InvalidArgumentException $e) { ok('View is not enough to purge', str_contains($e->getMessage(), 'permission'), $e->getMessage()); }
    $p = FilesRecycleService::purge($conn, $U1, 'folder', $sub);
    ok('purging a folder removes it and every file in it', !rowExists($conn, 'files_folders', $sub) && !rowExists($conn, 'files_items', $inSub['id']) && !rowExists($conn, 'files_items', $early['id']));
    ok('...and their stored files', !is_file("$store/{$inSub['key']}") && !is_file("$store/{$early['key']}"), json_encode($p));
    ok('...and shortcuts that pointed into it', !rowExists($conn, 'files_shortcuts', $shortcutId));
    ok('...and says how much it freed', $p['files'] === 2 && $p['bytes'] > 0 && $p['pending'] === 0, json_encode($p));

    // ── During a storage move: both locations ──────────────────────────
    $moved = item($conn, $store, $proj, 'zz-moved.txt');
    @mkdir(dirname("$old/{$moved['key']}"), 0777, true);
    copy("$store/{$moved['key']}", "$old/{$moved['key']}");
    setSetting($conn, 'files_storage_previous', encryptValue(json_encode(['driver' => 'local', 'root' => $old])));
    $conn->prepare("INSERT INTO storage_migration_items (store, object_key, size_bytes, state) VALUES ('files', ?, 1, 'verified')")->execute([$moved['key']]);
    softDelete($conn, 'item', $moved['id'], $U1);
    FilesRecycleService::purge($conn, $U1, 'item', $moved['id']);
    $s = $conn->prepare("SELECT COUNT(*) FROM storage_migration_items WHERE object_key = ?");
    $s->execute([$moved['key']]);
    ok('during a move the stored file goes from BOTH locations', !is_file("$store/{$moved['key']}") && !is_file("$old/{$moved['key']}"));
    ok('...and the move forgets it', (int)$s->fetchColumn() === 0);

    // ── Storage down: queued, then retried ─────────────────────────────
    $down = item($conn, $store, $proj, 'zz-down.txt');
    setSetting($conn, 'files_storage_previous', encryptValue(json_encode([
        'driver' => 's3', 'endpoint' => 'http://127.0.0.1:9', 'region' => 'us-east-1', 'bucket' => 'zz',
        'access_key_id' => 'zz', 'secret' => 'zz', 'prefix' => '', 'path_style' => true])));
    softDelete($conn, 'item', $down['id'], $U1);
    $p = FilesRecycleService::purge($conn, $U1, 'item', $down['id']);
    $s = $conn->prepare("SELECT COUNT(*) FROM files_purge_pending WHERE storage_path = ?");
    $s->execute([$down['key']]);
    ok('storage down: the purge still succeeds for the person', !rowExists($conn, 'files_items', $down['id']) && $p['pending'] === 1, json_encode($p));
    ok('...and the stored file waits for the job', (int)$s->fetchColumn() === 1);
    setSetting($conn, 'files_storage_previous', null);
    $b = FilesRecycleService::retryPendingBytes($conn, time() + 30);
    $s->execute([$down['key']]);
    ok('the job removes it once storage answers', $b['deleted'] === 1 && (int)$s->fetchColumn() === 0 && !is_file("$store/{$down['key']}"), json_encode($b));

    // ── Retention ──────────────────────────────────────────────────────
    $oldFile = item($conn, $store, $proj, 'zz-40-days.txt');
    $newFile = item($conn, $store, $proj, 'zz-10-days.txt');
    softDelete($conn, 'item', $oldFile['id'], $U1, 'UTC_TIMESTAMP() - INTERVAL 40 DAY');
    softDelete($conn, 'item', $newFile['id'], $U1, 'UTC_TIMESTAMP() - INTERVAL 10 DAY');
    $r = FilesRecycleService::purgeExpired($conn, time() + 30);
    ok('never saved: the job deletes nothing', $r['entries'] === 0 && rowExists($conn, 'files_items', $oldFile['id']), json_encode($r));
    FilesRecycleService::saveRetention($conn, $U1, 0);
    $r = FilesRecycleService::purgeExpired($conn, time() + 30);
    ok('0 days: kept for ever', $r['entries'] === 0 && rowExists($conn, 'files_items', $oldFile['id']));
    FilesRecycleService::saveRetention($conn, $U1, 30);
    $r = FilesRecycleService::purgeExpired($conn, time() + 30);
    ok('30 days: the 40-day-old entry goes', !rowExists($conn, 'files_items', $oldFile['id']) && !is_file("$store/{$oldFile['key']}"), json_encode($r));
    ok('...the 10-day-old one stays', rowExists($conn, 'files_items', $newFile['id']));
    try { FilesRecycleService::saveRetention($conn, $U1, -1); ok('a negative period is refused', false); }
    catch (InvalidArgumentException $e) { ok('a negative period is refused', true); }

    // ── A subfolder the deleter could never see ────────────────────────
    $fin = folder($conn, null, 'ZZ Finance');
    $hr  = folder($conn, $fin, 'ZZ HR');
    $conn->prepare("UPDATE files_folders SET inherit_permissions = 0 WHERE id = ?")->execute([$hr]);
    grant($conn, $fin, $U1, FilesAcl::MODIFY);
    grant($conn, $hr, $U3, FilesAcl::FULL);
    $budget = item($conn, $store, $fin, 'zz-budget.xlsx');
    $salary = item($conn, $store, $hr, 'zz-salaries.xlsx');
    softDelete($conn, 'folder', $fin, $U1);
    $peek = FilesRecycleService::contents($conn, $U1, $fin);
    ok('CONTROL: U1 can look inside the folder they deleted', in_array('zz-budget.xlsx', array_column($peek['files'], 'name'), true), json_encode($peek));
    ok('...but never sees the hidden subfolder\'s files or its name',
       !in_array('zz-salaries.xlsx', array_column($peek['files'], 'name'), true) && !str_contains(json_encode($peek), 'ZZ HR'), json_encode($peek));
    $finRow = array_values(array_filter(FilesRecycleService::entries($conn, $U1), fn($e) => $e['name'] === 'ZZ Finance'))[0];
    ok('...and its counts leave the hidden files out', $finRow['files'] === 1, json_encode($finRow));
    ok('...and it may be restored but not deleted for good', $finRow['can_modify'] && !$finRow['can_purge'], json_encode($finRow));
    try { FilesRecycleService::purge($conn, $U1, 'folder', $fin); ok('purging over a hidden subfolder is refused', false); }
    catch (InvalidArgumentException $e) { ok('purging over a hidden subfolder is refused', rowExists($conn, 'files_items', $salary['id']) && is_file("$store/{$salary['key']}"), $e->getMessage()); }

    // ── Restore restarts the clock for what was deleted inside ────────
    $box  = folder($conn, $proj, 'ZZ Box');
    $old1 = item($conn, $store, $box, 'zz-60-days.txt');
    softDelete($conn, 'item', $old1['id'], $U1, 'UTC_TIMESTAMP() - INTERVAL 60 DAY');
    softDelete($conn, 'folder', $box, $U1);
    FilesRecycleService::restore($conn, $U1, 'folder', $box);
    $st = $conn->prepare("SELECT deleted_datetime > UTC_TIMESTAMP() - INTERVAL 1 HOUR FROM files_items WHERE id = ?");
    $st->execute([$old1['id']]);
    ok('a file deleted inside a restored folder starts its time in the bin again', (int)$st->fetchColumn() === 1);
    $r = FilesRecycleService::purgeExpired($conn, time() + 30);   // retention is 30 days here
    ok('...so retention does not delete it straight away', rowExists($conn, 'files_items', $old1['id']), json_encode($r));

    // ── Saving a period says what it would delete; Empty works in slices ──
    $d = FilesRecycleService::describe($conn, 1);   // only zz-10-days.txt is older than a day
    ok('describe(days) counts what the next run would delete', $d['due'] === 1 && $d['entries'] >= 3, json_encode($d));
    ok('...and only that: 30 days would delete nothing yet', FilesRecycleService::describe($conn, 30)['due'] === 0);
    softDelete($conn, 'item', $old1['id'], $U1, 'UTC_TIMESTAMP() - INTERVAL 3 DAY');
    ok('...an entry older than the period is counted', FilesRecycleService::describe($conn, 1)['due'] === 2);
    $r = FilesRecycleService::emptyBin($conn, $U1, time() - 1);
    ok('Empty past its deadline does nothing and asks to be called again', $r['entries'] === 0 && $r['more'] === true, json_encode($r));

    // ── Empty, and the Storage tab's figures ───────────────────────────
    $u = FilesRecycleService::binUsage($conn);
    ok('binUsage counts what only deleted files use', $u['files'] >= 2, json_encode($u));   // zz-10-days + the hidden folder's file
    $r = FilesRecycleService::emptyBin($conn, $U2, time() + 30);
    ok('emptying as View-only purges nothing', $r['entries'] === 0 && rowExists($conn, 'files_items', $newFile['id']), json_encode($r));
    $r = FilesRecycleService::emptyBin($conn, $U1, time() + 30);
    $left = keys(FilesRecycleService::entries($conn, $U1));
    ok('emptying as U1 purges everything they may change', $r['entries'] >= 2 && $left === ['folder:ZZ Finance'], json_encode([$r, $left]));
    ok('...and leaves the folder with a hidden subfolder, saying so', $r['skipped'] === 1 && is_file("$store/{$salary['key']}"), json_encode($r));
    FilesRecycleService::restore($conn, $U1, 'folder', $fin);
    ok('restoring that folder brings the hidden subfolder back for its owner', isset(FilesAcl::tree($conn)[$hr]) && filesItem($conn, $salary['id']) !== null);
    ok('...including the hidden folder\'s stored file', !is_file("$store/{$secret['key']}"));

    // ── Real S3-compatible storage ─────────────────────────────────────
    $ep = getenv('FREEITSM_TEST_S3_ENDPOINT') ?: '';
    if ($ep === '') {
        echo "  SKIP S3 checks (set FREEITSM_TEST_S3_ENDPOINT, _KEY, _SECRET, _BUCKET)\n";
    } else {
        $s3cfg = ['driver' => 's3', 'endpoint' => $ep, 'region' => getenv('FREEITSM_TEST_S3_REGION') ?: 'us-east-1',
                  'bucket' => (string)getenv('FREEITSM_TEST_S3_BUCKET'), 'access_key_id' => (string)getenv('FREEITSM_TEST_S3_KEY'),
                  'secret' => (string)getenv('FREEITSM_TEST_S3_SECRET'), 'prefix' => 'zz-rb-' . bin2hex(random_bytes(4)) . '/', 'path_style' => true];
        $s3 = new S3Driver($s3cfg, 'files');
        $s3keys = [];
        /** A file whose bytes are in S3 (and, with $alsoLocal, in the local folder too). */
        $s3item = function (string $name, bool $alsoLocal = false) use ($conn, $proj, $store, $s3, &$s3keys): array {
            $it = item($conn, $store, $proj, $name);           // writes the local copy
            $s3->putFile($it['key'], "$store/{$it['key']}");   // and the S3 copy
            if (!$alsoLocal) @unlink("$store/{$it['key']}");
            $s3keys[] = $it['key'];
            return $it;
        };
        /** Points Files at the test bucket. */
        $useS3 = function () use ($conn, $s3cfg): void {
            setSetting($conn, 'files_storage_driver', 's3');
            foreach (['endpoint', 'region', 'bucket', 'access_key_id', 'prefix'] as $k) setSetting($conn, "files_s3_$k", $s3cfg[$k]);
            setSetting($conn, 'files_s3_path_style', '1');
            setSetting($conn, 'files_s3_secret', encryptValue($s3cfg['secret']));
        };
        try {
            // S3 is where files are stored.
            $useS3();
            setSetting($conn, 'files_storage_previous', null);
            $a = $s3item('zz-s3-a.txt');
            $aCopy = item($conn, $store, $proj, 'zz-s3-a copy.txt', $a['key']);
            ok('S3 CONTROL: the object is in the bucket', $s3->size($a['key']) !== null);
            softDelete($conn, 'item', $aCopy['id'], $U1);
            FilesRecycleService::purge($conn, $U1, 'item', $aCopy['id']);
            ok('S3: purging a copy keeps the shared object', $s3->size($a['key']) !== null);
            softDelete($conn, 'item', $a['id'], $U1);
            $p = FilesRecycleService::purge($conn, $U1, 'item', $a['id']);
            ok('S3: purging the last user deletes the object from the bucket', $s3->size($a['key']) === null && $p['pending'] === 0, json_encode($p));

            // Moving local -> S3: old copy in the folder, new copy in the bucket.
            setSetting($conn, 'files_storage_previous', encryptValue(json_encode(['driver' => 'local', 'root' => $store])));
            $b = $s3item('zz-s3-b.txt', true);
            softDelete($conn, 'item', $b['id'], $U1);
            FilesRecycleService::purge($conn, $U1, 'item', $b['id']);
            ok('move local -> S3: gone from the bucket AND the folder', $s3->size($b['key']) === null && !is_file("$store/{$b['key']}"));

            // Moving S3 -> local: old copy in the bucket, new copy in the folder.
            foreach (['files_storage_driver', 'files_s3_endpoint', 'files_s3_bucket', 'files_s3_prefix', 'files_s3_secret'] as $k) setSetting($conn, $k, null);
            setSetting($conn, 'files_storage_previous', encryptValue(json_encode($s3cfg)));
            $c = $s3item('zz-s3-c.txt', true);
            softDelete($conn, 'item', $c['id'], $U1);
            FilesRecycleService::purge($conn, $U1, 'item', $c['id']);
            ok('move S3 -> local: gone from the folder AND the bucket', $s3->size($c['key']) === null && !is_file("$store/{$c['key']}"));
        } finally {
            foreach ($s3keys as $k) { try { $s3->delete($k); } catch (Throwable $e) { } }
            setSetting($conn, 'files_storage_previous', null);
        }
    }
} catch (Throwable $e) {
    ok('no unexpected exception', false, get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
} finally {
    if ($conn->inTransaction()) $conn->rollBack();
    filesStorageReset();
    foreach ([$store, $old] as $d) {
        if (!is_dir($d)) continue;
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($d, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        @rmdir($d);
    }
}

echo str_repeat('-', 72) . "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
