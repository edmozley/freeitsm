<?php
/**
 * API Endpoint: undo a merge.
 *
 * POST { merge_id } -> { success, source_ticket_id, source_ticket_number, returned, target_trashed }
 *
 * Module access, like merging itself. The engine decides what is reversible — see
 * unmergeTicket(), which takes back only what the merge recorded and leaves every
 * reply written since on the surviving ticket.
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

try {
    $data    = json_decode(file_get_contents('php://input'), true) ?: [];
    $mergeId = (int)($data['merge_id'] ?? 0);
    if ($mergeId <= 0) throw new Exception('merge_id is required');

    $conn   = connectToDatabase();
    $result = unmergeTicket($conn, (int)$_SESSION['analyst_id'], $mergeId);

    echo json_encode(array_merge(['success' => true], $result));

} catch (ServiceError $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
