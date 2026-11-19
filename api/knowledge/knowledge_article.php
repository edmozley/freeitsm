<?php
/**
 * API Endpoint: Get single knowledge base article
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

$articleId = (int)($_GET['id'] ?? 0);

if (!$articleId) {
    echo json_encode(['success' => false, 'error' => 'Article ID required']);
    exit;
}

try {
    $conn = connectToDatabase();

    $includeArchived = (int)($_GET['include_archived'] ?? 0);

    // Get article
    $sql = "SELECT a.id, a.title, a.body,
                   a.author_id, a.owner_id, a.next_review_date,
                   a.created_datetime, a.modified_datetime, a.view_count,
                   a.is_archived, a.archived_datetime, a.version, a.is_published,
                   a.tenant_id, a.audience, a.folder_id, a.is_restricted, a.inherit_permissions,
                   COALESCE(an.full_name, '(deleted analyst)') as author_name,
                   owner.full_name as owner_name
            FROM knowledge_articles a
            LEFT JOIN analysts an ON an.id = a.author_id
            LEFT JOIN analysts owner ON owner.id = a.owner_id
            WHERE a.id = ?";

    // An id is a guess away, so a direct fetch needs the same filter the list
    // gets. It is carried IN the query rather than checked after the row is in
    // hand: a fetch that loads first and adjudicates second is one early return
    // away from serving the row it just decided you could not see. A miss is
    // reported as "not found" either way — an analyst has no business learning
    // that article 47 exists but belongs to someone else.
    $viewer = KnowledgeViewer::forAnalyst($conn, (int)$_SESSION['analyst_id']);
    [$visSql, $visParams] = knowledgeVisibilitySql($conn, $viewer, 'a', [
        'lifecycle' => $includeArchived ? 'any' : 'unarchived',
    ]);
    $sql .= $visSql;

    $stmt = $conn->prepare($sql);
    $stmt->execute(array_merge([$articleId], $visParams));
    $article = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$article) {
        echo json_encode(['success' => false, 'error' => 'Article not found']);
        exit;
    }

    // Get tags
    $tagSql = "SELECT t.id, t.name
               FROM knowledge_tags t
               INNER JOIN knowledge_article_tags kat ON kat.tag_id = t.id
               WHERE kat.article_id = ?";
    $tagStmt = $conn->prepare($tagSql);
    $tagStmt->execute([$articleId]);
    $article['tags'] = $tagStmt->fetchAll(PDO::FETCH_ASSOC);

    // Increment view count (skip for archived articles)
    if (!$article['is_archived']) {
        $updateSql = "UPDATE knowledge_articles SET view_count = view_count + 1 WHERE id = ?";
        $updateStmt = $conn->prepare($updateSql);
        $updateStmt->execute([$articleId]);
    }

    echo json_encode([
        'success' => true,
        'article' => $article
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}

?>
