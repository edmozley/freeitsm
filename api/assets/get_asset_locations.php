<?php
/**
 * API Endpoint: List asset locations (flat) — multi-tenancy aware.
 *
 * Locations are an arbitrary-depth tree (the client builds it from parent_id).
 * Per company they follow a "shared + own" model (a lighter form of the config
 * override — there is no hide toggle for a tree):
 *   - shared locations → tenant_id IS NULL (visible to every company)
 *   - a company's own  → tenant_id = that company
 * So a client company sees the shared locations plus its own; the MSP/Default
 * context manages the shared ones. On a single-company install this is simply
 * every location, exactly as before.
 *
 * Used by both the settings tree and the asset location picker, so scoping here
 * scopes both.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/tenancy.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

try {
    $conn = connectToDatabase();
    $activeId = getActiveTenantId($conn, (int)$_SESSION['analyst_id']);

    // getTenantConfigRows resolves "shared (NULL) not-hidden + this company's own".
    // We never write hidden rows for locations, so it yields shared + own.
    $locations = getTenantConfigRows(
        $conn, 'asset_locations', 'asset_location', $activeId,
        'id, name, parent_id, display_order, tenant_id', '',
        '(parent_id IS NULL) DESC, parent_id, display_order, name'
    );

    foreach ($locations as &$loc) {
        $loc['id'] = (int)$loc['id'];
        $loc['parent_id'] = $loc['parent_id'] !== null ? (int)$loc['parent_id'] : null;
        $loc['display_order'] = (int)$loc['display_order'];
        $loc['scope'] = ($loc['tenant_id'] === null) ? 'global' : 'company';
    }
    unset($loc);

    echo json_encode(['success' => true, 'locations' => $locations]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
