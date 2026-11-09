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
 * BYTES: a copied file SHARES its source's storage_path, so a .bin is only deleted
 * once no files_versions row points at it. It is deleted from the active location
 * and, while a storage move runs, from the previous one too. If storage cannot be
 * reached the key waits in files_purge_pending and the job tries again - the rows
 * are already gone, so the person purging never sees a storage error.
 *
 * RETENTION: files_recycle_bin_days, 0 = keep for ever. Until someone saves the
 * Recycle bin settings tab the setting is absent and nothing is purged, so an
 * upgrade never deletes anything on its own.
 *
 * Throws InvalidArgumentException with a message for the person (FilesRecycleBlocked
 * when a parent folder has to be restored first).
 */

require_once __DIR__ . '/../files/acl.php';
require_once __DIR__ . '/../files/audit.php';
require_once __DIR__ . '/../files/storage.php';
require_once __DIR__ . '/../files/names.php';

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
        $due = 0;
        if ($consider) {
            $cutoff = gmdate('Y-m-d H:i:s', time() - $consider * 86400);
            $due = count(array_filter($entries, fn($e) => $e['deleted_at'] < $cutoff));
        }
        return [
            'days'          => $days ?? self::DEFAULT_DAYS,
            'configured'    => $days !== null,
            'entries'       => count($entries),
            'due'           => $due,
            'bin_files'     => $u['files'],
            'bin_bytes'     => $u['bytes'],
            'pending_bytes' => $pending,
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
     * must not reach them through the bin. Analyst 0 (the retention job) gets all.
     */
    private static function subtreeAtLeast(PDO $conn, int $analystId, int $folderId, int $need): array
    {
        $sub = self::subtree($conn, $folderId);
        if ($analystId === 0) return $sub;
        $levels = FilesAcl::levelsIncludingDeleted($conn, $analystId);
        return array_values(array_filter($sub, fn($fid) => ($levels[$fid] ?? 0) >= $need));
    }

    /**
     * May this person delete the whole entry for good? Modify on the folder (or a file's
     * folder) AND on every folder under a deleted folder: a purge cannot be undone, so it
     * must never destroy something they could not even see.
     */
    private static function canPurge(PDO $conn, int $analystId, array $e): bool
    {
        if ($analystId === 0) return true;
        $levels = FilesAcl::levelsIncludingDeleted($conn, $analystId);
        if (($levels[self::aclFolder($e)] ?? 0) < FilesAcl::MODIFY) return false;
        if ($e['type'] !== 'folder') return true;
        foreach (self::subtree($conn, $e['id']) as $fid) {
            if (($levels[$fid] ?? 0) < FilesAcl::MODIFY) return false;
        }
        return true;
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
        $mine = array_values(array_filter(self::allEntries($conn), fn($e) => ($levels[self::aclFolder($e)] ?? 0) >= FilesAcl::VIEW));
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
            $out[] = [
                'type'            => $e['type'],
                'id'              => $e['id'],
                'name'            => $e['name'],
                'parent_id'       => $e['parent_id'],
                'location'        => $e['parent_id'] !== null ? FilesAcl::path($conn, $e['parent_id']) : '',
                'deleted_at'      => $e['deleted_at'],
                'deleted_by_name' => $e['deleted_by'] !== null ? ($names[$e['deleted_by']] ?? null) : null,
                'size'            => $isFolder ? (int)$folderStats[$e['id']][1] : (int)$e['size'],
                'files'           => $isFolder ? (int)$folderStats[$e['id']][0] : null,
                'purge_at'        => $days ? gmdate('Y-m-d H:i:s', strtotime($e['deleted_at'] . ' UTC') + $days * 86400) : null,
                'can_modify'      => ($levels[self::aclFolder($e)] ?? 0) >= FilesAcl::MODIFY,
                'can_purge'       => self::canPurge($conn, $analystId, $e),
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
    private static function entryFor(PDO $conn, int $analystId, string $type, int $id): array
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
        $lvl = $analystId > 0 ? (FilesAcl::levelsIncludingDeleted($conn, $analystId)[self::aclFolder($e)] ?? 0) : FilesAcl::FULL;
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
    private static function blocked(PDO $conn, int $analystId, int $below, string $msg, string $name): FilesRecycleBlocked
    {
        $first = self::binEntryAbove($conn, $below);
        $visible = $first && ($analystId === 0 || (FilesAcl::levelsIncludingDeleted($conn, $analystId)[$first['id']] ?? 0) >= FilesAcl::VIEW);
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
     * @param int $analystId  who asks (0 = the retention job, which needs no permission)
     * @return array ['files' => files removed, 'bytes' => bytes freed or queued, 'pending' => keys left for the job]
     */
    public static function purge(PDO $conn, int $analystId, string $type, int $id): array
    {
        [$e, $lvl] = self::entryFor($conn, $analystId, $type, $id);
        $all = FilesAcl::allFolders($conn);
        $isEntry = $type === 'folder'
            ? ($e['parent_id'] === null || !empty($all[$e['parent_id']]['live']))
            : (!empty($e['self_deleted']) && !empty($all[(int)$e['parent_id']]['live']));
        if (!$isEntry) {
            throw self::blocked($conn, $analystId, (int)$e['parent_id'],
                                '"{name}" is inside a deleted folder ("{folder}"). Delete that folder permanently instead.', $e['name']);
        }
        if ($analystId > 0 && $lvl < FilesAcl::MODIFY) {
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
        $bytes = 0;
        if ($items) {
            $in = implode(',', array_fill(0, count($items), '?'));
            $st = $conn->prepare("SELECT storage_path, MAX(size_bytes) FROM files_versions WHERE item_id IN ($in) GROUP BY storage_path");
            $st->execute($items);
            $keys = $st->fetchAll(PDO::FETCH_KEY_PAIR);
        }

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
            // Children first: an install grown from an older schema may lack the cascades.
            if ($items) {
                $in = implode(',', array_fill(0, count($items), '?'));
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
        $check = $conn->prepare("SELECT 1 FROM files_versions WHERE storage_path = ? LIMIT 1");
        foreach ($keys as $key => $size) {
            $check->execute([$key]);
            if ($check->fetchColumn()) continue;   // a copy elsewhere still uses it
            $bytes += (int)$size;
            try {
                if (self::deleteBytes($conn, (string)$key) !== 'ok') $pending++;
            } catch (Throwable $ex) {
                error_log("files: purge could not finish with stored bytes {$key}: " . $ex->getMessage());
                $pending++;
            }
        }

        $t = $type === 'folder' ? ['path' => $path] : ['item_id' => $id, 'path' => $path];
        filesAudit($conn, $analystId, 'purge', $t, array_filter([
            'type' => $type, 'files' => count($items), 'bytes' => $bytes,
            'by' => $analystId > 0 ? null : 'retention', 'bytes_pending' => $pending ?: null,
        ], fn($v) => $v !== null));
        return ['files' => count($items), 'bytes' => $bytes, 'pending' => $pending];
    }

    /**
     * Deletes one stored object everywhere it may be: the active location and, during a
     * move, the previous one. During a move its move row goes too, so the move does not
     * copy it (storageMigrateBatch() removes a copy that was already in flight).
     *
     * @return string 'ok'; 'unavailable' (a store could not be reached - the key waits in
     *                files_purge_pending for the job); 'error' (any other failure, also queued)
     */
    private static function deleteBytes(PDO $conn, string $key): string
    {
        if (!filesStorageKeyIsValid($key)) return 'ok';   // never a path we wrote: nothing to do
        $error = null;
        $result = 'ok';
        try {
            $stores = [filesActiveStore($conn)];
            if (($prev = filesPreviousStore($conn)) !== null) {
                // A move is running, so its table exists.
                $conn->prepare("DELETE FROM storage_migration_items WHERE store = 'files' AND object_key = ?")->execute([$key]);
                $stores[] = $prev;
            }
            foreach ($stores as $s) {
                try {
                    $s->delete($key);
                } catch (StorageNotFound $e) {
                    // already gone
                }
            }
        } catch (StorageUnavailable $e) {
            $error = $e->getMessage();
            $result = 'unavailable';
        } catch (Throwable $e) {
            $error = $e->getMessage();
            $result = 'error';
        }
        try {
            if ($error === null) {
                $conn->prepare("DELETE FROM files_purge_pending WHERE storage_path = ?")->execute([$key]);
                return 'ok';
            }
            error_log("files: could not delete stored bytes {$key}: {$error}");
            $conn->prepare("INSERT INTO files_purge_pending (storage_path, attempts, last_error, created_datetime, updated_datetime)
                            VALUES (?, 0, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())
                            ON DUPLICATE KEY UPDATE attempts = attempts + 1, last_error = VALUES(last_error), updated_datetime = UTC_TIMESTAMP()")
                 ->execute([$key, mb_substr($error, 0, 500)]);
        } catch (PDOException $e) {
            // Before Database Verification there is no queue: nothing was waiting, and a
            // failure can only be logged. Say so loudly - those bytes need removing by hand.
            if (!self::missingTable($e)) throw $e;
            if ($error !== null) error_log("files: stored bytes {$key} were NOT deleted and cannot be queued: run Database Verification (files_purge_pending is missing)");
        }
        return $result;
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
        foreach (self::entries($conn, $analystId) as $e) {
            if (!$e['can_purge']) { $r['skipped']++; continue; }
            if (time() >= $deadlineUnix) { $r['more'] = true; break; }
            try {
                $p = self::purge($conn, $analystId, $e['type'], $e['id']);
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
     * The retention job's work: purges entries deleted more than the retention period
     * ago, until $deadlineUnix. Nothing when the setting was never saved or is 0.
     *
     * @return array ['entries' => n, 'files' => n, 'bytes' => n, 'failed' => n, 'done' => bool]
     */
    public static function purgeExpired(PDO $conn, int $deadlineUnix): array
    {
        $r = ['entries' => 0, 'files' => 0, 'bytes' => 0, 'failed' => 0, 'done' => true];
        $days = self::retentionDays($conn);
        if (!$days) return $r;
        $cutoff = gmdate('Y-m-d H:i:s', time() - $days * 86400);
        $due = array_filter(self::allEntries($conn), fn($e) => $e['deleted_at'] < $cutoff);
        foreach ($due as $e) {
            if (time() >= $deadlineUnix) { $r['done'] = false; break; }
            try {
                $p = self::purge($conn, 0, $e['type'], $e['id']);
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
        $check = $conn->prepare("SELECT 1 FROM files_versions WHERE storage_path = ? LIMIT 1");
        foreach ($rows as $key) {
            if (time() >= $deadlineUnix) break;
            $check->execute([$key]);
            if ($check->fetchColumn()) {
                $conn->prepare("DELETE FROM files_purge_pending WHERE storage_path = ?")->execute([$key]);
                continue;
            }
            $res = self::deleteBytes($conn, (string)$key);
            if ($res === 'ok') {
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
