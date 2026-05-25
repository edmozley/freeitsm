<?php
/**
 * API Endpoint: Get Articles for Embedding
 * Returns list of published articles that don't have embeddings yet
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

    // Get articles without embeddings
    $sql = "SELECT id, title FROM knowledge_articles
            WHERE is_published = 1
            AND (is_archived = 0 OR is_archived IS NULL)
            AND (embedding IS NULL OR LENGTH(embedding) = 0)
            ORDER BY id";
    $stmt = $conn->prepare($sql);
    $stmt->execute();
    $articles = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'articles' => $articles
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
