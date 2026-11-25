<?php
/**
 * API: Knowledge articles on network diagrams (GitHub #433). Thin adapter over
 * NetworkArticleService - see includes/services/network_articles.php for the rules.
 *
 *   GET  ?q=text                           the "Link to article" picker: articles you can read
 *   GET  ?article_id=N                     the current diagrams that use article N (for the
 *                                          Knowledge reader; empty without Network Mapper)
 *   POST {action:'add',    diagram_id, article_id}   add to the diagram's related articles
 *   POST {action:'remove', diagram_id, article_id}   take it off again
 *
 * The article lookup is asked from Knowledge too, so it answers with an empty list - not
 * an error - for someone without the Network Mapper module. Everything else needs it.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/services/network_articles.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
$analystId = (int)$_SESSION['analyst_id'];

try {
    $conn = connectToDatabase();
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    if ($method === 'GET' && isset($_GET['article_id'])) {
        echo json_encode(['success' => true, 'diagrams' => NetworkArticleService::diagramsForArticle($conn, $analystId, (int)$_GET['article_id'])]);
        exit;
    }

    requireModuleAccessJson('network-mapper');

    if ($method === 'GET') {
        echo json_encode(['success' => true, 'can_read' => analystCanAccessModule($conn, $analystId, 'knowledge'),
                          'articles' => NetworkArticleService::search($conn, $analystId, (string)($_GET['q'] ?? ''))]);
        exit;
    }
    if ($method !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Unknown action']);
        exit;
    }
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $action = (string)($data['action'] ?? '');
    $diagramId = (int)($data['diagram_id'] ?? 0);
    $articleId = (int)($data['article_id'] ?? 0);
    if ($action === 'add') {
        NetworkArticleService::addRelated($conn, ActorContext::fromSession($conn), $diagramId, $articleId);
    } elseif ($action === 'remove') {
        NetworkArticleService::removeRelated($conn, $diagramId, $articleId);
    } else {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Unknown action']);
        exit;
    }
    echo json_encode(['success' => true] + NetworkArticleService::forDiagram($conn, $analystId, $diagramId));
} catch (ServiceError $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
