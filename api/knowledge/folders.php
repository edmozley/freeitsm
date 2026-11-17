<?php
/**
 * API Endpoint: Knowledge folders
 * Actions: list, create, rename, move, delete
 *
 * 🔑 THE TREE THIS RETURNS IS ALREADY FILTERED. A folder the caller cannot read
 * is not sent with a flag for the browser to hide — it is not sent at all.
 * Filtering in the client would mean the names had already crossed the wire,
 * and a folder name is exactly the kind of thing worth restricting ("Project
 * Nightingale", "Redundancies 2026").
 *
 * ⚠️ Readability is decided by includes/knowledge/visibility.php, the same file
 * that decides it for articles, so the tree and the article list can never
 * disagree about what exists.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/tenancy.php';
require_once '../../includes/knowledge/visibility.php';
require_once '../../includes/knowledge/audit.php';
require_once '../../includes/services/knowledge_transfer.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireModuleAccessJson('knowledge');

$analystId = (int)$_SESSION['analyst_id'];
$method    = $_SERVER['REQUEST_METHOD'];
$input     = [];

if ($method === 'GET') {
    $action = $_GET['action'] ?? 'list';
} else {
    $input  = json_decode(file_get_contents('php://input'), true) ?: [];
    $action = $input['action'] ?? '';
}

try {
    $conn = connectToDatabase();

    switch ($action) {
        case 'list':   handleList($conn, $analystId); break;
        case 'create': handleCreate($conn, $analystId, $input); break;
        case 'rename': handleRename($conn, $analystId, $input); break;
        case 'move':   handleMove($conn, $analystId, $input); break;
        case 'delete': handleDelete($conn, $analystId, $input); break;
        case 'move_article':      handleMoveArticle($conn, $analystId, $input); break;
        case 'add_shortcut':      handleAddShortcut($conn, $analystId, $input); break;
        case 'remove_shortcut':   handleRemoveShortcut($conn, $analystId, $input); break;
        case 'exceptions':        handleExceptions($conn, $analystId); break;
        default:
            echo json_encode(['success' => false, 'error' => 'Invalid action']);
    }
} catch (ServiceError $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('[Knowledge access] ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'The Knowledge operation could not be completed.']);
}

/**
 * The folders this analyst may see, each with the number of articles they may
 * see inside it.
 *
 * ⚠️ THE COUNT USES THE SAME CLAUSE AS THE ARTICLE LIST. A folder that says "4"
 * and opens to show 2 is the tag-badge bug (#1212) about to happen again, and
 * for the same reason: two hand-written copies of one rule that were meant to
 * match. Both ask knowledgeVisibilitySql().
 */
