<?php
/**
 * API Endpoint: who may read a Knowledge folder or article.
 * Actions: get, set_mode, add, remove, search_principals
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * THE MODEL, RESTATED HERE BECAUSE THIS FILE IS WHERE IT IS EDITED
 *
 * An object is Open or Restricted, and the list means the opposite thing in each:
 *
 *   Open       everyone (subject to the other axes), MINUS anyone on the list
 *   Restricted nobody, PLUS anyone on the list
 *
 * The polarity lives on the OBJECT, never on the rows, so allow and deny can
 * never coexist and there is no precedence rule to explain. That is the whole
 * reason knowledge_acl has no allow/deny column.
 *
 * ⚠️ CHANGING THE POLARITY WIPES THE LIST, and says how many rows it is about to
 * drop. Keeping them dormant would leave invisible entries that spring back on
 * the next flip — the "an unloaded checkbox looks exactly like OFF" failure in
 * another costume.
 *
 * ⚠️ EDITING PERMISSIONS REQUIRES THE ADMINISTRATOR FLOOR. Anyone who can merely
 * READ a folder must not be able to change who else can: that would let the
 * first person granted access hand it to anybody, and the access list would mean
 * nothing by the end of the week.
 * ─────────────────────────────────────────────────────────────────────────────
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
    $action = $_GET['action'] ?? 'get';
} else {
    $input  = json_decode(file_get_contents('php://input'), true) ?: [];
    $action = $input['action'] ?? '';
}

try {
    $conn   = connectToDatabase();
    $viewer = KnowledgeViewer::forAnalyst($conn, $analystId);

    // Reading the list is gated too: knowing WHO is excluded from a folder is
    // itself information about the folder and about those people.
    if (!knowledgeViewerHasAdminFloor($conn, $viewer)) {
        echo json_encode(['success' => false, 'error' => 'You do not have permission to manage access.']);
        exit;
    }

    switch ($action) {
        case 'get':               handleGet($conn, $_GET); break;
        case 'set_mode':          handleSetMode($conn, $analystId, $input); break;
        case 'add':               handleAdd($conn, $analystId, $input); break;
        case 'remove':            handleRemove($conn, $analystId, $input); break;
        case 'search_principals': handleSearch($conn, $_GET); break;
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
 * 'folder' or 'article', and an id that exists. Anything else is refused.
 *
 * @param array $src  the request ($_GET or the decoded JSON body) carrying object_type and object_id
 */
function requireObject(PDO $conn, array $src): array
{
    $type = (string)($src['object_type'] ?? '');
    $id   = (int)($src['object_id'] ?? 0);
    if (!in_array($type, ['folder', 'article'], true) || $id <= 0) {
        echo json_encode(['success' => false, 'error' => 'Unknown object']);
        exit;
    }
    $table = $type === 'folder' ? 'knowledge_folders' : 'knowledge_articles';
    $st = $conn->prepare("SELECT id, is_restricted, inherit_permissions FROM {$table} WHERE id = ?");
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        echo json_encode(['success' => false, 'error' => 'Not found']);
        exit;
    }
    return [$type, $id, $table, $row];
}

/**
 * The current state of one object's access.
 *
 * Returns the names as well as the ids: a list of "analyst 7, team 3" is not
 * something anybody can check, and a permissions screen you cannot read is a
 * permissions screen nobody will audit.
 *
 * @param array $src  the query string ($_GET): object_type ('folder' or 'article') and object_id
 */
