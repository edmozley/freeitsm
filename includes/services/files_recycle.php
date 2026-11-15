<?php
/**
 * FilesRecycleService - the Files recycle bin. The rules ONCE; api/files/recycle.php,
 * api/files/settings.php and cron/files_recycle_purge.php only pass input in and out.
 *
 *   entries()       what one person sees in the bin
 *   contents()      the files inside a deleted folder (read-only)
 *   restore()       put a file or folder back where it was
 *   purge()         delete one entry for good - rows, shortcuts AND the stored bytes
 *   emptyBin()      purge every entry this person may purge
 *   purgeExpired()  the retention job: entries older than files_recycle_bin_days
 *   retryPendingBytes()  bytes whose deletion failed because storage was down
 *
 * WHAT IS AN ENTRY: a folder or file whose own row is deleted while everything above
 * it is live. Things inside a deleted folder are not entries of their own - they go
 * and come back with it, as in Windows.
 *
 * WHO: permissions as they were before the delete (FilesAcl::levelsIncludingDeleted).
 * Seeing an entry needs View; restoring or purging it needs Modify - the level that
 * deleted it. Hidden means not found here too.
 *
 * BYTES: every version of a file goes (files_versions rows and their bytes). A
 * copied file or a restored version SHARES a storage_path, so bytes are deleted only
 * once no files_versions row names them - filesPurgeBytesIfUnused() (files_versions.php)
 * decides that and deletes from the active location and, during a storage move, the
 * previous one. Every key is recorded in files_purge_pending WITH the rows (same
 * transaction), so a purge cut short - a killed request, a deploy - forgets nothing; the
 * record goes once its bytes are gone. Storage is not asked again in that purge once it
 * is found unreachable, and a purge out of time leaves the rest to the job: the person
 * purging never waits on, or sees, a storage error for rows that are already gone.
 *
 * LINKS: a document that links to a file in Files (documents.kind = 'files') reads as
 * deleted while the file or a folder above it is in the bin (FilesAttach::viewFor),
 * works again after a restore, and after a permanent delete keeps its row - the record
 * still shows something was there - with files_item_id cleared, so it never opens again.
 *
 * RETENTION: files_recycle_bin_days, 0 = keep for ever. Until someone saves the
 * Recycle bin settings tab the setting is absent and nothing is purged, so an
 * upgrade never deletes anything on its own. THE CLOCK starts when purging is
 * switched on (files_recycle_bin_since): something already in the bin then gets
 * the whole period from that moment, never "deleted within the hour" because it
 * was binned long before anyone chose a period. (Decided 16 Nov 2026, GH #293.)
 *
 * HELD: the retention job only purges what the person who deleted it could have
 * deleted for good by hand (canPurge as them) - never a subfolder kept from them.
 * Such a folder stays in the bin, past its date, until someone resolves it:
 * System -> Maintenance -> "Deleted folders kept" lists them (heldEntries()). A
 * folder cannot be deleted while it holds anything its deleter cannot change
 * (FilesAcl::refusalInside), so this is mostly for permissions changed after the
 * delete, and for rows whose deleter was not recorded.
 *
 * DELETED ALONG WITH A FOLDER ABOVE IT: somebody who can see a subfolder of a
 * deleted folder, but not the deleted folder itself, would otherwise find their
 * folder simply gone. They see it in their bin as an entry of its own ('rescue'),
 * without the hidden folder's name, and Restore brings it back on its own to where
 * the deleted folder was, its permissions frozen first so nobody gains or loses
 * access by the move (rescue()).
 *
 * WHO ACTS: an analyst id, or null for the retention job (the system), which needs
 * no permission. Never 0: a missing session id must not mean "the system".
 *
 * Throws InvalidArgumentException with a message for the person (FilesRecycleBlocked
 * when a parent folder has to be restored first).
 */

require_once __DIR__ . '/../files/acl.php';
require_once __DIR__ . '/../files/audit.php';
require_once __DIR__ . '/../files/storage.php';
require_once __DIR__ . '/../files/names.php';
require_once __DIR__ . '/files_versions.php';   // filesPurgeBytesIfUnused()

/** Restore refused because the folder it came from is in the bin too. */
final class FilesRecycleBlocked extends InvalidArgumentException
{
    /** @var array{type:string,id:int,name:string} the bin entry to restore first */
    public array $first;

    public function __construct(string $message, array $first)
    {
        parent::__construct($message);
        $this->first = $first;
    }
}

final class FilesRecycleService
{
    /** How long one purge spends deleting stored bytes before leaving the rest to the job. */
    public const PURGE_BYTES_SECONDS = 20;

    const DEFAULT_DAYS = 30;
    const MAX_DAYS = 3650;

    /**
     * Days an entry stays in the bin before the job purges it: null when nobody has
     * saved the setting yet (nothing is purged), 0 = for ever.
     */
    public static function retentionDays(PDO $conn): ?int
    {
        $v = filesSetting($conn, 'files_recycle_bin_days', '');
        return $v === '' ? null : max(0, (int)$v);
    }

    /** When purging was switched on (UTC 'Y-m-d H:i:s'), or null while it is off. */
    public static function clockSince(PDO $conn): ?string
    {
        $v = filesSetting($conn, 'files_recycle_bin_since', '');
        return $v === '' ? null : $v;
    }

    /**
     * When an entry's time in the bin started counting: when it was deleted, or when
     * purging was switched on if that was later.
     */
    private static function clockStart(array $e, ?string $since): string
    {
        return ($since !== null && $since > $e['deleted_at']) ? $since : $e['deleted_at'];
    }

    /**
     * Saves the retention period (the Recycle bin settings tab).
     *
     * @param int $days  0 (keep for ever) to MAX_DAYS
     */
    public static function saveRetention(PDO $conn, int $analystId, int $days): void
    {
        if ($days < 0 || $days > self::MAX_DAYS) {
            throw new InvalidArgumentException('Enter a number of days from 0 to ' . self::MAX_DAYS . ' (0 keeps deleted items for ever).');
        }
        $before = self::retentionDays($conn);
        filesSaveSetting($conn, 'files_recycle_bin_days', (string)$days);
        // The clock starts when purging is switched ON - first save, or from 0 (keep
        // for ever) to a number - and runs on through later changes of the period.
        if ($days === 0) {
            filesSaveSetting($conn, 'files_recycle_bin_since', '');
        } elseif (!$before || self::clockSince($conn) === null) {
            filesSaveSetting($conn, 'files_recycle_bin_since', gmdate('Y-m-d H:i:s'));
        }
        filesStorageReset();
        filesAudit($conn, $analystId, 'settings', ['path' => '(Settings: recycle bin)'],
                   ['recycle_bin_days' => ['from' => $before, 'to' => $days]]);
    }

