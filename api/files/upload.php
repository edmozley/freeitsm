<?php
/**
 * Chunked uploads - how a 2 GB .exe gets in past PHP's upload limits and any
 * proxy's body limit: the browser sends it in FILES_CHUNK_BYTES pieces.
 *
 *   POST ?action=start   {folder_id, name, size, on_conflict?: ask|version|rename}
 *        -> {token, chunk}                       or {conflict: true, item_id, name}
 *   POST ?action=chunk&token=&offset=   raw bytes as the body (NOT multipart -
 *        upload_max_filesize does not apply; each piece is far under post_max_size)
 *   POST ?action=finish  {token}       -> {item_id, version_no, name}
 *   POST ?action=cancel  {token}
 *
 * Upload permission on the folder is checked at start AND finish: a grant removed
 * while a big file is still going up must stop it landing.
 *
 * The pieces collect in <root>/_incoming/<token>.part. On finish the file is
 * hashed (SHA-256) and moved to <root>/yyyy/mm/dd/<random>.bin. The original name
 * never touches the filesystem.
 */
require_once __DIR__ . '/../../includes/files/api_bootstrap.php';

filesApiRun(function () use ($conn, $analystId) {
    $action = (string)($_GET['action'] ?? '');

    if ($action === 'start') {
        $in       = filesApiBody();
        $folderId = (int)($in['folder_id'] ?? 0);
        $name     = filesCleanName((string)($in['name'] ?? ''));
        $size     = (int)($in['size'] ?? -1);
        $conflict = (string)($in['on_conflict'] ?? 'ask');

        if ($folderId <= 0) filesApiFail('Files go inside a folder - open or create one first.');
        filesNeed($conn, $analystId, $folderId, FilesAcl::UPLOAD, 'upload');
        if ($size < 0) filesApiFail('The file size is missing.');
        $max = filesMaxUploadBytes($conn);
        if ($size > $max) {
            filesApiFail('That file is larger than the ' . uploadFormatBytes($max) . ' limit set in Files settings.');
        }

        $itemId = null;
        $st = $conn->prepare("SELECT id, name FROM files_items WHERE folder_id = ? AND deleted_datetime IS NULL AND LOWER(name) = LOWER(?)");
        $st->execute([$folderId, $name]);
        $existing = $st->fetch(PDO::FETCH_ASSOC);
        if ($existing) {
            if ($conflict === 'version') {
                $itemId = (int)$existing['id'];
                $name   = $existing['name'];
            } elseif ($conflict === 'rename') {
                $name = filesFreeName($conn, $folderId, $name);
            } else {
                filesApiOk(['conflict' => true, 'item_id' => (int)$existing['id'], 'name' => $existing['name']]);
            }
        } elseif (filesNameTaken($conn, $folderId, $name)) {
            $name = filesFreeName($conn, $folderId, $name);   // a folder already has the name
        }

        $token = bin2hex(random_bytes(16));
        $path  = filesIncomingPath($conn, $token);
        if (@file_put_contents($path, '') === false) filesApiFail('The server could not start writing the file.');
        $conn->prepare("INSERT INTO files_uploads (token, analyst_id, folder_id, item_id, file_name, size_bytes, created_datetime)
                        VALUES (?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())")
             ->execute([$token, $analystId, $folderId, $itemId, $name, $size]);
        filesApiOk(['token' => $token, 'chunk' => FILES_CHUNK_BYTES, 'name' => $name]);
    }

    $token = (string)($_GET['token'] ?? (filesApiBody()['token'] ?? ''));
    if (!preg_match('/^[0-9a-f]{32}$/', $token)) filesApiFail('Upload not found.');
    $st = $conn->prepare("SELECT * FROM files_uploads WHERE token = ? AND analyst_id = ?");
    $st->execute([$token, $analystId]);
    $up = $st->fetch(PDO::FETCH_ASSOC);
    if (!$up) filesApiFail('Upload not found.');
    $part = filesIncomingPath($conn, $token);

    if ($action === 'chunk') {
        $offset = (int)($_GET['offset'] ?? -1);
        // Pieces must arrive in order; a retry of the last piece is allowed by
        // truncating back to its start.
        if ($offset < 0 || $offset > (int)$up['received_bytes']) filesApiFail('That piece arrived out of order.', 200, ['received' => (int)$up['received_bytes']]);
        $fh = fopen($part, 'c+b');
        if (!$fh) filesApiFail('The server could not write the file.');
        ftruncate($fh, $offset);
        fseek($fh, $offset);
        $inStream = fopen('php://input', 'rb');
        $written = stream_copy_to_stream($inStream, $fh, FILES_CHUNK_BYTES + 1);
        fclose($inStream);
        fclose($fh);
        if ($written === false || $written > FILES_CHUNK_BYTES) filesApiFail('That piece was too large.');
        $received = $offset + $written;
        if ($received > (int)$up['size_bytes']) filesApiFail('More arrived than the file size said.');
        $conn->prepare("UPDATE files_uploads SET received_bytes = ?, updated_datetime = UTC_TIMESTAMP() WHERE id = ?")
             ->execute([$received, $up['id']]);
        filesApiOk(['received' => $received]);
    }

    if ($action === 'cancel') {
        @unlink($part);
        $conn->prepare("DELETE FROM files_uploads WHERE id = ?")->execute([$up['id']]);
        filesApiOk();
    }

    if ($action === 'finish') {
        $folderId = (int)$up['folder_id'];
        FilesAcl::reset();
        filesNeed($conn, $analystId, $folderId, FilesAcl::UPLOAD, 'upload');

        clearstatcache(true, $part);
        $size = is_file($part) ? filesize($part) : -1;
        if ($size !== (int)$up['size_bytes']) filesApiFail('The file did not arrive in full. Please try again.');

        $rel = filesNewStoragePath($conn);
        $abs = filesStorageRoot($conn) . '/' . $rel;
        $sha = hash_file('sha256', $part);
        if (!@rename($part, $abs)) filesApiFail('The server could not store the file.');
        $mime = filesDetectMime($abs, $up['file_name']);

        $conn->beginTransaction();
        $itemId = $up['item_id'] !== null ? (int)$up['item_id'] : null;
        if ($itemId !== null) {
            // A new version of an existing file - still live, still in this folder?
            $it = filesItem($conn, $itemId);
            if (!$it || (int)$it['folder_id'] !== $folderId) $itemId = null;
        }
        $isNew = $itemId === null;
        if ($isNew) {
            $name = filesNameTaken($conn, $folderId, $up['file_name']) ? filesFreeName($conn, $folderId, $up['file_name']) : $up['file_name'];
            $conn->prepare("INSERT INTO files_items (folder_id, name, size_bytes, mime_type, created_by, created_datetime)
                            VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP())")->execute([$folderId, $name, $size, $mime, $analystId]);
            $itemId = (int)$conn->lastInsertId();
            $versionNo = 1;
        } else {
            $name = $it['name'];
            $versionNo = 1 + (int)$conn->query("SELECT COALESCE(MAX(version_no), 0) FROM files_versions WHERE item_id = " . (int)$itemId)->fetchColumn();
        }
        $conn->prepare("INSERT INTO files_versions (item_id, version_no, storage_path, size_bytes, mime_type, sha256, uploaded_by, uploaded_datetime)
                        VALUES (?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())")
             ->execute([$itemId, $versionNo, $rel, $size, $mime, $sha, $analystId]);
        $versionId = (int)$conn->lastInsertId();
        $conn->prepare("UPDATE files_items SET current_version_id = ?, size_bytes = ?, mime_type = ?" .
                       ($isNew ? "" : ", updated_by = " . (int)$analystId . ", updated_datetime = UTC_TIMESTAMP()") .
                       " WHERE id = ?")->execute([$versionId, $size, $mime, $itemId]);
        $conn->prepare("DELETE FROM files_uploads WHERE id = ?")->execute([$up['id']]);
        $conn->commit();

        filesAudit($conn, $analystId, $isNew ? 'upload' : 'new_version',
                   ['folder_id' => $folderId, 'item_id' => $itemId, 'version_id' => $versionId, 'item_name' => $name],
                   ['size' => $size, 'sha256' => $sha, 'version' => $versionNo]);
        filesApiOk(['item_id' => $itemId, 'version_no' => $versionNo, 'name' => $name]);
    }

    filesApiFail('Unknown action.');
});
