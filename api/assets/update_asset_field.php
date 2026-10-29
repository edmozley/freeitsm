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
require_once '../../includes/tenancy.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

requireModuleAccessJson('assets');

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
    // ⚠️ hostname / service_tag / manufacturer / model were added in #1143.
    //
    // They were read-only because for a COMPUTER they are agent-owned: the
    // inventory script reports them, so typing one in just gets overwritten on
    // the next sync, and read-only told the truth about who owns them.
    //
    // That reasoning does not survive contact with a television. Nothing will
    // ever report a webcam's model, so nothing overwrites it — and a typo made
    // while adding one by hand was uncorrectable. The service already validates
    // all four (hostname non-blank + unique per company); only this whitelist
    // stood in the way.
    //
    // 🔑 The RISK is renaming, not editing. The agent upserts on `hostname`
    // (api/external/system-info/submit), so renaming a machine that reports in
    // makes the next report create a SECOND asset. The UI warns before that
    // happens — it does not block, because plenty of assets never report.
    $allowedFields = ['asset_type_id', 'asset_status_id', 'location_id',
                      'purchase_date', 'purchase_cost', 'supplier_id', 'order_number', 'warranty_expiry',
                      'lease_start', 'lease_expiry', 'cost_centre_id',   // GH #160
                      'hostname', 'service_tag', 'manufacturer', 'model',
                      'retired_reason'];   // why a retired asset left - the date comes with its status
    if (!in_array($field, $allowedFields, true)) {
        throw new Exception('Invalid field');
    }

    $conn = connectToDatabase();

    // Multi-tenancy: refuse to edit an asset in a company this analyst can't
    // access (no-op on a single-company install). Framed as not-found so it
    // never reveals another company's asset.
    if (!analystCanAccessAsset($conn, (int)$_SESSION['analyst_id'], (int)$asset_id)) {
        throw new Exception('Asset not found');
    }

    $ctx = ActorContext::fromSession($conn);
    AssetsService::updateFields($conn, $ctx, (int)$asset_id, [$field => $value]);
    // GH #200 part D: a name, manufacturer or model a person corrects on an asset a tool
    // reports is protected from being overwritten by that tool (unless switched off).
    require_once '../../includes/services/asset_protection.php';
    $protected = AssetProtectionService::afterPersonEdit($conn, $ctx, (int)$asset_id, (string)$field, $value === null ? null : (string)$value);
    echo json_encode(['success' => true, 'protected' => $protected]);

} catch (ServiceError $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
