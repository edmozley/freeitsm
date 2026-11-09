<?php
/**
 * Files — who may do what to a folder. The ONE place that answers it.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * THE MODEL (agreed with Ed, 2026-10-10)
 * ─────────────────────────────────────────────────────────────────────────────
 *   PESSIMISTIC. Nothing is visible to anybody until a row in files_permissions
 *   grants it. There is no "everyone" principal, no admin bypass and no Deny.
 *
 *   Levels are cumulative - each includes every level below it:
 *     1 View          see it, open it in the viewer
 *     2 Download      take a copy away
 *     3 Upload        add files and subfolders, upload a new version
 *     4 Modify        rename, move, delete
 *     5 Full control  change permissions, inheritance and the watermark
 *
 *   A folder with inherit_permissions = 1 also carries every entry of its
 *   parent (and so on up). Turning inheritance off stops that at this folder.
 *   A person's level on a folder is the HIGHEST of every entry that applies to
 *   them directly or through any team they are in.
 *
 *   Files have no permissions of their own - a file takes its folder's.
 *
 * ⚠️ Admins are NOT special here. Cap::FILES_FOLDERS (which admins hold, like
 * every capability) lets someone TAKE OWNERSHIP - add a Full control row for
 * themselves, audited - but until they do, they see no more than anyone else.
 * Never add an is_admin shortcut to this file.
 *
 * ⚠️ HIDDEN MEANS NOT FOUND. A folder you cannot View does not exist for you:
 * endpoints answer "not found", never "access denied", so its existence cannot
 * be probed one id at a time.
 *
 * Performance: the whole tree and the asker's grants are read once per request
 * (two queries) and resolved in PHP. Trees of tens of thousands of folders are
 * fine; if that ever stops being true, materialise levels per principal.
 */

final class FilesAcl
{
    const NONE     = 0;
    const VIEW     = 1;
    const DOWNLOAD = 2;
    const UPLOAD   = 3;
    const MODIFY   = 4;
    const FULL     = 5;

    /** @var array<int, array{id:int,parent_id:?int,name:string,inherit:int}>|null */
    private static $tree = null;
    /** @var array<int, array<int,int>> analystId => [folderId => level] */
    private static $levels = [];
    /** @var array|null every folder, deleted ones too (the recycle bin's view) */
    private static $all = null;
    /** @var array<int, array<int,int>> analystId => [folderId => level] over $all */
    private static $levelsAll = [];

    /** Forget everything cached - call after changing folders or grants. */
    public static function reset(): void
    {
        self::$tree = null;
        self::$levels = [];
        self::$all = null;
        self::$levelsAll = [];
    }

    /**
     * The short key for a permission level ('view', 'download', 'upload', 'modify', 'full'), or 'none'.
     *
     * @param int $level  a level 0-5 (FilesAcl::NONE to FilesAcl::FULL); anything else gives 'none'
     */
    public static function levelKey(int $level): string
    {
        switch ($level) {
            case self::VIEW:     return 'view';
            case self::DOWNLOAD: return 'download';
            case self::UPLOAD:   return 'upload';
            case self::MODIFY:   return 'modify';
            case self::FULL:     return 'full';
        }
        return 'none';
    }

    /** Every live (not deleted) folder, keyed by id. */
    public static function tree(PDO $conn): array
    {
        if (self::$tree === null) {
            $all = [];
            $rows = $conn->query("SELECT id, parent_id, name, inherit_permissions, watermark, deleted_datetime
                                    FROM files_folders")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $r) {
                $all[(int)$r['id']] = [
                    'id'        => (int)$r['id'],
                    'parent_id' => $r['parent_id'] !== null ? (int)$r['parent_id'] : null,
                    'name'      => $r['name'],
                    'inherit'   => (int)$r['inherit_permissions'],
                    'watermark' => $r['watermark'] !== null ? (int)$r['watermark'] : null,
                    'deleted'   => $r['deleted_datetime'] !== null,
                ];
            }
            // ⚠️ A folder is live only if it AND every ancestor are live. Deleting
            // a folder marks that one row; without this walk its children would
            // drop out of the tree as orphans and anyone with a grant directly on
            // one would find it floating at the top level.
            $live = [];
            $isLive = function (int $id) use (&$isLive, &$live, $all): bool {
                if (isset($live[$id])) return $live[$id];
                $live[$id] = false;   // cycle guard
                $f = $all[$id] ?? null;
                if (!$f || $f['deleted']) return false;
                return $live[$id] = ($f['parent_id'] === null) ? true : $isLive($f['parent_id']);
            };
            self::$tree = [];
            foreach ($all as $id => $f) {
                if ($isLive($id)) { unset($f['deleted']); self::$tree[$id] = $f; }
            }
        }
        return self::$tree;
    }

