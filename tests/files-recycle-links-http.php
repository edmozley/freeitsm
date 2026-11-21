<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * A ticket's link to a file in Files (documents.kind = 'files', GH #303), opened
 * through api/documents/download.php over real HTTP while the file goes through
 * the Files recycle bin:
 *
 *   live            302 to files/?item=<id>
 *   in the bin      410 "deleted from Files" - the file, or a folder above it
 *   restored        302 again
 *   deleted for good  410, and the document row stays with files_item_id cleared
 *
 *   FREEITSM_TEST_DATABASE=<throwaway db> FREEITSM_TEST_URL=http://localhost/<that install> php tests/files-recycle-links-http.php
 *
 * A ZZ folder and file, granted to the first active administrator, linked to the
 * newest live ticket; the request carries a forged session for that administrator.
 * The web server has to see these rows, so they are committed - and removed in
 * finally, with the document, its link and its access log rows.
 */

$root = dirname(__DIR__);
require_once "$root/config.php";
require_once "$root/includes/functions.php";
require_once "$root/includes/documents.php";
require_once "$root/includes/services/documents.php";   // DocumentsService::filesLinkReady()
require_once "$root/includes/services/files_recycle.php";

$expected = getenv('FREEITSM_TEST_DATABASE');
$base = rtrim((string)getenv('FREEITSM_TEST_URL'), '/');
if (!$expected || !$base) { echo "(skipped: set FREEITSM_TEST_DATABASE and FREEITSM_TEST_URL)\n"; exit(0); }
$conn = connectToDatabase();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
if ($conn->query('SELECT DATABASE()')->fetchColumn() !== $expected) { echo "(skipped: config.php does not use $expected)\n"; exit(0); }
if (!DocumentsService::filesLinkReady($conn)) { echo "(skipped: run Database Verification first - documents.files_item_id)\n"; exit(0); }

$pass = 0; $fail = 0;
function ok(string $what, bool $cond, string $detail = ''): void
{
    global $pass, $fail;
    if ($cond) { $pass++; echo "  PASS  $what\n"; }
    else       { $fail++; echo "  FAIL  $what" . ($detail !== '' ? "  <- $detail" : '') . "\n"; }
}

$admin  = (int)$conn->query("SELECT id FROM analysts WHERE is_active = 1 AND is_admin = 1 ORDER BY id LIMIT 1")->fetchColumn();
$ticket = (int)$conn->query("SELECT id FROM tickets WHERE deleted_datetime IS NULL ORDER BY id DESC LIMIT 1")->fetchColumn();
if (!$admin || !$ticket) { echo "(skipped: needs an active administrator and a live ticket)\n"; exit(0); }

$sid  = 'zzrbl' . bin2hex(random_bytes(10));
$sessFile = rtrim(ini_get('session.save_path') ?: sys_get_temp_dir(), '/' . chr(92)) . '/sess_' . $sid;
/** GET download.php?id= as the administrator, redirects not followed: [code, body, Location]. */
$download = function (int $docId) use ($base, $sid): array {
    $ch = curl_init("$base/api/documents/download.php?id=$docId");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_COOKIE => "PHPSESSID=$sid",
                            CURLOPT_FOLLOWLOCATION => false, CURLOPT_HEADER => true]);
    $raw = (string)curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hlen = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    preg_match('/^Location:\s*(\S+)/mi', substr($raw, 0, $hlen), $m);
    return [$code, substr($raw, $hlen), $m[1] ?? ''];
};

