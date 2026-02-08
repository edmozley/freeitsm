<?php
/**
 * API: Get MFA status for current analyst
 * GET - Returns whether MFA is enabled
 */
session_start();
require_once '../../config.php';
require_once '../../includes/functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

try {
    $conn = connectToDatabase();

    $sql = "SELECT totp_enabled FROM analysts WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$_SESSION['analyst_id']]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'mfa_enabled' => $row ? (bool)$row['totp_enabled'] : false
    ]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
