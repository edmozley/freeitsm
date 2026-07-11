<?php
/**
 * API: set the site-wide module permission mode (issue #30).
 * POST { mode: 'most' | 'least' }. Admins only.
 *   most  = an analyst may use a module if ANY source (self or any team) grants it.
 *   least = only if their own access AND every team they're in grant it.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/admin_api_guard.php'; // System admins only (issue #34)
require_once '../../includes/functions.php';

header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true);
$mode = ($input['mode'] ?? '') === 'least' ? 'least' : 'most';

try {
    $conn = connectToDatabase();
    // Upsert the setting.
    $conn->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES ('module_permission_mode', ?)
                    ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")->execute([$mode]);
    echo json_encode(['success' => true, 'mode' => $mode]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
