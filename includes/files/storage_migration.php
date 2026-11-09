<?php
/**
 * Moving Files' stored bytes to a new location, a batch at a time. Run by the
 * storage_migrate background job (cron/storage_migrate.php); started by saving
 * a new location in Files -> Settings -> Storage (FilesStorageService::save).
 *
 * COPY: each pending storage_migration_items row is read from the previous
 * location into a temporary file, checked (size, and SHA-256 where Files
 * recorded one), written to the active location, checked again (size there),
 * and only then marked 'verified' - from that moment filesStoreFor() reads it
 * from the new place. A failure counts an attempt; five make the row 'failed'
 * (shown on the Storage tab, with Retry).
 *
 * CLEANUP: only after someone chooses "Remove old copies" (files_storage_cleanup
 * = 1), each verified row's old copy is deleted. When none is left the move
 * ends: files_storage_previous and the rows are cleared.
 */

require_once __DIR__ . '/storage.php';
require_once __DIR__ . '/audit.php';

const FILES_MIGRATION_MAX_ATTEMPTS = 5;

/**
 * Does one batch of the running move, until $deadlineUnix or $limit rows.
 *
 * @return array ['copied' => int, 'failed' => int, 'removed' => int, 'done' => bool]
 *               failed = rows that failed an attempt in this batch; done = nothing
 *               left to do in the current phase (copy, or cleanup)
 */
function storageMigrateBatch(PDO $conn, int $deadlineUnix, int $limit = 200): array
{
    $r = ['copied' => 0, 'failed' => 0, 'removed' => 0, 'done' => false];
    $prev = filesPreviousStore($conn);
    if ($prev === null) { $r['done'] = true; return $r; }
    $active = filesActiveStore($conn);
    $cleanup = filesSetting($conn, 'files_storage_cleanup') === '1';

    $state = $cleanup ? 'verified' : 'pending';
    $st = $conn->prepare("SELECT id, object_key, size_bytes, sha256 FROM storage_migration_items
                           WHERE store = 'files' AND state = ? ORDER BY id LIMIT " . (int)$limit);
    $st->execute([$state]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $row) {
        if (time() >= $deadlineUnix) return $r;
        $key = (string)$row['object_key'];
        if ($cleanup) {
            try {
                $prev->delete($key);
                $conn->prepare("UPDATE storage_migration_items SET state = 'removed', updated_datetime = UTC_TIMESTAMP() WHERE id = ?")->execute([$row['id']]);
                $r['removed']++;
            } catch (Throwable $e) {
                $conn->prepare("UPDATE storage_migration_items SET last_error = ?, updated_datetime = UTC_TIMESTAMP() WHERE id = ?")
                     ->execute([mb_substr($e->getMessage(), 0, 500), $row['id']]);
                $r['failed']++;
            }
            continue;
        }

        $tmp = tempnam(sys_get_temp_dir(), 'fmig');
        try {
            if (!filesStorageKeyIsValid($key)) throw new RuntimeException('Not a stored-file path.');
            $prev->readTo($key, $tmp);
            clearstatcache(true, $tmp);
            $size = (int)filesize($tmp);
            if ($size !== (int)$row['size_bytes']) throw new RuntimeException("The old copy is $size bytes, expected {$row['size_bytes']}.");
            $sha = hash_file('sha256', $tmp);
            if ($row['sha256'] !== null && $row['sha256'] !== '' && !hash_equals((string)$row['sha256'], $sha)) {
                throw new RuntimeException('The old copy does not match the checksum recorded at upload.');
            }
            $active->putFile($key, $tmp, true);
            $there = $active->size($key);
            if ($there !== (int)$row['size_bytes']) throw new RuntimeException('The new copy is ' . var_export($there, true) . " bytes, expected {$row['size_bytes']}.");
            $conn->prepare("UPDATE storage_migration_items SET state = 'verified', sha256 = ?, last_error = NULL, updated_datetime = UTC_TIMESTAMP() WHERE id = ?")
                 ->execute([$sha, $row['id']]);
            $r['copied']++;
        } catch (Throwable $e) {
            // MySQL applies SET left to right: `attempts` below is already the new count.
            $conn->prepare("UPDATE storage_migration_items
                               SET attempts = attempts + 1,
                                   state = IF(attempts >= ?, 'failed', state),
                                   last_error = ?, updated_datetime = UTC_TIMESTAMP()
                             WHERE id = ?")
                 ->execute([FILES_MIGRATION_MAX_ATTEMPTS, mb_substr($e->getMessage(), 0, 500), $row['id']]);
            $r['failed']++;
        } finally {
            @unlink($tmp);
        }
    }

    $left = $conn->prepare("SELECT COUNT(*) FROM storage_migration_items WHERE store = 'files' AND state = ?");
    $left->execute([$state]);
    $r['done'] = (int)$left->fetchColumn() === 0;

    if ($cleanup && $r['done']) {
        // Every old copy is gone: the move is over.
        $from = $prev->label();
        filesSaveSetting($conn, 'files_storage_previous', null);
        filesSaveSetting($conn, 'files_storage_cleanup', null);
        $conn->exec("DELETE FROM storage_migration_items WHERE store = 'files'");
        filesStorageReset();
        filesAudit($conn, 0, 'settings', ['path' => '(Settings: storage)'], ['storage_move' => 'old_copies_removed', 'from' => $from]);
    }
    return $r;
}
