<?php
/**
 * API: Delete a morning-check status (snapshots its label onto affected results
 * as orphans first). Thin UI adapter over MorningChecksService (UI-only).
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/services/morning_checks.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireModuleAccessJson('morning-checks');

try {
    $conn = connectToDatabase();
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $r = MorningChecksService::deleteStatus($conn, ActorContext::fromSession($conn), (int)($input['statusId'] ?? 0));
    echo json_encode(['success' => true, 'deleted' => $r['deleted'], 'orphaned' => $r['orphaned']]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
