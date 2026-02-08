<?php
/**
 * API: Begin MFA setup
 * POST - Generates TOTP secret, stores in session pending verification
 * Returns secret and otpauth URI for QR code generation
 */
session_start();
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/totp.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

try {
    // Generate new TOTP secret
    $secret = generateTotpSecret();
    $username = $_SESSION['analyst_username'] ?? $_SESSION['analyst_name'];
    $uri = getTotpUri($secret, $username, 'FreeITSM');

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
