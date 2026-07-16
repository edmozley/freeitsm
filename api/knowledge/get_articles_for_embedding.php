<?php
/**
 * API Endpoint: Get Articles for Embedding
 * Returns list of published articles that don't have embeddings yet
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/tenancy.php';
require_once '../../includes/rbac.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

// Settings-only: reached from the Embeddings tab and nowhere else. It had no module
// check at all — any logged-in analyst could enumerate the knowledge base this way.
requireModuleAccessJson('knowledge');
requireCapabilityJson(Cap::KNOWLEDGE_EMBEDDINGS);

try {
    $conn = connectToDatabase();

    // Scoped like every other list: you can only backfill embeddings for articles
    // you can see. An all-access analyst still covers the whole install.
    [$tenantSql, $tenantParams] = knowledgeTenantFilter($conn, (int)$_SESSION['analyst_id'], '');

    // Get articles without embeddings
    $sql = "SELECT id, title FROM knowledge_articles
            WHERE is_published = 1
            AND (is_archived = 0 OR is_archived IS NULL)
            AND (embedding IS NULL OR LENGTH(embedding) = 0)"
            . $tenantSql . "
            ORDER BY id";
    $stmt = $conn->prepare($sql);
    $stmt->execute($tenantParams);
    $articles = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'articles' => $articles
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
