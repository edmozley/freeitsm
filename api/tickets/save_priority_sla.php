<?php
/**
 * API: Save the SLA fields on a ticket_priorities row
 * (sla_response_minutes, sla_resolution_minutes, sla_calendar_id).
 *
 * POST JSON: { id, sla_response_minutes, sla_resolution_minutes, sla_calendar_id }
 *
 * Doesn't touch the other ticket_priorities columns (name, colour, etc.) — that's
 * a different endpoint. Pass nulls for minutes to clear targets; pass null
 * sla_calendar_id to detach the calendar.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

try {
    $data = json_decode(file_get_contents('php://input'), true);
    $id = isset($data['id']) ? (int)$data['id'] : 0;
    if (!$id) throw new Exception('Priority id required');

    $response   = isset($data['sla_response_minutes'])   && $data['sla_response_minutes']   !== '' ? max(0, (int)$data['sla_response_minutes'])   : null;
    $resolution = isset($data['sla_resolution_minutes']) && $data['sla_resolution_minutes'] !== '' ? max(0, (int)$data['sla_resolution_minutes']) : null;
    $calendarId = isset($data['sla_calendar_id'])        && $data['sla_calendar_id']        !== '' ? (int)$data['sla_calendar_id']                : null;

    $conn = connectToDatabase();

    // Validate calendar exists if one was passed (FK will catch it too but a friendly error reads better)
    if ($calendarId !== null) {
        $check = $conn->prepare("SELECT COUNT(*) FROM sla_calendars WHERE id = ? AND is_active = 1");
        $check->execute([$calendarId]);
        if ((int)$check->fetchColumn() === 0) throw new Exception('Calendar not found');
    }

    $stmt = $conn->prepare("UPDATE ticket_priorities
                            SET sla_response_minutes = ?, sla_resolution_minutes = ?, sla_calendar_id = ?
                            WHERE id = ?");
    $stmt->execute([$response, $resolution, $calendarId, $id]);

    echo json_encode(['success' => true]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
