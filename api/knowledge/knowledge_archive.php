<?php
/**
 * API Endpoint: Knowledge article recycle bin operations
 * Actions: list, restore, hard_delete
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/tenancy.php';
require_once '../../includes/knowledge/visibility.php';
require_once '../../includes/services/knowledge.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireModuleAccessJson('knowledge');

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

/**
 * Lists the Knowledge recycle bin - archived articles the caller could see when they
 * were live, most recently deleted first - with the retention period.
 *
 * Purges anything past the retention period first (purgeExpired).
 */
function handleList($conn) {
    // Auto-purge expired items first
    purgeExpired($conn);

    // The recycle bin is scoped like the live list — otherwise deleting an article
    // would make it visible to companies that could never see it when it was live.
    // The recycle bin: the ONE reader that wants archived rows, which is exactly
    // why lifecycle is a separate knob from permission rather than welded into it.
    $viewer = KnowledgeViewer::forAnalyst($conn, (int)$_SESSION["analyst_id"]);
    [$tenantSql, $tenantParams] = knowledgeVisibilitySql($conn, $viewer, "a", ["lifecycle" => "archived"]);

    $sql = "SELECT a.id, a.title, a.created_datetime, a.modified_datetime,
                   a.archived_datetime, a.view_count,
                   COALESCE(author.full_name, '(deleted analyst)') as author_name,
                   archiver.full_name as archived_by_name
            FROM knowledge_articles a
            LEFT JOIN analysts author ON author.id = a.author_id
            LEFT JOIN analysts archiver ON archiver.id = a.archived_by_id
            WHERE a.is_archived = 1" . $tenantSql . "
            ORDER BY a.archived_datetime DESC";

    $stmt = $conn->prepare($sql);
    $stmt->execute($tenantParams);
    $articles = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get retention setting
    $retentionDays = getRetentionDays($conn);

    echo json_encode([
        'success' => true,
        'articles' => $articles,
        'retention_days' => $retentionDays
    ]);
}

/**
 * Restores an archived article through KnowledgeService.
 *
 * @param mixed $input  the decoded JSON request; id is the archived article to restore
 */
function handleRestore($conn, $input) {
    // Thin UI adapter over KnowledgeService (errors bubble to the caller's catch).
    KnowledgeService::restoreArticle($conn, ActorContext::fromSession($conn), (int)($input['id'] ?? 0));
    echo json_encode(['success' => true, 'message' => 'Article restored']);
}

/**
 * Permanently deletes an archived article through KnowledgeService.
 *
 * @param mixed $input  the decoded JSON request; id is the archived article to delete for good
 */
function handleHardDelete($conn, $input) {
    // Thin UI adapter over KnowledgeService (errors bubble to the caller's catch).
    KnowledgeService::purgeArticle($conn, ActorContext::fromSession($conn), (int)($input['id'] ?? 0));
    echo json_encode(['success' => true, 'message' => 'Article permanently deleted']);
}

/**
 * Permanently deletes articles that have been in the recycle bin longer than the
 * retention period, with their versions and tags, then removes tags no article uses any
 * more. A retention of 0 keeps them forever.
 *
 * @writes knowledge_article_versions, knowledge_article_tags, knowledge_articles, knowledge_tags - children first, since the versions foreign key has no cascade
 */
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
        require_once __DIR__ . '/../../includes/services/knowledge_images.php';
        KnowledgeImageService::purgeArticles($conn, $ids);
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

/**
 * Returns how many days an article stays in the recycle bin before it is purged - the
 * knowledge_recycle_bin_days setting, 30 when unset, 0 meaning forever.
 */
function getRetentionDays($conn) {
    $sql = "SELECT setting_value FROM system_settings WHERE setting_key = 'knowledge_recycle_bin_days'";
    $stmt = $conn->prepare($sql);
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? (int)$row['setting_value'] : 30;
}
