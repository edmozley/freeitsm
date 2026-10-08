<?php
/**
 * API: Planned maintenance (3.2.0) - see includes/service_status_planned.php.
 *
 * GET  ?states=scheduled,started,finished,cancelled   (default: scheduled + started)
 * POST {action:'save', id?, title, comment?, start (ISO UTC), end?, services:[{service_id, impact_level_id}]}
 * POST {action:'cancel', id}
 *
 * Thin UI adapter; the rules live in the include. Needs Service Status.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/service_status_planned.php';
header('Content-Type: application/json');
if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireModuleAccessJson('service-status');
try {
    $conn = connectToDatabase();
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        statusPlannedDue($conn);
        $states = isset($_GET['states']) ? array_filter(explode(',', (string)$_GET['states'])) : ['scheduled', 'started'];
        echo json_encode(['success' => true, 'planned' => statusPlannedList($conn, ['states' => $states])]);
        exit;
    }
    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    $ctx = ActorContext::fromSession($conn);
    $action = (string)($in['action'] ?? 'save');
    if ($action === 'cancel') {
        statusPlannedCancel($conn, $ctx, (int)($in['id'] ?? 0));
        echo json_encode(['success' => true]);
        exit;
    }
    if ($action !== 'save') throw new ServiceError('validation', 'invalid_field', 'Unknown action.');
    // A project is attached only by Projects (api/projects/tools.php), which checks it.
    unset($in['project_id']);
    $id = statusPlannedSave($conn, $ctx, $in);
    echo json_encode(['success' => true, 'id' => $id, 'planned' => statusPlannedList($conn, ['states' => STATUS_PLANNED_STATES])]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
