<?php
/**
 * API Endpoint: Get ticket counts by department and status
 * Returns hierarchical count data for folder view
 * Respects team-based filtering for users with team assignments
 */
session_start();
require_once '../../config.php';
require_once '../../includes/functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$analystId = (int)$_SESSION['analyst_id'];

try {
    $conn = connectToDatabase();

    // Check if user has team assignments
    $teamCheckSql = "SELECT COUNT(*) as team_count FROM analyst_teams WHERE analyst_id = ?";
    $teamCheckStmt = $conn->prepare($teamCheckSql);
    $teamCheckStmt->execute([$analystId]);
    $teamCount = $teamCheckStmt->fetch(PDO::FETCH_ASSOC)['team_count'];
    $teamCheckStmt->closeCursor();

    $hasTeamFilter = ($teamCount > 0);

    if ($hasTeamFilter) {
        // User has team assignments - filter to only their departments
        // First get the list of accessible department IDs
        $accessibleDeptsSql = "SELECT DISTINCT dt.department_id as dept_id
                               FROM department_teams dt
                               INNER JOIN analyst_teams ant ON dt.team_id = ant.team_id
                               WHERE ant.analyst_id = ?";
        $accessibleDeptsStmt = $conn->prepare($accessibleDeptsSql);
        $accessibleDeptsStmt->execute([$analystId]);
        $accessibleDepts = $accessibleDeptsStmt->fetchAll(PDO::FETCH_COLUMN);
        $accessibleDeptsStmt->closeCursor();

        if (empty($accessibleDepts)) {
            // No accessible departments - just count unassigned
            $totalCount = 0;
            $departments = [];
            $deptStatusCounts = [];
            $statusCounts = [];
        } else {
            // Build IN clause with the department IDs
            $deptIdPlaceholders = implode(',', array_fill(0, count($accessibleDepts), '?'));

            // Get total counts for accessible departments only
            $totalSql = "SELECT COUNT(*) as total FROM tickets t
                         WHERE t.department_id IN ($deptIdPlaceholders) OR t.department_id IS NULL";
            $totalStmt = $conn->prepare($totalSql);
            $totalStmt->execute($accessibleDepts);
            $totalResult = $totalStmt->fetch(PDO::FETCH_ASSOC);
            $totalCount = $totalResult['total'];
            $totalStmt->closeCursor();

            // Get counts by department (filtered by team)
            $deptSql = "SELECT
                            d.id,
                            d.name,
                            d.display_order,
                            COUNT(t.id) as count
                        FROM departments d
                        LEFT JOIN tickets t ON t.department_id = d.id
                        WHERE d.is_active = 1 AND d.id IN ($deptIdPlaceholders)
                        GROUP BY d.id, d.name, d.display_order
                        ORDER BY d.display_order, d.name";
            $deptStmt = $conn->prepare($deptSql);
            $deptStmt->execute($accessibleDepts);
            $departments = $deptStmt->fetchAll(PDO::FETCH_ASSOC);
            $deptStmt->closeCursor();

            // Get counts by department and status (filtered by team)
            $deptStatusSql = "SELECT
                                d.id as dept_id,
                                t.status,
                                COUNT(t.id) as count
                              FROM departments d
                              LEFT JOIN tickets t ON t.department_id = d.id
                              WHERE d.is_active = 1 AND d.id IN ($deptIdPlaceholders)
                              GROUP BY d.id, t.status";
            $deptStatusStmt = $conn->prepare($deptStatusSql);
            $deptStatusStmt->execute($accessibleDepts);
            $deptStatusCounts = $deptStatusStmt->fetchAll(PDO::FETCH_ASSOC);
            $deptStatusStmt->closeCursor();

            // Get counts by status for accessible departments
            $statusSql = "SELECT
                            t.status,
                            COUNT(*) as count
                          FROM tickets t
                          WHERE t.department_id IN ($deptIdPlaceholders) OR t.department_id IS NULL
                          GROUP BY t.status";
            $statusStmt = $conn->prepare($statusSql);
            $statusStmt->execute($accessibleDepts);
            $statusCounts = $statusStmt->fetchAll(PDO::FETCH_ASSOC);
            $statusStmt->closeCursor();
        }
    } else {
        // No team assignments - show all departments
        // Get total counts
        $totalSql = "SELECT COUNT(*) as total FROM tickets";
        $totalStmt = $conn->prepare($totalSql);
        $totalStmt->execute();
        $totalResult = $totalStmt->fetch(PDO::FETCH_ASSOC);
        $totalCount = $totalResult['total'];
        $totalStmt->closeCursor();

        // Get counts by department
        $deptSql = "SELECT
                        d.id,
                        d.name,
                        d.display_order,
                        COUNT(t.id) as count
                    FROM departments d
                    LEFT JOIN tickets t ON t.department_id = d.id
                    WHERE d.is_active = 1
                    GROUP BY d.id, d.name, d.display_order
                    ORDER BY d.display_order, d.name";
        $deptStmt = $conn->prepare($deptSql);
        $deptStmt->execute();
        $departments = $deptStmt->fetchAll(PDO::FETCH_ASSOC);
        $deptStmt->closeCursor();

        // Get counts by department and status
        $deptStatusSql = "SELECT
                            d.id as dept_id,
                            t.status,
                            COUNT(t.id) as count
                          FROM departments d
                          LEFT JOIN tickets t ON t.department_id = d.id
                          WHERE d.is_active = 1
                          GROUP BY d.id, t.status";
        $deptStatusStmt = $conn->prepare($deptStatusSql);
        $deptStatusStmt->execute();
        $deptStatusCounts = $deptStatusStmt->fetchAll(PDO::FETCH_ASSOC);
        $deptStatusStmt->closeCursor();

        // Get counts by status (all departments)
        $statusSql = "SELECT
                        status,
                        COUNT(*) as count
                      FROM tickets
                      GROUP BY status";
        $statusStmt = $conn->prepare($statusSql);
        $statusStmt->execute();
        $statusCounts = $statusStmt->fetchAll(PDO::FETCH_ASSOC);
        $statusStmt->closeCursor();
    }

    // Get unassigned count (always visible to users regardless of teams)
    $unassignedSql = "SELECT COUNT(*) as count FROM tickets WHERE department_id IS NULL";
    $unassignedStmt = $conn->prepare($unassignedSql);
    $unassignedStmt->execute();
    $unassignedResult = $unassignedStmt->fetch(PDO::FETCH_ASSOC);
    $unassignedStmt->closeCursor();

    // Build status counts by department map
    $statusByDept = [];
    foreach ($deptStatusCounts as $row) {
        if (!isset($statusByDept[$row['dept_id']])) {
            $statusByDept[$row['dept_id']] = [];
        }
        $statusByDept[$row['dept_id']][$row['status']] = (int)$row['count'];
    }

    // Build department structure with status subfolders
    $departmentStructure = [];
    foreach ($departments as $dept) {
        $deptId = $dept['id'];
        $statuses = isset($statusByDept[$deptId]) ? $statusByDept[$deptId] : [];

        $departmentStructure[] = [
            'id' => $deptId,
            'name' => $dept['name'],
            'count' => (int)$dept['count'],
            'statuses' => [
                'Open' => $statuses['Open'] ?? 0,
                'In Progress' => $statuses['In Progress'] ?? 0,
                'On Hold' => $statuses['On Hold'] ?? 0,
                'Closed' => $statuses['Closed'] ?? 0
            ]
        ];
    }

    // Build overall status counts
    $overallStatuses = [
        'Open' => 0,
        'In Progress' => 0,
        'On Hold' => 0,
        'Closed' => 0
    ];
    foreach ($statusCounts as $row) {
        if (isset($overallStatuses[$row['status']])) {
            $overallStatuses[$row['status']] = (int)$row['count'];
        }
    }

    echo json_encode([
        'success' => true,
        'total_count' => $totalCount,
        'unassigned_count' => (int)$unassignedResult['count'],
        'departments' => $departmentStructure,
        'overall_statuses' => $overallStatuses
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

?>