function handleGet(PDO $conn, array $src): void
{
    [$type, $id, , $row] = requireObject($conn, $src);

    $st = $conn->prepare("SELECT id, principal_type, principal_id FROM knowledge_acl WHERE object_type = ? AND object_id = ? ORDER BY principal_type, principal_id");
    $st->execute([$type, $id]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    $entries = [];
    foreach ($rows as $r) {
        $entries[] = [
            'id'             => (int)$r['id'],
            'principal_type' => $r['principal_type'],
            'principal_id'   => (int)$r['principal_id'],
            'name'           => principalName($conn, $r['principal_type'], (int)$r['principal_id']),
        ];
    }

    $out = [
        'success'       => true,
        'is_restricted' => (int)$row['is_restricted'],
        'inherits'      => (int)$row['inherit_permissions'],
        'entries'       => $entries,
    ];

    // ⚠️ WHEN IT INHERITS, SAY WHAT IT INHERITS. Hiding the list behind the
    // tickbox left a dialog headed "Who can see this" that answered nothing —
    // the one question it exists for. Resolve the folder actually governing this
    // object and return ITS rules, to be shown read-only.
    if ((int)$row['inherit_permissions'] === 1) {
        $out['inherited'] = resolveInherited($conn, $type, $id);
    }

    echo json_encode($out);
}

/**
 * Walk up to the folder whose rules actually apply, and describe them.
 *
 * Returns null when nothing above restricts anything — which is the ordinary
 * case and deserves saying plainly rather than showing an empty list, because an
 * empty list and "no restrictions anywhere" look identical and mean opposite
 * things.
 *
 * @param string $type  'folder' or 'article'
 * @param int $id  that folder's or article's id
 */
function resolveInherited(PDO $conn, string $type, int $id): ?array
{
    $folders = knowledgeFolderIndex($conn);

    // An article starts at its folder; a folder starts at its parent.
    if ($type === 'article') {
        $st = $conn->prepare("SELECT folder_id FROM knowledge_articles WHERE id = ?");
        $st->execute([$id]);
        $cursor = $st->fetchColumn();
        $cursor = ($cursor === null || $cursor === false) ? null : (int)$cursor;
    } else {
        $cursor = $folders[$id]['parent'] ?? null;
    }

    $seen = [];
    while ($cursor !== null && isset($folders[$cursor])) {
        if (isset($seen[$cursor])) return null;    // a cycle; fail quiet rather than hang
        $seen[$cursor] = true;

        if (!$folders[$cursor]['inherit']) {
            $st = $conn->prepare("SELECT name FROM knowledge_folders WHERE id = ?");
            $st->execute([$cursor]);
            $name = (string)$st->fetchColumn();

            $st = $conn->prepare("SELECT id, principal_type, principal_id FROM knowledge_acl WHERE object_type = 'folder' AND object_id = ? ORDER BY principal_type, principal_id");
            $st->execute([$cursor]);
            $entries = [];
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $entries[] = [
                    'id'             => (int)$r['id'],
                    'principal_type' => $r['principal_type'],
                    'principal_id'   => (int)$r['principal_id'],
                    'name'           => principalName($conn, $r['principal_type'], (int)$r['principal_id']),
                ];
            }
            return [
                'folder_id'     => $cursor,
                'folder_name'   => $name,
                'is_restricted' => (int)$folders[$cursor]['restricted'],
                'entries'       => $entries,
            ];
        }
        $cursor = $folders[$cursor]['parent'];
    }
    return null;
}

/**
 * Resolve a principal to something a human can check.
 *
 * A principal whose row has since been deleted is named as such rather than
 * shown blank: an unexplained empty line in a permissions list is the sort of
 * thing people leave alone forever because they dare not remove it.
 *
 * @param string $type  the principal_type: 'analyst', 'team', 'user' or 'user_group'
 * @param int $id  the principal's id in its own table
 */
function principalName(PDO $conn, string $type, int $id): string
{
    $map = [
        'analyst'    => ['analysts', 'full_name'],
        'team'       => ['teams', 'name'],
        'user'       => ['users', 'display_name'],
        'user_group' => ['knowledge_user_groups', 'name'],
    ];
    if (!isset($map[$type])) return '(unknown)';
    [$table, $col] = $map[$type];
    try {
        $st = $conn->prepare("SELECT {$col} FROM {$table} WHERE id = ?");
        $st->execute([$id]);
        $v = $st->fetchColumn();
        if ($v === false || $v === null || $v === '') {
            return '(deleted — safe to remove)';
        }
        return (string)$v;
    } catch (PDOException $e) {
        return '(unknown)';
    }
}

/**
 * Set Open/Restricted and the inherit tickbox.
 *
 * ⚠️ A POLARITY CHANGE DELETES THE LIST. See the header. The count of what will
 * be dropped is returned so the interface can say so BEFORE asking again, rather
 * than reporting a loss after the fact.
 *
 * @param array $in  the decoded JSON request: object_type, object_id, and optionally is_restricted and inherits
 */
function handleSetMode(PDO $conn, int $analystId, array $in): void
{
    [$type, $id] = requireObject($conn, $in);
    $access = KnowledgeTransferService::access($conn, $type, $id);
    $restricted = array_key_exists('is_restricted', $in) ? (bool)$in['is_restricted'] : $access['is_restricted'];
    $dropped = $restricted !== $access['is_restricted'] ? count($access['entries']) : 0;
    // Preserve the UI's polarity rule: changing Open/Restricted clears the list.
    if ($restricted !== $access['is_restricted']) $access['entries'] = [];
    $access['is_restricted'] = $restricted;
    if (array_key_exists('inherits', $in)) $access['inherits'] = (bool)$in['inherits'];
    KnowledgeTransferService::setAccess($conn, ActorContext::fromSession($conn), $type, $id, $access);
    echo json_encode(['success' => true, 'dropped' => $dropped]);
}

