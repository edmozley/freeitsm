<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * Files' Save to Files and Attach as a copy (upstream GH #303) with the ticket-attachment
 * and documents stores in S3 (#284): the bytes are read and written through the stores
 * layer, never straight from or into the server folders.
 *
 *   - Save to Files from a ticket's email attachment kept in S3: the Files copy has its bytes;
 *   - Save to Files from a document kept in S3: the same;
 *   - Attach from Files as a copy: the new document is in the documents store (S3), not in
 *     the server folder.
 *
 * Database work is one transaction, always rolled back; objects written to S3 and the
 * temporary Files folder are removed. Needs FREEITSM_TEST_DATABASE and the
 * FREEITSM_TEST_S3_* variables (skipped without them). Run as the web server's user.
 */

$root = dirname(__DIR__);
require_once "$root/config.php";
require_once "$root/includes/functions.php";
require_once "$root/includes/storage/stores.php";
require_once "$root/includes/services/files_attach.php";
require_once "$root/includes/services/files_save.php";
require_once "$root/includes/files/names.php";   // filesNameTaken(), as api/files/save_from.php has it

$pass = 0; $fail = 0;
function ok(string $what, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  PASS  {$what}\n"; }
    else       { $fail++; echo "  FAIL  {$what}" . ($detail !== '' ? "  <- {$detail}" : '') . "\n"; }
}

echo "\nSave to Files / Attach as a copy with stores in S3\n" . str_repeat('=', 72) . "\n";
$conn = connectToDatabase();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
if (getenv('FREEITSM_TEST_DATABASE') !== (string)$conn->query('SELECT DATABASE()')->fetchColumn()) {
    exit("Set FREEITSM_TEST_DATABASE to the throwaway database config.php points at - this test writes (rolled back).\n");
}
if (!getenv('FREEITSM_TEST_S3_ENDPOINT')) { echo "SKIPPED: set FREEITSM_TEST_S3_ENDPOINT/KEY/SECRET/BUCKET.\n\n0 passed, 0 failed\n"; exit(0); }
$s3 = ['endpoint' => (string)getenv('FREEITSM_TEST_S3_ENDPOINT'), 'key' => (string)getenv('FREEITSM_TEST_S3_KEY'),
       'secret' => (string)getenv('FREEITSM_TEST_S3_SECRET'), 'bucket' => (string)getenv('FREEITSM_TEST_S3_BUCKET')];
$prefix = 'zz-share-' . bin2hex(random_bytes(3)) . '/';
$admin = (int)$conn->query("SELECT id FROM analysts WHERE is_active = 1 AND is_admin = 1 ORDER BY id LIMIT 1")->fetchColumn();
$filesDir = str_replace('\\', '/', sys_get_temp_dir()) . '/zz-share-' . bin2hex(random_bytes(4));
mkdir($filesDir);
$cleanup = [];
/** Reads a Files item's current bytes. */
$filesBytes = function (int $itemId) use ($conn): ?string {
    $v = $conn->prepare("SELECT v.storage_path FROM files_items i JOIN files_versions v ON v.id = i.current_version_id WHERE i.id = ?");
    $v->execute([$itemId]);
    $key = (string)$v->fetchColumn();
    if ($key === '') return null;
    $tmp = tempnam(sys_get_temp_dir(), 'zzs');
    try { filesStoreFor($conn, $key)->readTo($key, $tmp); return (string)file_get_contents($tmp); }
    catch (Throwable $e) { return null; }
    finally { @unlink($tmp); }
};

$conn->beginTransaction();
try {
    // Files on a temporary server folder; ticket attachments and documents in S3.
    foreach (['files_storage_driver', 'files_storage_previous', 'files_attach_mode'] as $k) $conn->prepare("DELETE FROM system_settings WHERE setting_key = ?")->execute([$k]);
    filesSaveSetting($conn, 'files_storage_root', $filesDir);
    filesStorageReset();
    $conn->exec("DELETE FROM system_settings WHERE setting_key LIKE 'storage\\_%'");
    foreach (['endpoint' => $s3['endpoint'], 'region' => 'us-east-1', 'bucket' => $s3['bucket'], 'access_key_id' => $s3['key'],
              'secret' => encryptValue($s3['secret']), 'prefix' => $prefix, 'path_style' => '1'] as $k => $v) {
        storeSaveSetting($conn, "storage_s3_$k", $v);
    }
    storeSaveSetting($conn, 'storage_tickets_location', 's3');
    storeSaveSetting($conn, 'storage_documents_location', 's3');
    storeReset();

    $conn->exec("INSERT INTO files_folders (name) VALUES ('ZZ-SHARE folder')");
    $folder = (int)$conn->lastInsertId();
    $conn->prepare("INSERT INTO files_permissions (folder_id, principal_type, principal_id, level) VALUES (?, 'analyst', ?, 5)")->execute([$folder, $admin]);
    FilesAcl::reset();

    $conn->prepare("INSERT INTO tickets (ticket_number, subject, created_datetime) VALUES (?, 'ZZ share', UTC_TIMESTAMP())")->execute(['ZZSH-' . bin2hex(random_bytes(3))]);
    $ticket = (int)$conn->lastInsertId();
    $conn->prepare("INSERT INTO emails (ticket_id, subject, received_datetime) VALUES (?, 'ZZ', UTC_TIMESTAMP())")->execute([$ticket]);
    $email = (int)$conn->lastInsertId();

    // An email attachment, kept only in S3.
    $attBytes = "zz attachment kept in S3\n";
    $attKey = storeJoinKey(storeTicketDir($email), bin2hex(random_bytes(8)) . '.txt');
    $tmp = tempnam(sys_get_temp_dir(), 'zzs'); file_put_contents($tmp, $attBytes);
    storePutFile($conn, 'tickets', $attKey, $tmp, true);
    $cleanup[] = fn() => storeDelete($conn, 'tickets', $attKey);
    $conn->prepare("INSERT INTO email_attachments (email_id, filename, content_type, file_path, file_size) VALUES (?, 'zz-from-email.txt', 'text/plain', ?, ?)")
         ->execute([$email, $attKey, strlen($attBytes)]);
    $att = (int)$conn->lastInsertId();
    ok('positive control: the attachment is in S3, not in the server folder',
       storeSize($conn, 'tickets', $attKey) === strlen($attBytes) && !is_file(storeLocalRoot('tickets') . '/' . $attKey));

    // A document on the ticket, kept only in S3.
    $docBytes = "zz document kept in S3\n";
    $docKey = bin2hex(random_bytes(8)) . '.txt';
    $tmp = tempnam(sys_get_temp_dir(), 'zzs'); file_put_contents($tmp, $docBytes);
    storePutFile($conn, 'documents', $docKey, $tmp, true);
    $cleanup[] = fn() => storeDelete($conn, 'documents', $docKey);
    $conn->prepare("INSERT INTO documents (kind, title, storage_key, original_name, mime_type, size_bytes, uploaded_by_id) VALUES ('file', 'ZZ doc', ?, 'zz-doc.txt', 'text/plain', ?, ?)")
         ->execute([$docKey, strlen($docBytes), $admin]);
    $doc = (int)$conn->lastInsertId();
    documentInsertLink($conn, $doc, 'ticket', $ticket, $admin);

    echo "\nSave to Files\n";
    try {
        $r = FilesSave::save($conn, $admin, null, 'email_attachment', $att, $folder, false, 'rename');
        ok('from an email attachment in S3: saved, with its bytes', isset($r['item_id']) && $filesBytes((int)$r['item_id']) === $attBytes, json_encode($r));
    } catch (Throwable $e) { ok('from an email attachment in S3: saved, with its bytes', false, get_class($e) . ': ' . $e->getMessage()); }
    try {
        $r = FilesSave::save($conn, $admin, null, 'document', $doc, $folder, false, 'rename');
        ok('from a document in S3: saved, with its bytes', isset($r['item_id']) && $filesBytes((int)$r['item_id']) === $docBytes, json_encode($r));
    } catch (Throwable $e) { ok('from a document in S3: saved, with its bytes', false, get_class($e) . ': ' . $e->getMessage()); }

    echo "\nAttach from Files as a copy\n";
    FilesAttach::saveMode($conn, $admin, 'both');
    $fkey = '2026/11/14/' . bin2hex(random_bytes(16)) . '.bin';
    @mkdir(dirname("$filesDir/$fkey"), 0777, true);
    $fBytes = "zz files item to copy\n";
    file_put_contents("$filesDir/$fkey", $fBytes);
    $conn->prepare("INSERT INTO files_items (folder_id, name, size_bytes) VALUES (?, 'zz-copy-me.txt', ?)")->execute([$folder, strlen($fBytes)]);
    $item = (int)$conn->lastInsertId();
    $conn->prepare("INSERT INTO files_versions (item_id, version_no, storage_path, size_bytes, sha256, uploaded_datetime) VALUES (?, 1, ?, ?, ?, UTC_TIMESTAMP())")
         ->execute([$item, $fkey, strlen($fBytes), hash('sha256', $fBytes)]);
    $conn->prepare("UPDATE files_items SET current_version_id = ? WHERE id = ?")->execute([(int)$conn->lastInsertId(), $item]);
    FilesAcl::reset();
    try {
        $copy = FilesAttach::attach($conn, $admin, null, 'ticket', $ticket, $item, 'copy');
        $k = (string)$conn->query("SELECT storage_key FROM documents WHERE id = " . (int)$copy)->fetchColumn();
        $cleanup[] = fn() => storeDelete($conn, 'documents', $k);
        ok('the copy is in the documents store (S3), with its bytes', $k !== '' && storeGetString($conn, 'documents', $k) === $fBytes, $k);
        ok('...and not written to the server folder', $k !== '' && !is_file(storeLocalRoot('documents') . '/' . $k));
    } catch (Throwable $e) { ok('the copy is in the documents store (S3), with its bytes', false, get_class($e) . ': ' . $e->getMessage()); }
} finally {
    foreach (array_reverse($cleanup) as $c) { try { $c(); } catch (Throwable $e) {} }
    if ($conn->inTransaction()) $conn->rollBack();
    storeReset();
    filesStorageReset();
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($filesDir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
    @rmdir($filesDir);
}

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
