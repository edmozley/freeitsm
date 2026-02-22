<?php
/**
 * API: Get MFA status for current user
 * GET - Returns whether MFA is enabled
 * Works for both analysts and self-service users via getMfaAuthContext()
 */
session_start();
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/mfa_helpers.php';

header('Content-Type: application/json');

$ctx = getMfaAuthContext($_GET['ctx'] ?? null);
if (!$ctx) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

try {
    $conn = connectToDatabase();

    if ($ctx['type'] === 'analyst') {
        // Analysts have trust_device_enabled column
        try {
            $sql = "SELECT totp_enabled, trust_device_enabled FROM {$ctx['table']} WHERE id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->execute([$ctx['id']]);
        } catch (Exception $colEx) {
            $sql = "SELECT totp_enabled FROM {$ctx['table']} WHERE id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->execute([$ctx['id']]);
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
    } else {
        // Self-service users: simple totp_enabled check only
        $stmt = $conn->prepare("SELECT totp_enabled FROM {$ctx['table']} WHERE id = ?");
        $stmt->execute([$ctx['id']]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true,
            'mfa_enabled' => $row ? (bool)$row['totp_enabled'] : false
        ]);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
