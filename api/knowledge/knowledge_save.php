<?php
/**
 * API Endpoint: Save knowledge base article (create or update)
 */
session_start();
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/encryption.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$analystId = (int)$_SESSION['analyst_id'];

$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    echo json_encode(['success' => false, 'error' => 'Invalid request data']);
    exit;
}

$articleId = !empty($input['id']) ? (int)$input['id'] : null;
$title = trim($input['title'] ?? '');
$body = $input['body'] ?? '';
$tags = $input['tags'] ?? [];
$ownerId = !empty($input['owner_id']) ? (int)$input['owner_id'] : null;
$nextReviewDate = !empty($input['next_review_date']) ? $input['next_review_date'] : null;
$saveAsVersion = !empty($input['save_as_version']);

if (empty($title)) {
    echo json_encode(['success' => false, 'error' => 'Title is required']);
    exit;
}

try {
    $conn = connectToDatabase();
    $conn->beginTransaction();

    if ($articleId) {
        if ($saveAsVersion) {
            // Snapshot current article content into version history
            $snapSql = "INSERT INTO knowledge_article_versions (article_id, version, title, body, saved_by_id, saved_datetime)
                        SELECT id, version, title, body, ?, UTC_TIMESTAMP()
                        FROM knowledge_articles WHERE id = ?";
            $snapStmt = $conn->prepare($snapSql);
            $snapStmt->execute([$analystId, $articleId]);

            // Update article with new content and increment version
            $sql = "UPDATE knowledge_articles
                    SET title = ?, body = ?, owner_id = ?, next_review_date = ?, modified_datetime = UTC_TIMESTAMP(), version = version + 1
                    WHERE id = ?";
        } else {
            // Normal update (no version change)
            $sql = "UPDATE knowledge_articles
                    SET title = ?, body = ?, owner_id = ?, next_review_date = ?, modified_datetime = UTC_TIMESTAMP()
                    WHERE id = ?";
        }
        $stmt = $conn->prepare($sql);
        $stmt->execute([$title, $body, $ownerId, $nextReviewDate, $articleId]);
    } else {
        // Create new article
        $sql = "INSERT INTO knowledge_articles (title, body, author_id, owner_id, next_review_date, created_datetime, modified_datetime, is_published, view_count)
                VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP(), 1, 0)";
        $stmt = $conn->prepare($sql);
        $stmt->execute([$title, $body, $analystId, $ownerId, $nextReviewDate]);
        $articleId = $conn->lastInsertId();
    }

    // Handle tags
    // First, remove existing tag associations
    $deleteSql = "DELETE FROM knowledge_article_tags WHERE article_id = ?";
    $deleteStmt = $conn->prepare($deleteSql);
    $deleteStmt->execute([$articleId]);

    // Process each tag
    foreach ($tags as $tagName) {
        $tagName = trim($tagName);
        if (empty($tagName)) continue;

        // Check if tag exists
        $tagSql = "SELECT id FROM knowledge_tags WHERE name = ?";
        $tagStmt = $conn->prepare($tagSql);
        $tagStmt->execute([$tagName]);
        $existingTag = $tagStmt->fetch(PDO::FETCH_ASSOC);

        if ($existingTag) {
            $tagId = $existingTag['id'];
        } else {
            // Create new tag
            $createTagSql = "INSERT INTO knowledge_tags (name, created_datetime) VALUES (?, UTC_TIMESTAMP())";
            $createTagStmt = $conn->prepare($createTagSql);
            $createTagStmt->execute([$tagName]);
            $tagId = $conn->lastInsertId();
        }

        // Link tag to article
        $linkSql = "INSERT INTO knowledge_article_tags (article_id, tag_id) VALUES (?, ?)";
        $linkStmt = $conn->prepare($linkSql);
        $linkStmt->execute([$articleId, $tagId]);
    }

    $conn->commit();

    // Auto-generate embedding if OpenAI API key is configured
    // This is done after commit to not block the save operation
    $embeddingGenerated = false;
    try {
        $openaiSql = "SELECT setting_value FROM system_settings WHERE setting_key = 'knowledge_openai_api_key'";
        $openaiStmt = $conn->prepare($openaiSql);
        $openaiStmt->execute();
        $openaiRow = $openaiStmt->fetch(PDO::FETCH_ASSOC);

        if ($openaiRow && !empty($openaiRow['setting_value'])) {
            $openaiApiKey = decryptValue($openaiRow['setting_value']);

            // Prepare text for embedding
            $plainText = strip_tags($body);
            $plainText = html_entity_decode($plainText, ENT_QUOTES, 'UTF-8');
            $plainText = preg_replace('/\s+/', ' ', $plainText);
            $plainText = trim($plainText);
            $textToEmbed = $title . "\n\n" . $plainText;

            if (strlen($textToEmbed) > 30000) {
                $textToEmbed = substr($textToEmbed, 0, 30000);
            }

            // Call OpenAI API
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
                'Authorization: Bearer ' . $openaiApiKey
            ]);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, SSL_VERIFY_PEER);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode === 200) {
                $responseData = json_decode($response, true);
                $embedding = $responseData['data'][0]['embedding'] ?? null;

                if ($embedding && is_array($embedding)) {
                    $embeddingJson = json_encode($embedding);
                    $updateSql = "UPDATE knowledge_articles SET embedding = ?, embedding_updated = UTC_TIMESTAMP() WHERE id = ?";
                    $updateStmt = $conn->prepare($updateSql);
                    $updateStmt->execute([$embeddingJson, $articleId]);
                    $embeddingGenerated = true;
                }
            }
        }
    } catch (Exception $embeddingError) {
        // Embedding generation failed, but article was saved - continue silently
    }

    echo json_encode([
        'success' => true,
        'article_id' => $articleId,
        'message' => 'Article saved successfully',
        'embedding_generated' => $embeddingGenerated
    ]);

} catch (Exception $e) {
    if (isset($conn)) {
        $conn->rollBack();
    }
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}

?>
