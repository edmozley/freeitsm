<?php
/**
 * API Endpoint: Archive (soft delete) knowledge base article
 * Moves article to recycle bin instead of permanently deleting
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

if (!$input || empty($input['id'])) {
    echo json_encode(['success' => false, 'error' => 'Article ID required']);
    exit;
}

$articleId = (int)$input['id'];
$analystId = (int)$_SESSION['analyst_id'];

try {
    $conn = connectToDatabase();

    // Soft-archive: move to recycle bin
    $sql = "UPDATE knowledge_articles
            SET is_archived = 1,
                archived_datetime = UTC_TIMESTAMP(),
                archived_by_id = ?
            WHERE id = ? AND (is_archived = 0 OR is_archived IS NULL)";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$analystId, $articleId]);

    if ($stmt->rowCount() === 0) {
        echo json_encode(['success' => false, 'error' => 'Article not found or already archived']);
        exit;
    }

    echo json_encode([
        'success' => true,
        'message' => 'Article moved to recycle bin'
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
