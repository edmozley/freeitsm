<?php
/**
 * Moving one of the stores (#284) - ticket attachments, change attachments, documents,
 * recordings, war-room attachments - between its server folder and S3, when its switch
 * on System -> Storage changes. Files has its own move (includes/files/storage_migration.php);
 * the rules here are the same:
 *
 *   START   the store's files are LISTED at the old place (guard files are not files)
 *           into storage_migration_items (store = the store id), the old place is kept as
 *           storage_<store>_previous and the switch points at the new place. From then on
 *           writes go to the new place, reads try the new place and then the old, deletes
 *           remove both (includes/storage/stores.php).
 *   COPY    the storage_migrate job reads each pending file into a temporary file,
 *           checks its size, writes it to the new place, checks the size there and
 *           records its SHA-256 -> 'verified'. Five failures -> 'failed' (Retry). An
 *           outage stops the batch without charging attempts.
 *           When nothing is pending, the old place is listed AGAIN and anything that
 *           arrived there late (a writer that started before the switch) is queued.
 *   CLEANUP only after "Remove old copies" (storage_<store>_cleanup = 1): each verified
 *           file's old copy is deleted; then the move ends. "Finish, keep old copies"
 *           ends it without deleting. The same rules as Files after the PR #318 review
 *           (includes/files/storage_migration.php):
 *             - deletes only when the two places are PROVED different
 *               (storageLocationRelation() === 'different'); 'unknown' deletes nothing;
 *             - each old copy is deleted only once its new copy is there at the recorded
 *               size; a missing one goes back to 'pending' and is copied again;
 *             - pending rows are copied before anything is deleted, and ending a move
 *               clears only finished rows (storeMoveEnd), so nothing is dropped.
 */

require_once __DIR__ . '/stores.php';

const STORE_MOVE_MAX_ATTEMPTS = 5;

/** A store's move setting. */
function storeMoveSetting(PDO $conn, string $store, string $what): string
{
    storeCheckId($store);
    return storeSetting($conn, "storage_{$store}_{$what}");
}

/**
 * Switches a store to $to ('local' or 's3') and, when it holds files, starts moving them.
 *
 * @return int how many files were queued (0: nothing to move, or already there)
 * @throws InvalidArgumentException when it cannot (a move running, no connection, unknown place)
 */
