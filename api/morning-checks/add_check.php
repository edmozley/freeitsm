<?php
/**
 * API Endpoint: Add New Morning Check.
 * Thin UI adapter over MorningChecksService.
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
    MorningChecksService::saveCheck($conn, ActorContext::fromSession($conn), [
        'name'        => $input['checkName'] ?? '',
        'description' => $input['checkDescription'] ?? '',
        'sort_order'  => $input['sortOrder'] ?? 0,
    ]);
    echo json_encode(['success' => true]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
