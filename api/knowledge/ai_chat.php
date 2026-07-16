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
require_once '../../includes/ai_settings.php';
require_once '../../includes/tenancy.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

// Reachable from more than one module, so a single-module gate would break the others —
// but it had NO module check at all, and it spends the AI budget. (Found by D005.)
requireAnyModuleAccessJson(['knowledge', 'tickets']);

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

    // Load the configured chat provider/model/key (Anthropic / OpenAI / OpenRouter)
    // via the shared AI settings building block.
    $aiCfg = aiSettingsLoad($conn, 'knowledge_ai');
    if (($aiCfg['api_key'] ?? '') === '') {
        echo json_encode(['success' => false, 'error' => 'AI API key not configured. Please add it in Knowledge Settings.']);
        exit;
    }

    // Get OpenAI API key for embeddings
    $openaiSql = "SELECT setting_value FROM system_settings WHERE setting_key = 'knowledge_openai_api_key'";
    $openaiStmt = $conn->prepare($openaiSql);
    $openaiStmt->execute();
    $openaiRow = $openaiStmt->fetch(PDO::FETCH_ASSOC);
    $openaiApiKey = decryptValue($openaiRow['setting_value'] ?? '');

    // Check if we have articles with embeddings
    $archiveFilter = $includeArchived ? '' : ' AND (is_archived = 0 OR is_archived IS NULL)';

    // Company scope. This is an ANALYST asking, so there is no audience filter —
    // the ladder only holds back customers and the public.
    // NOTE: this file still carries its own copy of the embedding/cosine code that
    // includes/knowledge/kb_ai.php factored out (see the note at the top of that
    // file). It is not consolidated here because kbRetrieveArticles has no
    // include-archived mode, and rewriting a working AI path was not worth bundling
    // into a security fix. The tenant filter is applied to BOTH copies instead.
    [$tenantSql, $tenantParams] = knowledgeTenantFilter($conn, (int)$_SESSION['analyst_id'], '');

    $embeddingCountSql = "SELECT COUNT(*) as count FROM knowledge_articles WHERE is_published = 1" . $archiveFilter . $tenantSql . " AND embedding IS NOT NULL AND LENGTH(embedding) > 0";
    $embeddingCountStmt = $conn->prepare($embeddingCountSql);
    $embeddingCountStmt->execute($tenantParams);
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
                          WHERE is_published = 1" . $archiveFilter . $tenantSql . " AND embedding IS NOT NULL AND LENGTH(embedding) > 0";
            $articleStmt = $conn->prepare($articleSql);
            $articleStmt->execute($tenantParams);
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
        $articleSql = "SELECT id, title, body FROM knowledge_articles WHERE is_published = 1" . $archiveFilter . $tenantSql . " ORDER BY title";
        $articleStmt = $conn->prepare($articleSql);
        $articleStmt->execute($tenantParams);
        $articles = $articleStmt->fetchAll(PDO::FETCH_ASSOC);
    }

    if (empty($articles)) {
        // These counts are reported back to the user in the messages below, so they
        // must be scoped too — an unscoped count tells one company how many articles
        // another company has.
        $totalSql = "SELECT COUNT(*) as total FROM knowledge_articles WHERE 1=1" . $tenantSql;
        $totalStmt = $conn->prepare($totalSql);
        $totalStmt->execute($tenantParams);
        $totalCount = $totalStmt->fetch(PDO::FETCH_ASSOC)['total'];

        $publishedSql = "SELECT COUNT(*) as published FROM knowledge_articles WHERE is_published = 1" . $tenantSql;
        $publishedStmt = $conn->prepare($publishedSql);
        $publishedStmt->execute($tenantParams);
        $publishedCount = $publishedStmt->fetch(PDO::FETCH_ASSOC)['published'];

        // Check how many are available after the archive filter
        $availableSql = "SELECT COUNT(*) as available FROM knowledge_articles WHERE is_published = 1" . $archiveFilter . $tenantSql;
        $availableStmt = $conn->prepare($availableSql);
        $availableStmt->execute($tenantParams);
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

    // Call the configured AI provider via the shared client (one-shot, no streaming).
    try {
        $aiResult = aiProviderChat($aiCfg, [
            'system'     => $systemPrompt,
            'user'       => $question,
            'max_tokens' => 1024,
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => 'AI error: ' . $e->getMessage()]);
        exit;
    }

    $answer = $aiResult['content'] !== '' ? $aiResult['content'] : 'No response received';

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