    /** The teams an analyst is in (active teams only). */
    public static function teamIds(PDO $conn, int $analystId): array
    {
        $st = $conn->prepare("SELECT at.team_id FROM analyst_teams at
                                JOIN teams t ON t.id = at.team_id
                               WHERE at.analyst_id = ? AND COALESCE(t.is_active, 1) = 1");
        $st->execute([$analystId]);
        return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * This analyst's level on every folder they can see at all: [folderId => level].
     * A folder missing from the result is invisible to them.
     */
    public static function levels(PDO $conn, int $analystId): array
    {
        if (isset(self::$levels[$analystId])) return self::$levels[$analystId];

        $tree  = self::tree($conn);
        $teams = self::teamIds($conn, $analystId);

        // Direct grants to me or my teams, best per folder.
        $sql = "SELECT folder_id, MAX(level) FROM files_permissions
                 WHERE (principal_type = 'analyst' AND principal_id = ?)";
        $args = [$analystId];
        if ($teams) {
            $sql .= " OR (principal_type = 'team' AND principal_id IN (" . implode(',', array_fill(0, count($teams), '?')) . "))";
            $args = array_merge($args, $teams);
        }
        $sql .= " GROUP BY folder_id";
        $st = $conn->prepare($sql);
        $st->execute($args);
        $own = [];
        foreach ($st->fetchAll(PDO::FETCH_NUM) as [$fid, $lvl]) $own[(int)$fid] = (int)$lvl;

        // Nothing granted anywhere: the common case for most people, and free.
        if (!$own) return self::$levels[$analystId] = [];

        return self::$levels[$analystId] = self::resolve($tree, $own);
    }

    /**
     * Every folder, deleted ones included, keyed by id - with 'deleted' (its own
     * row is deleted) and 'live' (it and every folder above it are live). ONLY for
     * the recycle bin: everything else must use tree(), where a deleted folder
     * does not exist.
     */
    public static function allFolders(PDO $conn): array
    {
        if (self::$all === null) {
            $tree = self::tree($conn);
            self::$all = [];
            $rows = $conn->query("SELECT id, parent_id, name, inherit_permissions, deleted_by, deleted_datetime FROM files_folders")
                         ->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $r) {
                $id = (int)$r['id'];
                self::$all[$id] = [
                    'id'         => $id,
                    'parent_id'  => $r['parent_id'] !== null ? (int)$r['parent_id'] : null,
                    'name'       => $r['name'],
                    'inherit'    => (int)$r['inherit_permissions'],
                    'deleted'    => $r['deleted_datetime'] !== null,
                    'deleted_at' => $r['deleted_datetime'],
                    'deleted_by' => $r['deleted_by'] !== null ? (int)$r['deleted_by'] : null,
                    'live'       => isset($tree[$id]),
                ];
            }
        }
        return self::$all;
    }

    /**
     * Like levels(), but over every folder INCLUDING deleted ones: what this
     * person could do to a folder as it was before it was deleted. Only the
     * recycle bin may use this - a deleted folder is otherwise "not found".
     */
    public static function levelsIncludingDeleted(PDO $conn, int $analystId): array
    {
        if (isset(self::$levelsAll[$analystId])) return self::$levelsAll[$analystId];
        $teams = self::teamIds($conn, $analystId);
        $sql = "SELECT folder_id, MAX(level) FROM files_permissions WHERE (principal_type = 'analyst' AND principal_id = ?)";
        $args = [$analystId];
        if ($teams) {
            $sql .= " OR (principal_type = 'team' AND principal_id IN (" . implode(',', array_fill(0, count($teams), '?')) . "))";
            $args = array_merge($args, $teams);
        }
        $st = $conn->prepare($sql . " GROUP BY folder_id");
        $st->execute($args);
        $own = [];
        foreach ($st->fetchAll(PDO::FETCH_NUM) as [$fid, $lvl]) $own[(int)$fid] = (int)$lvl;
        if (!$own) return self::$levelsAll[$analystId] = [];
        return self::$levelsAll[$analystId] = self::resolve(self::allFolders($conn), $own);
    }

    /**
     * "Finance / Invoices" for any folder, deleted or not - the recycle bin's
     * "original location". path() is the one for everything else.
     *
     * @param ?int $folderId  the files_folders id; null (the top level) gives ''
     */
    public static function pathIncludingDeleted(PDO $conn, ?int $folderId): string
    {
        if ($folderId === null) return '';
        $all = self::allFolders($conn);
        $parts = [];
        $guard = 0;
        $f = $all[$folderId] ?? null;
        while ($f && $guard++ < 200) {
            array_unshift($parts, $f['name']);
            $f = $f['parent_id'] !== null ? ($all[$f['parent_id']] ?? null) : null;
        }
        return implode(' / ', $parts);
    }

    /**
     * Each folder's level from the direct grants in $own, following inheritance up $tree.
     *
     * @param array $tree  folders keyed by id (needs parent_id and inherit)
     * @param array $own   [folderId => best level granted directly there]
     */
    private static function resolve(array $tree, array $own): array
    {
        $memo = [];
        $resolve = function (int $fid) use (&$resolve, &$memo, $tree, $own): int {
            if (isset($memo[$fid])) return $memo[$fid];
            $memo[$fid] = 0;   // cycle guard: a corrupt loop resolves to nothing
            $f = $tree[$fid] ?? null;
            if (!$f) return 0;
            $lvl = $own[$fid] ?? 0;
            if ($f['inherit'] && $f['parent_id'] !== null) {
                $lvl = max($lvl, $resolve($f['parent_id']));
            }
            return $memo[$fid] = $lvl;
        };

        $out = [];
        foreach ($tree as $fid => $_) {
            $l = $resolve($fid);
            if ($l > 0) $out[$fid] = $l;
        }
        return $out;
    }

    /**
     * The analyst's effective level on one folder - 0 when they have no grant there.
     *
     * @param int $folderId  the files_folders id; a deleted or unknown folder gives 0
     */
    public static function level(PDO $conn, int $analystId, int $folderId): int
    {
        return self::levels($conn, $analystId)[$folderId] ?? 0;
    }

    /**
     * True when the analyst's level on the folder is at least $need (levels are cumulative).
     *
     * @param int $folderId  the files_folders id; a deleted or unknown folder never passes
     * @param int $need      the lowest level that will do, one of FilesAcl::VIEW to FilesAcl::FULL
     */
    public static function can(PDO $conn, int $analystId, int $folderId, int $need): bool
    {
        return self::level($conn, $analystId, $folderId) >= $need;
    }

    /**
     * Every entry that applies to a folder, for the Permissions window: its own
     * rows, then (while inheriting) each ancestor's, marked with where they came
     * from. Names are resolved here so the window needs nothing else.
     *
     * @param int $folderId  the files_folders id; a deleted or unknown folder gives an empty list
     */
    public static function entries(PDO $conn, int $folderId): array
    {
        $tree = self::tree($conn);
        $chain = [];
        $f = $tree[$folderId] ?? null;
        $guard = 0;
        while ($f && $guard++ < 200) {
            $chain[] = $f;
            if (!$f['inherit'] || $f['parent_id'] === null) break;
            $f = $tree[$f['parent_id']] ?? null;
        }
        if (!$chain) return [];

        $ids = array_column($chain, 'id');
        $st = $conn->prepare(
            "SELECT p.folder_id, p.principal_type, p.principal_id, p.level,
                    CASE p.principal_type WHEN 'analyst' THEN a.full_name ELSE t.name END AS principal_name
               FROM files_permissions p
          LEFT JOIN analysts a ON p.principal_type = 'analyst' AND a.id = p.principal_id
          LEFT JOIN teams t    ON p.principal_type = 'team'    AND t.id = p.principal_id
              WHERE p.folder_id IN (" . implode(',', array_fill(0, count($ids), '?')) . ")
           ORDER BY p.principal_type DESC, principal_name"
        );
        $st->execute($ids);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);

        $out = [];
        foreach ($chain as $depth => $c) {
            foreach ($rows as $r) {
                if ((int)$r['folder_id'] !== $c['id']) continue;
                $out[] = [
                    'principal_type' => $r['principal_type'],
                    'principal_id'   => (int)$r['principal_id'],
                    'name'           => $r['principal_name'] ?? ('#' . $r['principal_id']),
                    'level'          => (int)$r['level'],
                    'inherited'      => $depth > 0,
                    'from_id'        => $c['id'],
                    'from_name'      => $depth > 0 ? self::path($conn, $c['id']) : null,
                ];
            }
        }
        return $out;
    }

