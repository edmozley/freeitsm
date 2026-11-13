<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * Files versions and the same-name choices (GH #303).
 *
 *   - settings: the defaults (New version, Keep both, Skip; Overwrite off; every
 *     version kept), and saving refuses Skip alone, a highlighted choice that is
 *     not ticked, and a keep value not in the list;
 *   - restore makes a NEW current version sharing the old one's bytes, and the
 *     version that was current stays;
 *   - deleting the current version is refused; deleting an earlier one keeps bytes
 *     another version still shares, and deletes bytes nothing uses;
 *   - keep-the-newest-N deletes the oldest, never the current one;
 *   - overwrite leaves only the given version.
 *
 * Bytes go to a temporary storage folder; the database work is in one transaction
 * that is always rolled back.
 *   FREEITSM_TEST_DATABASE=<throwaway db> php tests/files-versions.php
 */

$root = dirname(__DIR__);
require_once "$root/config.php";
require_once "$root/includes/functions.php";
require_once "$root/includes/services/files_versions.php";

$pass = 0; $fail = 0;
function ok(string $what, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  PASS  {$what}\n"; }
    else       { $fail++; echo "  FAIL  {$what}" . ($detail !== '' ? "  <- {$detail}" : '') . "\n"; }
}
/** The file's row (the test's own copy of filesItem() - the API bootstrap demands a session). */
function itemRow(PDO $conn, int $id): array { $st = $conn->prepare("SELECT * FROM files_items WHERE id = ?"); $st->execute([$id]); return $st->fetch(PDO::FETCH_ASSOC); }
function threw(callable $fn): ?Throwable { try { $fn(); return null; } catch (Throwable $e) { return $e; } }

$conn = connectToDatabase();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
if (getenv('FREEITSM_TEST_DATABASE') !== (string)$conn->query('SELECT DATABASE()')->fetchColumn()) {
    exit("Set FREEITSM_TEST_DATABASE to the throwaway database config.php points at - this test writes (rolled back).\n");
}
$admin = (int)$conn->query("SELECT id FROM analysts WHERE is_active = 1 AND is_admin = 1 ORDER BY id LIMIT 1")->fetchColumn();
$dir = str_replace('\\', '/', sys_get_temp_dir()) . '/zz-ver-' . bin2hex(random_bytes(4));
mkdir($dir);

/** A stored version: bytes in the temp store, a files_versions row. */
function ver(PDO $conn, int $itemId, int $no, string $bytes, ?string $path = null): array {
    global $dir;
    $path ??= '2026/11/13/' . bin2hex(random_bytes(16)) . '.bin';
    @mkdir(dirname("$dir/$path"), 0777, true);
    file_put_contents("$dir/$path", $bytes);
    $conn->prepare("INSERT INTO files_versions (item_id, version_no, storage_path, size_bytes, sha256, uploaded_datetime) VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP())")
         ->execute([$itemId, $no, $path, strlen($bytes), hash('sha256', $bytes)]);
    return ['id' => (int)$conn->lastInsertId(), 'path' => $path];
}
function makeCurrent(PDO $conn, int $itemId, int $versionId): array {
    $conn->prepare("UPDATE files_items SET current_version_id = ? WHERE id = ?")->execute([$versionId, $itemId]);
    return itemRow($conn, $itemId);
}
function versionNos(PDO $conn, int $itemId): array {
    return array_map('intval', $conn->query("SELECT version_no FROM files_versions WHERE item_id = $itemId ORDER BY version_no")->fetchAll(PDO::FETCH_COLUMN));
}

$conn->beginTransaction();
try {
    foreach (['files_storage_driver', 'files_storage_previous', 'files_storage_cleanup', 'files_conflict_choices', 'files_conflict_default', 'files_keep_versions'] as $k) {
        $conn->prepare("DELETE FROM system_settings WHERE setting_key = ?")->execute([$k]);
    }
    filesSaveSetting($conn, 'files_storage_root', $dir);
    filesStorageReset();

    echo "\nSettings\n";
    $s = FilesVersionsService::settings($conn);
    ok('defaults: New version, Keep both, Skip; New version highlighted; every version kept',
       $s['choices'] === ['version', 'rename', 'skip'] && $s['default'] === 'version' && $s['keep'] === 0, json_encode($s));
    ok('Skip alone is refused', threw(fn() => FilesVersionsService::saveSettings($conn, $admin, ['choices' => ['skip'], 'default' => 'skip', 'keep' => 0])) instanceof InvalidArgumentException);
    ok('a highlighted choice that is not ticked is refused', threw(fn() => FilesVersionsService::saveSettings($conn, $admin, ['choices' => ['version'], 'default' => 'rename', 'keep' => 0])) instanceof InvalidArgumentException);
    ok('a keep value not in the list is refused', threw(fn() => FilesVersionsService::saveSettings($conn, $admin, ['choices' => ['version'], 'default' => 'version', 'keep' => 7])) instanceof InvalidArgumentException);
    FilesVersionsService::saveSettings($conn, $admin, ['choices' => ['version', 'overwrite', 'skip', 'bogus'], 'default' => 'overwrite', 'keep' => 3]);
    $s = FilesVersionsService::settings($conn);
    ok('saving all four is allowed, an unknown choice is dropped', $s['choices'] === ['version', 'overwrite', 'skip'] && $s['default'] === 'overwrite' && $s['keep'] === 3, json_encode($s));
    FilesVersionsService::saveSettings($conn, $admin, ['choices' => ['version', 'rename', 'skip'], 'default' => 'version', 'keep' => 0]);

    $conn->exec("INSERT INTO files_folders (name) VALUES ('ZZ-VER folder')");
    $folder = (int)$conn->lastInsertId();
    $conn->prepare("INSERT INTO files_items (folder_id, name) VALUES (?, 'zz.txt')")->execute([$folder]);
    $item = (int)$conn->lastInsertId();
    $v1 = ver($conn, $item, 1, 'one');
    $v2 = ver($conn, $item, 2, 'two');
    $it = makeCurrent($conn, $item, $v2['id']);

    echo "\nRestore\n";
    $no = FilesVersionsService::restore($conn, $admin, $it, $v1['id']);
    $it = itemRow($conn, $item);
    $cur = $conn->query("SELECT * FROM files_versions WHERE id = " . (int)$it['current_version_id'])->fetch(PDO::FETCH_ASSOC);
    ok('restore makes a new current version 3', $no === 3 && (int)$cur['version_no'] === 3, "no $no");
    ok('...sharing version 1\'s bytes, and saying so', $cur['storage_path'] === $v1['path'] && (int)$cur['restored_from_no'] === 1);
    ok('...and version 2 stays in the history', versionNos($conn, $item) === [1, 2, 3], json_encode(versionNos($conn, $item)));
    ok('restoring the current version is refused', threw(fn() => FilesVersionsService::restore($conn, $admin, $it, (int)$it['current_version_id'])) instanceof InvalidArgumentException);

    echo "\nDelete a version\n";
    ok('the current version cannot be deleted', threw(fn() => FilesVersionsService::deleteVersion($conn, $admin, $it, (int)$it['current_version_id'])) instanceof InvalidArgumentException);
    FilesVersionsService::deleteVersion($conn, $admin, $it, $v1['id']);
    ok('deleting version 1 keeps its bytes - version 3 still uses them', is_file($dir . '/' . $v1['path']) && versionNos($conn, $item) === [2, 3]);
    FilesVersionsService::deleteVersion($conn, $admin, $it, $v2['id']);
    ok('deleting version 2 deletes its bytes - nothing else uses them', !is_file($dir . '/' . $v2['path']) && versionNos($conn, $item) === [3]);
    ok('a version of another file is refused', threw(fn() => FilesVersionsService::deleteVersion($conn, $admin, $it, 999999999)) instanceof InvalidArgumentException);

    echo "\nKeep the newest N\n";
    $conn->prepare("INSERT INTO files_items (folder_id, name) VALUES (?, 'zz2.txt')")->execute([$folder]);
    $item2 = (int)$conn->lastInsertId();
    $vs = []; for ($i = 1; $i <= 5; $i++) $vs[$i] = ver($conn, $item2, $i, "v$i");
    makeCurrent($conn, $item2, $vs[2]['id']);                     // an OLD version is current (restored by hand)
    $gone = FilesVersionsService::applyKeep($conn, $item2, 2);
    ok('keeping 2 of 5: the current one (2) and the newest (5) stay', versionNos($conn, $item2) === [2, 5] && $gone === 3, json_encode(versionNos($conn, $item2)));
    ok('...and the deleted versions\' bytes are gone', !is_file($dir . '/' . $vs[1]['path']) && !is_file($dir . '/' . $vs[4]['path']) && is_file($dir . '/' . $vs[2]['path']));
    ok('keeping 0 (every version) deletes nothing', FilesVersionsService::applyKeep($conn, $item2, 0) === 0);

    echo "\nOverwrite\n";
    $conn->prepare("INSERT INTO files_items (folder_id, name) VALUES (?, 'zz3.txt')")->execute([$folder]);
    $item3 = (int)$conn->lastInsertId();
    $a = ver($conn, $item3, 1, 'a'); $b = ver($conn, $item3, 2, 'b'); $c = ver($conn, $item3, 3, 'c');
    $it3 = makeCurrent($conn, $item3, $c['id']);
    FilesVersionsService::overwrite($conn, $admin, $it3, $c['id']);
    ok('overwrite leaves only the new version', versionNos($conn, $item3) === [3] && !is_file($dir . '/' . $a['path']) && !is_file($dir . '/' . $b['path']) && is_file($dir . '/' . $c['path']));

    echo "\nShared bytes (a copied file)\n";
    $conn->prepare("INSERT INTO files_items (folder_id, name) VALUES (?, 'copy.txt')")->execute([$folder]);
    $copy = (int)$conn->lastInsertId();
    ver($conn, $copy, 1, 'c', $c['path']);                    // a copy shares version 3's bytes
    $conn->prepare("INSERT INTO files_items (folder_id, name) VALUES (?, 'zz4.txt')")->execute([$folder]);
    $item4 = (int)$conn->lastInsertId();
    $x = ver($conn, $item4, 1, 'x'); $y = ver($conn, $item4, 2, 'c', $c['path']);
    $it4 = makeCurrent($conn, $item4, $x['id']);
    FilesVersionsService::deleteVersion($conn, $admin, $it4, $y['id']);
    ok('bytes a copied file still uses are never deleted', is_file($dir . '/' . $c['path']));
} catch (Throwable $e) {
    ok('no unexpected error', false, get_class($e) . ': ' . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    $conn->rollBack();
    filesStorageReset();
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $f) {
        $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
    }
    rmdir($dir);
}
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
