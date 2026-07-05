<?php
/**
 * API Endpoint: Update a single editable field on an asset.
 * Thin UI adapter over AssetsService::updateFields — the validation, audit
 * trail, and warranty-calendar sync live there, shared with the REST API's
 * PATCH /assets/{id}.
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

try {
    $data = json_decode(file_get_contents('php://input'), true) ?: [];

    $asset_id = $data['asset_id'] ?? null;
    $field = $data['field'] ?? '';
    $value = $data['value'] ?? null;

    if (!$asset_id) {
        throw new Exception('Asset ID is required');
    }

    // Whitelist the fields this UI action may edit. The service map is broader
    // (it also covers agent-synced hardware/OS columns); this endpoint keeps its
    // narrower classification/lifecycle surface, so an unexpected field is still
    // rejected here rather than silently widened.
    $allowedFields = ['asset_type_id', 'asset_status_id', 'location_id',
                      'purchase_date', 'purchase_cost', 'supplier_id', 'order_number', 'warranty_expiry'];
    if (!in_array($field, $allowedFields, true)) {
        throw new Exception('Invalid field');
    }

    $conn = connectToDatabase();
    AssetsService::updateFields($conn, ActorContext::fromSession($conn), (int)$asset_id, [$field => $value]);
    echo json_encode(['success' => true]);

} catch (ServiceError $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
