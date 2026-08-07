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

    // Same attempt counter as the analyst side — see api/myaccount/verify_login_otp.php
    // for why the session is the right place for it. A six-digit code with unlimited
    // guesses is not a second factor.
    if (!verifyTotpCode($secret, $code)) {
        $_SESSION['ss_mfa_attempts'] = ($_SESSION['ss_mfa_attempts'] ?? 0) + 1;

        if ($_SESSION['ss_mfa_attempts'] >= 5) {
            unset(
                $_SESSION['mfa_pending_ss_user_id'],
                $_SESSION['mfa_pending_ss_email'],
                $_SESSION['mfa_pending_ss_name'],
                $_SESSION['ss_mfa_attempts']
            );
            error_log('Portal MFA challenge abandoned after 5 failed codes from '
                      . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
            echo json_encode(['success' => false, 'error' => 'Too many incorrect codes. Please sign in again.', 'restart' => true]);
            exit;
        }

        echo json_encode(['success' => false, 'error' => 'Invalid code. Please try again.']);
        exit;
    }

    unset($_SESSION['ss_mfa_attempts']);

    // Complete login — rotate the session id first, see includes/session_security.php
    sessionPromoteToAuthenticated();
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
