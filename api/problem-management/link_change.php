<?php
/**
 * API: link the Change that fixes a problem (shared change_relations table).
 * Thin adapter over ProblemsService::linkChange.
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
    $d = json_decode(file_get_contents('php://input'), true) ?: [];
    $problemId = (int) ($d['problem_id'] ?? 0);
    $changeId  = (int) ($d['change_id'] ?? 0);
    if ($problemId <= 0 || $changeId <= 0) throw new Exception('Problem and change are required');
    $conn = connectToDatabase();
    ProblemsService::linkChange($conn, ActorContext::fromSession($conn), $problemId, ['change_id' => $changeId]);
    echo json_encode(['success' => true, 'message' => 'Change linked']);
} catch (ServiceError $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
