<?php
/**
 * API Endpoint: merge tickets.
 *
 * POST { source_ids: [1,2], target_id: 3 }
 *   -> { success, target_id, target_number, merged: [...], created_new: bool, settings }
 *
 * Guarded by module access and nothing more. Merging is everyday service-desk work —
 * the same category as closing a ticket or reassigning it — so it is not behind a
 * capability. What IS behind Cap::TICKETS_MERGE is the POLICY (Tickets → Settings →
 * Merge behaviour): whether a merge keeps the requester's reference alive, and what
 * happens to the conversations. Deciding that for the whole install is an
 * administrator's job; doing a merge is the job.
 *
 * The engine itself lives in includes/ticket_merge.php, where the reasoning is
 * written up. This file is a thin adapter: validate, call, report.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/rbac.php';
require_once '../../includes/tenancy.php';
require_once '../../includes/ticket_merge.php';

header('Content-Type: application/json');
if (!isset($_SESSION['analyst_id'])) { echo json_encode(['success' => false, 'error' => 'Not authenticated']); exit; }
requireModuleAccessJson('tickets');

// A merge is irreversible in one click and touches several tickets at once, so it is
// bounded well below the bulk-action cap. Twenty conversations folded into one is
// already an unusual day.
const MERGE_MAX_SOURCES = 20;

try {
    $data      = json_decode(file_get_contents('php://input'), true) ?: [];
    $sourceIds = $data['source_ids'] ?? null;
    $targetId  = isset($data['target_id']) ? (int)$data['target_id'] : 0;

    if (!is_array($sourceIds) || !count($sourceIds)) throw new Exception('source_ids is required');
    if ($targetId <= 0)                              throw new Exception('target_id is required');
    if (count($sourceIds) > MERGE_MAX_SOURCES)       throw new Exception('Too many tickets in one merge (max ' . MERGE_MAX_SOURCES . ')');

    $conn   = connectToDatabase();
    $result = mergeTickets($conn, (int)$_SESSION['analyst_id'], $sourceIds, $targetId);

    echo json_encode(array_merge(['success' => true], $result));

} catch (ServiceError $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
