<?php
/**
 * API Endpoint: Get forms module settings
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

    $stmt = $conn->prepare("SELECT setting_key, setting_value FROM system_settings WHERE setting_key LIKE 'forms_%'");
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $settings = [];
    foreach ($rows as $row) {
        $key = str_replace('forms_', '', $row['setting_key']);
        $settings[$key] = $row['setting_value'];
    }

    // Defaults
    if (!isset($settings['logo_alignment'])) $settings['logo_alignment'] = 'center';

    echo json_encode(['success' => true, 'settings' => $settings]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
