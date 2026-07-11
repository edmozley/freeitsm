<?php
/**
 * API: create or update a problem.
 * Thin UI adapter over ProblemsService. The audit trail, PRB-##### numbering,
 * status/closed_datetime transitions and validation live there, shared with the
 * REST API's POST/PATCH /problems. On create the acting company is the analyst's
 * active tenant (auth-adjacent), resolved here and passed in.
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
    if (!$data) throw new Exception('Invalid request data');
    $conn = connectToDatabase();
    $ctx = ActorContext::fromSession($conn);
    $id = (int) ($data['id'] ?? 0);
    if ($id > 0) {
        ProblemsService::updateProblem($conn, $ctx, $id, $data);
        echo json_encode(['success' => true, 'id' => $id, 'message' => 'Problem saved']);
    } else {
        $tenantId = getActiveTenantId($conn, $ctx->actorId);
        $newId = ProblemsService::createProblem($conn, $ctx, $tenantId, $data);
        echo json_encode(['success' => true, 'id' => $newId, 'message' => 'Problem created']);
    }
} catch (ServiceError $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
