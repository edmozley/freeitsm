<?php
/**
 * API: unlink an incident (ticket) from a change. Thin UI adapter over
 * ChangesService::unlinkTicket (company-scope gated). Accepts change_id + ticket_id.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/tenancy.php';
require_once '../../includes/services/changes.php';

header('Content-Type: application/json');
if (!isset($_SESSION['analyst_id'])) { echo json_encode(['success' => false, 'error' => 'Not authenticated']); exit; }
requireModuleAccessJson('changes');

try {
    $d = json_decode(file_get_contents('php://input'), true) ?: [];
    $changeId = (int) ($d['change_id'] ?? 0);
    $ticketId = (int) ($d['ticket_id'] ?? 0);
    if ($changeId <= 0 || $ticketId <= 0) throw new Exception('change_id and ticket_id are required');

    $conn = connectToDatabase();
    ChangesService::unlinkTicket($conn, ActorContext::fromSession($conn), $changeId, $ticketId);
    echo json_encode(['success' => true, 'message' => 'Incident unlinked']);
} catch (ServiceError $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