function storeMoveStart(PDO $conn, string $store, string $to, int $by): int
{
    storeCheckId($store);
    if (!in_array($to, ['local', 's3'], true)) throw new InvalidArgumentException('Choose a server folder or S3-compatible storage.');
    storeReset();
    if (storeMoveSetting($conn, $store, 'previous') !== '') {
        throw new InvalidArgumentException('A move of this store is still running. Wait for it to finish, or finish it, before changing again.');
    }
    if (storeLocation($conn, $store) === $to) return 0;
    if ($to === 's3' && storageS3Connection($conn) === null) {
        throw new InvalidArgumentException('Save the S3-compatible connection on this page first.');
    }

    $fromCfg = storeConfig($conn, $store);
    $from = storeMakeDriver($fromCfg, $store);
    $toCfg = $to === 's3' ? storageS3Connection($conn) : ['driver' => 'local', 'root' => storeLocalRoot($store)];
    $dest = storeMakeDriver($toCfg, $store);

    // Different settings can still be one place; then there is nothing to copy, and a
    // "move" must never reach the step that deletes old copies.
    if (storageLocationRelation($dest, $from) === 'same') {
        storeSaveSetting($conn, "storage_{$store}_location", $to === 's3' ? 's3' : null);
        storeReset();
        storeNotifyMoved($conn, $store, $to, 0, $by);
        return 0;
    }

    $listing = [];
    if ($from instanceof StorageLister) {
        foreach ($from->listObjects('') as $k => $o) if (storeListedKeyIsFile($k)) $listing[$k] = (int)$o['size'];
    }

    $own = !$conn->inTransaction();
    if ($own) $conn->beginTransaction();
    try {
        // No move of this store is running (checked above), so any rows left are from one
        // that ended; the listing below is the whole queue for this one.
        $conn->prepare('DELETE FROM storage_migration_items WHERE store = ?')->execute([$store]);
        $ins = $conn->prepare("INSERT IGNORE INTO storage_migration_items (store, object_key, size_bytes, state, updated_datetime)
                               VALUES (?, ?, ?, 'pending', UTC_TIMESTAMP())");
        foreach ($listing as $k => $size) $ins->execute([$store, $k, $size]);
        if ($listing) storeSaveSetting($conn, "storage_{$store}_previous", encryptValue(json_encode($fromCfg)));
        storeSaveSetting($conn, "storage_{$store}_cleanup", null);
        storeSaveSetting($conn, "storage_{$store}_location", $to === 's3' ? 's3' : null);
        if ($own) $conn->commit();
    } catch (Throwable $e) {
        if ($own && $conn->inTransaction()) $conn->rollBack();
        throw $e;
    }
    storeReset();
    storeMoveAudit($conn, $by, $store, ['to' => $to, 'files' => count($listing)]);
    storeNotifyMoved($conn, $store, $to, count($listing), $by);
    return count($listing);
}

/**
 * One batch of a store's running move, until $deadlineUnix or $limit files.
 *
 * @return array{copied: int, failed: int, removed: int, done: bool, unavailable?: bool}
 */
function storeMoveBatch(PDO $conn, string $store, int $deadlineUnix, int $limit = 200): array
{
    $r = ['copied' => 0, 'failed' => 0, 'removed' => 0, 'done' => false];
    storeReset();
    try {
        $prev = storePrevious($conn, $store);
        if ($prev === null) { $r['done'] = true; return $r; }
        $active = storeActive($conn, $store);
    } catch (StorageUnavailable $e) {
        $r['unavailable'] = true;
        return $r;
    }
    $cleanup = storeMoveSetting($conn, $store, 'cleanup') === '1';

    // Anything still to copy (a file that went back to pending) is copied first, even
    // while removing old copies - never deleted before it has a new copy.
    $pl = $conn->prepare("SELECT COUNT(*) FROM storage_migration_items WHERE store = ? AND state = 'pending'");
    $pl->execute([$store]);
    $deleting = $cleanup && (int)$pl->fetchColumn() === 0;

    // Before deleting anything: if the two places are one after all, every "old copy" IS
    // the new copy - end the move and delete nothing. If they cannot be PROVED different,
    // delete nothing either; the job tries again.
    // TRAP: never treat 'unknown' as 'different' (PR #318 review).
    if ($deleting) {
        $rel = storageLocationRelation($active, $prev);
        if ($rel === 'same') {
            $conn->prepare("UPDATE storage_migration_items SET state = 'removed' WHERE store = ? AND state = 'verified'")->execute([$store]);
            storeMoveEnd($conn, $store, 'ended_same_location_nothing_deleted');
            $r['done'] = true;
            return $r;
        }
        if ($rel !== 'different') {
            $r['unavailable'] = true;     // nothing charged to any file
            return $r;
        }
    }

    $state = $deleting ? 'verified' : 'pending';
    $st = $conn->prepare("SELECT id, object_key, size_bytes FROM storage_migration_items
                           WHERE store = ? AND state = ? ORDER BY id LIMIT " . (int)$limit);
    $st->execute([$store, $state]);
    $mark = fn(string $sql, array $args) => $conn->prepare($sql)->execute($args);

    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (time() >= $deadlineUnix) return $r;
        $key = (string)$row['object_key'];
        if ($deleting) {
            try {
                // Never delete the old copy on the strength of a row alone: the new copy
                // must be there, at the size recorded, now.
                if (!storeKeyIsValid($key)) throw new RuntimeException('Not a valid stored-file key.');
                $there = $active->size($key);
                if ($there !== (int)$row['size_bytes']) {
                    $mark("UPDATE storage_migration_items SET state = 'pending', attempts = 0, last_error = ?, updated_datetime = UTC_TIMESTAMP() WHERE id = ?",
                          [mb_substr('The new copy was ' . var_export($there, true) . " bytes, expected {$row['size_bytes']} - copying it again before the old one is removed.", 0, 500), $row['id']]);
                    $r['failed']++;
                    continue;
                }
                $prev->delete($key);
                $mark("UPDATE storage_migration_items SET state = 'removed', updated_datetime = UTC_TIMESTAMP() WHERE id = ?", [$row['id']]);
                $r['removed']++;
            } catch (Throwable $e) {
                $mark("UPDATE storage_migration_items SET last_error = ?, updated_datetime = UTC_TIMESTAMP() WHERE id = ?", [mb_substr($e->getMessage(), 0, 500), $row['id']]);
                $r['failed']++;
                if ($e instanceof StorageUnavailable) { $r['unavailable'] = true; return $r; }
            }
            continue;
        }

        $tmp = null;
        try {
            if (!storeKeyIsValid($key)) throw new RuntimeException('Not a valid stored-file key.');
            $tmp = tempnam(storeMoveTempDir($store), 'smv');
            try {
                $prev->readTo($key, $tmp);
            } catch (StorageNotFound $gone) {
                // Deleted since it was listed (a delete during a move removes both copies):
                // nothing to copy, and nothing an administrator needs to look at.
                $there = $active->size($key);
                $mark("UPDATE storage_migration_items SET state = ?, size_bytes = COALESCE(?, size_bytes), last_error = NULL, updated_datetime = UTC_TIMESTAMP() WHERE id = ?",
                      [$there === null ? 'skipped' : 'verified', $there, $row['id']]);
                continue;
            }
            clearstatcache(true, $tmp);
            $size = (int)filesize($tmp);
            if ($size !== (int)$row['size_bytes']) {
                // Changed since it was listed (rewritten in place): take what is there now.
                $mark('UPDATE storage_migration_items SET size_bytes = ? WHERE id = ?', [$size, $row['id']]);
            }
            $sha = hash_file('sha256', $tmp);
            $active->putFile($key, $tmp, true);
            $there = $active->size($key);
            if ($there !== $size) throw new RuntimeException('The new copy is ' . var_export($there, true) . " bytes, expected $size.");
            // Deleted while it was being copied (storeDelete forgets the row first): this copy
            // is the only one left and nothing records it - remove it.
            $still = $conn->prepare('SELECT 1 FROM storage_migration_items WHERE id = ?');
            $still->execute([$row['id']]);
            if (!$still->fetchColumn()) { $active->delete($key); continue; }
            $mark("UPDATE storage_migration_items SET state = 'verified', sha256 = ?, last_error = NULL, updated_datetime = UTC_TIMESTAMP() WHERE id = ?", [$sha, $row['id']]);
            $r['copied']++;
        } catch (StorageUnavailable $e) {
            // A store is down: nothing is wrong with THIS file. No attempt charged; stop.
            $mark("UPDATE storage_migration_items SET last_error = ?, updated_datetime = UTC_TIMESTAMP() WHERE id = ?", [mb_substr($e->getMessage(), 0, 500), $row['id']]);
            $r['unavailable'] = true;
            return $r;
        } catch (Throwable $e) {
            // MySQL applies SET left to right: `attempts` below is already the new count.
            $mark("UPDATE storage_migration_items SET attempts = attempts + 1, state = IF(attempts >= ?, 'failed', state),
                          last_error = ?, updated_datetime = UTC_TIMESTAMP() WHERE id = ?",
                  [STORE_MOVE_MAX_ATTEMPTS, mb_substr($e->getMessage(), 0, 500), $row['id']]);
            $r['failed']++;
        } finally {
            if ($tmp !== null && is_file($tmp)) @unlink($tmp);
        }
    }

    // Done only when nothing is left to do: while removing old copies that includes
    // files sent back to be copied (pending) and copies that failed.
    $open = $cleanup ? "'pending', 'verified', 'failed'" : "'pending'";
    $left = $conn->prepare("SELECT COUNT(*) FROM storage_migration_items WHERE store = ? AND state IN ($open)");
    $left->execute([$store]);
    $r['done'] = (int)$left->fetchColumn() === 0;

    if (!$cleanup && $r['done']) {
        // Before the copy phase can end: anything that reached the old place after the
        // list was made (a write that began before the switch) is queued too.
        try {
            $known = $conn->prepare('SELECT object_key FROM storage_migration_items WHERE store = ?');
            $known->execute([$store]);
            $have = array_flip($known->fetchAll(PDO::FETCH_COLUMN));
            $ins = $conn->prepare("INSERT IGNORE INTO storage_migration_items (store, object_key, size_bytes, state, updated_datetime)
                                   VALUES (?, ?, ?, 'pending', UTC_TIMESTAMP())");
            $late = 0;
            foreach ($prev instanceof StorageLister ? $prev->listObjects('') : [] as $k => $o) {
                if (!storeListedKeyIsFile($k) || isset($have[$k])) continue;
                $ins->execute([$store, $k, (int)$o['size']]);
                $late++;
            }
            if ($late > 0) $r['done'] = false;
        } catch (StorageUnavailable $e) {
            $r['unavailable'] = true;
            $r['done'] = false;
        }
    }

    if ($cleanup && $r['done']) storeMoveEnd($conn, $store, 'old_copies_removed');
    return $r;
}

/**
 * What an administrator can do with a running move (as for Files):
 *   retry_failed, skip_missing, remove_old (once all is copied), finish_keep (once all is copied)
 *
 * @throws InvalidArgumentException
 */
function storeMoveAction(PDO $conn, string $store, string $action, int $by): void
{
    $m = storeMoveSummary($conn, $store);
    if ($m === null) throw new InvalidArgumentException('No move of this store is running.');
    $copiedAll = $m['pending'] === 0 && $m['failed'] === 0;
    if ($action === 'retry_failed') {
        $conn->prepare("UPDATE storage_migration_items SET state = 'pending', attempts = 0, last_error = NULL, updated_datetime = UTC_TIMESTAMP()
                         WHERE store = ? AND state = 'failed'")->execute([$store]);
    } elseif ($action === 'skip_missing') {
        $rows = $conn->prepare("SELECT id, object_key FROM storage_migration_items WHERE store = ? AND state = 'failed'");
        $rows->execute([$store]);
        $skip = $conn->prepare("UPDATE storage_migration_items SET state = 'skipped', updated_datetime = UTC_TIMESTAMP() WHERE id = ?");
        try {
            $prev = storePrevious($conn, $store);   // may throw: an undecryptable old place
            if ($prev === null) throw new StorageUnavailable('the old location is no longer recorded.');
            foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $r) if ($prev->size((string)$r['object_key']) === null) $skip->execute([$r['id']]);
        } catch (StorageUnavailable $e) {
            throw new InvalidArgumentException('The old location cannot be reached, so missing files cannot be told apart: ' . $e->getMessage());
        }
    } elseif ($action === 'remove_old') {
        if (!$copiedAll) throw new InvalidArgumentException('Old copies can only be removed once every file has been copied.');
        storeSaveSetting($conn, "storage_{$store}_cleanup", '1');
    } elseif ($action === 'finish_keep') {
        if (!$copiedAll) throw new InvalidArgumentException('The move can only be finished once every file has been copied.');
        storeMoveEnd($conn, $store, 'finished_old_copies_kept', $by);
        return;
    } else {
        throw new InvalidArgumentException('Unknown action.');
    }
    storeReset();
    storeMoveAudit($conn, $by, $store, ['action' => $action]);
}

/** Progress of a store's running move, or null when none is running. */
function storeMoveSummary(PDO $conn, string $store): ?array
{
    storeCheckId($store);
    if (storeMoveSetting($conn, $store, 'previous') === '') return null;
    $q = $conn->prepare('SELECT state, COUNT(*) FROM storage_migration_items WHERE store = ? GROUP BY state');
    $q->execute([$store]);
    $m = ['pending' => 0, 'verified' => 0, 'failed' => 0, 'removed' => 0, 'skipped' => 0];
    foreach ($q->fetchAll(PDO::FETCH_KEY_PAIR) as $state => $n) if (isset($m[$state])) $m[$state] = (int)$n;
    $m['total'] = array_sum($m);
    try {
        $prev = storePreviousConfig($conn, $store);
        $m['from'] = ($prev['driver'] ?? 'local') === 's3' ? 's3' : 'local';
    } catch (StorageUnavailable $e) {
        $m['from'] = 'unknown';
    }
    $m['to'] = storeLocation($conn, $store);
    $m['cleanup_running'] = storeMoveSetting($conn, $store, 'cleanup') === '1';
    $err = $conn->prepare("SELECT last_error FROM storage_migration_items WHERE store = ? AND state = 'failed' ORDER BY updated_datetime DESC LIMIT 1");
    $err->execute([$store]);
    $m['last_error'] = $m['failed'] > 0 ? (string)$err->fetchColumn() : null;
    return $m;
}

/** Stores with a move running. */
function storesMoving(PDO $conn): array
{
    return array_values(array_filter(array_keys(STORAGE_STORES), fn($s) => storeMoveSetting($conn, $s, 'previous') !== ''));
}

/**
 * Ends a move: clears the FINISHED rows (verified, removed, skipped) and, when nothing
 * unfinished is left, forgets the old place. A row still pending or failed keeps the
 * move running, so its file is never left in a place nothing records.
 *
 * TRAP: never DELETE every row of the store to end a move (PR #318 review).
 */
function storeMoveEnd(PDO $conn, string $store, string $how, ?int $by = null): void
{
    // Files set aside as missing are named in the record, so one skipped because of a
    // passing permission error can still be found afterwards.
    $sk = $conn->prepare("SELECT object_key FROM storage_migration_items WHERE store = ? AND state = 'skipped' ORDER BY id LIMIT 200");
    $sk->execute([$store]);
    $skipped = $sk->fetchAll(PDO::FETCH_COLUMN);
    $conn->prepare("DELETE FROM storage_migration_items WHERE store = ? AND state IN ('verified', 'removed', 'skipped')")->execute([$store]);
    $u = $conn->prepare('SELECT COUNT(*) FROM storage_migration_items WHERE store = ?');
    $u->execute([$store]);
    $unfinished = (int)$u->fetchColumn();
    storeSaveSetting($conn, "storage_{$store}_cleanup", null);
    if ($unfinished === 0) storeSaveSetting($conn, "storage_{$store}_previous", null);
    storeReset();
    $detail = ['ended' => $how];
    if ($skipped) $detail['skipped_missing'] = implode(', ', $skipped);
    if ($unfinished) $detail['still_to_copy'] = $unfinished;
    storeMoveAudit($conn, $by, $store, $detail);
    // Storage series N1: administrators are told a move ended.
    require_once __DIR__ . '/notify.php';
    $what = ['old_copies_removed' => 'the old copies were removed', 'finished_old_copies_kept' => 'the old copies were kept',
             'ended_same_location_nothing_deleted' => 'the old and new places turned out to be one, so nothing was deleted'][$how] ?? $how;
    storageNotifyAdmins($conn, storeName($store) . ': move finished', storeName($store) . " finished moving: $what."
        . ($unfinished ? " Files still to copy: $unfinished." : ''), 'storage', $by);
}

/** A store's name for people. */
function storeName(string $store): string
{
    return ['tickets' => 'Ticket attachments', 'changes' => 'Change attachments', 'documents' => 'Documents',
            'recordings' => 'Screen recordings', 'warroom' => 'War-room attachments'][$store] ?? $store;
}

/** Storage series N1: a store's location changed (and a move started, when it holds files). */
function storeNotifyMoved(PDO $conn, string $store, string $to, int $files, int $by): void
{
    require_once __DIR__ . '/notify.php';
    $where = $to === 's3' ? 'S3-compatible storage' : 'the server folder';
    storageNotifyAdmins($conn, storeName($store) . ' now kept in ' . $where,
        storeName($store) . " are now kept in $where." . ($files > 0 ? " Files being copied there in the background: $files. Until each is, it is read from the old place." : ''),
        'storage', $by);
}

/** Where a file waits between the two places: the store's own staging folder (same disk as the store). */
function storeMoveTempDir(string $store): string
{
    $dir = storeLocalRoot($store) . '/' . STORAGE_STAGING_DIR;
    if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
        throw new StorageUnavailable('The staging folder could not be created.');
    }
    return $dir;
}

/** A line in the system log: who moved which store where. */
function storeMoveAudit(PDO $conn, ?int $by, string $store, array $detail): void
{
    error_log('[storage] ' . $store . ' ' . json_encode($detail) . ($by !== null ? " (analyst $by)" : ''));
    try {
        $conn->prepare("INSERT INTO system_logs (log_type, analyst_id, details, created_datetime) VALUES ('storage', ?, ?, UTC_TIMESTAMP())")
             ->execute([$by, json_encode(['store' => $store] + $detail)]);
    } catch (Throwable $e) {
        // the error_log line above is the record
    }
}
