<?php
/**
 * API: Verify MFA setup
 * POST - Verifies OTP code against pending secret, enables MFA if valid
 */
session_start();
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/totp.php';
require_once '../../includes/encryption.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
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

// Verify the code
if (!verifyTotpCode($secret, $code)) {
    echo json_encode(['success' => false, 'error' => 'Invalid verification code. Please try again.']);
    exit;
}

try {
    $conn = connectToDatabase();

    // Encrypt the secret before storing
    $encryptedSecret = encryptValue($secret);

    $sql = "UPDATE analysts SET totp_secret = ?, totp_enabled = 1, last_modified_datetime = GETDATE() WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$encryptedSecret, $_SESSION['analyst_id']]);

    // Clear pending secret from session
    unset($_SESSION['pending_totp_secret']);

    echo json_encode(['success' => true, 'message' => 'MFA has been enabled']);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
