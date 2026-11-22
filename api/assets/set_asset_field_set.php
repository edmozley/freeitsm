<?php
/**
 * API: attach or detach a field SET on ONE asset — the pilot case.
 *
 * "Three of these ten televisions are being trialled as smart TVs": tick the
 * set on those three, and only those three carry IP address, MAC address and
 * Netflix enabled. The other seven do not show empty fields — they do not have
 * the fields.
 *
 * ⚠️ Detaching KEEPS the values. Un-ticking hides the fields; it does not throw
 * away what somebody recorded, so an accidental click is recoverable.
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
    $conn    = connectToDatabase();
    $data    = json_decode(file_get_contents('php://input'), true) ?: [];
    $assetId = (int)($data['asset_id'] ?? 0);
    $setId   = (int)($data['set_id'] ?? 0);
    $attach  = ($data['action'] ?? 'attach') === 'attach';
    // Multi-tenancy: only an asset in this analyst's companies, as every sibling per-asset endpoint.
    require_once '../../includes/tenancy.php';
    if (!analystCanAccessAsset($conn, (int)$_SESSION['analyst_id'], $assetId)) {
        throw new Exception('Asset not found');
    }

    if ($attach) {
        AssetFieldsService::attachSetToAsset($conn, ActorContext::fromSession($conn), $assetId, $setId);
    } else {
        AssetFieldsService::detachSetFromAsset($conn, $assetId, $setId);
    }
    echo json_encode(['success' => true]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
