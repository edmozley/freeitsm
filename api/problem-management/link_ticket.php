<?php
/**
 * API: link an incident (ticket) to a problem. Accepts problem_id|problem_number
 * and ticket_id|ticket_number. Thin UI adapter over ProblemsService::linkTicket
 * (which gates both sides, enforces same-company, and writes the audit note).
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/tenancy.php';
require_once '../../includes/services/problems.php';

header('Content-Type: application/json');
if (!isset($_SESSION['analyst_id'])) { echo json_encode(['success' => false, 'error' => 'Not authenticated']); exit; }
requireModuleAccessJson('problems');

try {
    $d = json_decode(file_get_contents('php://input'), true) ?: [];
    $conn = connectToDatabase();

    // Resolve problem + ticket references (the UI accepts numbers too).
    $problemId = (int) ($d['problem_id'] ?? 0);
    if (!$problemId && !empty($d['problem_number'])) {
        $s = $conn->prepare("SELECT id FROM problems WHERE problem_number = ?");
        $s->execute([trim($d['problem_number'])]);
        $problemId = (int) ($s->fetchColumn() ?: 0);
    }
    if ($problemId <= 0) throw new Exception('Problem not found');

    $ticketId = (int) ($d['ticket_id'] ?? 0);
    if (!$ticketId && !empty($d['ticket_number'])) {
        $s = $conn->prepare("SELECT id FROM tickets WHERE ticket_number = ? AND deleted_datetime IS NULL");
        $s->execute([trim($d['ticket_number'])]);
        $ticketId = (int) ($s->fetchColumn() ?: 0);
    }
    if ($ticketId <= 0) throw new Exception('Ticket not found — check the ticket number.');

    ProblemsService::linkTicket($conn, ActorContext::fromSession($conn), $problemId, ['ticket_id' => $ticketId]);
    echo json_encode(['success' => true, 'problem_id' => $problemId, 'message' => 'Incident linked']);
} catch (ServiceError $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
