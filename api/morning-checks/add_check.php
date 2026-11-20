<?php
/**
 * API Endpoint: Add New Morning Check.
 * Thin UI adapter over MorningChecksService.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/rbac.php';
require_once '../../includes/services/morning_checks.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireModuleAccessJson('morning-checks');
requireCapabilityJson(Cap::MORNING_CHECKS_CHECKS);   // settings tab — see docs/design/rbac.md

try {
    $conn = connectToDatabase();
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $payload = [
        'name'        => $input['checkName'] ?? '',
        'description' => $input['checkDescription'] ?? '',
        'sort_order'  => $input['sortOrder'] ?? 0,
    ];
    // Only forward grouping/routing when the caller actually sent it — the
    // service reads a present-but-null key as "clear this", so passing the
    // keys unconditionally would wipe an assignment on any partial update.
    if (array_key_exists('groupId', $input))   { $payload['group_id']            = $input['groupId']; }
    if (array_key_exists('analystId', $input)) { $payload['assigned_analyst_id'] = $input['analystId']; }
    // GH #383: { type, days, month_when, month_day }; only when sent, as above.
    if (array_key_exists('schedule', $input))  { $payload['schedule']            = $input['schedule']; }
    MorningChecksService::saveCheck($conn, ActorContext::fromSession($conn), $payload);
    echo json_encode(['success' => true]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
