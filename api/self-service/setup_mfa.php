<?php
/**
 * API: Begin MFA setup for self-service user
 * POST - Generates TOTP secret, stores in session pending verification
 */
session_start();
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/totp.php';

header('Content-Type: application/json');

if (!isset($_SESSION['ss_user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

try {
    $secret = generateTotpSecret();
    $accountName = $_SESSION['ss_user_email'] ?? 'user';
    $uri = getTotpUri($secret, $accountName, 'FreeITSM');

    $_SESSION['pending_totp_secret'] = $secret;

    echo json_encode([
        'success' => true,
        'secret' => $secret,
        'uri' => $uri
    ]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