function handleList(PDO $conn, int $analystId): void
{
    $viewer = KnowledgeViewer::forAnalyst($conn, $analystId);

    // 'unarchived' matches knowledge_articles.php exactly — drafts count, because
    // drafts are listed.
    [$visSql, $visParams] = knowledgeVisibilitySql($conn, $viewer, 'a', ['lifecycle' => 'unarchived']);

    $rows = $conn->query(
        "SELECT id, parent_id, name, is_restricted, inherit_permissions, owner_id, tenant_id
           FROM knowledge_folders ORDER BY name"
    )->fetchAll(PDO::FETCH_ASSOC);

    // Counts for every folder in one query rather than one query per folder.
    $counts = [];
    $st = $conn->prepare(
        "SELECT a.folder_id, COUNT(*) AS n FROM knowledge_articles a
          WHERE a.folder_id IS NOT NULL" . $visSql . " GROUP BY a.folder_id"
    );
    $st->execute($visParams);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $counts[(int)$r['folder_id']] = (int)$r['n'];

    // Articles at the root — the folder that is not a row.
    $st = $conn->prepare("SELECT COUNT(*) FROM knowledge_articles a WHERE a.folder_id IS NULL" . $visSql);
    $st->execute($visParams);
    $rootCount = (int)$st->fetchColumn();

    $folders = [];
    foreach ($rows as $r) {
        $id = (int)$r['id'];
        // A folder is listed only if this analyst may reach it — the same
        // question, asked of the same code, as an article inside it.
        if (!knowledgeFolderIsReadable($conn, $viewer, $id)) continue;
        $folders[] = [
            'id'            => $id,
            'parent_id'     => $r['parent_id'] === null ? null : (int)$r['parent_id'],
            'name'          => $r['name'],
            'is_restricted' => (int)$r['is_restricted'],
            'inherits'      => (int)$r['inherit_permissions'],
            'article_count' => $counts[$id] ?? 0,
        ];
    }

    // A folder whose parent was filtered out would be an orphan the tree cannot
    // place. Promote it to the root rather than dropping it: you are allowed to
    // see it, and hiding it because of where it sits would lose the document.
    $present = [];
    foreach ($folders as $f) $present[$f['id']] = true;
    foreach ($folders as &$f) {
        if ($f['parent_id'] !== null && !isset($present[$f['parent_id']])) {
            $f['parent_id'] = null;
            $f['detached']  = true;
        }
    }
    unset($f);

    echo json_encode([
        'success'    => true,
        'folders'    => $folders,
        'root_count' => $rootCount,
        'can_manage' => knowledgeViewerHasAdminFloor($conn, $viewer),
    ]);
}

// knowledgeFolderIsReadable() lives in includes/knowledge/visibility.php, so the
// article service checks a target folder by the same rule as this file.

/**
 * Refuse anything the caller may not reach, in the same words as "not found".
 *
 * @param ?int $folderId  the folder to check; null means the root, which everyone may reach
 */
function requireFolderAccess(PDO $conn, int $analystId, ?int $folderId): void
{
    if ($folderId === null) return;   // the root: everyone has it
    $viewer = KnowledgeViewer::forAnalyst($conn, $analystId);
    $exists = $conn->prepare("SELECT 1 FROM knowledge_folders WHERE id = ?");
    $exists->execute([$folderId]);
    if (!$exists->fetchColumn() || !knowledgeFolderIsReadable($conn, $viewer, $folderId)) {
        echo json_encode(['success' => false, 'error' => 'Folder not found']);
        exit;
    }
}

/**
 * Creates a knowledge folder under the given parent (or at the top level) and audits it.
 *
 * A new folder always inherits and restricts nothing, so creating one never changes who
 * can see what is already there. The caller must be able to read the parent.
 *
 * @param array $in  the decoded JSON request: name, and parent_id (empty or null for the top level)
 */
function handleCreate(PDO $conn, int $analystId, array $in): void
{
    $parent = $in['parent_id'] ?? null;
    if ($parent === '' || $parent === 0 || $parent === '0') $parent = null;
    $result = KnowledgeTransferService::createFolder($conn, ActorContext::fromSession($conn), [
        'name' => $in['name'] ?? '', 'parent_id' => $parent,
    ]);
    echo json_encode(['success' => true, 'id' => $result['id']]);
}

/**
 * Renames a knowledge folder the caller can read, and audits the change.
 *
 * @param array $in  the decoded JSON request: id and the new name
 */
function handleRename(PDO $conn, int $analystId, array $in): void
{
    $id   = (int)($in['id'] ?? 0);
    $name = trim((string)($in['name'] ?? ''));
    if (!$id || $name === '') { echo json_encode(['success' => false, 'error' => 'A folder and a name are required']); return; }
    requireFolderAccess($conn, $analystId, $id);

    $conn->prepare("UPDATE knowledge_folders SET name = ?, modified_datetime = UTC_TIMESTAMP() WHERE id = ?")
         ->execute([$name, $id]);
    knowledgeAudit($conn, 'folder', $id, 'edit', $analystId, ['name' => $name]);
    echo json_encode(['success' => true]);
}

