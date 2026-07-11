<?php
/**
 * API Endpoint: Update ticket owner (assigned analyst).
 * Thin UI adapter over TicketsService::updateTicket — an owner change is an
 * assignment change, so it flows through the same path (sets assigned + owner,
 * fires the ticket_assigned template + ticket.assigned workflow). writeAudit=false
 * (the UI audits client-side).
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/tenancy.php';
require_once '../../includes/services/tickets.php';

header('Content-Type: application/json');
if (!isset($_SESSION['analyst_id'])) { echo json_encode(['success' => false, 'error' => 'Not authenticated']); exit; }
requireModuleAccessJson('tickets');

$data = json_decode(file_get_contents('php://input'), true);
if (!$data || !isset($data['ticket_id'])) { echo json_encode(['success' => false, 'error' => 'Invalid request data']); exit; }

$ticketId = (int)$data['ticket_id'];
$ownerId = isset($data['owner_id']) && $data['owner_id'] !== '' ? (int)$data['owner_id'] : null;

try {
    $conn = connectToDatabase();
    TicketsService::updateTicket($conn, ActorContext::fromSession($conn), $ticketId, ['assigned_analyst_id' => $ownerId], false);
    echo json_encode(['success' => true, 'message' => 'Ticket owner updated successfully']);
} catch (ServiceError $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
