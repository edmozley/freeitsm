<?php
/**
 * API Endpoint: Create or update a ticket time entry.
 * Thin UI adapter over TicketsService (createTimeEntry / updateTimeEntry). On
 * update, only the entry's own analyst may edit it.
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
    $entryId  = isset($data['id']) ? (int)$data['id'] : null;
    $ticketId = $data['ticket_id'] ?? null;
    if (!$ticketId) {
        throw new Exception('Ticket ID is required');
    }
    $in = [
        'minutes'  => $data['time_spent_minutes'] ?? 0,
        'notes'    => $data['notes'] ?? '',
        'entry_at' => !empty($data['entry_datetime']) ? $data['entry_datetime'] : null,
    ];
    $conn = connectToDatabase();
    $ctx = ActorContext::fromSession($conn);
    if ($entryId) {
        TicketsService::updateTimeEntry($conn, $ctx, $entryId, (int)$ticketId, $in);
        echo json_encode(['success' => true, 'id' => $entryId]);
    } else {
        $newId = TicketsService::createTimeEntry($conn, $ctx, (int)$ticketId, $in);
        echo json_encode(['success' => true, 'id' => $newId]);
    }
} catch (ServiceError $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
