<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * Attach from Files (GH #303): a file in Files on a ticket's Documents, as a link or
 * a copy, as Files -> Settings -> Sharing allows.
 *
 *   - the default offers a link only; a copy is refused until Sharing allows it;
 *   - search finds only files in folders the person can open;
 *   - a link: the list shows its name and files/?item= to someone who can open the
 *     folder, and NEITHER to someone who cannot ("hidden means not found");
 *   - a copy: an ordinary document with the same bytes, which needs Download;
 *   - a deleted Files item reads as deleted;
 *   - the portal's queries leave Files links out.
 *
 * Bytes go to a temporary Files store; database work is in one transaction that is
 * always rolled back, and the copy's stored document is deleted.
 *   FREEITSM_TEST_DATABASE=<throwaway db> php tests/files-attach.php
 */

$root = dirname(__DIR__);
require_once "$root/config.php";
require_once "$root/includes/functions.php";
require_once "$root/includes/services/files_attach.php";

$pass = 0; $fail = 0;
function ok(string $what, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  PASS  {$what}\n"; }
    else       { $fail++; echo "  FAIL  {$what}" . ($detail !== '' ? "  <- {$detail}" : '') . "\n"; }
}
function threw(callable $fn): ?Throwable { try { $fn(); return null; } catch (Throwable $e) { return $e; } }

$conn = connectToDatabase();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
if (getenv('FREEITSM_TEST_DATABASE') !== (string)$conn->query('SELECT DATABASE()')->fetchColumn()) {
    exit("Set FREEITSM_TEST_DATABASE to the throwaway database config.php points at - this test writes (rolled back).\n");
}
if (!DocumentsService::filesLinkReady($conn)) exit("Run Database Verification first (documents.files_item_id).\n");
$admin = (int)$conn->query("SELECT id FROM analysts WHERE is_active = 1 AND is_admin = 1 ORDER BY id LIMIT 1")->fetchColumn();
$ticket = (int)$conn->query("SELECT id FROM tickets WHERE deleted_datetime IS NULL ORDER BY id DESC LIMIT 1")->fetchColumn();
$dir = str_replace('\\', '/', sys_get_temp_dir()) . '/zz-att-' . bin2hex(random_bytes(4));
mkdir($dir);
$copyPaths = [];

$conn->beginTransaction();
try {
    foreach (['files_storage_driver', 'files_storage_previous', 'files_attach_mode'] as $k) $conn->prepare("DELETE FROM system_settings WHERE setting_key = ?")->execute([$k]);
    filesSaveSetting($conn, 'files_storage_root', $dir);
    filesStorageReset();

    // A non-admin analyst, with NO grant on the test folder (Files is pessimistic).
    $conn->prepare("INSERT INTO analysts (username, full_name, email, password_hash, is_active, is_admin) VALUES (?, 'ZZ Attach', ?, '', 1, 0)")
         ->execute(['zzatt' . bin2hex(random_bytes(3)), 'zzatt' . bin2hex(random_bytes(3)) . '@example.test']);
    $other = (int)$conn->lastInsertId();

    $conn->exec("INSERT INTO files_folders (name) VALUES ('ZZ-ATT folder')");
    $folder = (int)$conn->lastInsertId();
    $conn->prepare("INSERT INTO files_permissions (folder_id, principal_type, principal_id, level) VALUES (?, 'analyst', ?, 5)")->execute([$folder, $admin]);
    $key = '2026/11/13/' . bin2hex(random_bytes(16)) . '.bin';
    @mkdir(dirname("$dir/$key"), 0777, true);
    $bytes = "zz attach test\n";
    file_put_contents("$dir/$key", $bytes);
    $conn->prepare("INSERT INTO files_items (folder_id, name, size_bytes) VALUES (?, 'zz-supplier-quote.txt', ?)")->execute([$folder, strlen($bytes)]);
    $item = (int)$conn->lastInsertId();
    $conn->prepare("INSERT INTO files_versions (item_id, version_no, storage_path, size_bytes, sha256, uploaded_datetime) VALUES (?, 1, ?, ?, ?, UTC_TIMESTAMP())")
         ->execute([$item, $key, strlen($bytes), hash('sha256', $bytes)]);
    $conn->prepare("UPDATE files_items SET current_version_id = ? WHERE id = ?")->execute([(int)$conn->lastInsertId(), $item]);
    FilesAcl::reset();

    echo "\nSettings and search\n";
    ok('the default is a link only', FilesAttach::mode($conn) === 'link');
    $found = FilesAttach::search($conn, $admin, 'supplier-quote');
    $mine = array_values(array_filter($found, fn($f) => $f['item_id'] === $item));
    ok('search finds the file for someone who can open its folder, as a link only', count($mine) === 1 && $mine[0]['can_link'] && !$mine[0]['can_copy'], json_encode($mine));
    ok('...and not for someone who cannot', !array_filter(FilesAttach::search($conn, $other, 'supplier-quote'), fn($f) => $f['item_id'] === $item));

    echo "\nLink\n";
    $e = threw(fn() => FilesAttach::attach($conn, $admin, null, 'ticket', $ticket, $item, 'copy'));
    ok('a copy is refused while Sharing offers links only', $e instanceof ServiceError && str_contains($e->getMessage(), 'not offered'), $e ? $e->getMessage() : 'attached');
    $e = threw(fn() => FilesAttach::attach($conn, $other, null, 'ticket', $ticket, $item, 'link'));
    ok('someone who cannot open the folder cannot attach it (not found)', $e instanceof ServiceError);
    $doc = FilesAttach::attach($conn, $admin, null, 'ticket', $ticket, $item, 'link');
    $row = $conn->query("SELECT kind, files_item_id, storage_key FROM documents WHERE id = $doc")->fetch(PDO::FETCH_ASSOC);
    ok('a link is a "files" document pointing at the item, with no bytes of its own', $row['kind'] === 'files' && (int)$row['files_item_id'] === $item && $row['storage_key'] === null, json_encode($row));
    $mineRow = fn(array $rows) => array_values(array_filter($rows, fn($r) => (int)$r['id'] === $doc))[0] ?? null;
    $asAdmin = $mineRow(DocumentsService::listFor($conn, $admin, null, 'ticket', $ticket)['rows']);
    ok('the list shows its name and files/?item= to someone who can open the folder',
       $asAdmin && $asAdmin['title'] === 'zz-supplier-quote.txt' && $asAdmin['files_access'] === true && str_ends_with((string)$asAdmin['open_url'], 'files/?item=' . $item), json_encode($asAdmin));
    $v = FilesAttach::viewFor($conn, $other, $item);
    ok('someone who cannot open the folder gets neither its name nor a way in', $v['access'] === false && $v['title'] === null && $v['open_url'] === null && !$v['gone'], json_encode($v));

    echo "\nCopy\n";
    FilesAttach::saveMode($conn, $admin, 'both');
    ok('Sharing "both" offers a link and a copy', ($f = array_values(array_filter(FilesAttach::search($conn, $admin, 'supplier-quote'), fn($f) => $f['item_id'] === $item))[0] ?? null) && $f['can_link'] && $f['can_copy']);
    $copy = FilesAttach::attach($conn, $admin, null, 'ticket', $ticket, $item, 'copy');
    $c = $conn->query("SELECT kind, storage_key, content_hash, description FROM documents WHERE id = $copy")->fetch(PDO::FETCH_ASSOC);
    $path = documentStoragePath((string)$c['storage_key']);
    $copyPaths[] = $path;
    ok('a copy is an ordinary document with the same bytes', $c['kind'] === 'file' && is_file($path) && file_get_contents($path) === $bytes && $c['content_hash'] === hash('sha256', $bytes), json_encode($c));
    ok('...saying where it was copied from', str_contains((string)$c['description'], 'Copied from Files'));
    $conn->prepare("UPDATE files_permissions SET level = 1 WHERE folder_id = ? AND principal_id = ?")->execute([$folder, $admin]);
    FilesAcl::reset();
    // An admin's level comes from their grant here only if Files does not treat admins as Full everywhere.
    $lvl = FilesAcl::level($conn, $admin, $folder);
    if ($lvl < FilesAcl::DOWNLOAD) {
        $e = threw(fn() => FilesAttach::attach($conn, $admin, null, 'ticket', $ticket, $item, 'copy'));
        ok('View only cannot make a copy - "attach it as a link instead"', $e instanceof ServiceError && str_contains($e->getMessage(), 'link instead'), $e ? $e->getMessage() : 'copied');
    } else {
        ok('(skipped: administrators hold Full control in Files, so View only cannot be tested with this account)', true);
    }

    echo "\nDeleted, and the portal\n";
    $conn->prepare("UPDATE files_items SET deleted_datetime = UTC_TIMESTAMP() WHERE id = ?")->execute([$item]);
    $gone = FilesAttach::viewFor($conn, $admin, $item);
    ok('a file deleted from Files reads as deleted, with no way in', $gone['gone'] && !$gone['access']);
    foreach (['api/self-service/get_ticket_detail.php', 'api/self-service/get_document.php', 'includes/knowledge/help_centre_documents.php'] as $f) {
        ok("the portal leaves Files links out: $f", str_contains((string)file_get_contents("$root/$f"), "d.kind <> 'files'"));
    }
} catch (Throwable $e) {
    ok('no unexpected error', false, get_class($e) . ': ' . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    $conn->rollBack();
    filesStorageReset();
    foreach ($copyPaths as $p) @unlink($p);
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $f) {
        $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
    }
    rmdir($dir);
}
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
