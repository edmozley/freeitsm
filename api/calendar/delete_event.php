<?php
/**
 * API Endpoint: Delete Calendar Event.
 * Thin UI adapter over CalendarService.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/services/calendar.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireModuleAccessJson('calendar');

try {
    $conn  = connectToDatabase();
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    CalendarService::deleteEvent($conn, ActorContext::fromSession($conn), (int)($input['id'] ?? 0));
    echo json_encode(['success' => true, 'message' => 'Event deleted']);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
