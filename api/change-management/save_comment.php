<?php
/**
 * API Endpoint: Add a comment to a change.
 * Thin UI adapter over ChangesService::createComment (append internal comment +
 * audit-trail preview row).
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/services/changes.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireModuleAccessJson('changes');

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$changeId = (int)($input['change_id'] ?? 0);

try {
    $conn = connectToDatabase();
    $commentId = ChangesService::createComment($conn, ActorContext::fromSession($conn), $changeId, ['text' => $input['comment_text'] ?? '']);
    echo json_encode(['success' => true, 'comment_id' => $commentId]);
} catch (ServiceError $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