$folder = $item = $docId = 0;
$tag = 'ZZRBL-' . bin2hex(random_bytes(3));
echo "\nA ticket's link to a file in Files, through the recycle bin (HTTP)\n" . str_repeat('=', 72) . "\n";
try {
    file_put_contents($sessFile, 'analyst_id|i:' . $admin . ';analyst_name|s:5:"ZZRBL";is_admin|i:1;');
    $conn->prepare("INSERT INTO files_folders (parent_id, name) VALUES (NULL, ?)")->execute(["$tag folder"]);
    $folder = (int)$conn->lastInsertId();
    $conn->prepare("INSERT INTO files_permissions (folder_id, principal_type, principal_id, level) VALUES (?, 'analyst', ?, ?)")->execute([$folder, $admin, FilesAcl::MODIFY]);
    $conn->prepare("INSERT INTO files_items (folder_id, name, size_bytes) VALUES (?, ?, 4)")->execute([$folder, "$tag.txt"]);
    $item = (int)$conn->lastInsertId();
    // Bytes that were never written: a permanent delete only has to find them gone.
    $conn->prepare("INSERT INTO files_versions (item_id, version_no, storage_path, size_bytes) VALUES (?, 1, ?, 4)")
         ->execute([$item, '2026/11/21/' . bin2hex(random_bytes(16)) . '.bin']);
    $conn->prepare("INSERT INTO documents (kind, title, files_item_id, uploaded_by_id) VALUES ('files', ?, ?, ?)")->execute(["$tag.txt", $item, $admin]);
    $docId = (int)$conn->lastInsertId();
    documentInsertLink($conn, $docId, 'ticket', $ticket, $admin);
    FilesAcl::reset();

    [$code, $body, $loc] = $download($docId);
    ok('CONTROL: a live file opens through Files (302 to files/?item=)', $code === 302 && str_ends_with($loc, 'files/?item=' . $item), "$code $loc " . trim($body));

    $conn->prepare("UPDATE files_items SET deleted_by = ?, deleted_datetime = UTC_TIMESTAMP() WHERE id = ?")->execute([$admin, $item]);
    FilesAcl::reset();
    [$code, $body] = $download($docId);
    ok('the file in the recycle bin: 410, saying it was deleted from Files', $code === 410 && str_contains($body, 'deleted from Files'), "$code " . trim($body));
    FilesRecycleService::restore($conn, $admin, 'item', $item);
    [$code, , $loc] = $download($docId);
    ok('restored: it opens again', $code === 302 && str_ends_with($loc, 'files/?item=' . $item), "$code $loc");

    $conn->prepare("UPDATE files_folders SET deleted_by = ?, deleted_datetime = UTC_TIMESTAMP() WHERE id = ?")->execute([$admin, $folder]);
    FilesAcl::reset();
    [$code, $body] = $download($docId);
    ok('its folder in the recycle bin: 410 too', $code === 410 && str_contains($body, 'deleted from Files'), "$code " . trim($body));

    FilesRecycleService::purge($conn, $admin, 'folder', $folder);
    [$code, $body] = $download($docId);
    $row = $conn->query("SELECT kind, files_item_id FROM documents WHERE id = $docId")->fetch(PDO::FETCH_ASSOC);
    ok('deleted for good: still 410, never a 404 or a way in', $code === 410 && str_contains($body, 'deleted from Files'), "$code " . trim($body));
    ok('...and the document row stays, pointing at nothing', $row && $row['kind'] === 'files' && $row['files_item_id'] === null, json_encode($row));
} catch (Throwable $e) {
    ok('no unexpected error', false, get_class($e) . ': ' . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    @unlink($sessFile);
    if ($docId) {
        foreach (['document_access_log' => 'document_id', 'document_links' => 'document_id', 'documents' => 'id'] as $t => $col) {
            $conn->prepare("DELETE FROM $t WHERE $col = ?")->execute([$docId]);
        }
    }
    if ($item) {
        $conn->prepare("DELETE FROM files_versions WHERE item_id = ?")->execute([$item]);
        $conn->prepare("DELETE FROM files_items WHERE id = ?")->execute([$item]);
    }
    if ($folder) {
        $conn->prepare("DELETE FROM files_permissions WHERE folder_id = ?")->execute([$folder]);
        $conn->prepare("DELETE FROM files_folders WHERE id = ?")->execute([$folder]);
    }
    if ($folder) $conn->prepare("DELETE FROM files_audit WHERE folder_id = ? OR item_id = ? OR target_path LIKE ?")->execute([$folder, $item, "$tag%"]);
}

echo str_repeat('-', 72) . "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
