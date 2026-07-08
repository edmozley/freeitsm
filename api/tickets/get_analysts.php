<?php
/**
 * API Endpoint: Get all analysts
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';

header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

try {
    $conn = connectToDatabase();

    $sql = "SELECT id, username, full_name, email, is_active, auth_provider_id, can_access_all_tenants, created_datetime, last_login_datetime, last_modified_datetime
            FROM analysts
            ORDER BY full_name";

    $stmt = $conn->prepare($sql);
    $stmt->execute();
    $analysts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Multi-tenancy: which companies each analyst is granted DIRECTLY (only
    // consulted when they're NOT all-access). Degrades to empty if not migrated.
    $grantsByAnalyst = [];
    try {
        foreach ($conn->query("SELECT analyst_id, tenant_id FROM analyst_tenant_access") as $row) {
            $grantsByAnalyst[(int)$row['analyst_id']][] = (int)$row['tenant_id'];
        }
    } catch (Exception $e) {
        $grantsByAnalyst = [];
    }

    // Company access an analyst also gets VIA their teams (additive). Two parts:
    // membership of an all-access team, and specific companies granted by a team.
    // Lets the UI show effective access, not just direct grants.
    $teamAllAccess = [];       // analyst_id => true (in a team flagged all-access)
    $teamGrantsByAnalyst = []; // analyst_id => [tenant_id, ...]
    try {
        foreach ($conn->query("SELECT at.analyst_id FROM analyst_teams at JOIN teams t ON at.team_id = t.id WHERE t.can_access_all_tenants = 1") as $row) {
            $teamAllAccess[(int)$row['analyst_id']] = true;
        }
        foreach ($conn->query("SELECT at.analyst_id, tta.tenant_id FROM team_tenant_access tta JOIN analyst_teams at ON at.team_id = tta.team_id") as $row) {
            $teamGrantsByAnalyst[(int)$row['analyst_id']][] = (int)$row['tenant_id'];
        }
    } catch (Exception $e) {
        $teamAllAccess = [];
        $teamGrantsByAnalyst = [];
    }

    // Convert fields to proper types
    foreach ($analysts as &$analyst) {
        $analyst['id'] = (int)$analyst['id'];
        $analyst['is_active'] = (bool)$analyst['is_active'];
        $analyst['auth_provider_id'] = $analyst['auth_provider_id'] !== null ? (int)$analyst['auth_provider_id'] : null;
        // Default to all-access if the column is somehow NULL (matches the migration default).
        $analyst['can_access_all_tenants'] = !isset($analyst['can_access_all_tenants']) || (int)$analyst['can_access_all_tenants'] === 1;
        $direct = $grantsByAnalyst[$analyst['id']] ?? [];
        $viaTeam = array_values(array_unique($teamGrantsByAnalyst[$analyst['id']] ?? []));
        $analyst['tenant_ids'] = $direct;                                   // directly granted (editable here)
        $analyst['team_tenant_ids'] = $viaTeam;                             // granted via a team (read-only here)
        $analyst['team_all_access'] = !empty($teamAllAccess[$analyst['id']]); // in an all-access team
    }

    echo json_encode(['success' => true, 'analysts' => $analysts]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

?>
