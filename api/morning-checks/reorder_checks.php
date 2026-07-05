<?php
/**
 * API Endpoint: Reorder Morning Checks.
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

try {
    $conn = connectToDatabase();
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $order = $input['order'] ?? null;
    if (!$order || !is_array($order)) {
        throw new Exception('Order array is required');
    }
    MorningChecksService::reorderChecks($conn, ActorContext::fromSession($conn), $order);
    echo json_encode(['success' => true]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
