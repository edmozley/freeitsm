<?php
/**
 * API: Tasks - the suppliers (and their active contacts) a task can be given to (3.3.0).
 * GET only. Suppliers live in Contracts, so choosing one needs the Contracts module
 * as well as Tasks; seeing a contractor's name on a task you can already see does not.
 * Rules: includes/task_contractors.php.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/task_contractors.php';

header('Content-Type: application/json');
if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireAnyModuleAccessJson(['tasks', 'projects']);

try {
    $conn = connectToDatabase();
    if (!analystCanAccessModule($conn, (int)$_SESSION['analyst_id'], 'contracts')) {
        echo json_encode(['success' => true, 'allowed' => false, 'suppliers' => []]);
        exit;
    }
    echo json_encode(['success' => true, 'allowed' => true, 'ready' => tasksContractorReady($conn), 'suppliers' => tasksContractorChoices($conn)]);
} catch (Throwable $e) {
    error_log('tasks contractors: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Could not load the suppliers.']);
}