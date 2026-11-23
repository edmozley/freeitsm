<?php
/**
 * API: save custom field values for one asset.
 *
 * Takes { asset_id, values: { field_key: value } }. Only fields that actually
 * apply to this asset are accepted — an inapplicable key is a hard error, never
 * a silent drop, so a mapping mistake surfaces instead of going quiet.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/rbac.php';
require_once '../../includes/services/asset_fields.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireModuleAccessJson('assets');

try {
    $conn = connectToDatabase();
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    // Multi-tenancy: only an asset in this analyst's companies, as every sibling per-asset endpoint.
    require_once '../../includes/tenancy.php';
    if (!analystCanAccessAsset($conn, (int)$_SESSION['analyst_id'], (int)($data['asset_id'] ?? 0))) {
        throw new Exception('Asset not found');
    }
    $res  = AssetFieldsService::saveValues(
        $conn,
        ActorContext::fromSession($conn),
        (int)($data['asset_id'] ?? 0),
        is_array($data['values'] ?? null) ? $data['values'] : []
    );
    echo json_encode(['success' => true, 'id' => $res['id']]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
