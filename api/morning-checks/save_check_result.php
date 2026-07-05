<?php
/**
 * API Endpoint: Save Morning Check Result.
 * Thin UI adapter over MorningChecksService (upsert: one result per check per date).
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
    MorningChecksService::recordResult($conn, ActorContext::fromSession($conn), [
        'check_id'  => $input['checkId'] ?? null,
        'status_id' => $input['statusId'] ?? null,
        'notes'     => $input['notes'] ?? '',
        'date'      => $input['checkDate'] ?? null,   // null -> service defaults to today
    ]);
    echo json_encode(['success' => true]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