    /**
     * What the Recycle bin settings tab shows.
     *
     * @param ?int $askDays  a period being considered: 'due' then counts what saving it
     *                       would let the next job run delete (the page asks before saving)
     */
    public static function describe(PDO $conn, ?int $askDays = null): array
    {
        $days = self::retentionDays($conn);
        $u = self::binUsage($conn);
        // null = unknown (the table arrives with Database Verification): never a false 0.
        $pending = null;
        try {
            $pending = (int)$conn->query("SELECT COUNT(*) FROM files_purge_pending")->fetchColumn();
        } catch (PDOException $e) {
            if (!self::missingTable($e)) throw $e;
        }
        $entries = self::allEntries($conn);
        $consider = $askDays ?? $days;
        // Saving a period while purging is off switches it on NOW: the clock starts
        // now, so nothing already in the bin is due yet.
        $since = ($askDays !== null && !$days) ? gmdate('Y-m-d H:i:s') : self::clockSince($conn);
        $due = 0;
        if ($consider) {
            $cutoff = gmdate('Y-m-d H:i:s', time() - $consider * 86400);
            foreach ($entries as $e) {
                if (self::clockStart($e, $since) < $cutoff && !self::heldBack($conn, $e)) $due++;
            }
        }
        return [
            'days'          => $days ?? self::DEFAULT_DAYS,
            'configured'    => $days !== null,
            'entries'       => count($entries),
            'due'           => $due,
            'bin_files'     => $u['files'],
            'bin_bytes'     => $u['bytes'],
            'pending_bytes' => $pending,
            'held'          => $days ? count(array_filter(self::heldEntries($conn), fn($h) => $h['due'])) : 0,
            'max_days'      => self::MAX_DAYS,
        ];
    }

    /** True when a query failed only because a table is not there yet (before Database Verification). */
    private static function missingTable(PDOException $e): bool
    {
        return $e->getCode() === '42S02' || str_contains($e->getMessage(), '1146');
    }

    /**
     * Stored objects only deleted things use: [files => n, bytes => n]. A .bin a live
     * file still shares (a copy) is not counted - purging would not free it.
     */
    public static function binUsage(PDO $conn): array
    {
        $tree = FilesAcl::tree($conn);
        $rows = $conn->query("SELECT v.storage_path, v.size_bytes, i.folder_id, i.deleted_datetime
                                FROM files_versions v JOIN files_items i ON i.id = v.item_id")->fetchAll(PDO::FETCH_ASSOC);
        $live = []; $size = [];
        foreach ($rows as $r) {
            $p = $r['storage_path'];
            $isLive = $r['deleted_datetime'] === null && isset($tree[(int)$r['folder_id']]);
            $live[$p] = ($live[$p] ?? false) || $isLive;
            $size[$p] = max($size[$p] ?? 0, (int)$r['size_bytes']);
        }
        $n = 0; $bytes = 0;
        foreach ($live as $p => $l) {
            if ($l) continue;
            $n++;
            $bytes += $size[$p];
        }
        return ['files' => $n, 'bytes' => $bytes];
    }

    /**
     * Every bin entry, regardless of who is asking: deleted folders whose parent is
     * live (or top level), and deleted files whose folder is live.
     *
     * @return array<int, array{type:string,id:int,name:string,parent_id:?int,deleted_at:string,deleted_by:?int}>
     */
    private static function allEntries(PDO $conn): array
    {
        $all = FilesAcl::allFolders($conn);
        $out = [];
        foreach ($all as $f) {
            if (!$f['deleted']) continue;
            if ($f['parent_id'] !== null && empty($all[$f['parent_id']]['live'])) continue;
            $out[] = ['type' => 'folder', 'id' => $f['id'], 'name' => $f['name'], 'parent_id' => $f['parent_id'],
                      'deleted_at' => $f['deleted_at'], 'deleted_by' => $f['deleted_by']];
        }
        $st = $conn->query("SELECT id, folder_id, name, size_bytes, deleted_by, deleted_datetime FROM files_items WHERE deleted_datetime IS NOT NULL");
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $i) {
            if (empty($all[(int)$i['folder_id']]['live'])) continue;
            $out[] = ['type' => 'item', 'id' => (int)$i['id'], 'name' => $i['name'], 'parent_id' => (int)$i['folder_id'],
                      'deleted_at' => $i['deleted_datetime'], 'deleted_by' => $i['deleted_by'] !== null ? (int)$i['deleted_by'] : null,
                      'size' => (int)$i['size_bytes']];
        }
        return $out;
    }

    /**
     * The folder whose permissions decide an entry: a deleted folder itself, or a
     * deleted file's folder.
     */
    private static function aclFolder(array $e): int
    {
        return $e['type'] === 'folder' ? $e['id'] : (int)$e['parent_id'];
    }

    /** A folder and every folder under it, deleted ones included. */
    private static function subtree(PDO $conn, int $folderId): array
    {
        $kids = [];
        foreach (FilesAcl::allFolders($conn) as $f) {
            if ($f['parent_id'] !== null) $kids[$f['parent_id']][] = $f['id'];
        }
        $out = [];
        $stack = [$folderId];
        while ($stack) {
            $id = array_pop($stack);
            if (isset($out[$id])) continue;
            $out[$id] = true;
            foreach ($kids[$id] ?? [] as $k) $stack[] = $k;
        }
        return array_keys($out);
    }

    /**
     * The folders of a subtree this person holds at least $need on (as before the
     * delete). A deleted folder can hold a subfolder that stopped inheriting and was
     * never shared with the person who deleted the parent: its name, files and sizes
     * must not reach them through the bin. Null (the retention job) gets all.
     */
    private static function subtreeAtLeast(PDO $conn, ?int $analystId, int $folderId, int $need): array
    {
        $sub = self::subtree($conn, $folderId);
        if ($analystId === null) return $sub;
        $levels = FilesAcl::levelsIncludingDeleted($conn, $analystId);
        return array_values(array_filter($sub, fn($fid) => ($levels[$fid] ?? 0) >= $need));
    }

    /**
     * May this person delete the whole entry for good? Modify on the folder (or a file's
     * folder) AND on every folder under a deleted folder: a purge cannot be undone, so it
     * must never destroy something they could not even see.
     */
    private static function canPurge(PDO $conn, ?int $analystId, array $e): bool
    {
        if ($analystId === null) return true;
        $levels = FilesAcl::levelsIncludingDeleted($conn, $analystId);
        if (($levels[self::aclFolder($e)] ?? 0) < FilesAcl::MODIFY) return false;
        if ($e['type'] !== 'folder') return true;
        foreach (self::subtree($conn, $e['id']) as $fid) {
            if (($levels[$fid] ?? 0) < FilesAcl::MODIFY) return false;
        }
        return true;
    }

