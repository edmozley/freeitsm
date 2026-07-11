<?php
/**
 * API Endpoint: Save Calendar Event.
 * Thin UI adapter over CalendarService — creates or updates an event.
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
    // Map the UI's start_datetime/end_datetime to the service's start_at/end_at;
    // the rest of the keys are already canonical.
    $in = [
        'title'       => $input['title'] ?? '',
        'description' => $input['description'] ?? '',
        'category_id' => $input['category_id'] ?? null,
        'start_at'    => $input['start_datetime'] ?? null,
        'end_at'      => $input['end_datetime'] ?? null,
        'all_day'     => $input['all_day'] ?? 0,
        'location'    => $input['location'] ?? '',
        'contract_id' => $input['contract_id'] ?? null,
    ];
    if (!empty($input['id'])) {
        $in['id'] = (int)$input['id'];
    }
    $res = CalendarService::saveEvent($conn, ActorContext::fromSession($conn), $in);
    echo json_encode([
        'success' => true,
        'message' => $res['created'] ? 'Event created' : 'Event updated',
        'id'      => $res['id'],
    ]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
