<?php
/**
 * POST {mode: move|copy, target: <folder id, 0 = top level>, entries: [{type: folder|item, id}]}
 * Paste after Cut/Copy, and drag-and-drop between windows.
 *
 *   MOVE  needs Modify on what is moved, and Upload on the target. A folder that
 *         moves keeps its own entries and, if it inherits, now inherits from its
 *         new parent - exactly what Windows does, and recorded in the audit row.
 *   COPY  needs Download on what is copied (a copy is a copy taken away) and
 *         Upload on the target. Copies inherit at the target and carry no entries
 *         of their own. A copied file SHARES the source's stored bytes rather than
 *         writing them twice - a 2 GB copy is instant - so a purge must check
 *         nothing else points at a .bin (see files_versions in freeitsm.sql).
 *
 * The top level as a target needs Cap::FILES_FOLDERS, and anything landing there
 * gives the person doing it Full control, as creating a top-level folder does.
 * Files cannot live at the top level - only folders.
 *
 * Each entry succeeds or fails on its own; the response lists what failed and why.
 */
require_once __DIR__ . '/../../includes/files/api_bootstrap.php';

filesApiRun(function () use ($conn, $analystId) {
    $in      = filesApiBody();
    $mode    = ($in['mode'] ?? '') === 'copy' ? 'copy' : 'move';
    $target  = (int)($in['target'] ?? 0);
    $entries = is_array($in['entries'] ?? null) ? array_slice($in['entries'], 0, 500) : [];
    if (!$entries) filesApiFail('Nothing to ' . $mode . '.');

    if ($target > 0) {
        filesNeed($conn, $analystId, $target, FilesAcl::UPLOAD, $mode);
    } elseif (!filesHasCap($conn, $analystId, Cap::FILES_FOLDERS)) {
        filesApiFail('You cannot put folders at the top level.');
    }

    $done = 0;
    $failed = [];
    foreach ($entries as $e) {
        $type = ($e['type'] ?? '') === 'folder' ? 'folder' : 'item';
        $id   = (int)($e['id'] ?? 0);
        try {
            if ($type === 'item') {
                filesTransferItem($conn, $analystId, $mode, $id, $target);
            } else {
                filesTransferFolder($conn, $analystId, $mode, $id, $target);
            }
            $done++;
        } catch (RuntimeException $ex) {
            $failed[] = ['type' => $type, 'id' => $id, 'error' => $ex->getMessage()];
        }
        FilesAcl::reset();
    }
    filesApiOk(['done' => $done, 'failed' => $failed]);
});

/** RuntimeException = a refusal to report for this one entry. */
function filesTransferItem(PDO $conn, int $analystId, string $mode, int $id, int $target): void
{
    $it = filesItem($conn, $id);
    if (!$it || FilesAcl::level($conn, $analystId, (int)$it['folder_id']) < FilesAcl::VIEW) throw new RuntimeException('File not found.');
    if ($target <= 0) throw new RuntimeException('Files cannot go at the top level - choose a folder.');

    $need = $mode === 'copy' ? FilesAcl::DOWNLOAD : FilesAcl::MODIFY;
    if (FilesAcl::level($conn, $analystId, (int)$it['folder_id']) < $need) {
        filesAudit($conn, $analystId, 'denied', ['folder_id' => (int)$it['folder_id'], 'item_id' => $id, 'item_name' => $it['name']], ['doing' => $mode]);
        throw new RuntimeException('You do not have permission to ' . $mode . ' "' . $it['name'] . '".');
    }
    $fromPath = FilesAcl::path($conn, (int)$it['folder_id']) . ' / ' . $it['name'];

    if ($mode === 'move') {
        if ((int)$it['folder_id'] === $target) return;
        $name = filesFreeName($conn, $target, $it['name']);
        $conn->prepare("UPDATE files_items SET folder_id = ?, name = ?, updated_by = ?, updated_datetime = UTC_TIMESTAMP() WHERE id = ?")
             ->execute([$target, $name, $analystId, $id]);
        filesAudit($conn, $analystId, 'move', ['folder_id' => $target, 'item_id' => $id, 'item_name' => $name], ['from' => $fromPath]);
        return;
    }

    $name = filesFreeName($conn, $target, $it['name']);
    $newId = filesCopyItemRow($conn, $analystId, $it, $target, $name);
    filesAudit($conn, $analystId, 'copy', ['folder_id' => $target, 'item_id' => $newId, 'item_name' => $name],
               ['from' => $fromPath, 'source_item_id' => $id]);
}

