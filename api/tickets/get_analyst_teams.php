<?php
/**
 * API Endpoint: Get teams assigned to an analyst
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

$analystId = $_GET['analyst_id'] ?? null;

if (!$analystId) {
    echo json_encode(['success' => false, 'error' => 'Analyst ID is required']);
    exit;
}

try {
    $conn = connectToDatabase();

    $sql = "SELECT t.id, t.name, t.description
            FROM teams t
            INNER JOIN analyst_teams at ON t.id = at.team_id
            WHERE at.analyst_id = ? AND t.is_active = 1
            ORDER BY t.display_order, t.name";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$analystId]);
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
