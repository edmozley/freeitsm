<?php
/**
 * API Endpoint: Soft-delete a ticket time entry (sets is_active = 0).
 * Thin UI adapter over TicketsService::deleteTimeEntry — only the entry's own
 * analyst may delete it (restrictToOwner = true). The entry id alone identifies
 * it; the service resolves + scope-gates the owning ticket.
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
    $entryId = isset($data['id']) ? (int)$data['id'] : null;
    if (!$entryId) {
        throw new Exception('Entry ID is required');
    }
    $conn = connectToDatabase();
    TicketsService::deleteTimeEntry($conn, ActorContext::fromSession($conn), $entryId, null, true);
    echo json_encode(['success' => true]);
} catch (ServiceError $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
