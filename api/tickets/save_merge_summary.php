<?php
/**
 * API Endpoint: save the AI merge summary as an internal note.
 *
 * POST { ticket_id, summary }
 *
 * Separate from ai_merge_summary.php on purpose. That endpoint streams so the analyst
 * watches it appear; this one commits it. If the analyst closes the tab mid-stream,
 * nothing was ever written — a half-finished briefing saved into a ticket would be
 * worse than none, because the next person would read it as complete.
 *
 * Always INTERNAL. The summary is a machine's reading of the conversation; it is for
 * the analyst picking the ticket up, and must never reach the requester. `is_internal`
 * is hardcoded here rather than taken from the request precisely so that no future
 * caller can make it shareable by accident.
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
    $summary  = trim((string)($data['summary'] ?? ''));

    if ($ticketId <= 0)  throw new Exception('ticket_id is required');
    if ($summary === '') throw new Exception('summary is empty');
    if (mb_strlen($summary) > 20000) throw new Exception('summary is too long');

    $conn      = connectToDatabase();
    $analystId = (int)$_SESSION['analyst_id'];

    if (!analystCanAccessTicket($conn, $analystId, $ticketId)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Ticket not found']);
        exit;
    }

    // Labelled in the note text itself, not only in the UI: whoever reads this in six
    // months — in the audit trail, in an export, in the portal-side code — must be able
    // to tell that a model wrote it and not a colleague.
    $body = "[AI summary of merged tickets]\n\n" . $summary;

    $stmt = $conn->prepare(
        "INSERT INTO ticket_notes (ticket_id, analyst_id, note_text, is_internal, created_datetime)
         VALUES (?, ?, ?, 1, UTC_TIMESTAMP())"
    );
    $stmt->execute([$ticketId, $analystId, $body]);

    echo json_encode(['success' => true, 'id' => (int)$conn->lastInsertId()]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
