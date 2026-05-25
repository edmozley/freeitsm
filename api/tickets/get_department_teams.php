<?php
/**
 * API Endpoint: Get teams assigned to a department
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

$departmentId = $_GET['department_id'] ?? null;

if (!$departmentId) {
    echo json_encode(['success' => false, 'error' => 'Department ID is required']);
    exit;
}

try {
    $conn = connectToDatabase();

    $sql = "SELECT t.id, t.name, t.description
            FROM teams t
            INNER JOIN department_teams dt ON t.id = dt.team_id
            WHERE dt.department_id = ? AND t.is_active = 1
            ORDER BY t.display_order, t.name";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$departmentId]);
    $teams = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'teams' => $teams
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}

?>
