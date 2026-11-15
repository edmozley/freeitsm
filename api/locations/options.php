<?php
/**
 * API: the locations a record can have (GH #311).
 * GET ?company_id=N
 *
 * Company N's own locations plus the shared ones, each with its full path
 * ("UK > London > Floor 2") - see includes/record_locations.php. Shared by the
 * person, ticket, contract and project screens, so it asks for any one of
 * those modules rather than System (where locations are managed), as
 * api/cost-centres/options.php does. A company the analyst cannot reach is
 * refused; with one company, it is always the Default.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/record_locations.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireAnyModuleAccessJson(['assets', 'contracts', 'tickets', 'projects']);

try {
    $conn = connectToDatabase();
    $raw = isset($_GET['company_id']) ? (int)$_GET['company_id'] : 0;
    $tenantId = $raw > 0 ? $raw : getDefaultTenantId($conn);
    if (isMultiTenant($conn) && !analystCanAccessTenant($conn, (int)$_SESSION['analyst_id'], $tenantId)) {
        echo json_encode(['success' => false, 'error' => 'Company not found']);
        exit;
    }
    echo json_encode(['success' => true, 'locations' => recordLocationOptions($conn, $tenantId)]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
