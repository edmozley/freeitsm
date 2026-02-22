<?php
/**
 * API: Begin MFA setup
 * POST - Generates TOTP secret, stores in session pending verification
 * Returns secret and otpauth URI for QR code generation
 * Works for both analysts and self-service users via getMfaAuthContext()
 */
session_start();
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/totp.php';
require_once '../../includes/mfa_helpers.php';

header('Content-Type: application/json');

$ctx = getMfaAuthContext($_GET['ctx'] ?? null);
if (!$ctx) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

try {
    // Generate new TOTP secret
    $secret = generateTotpSecret();

    // Use appropriate account name for the QR code
    if ($ctx['type'] === 'analyst') {
        $accountName = $_SESSION['analyst_username'] ?? $_SESSION['analyst_name'];
    } else {
        $accountName = $_SESSION['ss_user_email'] ?? 'user';
    }
    $uri = getTotpUri($secret, $accountName, 'FreeITSM');

    // Store in session pending verification (not in DB yet)
    $_SESSION['pending_totp_secret'] = $secret;

    echo json_encode([
        'success' => true,
        'secret' => $secret,
        'uri' => $uri
    ]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
