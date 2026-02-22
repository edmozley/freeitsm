<?php
/**
 * API: Self-Service Portal Login
 * POST - Authenticates a user by email and password
 * Returns mfa_required: true if TOTP is enabled
 */
session_start();
require_once '../../config.php';
require_once '../../includes/functions.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$email = strtolower(trim($input['email'] ?? ''));
$password = $input['password'] ?? '';

if (empty($email) || empty($password)) {
    echo json_encode(['success' => false, 'error' => 'Email and password are required']);
    exit;
}

try {
    $conn = connectToDatabase();

    $stmt = $conn->prepare("SELECT id, email, display_name, preferred_name, password_hash, totp_enabled FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user || empty($user['password_hash']) || !password_verify($password, $user['password_hash'])) {
        echo json_encode(['success' => false, 'error' => 'Invalid email or password']);
        exit;
    }

    $displayName = $user['preferred_name'] ?: $user['display_name'] ?: $user['email'];

    // Check if MFA is enabled
    if ($user['totp_enabled']) {
        // Store pending MFA state (don't complete login yet)
        $_SESSION['mfa_pending_ss_user_id'] = (int)$user['id'];
        $_SESSION['mfa_pending_ss_email'] = $user['email'];
        $_SESSION['mfa_pending_ss_name'] = $displayName;

        echo json_encode(['success' => true, 'mfa_required' => true]);
        exit;
    }

    // No MFA - complete login
    $_SESSION['ss_user_id'] = (int)$user['id'];
    $_SESSION['ss_user_email'] = $user['email'];
    $_SESSION['ss_user_name'] = $displayName;

    echo json_encode(['success' => true]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'Login failed. Please try again.']);
}
