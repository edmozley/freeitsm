<?php
/**
 * Outbound webhook delivery worker — cron entry point.
 *
 * Delivers queued rows from `webhook_deliveries` (enqueued by the `send_webhook`
 * workflow action) asynchronously, with retries + exponential backoff and
 * dead-lettering after max_attempts — so a slow or dead endpoint never blocks
 * the request that fired the webhook. Delivery latency is roughly your cron
 * interval; every 1 minute is a good default. See docs/webhook-cron-setup.md.
 *
 * SECURITY (HTTP invocation only), mirroring cron/sla_breach_check.php:
 *   - Shared-secret token via ?token=<value> matching `webhook_cron_token`
 *     in system_settings (hash_equals; seeded by Database Verification).
 *   - Min interval between runs (`webhook_cron_min_interval_seconds`, default
 *     20s), enforced for CLI + HTTP, defeats double-scheduling / runaway loops.
 * CLI invocation skips the token (there is no untrusted caller).
 */

set_time_limit(120);
if (PHP_SAPI !== 'cli') header('Content-Type: text/plain; charset=utf-8');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/webhook_delivery.php';

$isCli    = (PHP_SAPI === 'cli');
$clientIp = $isCli ? null : ($_SERVER['REMOTE_ADDR'] ?? null);

try {
    $conn = connectToDatabase();

    $settingsStmt = $conn->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('webhook_cron_token','webhook_cron_min_interval_seconds','webhook_cron_last_run','webhook_delivery_retention_days')");
    $settings = [];
    foreach ($settingsStmt->fetchAll(PDO::FETCH_ASSOC) as $row) $settings[$row['setting_key']] = $row['setting_value'];
    $minInterval   = max(0, (int)($settings['webhook_cron_min_interval_seconds'] ?? 20));
    $retentionDays = max(1, (int)($settings['webhook_delivery_retention_days'] ?? 30));

    // ---- HTTP token auth ----
    if (!$isCli) {
        $expected = $settings['webhook_cron_token'] ?? null;
        if (empty($expected)) {
            http_response_code(503);
            echo "Cron token not set. Run Database Verification to seed webhook_cron_token.\n";
            exit;
        }
        if (!hash_equals((string)$expected, (string)($_GET['token'] ?? ''))) {
            http_response_code(403);
            echo "Forbidden\n";
            exit;
        }
    }

    // ---- Min interval between runs (CLI + HTTP) ----
    if ($minInterval > 0 && !empty($settings['webhook_cron_last_run'])) {
        $ageStmt = $conn->prepare("SELECT TIMESTAMPDIFF(SECOND, ?, UTC_TIMESTAMP())");
        $ageStmt->execute([$settings['webhook_cron_last_run']]);
        $age = (int)$ageStmt->fetchColumn();
        if ($age >= 0 && $age < $minInterval) {
            http_response_code(429);
            echo "Rate limited. Last run {$age}s ago; minimum interval is {$minInterval}s.\n";
            exit;
        }
    }
    // Stamp this run's start time.
    $conn->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES ('webhook_cron_last_run', UTC_TIMESTAMP())
                    ON DUPLICATE KEY UPDATE setting_value = UTC_TIMESTAMP()")->execute();

    // ---- Deliver due rows ----
    $counts = webhookRunQueue($conn, 100);

    // ---- Prune old terminal rows ----
    $prune = $conn->prepare("DELETE FROM webhook_deliveries WHERE status IN ('delivered','dead') AND updated_datetime < UTC_TIMESTAMP() - INTERVAL ? DAY");
    $prune->execute([$retentionDays]);

    echo "OK — attempted {$counts['attempted']}, delivered {$counts['delivered']}, failed {$counts['failed']}, dead {$counts['dead']}, pruned {$prune->rowCount()}.\n";
} catch (Throwable $e) {
    http_response_code(500);
    error_log('webhook_deliveries cron failed: ' . $e->getMessage());
    echo "Error: " . $e->getMessage() . "\n";
}
