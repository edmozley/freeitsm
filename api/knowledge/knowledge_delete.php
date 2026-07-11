<?php
/**
 * API Endpoint: Archive (soft delete) knowledge base article.
 * Thin UI adapter over KnowledgeService — moves the article to the recycle bin.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/services/knowledge.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireModuleAccessJson('knowledge');

try {
    $conn = connectToDatabase();
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    KnowledgeService::archiveArticle($conn, ActorContext::fromSession($conn), (int)($input['id'] ?? 0));
    echo json_encode(['success' => true, 'message' => 'Article moved to recycle bin']);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
