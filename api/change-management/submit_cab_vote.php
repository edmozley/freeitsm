<?php
/**
 * API Endpoint: Submit a CAB vote.
 * Thin UI adapter over ChangesService::voteCab — records the vote and applies
 * the auto-transition (any required Reject → Draft; the all/majority threshold
 * on required members → Approved, firing change.approved).
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
    $res = ChangesService::voteCab($conn, ActorContext::fromSession($conn), $changeId, [
        'vote'    => $input['vote'] ?? '',
        'comment' => $input['vote_comment'] ?? '',
    ]);
    echo json_encode([
        'success'        => true,
        'vote'           => $res['vote'],
        'status_changed' => $res['status_changed'],
        'new_status'     => $res['new_status'],
    ]);
} catch (ServiceError $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
