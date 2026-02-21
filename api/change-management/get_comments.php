<?php
/**
 * API Endpoint: Get comments for a change
 */
session_start();
require_once '../../config.php';
require_once '../../includes/functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$changeId = (int)($_GET['change_id'] ?? 0);

if (!$changeId) {
    echo json_encode(['success' => false, 'error' => 'Change ID required']);
    exit;
}

try {
    $conn = connectToDatabase();

    $sql = "SELECT c.id, c.change_id, c.analyst_id, c.comment_text, c.is_internal,
                   c.created_datetime, a.full_name as analyst_name
            FROM change_comments c
            LEFT JOIN analysts a ON c.analyst_id = a.id
            WHERE c.change_id = ?
            ORDER BY c.created_datetime DESC";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$changeId]);
    $comments = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'comments' => $comments]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
