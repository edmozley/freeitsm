<?php
/**
 * API Endpoint: Get all knowledge base tags with article counts
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

try {
    $conn = connectToDatabase();

    // article_count must match exactly what knowledge_articles.php returns
    // for the list view — same WHERE clause — otherwise clicking a tag
    // appears to show fewer matches than its sidebar count suggests.
    $sql = "SELECT t.id, t.name,
                   (SELECT COUNT(*) FROM knowledge_article_tags kat
                    INNER JOIN knowledge_articles ka ON ka.id = kat.article_id
                    WHERE kat.tag_id = t.id
                      AND ka.is_published = 1
                      AND (ka.is_archived = 0 OR ka.is_archived IS NULL)) as article_count
            FROM knowledge_tags t
            ORDER BY t.name";

    $stmt = $conn->query($sql);
    $tags = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'tags' => $tags
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}

?>
