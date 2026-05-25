<?php
/**
 * API Endpoint: Get analysts linked to a specific team
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

    $sql = "SELECT a.id, a.username, a.full_name, a.email, a.is_active
            FROM analysts a
            INNER JOIN analyst_teams at ON a.id = at.analyst_id
            WHERE at.team_id = ?
            ORDER BY a.full_name";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$teamId]);
    $analysts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Convert bit fields to boolean
    foreach ($analysts as &$analyst) {
        $analyst['is_active'] = (bool)$analyst['is_active'];
    }

    echo json_encode([
        'success' => true,
        'analysts' => $analysts
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}

?>
