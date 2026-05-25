<?php
/**
 * API Endpoint: Get Change Management Settings
 * Returns module settings from system_settings table
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

    $stmt = $conn->prepare("SELECT setting_key, setting_value FROM system_settings WHERE setting_key LIKE 'change_%'");
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $settings = [];
    foreach ($rows as $row) {
        $key = str_replace('change_', '', $row['setting_key']);
        $settings[$key] = $row['setting_value'];
    }

    // Parse field_visibility JSON if present
    if (isset($settings['field_visibility'])) {
        $settings['field_visibility'] = json_decode($settings['field_visibility'], true) ?: [];
    }

    echo json_encode(['success' => true, 'settings' => $settings]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