    /**
     * Is the viewer watermark on for files in this folder? The nearest folder
     * that says on or off decides; NULL means "as the parent", and nothing set
     * anywhere means off. Follows the folder tree, NOT permission inheritance -
     * a folder that stops inheriting permissions still sits inside its parent.
     *
     * @param int $folderId  the files_folders id of the folder holding the file; unknown gives false
     */
    public static function watermark(PDO $conn, int $folderId): bool
    {
        $tree = self::tree($conn);
        $f = $tree[$folderId] ?? null;
        $guard = 0;
        while ($f && $guard++ < 200) {
            if ($f['watermark'] !== null) return $f['watermark'] === 1;
            $f = $f['parent_id'] !== null ? ($tree[$f['parent_id']] ?? null) : null;
        }
        return false;
    }

    /**
     * "Finance / Invoices / 2026" - for audit rows, tooltips and the address bar.
     *
     * @param ?int $folderId  the files_folders id; null (the top level) or a deleted folder gives ''
     */
    public static function path(PDO $conn, ?int $folderId): string
    {
        if ($folderId === null) return '';
        $tree = self::tree($conn);
        $parts = [];
        $guard = 0;
        $f = $tree[$folderId] ?? null;
        while ($f && $guard++ < 200) {
            array_unshift($parts, $f['name']);
            $f = $f['parent_id'] !== null ? ($tree[$f['parent_id']] ?? null) : null;
        }
        return implode(' / ', $parts);
    }

    /**
     * Ids of a folder and everything beneath it (live folders only).
     *
     * @param int $folderId  the files_folders id at the top; it is always in the result, even if unknown
     */
    public static function descendants(PDO $conn, int $folderId): array
    {
        $tree = self::tree($conn);
        $kids = [];
        foreach ($tree as $f) {
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
}
