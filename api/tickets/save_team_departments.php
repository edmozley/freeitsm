<?php
/**
 * API Endpoint: Save departments assigned to a team (team-keyed side of the
 * department_teams many-to-many). The department-keyed mirror is
 * save_department_teams.php; both write the same table, from whichever side the
 * admin happens to be editing. Used by System → Teams.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/admin_api_guard.php'; // System admins only (issue #34)
require_once '../../includes/functions.php';
require_once '../../includes/rbac.php';

header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

// Settings-only (the Departments tab's team mapping). It had no module check at all.
requireModuleAccessJson('tickets');
requireCapabilityJson(Cap::TICKETS_DEPARTMENTS);

// Get POST data
$input = json_decode(file_get_contents('php://input'), true);

$teamId = $input['team_id'] ?? null;
$departmentIds = $input['department_ids'] ?? [];

if (!$teamId) {
    echo json_encode(['success' => false, 'error' => 'Team ID is required']);
    exit;
}

try {
    $conn = connectToDatabase();

    // Start transaction
    $conn->beginTransaction();

    // Delete existing department assignments for this team
    $deleteSql = "DELETE FROM department_teams WHERE team_id = ?";
    $deleteStmt = $conn->prepare($deleteSql);
    $deleteStmt->execute([$teamId]);

    // Insert new department assignments
    if (!empty($departmentIds)) {
        $insertSql = "INSERT INTO department_teams (department_id, team_id) VALUES (?, ?)";
        $insertStmt = $conn->prepare($insertSql);

        foreach ($departmentIds as $departmentId) {
            $insertStmt->execute([$departmentId, $teamId]);
        }
    }

    $conn->commit();

    echo json_encode([
        'success' => true,
        'message' => 'Team departments updated successfully'
    ]);

} catch (Exception $e) {
    if (isset($conn)) {
        $conn->rollBack();
    }
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}

?>
