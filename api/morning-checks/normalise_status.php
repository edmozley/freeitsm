<?php
/**
 * API: Map a batch of orphan label strings to target StatusIDs.
 * POST body: { mappings: [{ label, statusId }, …] }
 *
 * Thin UI adapter over MorningChecksService (UI-only).
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
    $mappings = isset($input['mappings']) && is_array($input['mappings']) ? $input['mappings'] : null;
    if ($mappings === null) throw new Exception('mappings array is required');
    $updated = MorningChecksService::normaliseStatuses($conn, ActorContext::fromSession($conn), $mappings);
    echo json_encode(['success' => true, 'updated' => $updated]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
