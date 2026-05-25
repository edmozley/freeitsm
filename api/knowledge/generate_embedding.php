<?php
/**
 * API Endpoint: Generate Embedding
 * Generates an OpenAI embedding for a knowledge article and stores it
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/encryption.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

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

    // Get article content
    $articleSql = "SELECT id, title, body FROM knowledge_articles WHERE id = ? AND is_published = 1";
    $articleStmt = $conn->prepare($articleSql);
    $articleStmt->execute([$articleId]);
    $article = $articleStmt->fetch(PDO::FETCH_ASSOC);

    if (!$article) {
        echo json_encode(['success' => false, 'error' => 'Article not found or not published']);
        exit;
    }

    // Prepare text for embedding - combine title and body, strip HTML
    $plainText = strip_tags($article['body']);
    $plainText = html_entity_decode($plainText, ENT_QUOTES, 'UTF-8');
    $plainText = preg_replace('/\s+/', ' ', $plainText);
    $plainText = trim($plainText);

    $textToEmbed = $article['title'] . "\n\n" . $plainText;

    // Truncate if too long (OpenAI has ~8k token limit for embedding models)
    // Roughly 4 chars per token, so ~32k chars max to be safe
    if (strlen($textToEmbed) > 30000) {
        $textToEmbed = substr($textToEmbed, 0, 30000);
    }

    // Call OpenAI Embeddings API
    $requestBody = json_encode([
        'model' => 'text-embedding-3-small',
        'input' => $textToEmbed
    ]);

    $ch = curl_init('https://api.openai.com/v1/embeddings');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $requestBody);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $apiKey
    ]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, SSL_VERIFY_PEER);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        echo json_encode(['success' => false, 'error' => 'Connection error: ' . $curlError]);
        exit;
    }

    $responseData = json_decode($response, true);

    if ($httpCode !== 200) {
        $errorMsg = $responseData['error']['message'] ?? 'API returned HTTP ' . $httpCode;
        echo json_encode(['success' => false, 'error' => 'OpenAI API error: ' . $errorMsg]);
        exit;
    }

    // Extract embedding vector
    $embedding = $responseData['data'][0]['embedding'] ?? null;

    if (!$embedding || !is_array($embedding)) {
        echo json_encode(['success' => false, 'error' => 'Invalid embedding response']);
        exit;
    }

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
