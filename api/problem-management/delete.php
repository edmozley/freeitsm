<?php
/**
 * API: delete a problem (cascades problem_tickets + problem_audit via FKs).
 * Thin UI adapter over ProblemsService::deleteProblem.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/tenancy.php';
require_once '../../includes/services/problems.php';

header('Content-Type: application/json');
if (!isset($_SESSION['analyst_id'])) { echo json_encode(['success' => false, 'error' => 'Not authenticated']); exit; }
requireModuleAccessJson('problems');

try {
    $data = json_decode(file_get_contents('php://input'), true);
    $id = (int) ($data['id'] ?? $_GET['id'] ?? 0);
    if ($id <= 0) throw new Exception('Problem ID is required');
    $conn = connectToDatabase();
    ProblemsService::deleteProblem($conn, ActorContext::fromSession($conn), $id);
    echo json_encode(['success' => true, 'message' => 'Problem deleted']);
} catch (ServiceError $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
