<?php
/**
 * API Endpoint: move a change to another company (tenant).
 *
 * Thin UI adapter over ChangesService::moveToCompany() (GH #390), which holds the
 * rules for the app and the REST API alike: the analyst must reach the change
 * and the target company; linked tickets must already be in the target; the
 * tasks linked to the change move with it. Writes the change_audit entry.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/tenancy.php';
require_once '../../includes/services/changes.php';

// Changes data: never on a GET, which a link on another site can send (csrf.php).
csrfRequireWriteMethod();

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireModuleAccessJson('changes');
requireModuleWriteJson('changes');   // Read only access (#180) changes nothing here

try {
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $changeId = (int)($input['change_id'] ?? $input['id'] ?? 0);
    $targetId = (int)($input['tenant_id'] ?? 0);

    if ($changeId <= 0) throw new Exception('Change ID is required');
    if ($targetId <= 0) throw new Exception('A target company is required');

    $conn = connectToDatabase();
    $r = ChangesService::moveToCompany($conn, ActorContext::fromSession($conn), $changeId, $targetId);

    if ($r['moved'] === 0) {
        echo json_encode(['success' => true, 'message' => 'Change is already in that company.', 'company_name' => $r['to']]);
        exit;
    }
    $message = 'Change moved to ' . $r['to']
        . ($r['tasks_moved'] > 0 ? ', with its linked tasks (' . $r['tasks_moved'] . ')' : '');
    echo json_encode(['success' => true, 'message' => $message, 'company_name' => $r['to'], 'tasks_moved' => $r['tasks_moved']]);

} catch (ServiceError $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
