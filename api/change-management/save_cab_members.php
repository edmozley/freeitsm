<?php
/**
 * API Endpoint: Sync CAB members for a change.
 * Thin UI adapter over ChangesService::saveCab (diff-syncs the roster: adds,
 * removes, and required/optional changes, each with an audit row).
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
if (!$changeId) {
    echo json_encode(['success' => false, 'error' => 'Change ID required']);
    exit;
}

try {
    $conn = connectToDatabase();
    ChangesService::saveCab($conn, ActorContext::fromSession($conn), $changeId, $input['members'] ?? []);
    echo json_encode(['success' => true]);
} catch (ServiceError $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
