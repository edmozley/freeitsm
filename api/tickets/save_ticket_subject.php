<?php
/**
 * API: rename a ticket's subject.
 *
 * POST { ticket_id, subject } -> { success, subject }
 *
 * The subject is set at intake / creation (and by split) and had no edit path until
 * now. Everything on screen — the list, the reading-pane header, triage — reads
 * `tickets.subject`, so this one column is all that changes.
 *
 * Module access only (like assign / status changes): editing a ticket is everyday
 * work, not administration. Deliberately does NOT bump `updated_datetime` — fixing a
 * subject is a correction, not activity, and re-sorting the inbox to the top as though
 * the customer had replied would be misleading. The change is recorded in the audit
 * trail instead.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/rbac.php';
require_once '../../includes/tenancy.php';

header('Content-Type: application/json');
if (!isset($_SESSION['analyst_id'])) { echo json_encode(['success' => false, 'error' => 'Not authenticated']); exit; }
requireModuleAccessJson('tickets');

try {
    $data     = json_decode(file_get_contents('php://input'), true) ?: [];
    $ticketId = (int)($data['ticket_id'] ?? 0);
    $subject  = trim((string)($data['subject'] ?? ''));

    if ($ticketId <= 0) throw new Exception('ticket_id is required');
    if ($subject === '') throw new Exception('Subject cannot be empty');
    if (mb_strlen($subject) > 255) $subject = mb_substr($subject, 0, 255);

    $conn = connectToDatabase();
    if (!analystCanAccessTicket($conn, (int)$_SESSION['analyst_id'], $ticketId)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Ticket not found']);
        exit;
    }

    $stmt = $conn->prepare("SELECT subject FROM tickets WHERE id = ?");
    $stmt->execute([$ticketId]);
    $old = $stmt->fetchColumn();
    if ($old === false) throw new Exception('Ticket not found');

    // No-op guard: nothing to write or audit if the subject is unchanged.
    if ((string)$old === $subject) {
        echo json_encode(['success' => true, 'subject' => $subject, 'unchanged' => true]);
        exit;
    }

    $conn->prepare("UPDATE tickets SET subject = ? WHERE id = ?")->execute([$subject, $ticketId]);

    // Best-effort audit — never fail the rename because the trail insert did.
    try {
        $conn->prepare(
            "INSERT INTO ticket_audit (ticket_id, analyst_id, field_name, old_value, new_value, created_datetime)
             VALUES (?, ?, 'Subject', ?, ?, UTC_TIMESTAMP())"
        )->execute([$ticketId, (int)$_SESSION['analyst_id'], (string)$old, $subject]);
    } catch (Exception $e) { /* audit is best-effort */ }

    echo json_encode(['success' => true, 'subject' => $subject]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
