<?php
/**
 * API Endpoint: Dismiss the reading pane's wrong-company warning on one ticket.
 *
 * "Filed under B, but the requester looks like A" is a soft suggestion. Dismiss
 * used to remove it only from the screen, so it was back the next time anyone
 * opened the ticket. Now it records WHICH company was suggested
 * (tickets.company_warning_dismissed_tenant_id), and the warning stays away
 * unless it would suggest a different company (GH #228).
 *
 * The suggested company comes from the banner. It is checked to be a real
 * company; the most a wrong one can do is hide a warning, on a ticket this
 * analyst can already reach and move.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/tenancy.php';

csrfRequireWriteMethod();

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireModuleAccessJson('tickets');

try {
    $input = json_decode(file_get_contents('php://input'), true);
    $ticketId    = (int)($input['ticket_id'] ?? 0);
    $suggestedId = (int)($input['suggested_id'] ?? 0);
    if ($ticketId <= 0 || $suggestedId <= 0) throw new Exception('Ticket and company are required');

    $conn = connectToDatabase();
    if (!analystCanAccessTicket($conn, (int)$_SESSION['analyst_id'], $ticketId)) {
        throw new Exception('Ticket not found');
    }
    if (!getTenantById($conn, $suggestedId)) {
        throw new Exception('That company does not exist.');
    }

    try {
        $conn->query('SELECT company_warning_dismissed_tenant_id FROM tickets LIMIT 0');
    } catch (Throwable $e) {
        // Before Database Verification: nowhere to remember it. The banner still
        // goes from the screen; say why it will come back.
        echo json_encode(['success' => false, 'error' => 'not_ready']);
        exit;
    }

    $conn->prepare("UPDATE tickets SET company_warning_dismissed_tenant_id = ? WHERE id = ?")
         ->execute([$suggestedId, $ticketId]);

    echo json_encode(['success' => true]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
