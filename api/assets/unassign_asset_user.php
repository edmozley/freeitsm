<?php
/**
 * API Endpoint: Remove a user from an asset
 * Deletes the users_assets record
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
$skipAudit = $data['skip_audit'] ?? false;

if (!$assetId || !$userId) {
    echo json_encode(['success' => false, 'error' => 'Asset ID and User ID are required']);
    exit;
}

try {
    $conn = connectToDatabase();

    // Delete the assignment
    $sql = "DELETE FROM users_assets WHERE asset_id = ? AND user_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$assetId, $userId]);

    if ($stmt->rowCount() > 0) {
        // Log to asset_history (skip if this is part of a re-assign, the assign endpoint will log it)
        if (!$skipAudit) {
            $userStmt = $conn->prepare("SELECT display_name FROM users WHERE id = ?");
            $userStmt->execute([$userId]);
            $userRow = $userStmt->fetch(PDO::FETCH_ASSOC);
            $userName = $userRow ? $userRow['display_name'] : $userId;

            $auditSql = "INSERT INTO asset_history (asset_id, analyst_id, field_name, old_value, new_value, created_datetime)
                         VALUES (?, ?, 'Assigned User', ?, NULL, GETUTCDATE())";
            $auditStmt = $conn->prepare($auditSql);
            $auditStmt->execute([$assetId, $_SESSION['analyst_id'], $userName]);
        }

        echo json_encode([
            'success' => true,
            'message' => 'User removed from asset successfully'
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'error' => 'Assignment not found'
        ]);
    }

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>
