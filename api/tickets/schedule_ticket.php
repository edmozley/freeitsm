<?php
/**
 * API Endpoint: Schedule work for a ticket (set/clear work_start_datetime).
 * Thin UI adapter over TicketsService::updateTicket (writeAudit=false).
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/tenancy.php';
require_once '../../includes/services/tickets.php';

header('Content-Type: application/json');
if (!isset($_SESSION['analyst_id'])) { echo json_encode(['success' => false, 'error' => 'Not authenticated']); exit; }
requireModuleAccessJson('tickets');

$input = json_decode(file_get_contents('php://input'), true);
if (!$input || !isset($input['ticket_id'])) { echo json_encode(['success' => false, 'error' => 'Ticket ID required']); exit; }

$ticketId = (int)$input['ticket_id'];
$workStart = $input['work_start_datetime'] ?? null;

try {
    $conn = connectToDatabase();
    TicketsService::updateTicket($conn, ActorContext::fromSession($conn), $ticketId, ['work_start_at' => $workStart], false);
    echo json_encode(['success' => true, 'message' => $workStart ? 'Work scheduled' : 'Schedule cleared']);
} catch (ServiceError $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
