<?php
/**
 * API Endpoint: Knowledge article recycle bin operations
 * Actions: list, restore, hard_delete
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';

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
                   author.full_name as author_name,
                   archiver.full_name as archived_by_name
            FROM knowledge_articles a
            INNER JOIN analysts author ON author.id = a.author_id
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
    if (empty($input['id'])) {
        echo json_encode(['success' => false, 'error' => 'Article ID required']);
        return;
    }

    $articleId = (int)$input['id'];

    $sql = "UPDATE knowledge_articles
            SET is_archived = 0,
                archived_datetime = NULL,
                archived_by_id = NULL
            WHERE id = ? AND is_archived = 1";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$articleId]);

    if ($stmt->rowCount() === 0) {
        echo json_encode(['success' => false, 'error' => 'Article not found or not archived']);
        return;
    }

    echo json_encode(['success' => true, 'message' => 'Article restored']);
}

function handleHardDelete($conn, $input) {
    if (empty($input['id'])) {
        echo json_encode(['success' => false, 'error' => 'Article ID required']);
        return;
    }

    $articleId = (int)$input['id'];

    // Only allow hard delete on archived articles
    $sql = "DELETE FROM knowledge_articles WHERE id = ? AND is_archived = 1";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$articleId]);

    if ($stmt->rowCount() === 0) {
        echo json_encode(['success' => false, 'error' => 'Article not found or not archived']);
        return;
    }

    // Clean up orphaned tags
    $cleanupSql = "DELETE FROM knowledge_tags
                   WHERE id NOT IN (SELECT DISTINCT tag_id FROM knowledge_article_tags)";
    $conn->exec($cleanupSql);

    echo json_encode(['success' => true, 'message' => 'Article permanently deleted']);
}

function purgeExpired($conn) {
    $days = getRetentionDays($conn);
    if ($days === 0) return; // 0 = keep forever

    $sql = "DELETE FROM knowledge_articles
            WHERE is_archived = 1
            AND archived_datetime < DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? DAY)";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$days]);

    $purged = $stmt->rowCount();
    if ($purged > 0) {
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
