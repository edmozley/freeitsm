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
// A requester chosen from the picker (discussion #54). Either this OR a
// name/email pair — the form sends one or the other, never both.
$userId    = isset($input['user_id']) && (int)$input['user_id'] > 0 ? (int)$input['user_id'] : null;

if ($userId === null) {
    if ($fromName === '')  { echo json_encode(['success' => false, 'error' => 'Requester name is required']); exit; }
    if ($fromEmail === '') { echo json_encode(['success' => false, 'error' => 'Requester email is required']); exit; }
    if (!filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) { echo json_encode(['success' => false, 'error' => 'Invalid email address']); exit; }
}
if ($subject === '')   { echo json_encode(['success' => false, 'error' => 'Subject is required']); exit; }

try {
    $conn = connectToDatabase();
    $ctx = ActorContext::fromSession($conn);

    // Normally the acting company is simply the active one. In the consolidated
    // view (#1554) there is no single active company the analyst is looking at,
    // so the form asks — and this is where the answer lands.
    //
    // ⚠️ A DROPDOWN IS NOT A CHECK, exactly as the requester note below says.
    // `tenant_id` arrives in a JSON body and can be any integer, so it is
    // verified against what this analyst may actually reach. Without that, an
    // analyst scoped to one school could file a ticket into another's queue —
    // and it would look entirely legitimate once it landed.
    $tenantId = getActiveTenantId($conn, $analystId);
    if (isActiveTenantAll($conn) && !empty($input['tenant_id'])) {
        $wanted = (int) $input['tenant_id'];
        if (!analystCanAccessTenant($conn, $analystId, $wanted)) {
            echo json_encode(['success' => false, 'error' => 'You do not have access to that company']);
            exit;
        }
        $tenantId = $wanted;
    }

    // ⚠️ A SCOPED LIST IS NOT A CHECK.
    //
    // The picker only offers requesters this analyst can reach, because
    // get_users.php is company-filtered. That governs what is easy to choose,
    // not what can be sent: user_id arrives in a JSON body and can be any
    // integer. Without this line, an analyst scoped to one company could file a
    // ticket against another company's contact by editing one number — which is
    // the same shape as the S1/S2 findings, arriving fresh with a new feature
    // rather than being inherited from an old one.
    //
    // Same refusal text as an id that does not exist: "not yours" and "not
    // there" must stay indistinguishable.
    if ($userId !== null && !analystCanAccessUser($conn, $analystId, $userId)) {
        echo json_encode(['success' => false, 'error' => 'That requester was not found']);
        exit;
    }

    // Map the manual-create payload onto the service's canonical keys.
    $in = [
        'subject'             => $subject,
        'description'         => $input['body'] ?? '',
        'user_id'             => $userId,
        'requester_email'     => $fromEmail,
        'requester_name'      => $fromName,
        'department_id'       => $input['department_id'] ?? null,
        'ticket_type_id'      => $input['ticket_type_id'] ?? null,
        'mailbox_id'          => $input['mailbox_id'] ?? null,
    ];
    // Only pass a priority if the form actually sent one. This used to default to
    // the literal 'Normal', which the service resolves BY NAME and rejects with
    // "Unknown priority: Normal" on any install that renamed it — so a hardcoded
    // English fallback could fail the create outright (#79). No key = the
    // service picks the configured default.
    if (isset($input['priority']) && trim((string)$input['priority']) !== '') {
        $in['priority'] = $input['priority'];
    }
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
