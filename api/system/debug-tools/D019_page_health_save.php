<?php
/**
 * Debug Tool D019 — Page health: remember the last run.
 *
 * The check runs in the browser; this stores its one-line result so System ->
 * Database Verification can say "Page health: last checked today, all clear"
 * and point at D019 when it was not (or was never run).
 *
 * Stores counts, a date and who ran it in system_settings 'page_health_last' -
 * never page content. Only the numbers the browser sends are kept, each one
 * clamped, so this cannot be used to store anything else.
 */

@session_start();

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/functions.php';
require_once __DIR__ . '/../../../includes/version.php';

csrfRequireWriteMethod();
header('Content-Type: application/json; charset=utf-8');

try {
    $conn = connectToDatabase();
    $__dbgAdmin = !empty($_SESSION['analyst_id']) && analystIsAdmin($conn, (int)$_SESSION['analyst_id']);
} catch (Throwable $e) {
    $__dbgAdmin = false;
}
if (!$__dbgAdmin) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Administrator access required.']);
    exit;
}

$in = json_decode((string) file_get_contents('php://input'), true) ?: [];
$n = fn($k) => max(0, min(100000, (int) ($in[$k] ?? 0)));
$result = [
    'ran_at'   => gmdate('Y-m-d H:i:s'),
    'by'       => (string) ($_SESSION['analyst_name'] ?? ''),
    'version'  => freeitsmVersion(),
    'pages'    => $n('pages'),
    'scripts'  => $n('scripts'),
    'problems' => $n('problems'),
    'skipped'  => $n('skipped'),
];

try {
    $conn->prepare(
        "INSERT INTO system_settings (setting_key, setting_value) VALUES ('page_health_last', ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
    )->execute([json_encode($result)]);
    echo json_encode(['success' => true, 'last' => $result]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Could not save the result.']);
}
