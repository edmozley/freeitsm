<?php
/**
 * API: link an incident (ticket) to a change. Thin UI adapter over
 * ChangesService::linkTicket (which gates both sides, enforces the same-company
 * rule, and writes the audit row). Accepts change_id and ticket_id|ticket_number.
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
    $conn = connectToDatabase();

    $changeId = (int) ($d['change_id'] ?? 0);
    if ($changeId <= 0) throw new Exception('Change not found');

    $ticketId = (int) ($d['ticket_id'] ?? 0);
    if (!$ticketId && !empty($d['ticket_number'])) {
        $s = $conn->prepare("SELECT id FROM tickets WHERE ticket_number = ? AND deleted_datetime IS NULL");
        $s->execute([trim($d['ticket_number'])]);
        $ticketId = (int) ($s->fetchColumn() ?: 0);
    }
    if ($ticketId <= 0) throw new Exception('Ticket not found — check the ticket number.');

    ChangesService::linkTicket($conn, ActorContext::fromSession($conn), $changeId, ['ticket_id' => $ticketId]);
    echo json_encode(['success' => true, 'change_id' => $changeId, 'message' => 'Incident linked']);
} catch (ServiceError $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
