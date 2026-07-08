<?php
/**
 * API Endpoint: Save the company (tenant) access a team grants its members.
 *
 * Team grants are ADDITIVE — they widen the access of every analyst in the team
 * (unioned with each analyst's own grants in getAccessibleTenantIds()). A team
 * defaults to granting nothing; set can_access_all_tenants for every company, or
 * list specific companies. Used by System → Teams.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$teamId = isset($input['team_id']) ? (int) $input['team_id'] : 0;
if (!$teamId) {
    echo json_encode(['success' => false, 'error' => 'Team ID is required']);
    exit;
}

$allAccess = !empty($input['can_access_all_tenants']) ? 1 : 0;
$tenantIds = is_array($input['tenant_ids'] ?? null) ? array_map('intval', $input['tenant_ids']) : [];

try {
    $conn = connectToDatabase();
    $conn->beginTransaction();

    $conn->prepare("UPDATE teams SET can_access_all_tenants = ? WHERE id = ?")->execute([$allAccess, $teamId]);

    // Rebuild the specific grants from scratch (ignored anyway while all-access).
    $conn->prepare("DELETE FROM team_tenant_access WHERE team_id = ?")->execute([$teamId]);
    if (!$allAccess && $tenantIds) {
        $ins = $conn->prepare("INSERT IGNORE INTO team_tenant_access (team_id, tenant_id) VALUES (?, ?)");
        foreach (array_unique($tenantIds) as $tid) {
            if ($tid > 0) $ins->execute([$teamId, $tid]);
        }
    }

    $conn->commit();
    echo json_encode(['success' => true, 'message' => 'Team company access updated']);
} catch (Exception $e) {
    if (isset($conn) && $conn->inTransaction()) $conn->rollBack();
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
