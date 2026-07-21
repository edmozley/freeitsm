<?php
/**
 * API Endpoint: undo a split.
 *
 * POST { split_id } -> { success, source_ticket_id, returned, new_ticket_number }
 *
 * Module access, like the split itself. The engine refuses on its own terms when the
 * new ticket has been worked on since — see undoSplit() for why refusing beats
 * guessing.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/rbac.php';
require_once '../../includes/tenancy.php';
require_once '../../includes/ticket_split.php';

header('Content-Type: application/json');
if (!isset($_SESSION['analyst_id'])) { echo json_encode(['success' => false, 'error' => 'Not authenticated']); exit; }
requireModuleAccessJson('tickets');

try {
    $data    = json_decode(file_get_contents('php://input'), true) ?: [];
    $splitId = (int)($data['split_id'] ?? 0);
    if ($splitId <= 0) throw new Exception('split_id is required');

    $conn   = connectToDatabase();
    $result = undoSplit($conn, (int)$_SESSION['analyst_id'], $splitId);

    echo json_encode(array_merge(['success' => true], $result));

} catch (ServiceError $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
