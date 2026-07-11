<?php
/**
 * API Endpoint: Assign a user to an asset.
 * Thin UI adapter over AssetsService::assignUser (creates the users_assets row,
 * custody checkout, and audit trail). On a re-assign the UI passes
 * `previous_user_id` so the audit records the "was X, now Y" transition.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/services/assets.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

requireModuleAccessJson('assets');

$data = json_decode(file_get_contents('php://input'), true) ?: [];

$assetId = $data['asset_id'] ?? null;
$userId = $data['user_id'] ?? null;
if (!$assetId || !$userId) {
    echo json_encode(['success' => false, 'error' => 'Asset ID and User ID are required']);
    exit;
}

try {
    $conn = connectToDatabase();
    AssetsService::assignUser($conn, ActorContext::fromSession($conn), (int)$assetId, $data);
    echo json_encode(['success' => true, 'message' => 'User assigned successfully']);
} catch (ServiceError $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
