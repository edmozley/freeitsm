<?php
/**
 * API: Toggle trusted device preference for current analyst
 * POST - Flips trust_device_enabled on/off. If disabling, clears all trusted devices.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

try {
    $conn = connectToDatabase();
    $analystId = $_SESSION['analyst_id'];

    // Get current state
    $stmt = $conn->prepare("SELECT trust_device_enabled FROM analysts WHERE id = ?");
    $stmt->execute([$analystId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        echo json_encode(['success' => false, 'error' => 'Analyst not found']);
        exit;
    }

    $newState = empty($row['trust_device_enabled']) ? 1 : 0;

    // Update the flag
    $updateStmt = $conn->prepare("UPDATE analysts SET trust_device_enabled = ? WHERE id = ?");
    $updateStmt->execute([$newState, $analystId]);

    // If disabling, clear all trusted devices for this analyst
    if ($newState === 0) {
        $delStmt = $conn->prepare("DELETE FROM trusted_devices WHERE analyst_id = ?");
        $delStmt->execute([$analystId]);
    }

    echo json_encode(['success' => true, 'enabled' => (bool)$newState]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
