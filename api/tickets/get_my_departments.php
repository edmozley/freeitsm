<?php
/**
 * API Endpoint: Get departments accessible to the current user based on team membership
 *
 * If the user has no team assignments, returns all active departments.
 * If the user has team assignments, returns only departments linked to those teams.
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

$analystId = (int)$_SESSION['analyst_id'];

try {
    $conn = connectToDatabase();

    // First, check if the user has any team assignments
    $teamCheckSql = "SELECT COUNT(*) as team_count FROM analyst_teams WHERE analyst_id = ?";
    $teamCheckStmt = $conn->prepare($teamCheckSql);
    $teamCheckStmt->execute([$analystId]);
    $teamCount = $teamCheckStmt->fetch(PDO::FETCH_ASSOC)['team_count'];
    $teamCheckStmt->closeCursor();

    if ($teamCount == 0) {
        // No team assignments - return all active departments
        $sql = "SELECT id, name, description, display_order
                FROM departments
                WHERE is_active = 1
                ORDER BY display_order, name";
        $stmt = $conn->prepare($sql);
        $stmt->execute();
        $departments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        // User has team assignments - first get accessible department IDs
        $accessibleDeptsSql = "SELECT DISTINCT CAST(dt.department_id AS INT) as dept_id
                               FROM department_teams dt
                               INNER JOIN analyst_teams ant ON dt.team_id = ant.team_id
                               WHERE ant.analyst_id = ?";
        $accessibleDeptsStmt = $conn->prepare($accessibleDeptsSql);
        $accessibleDeptsStmt->execute([$analystId]);
        $accessibleDepts = $accessibleDeptsStmt->fetchAll(PDO::FETCH_COLUMN);
        $accessibleDeptsStmt->closeCursor();

        if (empty($accessibleDepts)) {
            $departments = [];
        } else {
            // Get department details for accessible departments
            $deptIdPlaceholders = implode(',', array_fill(0, count($accessibleDepts), '?'));
            $sql = "SELECT id, name, description, display_order
                    FROM departments
                    WHERE is_active = 1 AND id IN ($deptIdPlaceholders)
                    ORDER BY display_order, name";
            $stmt = $conn->prepare($sql);
            $stmt->execute($accessibleDepts);
            $departments = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    }

    echo json_encode([
        'success' => true,
        'departments' => $departments,
        'filtered_by_team' => ($teamCount > 0)
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}

?>
