<?php
/**
 * API Endpoint: Get Embedding Stats
 * Returns statistics on how many articles have embeddings
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

    // Get total published articles
    $totalSql = "SELECT COUNT(*) as total FROM knowledge_articles WHERE is_published = 1 AND (is_archived = 0 OR is_archived IS NULL)";
    $totalStmt = $conn->prepare($totalSql);
    $totalStmt->execute();
    $total = $totalStmt->fetch(PDO::FETCH_ASSOC)['total'];

    // Get articles with embeddings
    $withSql = "SELECT COUNT(*) as count FROM knowledge_articles WHERE is_published = 1 AND (is_archived = 0 OR is_archived IS NULL) AND embedding IS NOT NULL AND LENGTH(embedding) > 0";
    $withStmt = $conn->prepare($withSql);
    $withStmt->execute();
    $withEmbeddings = $withStmt->fetch(PDO::FETCH_ASSOC)['count'];

    echo json_encode([
        'success' => true,
        'stats' => [
            'total' => (int)$total,
            'with_embeddings' => (int)$withEmbeddings,
            'without_embeddings' => (int)$total - (int)$withEmbeddings
        ]
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
