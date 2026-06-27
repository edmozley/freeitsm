<?php
/**
 * API: link an incident (ticket) to a problem. Accepts problem_id|problem_number and
 * ticket_id|ticket_number. Gated both sides; enforces same-company on a multi-tenant
 * install. Idempotent (unique key). Writes a problem_audit note.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/tenancy.php';

header('Content-Type: application/json');
if (!isset($_SESSION['analyst_id'])) { echo json_encode(['success' => false, 'error' => 'Not authenticated']); exit; }

try {
    $d = json_decode(file_get_contents('php://input'), true);
    $conn = connectToDatabase();
    $analystId = (int) $_SESSION['analyst_id'];

    // Resolve problem.
    $problemId = (int) ($d['problem_id'] ?? 0);
    if (!$problemId && !empty($d['problem_number'])) {
        $s = $conn->prepare("SELECT id FROM problems WHERE problem_number = ?");
        $s->execute([trim($d['problem_number'])]);
        $problemId = (int) ($s->fetchColumn() ?: 0);
    }
    if ($problemId <= 0) throw new Exception('Problem not found');

    // Resolve ticket.
    $ticketId = (int) ($d['ticket_id'] ?? 0);
    if (!$ticketId && !empty($d['ticket_number'])) {
        $s = $conn->prepare("SELECT id FROM tickets WHERE ticket_number = ? AND deleted_datetime IS NULL");
        $s->execute([trim($d['ticket_number'])]);
        $ticketId = (int) ($s->fetchColumn() ?: 0);
    }
    if ($ticketId <= 0) throw new Exception('Ticket not found — check the ticket number.');

    if (!analystCanAccessProblem($conn, $analystId, $problemId)) throw new Exception('Problem not found');
    if (!analystCanAccessTicket($conn, $analystId, $ticketId)) throw new Exception('Ticket not found');

    // Same-company check (multi-tenant only).
    if (isMultiTenant($conn)) {
        $def = getDefaultTenantId($conn);
        $pt = $conn->prepare("SELECT tenant_id FROM problems WHERE id = ?"); $pt->execute([$problemId]);
        $tt = $conn->prepare("SELECT tenant_id FROM tickets WHERE id = ?");  $tt->execute([$ticketId]);
        $pTen = $pt->fetchColumn(); $tTen = $tt->fetchColumn();
        $pTen = ($pTen === null || $pTen === false) ? $def : (int) $pTen;
        $tTen = ($tTen === null || $tTen === false) ? $def : (int) $tTen;
        if ($pTen !== $tTen) throw new Exception('That incident belongs to a different company than this problem.');
    }

    $num = $conn->prepare("SELECT ticket_number FROM tickets WHERE id = ?"); $num->execute([$ticketId]);
    $ticketNumber = (string) ($num->fetchColumn() ?: $ticketId);

    $ins = $conn->prepare("INSERT IGNORE INTO problem_tickets (problem_id, ticket_id, created_by_id, created_datetime) VALUES (?, ?, ?, UTC_TIMESTAMP())");
    $ins->execute([$problemId, $ticketId, $analystId]);
    if ($ins->rowCount() > 0) {
        $conn->prepare("INSERT INTO problem_audit (problem_id, analyst_id, action_type, field_name, new_value, created_datetime) VALUES (?, ?, 'modified', 'linked_incident', ?, UTC_TIMESTAMP())")
             ->execute([$problemId, $analystId, $ticketNumber]);
        $conn->prepare("UPDATE problems SET updated_datetime = UTC_TIMESTAMP() WHERE id = ?")->execute([$problemId]);
    }
    echo json_encode(['success' => true, 'problem_id' => $problemId, 'message' => 'Incident linked']);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
