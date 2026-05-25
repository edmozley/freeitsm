<?php
/**
 * API: Recent SLA cron run history for the Cron Activity panel.
 *
 * GET ?limit=20 (cap 100) — returns most-recent runs first.
 *
 * Response: { runs: [...], settings: { min_interval_seconds, retention_days } }
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
    $limit = (int)($_GET['limit'] ?? 20);
    if ($limit < 1) $limit = 20;
    if ($limit > 100) $limit = 100;

    $conn = connectToDatabase();

    $stmt = $conn->prepare("
        SELECT id, started_at, ended_at, duration_ms, invocation, client_ip,
               outcome, sent_count, skipped_count, error_count, notes
          FROM sla_cron_runs
      ORDER BY started_at DESC
         LIMIT $limit
    ");
    $stmt->execute();
    $runs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $settingsStmt = $conn->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('sla_cron_min_interval_seconds','sla_cron_log_retention_days')");
    $settingsRaw = [];
    foreach ($settingsStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $settingsRaw[$r['setting_key']] = $r['setting_value'];
    }

    echo json_encode([
        'success'  => true,
        'runs'     => $runs,
        'settings' => [
            'min_interval_seconds' => (int)($settingsRaw['sla_cron_min_interval_seconds'] ?? 30),
            'retention_days'       => (int)($settingsRaw['sla_cron_log_retention_days'] ?? 30),
        ],
    ]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
