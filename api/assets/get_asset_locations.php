<?php
/**
 * API Endpoint: List asset locations (flat) — multi-tenancy aware.
 *
 * Locations are an arbitrary-depth tree (the client builds it from parent_id),
 * and they are PER COMPANY (a client's physical sites are entirely its own —
 * there are no "shared" locations). So the tree is scoped like the assets
 * themselves: the Default company owns the pre-existing (NULL-tenant) locations,
 * and each client company sees only its own. On a single-company install this is
 * simply every location, exactly as before.
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

    // Per-company data scope (like the asset list): the Default company also owns
    // NULL-tenant locations; a client company sees only its own. No-op at N=1.
    [$tSql, $tArgs] = activeTenantFilter($conn, (int)$_SESSION['analyst_id'], 'l');
    $stmt = $conn->prepare(
        "SELECT id, name, parent_id, display_order FROM asset_locations l
         WHERE 1=1" . $tSql . "
         ORDER BY (parent_id IS NULL) DESC, parent_id, display_order, name"
    );
    $stmt->execute($tArgs);
    $locations = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($locations as &$loc) {
        $loc['id'] = (int)$loc['id'];
        $loc['parent_id'] = $loc['parent_id'] !== null ? (int)$loc['parent_id'] : null;
        $loc['display_order'] = (int)$loc['display_order'];
    }
    unset($loc);

    echo json_encode(['success' => true, 'locations' => $locations]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
