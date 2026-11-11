<?php
/**
 * API Endpoint: create an asset from the UI.
 *
 * Until now the ONLY way to create an asset was the inventory agent, Intune,
 * vCenter or POST /api/v1/assets — every one of which assumes the thing reports
 * for itself. A television, a printer or a headset never will, so recording one
 * meant a curl command. This is the front door.
 *
 * Thin adapter over AssetsService::createAsset — the duplicate-hostname refusal,
 * field validation and audit trail all live there, shared with the REST API.
 *
 * Deliberately CORE FIELDS ONLY. Custom fields are filled in on the asset
 * itself, where there is room for them and the "3 of 3 filled in" counter says
 * what is outstanding; a modal is the wrong place to answer eight questions.
 */
session_start(['read_and_close' => true]);
require_once __DIR__ . '/../../includes/service_context.php';   // ServiceError, thrown below (GH #288)
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/rbac.php';
require_once '../../includes/tenancy.php';
require_once '../../includes/services/assets.php';
require_once '../../includes/asset_locations.php';
// This endpoint rewrites one service message for a human audience (see the
// catch block), so unlike its siblings it needs the translation layer. Same
// pattern as api/assets/email_handover.php.
require_once '../../includes/i18n.php';
I18n::initFromSession();

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

// Plain module access, no settings capability: adding a record is ordinary
// analyst work, the same as assigning one to somebody. Designing the FIELDS an
// asset carries is administration and is gated separately.
requireModuleAccessJson('assets');

try {
    $conn = connectToDatabase();
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $analystId = (int)$_SESSION['analyst_id'];

    // Only the classification and lifecycle columns a person would fill in by
    // hand. The agent-synced hardware ones (cpu_name, bios_version, memory…) are
    // deliberately absent: typing them in would be overwritten by the next sync
    // on anything that does report for itself.
    $allowed = ['hostname', 'asset_tag', 'asset_type_id', 'asset_status_id', 'location_id',
                'manufacturer', 'model', 'service_tag',
                'supplier_id', 'purchase_date', 'purchase_cost', 'order_number', 'warranty_expiry',
                'lease_start', 'lease_expiry'];

    $in = [];
    foreach ($allowed as $f) {
        if (array_key_exists($f, $data) && $data[$f] !== '' && $data[$f] !== null) {
            $in[$f] = $data[$f];
        }
    }

    // Multi-tenancy: the company the analyst is currently working in, UNLESS the
    // form's Company picker named another (2.6.0). A customer reported that in
    // the All companies view, a new asset silently landed in whichever company
    // was last selected; the picker now asks.
    //
    // 🔴 Checked twice, the way TasksService::moveTaskToCompany checks: what THIS
    // caller may touch (companyScope) and what the person may reach. The service
    // normalises the Default company to NULL so a UI-created asset stores what an
    // agent-created one stores.
    $ctx      = ActorContext::fromSession($conn);
    $tenantId = getActiveTenantId($conn, $analystId);
    if (!empty($data['tenant_id']) && isMultiTenant($conn)) {
        $wanted = (int)$data['tenant_id'];
        if (($ctx->companyScope !== null && !in_array($wanted, $ctx->companyScope, true))
                || !analystCanAccessTenant($conn, $analystId, $wanted)) {
            throw new ServiceError('validation', 'invalid_field', t('asset-management.new.company_denied'));
        }
        $tenantId = $wanted;
    }

    // The type, status and location must be ones THAT company can use. The form
    // loads them for the chosen company, so this only fires on a stale form or a
    // crafted request - but without it, an asset could be created in one company
    // wearing another company's private location.
    if (isMultiTenant($conn)) {
        foreach ([['asset_type_id', 'asset_types', 'asset_type'], ['asset_status_id', 'asset_status_types', 'asset_status_type']] as [$col, $table, $entity]) {
            if (empty($in[$col])) continue;
            $ok = array_filter(getTenantConfigRows($conn, $table, $entity, $tenantId, 'id'),
                               fn($r) => (int)$r['id'] === (int)$in[$col]);
            if (!$ok) {
                throw new ServiceError('validation', 'invalid_field', t('asset-management.new.lookup_wrong_company'));
            }
        }
        if (!empty($in['location_id'])) {
            [$lSql, $lArgs] = assetLocationScope($conn, $tenantId, '');
            $lc = $conn->prepare("SELECT id FROM asset_locations WHERE id = ?" . $lSql);
            $lc->execute(array_merge([(int)$in['location_id']], $lArgs));
            if (!$lc->fetchColumn()) {
                throw new ServiceError('validation', 'invalid_field', t('asset-management.new.lookup_wrong_company'));
            }
        }
    }

    $assetId = AssetsService::createAsset(
        $conn,
        $ctx,
        $in,
        'Created manually by ' . ($_SESSION['analyst_name'] ?? 'an analyst'),
        $tenantId
    );

    // ⚠️ The ASSET TAG is deliberately not settable here. It is unique per
    // company, and that rule lives in save_asset_tag.php — reimplementing it
    // would be a second source of truth for the one field whose uniqueness the
    // database explicitly does NOT guard (see the assets.asset_tag comment).
    // It is the first box on the asset page, so it is one click away.

    $stmtTag = $conn->prepare("SELECT asset_tag FROM assets WHERE id = ?");
    $stmtTag->execute([$assetId]);
    $createdAssetTag = $stmtTag->fetchColumn() ?: null;

    wf_emit('asset', 'created', $assetId, $in['hostname'] ?? '');
    // Whether it landed in the company on screen, so the form can open it there
    // or, if it went elsewhere, say where rather than opening a record the list
    // is not showing.
    echo json_encode([
        'success'        => true,
        'id'             => $assetId,
        'asset_tag'      => $createdAssetTag,
        'in_active'      => $tenantId === getActiveTenantId($conn, $analystId),
        'company_name'   => (getTenantById($conn, $tenantId)['name'] ?? ''),
    ]);

} catch (ServiceError $e) {
    // ⚠️ The service speaks REST, because its duplicate-hostname message is a
    // PUBLISHED API error body ("Use PATCH /assets/640 to update it"). That is
    // right for a key holder and meaningless in a toast, so the adapter
    // translates it here rather than the contract being changed underneath the
    // REST API. Everything else passes through unaltered.
    $msg = $e->errorCode === 'conflict'
        ? t('asset-management.new.duplicate', ['name' => $in['hostname'] ?? ''])
        : $e->getMessage();
    echo json_encode(['success' => false, 'error' => $msg]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
