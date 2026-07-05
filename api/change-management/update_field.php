<?php
/**
 * API Endpoint: Update a single field on a change record (Table view inline
 * edit). Thin UI adapter over ChangesService::updateChange — one whitelisted
 * field maps to one canonical key, producing exactly one audit row. Status is
 * intentionally excluded so the approval/CAB paths can't be bypassed from a cell.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/services/changes.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$changeId = !empty($input['id']) ? (int)$input['id'] : null;
$field = $input['field'] ?? '';
$value = array_key_exists('value', $input) ? $input['value'] : null;

if (!$changeId) {
    echo json_encode(['success' => false, 'error' => 'Missing change id']);
    exit;
}

// Only these list-level fields are editable inline; each maps to a service key.
$allowed = ['priority', 'impact', 'change_type', 'assigned_to_id'];
if (!in_array($field, $allowed, true)) {
    echo json_encode(['success' => false, 'error' => 'Field not editable here']);
    exit;
}

try {
    $conn = connectToDatabase();
    ChangesService::updateChange($conn, ActorContext::fromSession($conn), $changeId, [$field => $value]);
    echo json_encode(['success' => true]);
} catch (ServiceError $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
