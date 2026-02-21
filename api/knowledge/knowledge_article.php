<?php
/**
 * API Endpoint: Get single knowledge base article
 */
session_start();
require_once '../../config.php';
require_once '../../includes/functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

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
                   a.is_archived, a.archived_datetime, a.version,
                   an.full_name as author_name,
                   owner.full_name as owner_name
            FROM knowledge_articles a
            INNER JOIN analysts an ON an.id = a.author_id
            LEFT JOIN analysts owner ON owner.id = a.owner_id
            WHERE a.id = ?";

    if (!$includeArchived) {
        $sql .= " AND (a.is_archived = 0 OR a.is_archived IS NULL)";
    }

    $stmt = $conn->prepare($sql);
    $stmt->execute([$articleId]);
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
