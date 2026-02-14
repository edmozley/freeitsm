<?php
/**
 * API Endpoint: Assign a user to an asset
 * Creates a new users_assets record
 */
session_start();
require_once '../../config.php';
require_once '../../includes/functions.php';

header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

// Get POST data
$data = json_decode(file_get_contents('php://input'), true);

$assetId = $data['asset_id'] ?? null;
$userId = $data['user_id'] ?? null;
$notes = $data['notes'] ?? null;

if (!$assetId || !$userId) {
    echo json_encode(['success' => false, 'error' => 'Asset ID and User ID are required']);
    exit;
}

try {
    $conn = connectToDatabase();

    // Check if users_assets table exists
    $tableCheck = $conn->query("SELECT OBJECT_ID('users_assets', 'U') as table_exists");
    $tableExists = $tableCheck->fetch(PDO::FETCH_ASSOC)['table_exists'] !== null;

    if (!$tableExists) {
        echo json_encode([
            'success' => false,
            'error' => 'The users_assets table has not been created yet. Please run the SQL script: database/create_users_assets_table.sql'
        ]);
        exit;
    }

    // Check if assignment already exists
    $checkSql = "SELECT id FROM users_assets WHERE asset_id = ? AND user_id = ?";
    $checkStmt = $conn->prepare($checkSql);
    $checkStmt->execute([$assetId, $userId]);

    if ($checkStmt->fetch()) {
        echo json_encode(['success' => false, 'error' => 'User is already assigned to this asset']);
        exit;
    }

    // Get the user's display name for the audit log
    $userStmt = $conn->prepare("SELECT display_name FROM users WHERE id = ?");
    $userStmt->execute([$userId]);
    $userRow = $userStmt->fetch(PDO::FETCH_ASSOC);
    $userName = $userRow ? $userRow['display_name'] : $userId;

    // Insert the assignment
    $sql = "INSERT INTO users_assets (asset_id, user_id, assigned_by_analyst_id, notes, assigned_datetime)
            VALUES (?, ?, ?, ?, GETUTCDATE())";

    $stmt = $conn->prepare($sql);
    $stmt->execute([$assetId, $userId, $_SESSION['analyst_id'], $notes]);

    // Log to asset_history
    $auditSql = "INSERT INTO asset_history (asset_id, analyst_id, field_name, old_value, new_value, created_datetime)
                 VALUES (?, ?, 'Assigned User', NULL, ?, GETUTCDATE())";
    $auditStmt = $conn->prepare($auditSql);
    $auditStmt->execute([$assetId, $_SESSION['analyst_id'], $userName]);

    echo json_encode([
        'success' => true,
        'message' => 'User assigned successfully'
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>