/**
 * Re-parent a folder.
 *
 * ⚠️ REFUSES A CYCLE. Dropping a folder into its own descendant would detach the
 * whole branch from the root: every folder in it still exists, still holds
 * documents, and is reachable from nothing. The permission walk survives a cycle
 * (it fails closed) but the tree does not, so this is refused at the door rather
 * than tolerated later.
 *
 * @param array $in  the decoded JSON request: id, and parent_id (empty or null for the top level)
 */
function handleMove(PDO $conn, int $analystId, array $in): void
{
    $id     = (int)($in['id'] ?? 0);
    $parent = isset($in['parent_id']) && $in['parent_id'] !== null && $in['parent_id'] !== ''
        ? (int)$in['parent_id'] : null;
    if (!$id) { echo json_encode(['success' => false, 'error' => 'A folder is required']); return; }

    requireFolderAccess($conn, $analystId, $id);
    requireFolderAccess($conn, $analystId, $parent);

    if ($parent === $id) { echo json_encode(['success' => false, 'error' => 'A folder cannot be inside itself']); return; }

    $folders = knowledgeFolderIndex($conn);
    $cursor  = $parent;
    $seen    = [];
    while ($cursor !== null && isset($folders[$cursor])) {
        if ($cursor === $id) { echo json_encode(['success' => false, 'error' => 'A folder cannot be moved inside one of its own subfolders']); return; }
        if (isset($seen[$cursor])) break;    // already corrupt; do not hang
        $seen[$cursor] = true;
        $cursor = $folders[$cursor]['parent'];
    }

    $conn->prepare("UPDATE knowledge_folders SET parent_id = ?, modified_datetime = UTC_TIMESTAMP() WHERE id = ?")
         ->execute([$parent, $id]);
    knowledgeAclResetCaches();
    knowledgeAudit($conn, 'folder', $id, 'move', $analystId, ['parent_id' => $parent]);
    echo json_encode(['success' => true]);
}

/**
 * Delete a folder.
 *
 * ⚠️ REFUSES WHILE IT HOLDS ANYTHING. Deleting a folder full of documents would
 * either destroy them or orphan them, and neither is something to do on one
 * click. Empty it first — which is a decision about the documents, made
 * deliberately, rather than a side effect of tidying the tree.
 *
 * @param array $in  the decoded JSON request: id of the folder to delete
 */
function handleDelete(PDO $conn, int $analystId, array $in): void
{
    $id = (int)($in['id'] ?? 0);
    if (!$id) { echo json_encode(['success' => false, 'error' => 'A folder is required']); return; }
    requireFolderAccess($conn, $analystId, $id);

    $st = $conn->prepare("SELECT COUNT(*) FROM knowledge_articles WHERE folder_id = ?");
    $st->execute([$id]);
    if ((int)$st->fetchColumn() > 0) {
        echo json_encode(['success' => false, 'error' => 'That folder still has articles in it. Move them out first.']);
        return;
    }
    $st = $conn->prepare("SELECT COUNT(*) FROM knowledge_folders WHERE parent_id = ?");
    $st->execute([$id]);
    if ((int)$st->fetchColumn() > 0) {
        echo json_encode(['success' => false, 'error' => 'That folder still has subfolders in it. Move or delete them first.']);
        return;
    }

    // The access rows go with it. They name an object that will not exist, and a
    // later folder reusing the id would silently inherit somebody else's rules.
    $conn->prepare("DELETE FROM knowledge_acl WHERE object_type = 'folder' AND object_id = ?")->execute([$id]);
    $conn->prepare("DELETE FROM knowledge_folders WHERE id = ?")->execute([$id]);

    knowledgeAclResetCaches();
    knowledgeAudit($conn, 'folder', $id, 'delete', $analystId, null);
    echo json_encode(['success' => true]);
}

