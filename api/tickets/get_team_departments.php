<?php
/**
 * API Endpoint: Get departments linked to a specific team
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

$teamId = $_GET['team_id'] ?? null;

if (!$teamId) {
    echo json_encode(['success' => false, 'error' => 'Team ID required']);
    exit;
}

try {
    $conn = connectToDatabase();

    $sql = "SELECT d.id, d.name, d.description, d.display_order, d.is_active
            FROM departments d
            INNER JOIN department_teams dt ON d.id = dt.department_id
            WHERE dt.team_id = ?
            ORDER BY d.display_order, d.name";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$teamId]);
    $departments = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Convert bit fields to boolean
    foreach ($departments as &$dept) {
        $dept['is_active'] = (bool)$dept['is_active'];
    }

    echo json_encode([
        'success' => true,
        'departments' => $departments
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}

?>
