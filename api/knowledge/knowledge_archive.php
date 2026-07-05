<?php
/**
 * API Endpoint: Knowledge article recycle bin operations
 * Actions: list, restore, hard_delete
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

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $action = $_GET['action'] ?? 'list';
} else {
    $input = json_decode(file_get_contents('php://input'), true);
    $action = $input['action'] ?? '';
}

try {
    $conn = connectToDatabase();

    switch ($action) {
        case 'list':
            handleList($conn);
            break;
        case 'restore':
            handleRestore($conn, $input);
            break;
        case 'hard_delete':
            handleHardDelete($conn, $input);
            break;
        default:
            echo json_encode(['success' => false, 'error' => 'Invalid action']);
    }

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

function handleList($conn) {
    // Auto-purge expired items first
    purgeExpired($conn);

    $sql = "SELECT a.id, a.title, a.created_datetime, a.modified_datetime,
                   a.archived_datetime, a.view_count,
                   COALESCE(author.full_name, '(deleted analyst)') as author_name,
                   archiver.full_name as archived_by_name
            FROM knowledge_articles a
            LEFT JOIN analysts author ON author.id = a.author_id
            LEFT JOIN analysts archiver ON archiver.id = a.archived_by_id
            WHERE a.is_archived = 1
            ORDER BY a.archived_datetime DESC";

    $stmt = $conn->prepare($sql);
    $stmt->execute();
    $articles = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get retention setting
    $retentionDays = getRetentionDays($conn);

    echo json_encode([
        'success' => true,
        'articles' => $articles,
        'retention_days' => $retentionDays
    ]);
}

function handleRestore($conn, $input) {
    // Thin UI adapter over KnowledgeService (errors bubble to the caller's catch).
    KnowledgeService::restoreArticle($conn, ActorContext::fromSession($conn), (int)($input['id'] ?? 0));
    echo json_encode(['success' => true, 'message' => 'Article restored']);
}

function handleHardDelete($conn, $input) {
    // Thin UI adapter over KnowledgeService (errors bubble to the caller's catch).
    KnowledgeService::purgeArticle($conn, ActorContext::fromSession($conn), (int)($input['id'] ?? 0));
    echo json_encode(['success' => true, 'message' => 'Article permanently deleted']);
}

function purgeExpired($conn) {
    $days = getRetentionDays($conn);
    if ($days === 0) return; // 0 = keep forever

    // Delete children first (versions FK has no cascade; grown installs may
    // lack the knowledge FKs entirely — see handleHardDelete).
    $idsStmt = $conn->prepare(
        "SELECT id FROM knowledge_articles
         WHERE is_archived = 1
         AND archived_datetime < DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? DAY)"
    );
    $idsStmt->execute([$days]);
    $ids = $idsStmt->fetchAll(PDO::FETCH_COLUMN);

    if ($ids) {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $conn->prepare("DELETE FROM knowledge_article_versions WHERE article_id IN ($placeholders)")->execute($ids);
        $conn->prepare("DELETE FROM knowledge_article_tags WHERE article_id IN ($placeholders)")->execute($ids);
        $conn->prepare("DELETE FROM knowledge_articles WHERE id IN ($placeholders)")->execute($ids);

        // Clean up orphaned tags after purge
        $cleanupSql = "DELETE FROM knowledge_tags
                       WHERE id NOT IN (SELECT DISTINCT tag_id FROM knowledge_article_tags)";
        $conn->exec($cleanupSql);
    }
}

function getRetentionDays($conn) {
    $sql = "SELECT setting_value FROM system_settings WHERE setting_key = 'knowledge_recycle_bin_days'";
    $stmt = $conn->prepare($sql);
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? (int)$row['setting_value'] : 30;
}