    /**
     * The highest folders inside $folderId (not itself) this person can see: where
     * a branch of a hidden deleted folder becomes theirs. Deeper visible folders
     * come back with these, so are not listed again.
     *
     * @param array $levels  FilesAcl::levelsIncludingDeleted() for the person
     * @return list<int>
     */
    private static function visibleTopsInside(PDO $conn, array $levels, int $folderId): array
    {
        $kids = [];
        foreach (FilesAcl::allFolders($conn) as $f) {
            if ($f['parent_id'] !== null) $kids[$f['parent_id']][] = $f['id'];
        }
        $out = [];
        $stack = $kids[$folderId] ?? [];
        $seen = [];
        while ($stack) {
            $id = array_pop($stack);
            if (isset($seen[$id])) continue;
            $seen[$id] = true;
            if (($levels[$id] ?? 0) >= FilesAcl::VIEW) { $out[] = $id; continue; }
            foreach ($kids[$id] ?? [] as $k) $stack[] = $k;
        }
        return $out;
    }

    /**
     * Must the retention job leave this entry alone? True for a folder holding
     * anything its deleter could not have deleted for good by hand (Ed, 16 Nov 2026:
     * "keep it safe"). A file never is - deleting it needed Modify on its folder.
     * Deleter unknown (an older row): held when anything inside stops inheriting,
     * which is the only way a subfolder can be kept from anyone.
     */
    private static function heldBack(PDO $conn, array $e): bool
    {
        if ($e['type'] !== 'folder') return false;
        if ($e['deleted_by'] === null) {
            $all = FilesAcl::allFolders($conn);
            foreach (self::subtree($conn, $e['id']) as $fid) {
                if ($fid !== $e['id'] && empty($all[$fid]['inherit'])) return true;
            }
            return false;
        }
        return !self::canPurge($conn, $e['deleted_by'], $e);
    }

    /**
     * Every deleted folder the retention job is holding back, for System ->
     * Maintenance -> "Deleted folders kept" (administrators: names are shown).
     *
     * @return list<array{id:int, name:string, path:string, deleted_at:string, deleted_by_name:?string,
     *                    due:bool, deleter_lost_access:bool, blocking:list<string>, blocking_count:int}>
     *         due = it would have been deleted already but for this
     */
    public static function heldEntries(PDO $conn): array
    {
        $days = self::retentionDays($conn);
        $since = self::clockSince($conn);
        $cutoff = $days ? gmdate('Y-m-d H:i:s', time() - $days * 86400) : null;
        $out = [];
        foreach (self::allEntries($conn) as $e) {
            if (!self::heldBack($conn, $e)) continue;
            $by = $e['deleted_by'];
            $levels = $by !== null ? FilesAcl::levelsIncludingDeleted($conn, $by) : [];
            $all = FilesAcl::allFolders($conn);
            $blocking = [];
            foreach (self::subtree($conn, $e['id']) as $fid) {
                if ($fid === $e['id']) continue;
                $blocks = $by === null ? empty($all[$fid]['inherit']) : (($levels[$fid] ?? 0) < FilesAcl::MODIFY);
                if ($blocks) $blocking[] = FilesAcl::pathIncludingDeleted($conn, $fid);
            }
            sort($blocking, SORT_NATURAL | SORT_FLAG_CASE);
            $name = null;
            if ($by !== null) {
                $st = $conn->prepare("SELECT full_name FROM analysts WHERE id = ?");
                $st->execute([$by]);
                $name = $st->fetchColumn() ?: null;
            }
            $out[] = [
                'id'                  => $e['id'],
                'name'                => $e['name'],
                'path'                => FilesAcl::pathIncludingDeleted($conn, $e['id']),
                'deleted_at'          => $e['deleted_at'],
                'deleted_by_name'     => $name,
                'due'                 => $cutoff !== null && self::clockStart($e, $since) < $cutoff,
                'deleter_lost_access' => $by !== null && ($levels[$e['id']] ?? 0) < FilesAcl::MODIFY,
                'blocking'            => array_slice($blocking, 0, 50),
                'blocking_count'      => count($blocking),
            ];
        }
        usort($out, fn($a, $b) => strcmp($a['deleted_at'], $b['deleted_at']));
        return $out;
    }