/** A new file row whose single version shares the source's current bytes. */
function filesCopyItemRow(PDO $conn, int $analystId, array $it, int $target, string $name): int
{
    $conn->prepare("INSERT INTO files_items (folder_id, name, size_bytes, mime_type, created_by, created_datetime)
                    VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP())")
         ->execute([$target, $name, $it['size_bytes'], $it['mime_type'], $analystId]);
    $newId = (int)$conn->lastInsertId();
    if ($it['current_version_id']) {
        $conn->prepare("INSERT INTO files_versions (item_id, version_no, storage_path, size_bytes, mime_type, sha256, uploaded_by, uploaded_datetime)
                        SELECT ?, 1, storage_path, size_bytes, mime_type, sha256, ?, UTC_TIMESTAMP() FROM files_versions WHERE id = ?")
             ->execute([$newId, $analystId, $it['current_version_id']]);
        $conn->prepare("UPDATE files_items SET current_version_id = ? WHERE id = ?")->execute([(int)$conn->lastInsertId(), $newId]);
    }
    return $newId;
}

function filesTransferFolder(PDO $conn, int $analystId, string $mode, int $id, int $target): void
{
    $tree = FilesAcl::tree($conn);
    $lvl  = FilesAcl::level($conn, $analystId, $id);
    if (!isset($tree[$id]) || $lvl < FilesAcl::VIEW) throw new RuntimeException('Folder not found.');
    $f = $tree[$id];

    $need = $mode === 'copy' ? FilesAcl::DOWNLOAD : FilesAcl::MODIFY;
    if ($lvl < $need) {
        filesAudit($conn, $analystId, 'denied', ['folder_id' => $id], ['doing' => $mode]);
        throw new RuntimeException('You do not have permission to ' . $mode . ' "' . $f['name'] . '".');
    }
    if ($target > 0 && in_array($target, FilesAcl::descendants($conn, $id), true)) {
        throw new RuntimeException('"' . $f['name'] . '" cannot go inside itself.');
    }
    $fromPath = FilesAcl::path($conn, $id);

    if ($mode === 'move') {
        if (($f['parent_id'] ?? 0) === ($target ?: null) || (int)$f['parent_id'] === $target) return;
        $name = filesFreeName($conn, $target ?: null, $f['name']);
        $conn->beginTransaction();
        $conn->prepare("UPDATE files_folders SET parent_id = ?, name = ?, updated_by = ?, updated_datetime = UTC_TIMESTAMP() WHERE id = ?")
             ->execute([$target ?: null, $name, $analystId, $id]);
        if (!$target) filesGrantCreatorFull($conn, $analystId, $id);
        $conn->commit();
        FilesAcl::reset();
        filesAudit($conn, $analystId, 'move', ['folder_id' => $id], [
            'from' => $fromPath,
            // Moving changes what an inheriting folder inherits - worth saying so
            // in the one row someone will look at when access "changed by itself".
            'inherits' => (bool)$f['inherit'],
        ]);
        return;
    }

    // Copy: the folder, then everything under it the caller can at least Download.
    $conn->beginTransaction();
    $name  = filesFreeName($conn, $target ?: null, $f['name']);
    $newId = filesCopyFolderTree($conn, $analystId, $id, $target ?: null, $name);
    if (!$target) filesGrantCreatorFull($conn, $analystId, $newId);
    $conn->commit();
    FilesAcl::reset();
    filesAudit($conn, $analystId, 'copy', ['folder_id' => $newId], ['from' => $fromPath, 'source_folder_id' => $id]);
}

function filesCopyFolderTree(PDO $conn, int $analystId, int $srcId, ?int $parentId, string $name): int
{
    $conn->prepare("INSERT INTO files_folders (parent_id, name, inherit_permissions, created_by, created_datetime)
                    VALUES (?, ?, 1, ?, UTC_TIMESTAMP())")->execute([$parentId, $name, $analystId]);
    $newId = (int)$conn->lastInsertId();

    // Only what the copier could have downloaded comes along. A subfolder that
    // stopped inheriting and shuts them out is skipped - copying must not become
    // a way to read what you cannot read.
    if (FilesAcl::level($conn, $analystId, $srcId) >= FilesAcl::DOWNLOAD) {
        $st = $conn->prepare("SELECT * FROM files_items WHERE folder_id = ? AND deleted_datetime IS NULL");
        $st->execute([$srcId]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $it) {
            filesCopyItemRow($conn, $analystId, $it, $newId, $it['name']);
        }
    }
    foreach (FilesAcl::tree($conn) as $f) {
        if ($f['parent_id'] === $srcId && FilesAcl::level($conn, $analystId, $f['id']) >= FilesAcl::DOWNLOAD) {
            filesCopyFolderTree($conn, $analystId, $f['id'], $newId, $f['name']);
        }
    }
    return $newId;
}

function filesGrantCreatorFull(PDO $conn, int $analystId, int $folderId): void
{
    $conn->prepare("INSERT INTO files_permissions (folder_id, principal_type, principal_id, level, granted_by, granted_datetime)
                    VALUES (?, 'analyst', ?, ?, ?, UTC_TIMESTAMP())
                    ON DUPLICATE KEY UPDATE level = VALUES(level)")->execute([$folderId, $analystId, FilesAcl::FULL, $analystId]);
}
