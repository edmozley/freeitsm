<?php
/**
 * API Endpoint: Get users assigned to an asset
 * Returns list of users with assignment details
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

// Get asset_id parameter
$assetId = $_GET['asset_id'] ?? null;

if (!$assetId) {
    echo json_encode(['success' => false, 'error' => 'Asset ID is required']);
    exit;
}

try {
    $conn = connectToDatabase();

    // Check if users_assets table exists
    $tableCheck = $conn->prepare("SELECT COUNT(*) as cnt FROM information_schema.tables WHERE table_schema = ? AND table_name = 'users_assets'");
    $tableCheck->execute([DB_NAME]);
    $tableExists = (int)$tableCheck->fetch(PDO::FETCH_ASSOC)['cnt'] > 0;

    if (!$tableExists) {
        // Table doesn't exist yet, return empty array
        echo json_encode([
            'success' => true,
            'users' => [],
            'message' => 'users_assets table not created yet'
        ]);
        exit;
    }

    $sql = "SELECT
                ua.id as assignment_id,
                ua.user_id,
                ua.assigned_datetime,
                ua.notes,
                u.display_name,
                u.email,
                an.full_name as assigned_by_name
            FROM users_assets ua
            INNER JOIN users u ON ua.user_id = u.id
            LEFT JOIN analysts an ON ua.assigned_by_analyst_id = an.id
            WHERE ua.asset_id = ?
            ORDER BY ua.assigned_datetime DESC";

    $stmt = $conn->prepare($sql);
    $stmt->execute([$assetId]);
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'users' => $users
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>
