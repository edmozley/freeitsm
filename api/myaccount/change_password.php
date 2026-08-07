<?php
/**
 * API: Change own password
 * POST - Validates current password and updates to new password
 */
session_start();
require_once '../../config.php';
require_once '../../includes/functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$currentPassword = $input['current_password'] ?? '';
$newPassword = $input['new_password'] ?? '';
$confirmPassword = $input['confirm_password'] ?? '';

if (empty($currentPassword) || empty($newPassword) || empty($confirmPassword)) {
    echo json_encode(['success' => false, 'error' => 'All fields are required']);
    exit;
}

if (strlen($newPassword) < 8) {
    echo json_encode(['success' => false, 'error' => 'New password must be at least 8 characters']);
    exit;
}

if ($newPassword !== $confirmPassword) {
    echo json_encode(['success' => false, 'error' => 'New passwords do not match']);
    exit;
}

try {
    $conn = connectToDatabase();
    $analystId = $_SESSION['analyst_id'];

    // Verify current password
    $sql = "SELECT password_hash FROM analysts WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$analystId]);
    $analyst = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$analyst || !password_verify($currentPassword, $analyst['password_hash'])) {
        echo json_encode(['success' => false, 'error' => 'Current password is incorrect']);
        exit;
    }

    // Update password and track change datetime
    $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
    try {
        $updateSql = "UPDATE analysts SET password_hash = ?, last_modified_datetime = UTC_TIMESTAMP(), password_changed_datetime = UTC_TIMESTAMP() WHERE id = ?";
        $updateStmt = $conn->prepare($updateSql);
        $updateStmt->execute([$newHash, $analystId]);
    } catch (Exception $colEx) {
        // Fallback if password_changed_datetime column doesn't exist yet
        $updateSql = "UPDATE analysts SET password_hash = ?, last_modified_datetime = UTC_TIMESTAMP() WHERE id = ?";
        $updateStmt = $conn->prepare($updateSql);
        $updateStmt->execute([$newHash, $analystId]);
    }

    // Clear password expired flag if set
    unset($_SESSION['password_expired']);

    // ...and clear the account-level "must change" flag, which is what the seeded
    // admin account carries. Guarded: an install that has not run Database Verify
    // since this shipped has no such column, and a change_password that fataled
    // because of it would be worse than the problem.
    try {
        $conn->prepare("UPDATE analysts SET must_change_password = 0 WHERE id = ?")->execute([$analystId]);
    } catch (Exception $mcEx) {
        // column not migrated yet — nothing to clear
    }

    // Changing a password is often somebody's response to thinking they have been
    // compromised. Without rotating the id here, the session an attacker already
    // holds survives the very action taken to shut them out.
    sessionPromoteToAuthenticated();

    echo json_encode(['success' => true, 'message' => 'Password changed successfully']);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
