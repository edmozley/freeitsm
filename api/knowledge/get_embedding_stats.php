<?php
/**
 * API Endpoint: Get Embedding Stats
 * Returns statistics on how many articles have embeddings
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

    // Must agree with get_articles_for_embedding.php, which is scoped — otherwise
    // the progress bar counts articles the backfill will never offer, and the
    // totals leak how many articles other companies have.
    [$tenantSql, $tenantParams] = knowledgeTenantFilter($conn, (int)$_SESSION['analyst_id'], '');

    // Get total published articles
    $totalSql = "SELECT COUNT(*) as total FROM knowledge_articles WHERE is_published = 1 AND (is_archived = 0 OR is_archived IS NULL)" . $tenantSql;
    $totalStmt = $conn->prepare($totalSql);
    $totalStmt->execute($tenantParams);
    $total = $totalStmt->fetch(PDO::FETCH_ASSOC)['total'];

    // Get articles with embeddings
    $withSql = "SELECT COUNT(*) as count FROM knowledge_articles WHERE is_published = 1 AND (is_archived = 0 OR is_archived IS NULL) AND embedding IS NOT NULL AND LENGTH(embedding) > 0" . $tenantSql;
    $withStmt = $conn->prepare($withSql);
    $withStmt->execute($tenantParams);
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
