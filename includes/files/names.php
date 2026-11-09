<?php
/**
 * Files - the shared lookups behind names and shortcuts: a live file, whether a
 * name is taken in a folder (only by things the caller can see), the first free
 * "Name (2)", and what a shortcut points at. Used by the api/files endpoints
 * (through api_bootstrap.php) and by services that run without a web request
 * (the recycle bin), so it has no session or output of its own.
 *
 * "The caller" is $GLOBALS['analystId'], as the endpoints set it.
 */

require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/../rbac.php';
require_once __DIR__ . '/acl.php';

/**
 * A live file row, or null.
 *
 * @param int $itemId  the files_items id; null comes back if it or any folder above it is deleted
 */
function filesItem(PDO $conn, int $itemId): ?array
{
    $st = $conn->prepare("SELECT * FROM files_items WHERE id = ? AND deleted_datetime IS NULL");
    $st->execute([$itemId]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) return null;
    // A file in a deleted folder is gone too.
    if (!isset(FilesAcl::tree($conn)[(int)$r['folder_id']])) return null;
    return $r;
}

/**
 * Is a name already used by a live folder or file in this folder? (case-insensitive)
 *
 * ⚠️ Only folders the CALLER can see count. Otherwise "that name is taken" would
 * confirm a hidden folder exists - at the top level, or a subfolder that stopped
 * inheriting. Two people may therefore end up with same-named folders they cannot
 * see each other's of; that is the price of not leaking, and it is harmless.
 *
 * @param ?int $parentId     the folder to look in; null is the top level, where only folders are checked
 * @param string $name       the name to test, compared case-insensitively
 * @param string $exceptType 'folder', 'item' or 'shortcut' - the kind of the thing being renamed; '' for none
 * @param int $exceptId      the id of that thing, so its own current name does not count as taken
 */
function filesNameTaken(PDO $conn, ?int $parentId, string $name, string $exceptType = '', int $exceptId = 0): bool
{
    $visible = FilesAcl::levels($conn, (int)($GLOBALS['analystId'] ?? 0));
    $st = $conn->prepare("SELECT id FROM files_folders WHERE " . ($parentId === null ? "parent_id IS NULL" : "parent_id = ?") .
                         " AND deleted_datetime IS NULL AND LOWER(name) = LOWER(?)");
    $st->execute($parentId === null ? [$name] : [$parentId, $name]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) {
        if (!isset($visible[(int)$id])) continue;
        if (!($exceptType === 'folder' && (int)$id === $exceptId)) return true;
    }
    if ($parentId !== null) {
        $st = $conn->prepare("SELECT id FROM files_items WHERE folder_id = ? AND deleted_datetime IS NULL AND LOWER(name) = LOWER(?)");
        $st->execute([$parentId, $name]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) {
            if (!($exceptType === 'item' && (int)$id === $exceptId)) return true;
        }
        // Shortcuts share the folder's names too - but only ones the caller can see.
        $st = $conn->prepare("SELECT id, target_type, target_id FROM files_shortcuts WHERE folder_id = ? AND LOWER(name) = LOWER(?)");
        $st->execute([$parentId, $name]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $s) {
            if ($exceptType === 'shortcut' && (int)$s['id'] === $exceptId) continue;
            if (filesShortcutTarget($conn, (int)($GLOBALS['analystId'] ?? 0), $s['target_type'], (int)$s['target_id'])) return true;
        }
    }
    return false;
}

/**
 * What a shortcut points at, IF the caller can see it - else null. The one
 * place shortcuts meet permissions: a shortcut never widens access.
 * Returns [name, folder_id (the target folder, or the file's folder), path, level].
 *
 * @param string $type  files_shortcuts.target_type - 'folder', 'article', or anything else for a file ('item')
 * @param int $id       the target's id - a files_folders, knowledge_articles or files_items id to match $type
 */
function filesShortcutTarget(PDO $conn, int $analystId, string $type, int $id): ?array
{
    if ($type === 'article') return filesArticleTarget($conn, $analystId, $id);
    if ($type === 'folder') {
        $tree = FilesAcl::tree($conn);
        $lvl = FilesAcl::level($conn, $analystId, $id);
        if (!isset($tree[$id]) || $lvl < FilesAcl::VIEW) return null;
        return ['name' => $tree[$id]['name'], 'folder_id' => $id, 'path' => FilesAcl::path($conn, $id), 'level' => $lvl];
    }
    $it = filesItem($conn, $id);
    if (!$it) return null;
    $lvl = FilesAcl::level($conn, $analystId, (int)$it['folder_id']);
    if ($lvl < FilesAcl::VIEW) return null;
    return ['name' => $it['name'], 'folder_id' => (int)$it['folder_id'],
            'path' => FilesAcl::path($conn, (int)$it['folder_id']) . ' / ' . $it['name'], 'level' => $lvl];
}

/**
 * A Knowledge article as a shortcut target, or null when this person may not
 * read it (no Knowledge module, another company's, restricted, archived, a draft,
 * deleted). Knowledge's own visibility rules decide - the same clause its list
 * uses, WITHOUT the administrator floor: that floor is an audited override for
 * opening one article in Knowledge, never a reason for it to show up in a folder.
 *
 * @param int $id  the knowledge_articles id; 0 or less gives null
 */
function filesArticleTarget(PDO $conn, int $analystId, int $id): ?array
{
    static $viewer = [];
    if ($id <= 0) return null;
    if (!array_key_exists($analystId, $viewer)) {
        $viewer[$analystId] = null;
        try {
            if (analystCanAccessModule($conn, $analystId, 'knowledge')) {
                require_once __DIR__ . '/../knowledge/visibility.php';
                $viewer[$analystId] = KnowledgeViewer::forAnalyst($conn, $analystId);
            }
        } catch (Throwable) { /* fail closed */ }
    }
    if (!$viewer[$analystId]) return null;
    [$sql, $params] = knowledgeVisibilitySql($conn, $viewer[$analystId], 'a', ['no_admin_floor' => true]);
    $st = $conn->prepare("SELECT a.title FROM knowledge_articles a WHERE a.id = ?" . $sql . " LIMIT 1");
    $st->execute(array_merge([$id], $params));
    $title = $st->fetchColumn();
    if ($title === false) return null;
    return ['name' => (string)$title, 'folder_id' => null, 'path' => 'Knowledge / ' . $title, 'level' => FilesAcl::VIEW];
}

/**
 * "Report.pdf" -> "Report (2).pdf", the first free one.
 *
 * @param ?int $parentId  the folder the name must be free in; null is the top level
 * @param string $name    the name wanted; returned unchanged when nothing visible already uses it
 */
function filesFreeName(PDO $conn, ?int $parentId, string $name): string
{
    if (!filesNameTaken($conn, $parentId, $name)) return $name;
    $ext  = pathinfo($name, PATHINFO_EXTENSION);
    $base = $ext !== '' ? substr($name, 0, -strlen($ext) - 1) : $name;
    for ($i = 2; $i < 1000; $i++) {
        $try = $base . " ($i)" . ($ext !== '' ? ".$ext" : '');
        if (!filesNameTaken($conn, $parentId, $try)) return $try;
    }
    return $base . ' (' . bin2hex(random_bytes(3)) . ')' . ($ext !== '' ? ".$ext" : '');
}
