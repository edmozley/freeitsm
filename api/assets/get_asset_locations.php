<?php
/**
 * API Endpoint: List asset locations (flat) — multi-tenancy aware.
 *
 * Locations are an arbitrary-depth tree (the client builds it from parent_id),
 * and they are PER COMPANY (a client's physical sites are its own), EXCEPT the
 * ones marked shared (2.6.0), which every company sees. So the tree is scoped
 * like the assets themselves: the Default company owns the pre-existing
 * (NULL-tenant) locations, and each client company sees its own plus the shared
 * ones. On a single-company install this is simply every location, as before.
 *
 * Used by both the settings tree and the asset location picker, so scoping here
 * scopes both.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/tenancy.php';
require_once '../../includes/asset_locations.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
// GH #311: an administrator (System -> Locations) or anyone with Assets.
locationsRequireAccessJson(false);

try {
    $conn = connectToDatabase();

    // Per-company data scope (like the asset list): the Default company also owns
    // NULL-tenant locations; a client company sees only its own. No-op at N=1.
    //
    // 2.6.0: plus every SHARED location, and for the company named by
    // ?for_tenant= when the New asset form is adding to a company other than the
    // active one. An id the analyst cannot reach falls back to the active company.
    $analystId = (int)$_SESSION['analyst_id'];
    $tenantId  = requestedTenantId($conn, $analystId);
    [$tSql, $tArgs] = assetLocationScope($conn, $tenantId, 'l');
    $sharedCol = assetLocationsSharedReady($conn) ? 'is_shared' : '0 AS is_shared';
    $stmt = $conn->prepare(
        "SELECT id, name, parent_id, display_order, $sharedCol FROM asset_locations l
         WHERE 1=1" . $tSql . "
         ORDER BY (parent_id IS NULL) DESC, parent_id, display_order, name"
    );
    $stmt->execute($tArgs);
    $locations = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($locations as &$loc) {
        $loc['id'] = (int)$loc['id'];
        $loc['parent_id'] = $loc['parent_id'] !== null ? (int)$loc['parent_id'] : null;
        $loc['display_order'] = (int)$loc['display_order'];
        $loc['is_shared'] = (bool)$loc['is_shared'];
    }
    unset($loc);

    echo json_encode([
        'success'   => true,
        'locations' => $locations,
        // Settings shows the Shared tick only to someone who may use it; the
        // save endpoint enforces the same rule regardless.
        'can_share' => isMultiTenant($conn) && assetLocationsSharedReady($conn)
                       && analystHasAllTenantAccess($conn, $analystId),
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
