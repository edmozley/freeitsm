<?php
/**
 * API: Verify OTP during login
 * POST - Verifies TOTP code for MFA login challenge, completes login if valid
 */
session_start();
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/totp.php';
require_once '../../includes/encryption.php';

header('Content-Type: application/json');

// Check for pending MFA state
if (!isset($_SESSION['mfa_pending_analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'No MFA challenge pending']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$code = $input['code'] ?? '';

if (empty($code)) {
    echo json_encode(['success' => false, 'error' => 'Verification code is required']);
    exit;
}

try {
    $conn = connectToDatabase();
    $analystId = $_SESSION['mfa_pending_analyst_id'];

    // Get the encrypted TOTP secret
    $sql = "SELECT totp_secret FROM analysts WHERE id = ? AND totp_enabled = 1";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$analystId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row || empty($row['totp_secret'])) {
        echo json_encode(['success' => false, 'error' => 'MFA configuration error']);
        exit;
    }

    // Decrypt the secret
    $secret = decryptValue($row['totp_secret']);

    // Verify the code
    if (!verifyTotpCode($secret, $code)) {
        echo json_encode(['success' => false, 'error' => 'Invalid code. Please try again.']);
        exit;
    }

    // MFA verified — complete login
    $_SESSION['analyst_id'] = $_SESSION['mfa_pending_analyst_id'];
    $_SESSION['analyst_username'] = $_SESSION['mfa_pending_username'];
    $_SESSION['analyst_name'] = $_SESSION['mfa_pending_name'];
    $_SESSION['analyst_email'] = $_SESSION['mfa_pending_email'];
    $_SESSION['allowed_modules'] = $_SESSION['mfa_pending_allowed_modules'];

    // Clear pending state
    unset($_SESSION['mfa_pending_analyst_id']);
    unset($_SESSION['mfa_pending_username']);
    unset($_SESSION['mfa_pending_name']);
    unset($_SESSION['mfa_pending_email']);
    unset($_SESSION['mfa_pending_allowed_modules']);

    // Update last login time
    $updateSql = "UPDATE analysts SET last_login_datetime = GETDATE() WHERE id = ?";
    $updateStmt = $conn->prepare($updateSql);
    $updateStmt->execute([$analystId]);

    echo json_encode(['success' => true, 'message' => 'Login successful']);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
