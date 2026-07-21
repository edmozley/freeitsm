<?php
/**
 * API Endpoint: resolve a template's merge codes against a ticket.
 *
 * POST { template_id, ticket_id } -> { body }
 *
 * WHY THE SERVER DOES THE SUBSTITUTION
 * ------------------------------------
 * The picker could have merged client-side from the ticket already loaded in the
 * inbox — it would have been fewer lines. It would also have put the escaping in
 * JavaScript, where the next caller (the portal, a future mobile client) would need
 * its own copy and would eventually get it wrong. Doing it here keeps the one rule in
 * includes/reply_templates.php, and lets this endpoint re-check two things the client
 * cannot be trusted to: that the analyst may see this TICKET, and that they may see
 * this TEMPLATE.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/rbac.php';
require_once '../../includes/reply_templates.php';
require_once '../../includes/tenancy.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
// Layer 1. This endpoint reads a ticket's requester details through the merge codes,
// so "is logged in" is not enough — an analyst whose only module is, say, Calendar has
// no business resolving anything against a ticket. The per-ticket access check below
// is the second gate, not the first.
requireModuleAccessJson('tickets');

$data       = json_decode(file_get_contents('php://input'), true);
$templateId = isset($data['template_id']) ? (int)$data['template_id'] : 0;
$ticketId   = isset($data['ticket_id'])   ? (int)$data['ticket_id']   : 0;

if ($templateId <= 0 || $ticketId <= 0) {
    echo json_encode(['success' => false, 'error' => 'template_id and ticket_id are required']);
    exit;
}

try {
    $conn = connectToDatabase();
    $analystId = (int)$_SESSION['analyst_id'];

    // The ticket must be one this analyst can actually open — otherwise this endpoint
    // would happily read another company's requester name out through a merge code.
    if (!analystCanAccessTicket($conn, $analystId, $ticketId)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'No access to that ticket']);
        exit;
    }

    // And the template must be one they can see. Reuse the visibility rule rather than
    // re-querying by id: a bare "SELECT ... WHERE id = ?" here is exactly how somebody
    // else's private template would leak, one guessed integer at a time.
    $visible = replyTemplatesVisibleTo($conn, $analystId, false);
    $match   = null;
    foreach ($visible as $t) {
        if ((int)$t['id'] === $templateId) { $match = $t; break; }
    }
    if (!$match) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Template not found']);
        exit;
    }

    echo json_encode([
        'success' => true,
        'body'    => renderReplyTemplate($conn, $match['body'], $ticketId),
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
