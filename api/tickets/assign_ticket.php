<?php
/**
 * API Endpoint: Update a ticket (department / type / status / origin / priority /
 * assignment / first-time-fix / IT-training).
 * Thin UI adapter over TicketsService::updateTicket. writeAudit=false — the UI
 * writes its audit trail client-side (log_ticket_audit.php). The shared rules
 * (closed_datetime, owner sync, template emails, CSAT, workflow dispatches) run
 * in the service, exactly as this endpoint did inline before.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/tenancy.php';
require_once '../../includes/services/tickets.php';

header('Content-Type: application/json');
if (!isset($_SESSION['analyst_id'])) { echo json_encode(['success' => false, 'error' => 'Not authenticated']); exit; }
requireModuleAccessJson('tickets');

try {
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $ticketId = $data['ticket_id'] ?? null;
    if (!$ticketId) {
        throw new Exception('Ticket ID is required');
    }
    $conn = connectToDatabase();

    // Only the ticket-field keys the detail panel edits (the service ignores the rest).
    $in = [];
    foreach (['department_id', 'ticket_type_id', 'status', 'origin_id', 'first_time_fix', 'it_training_provided', 'priority_id', 'assigned_analyst_id'] as $k) {
        if (array_key_exists($k, $data)) $in[$k] = $data[$k];
    }

    TicketsService::updateTicket($conn, ActorContext::fromSession($conn), (int)$ticketId, $in, false);
    echo json_encode(['success' => true]);
} catch (ServiceError $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
