<?php
/**
 * API: Manually request a CSAT survey for a ticket.
 *
 * Used by the "Request feedback" toolbar button. Bypasses the auto-trigger
 * (which only fires on close transitions) and the one-per-ticket guard (an
 * analyst clicking the button is a deliberate request, not survey-spam).
 *
 * Refuses if csat_mode is 'off' — settings are still the master switch.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/csat.php';
require_once '../../includes/tenancy.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
$ticketId = (int)($data['ticket_id'] ?? 0);
if ($ticketId <= 0) {
    echo json_encode(['success' => false, 'error' => 'ticket_id required']);
    exit;
}

try {
    $conn = connectToDatabase();
    // Multi-tenancy: don't send a CSAT survey for a ticket in a company this
    // analyst can't access.
    if (!analystCanAccessTicket($conn, (int)$_SESSION['analyst_id'], $ticketId)) {
        echo json_encode(['success' => false, 'error' => 'Ticket not found']);
        exit;
    }
    if (csatGetSetting($conn, 'csat_mode', 'off') === 'off') {
        echo json_encode(['success' => false, 'error' => 'CSAT is turned off — enable it under Tickets → Settings → CSAT first']);
        exit;
    }

    $out = sendCsatSurvey($conn, $ticketId, (int)$_SESSION['analyst_id'], true);
    switch ($out['result']) {
        case CSAT_RESULT_SENT:
            echo json_encode(['success' => true, 'response_id' => $out['response_id']]);
            break;
        case CSAT_RESULT_NO_TEMPLATE:
            echo json_encode(['success' => false, 'error' => 'No active CSAT survey template found. Add one under Tickets → Settings → Email templates with event "CSAT survey" (and remember to include the [csat_link] placeholder in the body).']);
            break;
        case CSAT_RESULT_NO_MAILBOX:
            echo json_encode(['success' => false, 'error' => 'This ticket has no associated mailbox to send from (probably a manual ticket without an email thread).']);
            break;
        case CSAT_RESULT_ALREADY_SENT:
            // Force = true above means this shouldn't actually fire here, but cover it for safety
            echo json_encode(['success' => false, 'error' => 'A survey was already sent for this ticket.']);
            break;
        case CSAT_RESULT_OFF:
        default:
            echo json_encode(['success' => false, 'error' => 'CSAT is turned off — enable it under Tickets → Settings → CSAT first']);
            break;
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    error_log('request_csat.php: ' . $e->getMessage());
}
