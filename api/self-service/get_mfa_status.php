<?php
/**
 * API: Get MFA status for current self-service user
 * GET - Returns whether MFA is enabled
 */
session_start();
require_once '../../config.php';
require_once '../../includes/functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['ss_user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

try {
    $conn = connectToDatabase();

    $stmt = $conn->prepare("SELECT totp_enabled FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['ss_user_id']]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'mfa_enabled' => $row ? (bool)$row['totp_enabled'] : false
    ]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
