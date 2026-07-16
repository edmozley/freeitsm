<?php
/**
 * API Endpoint: Get knowledge base articles list
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/tenancy.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

// Reachable from more than one module, so a single-module gate would break the others —
// but it had NO module check at all, and it spends the AI budget. (Found by D005.)
requireAnyModuleAccessJson(['knowledge', 'tickets', 'lms']);

$search = trim($_GET['search'] ?? '');
$tagIds = isset($_GET['tags']) ? array_filter(explode(',', $_GET['tags'])) : [];

try {
    $conn = connectToDatabase();

    // LEFT JOIN (not INNER) so articles authored by a since-deleted analyst
    // still show up — otherwise they vanish from the list but stay counted in
    // the tag sidebar, causing the count mismatch reported in #391.
    $sql = "SELECT DISTINCT a.id, a.title, a.created_datetime, a.modified_datetime, a.view_count,
                   a.tenant_id, a.audience,
                   LEFT(a.body, 300) as preview,
                   COALESCE(an.full_name, '(deleted analyst)') as author_name
            FROM knowledge_articles a
            LEFT JOIN analysts an ON an.id = a.author_id
            WHERE a.is_published = 1
              AND (a.is_archived = 0 OR a.is_archived IS NULL)";

    $params = [];

    // Scope to the company the analyst has switched to, plus shared articles.
    // No-op on a single-company install. Analysts see every audience — the ladder
    // only holds back customers and the public.
    [$tenantSql, $tenantParams] = knowledgeTenantFilter($conn, (int)$_SESSION['analyst_id'], 'a');
    $sql .= $tenantSql;
    $params = array_merge($params, $tenantParams);

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
