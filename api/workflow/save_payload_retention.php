<?php
/**
 * API: set how long sent webhook payload bodies are kept.
 *
 * Body: { days }  —  0 = don't store at all, -1 = as long as the delivery row
 * lives, otherwise a positive number of days.
 *
 * This governs `webhook_deliveries.request_body` / `response_snippet`, which
 * hold the exact payload that was sent — with the Full-record preset that's an
 * entire ticket in plain text. Distinct from `webhook_delivery_retention_days`,
 * which deletes the whole row; this one scrubs just the bodies and keeps the
 * delivery record for audit.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once __DIR__ . '/../../includes/admin_api_guard.php';
require_once __DIR__ . '/../../includes/webhook_delivery.php';
header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
// Lives under System, so it's admin-only like every other System endpoint.
requireAdminJson(connectToDatabase());

$in   = json_decode(file_get_contents('php://input'), true);
$days = isset($in['days']) ? (int)$in['days'] : null;

// Whitelist rather than accept any integer — these are the only values the UI
// offers, and an arbitrary number here would be a silent foot-gun.
$allowed = [WEBHOOK_RETENTION_NEVER, 1, 7, 30, WEBHOOK_RETENTION_FOREVER];
if ($days === null || !in_array($days, $allowed, true)) {
    echo json_encode(['success' => false, 'error' => 'Invalid retention value']);
    exit;
}

try {
    $conn = connectToDatabase();
    $conn->prepare(
        "INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
    )->execute([WEBHOOK_RETENTION_SETTING, (string)$days]);

    // Apply immediately rather than waiting for the next cron sweep — if an admin
    // has just tightened retention, they mean now, not in a minute.
    $scrubbed = webhookPurgePayloads($conn);

    echo json_encode(['success' => true, 'days' => $days, 'scrubbed' => $scrubbed]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