/**
 * File an article into a folder — what a drag-and-drop actually does.
 *
 * ⚠️ BOTH ENDS ARE CHECKED, and they are different questions. The DESTINATION
 * must be a folder this analyst can reach, or a drop would file a document
 * somewhere they cannot see it again. The ARTICLE must be one they can read,
 * because moving something is a way of finding out it exists — an id is a guess
 * away, and "it moved" and "no such article" must look the same from outside.
 *
 * Deliberately its own action rather than a save: the editor's save carries a
 * title and a body, and requiring those to move a row would mean the drag had to
 * fetch and re-post the whole article — which is also how a drag ends up
 * overwriting an edit somebody else was making.
 *
 * @param array $in  the decoded JSON request: article_id, and folder_id (empty or null for the root)
 */
function handleMoveArticle(PDO $conn, int $analystId, array $in): void
{
    $articleId = (int)($in['article_id'] ?? 0);
    $folder    = isset($in['folder_id']) && $in['folder_id'] !== null && $in['folder_id'] !== ''
        ? (int)$in['folder_id'] : null;
    if (!$articleId) { echo json_encode(['success' => false, 'error' => 'An article is required']); return; }

    requireFolderAccess($conn, $analystId, $folder);

    $viewer = KnowledgeViewer::forAnalyst($conn, $analystId);
    if (!knowledgeCanRead($conn, $viewer, $articleId, ['lifecycle' => 'any'])) {
        echo json_encode(['success' => false, 'error' => 'Article not found']);
        return;
    }

    $conn->prepare("UPDATE knowledge_articles SET folder_id = ?, modified_datetime = UTC_TIMESTAMP() WHERE id = ?")
         ->execute([$folder, $articleId]);

    knowledgeAudit($conn, 'article', $articleId, 'move', $analystId, ['folder_id' => $folder]);
    echo json_encode(['success' => true]);
}

/**
 * Make an article appear in a second folder.
 *
 * 🔑 A SHORTCUT HAS NO PERMISSIONS OF ITS OWN. It resolves to the target and the
 * target's rules decide — which is exactly what lets the tree stay single-parent
 * (one home, one answer to "who can see it") while a document still appears in
 * two places. Two consequences, both load-bearing:
 *
 *   • a shortcut can never GRANT. If you cannot read the target, the shortcut is
 *     not a way in — the list query filters on the TARGET, so the row simply is
 *     not returned rather than being returned and hidden.
 *   • creating one requires being able to read the target, or a shortcut would
 *     be a way to confirm that an article you cannot see exists.
 *
 * @param array $in  the decoded JSON request: article_id and the folder_id the shortcut goes in
 */
function handleAddShortcut(PDO $conn, int $analystId, array $in): void
{
    $articleId = (int)($in['article_id'] ?? 0);
    $folder    = isset($in['folder_id']) && $in['folder_id'] !== null && $in['folder_id'] !== ''
        ? (int)$in['folder_id'] : null;
    if (!$articleId || $folder === null) {
        echo json_encode(['success' => false, 'error' => 'An article and a folder are required']);
        return;
    }
    requireFolderAccess($conn, $analystId, $folder);

    $viewer = KnowledgeViewer::forAnalyst($conn, $analystId);
    if (!knowledgeCanRead($conn, $viewer, $articleId, ['lifecycle' => 'any'])) {
        echo json_encode(['success' => false, 'error' => 'Article not found']);
        return;
    }

    // Pointing a shortcut at the folder the article already lives in would show
    // it twice in one list. Harmless, but it looks like a bug to whoever sees it.
    $st = $conn->prepare("SELECT folder_id FROM knowledge_articles WHERE id = ?");
    $st->execute([$articleId]);
    if ((int)$st->fetchColumn() === $folder) {
        echo json_encode(['success' => false, 'error' => 'That article already lives in this folder.']);
        return;
    }

    try {
        $conn->prepare(
            "INSERT INTO knowledge_shortcuts (folder_id, article_id, created_by_id, created_datetime)
             VALUES (?, ?, ?, UTC_TIMESTAMP())"
        )->execute([$folder, $articleId, $analystId]);
    } catch (PDOException $e) {
        // The unique key: already there is the state the caller wanted.
        if ($e->getCode() !== '23000') throw $e;
    }
    knowledgeAudit($conn, 'article', $articleId, 'move', $analystId, ['shortcut_into' => $folder]);
    echo json_encode(['success' => true]);
}

