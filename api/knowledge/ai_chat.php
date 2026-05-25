<?php
/**
 * API Endpoint: AI Chat - Ask questions about knowledge base articles
 * Uses Claude Haiku to answer questions based solely on knowledge article content
 * Supports vector similarity search for finding relevant articles (if embeddings are configured)
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
$question = trim($input['question'] ?? '');
$includeArchived = !empty($input['include_archived']);

if (empty($question)) {
    echo json_encode(['success' => false, 'error' => 'Please enter a question']);
    exit;
}

/**
 * Calculate cosine similarity between two vectors
 */
function cosineSimilarity($vec1, $vec2) {
    $dotProduct = 0;
    $norm1 = 0;
    $norm2 = 0;

    $len = min(count($vec1), count($vec2));
    for ($i = 0; $i < $len; $i++) {
        $dotProduct += $vec1[$i] * $vec2[$i];
        $norm1 += $vec1[$i] * $vec1[$i];
        $norm2 += $vec2[$i] * $vec2[$i];
    }

    if ($norm1 == 0 || $norm2 == 0) return 0;
    return $dotProduct / (sqrt($norm1) * sqrt($norm2));
}

/**
 * Generate embedding for text using OpenAI API
 */
function generateEmbedding($text, $apiKey) {
    // Truncate if too long
    if (strlen($text) > 30000) {
        $text = substr($text, 0, 30000);
    }

    $requestBody = json_encode([
        'model' => 'text-embedding-3-small',
        'input' => $text
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
    curl_close($ch);

    if ($httpCode !== 200) {
        return null;
    }

    $responseData = json_decode($response, true);
    return $responseData['data'][0]['embedding'] ?? null;
}

try {
    $conn = connectToDatabase();

    // Get Claude API key
    $keySql = "SELECT setting_value FROM system_settings WHERE setting_key = 'knowledge_ai_api_key'";
    $keyStmt = $conn->prepare($keySql);
    $keyStmt->execute();
    $keyRow = $keyStmt->fetch(PDO::FETCH_ASSOC);

    if (!$keyRow || empty($keyRow['setting_value'])) {
        echo json_encode(['success' => false, 'error' => 'AI API key not configured. Please add it in Knowledge Settings.']);
        exit;
    }

    $claudeApiKey = decryptValue($keyRow['setting_value']);

    // Get OpenAI API key for embeddings
    $openaiSql = "SELECT setting_value FROM system_settings WHERE setting_key = 'knowledge_openai_api_key'";
    $openaiStmt = $conn->prepare($openaiSql);
    $openaiStmt->execute();
    $openaiRow = $openaiStmt->fetch(PDO::FETCH_ASSOC);
    $openaiApiKey = decryptValue($openaiRow['setting_value'] ?? '');

    // Check if we have articles with embeddings
    $archiveFilter = $includeArchived ? '' : ' AND (is_archived = 0 OR is_archived IS NULL)';
    $embeddingCountSql = "SELECT COUNT(*) as count FROM knowledge_articles WHERE is_published = 1" . $archiveFilter . " AND embedding IS NOT NULL AND LENGTH(embedding) > 0";
    $embeddingCountStmt = $conn->prepare($embeddingCountSql);
    $embeddingCountStmt->execute();
    $embeddingCount = $embeddingCountStmt->fetch(PDO::FETCH_ASSOC)['count'];

    $useVectorSearch = !empty($openaiApiKey) && $embeddingCount > 0;
    $articles = [];
    $searchMethod = 'all';

    if ($useVectorSearch) {
        // Vector similarity search
        $searchMethod = 'vector';

        // Generate embedding for the question
        $questionEmbedding = generateEmbedding($question, $openaiApiKey);

        if ($questionEmbedding) {
            // Fetch all articles with embeddings
            $articleSql = "SELECT id, title, body, embedding
                          FROM knowledge_articles
                          WHERE is_published = 1" . $archiveFilter . " AND embedding IS NOT NULL AND LENGTH(embedding) > 0";
            $articleStmt = $conn->prepare($articleSql);
            $articleStmt->execute();
            $allArticles = $articleStmt->fetchAll(PDO::FETCH_ASSOC);

            // Calculate similarity scores
            $scoredArticles = [];
            foreach ($allArticles as $article) {
                $articleEmbedding = json_decode($article['embedding'], true);
                if ($articleEmbedding) {
                    $similarity = cosineSimilarity($questionEmbedding, $articleEmbedding);
                    $scoredArticles[] = [
                        'id' => $article['id'],
                        'title' => $article['title'],
                        'body' => $article['body'],
                        'similarity' => $similarity
                    ];
                }
            }

            // Sort by similarity (highest first)
            usort($scoredArticles, function($a, $b) {
                return $b['similarity'] <=> $a['similarity'];
            });

            // Take top 5 most relevant articles
            $articles = array_slice($scoredArticles, 0, 5);
        } else {
            // Embedding generation failed, fall back to all articles
            $useVectorSearch = false;
        }
    }

    if (!$useVectorSearch) {
        // Fallback: fetch all published articles
        $searchMethod = 'all';
        $articleSql = "SELECT id, title, body FROM knowledge_articles WHERE is_published = 1" . $archiveFilter . " ORDER BY title";
        $articleStmt = $conn->prepare($articleSql);
        $articleStmt->execute();
        $articles = $articleStmt->fetchAll(PDO::FETCH_ASSOC);
    }

    if (empty($articles)) {
        // Check if there are any articles at all (regardless of published status)
        $totalSql = "SELECT COUNT(*) as total FROM knowledge_articles";
        $totalStmt = $conn->prepare($totalSql);
        $totalStmt->execute();
        $totalCount = $totalStmt->fetch(PDO::FETCH_ASSOC)['total'];

        $publishedSql = "SELECT COUNT(*) as published FROM knowledge_articles WHERE is_published = 1";
        $publishedStmt = $conn->prepare($publishedSql);
        $publishedStmt->execute();
        $publishedCount = $publishedStmt->fetch(PDO::FETCH_ASSOC)['published'];

        // Check how many are available after the archive filter
        $availableSql = "SELECT COUNT(*) as available FROM knowledge_articles WHERE is_published = 1" . $archiveFilter;
        $availableStmt = $conn->prepare($availableSql);
        $availableStmt->execute();
        $availableCount = $availableStmt->fetch(PDO::FETCH_ASSOC)['available'];

        if ($totalCount == 0) {
            echo json_encode(['success' => false, 'error' => 'No knowledge articles found in the database.']);
        } elseif ($publishedCount == 0) {
            echo json_encode(['success' => false, 'error' => "Found {$totalCount} article(s) but none are published. Please publish your articles to enable AI search."]);
        } elseif ($availableCount == 0 && !$includeArchived) {
            echo json_encode(['success' => false, 'error' => "All {$publishedCount} published article(s) are archived. Enable \"Include archived articles\" to search them."]);
        } else {
            echo json_encode(['success' => false, 'error' => "Found {$availableCount} published article(s) but none have embeddings. Please generate embeddings in Knowledge Settings."]);
        }
        exit;
    }

    // Build context from articles - strip HTML to plain text
    $context = "";
    foreach ($articles as $article) {
        $plainText = strip_tags($article['body']);
        $plainText = html_entity_decode($plainText, ENT_QUOTES, 'UTF-8');
        $plainText = preg_replace('/\s+/', ' ', $plainText);
        $plainText = trim($plainText);

        if (!empty($plainText)) {
            $context .= "=== Article: " . $article['title'] . " (ID: " . $article['id'] . ") ===\n";
            $context .= $plainText . "\n\n";
        }
    }

    // Call Claude API
    $systemPrompt = "You are a helpful IT support assistant. You answer questions ONLY based on the knowledge base articles provided below. " .
        "If the answer cannot be found in the articles, say so clearly - do not make up information or use outside knowledge. " .
        "When you reference information, always mention the source article by its exact title in quotes, e.g. \"Article Title Here\". " .
        "Keep your answers concise and practical.\n\n" .
        "KNOWLEDGE BASE ARTICLES:\n" . $context;

    $requestBody = json_encode([
        'model' => 'claude-haiku-4-5-20251001',
        'max_tokens' => 1024,
        'system' => $systemPrompt,
        'messages' => [
            ['role' => 'user', 'content' => $question]
        ]
    ]);

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $requestBody);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'x-api-key: ' . $claudeApiKey,
        'anthropic-version: 2023-06-01'
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
        echo json_encode(['success' => false, 'error' => 'Claude API error: ' . $errorMsg]);
        exit;
    }

    $answer = $responseData['content'][0]['text'] ?? 'No response received';

    // Build article lookup for frontend linking
    $articleList = array_map(function($a) {
        return ['id' => $a['id'], 'title' => $a['title']];
    }, $articles);

    echo json_encode([
        'success' => true,
        'answer' => $answer,
        'articles_searched' => count($articles),
        'articles' => $articleList,
        'search_method' => $searchMethod
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
