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
// Tickets has no Read only level yet (MODULES_WITH_READ_ONLY), so today this always
// passes. It is the right question to ask of a write all the same (PR #211).
requireModuleWriteJson('tickets');
// Changes data: never on a GET, which a link on another site can send (csrf.php).
csrfRequireWriteMethod();

try {
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $ticketId = $data['ticket_id'] ?? null;
    if (!$ticketId) {
        throw new Exception('Ticket ID is required');
    }
    $conn = connectToDatabase();

    // Only the ticket-field keys the detail panel edits (the service ignores the rest).
    $in = [];
    foreach (['department_id', 'ticket_type_id', 'status', 'origin_id', 'first_time_fix', 'it_training_provided', 'priority_id', 'assigned_analyst_id',
              // Classification (#1540). The service clears a category the ticket
              // type no longer allows, so these two travelling together is fine.
              'category_id', 'closure_category_id', 'resolution_code_id',
              // #1566. Independent of assigned_analyst_id by design — the service
              // never writes one as a side effect of the other.
              'assigned_team_id',
              // Normal / Confidential (discussion #62). Lowering it is only ever done here,
              // by a person; see includes/ticket_sensitivity.php.
              'sensitivity',
              'workspace',
              // GH #311: one of the ticket's company's locations, or a shared one.
              'location_id',
              // ⚠️ NOT a ticket field (#142). A one-off note for the closure
              // email only: nothing stores it, and it is ignored unless this
              // request is the one that closes the ticket.
              'closed_message'] as $k) {
        if (array_key_exists($k, $data)) $in[$k] = $data[$k];
    }

    TicketsService::updateTicket($conn, ActorContext::fromSession($conn), (int)$ticketId, $in, false);

    // The sensitivity AFTER the save (discussion #62). A department move can make a
    // ticket confidential on the server's own initiative, and the open ticket would
    // otherwise keep showing Normal until it was reopened.
    $sensitivity = null;
    if (ticketSensitivityReady($conn)) {
        $sq = $conn->prepare("SELECT sensitivity FROM tickets WHERE id = ?");
        $sq->execute([(int)$ticketId]);
        $sensitivity = ticketSensitivityNormalise($sq->fetchColumn());
    }
    echo json_encode(['success' => true, 'sensitivity' => $sensitivity]);
} catch (ServiceError $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
