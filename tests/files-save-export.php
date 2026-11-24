<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * Save to Files for an export a page made in the browser (FilesSave::saveExport) - the
 * Network Mapper's PNG / PDF, and any later page that offers "Save to Files" for an export.
 *
 *   FREEITSM_TEST_DATABASE=<throwaway db> FREEITSM_TEST_URL=http://localhost/<that install> php tests/files-save-export.php
 *
 * A real PNG and a real PDF go in (positive controls); HTML or a script renamed .png, a
 * format that is not an export, and a binary renamed .csv are refused; saving the same name
 * again asks first, exactly as an upload does. Makes a ZZ folder at the top of Files (Full for the admin) and
 * removes it, its files and their stored bytes in finally.
 *
 * @wiki Save-To-Files-Developer-Guide
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/files/names.php';
require_once __DIR__ . '/../includes/services/files_save.php';

$expected = getenv('FREEITSM_TEST_DATABASE');
if (!$expected) { echo "(skipped: set FREEITSM_TEST_DATABASE)\n"; exit(0); }
$conn = connectToDatabase();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
if ($conn->query('SELECT DATABASE()')->fetchColumn() !== $expected) { echo "(skipped: config.php does not use $expected)\n"; exit(0); }

$pass = 0; $fail = 0;
function check(string $what, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  [PASS] $what\n"; }
    else { $fail++; echo "  [FAIL] $what" . ($detail !== '' ? " - $detail" : '') . "\n"; }
}

$admin = (int)$conn->query("SELECT id FROM analysts WHERE is_active = 1 AND is_admin = 1 ORDER BY id LIMIT 1")->fetchColumn();
if (!analystCanAccessModule($conn, $admin, 'files')) { echo "(skipped: Files is off on this install)\n"; exit(0); }

$tmp = [];
$file = function (string $bytes) use (&$tmp) {
    $p = tempnam(sys_get_temp_dir(), 'zzx');
    file_put_contents($p, $bytes);
    $tmp[] = $p;
    return $p;
};
$folder = 0;
try {
    // a top-level ZZ folder of its own, with Full for the admin only (FilesAcl::FULL)
    $conn->prepare("INSERT INTO files_folders (parent_id, name, inherit_permissions, created_by, created_datetime) VALUES (NULL, ?, 0, ?, UTC_TIMESTAMP())")
         ->execute(['ZZ export test ' . bin2hex(random_bytes(3)), $admin]);
    $folder = (int)$conn->lastInsertId();
    $conn->prepare("INSERT INTO files_permissions (folder_id, principal_type, principal_id, level, granted_by) VALUES (?, 'analyst', ?, ?, ?)")
         ->execute([$folder, $admin, FilesAcl::FULL, $admin]);
    FilesAcl::reset();

    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
    $r = FilesSave::saveExport($conn, $admin, $file($png), 'zz-diagram-v1.png', $folder);
    check('POSITIVE CONTROL: a real PNG is saved', !empty($r['item_id']) && $r['name'] === 'zz-diagram-v1.png', json_encode($r));
    $r2 = FilesSave::saveExport($conn, $admin, $file("%PDF-1.4\n%ZZ\n"), 'zz-diagram-v1.pdf', $folder);
    check('...and a PDF', !empty($r2['item_id']));
    $r3 = FilesSave::saveExport($conn, $admin, $file("name,ip\ncore-sw,10.0.0.1\n"), 'zz-nodes.csv', $folder);
    check('...and a CSV', !empty($r3['item_id']));

    $again = FilesSave::saveExport($conn, $admin, $file($png), 'zz-diagram-v1.png', $folder);
    check('the same name again asks first, as an upload does', !empty($again['conflict']) && !empty($again['choices']), json_encode($again));

    $refused = function (string $bytes, string $name) use ($conn, $admin, $folder, $file) {
        try { FilesSave::saveExport($conn, $admin, $file($bytes), $name, $folder); return false; }
        catch (ServiceError $e) { return true; }
    };
    check('HTML renamed .png is refused', $refused('<html><script>alert(1)</script></html>', 'x.png'));
    check('a PDF renamed .png is refused - the bytes must match the name', $refused("%PDF-1.4\n", 'x.png'));
    check('a format that is not an export is refused, whatever it holds', $refused($png, 'x.html') && $refused($png, 'x.svg') && $refused($png, 'x.exe'));
    check('a binary renamed .csv is refused', $refused("MZ\0\0\x90\0", 'x.csv'));
    check('a folder that does not exist is refused', (function () use ($conn, $admin, $file, $png) {
        try { FilesSave::saveExport($conn, $admin, $file($png), 'x.png', 999999999); return false; } catch (ServiceError $e) { return true; }
    })());
} catch (Throwable $e) {
    check('no unexpected error', false, $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    if ($folder) {
        $st = $conn->prepare("SELECT v.storage_path FROM files_versions v JOIN files_items i ON i.id = v.item_id WHERE i.folder_id = ?");
        $st->execute([$folder]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $rel) { try { filesActiveStore($conn)->delete($rel); } catch (Throwable $e) {} }
        $conn->exec("UPDATE files_items SET current_version_id = NULL WHERE folder_id = $folder");
        $conn->exec("DELETE v FROM files_versions v JOIN files_items i ON i.id = v.item_id WHERE i.folder_id = $folder");
        $conn->exec("DELETE FROM files_items WHERE folder_id = $folder");
        $conn->exec("DELETE FROM files_permissions WHERE folder_id = $folder");
        $conn->exec("DELETE FROM files_folders WHERE id = $folder");
    }
    foreach ($tmp as $p) @unlink($p);
    check('cleaned up', !$folder || (int)$conn->query("SELECT COUNT(*) FROM files_folders WHERE id = $folder")->fetchColumn() === 0);
}

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
