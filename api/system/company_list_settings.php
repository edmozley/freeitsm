<?php
/**
 * System -> Companies -> Company lists (GH #358): how long a company list may get
 * before it becomes a search box (assets/js/select-search.js).
 *
 *   GET                       {success, after, default}
 *   POST {after: 0..1000}     saves it; 0 = always a search box
 *
 * Administrators only. Read on every page by DateFmt::init() (includes/timezone.php).
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/timezone.php';
require_once '../../includes/admin_api_guard.php';   // administrators only

header('Content-Type: application/json');

try {
    $conn = connectToDatabase();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrfRequireWriteMethod();
        $in = json_decode(file_get_contents('php://input'), true) ?: [];
        $after = $in['after'] ?? null;
        if (!is_numeric($after) || (int)$after < 0 || (int)$after > 1000) {
            echo json_encode(['success' => false, 'error' => 'Enter a number from 0 to 1000.']);
            exit;
        }
        $conn->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES ('company_list_search_after', ?)
                        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")->execute([(string)(int)$after]);
        echo json_encode(['success' => true, 'after' => (int)$after]);
        exit;
    }
    DateFmt::init($conn);
    echo json_encode(['success' => true, 'after' => DateFmt::listAfter(), 'default' => DateFmt::DEFAULT_LIST_AFTER]);
} catch (Throwable $e) {
    error_log('company_list_settings: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Could not read or save the setting.']);
}
