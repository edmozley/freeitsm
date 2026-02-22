<?php
/**
 * API: Verify OTP during self-service login
 * POST - Validates TOTP code for pending MFA login
 */
session_start();
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/totp.php';
require_once '../../includes/encryption.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$code = trim($input['code'] ?? '');

if (empty($code)) {
    echo json_encode(['success' => false, 'error' => 'Verification code is required']);
    exit;
}

if (!isset($_SESSION['mfa_pending_ss_user_id'])) {
    echo json_encode(['success' => false, 'error' => 'No MFA verification pending. Please log in again.']);
    exit;
}

try {
    $conn = connectToDatabase();
    $userId = $_SESSION['mfa_pending_ss_user_id'];

    $stmt = $conn->prepare("SELECT totp_secret FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user || empty($user['totp_secret'])) {
        echo json_encode(['success' => false, 'error' => 'MFA configuration not found']);
        exit;
    }

    $secret = decryptValue($user['totp_secret']);

    if (!verifyTotpCode($secret, $code)) {
        echo json_encode(['success' => false, 'error' => 'Invalid code. Please try again.']);
        exit;
    }

    // Complete login
    $_SESSION['ss_user_id'] = (int)$_SESSION['mfa_pending_ss_user_id'];
    $_SESSION['ss_user_email'] = $_SESSION['mfa_pending_ss_email'];
    $_SESSION['ss_user_name'] = $_SESSION['mfa_pending_ss_name'];

    // Clear pending state
    unset(
        $_SESSION['mfa_pending_ss_user_id'],
        $_SESSION['mfa_pending_ss_email'],
        $_SESSION['mfa_pending_ss_name']
    );

    echo json_encode(['success' => true]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'Verification failed. Please try again.']);
}
