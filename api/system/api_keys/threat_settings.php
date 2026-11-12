<?php
/**
 * System -> API -> Threat protection: the install's API security settings (GH #287).
 *
 * GET  -> { success, ready, settings, defaults }
 * POST -> { settings: {...} } saves the ones sent, each checked; returns the result.
 *
 * Administrators only (admin_api_guard). See includes/api_security.php for what
 * each setting does.
 */
session_start(['read_and_close' => true]);
require_once '../../../config.php';
require_once '../../../includes/admin_api_guard.php'; // System admins only
require_once '../../../includes/functions.php';
require_once '../../../includes/api_security.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

try {
    $conn = connectToDatabase();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrfRequireWriteMethod();
        $in = json_decode(file_get_contents('php://input'), true);
        $in = is_array($in['settings'] ?? null) ? $in['settings'] : [];
        // Whole numbers within sensible bounds; 0 switches a watcher off where that makes sense.
        $ints = [
            'api_download_alert_files' => [1, 100000], 'api_download_alert_mb' => [1, 1000000],
            'api_download_limit_files' => [1, 100000], 'api_download_limit_mb' => [1, 1000000],
            'api_failed_auth_limit'    => [0, 10000],  'api_failed_auth_minutes' => [1, 1440],
            'api_refusal_alert'        => [0, 100000], 'api_refusal_limit' => [0, 100000],
            'api_log_keep_days'        => [1, 3650],
        ];
        $choices = ['api_download_limit_action' => ['refuse', 'suspend'], 'api_new_ip_alert' => ['bell_email', 'bell', 'off'],
                    'api_admin_keys' => ['allow', 'require_ips', 'forbid'], 'api_log_successes' => ['1', '0'], 'api_log_old_rows' => ['delete', 'archive'],
                    'api_log_archive_period' => ['day', 'week', 'month']];
        $save = [];
        foreach ($in as $k => $v) {
            if (isset($ints[$k])) {
                if (!is_numeric($v)) throw new Exception("$k must be a whole number.");
                [$lo, $hi] = $ints[$k];
                $save[$k] = (string)max($lo, min($hi, (int)$v));
            } elseif (isset($choices[$k])) {
                if (!in_array($v, $choices[$k], true)) throw new Exception("$k is not one of the choices.");
                $save[$k] = (string)$v;
            }
        }
        $merged = array_merge(apiSecuritySettings($conn), $save);
        if ((int)$merged['api_download_alert_files'] > (int)$merged['api_download_limit_files']
            || (int)$merged['api_download_alert_mb'] > (int)$merged['api_download_limit_mb']) {
            throw new Exception('The alert level must not be higher than the limit.');
        }
        if ($merged['api_log_old_rows'] === 'archive' && !class_exists('ZipArchive')) {
            throw new Exception('Archiving needs the PHP zip extension, which this server does not have - choose Delete.');
        }
        if ((int)$merged['api_refusal_limit'] > 0 && (int)$merged['api_refusal_alert'] > (int)$merged['api_refusal_limit']) {
            throw new Exception('The refusal alert must not be higher than the point a key is switched off.');
        }
        $st = $conn->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
        foreach ($save as $k => $v) $st->execute([$k, $v]);
    }
    echo json_encode([
        'success'  => true,
        'ready'    => apiSecurityReady($conn),
        'settings' => apiSecuritySettings($conn),
        'defaults' => API_SECURITY_DEFAULTS,
    ]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
