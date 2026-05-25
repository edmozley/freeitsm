<?php
/**
 * API: Manually fire a workflow with a synthetic payload — the "Test fire"
 * button in the editor. Lets a user verify the engine end-to-end without
 * waiting for a real event from the host module.
 *
 * Body: { id, payload?: object }
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once __DIR__ . '/../../workflow/includes/engine.php';
header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$in = json_decode(file_get_contents('php://input'), true);
$id = isset($in['id']) ? (int)$in['id'] : 0;
$payload = is_array($in['payload'] ?? null) ? $in['payload'] : [];
if (!$id) {
    echo json_encode(['success' => false, 'error' => 'Missing id']);
    exit;
}

try {
    $result = WorkflowEngine::manualFire($id, $payload);
    echo json_encode(['success' => true, 'result' => $result]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
