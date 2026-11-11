<?php
/**
 * API Endpoint: Generate Embedding
 * Generates an OpenAI embedding for a knowledge article and stores it
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/tenancy.php';
require_once '../../includes/knowledge/visibility.php';
require_once '../../includes/rbac.php';
require_once '../../includes/encryption.php';
require_once '../../includes/ai_embeddings.php';   // aiEmbedRequest(), aiEmbeddingText()

// Changes data: never on a GET, which a link on another site can send (csrf.php).
csrfRequireWriteMethod();

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireModuleAccessJson('knowledge');
requireCapabilityJson(Cap::KNOWLEDGE_EMBEDDINGS);   // Knowledge settings tab — see docs/design/rbac.md

$input = json_decode(file_get_contents('php://input'), true);
$articleId = intval($input['article_id'] ?? 0);

if (!$articleId) {
    echo json_encode(['success' => false, 'error' => 'Article ID is required']);
    exit;
}

try {
    $conn = connectToDatabase();

    // Get OpenAI API key
    $keySql = "SELECT setting_value FROM system_settings WHERE setting_key = 'knowledge_openai_api_key'";
    $keyStmt = $conn->prepare($keySql);
    $keyStmt->execute();
    $keyRow = $keyStmt->fetch(PDO::FETCH_ASSOC);

    if (!$keyRow || empty($keyRow['setting_value'])) {
        echo json_encode(['success' => false, 'error' => 'OpenAI API key not configured. Please add it in Knowledge Settings.']);
        exit;
    }

    $apiKey = decryptValue($keyRow['setting_value']);

    // Reads a body by id, so it needs the same ownership check as any other
    // by-id read — an id is a guess away.
    // lifecycle 'any': the published test lives in the query below and stays
    // there, so this guard keeps meaning exactly what it meant before — "may you
    // reach this article at all", now including the audience rung and the access
    // list rather than the company alone.
    if (!knowledgeCanRead($conn, KnowledgeViewer::forAnalyst($conn, (int)$_SESSION["analyst_id"]), $articleId, ["lifecycle" => "any"])) {
        echo json_encode(['success' => false, 'error' => 'Article not found']);
        exit;
    }

    // Get article content
    $articleSql = "SELECT id, title, body FROM knowledge_articles WHERE id = ? AND is_published = 1";
    $articleStmt = $conn->prepare($articleSql);
    $articleStmt->execute([$articleId]);
    $article = $articleStmt->fetch(PDO::FETCH_ASSOC);

    if (!$article) {
        echo json_encode(['success' => false, 'error' => 'Article not found or not published']);
        exit;
    }

    // Title + plain-text body, sent to OpenAI by the shared embedding code.
    $result = aiEmbedRequest(aiEmbeddingText((string)$article['title'], (string)$article['body']), $apiKey);
    if ($result['error'] !== null) {
        echo json_encode(['success' => false, 'error' => $result['error']]);
        exit;
    }
    $embedding = $result['embedding'];

    // Store embedding as JSON
    $embeddingJson = json_encode($embedding);

    $updateSql = "UPDATE knowledge_articles SET embedding = ?, embedding_updated = UTC_TIMESTAMP() WHERE id = ?";
    $updateStmt = $conn->prepare($updateSql);
    $updateStmt->execute([$embeddingJson, $articleId]);

    echo json_encode([
        'success' => true,
        'article_id' => $articleId,
        'dimensions' => count($embedding)
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