/**
 * Adds an analyst, team, portal user or user group to a folder's or article's access
 * list, and audits it. Adding someone already listed is treated as success.
 *
 * @param array $in  the decoded JSON request: object_type, object_id, principal_type and principal_id
 * @writes knowledge_acl - one entry for the object
 */
function handleAdd(PDO $conn, int $analystId, array $in): void
{
    [$type, $id] = requireObject($conn, $in);
    $access = KnowledgeTransferService::access($conn, $type, $id);
    $access['entries'][] = ['principal_type' => $in['principal_type'] ?? '', 'principal_id' => $in['principal_id'] ?? null];
    KnowledgeTransferService::setAccess($conn, ActorContext::fromSession($conn), $type, $id, $access);
    echo json_encode(['success' => true]);
}

/**
 * Removes one entry from a folder's or article's access list and audits it. The reply's
 * now_unreachable is true when a restricted object is left with nobody on its list.
 *
 * @param array $in  the decoded JSON request: object_type, object_id and entry_id (the knowledge_acl row)
 */
function handleRemove(PDO $conn, int $analystId, array $in): void
{
    [$type, $id] = requireObject($conn, $in);
    $st = $conn->prepare('SELECT principal_type, principal_id FROM knowledge_acl WHERE id = ? AND object_type = ? AND object_id = ?');
    $st->execute([(int)($in['entry_id'] ?? 0), $type, $id]);
    $entry = $st->fetch(PDO::FETCH_ASSOC);
    $access = KnowledgeTransferService::access($conn, $type, $id);
    if ($entry) {
        $access['entries'] = array_values(array_filter($access['entries'], static function ($row) use ($entry) {
            return $row['principal_type'] !== $entry['principal_type'] || $row['principal_id'] !== (int)$entry['principal_id'];
        }));
    }
    KnowledgeTransferService::setAccess($conn, ActorContext::fromSession($conn), $type, $id, $access);
    echo json_encode(['success' => true, 'now_unreachable' => $access['is_restricted'] && !$access['entries']]);
}

/**
 * Find people, teams and groups to add.
 *
 * All four kinds in one search rather than a type picker first: you know the
 * NAME of who you want, not which of four tables they live in.
 *
 * @param array $src  the query string ($_GET); q is the name to look for, at least 2 characters
 */
function handleSearch(PDO $conn, array $src): void
{
    $q = trim((string)($src['q'] ?? ''));
    if (mb_strlen($q) < 2) { echo json_encode(['success' => true, 'results' => []]); return; }
    $like = '%' . $q . '%';
    $out  = [];

    $add = function (string $sql, string $type, string $label) use ($conn, $like, &$out) {
        try {
            $st = $conn->prepare($sql);
            $st->execute([$like]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $out[] = ['type' => $type, 'id' => (int)$r['id'], 'name' => (string)$r['name'], 'kind' => $label];
            }
        } catch (PDOException $e) { /* table absent — that kind simply has no matches */ }
    };

    $add("SELECT id, full_name AS name FROM analysts WHERE is_active = 1 AND full_name LIKE ? ORDER BY full_name LIMIT 8", 'analyst', 'Analyst');
    $add("SELECT id, name FROM teams WHERE is_active = 1 AND name LIKE ? ORDER BY name LIMIT 8", 'team', 'Team');
    $add("SELECT id, COALESCE(NULLIF(display_name,''), email, username) AS name FROM users WHERE COALESCE(NULLIF(display_name,''), email, username) LIKE ? ORDER BY name LIMIT 8", 'user', 'Portal user');
    $add("SELECT id, name FROM knowledge_user_groups WHERE is_active = 1 AND name LIKE ? ORDER BY name LIMIT 8", 'user_group', 'Group');

    echo json_encode(['success' => true, 'results' => $out]);
}

/**
 * Permission changes are the rows people actually come looking for. Never silent.
 *
 * @param string $type  'folder' or 'article'
 * @param int $id  that folder's or article's id
 * @param array $detail  what changed, stored as JSON on the audit row
 */
function permAudit(PDO $conn, string $type, int $id, int $analystId, array $detail): void
{
    knowledgeAuditLog($conn, $type, $id, 'permissions', $analystId, $detail);
}