    /**
     * Gives a folder that inherits its permissions the same entries as its own, and
     * stops it inheriting - so moving it changes nobody's access. The chain is read
     * over deleted folders too (allFolders), as the folder stood in the bin.
     */
    private static function freezePermissions(PDO $conn, int $folderId, int $byAnalyst): void
    {
        $all = FilesAcl::allFolders($conn);
        $f = $all[$folderId] ?? null;
        if (!$f || !$f['inherit']) return;
        $chain = [];
        $guard = 0;
        while ($f && $guard++ < 200) {
            $chain[] = $f['id'];
            if (!$f['inherit'] || $f['parent_id'] === null) break;
            $f = $all[$f['parent_id']] ?? null;
        }
        $in = implode(',', array_fill(0, count($chain), '?'));
        $st = $conn->prepare("SELECT principal_type, principal_id, MAX(level) FROM files_permissions WHERE folder_id IN ($in) GROUP BY principal_type, principal_id");
        $st->execute($chain);
        $ins = $conn->prepare("INSERT INTO files_permissions (folder_id, principal_type, principal_id, level, granted_by, granted_datetime)
                               VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE level = GREATEST(level, VALUES(level))");
        foreach ($st->fetchAll(PDO::FETCH_NUM) as [$type, $pid, $lvl]) $ins->execute([$folderId, $type, (int)$pid, (int)$lvl, $byAnalyst]);
        $conn->prepare("UPDATE files_folders SET inherit_permissions = 0 WHERE id = ?")->execute([$folderId]);
    }

    /**
     * Restores a folder that went into the bin with a folder above it that this
     * person cannot see: on its own, to where that deleted folder was, permissions
     * frozen first (freezePermissions) so the move gives nobody access and takes
     * none away. Needs Modify on the folder. The hidden folder is never named.
     */
    private static function rescue(PDO $conn, int $analystId, int $folderId, array $top): array
    {
        $levels = FilesAcl::levelsIncludingDeleted($conn, $analystId);
        if (($levels[$folderId] ?? 0) < FilesAcl::MODIFY) {
            filesAudit($conn, $analystId, 'denied', ['folder_id' => $folderId],
                       ['wanted' => 'modify', 'held' => FilesAcl::levelKey($levels[$folderId] ?? 0), 'doing' => 'restore']);
            throw new InvalidArgumentException('You do not have permission to do that here.');
        }
        $from = FilesAcl::pathIncludingDeleted($conn, $folderId);
        $own = !$conn->inTransaction();
        if ($own) $conn->beginTransaction();
        try {
            // The deleted folder above, locked: a purge or restore of it waits for this.
            $st = $conn->prepare("SELECT deleted_datetime FROM files_folders WHERE id = ? FOR UPDATE");
            $st->execute([$top['id']]);
            $still = $st->fetchColumn();
            FilesAcl::reset();
            $now = self::binEntryAbove($conn, $folderId);
            if ($still === false || $still === null || !$now || $now['id'] !== $top['id']) throw new InvalidArgumentException('Folder not found.');
            $all = FilesAcl::allFolders($conn);
            $dest = $all[$top['id']]['parent_id'];   // live, or null: the top level
            self::freezePermissions($conn, $folderId, $analystId);
            $was = $GLOBALS['analystId'] ?? null;
            $GLOBALS['analystId'] = $analystId;
            try {
                $name = filesFreeName($conn, $dest, $all[$folderId]['name']);
            } finally {
                $GLOBALS['analystId'] = $was;
            }
            $conn->prepare("UPDATE files_folders SET parent_id = ?, name = ?, deleted_by = NULL, deleted_datetime = NULL,
                                   updated_by = ?, updated_datetime = UTC_TIMESTAMP() WHERE id = ?")
                 ->execute([$dest, $name, $analystId, $folderId]);
            // As restore(): things deleted on their own inside it start their time again.
            $sub = array_values(array_diff(self::subtree($conn, $folderId), [$folderId]));
            $conn->prepare("UPDATE files_items SET deleted_datetime = UTC_TIMESTAMP() WHERE deleted_datetime IS NOT NULL AND folder_id IN ("
                           . implode(',', array_fill(0, count($sub) + 1, '?')) . ")")->execute(array_merge([$folderId], $sub));
            if ($sub) {
                $conn->prepare("UPDATE files_folders SET deleted_datetime = UTC_TIMESTAMP() WHERE deleted_datetime IS NOT NULL AND id IN ("
                               . implode(',', array_fill(0, count($sub), '?')) . ")")->execute($sub);
            }
            if ($own) $conn->commit();
        } catch (Throwable $ex) {
            if ($own && $conn->inTransaction()) $conn->rollBack();
            throw $ex;
        }
        FilesAcl::reset();
        filesAudit($conn, $analystId, 'restore', ['folder_id' => $folderId],
                   array_filter(['type' => 'folder', 'from' => $from, 'out_of_deleted_folder' => $top['id'], 'restored_as' => $name !== $all[$folderId]['name'] ? $name : null]));
        return ['name' => $name, 'renamed' => $name !== $all[$folderId]['name'], 'folder_id' => $folderId];
    }

    /**
     * What this person sees in the bin, most recently deleted first.
     *
     * @return array list of entries: type, id, name, location (the folder it was in),
     *               deleted_at, deleted_by_name, size, files (folders), purge_at, can_modify
     */
    public static function entries(PDO $conn, int $analystId): array
    {
        $levels = FilesAcl::levelsIncludingDeleted($conn, $analystId);
        $days = self::retentionDays($conn);
        $since = self::clockSince($conn);
        $allEntries = self::allEntries($conn);
        $mine = array_values(array_filter($allEntries, fn($e) => ($levels[self::aclFolder($e)] ?? 0) >= FilesAcl::VIEW));
        // A folder of theirs inside a deleted folder they cannot see: an entry of its
        // own, so it does not just vanish. The hidden folder is never named.
        foreach ($allEntries as $e) {
            if ($e['type'] !== 'folder' || ($levels[$e['id']] ?? 0) >= FilesAcl::VIEW) continue;
            foreach (self::visibleTopsInside($conn, $levels, $e['id']) as $fid) {
                $all = FilesAcl::allFolders($conn);
                $mine[] = ['type' => 'folder', 'id' => $fid, 'name' => $all[$fid]['name'], 'parent_id' => null,
                           'deleted_at' => $e['deleted_at'], 'deleted_by' => $e['deleted_by'], 'rescue_of' => $e];
            }
        }
        if (!$mine) return [];

        $names = [];
        $ids = array_values(array_unique(array_filter(array_column($mine, 'deleted_by'))));
        if ($ids) {
            $st = $conn->prepare("SELECT id, full_name FROM analysts WHERE id IN (" . implode(',', array_fill(0, count($ids), '?')) . ")");
            $st->execute($ids);
            $names = $st->fetchAll(PDO::FETCH_KEY_PAIR);
        }

        // Size and file count of each deleted folder: the files under it in folders this
        // person may see - never a hidden subfolder's.
        $folderStats = [];
        foreach ($mine as $e) {
            if ($e['type'] !== 'folder') continue;
            $sub = self::subtreeAtLeast($conn, $analystId, $e['id'], FilesAcl::VIEW);
            $st = $conn->prepare("SELECT COUNT(*), COALESCE(SUM(size_bytes), 0) FROM files_items WHERE folder_id IN (" . implode(',', array_fill(0, count($sub), '?')) . ")");
            $st->execute($sub);
            $folderStats[$e['id']] = $st->fetch(PDO::FETCH_NUM);
        }

        $out = [];
        foreach ($mine as $e) {
            $isFolder = $e['type'] === 'folder';
            $rescue = isset($e['rescue_of']);
            $binned = $rescue ? $e['rescue_of'] : $e;   // what the retention job would purge
            $out[] = [
                'type'            => $e['type'],
                'id'              => $e['id'],
                'name'            => $e['name'],
                'parent_id'       => $e['parent_id'],
                // A rescue row: '' and rescue=true - the folder above it is not theirs to see.
                'location'        => (!$rescue && $e['parent_id'] !== null) ? FilesAcl::path($conn, $e['parent_id']) : '',
                'rescue'          => $rescue,
                'deleted_at'      => $e['deleted_at'],
                'deleted_by_name' => $e['deleted_by'] !== null ? ($names[$e['deleted_by']] ?? null) : null,
                'size'            => $isFolder ? (int)$folderStats[$e['id']][1] : (int)$e['size'],
                'files'           => $isFolder ? (int)$folderStats[$e['id']][0] : null,
                // No date while the job is holding it back: it will not be purged then.
                'purge_at'        => ($days && !self::heldBack($conn, $binned))
                                     ? gmdate('Y-m-d H:i:s', strtotime(self::clockStart($binned, $since) . ' UTC') + $days * 86400) : null,
                'can_modify'      => ($levels[self::aclFolder($e)] ?? 0) >= FilesAcl::MODIFY,
                // A rescue row can only be restored: deleting it for good is for whoever
                // may delete the folder it went with.
                'can_purge'       => !$rescue && self::canPurge($conn, $analystId, $e),
            ];
        }
        usort($out, fn($a, $b) => strcmp($b['deleted_at'], $a['deleted_at']) ?: strnatcasecmp($a['name'], $b['name']));
        return $out;
    }

    /**
     * The files inside a deleted folder, for looking before restoring. Read-only.
     *
     * @param int $folderId  a folder in the bin (an entry, or a folder under one)
     * @return array ['name' => folder name, 'files' => [{name, path (under the folder), size, deleted_at}]]
     */
    public static function contents(PDO $conn, int $analystId, int $folderId): array
    {
        $all = FilesAcl::allFolders($conn);
        $f = $all[$folderId] ?? null;
        if (!$f || $f['live'] || (FilesAcl::levelsIncludingDeleted($conn, $analystId)[$folderId] ?? 0) < FilesAcl::VIEW) {
            throw new InvalidArgumentException('Folder not found.');
        }
        // Only folders this person may see - a hidden subfolder's files are not listed.
        $sub = self::subtreeAtLeast($conn, $analystId, $folderId, FilesAcl::VIEW);
        $st = $conn->prepare("SELECT folder_id, name, size_bytes FROM files_items WHERE folder_id IN (" . implode(',', array_fill(0, count($sub), '?')) . ")");
        $st->execute($sub);
        $base = FilesAcl::pathIncludingDeleted($conn, $folderId);
        $files = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $p = FilesAcl::pathIncludingDeleted($conn, (int)$r['folder_id']);
            $rel = ltrim(substr($p, strlen($base)), ' /');
            $files[] = ['name' => $r['name'], 'path' => $rel, 'size' => (int)$r['size_bytes']];
        }
        usort($files, fn($a, $b) => strnatcasecmp($a['path'] . '/' . $a['name'], $b['path'] . '/' . $b['name']));
        return ['name' => $f['name'], 'files' => $files];
    }

    /**
     * The entry for (type, id) if it is one and this person may at least see it - else
     * "not found". Returns [entry, level].
     */
    private static function entryFor(PDO $conn, ?int $analystId, string $type, int $id): array
    {
        if (!in_array($type, ['folder', 'item'], true)) throw new InvalidArgumentException('Unknown type.');
        $notFound = $type === 'folder' ? 'Folder not found.' : 'File not found.';
        $all = FilesAcl::allFolders($conn);
        if ($type === 'folder') {
            $f = $all[$id] ?? null;
            if (!$f || !$f['deleted']) throw new InvalidArgumentException($notFound);
            $e = ['type' => 'folder', 'id' => $id, 'name' => $f['name'], 'parent_id' => $f['parent_id']];
        } else {
            $st = $conn->prepare("SELECT id, folder_id, name, deleted_datetime FROM files_items WHERE id = ?");
            $st->execute([$id]);
            $i = $st->fetch(PDO::FETCH_ASSOC);
            if (!$i || ($i['deleted_datetime'] === null && !empty($all[(int)$i['folder_id']]['live']))) throw new InvalidArgumentException($notFound);
            $e = ['type' => 'item', 'id' => $id, 'name' => $i['name'], 'parent_id' => (int)$i['folder_id'], 'self_deleted' => $i['deleted_datetime'] !== null];
        }
        $lvl = $analystId !== null ? (FilesAcl::levelsIncludingDeleted($conn, $analystId)[self::aclFolder($e)] ?? 0) : FilesAcl::FULL;
        if ($lvl < FilesAcl::VIEW) throw new InvalidArgumentException($notFound);
        return [$e, $lvl];
    }

    /** The topmost deleted folder above (and including) $folderId - the bin entry holding it. */
    private static function binEntryAbove(PDO $conn, int $folderId): ?array
    {
        $all = FilesAcl::allFolders($conn);
        $top = null;
        $f = $all[$folderId] ?? null;
        $guard = 0;
        while ($f && $guard++ < 200) {
            if ($f['deleted']) $top = $f;
            $f = $f['parent_id'] !== null ? ($all[$f['parent_id']] ?? null) : null;
        }
        return $top ? ['type' => 'folder', 'id' => $top['id'], 'name' => $top['name']] : null;
    }

    /**
     * The "do that folder first" refusal. The folder is only named, and offered, when
     * this person may see it - otherwise the bin would reveal a hidden folder's name.
     *
     * @param int $below     a folder at or under the bin entry that blocks
     * @param string $msg    English with {name} and {folder}
     */
    private static function blocked(PDO $conn, ?int $analystId, int $below, string $msg, string $name): FilesRecycleBlocked
    {
        $first = self::binEntryAbove($conn, $below);
        $visible = $first && ($analystId === null || (FilesAcl::levelsIncludingDeleted($conn, $analystId)[$first['id']] ?? 0) >= FilesAcl::VIEW);
        $text = strtr($msg, ['{name}' => $name, '{folder}' => $visible ? $first['name'] : '…']);
        return new FilesRecycleBlocked($text, $visible ? $first : []);
    }

    /**
     * Puts a file or folder back where it was. A clash with a live name restores it as
     * "Name (2)". Refused (FilesRecycleBlocked) while the folder it was in is in the bin.
     *
     * @return array ['name' => the name it came back under, 'renamed' => bool, 'folder_id' => where it is now (a file's folder, or the folder itself)]
     */
    public static function restore(PDO $conn, int $analystId, string $type, int $id): array
    {
        if ($analystId < 1) throw new InvalidArgumentException('Not signed in.');
        // Their folder, inside a deleted folder they cannot see: it comes back on its own.
        if ($type === 'folder') {
            $all = FilesAcl::allFolders($conn);
            $f = $all[$id] ?? null;
            if ($f && !$f['live']) {
                $top = self::binEntryAbove($conn, $id);
                $lv = FilesAcl::levelsIncludingDeleted($conn, $analystId);
                if ($top && $top['id'] !== $id && ($lv[$top['id']] ?? 0) < FilesAcl::VIEW && ($lv[$id] ?? 0) >= FilesAcl::VIEW) {
                    return self::rescue($conn, $analystId, $id, $top);
                }
            }
        }
        [$e, $lvl] = self::entryFor($conn, $analystId, $type, $id);
        $all = FilesAcl::allFolders($conn);
        $parent = $e['parent_id'];

        if ($parent !== null && empty($all[$parent]['live'])) {
            throw self::blocked($conn, $analystId, $parent, '"{name}" was in a folder that is in the recycle bin too ("{folder}"). Restore that folder first.', $e['name']);
        }
        if ($type === 'item' && empty($e['self_deleted'])) {
            // A live file inside a deleted folder: it comes back with its folder.
            throw self::blocked($conn, $analystId, (int)$parent, '"{name}" is inside a deleted folder ("{folder}"). Restore that folder.', $e['name']);
        }
        if ($lvl < FilesAcl::MODIFY) {
            filesAudit($conn, $analystId, 'denied', ['folder_id' => null, 'path' => self::entryPath($conn, $e)],
                       ['wanted' => 'modify', 'held' => FilesAcl::levelKey($lvl), 'doing' => 'restore']);
            throw new InvalidArgumentException('You do not have permission to do that here.');
        }

        // filesFreeName() counts only names the caller can see (names.php reads the caller from here).
        $was = $GLOBALS['analystId'] ?? null;
        $GLOBALS['analystId'] = $analystId;
        try {
            $name = filesFreeName($conn, $parent, $e['name']);
        } finally {
            $GLOBALS['analystId'] = $was;
        }
        $table = $type === 'folder' ? 'files_folders' : 'files_items';
        $own = !$conn->inTransaction();
        if ($own) $conn->beginTransaction();
        try {
            // Still deleted? A purge may have got there first.
            $st = $conn->prepare("UPDATE $table SET name = ?, deleted_by = NULL, deleted_datetime = NULL, updated_by = ?, updated_datetime = UTC_TIMESTAMP()
                                   WHERE id = ? AND deleted_datetime IS NOT NULL");
            $st->execute([$name, $analystId, $id]);
            if ($st->rowCount() === 0) throw new InvalidArgumentException($type === 'folder' ? 'Folder not found.' : 'File not found.');

            if ($type === 'folder') {
                // Things deleted on their own inside it become entries again, each with its
                // own old date - which the retention job would read as "long overdue" and
                // delete within the hour. Their time in the bin starts again from now.
                $sub = array_values(array_diff(self::subtree($conn, $id), [$id]));
                $conn->prepare("UPDATE files_items SET deleted_datetime = UTC_TIMESTAMP() WHERE deleted_datetime IS NOT NULL AND folder_id IN ("
                               . implode(',', array_fill(0, count($sub) + 1, '?')) . ")")->execute(array_merge([$id], $sub));
                if ($sub) {
                    $conn->prepare("UPDATE files_folders SET deleted_datetime = UTC_TIMESTAMP() WHERE deleted_datetime IS NOT NULL AND id IN ("
                                   . implode(',', array_fill(0, count($sub), '?')) . ")")->execute($sub);
                }
            }
            if ($own) $conn->commit();
        } catch (Throwable $ex) {
            if ($own && $conn->inTransaction()) $conn->rollBack();
            throw $ex;
        }
        FilesAcl::reset();

        $t = $type === 'folder' ? ['folder_id' => $id] : ['folder_id' => $parent, 'item_id' => $id, 'item_name' => $name];
        filesAudit($conn, $analystId, 'restore', $t, array_filter(['type' => $type, 'restored_as' => $name !== $e['name'] ? $name : null]));
        return ['name' => $name, 'renamed' => $name !== $e['name'], 'folder_id' => $type === 'folder' ? $id : $parent];
    }

    /** "Finance / Old / report.pdf" for an entry, for audit rows. */
    private static function entryPath(PDO $conn, array $e): string
    {
        if ($e['type'] === 'folder') return FilesAcl::pathIncludingDeleted($conn, $e['id']);
        return FilesAcl::pathIncludingDeleted($conn, (int)$e['parent_id']) . ' / ' . $e['name'];
    }

    /**
     * Deletes one bin entry for good: its rows, every shortcut to it, and stored bytes
     * nothing else uses.
     *
     * @param int      $analystId     who asks - a person; the retention job uses purgeAs(null)
     * @param int|null $bytesDeadline unix time after which stored bytes are left to the job
     *                                (default: PURGE_BYTES_SECONDS from now)
     * @return array ['files' => files removed, 'bytes' => bytes freed or queued, 'pending' => keys left for the job]
     */
    public static function purge(PDO $conn, int $analystId, string $type, int $id, ?int $bytesDeadline = null): array
    {
        if ($analystId < 1) throw new InvalidArgumentException('Not signed in.');
        return self::purgeAs($conn, $analystId, $type, $id, $bytesDeadline);
    }

    /**
     * purge() for a person, or for the retention job ($analystId null: no permission
     * needed, and only the stay in the bin it listed - $expectDeletedAt).
     *
     * @param ?string $expectDeletedAt  the deleted_datetime the job saw; restored and deleted
     *                                  again since means a new stay, not the one that was due
     */
    private static function purgeAs(PDO $conn, ?int $analystId, string $type, int $id, ?int $bytesDeadline, ?string $expectDeletedAt = null): array
    {
        $bytesDeadline ??= time() + self::PURGE_BYTES_SECONDS;
        [$e, $lvl] = self::entryFor($conn, $analystId, $type, $id);
        $all = FilesAcl::allFolders($conn);
        $isEntry = $type === 'folder'
            ? ($e['parent_id'] === null || !empty($all[$e['parent_id']]['live']))
            : (!empty($e['self_deleted']) && !empty($all[(int)$e['parent_id']]['live']));
        if (!$isEntry) {
            throw self::blocked($conn, $analystId, (int)$e['parent_id'],
                                '"{name}" is inside a deleted folder ("{folder}"). Delete that folder permanently instead.', $e['name']);
        }
        if ($analystId !== null && $lvl < FilesAcl::MODIFY) {
            filesAudit($conn, $analystId, 'denied', ['path' => self::entryPath($conn, $e)],
                       ['wanted' => 'modify', 'held' => FilesAcl::levelKey($lvl), 'doing' => 'purge']);
            throw new InvalidArgumentException('You do not have permission to do that here.');
        }
        if (!self::canPurge($conn, $analystId, $e)) {
            // A subfolder they cannot change (or see). Restoring is still theirs to do;
            // deleting for good is for someone who can change all of it, or the retention job.
            filesAudit($conn, $analystId, 'denied', ['path' => self::entryPath($conn, $e)],
                       ['wanted' => 'modify', 'held' => 'not_on_every_subfolder', 'doing' => 'purge']);
            throw new InvalidArgumentException('This folder holds folders you cannot change, so you cannot delete it permanently. You can restore it, or ask someone with Modify on all of it.');
        }

        $path = self::entryPath($conn, $e);
        $bytes = 0;
        $own = !$conn->inTransaction();
        if ($own) $conn->beginTransaction();
        try {
            // Still in the bin? A restore may have landed since we looked. Locked until commit.
            $st = $conn->prepare("SELECT deleted_datetime FROM " . ($type === 'folder' ? 'files_folders' : 'files_items') . " WHERE id = ? FOR UPDATE");
            $st->execute([$id]);
            $still = $st->fetchColumn();
            if ($still === false || ($still === null && ($type === 'folder' || !empty($e['self_deleted'])))) {
                throw new InvalidArgumentException($type === 'folder' ? 'Folder not found.' : 'File not found.');
            }
            if ($expectDeletedAt !== null && $still !== $expectDeletedAt) {
                throw new InvalidArgumentException($type === 'folder' ? 'Folder not found.' : 'File not found.');
            }
            // What goes is read NOW, with the row locked - not before it. Read earlier, a
            // folder brought out of it meanwhile (rescue(), which locks this row too) or a
            // file restored and deleted again would be destroyed on an out-of-date list.
            FilesAcl::reset();
            $all = FilesAcl::allFolders($conn);
            $folders = $type === 'folder' ? self::subtree($conn, $id) : [];
            if ($folders) {
                $in = implode(',', array_fill(0, count($folders), '?'));
                $st = $conn->prepare("SELECT id FROM files_items WHERE folder_id IN ($in)");
                $st->execute($folders);
                $items = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
            } else {
                $items = [$id];
            }
            $keys = [];
            if ($items) {
                $in = implode(',', array_fill(0, count($items), '?'));
                $st = $conn->prepare("SELECT storage_path, MAX(size_bytes) FROM files_versions WHERE item_id IN ($in) GROUP BY storage_path");
                $st->execute($items);
                $keys = $st->fetchAll(PDO::FETCH_KEY_PAIR);
            }
            // Record the stored files first, in the same transaction as the rows: whatever
            // happens to this request afterwards, the job knows what is left to delete.
            $journaled = self::queueKeys($conn, array_map('strval', array_keys($keys)));
            // Children first: an install grown from an older schema may lack the cascades.
            if ($items) {
                $in = implode(',', array_fill(0, count($items), '?'));
                self::unlinkDocuments($conn, $items);
                $conn->prepare("DELETE FROM files_shortcuts WHERE target_type = 'item' AND target_id IN ($in)")->execute($items);
                $conn->prepare("DELETE FROM files_uploads WHERE item_id IN ($in)")->execute($items);
                $conn->prepare("DELETE FROM files_versions WHERE item_id IN ($in)")->execute($items);
                $conn->prepare("DELETE FROM files_items WHERE id IN ($in)")->execute($items);
            }
            if ($folders) {
                $in = implode(',', array_fill(0, count($folders), '?'));
                $conn->prepare("DELETE FROM files_shortcuts WHERE (target_type = 'folder' AND target_id IN ($in)) OR folder_id IN ($in)")
                     ->execute(array_merge($folders, $folders));
                $conn->prepare("DELETE FROM files_uploads WHERE folder_id IN ($in)")->execute($folders);
                $conn->prepare("DELETE FROM files_permissions WHERE folder_id IN ($in)")->execute($folders);
                // Deepest first, so a parent is never deleted before its children.
                $depth = function (int $fid) use ($all): int {
                    $d = 0; $f = $all[$fid] ?? null;
                    while ($f && $f['parent_id'] !== null && $d < 200) { $d++; $f = $all[$f['parent_id']] ?? null; }
                    return $d;
                };
                usort($folders, fn($a, $b) => $depth($b) <=> $depth($a));
                $del = $conn->prepare("DELETE FROM files_folders WHERE id = ?");
                foreach ($folders as $fid) $del->execute([$fid]);
            }
            if ($own) $conn->commit();
        } catch (Throwable $ex) {
            if ($own && $conn->inTransaction()) $conn->rollBack();
            throw $ex;
        }
        FilesAcl::reset();

        // Bytes, after the rows are gone: only keys nothing else still points at.
        // One key's failure must not skip the others or the audit row: the rows are gone.
        $pending = 0;
        $down = false;
        $inUse = $conn->prepare("SELECT 1 FROM files_versions WHERE storage_path = ? LIMIT 1");
        foreach ($keys as $key => $size) {
            $key = (string)$key;
            if ($journaled && ($down || time() >= $bytesDeadline)) {
                // Left recorded for the job - storage is down, or this request is out of time.
                $inUse->execute([$key]);
                if ($inUse->fetchColumn()) { self::unqueueKey($conn, $key); continue; }   // a copy still uses it
                $bytes += (int)$size;
                $pending++;
                continue;
            }
            $res = self::purgeBytes($conn, $key);
            if ($res === 'in_use') continue;   // a copy or another version still uses it
            $bytes += (int)$size;
            if ($res !== 'deleted') {
                $pending++;
                if ($res === 'unavailable') $down = true;   // don't wait on it again for every other file
            }
        }

        $t = $type === 'folder' ? ['path' => $path] : ['item_id' => $id, 'path' => $path];
        filesAudit($conn, $analystId ?? 0, 'purge', $t, array_filter([
            'type' => $type, 'files' => count($items), 'bytes' => $bytes,
            'by' => $analystId !== null ? null : 'retention', 'bytes_pending' => $pending ?: null,
        ], fn($v) => $v !== null));
        return ['files' => count($items), 'bytes' => $bytes, 'pending' => $pending];
    }

    /**
     * Documents that link to these files (documents.kind = 'files', upstream GH #303) keep
     * their row, so the record still shows something was there, but point at nothing.
     */
    private static function unlinkDocuments(PDO $conn, array $items): void
    {
        require_once __DIR__ . '/documents.php';
        if (!$items || !DocumentsService::filesLinkReady($conn)) return;
        $in = implode(',', array_fill(0, count($items), '?'));
        $conn->prepare("UPDATE documents SET files_item_id = NULL WHERE files_item_id IN ($in)")->execute($items);
    }

    /**
     * Records keys in files_purge_pending (nothing deleted yet). False when the table is
     * missing (before Database Verification): then nothing can be recorded, and the purge
     * deletes what it can at once, as before.
     *
     * @param string[] $keys
     */
    private static function queueKeys(PDO $conn, array $keys): bool
    {
        try {
            $ins = $conn->prepare("INSERT IGNORE INTO files_purge_pending (storage_path, attempts, created_datetime, updated_datetime)
                                   VALUES (?, 0, UTC_TIMESTAMP(), UTC_TIMESTAMP())");
            foreach ($keys as $k) if (filesStorageKeyIsValid($k)) $ins->execute([$k]);
            return true;
        } catch (PDOException $e) {
            if (!self::missingTable($e)) throw $e;
            return false;
        }
    }

    /** Forgets a recorded key (its bytes are gone, or a file still uses them). */
    private static function unqueueKey(PDO $conn, string $key): void
    {
        try {
            $conn->prepare("DELETE FROM files_purge_pending WHERE storage_path = ?")->execute([$key]);
        } catch (PDOException $e) {
            if (!self::missingTable($e)) throw $e;
        }
    }

    /**
     * Deletes one stored object if nothing uses it (filesPurgeBytesIfUnused); a failure is
     * queued in files_purge_pending for the job, a success or "in use" clears the queue.
     *
     * @return string as filesPurgeBytesIfUnused(): in_use | deleted | unavailable | failed
     */
    private static function purgeBytes(PDO $conn, string $key): string
    {
        try {
            $res = filesPurgeBytesIfUnused($conn, $key);
        } catch (StorageUnavailable $e) {
            error_log("files: could not delete stored bytes {$key}: " . $e->getMessage());
            $res = 'unavailable';
        } catch (Throwable $e) {
            error_log("files: could not delete stored bytes {$key}: " . $e->getMessage());
            $res = 'failed';
        }
        try {
            if ($res === 'deleted' || $res === 'in_use') {
                $conn->prepare("DELETE FROM files_purge_pending WHERE storage_path = ?")->execute([$key]);
            } else {
                $conn->prepare("INSERT INTO files_purge_pending (storage_path, attempts, last_error, created_datetime, updated_datetime)
                                VALUES (?, 0, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())
                                ON DUPLICATE KEY UPDATE attempts = attempts + 1, last_error = VALUES(last_error), updated_datetime = UTC_TIMESTAMP()")
                     ->execute([$key, $res === 'unavailable' ? 'Storage could not be reached (see the server log).' : 'Storage refused the delete (see the server log).']);
            }
        } catch (PDOException $e) {
            // Before Database Verification there is no queue: nothing was waiting, and a
            // failure can only be logged. Say so loudly - those bytes need removing by hand.
            if (!self::missingTable($e)) throw $e;
            if ($res === 'unavailable' || $res === 'failed') error_log("files: stored bytes {$key} were NOT deleted and cannot be queued: run Database Verification (files_purge_pending is missing)");
        }
        return $res;
    }

    /**
     * Purges what this person may delete for good, until $deadlineUnix. Entries they can
     * see but not change all of stay, and are counted.
     *
     * @return array ['entries' => purged, 'files' => n, 'bytes' => n, 'skipped' => n they may
     *               not purge, 'failed' => n that failed, 'more' => bool (the deadline stopped it)]
     */
    public static function emptyBin(PDO $conn, int $analystId, int $deadlineUnix): array
    {
        $r = ['entries' => 0, 'files' => 0, 'bytes' => 0, 'skipped' => 0, 'failed' => 0, 'more' => false];
        $tried = 0;
        foreach (self::entries($conn, $analystId) as $e) {
            if (!$e['can_purge']) { $r['skipped']++; continue; }
            // At least one entry per call, so a slow listing can never stop Empty making progress.
            if ($tried > 0 && time() >= $deadlineUnix) { $r['more'] = true; break; }
            $tried++;
            try {
                $p = self::purge($conn, $analystId, $e['type'], $e['id'], $deadlineUnix);
            } catch (Throwable $ex) {
                // Gone meanwhile, restored meanwhile, or a real failure: count it, carry on.
                if (!$ex instanceof InvalidArgumentException) error_log('files: empty recycle bin: ' . $ex->getMessage());
                $r['failed']++;
                continue;
            }
            $r['entries']++;
            $r['files'] += $p['files'];
            $r['bytes'] += $p['bytes'];
        }
        return $r;
    }

    /**
     * The retention job's work: purges entries in the bin longer than the retention
     * period (counted from clockStart()), until $deadlineUnix. Nothing when the setting
     * was never saved or is 0. Never one heldBack(): those are counted in 'held' and
     * listed in System -> Maintenance -> "Deleted folders kept".
     *
     * @return array ['entries' => n, 'files' => n, 'bytes' => n, 'failed' => n, 'held' => n, 'done' => bool]
     */
    public static function purgeExpired(PDO $conn, int $deadlineUnix): array
    {
        $r = ['entries' => 0, 'files' => 0, 'bytes' => 0, 'failed' => 0, 'held' => 0, 'done' => true];
        $days = self::retentionDays($conn);
        if (!$days) return $r;
        $since = self::clockSince($conn);
        $cutoff = gmdate('Y-m-d H:i:s', time() - $days * 86400);
        $due = array_filter(self::allEntries($conn), fn($e) => self::clockStart($e, $since) < $cutoff);
        foreach ($due as $e) {
            if (time() >= $deadlineUnix) { $r['done'] = false; break; }
            if (self::heldBack($conn, $e)) { $r['held']++; continue; }
            try {
                $p = self::purgeAs($conn, null, $e['type'], $e['id'], $deadlineUnix, $e['deleted_at']);
            } catch (InvalidArgumentException $ex) {
                continue;   // restored or purged since the list was read
            } catch (Throwable $ex) {
                // One stuck entry must not hold up every other one, run after run.
                error_log("files: recycle bin retention could not purge {$e['type']} {$e['id']}: " . $ex->getMessage());
                $r['failed']++;
                continue;
            }
            $r['entries']++;
            $r['files'] += $p['files'];
            $r['bytes'] += $p['bytes'];
        }
        return $r;
    }

    /**
     * Tries again to delete stored bytes whose first attempt found storage unreachable.
     * A key that a file uses again (it can't, but be sure) is only forgotten.
     *
     * @return array ['deleted' => n, 'still_pending' => n]
     */
    public static function retryPendingBytes(PDO $conn, int $deadlineUnix, int $limit = 500): array
    {
        $r = ['deleted' => 0, 'still_pending' => 0];
        try {
            $rows = $conn->query("SELECT storage_path FROM files_purge_pending ORDER BY updated_datetime LIMIT " . (int)$limit)->fetchAll(PDO::FETCH_COLUMN);
        } catch (PDOException $e) {
            if (self::missingTable($e)) return $r;   // no queue yet means nothing is queued
            throw $e;
        }
        foreach ($rows as $key) {
            if (time() >= $deadlineUnix) break;
            $res = self::purgeBytes($conn, (string)$key);
            if ($res === 'in_use') continue;   // a file uses it again: only forgotten
            if ($res === 'deleted') {
                $r['deleted']++;
                continue;
            }
            $r['still_pending']++;
            if ($res === 'unavailable') break;   // storage is still down: the next run tries again
            // Any other failure is this key's alone (attempts and last_error are kept): try the rest.
        }
        return $r;
    }
}
