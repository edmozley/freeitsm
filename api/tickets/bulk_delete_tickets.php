<?php
/**
 * API Endpoint: move many tickets to the trash.
 *
 * POST { ticket_ids: [1,2,3] } -> { success, deleted, failed: [ { id, error } ] }
 *
 * Same shape and the same reasoning as bulk_update_tickets.php: a loop over
 * TicketsService::deleteTicket() rather than a bulk statement, so the soft-delete
 * semantics, the audit row and the per-ticket scope check are identical to deleting
 * one ticket. `true` is the writeAudit flag — the single-ticket UI path audits delete
 * server-side too, so this matches it exactly.
 *
 * This is the SOFT delete (the trash), which is what makes it a reasonable bulk
 * action at all: the recovery story for "I selected the wrong forty" is Restore, not
 * a database backup.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/tenancy.php';
require_once '../../includes/services/tickets.php';

header('Content-Type: application/json');
if (!isset($_SESSION['analyst_id'])) { echo json_encode(['success' => false, 'error' => 'Not authenticated']); exit; }
requireModuleAccessJson('tickets');

const BULK_DELETE_MAX = 100;

try {
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $ids  = $data['ticket_ids'] ?? null;

    if (!is_array($ids) || !count($ids)) {
        throw new Exception('ticket_ids is required');
    }
    if (count($ids) > BULK_DELETE_MAX) {
        throw new Exception('Too many tickets in one request (max ' . BULK_DELETE_MAX . ')');
    }

    $conn = connectToDatabase();
    $ctx  = ActorContext::fromSession($conn);

    $deleted = 0;
    $failed  = [];

    foreach ($ids as $rawId) {
        $ticketId = (int)$rawId;
        if ($ticketId <= 0) { $failed[] = ['id' => $rawId, 'error' => 'Invalid id']; continue; }
        try {
            TicketsService::deleteTicket($conn, $ctx, $ticketId, true);
            $deleted++;
        } catch (ServiceError $e) {
            $failed[] = ['id' => $ticketId, 'error' => $e->getMessage()];
        } catch (Exception $e) {
            $failed[] = ['id' => $ticketId, 'error' => $e->getMessage()];
        }
    }

    echo json_encode(['success' => true, 'deleted' => $deleted, 'failed' => $failed]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