/**
 * Remove a shortcut. The article itself is untouched — that is the whole point.
 *
 * @param array $in  the decoded JSON request: article_id and the folder_id the shortcut is in
 */
function handleRemoveShortcut(PDO $conn, int $analystId, array $in): void
{
    $articleId = (int)($in['article_id'] ?? 0);
    $folder    = (int)($in['folder_id'] ?? 0);
    if (!$articleId || !$folder) { echo json_encode(['success' => false, 'error' => 'Nothing to remove']); return; }
    requireFolderAccess($conn, $analystId, $folder);

    $conn->prepare("DELETE FROM knowledge_shortcuts WHERE article_id = ? AND folder_id = ?")
         ->execute([$articleId, $folder]);
    knowledgeAudit($conn, 'article', $articleId, 'move', $analystId, ['shortcut_removed_from' => $folder]);
    echo json_encode(['success' => true]);
}

/**
 * Everything on this install carrying its OWN permissions rather than its
 * parent's — the report from §9 of the design page.
 *
 * ⚠️ THIS IS THE ANSWER TO THE ONE THING THAT MAKES PERMISSION SYSTEMS
 * UNMANAGEABLE. A document whose rules differ from its folder is invisible from
 * the tree: you cannot look at a folder and know what is true inside it. At ten
 * exceptions that is fine and at four hundred it is not, so the exceptions get a
 * list of their own. Nothing here is a permission check — it is a way of finding
 * the things that need checking.
 */
function handleExceptions(PDO $conn, int $analystId): void
{
    $viewer = KnowledgeViewer::forAnalyst($conn, $analystId);
    if (!knowledgeViewerHasAdminFloor($conn, $viewer)) {
        echo json_encode(['success' => false, 'error' => 'You do not have permission to manage access.']);
        return;
    }

    $out = [];

    $st = $conn->query(
        "SELECT f.id, f.name, f.is_restricted,
                (SELECT COUNT(*) FROM knowledge_acl a WHERE a.object_type = 'folder' AND a.object_id = f.id) AS entries
           FROM knowledge_folders f
          WHERE f.inherit_permissions = 0
          ORDER BY f.name"
    );
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[] = [
            'type'          => 'folder',
            'id'            => (int)$r['id'],
            'name'          => $r['name'],
            'is_restricted' => (int)$r['is_restricted'],
            'entries'       => (int)$r['entries'],
        ];
    }

    $st = $conn->query(
        "SELECT a.id, a.title, a.is_restricted,
                (SELECT COUNT(*) FROM knowledge_acl x WHERE x.object_type = 'article' AND x.object_id = a.id) AS entries
           FROM knowledge_articles a
          WHERE a.inherit_permissions = 0 AND (a.is_archived = 0 OR a.is_archived IS NULL)
          ORDER BY a.title"
    );
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[] = [
            'type'          => 'article',
            'id'            => (int)$r['id'],
            'name'          => $r['title'],
            'is_restricted' => (int)$r['is_restricted'],
            'entries'       => (int)$r['entries'],
        ];
    }

    echo json_encode(['success' => true, 'exceptions' => $out]);
}

/**
 * Thin alias onto includes/knowledge/audit.php, the one writer.
 *
 * @param string $type  what was acted on: 'folder' or 'article'
 * @param int $id  that folder's or article's id
 * @param string $action  one of KNOWLEDGE_AUDIT_ACTIONS, e.g. 'create', 'move', 'delete'
 * @param ?array $detail  extra facts stored as JSON with the row, or null
 */
function knowledgeAudit(PDO $conn, string $type, int $id, string $action, int $analystId, ?array $detail): void
{
    knowledgeAuditLog($conn, $type, $id, $action, $analystId, $detail);
}
