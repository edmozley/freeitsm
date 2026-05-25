<?php
/**
 * API Endpoint: Save teams assigned to a department
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

// Get POST data
$input = json_decode(file_get_contents('php://input'), true);

$departmentId = $input['department_id'] ?? null;
$teamIds = $input['team_ids'] ?? [];

if (!$departmentId) {
    echo json_encode(['success' => false, 'error' => 'Department ID is required']);
    exit;
}

try {
    $conn = connectToDatabase();

    // Start transaction
    $conn->beginTransaction();

    // Delete existing team assignments for this department
    $deleteSql = "DELETE FROM department_teams WHERE department_id = ?";
    $deleteStmt = $conn->prepare($deleteSql);
    $deleteStmt->execute([$departmentId]);

    // Insert new team assignments
    if (!empty($teamIds)) {
        $insertSql = "INSERT INTO department_teams (department_id, team_id) VALUES (?, ?)";
        $insertStmt = $conn->prepare($insertSql);

        foreach ($teamIds as $teamId) {
            $insertStmt->execute([$departmentId, $teamId]);
        }
    }

    $conn->commit();

    echo json_encode([
        'success' => true,
        'message' => 'Department teams updated successfully'
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
