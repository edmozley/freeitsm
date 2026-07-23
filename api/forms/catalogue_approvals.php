<?php
/**
 * API: the catalogue-request approval inbox (#928).
 *
 * GET ?filter=mine|all|decided
 *   -> { success, items: [...], counts: { mine, all, decided } }
 *
 * Module access only, like the Change approvals inbox: signing off a catalogue request
 * is everyday service-desk work. The engine (includes/catalogue_approvals.php) still
 * enforces that only the ASSIGNED approver (or an admin) can actually decide one.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/rbac.php';
require_once '../../includes/catalogue_approvals.php';

header('Content-Type: application/json');
if (!isset($_SESSION['analyst_id'])) { echo json_encode(['success' => false, 'error' => 'Not authenticated']); exit; }
requireModuleAccessJson('forms');

try {
    $filter = $_GET['filter'] ?? 'mine';
    if (!in_array($filter, ['mine', 'all', 'decided'], true)) $filter = 'mine';

    $conn = connectToDatabase();
    $res  = catalogueApprovalsList($conn, (int)$_SESSION['analyst_id'], $filter);
    echo json_encode(array_merge(['success' => true], $res));
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
