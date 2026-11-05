<?php
/**
 * API Endpoint: Get knowledge base articles list
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/tenancy.php';
require_once '../../includes/knowledge/visibility.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

// Reachable from more than one module, so a single-module gate would break the others —
// but it had NO module check at all, and it spends the AI budget. (Found by D005.)
requireAnyModuleAccessJson(['knowledge', 'tickets', 'lms'], null, 'knowledge');   // owner: GH #217

$search = trim($_GET['search'] ?? '');
$tagIds = isset($_GET['tags']) ? array_filter(explode(',', $_GET['tags'])) : [];
$limit = null;
if (isset($_GET['limit'])) {
    $limit = filter_var($_GET['limit'], FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1, 'max_range' => 50]]);
    if ($limit === false) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid limit']);
        exit;
    }
}

try {
    $conn = connectToDatabase();

    // LEFT JOIN (not INNER) so articles authored by a since-deleted analyst
    // still show up — otherwise they vanish from the list but stay counted in
    // the tag sidebar, causing the count mismatch reported in #391.
    // ⚠️ Unpublished articles ARE listed here, deliberately, and this is the ONLY
    // reader where that is true. The Knowledge assistant saves its AI-written
    // work as an unpublished draft, and a draft nobody can find is a draft
    // nobody will ever publish — the feature would appear to lose your work.
    // Analysts see drafts with a badge; every reader that faces a customer
    // (includes/knowledge/portal_reader.php, and KB_VISIBLE_SQL in
    // includes/knowledge/kb_ai.php for web chat + AI answers) still requires
    // is_published = 1, so nothing unreviewed can reach a requester.
    $sql = "SELECT DISTINCT a.id, a.title, a.created_datetime, a.modified_datetime, a.view_count,
                   a.tenant_id, a.audience, a.is_published,
                   a.folder_id, a.inherit_permissions, a.is_restricted,
                   LEFT(a.body, 300) as preview,
                   COALESCE(an.full_name, '(deleted analyst)') as author_name
            FROM knowledge_articles a
            LEFT JOIN analysts an ON an.id = a.author_id
            WHERE 1=1";

    // Everything a reader is allowed to see, in one clause: lifecycle, company,
    // audience and (once it lands) the access list. 'unarchived' rather than
    // 'live' is what keeps the drafts above visible.
    $viewer = KnowledgeViewer::forAnalyst($conn, (int)$_SESSION['analyst_id']);
    [$visSql, $params] = knowledgeVisibilitySql($conn, $viewer, 'a', ['lifecycle' => 'unarchived']);
    $sql .= $visSql;

    // Folder filter. 'root' means the articles filed nowhere — the folder that
    // is not a row — which is distinct from "no filter at all", so it needs its
    // own value rather than an empty string.
    //
    // Not access-checked here on purpose: the visibility clause above already
    // decides what may be returned, so naming a folder you cannot read yields an
    // empty list rather than a refusal. That is the same answer an empty folder
    // gives, which is exactly right — a refusal would confirm the folder exists.
    $folder = $_GET['folder'] ?? '';
    if ($folder === 'root') {
        $sql .= " AND a.folder_id IS NULL";
    } elseif ($folder !== '' && ctype_digit((string)$folder)) {
        // Filed here, OR pointed at from here by a shortcut. A shortcut has no
        // permissions of its own — it resolves to the target, and the visibility
        // clause above has already decided whether the target may be seen. So a
        // shortcut to something you cannot read simply yields no row, rather
        // than a row that leaks the target's title.
        $sql .= " AND (a.folder_id = ? OR EXISTS (SELECT 1 FROM knowledge_shortcuts s WHERE s.article_id = a.id AND s.folder_id = ?))";
        $params[] = (int)$folder;
        $params[] = (int)$folder;
    }

    // Search filter
    if (!empty($search)) {
        $sql .= " AND (a.title LIKE ? OR a.body LIKE ?)";
        $params[] = '%' . $search . '%';
        $params[] = '%' . $search . '%';
    }

    // Tag filter
    if (!empty($tagIds)) {
        $placeholders = implode(',', array_fill(0, count($tagIds), '?'));
        $sql .= " AND a.id IN (SELECT article_id FROM knowledge_article_tags WHERE tag_id IN ($placeholders))";
        $params = array_merge($params, $tagIds);
    }

    $sql .= " ORDER BY a.modified_datetime DESC";
    if ($limit !== null) $sql .= " LIMIT " . $limit;

    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $articles = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get tags for each article
    foreach ($articles as &$article) {
        $tagSql = "SELECT t.id, t.name
                   FROM knowledge_tags t
                   INNER JOIN knowledge_article_tags kat ON kat.tag_id = t.id
                   WHERE kat.article_id = ?";
        $tagStmt = $conn->prepare($tagSql);
        $tagStmt->execute([$article['id']]);
        $article['tags'] = $tagStmt->fetchAll(PDO::FETCH_ASSOC);

        // Strip HTML from preview
        $article['preview'] = strip_tags($article['preview']);
    }

    echo json_encode([
        'success' => true,
        'articles' => $articles
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}

?>
