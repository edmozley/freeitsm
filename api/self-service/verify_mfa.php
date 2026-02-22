<?php
/**
 * API: Verify MFA setup for self-service user
 * POST - Verifies OTP code against pending secret, enables MFA if valid
 */
session_start();
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/totp.php';
require_once '../../includes/encryption.php';

header('Content-Type: application/json');

if (!isset($_SESSION['ss_user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$code = $input['code'] ?? '';

if (empty($code)) {
    echo json_encode(['success' => false, 'error' => 'Verification code is required']);
    exit;
}

if (!isset($_SESSION['pending_totp_secret'])) {
    echo json_encode(['success' => false, 'error' => 'No MFA setup in progress. Please start setup again.']);
    exit;
}

$secret = $_SESSION['pending_totp_secret'];

if (!verifyTotpCode($secret, $code)) {
    echo json_encode(['success' => false, 'error' => 'Invalid verification code. Please try again.']);
    exit;
}

try {
    $conn = connectToDatabase();

    $encryptedSecret = encryptValue($secret);

    $stmt = $conn->prepare("UPDATE users SET totp_secret = ?, totp_enabled = 1 WHERE id = ?");
    $stmt->execute([$encryptedSecret, $_SESSION['ss_user_id']]);

    unset($_SESSION['pending_totp_secret']);

    echo json_encode(['success' => true, 'message' => 'MFA has been enabled']);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
