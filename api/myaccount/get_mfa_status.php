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

    // Try extended query; fall back if trust_device_enabled column doesn't exist yet
    try {
        $sql = "SELECT totp_enabled, trust_device_enabled FROM analysts WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->execute([$_SESSION['analyst_id']]);
    } catch (Exception $colEx) {
        $sql = "SELECT totp_enabled FROM analysts WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->execute([$_SESSION['analyst_id']]);
    }
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    // Get system-wide trusted device days setting
    $trustDays = 0;
    try {
        $tdStmt = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'trusted_device_days'");
        $tdStmt->execute();
        $tdRow = $tdStmt->fetch(PDO::FETCH_ASSOC);
        $trustDays = (int)($tdRow['setting_value'] ?? 0);
    } catch (Exception $ignore) {}

    echo json_encode([
        'success' => true,
        'mfa_enabled' => $row ? (bool)$row['totp_enabled'] : false,
        'trust_device_enabled' => isset($row['trust_device_enabled']) ? (bool)$row['trust_device_enabled'] : false,
        'trusted_device_days' => $trustDays
    ]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
