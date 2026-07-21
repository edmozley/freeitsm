<?php
/**
 * API Endpoint: apply one field change to many tickets.
 *
 * POST { ticket_ids: [1,2,3], fields: { status: 'Resolved' } }
 *   -> { success, updated, failed: [ { id, error } ] }
 *
 * WHY THIS IS A LOOP OVER THE SERVICE, NOT A BULK `UPDATE`
 * -------------------------------------------------------
 * The obvious implementation — one `UPDATE tickets SET status_id = ? WHERE id IN (…)`
 * — would be faster and wrong. Changing a ticket is not a column write: it moves the
 * closed_datetime, syncs the owner, may send a template email, may fire CSAT, and
 * dispatches workflow triggers. All of that lives in TicketsService::updateTicket(),
 * and a second write path would drift from it. The symptom of that drift is the worst
 * kind: a workflow that fires when you change one ticket and silently doesn't when you
 * change fifty.
 *
 * So this calls the SAME service method the single-ticket endpoint calls, once per
 * ticket. Every side effect is identical by construction, and per-ticket access
 * control comes free — `loadTicket()` inside the service throws for a ticket that is
 * unknown OR out of this analyst's scope, so a caller cannot reach another company's
 * tickets by putting their ids in the array.
 *
 * writeAudit is TRUE here, unlike assign_ticket.php. The single-ticket UI path audits
 * client-side because it already knows the old value; a bulk caller does not know the
 * old value of fifty tickets, and asking the browser to fetch them all first would be
 * both slow and a lie waiting to happen. The service reads the real previous value per
 * ticket, so the audit trail is correct rather than approximate.
 *
 * ONE FAILURE DOES NOT STOP THE RUN. A ticket somebody else just deleted, or one in a
 * status that refuses the change, is reported in `failed[]` and the rest still apply —
 * an all-or-nothing bulk action that abandons 49 good updates because of one bad row
 * is not what anybody means by "apply to selection".
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/tenancy.php';
require_once '../../includes/services/tickets.php';

header('Content-Type: application/json');
if (!isset($_SESSION['analyst_id'])) { echo json_encode(['success' => false, 'error' => 'Not authenticated']); exit; }
requireModuleAccessJson('tickets');

// Bounded so one request cannot become an unbounded write. The client chunks
// larger selections; this is the backstop, not the user-facing limit.
const BULK_MAX_TICKETS = 100;

try {
    $data = json_decode(file_get_contents('php://input'), true) ?: [];

    $ids    = $data['ticket_ids'] ?? null;
    $fields = $data['fields'] ?? null;

    if (!is_array($ids) || !count($ids)) {
        throw new Exception('ticket_ids is required');
    }
    if (!is_array($fields) || !count($fields)) {
        throw new Exception('fields is required');
    }
    if (count($ids) > BULK_MAX_TICKETS) {
        throw new Exception('Too many tickets in one request (max ' . BULK_MAX_TICKETS . ')');
    }

    // Whitelist, mirroring assign_ticket.php. A bulk endpoint that accepted any key
    // would be a wider hole than the single-ticket one it is meant to match.
    $allowed = ['department_id', 'ticket_type_id', 'status', 'origin_id',
                'first_time_fix', 'it_training_provided', 'priority_id', 'assigned_analyst_id'];
    $in = [];
    foreach ($allowed as $k) {
        if (array_key_exists($k, $fields)) $in[$k] = $fields[$k];
    }
    if (!$in) {
        throw new Exception('No updatable fields supplied');
    }

    $conn = connectToDatabase();
    $ctx  = ActorContext::fromSession($conn);

    $updated = 0;
    $failed  = [];

    foreach ($ids as $rawId) {
        $ticketId = (int)$rawId;
        if ($ticketId <= 0) { $failed[] = ['id' => $rawId, 'error' => 'Invalid id']; continue; }
        try {
            TicketsService::updateTicket($conn, $ctx, $ticketId, $in, true);
            $updated++;
        } catch (ServiceError $e) {
            $failed[] = ['id' => $ticketId, 'error' => $e->getMessage()];
        } catch (Exception $e) {
            $failed[] = ['id' => $ticketId, 'error' => $e->getMessage()];
        }
    }

    echo json_encode(['success' => true, 'updated' => $updated, 'failed' => $failed]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
