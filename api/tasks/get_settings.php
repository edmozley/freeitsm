<?php
/**
 * API: Tasks — Get module settings
 * Returns tasks_* keys from system_settings (prefix stripped).
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

    $stmt = $conn->prepare("SELECT setting_key, setting_value FROM system_settings WHERE setting_key LIKE 'tasks_%'");
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $settings = [];
    foreach ($rows as $row) {
        $key = substr($row['setting_key'], strlen('tasks_'));
        $settings[$key] = $row['setting_value'];
    }

    // Defaults for keys that haven't been saved yet
    if (!isset($settings['calendar_span_mode'])) {
        $settings['calendar_span_mode'] = 'deadline';
    }

    echo json_encode(['success' => true, 'settings' => $settings]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
