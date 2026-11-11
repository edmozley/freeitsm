<?php
/**
 * API: close your own ticket from the Self-Service Portal.
 * POST { ticket_id, reason? }
 *
 * For the commonest wasted ticket on any desk: "it started working", "I sorted
 * it myself", "I raised this twice". Without this the requester either replies
 * asking somebody to close it - which costs an analyst a round trip to do
 * nothing - or says nothing and the ticket ages in a queue.
 *
 * 🔑 IT GOES THROUGH TicketsService::updateTicket LIKE EVERY OTHER CLOSURE.
 * Closing is not one UPDATE: it stamps closed_datetime, stops the SLA, fires
 * the ticket_closed template, may trigger the CSAT survey, dispatches the
 * ticket.closed workflow event, and is refused by a blocking checklist gate. A
 * second closure path here would get some of that and silently skip the rest.
 *
 * 🔴 Off unless an administrator turns it on (System → Self-service portal),
 * and the switch is re-checked here - the button being hidden is not a rule.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/self_service_settings.php';
require_once '../../includes/services/tickets.php';

header('Content-Type: application/json');

if (empty($_SESSION['ss_user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$userId   = (int)$_SESSION['ss_user_id'];
$in       = json_decode(file_get_contents('php://input'), true) ?: [];
$ticketId = (int)($in['ticket_id'] ?? 0);
$reason   = trim((string)($in['reason'] ?? ''));

if (!$ticketId) {
    echo json_encode(['success' => false, 'error' => 'Ticket ID required']);
    exit;
}

try {
    $conn = connectToDatabase();

    // Who is asking: the requester, or one of their managers (#62) - the SAME rule
    // as the ticket page. Each has its own switch: allow_self_close for the
    // requester, managers_can_close for a manager.
    require_once __DIR__ . '/../../includes/managers.php';
    $access = portalTicketAccess($conn, $userId, $ticketId);
    if (!$access) {
        echo json_encode(['success' => false, 'error' => 'Ticket not found']);
        exit;
    }
    $asManager = $access['role'] === 'manager';
    if ($asManager ? !$access['can_close'] : !selfServicePortalSettings($conn)['allow_self_close']) {
        echo json_encode(['success' => false, 'error' => $asManager
            ? 'Managers cannot close tickets on this service desk.'
            : 'Closing your own ticket is not available on this service desk.']);
        exit;
    }

    // Ownership is the whole guard, and it is the same query shape and the same
    // "not found" wording as reply_ticket.php and get_ticket_detail.php - so
    // this endpoint can never confirm that somebody else's ticket exists.
    $st = $conn->prepare(
        "SELECT t.id, t.status_id, ts.is_closed
           FROM tickets t
      LEFT JOIN ticket_statuses ts ON ts.id = t.status_id
          WHERE t.id = ? AND t.deleted_datetime IS NULL"
    );
    $st->execute([$ticketId]);
    $ticket = $st->fetch(PDO::FETCH_ASSOC);
    if (!$ticket) {
        echo json_encode(['success' => false, 'error' => 'Ticket not found']);
        exit;
    }
    if ((int)$ticket['is_closed'] === 1) {
        echo json_encode(['success' => false, 'error' => 'This ticket is already closed.']);
        exit;
    }

    // The desk's own closed status, not a hard-coded id: installs rename and
    // reorder these, and more than one may be closed. display_order then id, so
    // it matches what an analyst would pick first from the same list.
    $closed = $conn->query(
        "SELECT id, name FROM ticket_statuses WHERE is_closed = 1 ORDER BY display_order ASC, id ASC LIMIT 1"
    )->fetch(PDO::FETCH_ASSOC);
    if (!$closed) {
        echo json_encode(['success' => false, 'error' => 'This service desk has no closed status configured.']);
        exit;
    }

    $who = $conn->prepare("SELECT COALESCE(preferred_name, display_name, email) AS name FROM users WHERE id = ?");
    $who->execute([$userId]);
    $name = (string)($who->fetchColumn() ?: 'the requester');

    // The requester's own words reach the closure email through the same
    // [ticket_closed_message] code an analyst's closing note uses, so an install
    // that already prints that code needs no change to show this.
    // A manager is named AS a manager, so neither the closure email nor the trail
    // says the requester closed a ticket their manager closed.
    $closedBy = $asManager ? $name . ' (manager)' : $name;
    $message = $reason !== ''
        ? $closedBy . ' closed this from the portal: ' . $reason
        : $closedBy . ' closed this from the portal — no longer needed.';

    TicketsService::updateTicket(
        $conn,
        // 🔴 A REQUESTER, NOT AN ANALYST. actorId is 0 and the audit row stores
        // NULL, so the trail does not claim a member of staff closed a ticket
        // they never touched.
        ActorContext::fromPortalUser($closedBy),
        $ticketId,
        ['status' => $closed['name'], 'closed_message' => $message],
        true    // write the audit: this is the only record that it happened
    );

    // Why it closed, in the trail, named.
    //
    // 🔴 ticket_audit, NOT ticket_notes. `ticket_notes.analyst_id` was NOT NULL
    // (until #281; NULL now means System, not a requester), so a requester has no value to put in it - and the way that goes wrong is
    // that somebody hard-codes an analyst id to satisfy the constraint and the
    // trail then says a member of staff wrote a note they never wrote. This
    // product has made exactly that mistake before. `ticket_audit.analyst_id`
    // IS nullable, which is why the checklist closure note uses it for the same
    // reason, so this follows that precedent rather than inventing a third way.
    try {
        $conn->prepare(
            "INSERT INTO ticket_audit (ticket_id, analyst_id, field_name, old_value, new_value, created_datetime)
             VALUES (?, NULL, ?, NULL, ?, UTC_TIMESTAMP())"
        )->execute([$ticketId, $asManager ? 'Closed by manager' : 'Closed by requester', $message]);
    } catch (Throwable $e) {
        // ⚠️ Logged, never swallowed silently: the ticket is already closed, so
        // this failing costs the REASON rather than the action - but a catch
        // that says nothing is how the NOT NULL problem above stayed hidden.
        error_log('close_ticket.php: closed ticket ' . $ticketId . ' but could not record why: ' . $e->getMessage());
    }

    echo json_encode(['success' => true, 'status' => $closed['name']]);

} catch (ServiceError $e) {
    // The commonest one here is a blocking checklist gate: the desk has steps
    // that must be done before anything closes. Saying so is better than a
    // generic failure, because it is not the requester's fault and not
    // something they can fix.
    echo json_encode([
        'success' => false,
        'error'   => 'This ticket cannot be closed from here yet. The service desk has steps to complete first, and they will be in touch.'
    ]);
} catch (Throwable $e) {
    error_log('close_ticket.php: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'The ticket could not be closed just now. Please try again.']);
}
