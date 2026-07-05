<?php
/**
 * API Endpoint: Delete a comment from a change.
 * Thin UI adapter over ChangesService::deleteComment (unscoped — by comment id
 * alone, as this endpoint carries no change id).
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

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$id = (int)($input['id'] ?? 0);
if (!$id) {
    echo json_encode(['success' => false, 'error' => 'Comment ID required']);
    exit;
}

try {
    $conn = connectToDatabase();
    ChangesService::deleteComment($conn, ActorContext::fromSession($conn), $id, null);
    echo json_encode(['success' => true]);
} catch (ServiceError $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
