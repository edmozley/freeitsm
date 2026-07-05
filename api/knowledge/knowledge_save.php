<?php
/**
 * API Endpoint: Save knowledge base article (create or update).
 * Thin UI adapter over KnowledgeService. encryption.php is required so the
 * service's embedding-regeneration step has decryptValue() available.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/encryption.php';
require_once '../../includes/services/knowledge.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    echo json_encode(['success' => false, 'error' => 'Invalid request data']);
    exit;
}

try {
    $conn = connectToDatabase();
    // Map the UI's `body` to the service's canonical `body_html`; the rest of
    // the keys (id, title, tags, owner_id, next_review_date, save_as_version)
    // are already canonical.
    $in = $input;
    if (array_key_exists('body', $input)) {
        $in['body_html'] = $input['body'];
    }
    $res = KnowledgeService::saveArticle($conn, ActorContext::fromSession($conn), $in);
    echo json_encode([
        'success'             => true,
        'article_id'          => $res['id'],
        'message'             => 'Article saved successfully',
        'embedding_generated' => $res['embedding_generated'],
    ]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
