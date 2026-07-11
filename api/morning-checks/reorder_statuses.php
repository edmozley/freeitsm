<?php
/**
 * API: Reorder morning-check statuses (positions become 10, 20, 30, …).
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
    $order = isset($input['order']) && is_array($input['order']) ? $input['order'] : null;
    if ($order === null) throw new Exception('order array is required');
    MorningChecksService::reorderStatuses($conn, ActorContext::fromSession($conn), $order);
    echo json_encode(['success' => true]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
