<?php
/**
 * API Endpoint: Move a ticket to the trash (soft-delete).
 * Thin UI adapter over TicketsService::deleteTicket. writeAudit=true — the UI
 * records the 'Trash' audit row server-side.
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

try {
    $conn = connectToDatabase();
    TicketsService::deleteTicket($conn, ActorContext::fromSession($conn), (int)$data['ticket_id'], true);
    echo json_encode(['success' => true, 'message' => 'Ticket moved to trash']);
} catch (ServiceError $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
