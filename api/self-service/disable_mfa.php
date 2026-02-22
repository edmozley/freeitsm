<?php
/**
 * API: Disable MFA for self-service user
 * POST - Verifies password and disables MFA
 */
session_start();
require_once '../../config.php';
require_once '../../includes/functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['ss_user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$password = $input['password'] ?? '';

if (empty($password)) {
    echo json_encode(['success' => false, 'error' => 'Password is required to disable MFA']);
    exit;
}

try {
    $conn = connectToDatabase();
    $userId = $_SESSION['ss_user_id'];

    $stmt = $conn->prepare("SELECT password_hash FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user || !password_verify($password, $user['password_hash'])) {
        echo json_encode(['success' => false, 'error' => 'Incorrect password']);
        exit;
    }

    $updateStmt = $conn->prepare("UPDATE users SET totp_enabled = 0, totp_secret = NULL WHERE id = ?");
    $updateStmt->execute([$userId]);

    echo json_encode(['success' => true, 'message' => 'MFA has been disabled']);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
