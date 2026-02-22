<?php
/**
 * API: Change password for self-service user
 * POST - Verifies current password and updates to new one
 */
session_start();
require_once '../../config.php';
require_once '../../includes/functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['ss_user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
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

if ($newPassword !== $confirmPassword) {
    echo json_encode(['success' => false, 'error' => 'New passwords do not match']);
    exit;
}

if (strlen($newPassword) < 8) {
    echo json_encode(['success' => false, 'error' => 'Password must be at least 8 characters']);
    exit;
}

try {
    $conn = connectToDatabase();
    $userId = $_SESSION['ss_user_id'];

    $stmt = $conn->prepare("SELECT password_hash FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user || !password_verify($currentPassword, $user['password_hash'])) {
        echo json_encode(['success' => false, 'error' => 'Current password is incorrect']);
        exit;
    }

    $newHash = password_hash($newPassword, PASSWORD_BCRYPT);

    $updateStmt = $conn->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
    $updateStmt->execute([$newHash, $userId]);

    echo json_encode(['success' => true, 'message' => 'Password changed successfully']);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'Failed to change password']);
}
