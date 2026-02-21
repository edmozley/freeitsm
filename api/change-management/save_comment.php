<?php
/**
 * API Endpoint: Add a comment to a change
 */
session_start();
require_once '../../config.php';
require_once '../../includes/functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$analystId = (int)$_SESSION['analyst_id'];
$input = json_decode(file_get_contents('php://input'), true);

$changeId = (int)($input['change_id'] ?? 0);
$commentText = trim($input['comment_text'] ?? '');

if (!$changeId || empty($commentText)) {
    echo json_encode(['success' => false, 'error' => 'Change ID and comment text are required']);
    exit;
}

try {
    $conn = connectToDatabase();

    // Insert comment
    $sql = "INSERT INTO change_comments (change_id, analyst_id, comment_text, is_internal, created_datetime)
            VALUES (?, ?, ?, 1, UTC_TIMESTAMP())";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$changeId, $analystId, $commentText]);
    $commentId = $conn->lastInsertId();

    // Log to audit trail
    $auditSql = "INSERT INTO change_audit (change_id, analyst_id, action_type, new_value, created_datetime)
                 VALUES (?, ?, 'comment', ?, UTC_TIMESTAMP())";
    $auditStmt = $conn->prepare($auditSql);
    $preview = mb_strlen($commentText) > 100 ? mb_substr($commentText, 0, 100) . '...' : $commentText;
    $auditStmt->execute([$changeId, $analystId, $preview]);

    echo json_encode(['success' => true, 'comment_id' => $commentId]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
