<?php
/**
 * API Endpoint: Create a new ticket manually.
 * Thin UI adapter over TicketsService::createTicket — the initial email row,
 * audit, and ticket.created workflow live there, shared with POST /api/v1/tickets.
 * The acting company is the analyst's active tenant; a manual ticket auto-assigns
 * to the creator unless another analyst is chosen.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/tenancy.php';
require_once '../../includes/services/tickets.php';

header('Content-Type: application/json');
if (!isset($_SESSION['analyst_id'])) { echo json_encode(['success' => false, 'error' => 'Not authenticated']); exit; }
requireModuleAccessJson('tickets');

$analystId = (int)$_SESSION['analyst_id'];
$analystName = $_SESSION['analyst_name'] ?? 'Unknown';

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) { echo json_encode(['success' => false, 'error' => 'Invalid request data']); exit; }

// Preserve the manual-create form's field-specific validation messages.
$fromName  = trim($input['from_name'] ?? '');
$fromEmail = trim($input['from_email'] ?? '');
$subject   = trim($input['subject'] ?? '');
if ($fromName === '')  { echo json_encode(['success' => false, 'error' => 'Requester name is required']); exit; }
if ($fromEmail === '') { echo json_encode(['success' => false, 'error' => 'Requester email is required']); exit; }
if (!filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) { echo json_encode(['success' => false, 'error' => 'Invalid email address']); exit; }
if ($subject === '')   { echo json_encode(['success' => false, 'error' => 'Subject is required']); exit; }

try {
    $conn = connectToDatabase();
    $ctx = ActorContext::fromSession($conn);
    $tenantId = getActiveTenantId($conn, $analystId);

    // Map the manual-create payload onto the service's canonical keys.
    $in = [
        'subject'             => $subject,
        'description'         => $input['body'] ?? '',
        'requester_email'     => $fromEmail,
        'requester_name'      => $fromName,
        'priority'            => $input['priority'] ?? 'Normal',
        'department_id'       => $input['department_id'] ?? null,
        'ticket_type_id'      => $input['ticket_type_id'] ?? null,
        'mailbox_id'          => $input['mailbox_id'] ?? null,
    ];
    if (!empty($input['assigned_analyst_id'])) {
        $in['assigned_analyst_id'] = (int)$input['assigned_analyst_id'];
    }

    $ticketId = TicketsService::createTicket($conn, $ctx, $tenantId, $in, $analystId, 'Manual ticket created by ' . $analystName);
    $ticketNumber = $conn->query("SELECT ticket_number FROM tickets WHERE id = " . (int)$ticketId)->fetchColumn();

    echo json_encode(['success' => true, 'message' => 'Ticket created successfully', 'ticket_id' => $ticketId, 'ticket_number' => $ticketNumber]);
} catch (ServiceError $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
