<?php
/**
 * API Endpoint: Create or update a change record.
 * Thin UI adapter over ChangesService (create/update). The per-field audit
 * trail, risk scoring, and the change.approved workflow event live there, shared
 * with the REST API's POST/PATCH /changes. This is a full-form save, so it sends
 * every field; the service treats absent keys as untouched.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/tenancy.php';
require_once '../../includes/services/changes.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    echo json_encode(['success' => false, 'error' => 'Invalid request data']);
    exit;
}

// Map the UI's schedule/PIR datetime keys onto the service's canonical names;
// everything else passes through unchanged.
$dateKeys = [
    'work_start_datetime'   => 'work_start_at',
    'work_end_datetime'     => 'work_end_at',
    'outage_start_datetime' => 'outage_start_at',
    'outage_end_datetime'   => 'outage_end_at',
    'pir_actual_start'      => 'pir_actual_start_at',
    'pir_actual_end'        => 'pir_actual_end_at',
];
$in = $input;
foreach ($dateKeys as $uiKey => $canonKey) {
    if (array_key_exists($uiKey, $in)) {
        $in[$canonKey] = $in[$uiKey];
        unset($in[$uiKey]);
    }
}

try {
    $conn = connectToDatabase();
    $ctx = ActorContext::fromSession($conn);
    $changeId = !empty($input['id']) ? (int)$input['id'] : null;
    if ($changeId) {
        ChangesService::updateChange($conn, $ctx, $changeId, $in);
    } else {
        // Stamp the new change with the analyst's active company (auth-adjacent,
        // resolved here and passed in). Default at N=1.
        $tenantId = getActiveTenantId($conn, $ctx->actorId);
        $changeId = ChangesService::createChange($conn, $ctx, $tenantId, $in);
    }
    echo json_encode(['success' => true, 'change_id' => $changeId, 'message' => 'Change saved successfully']);
} catch (ServiceError $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
