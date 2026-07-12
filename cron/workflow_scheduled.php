<?php
/**
 * Time-based workflow triggers — cron entry point.
 *
 * Every other workflow trigger hangs off a write path: someone saved a ticket,
 * so there is a moment to dispatch from. These ones don't exist until something
 * goes looking. "This contract expires in 30 days" is not an event — nothing
 * happened, TIME PASSED.
 *
 * Emits:
 *   contract.expiring        — at 90 / 30 / 7 / 1 days out
 *   asset.warranty_expiring  — same windows
 *
 * NOT here: `sla.warning` and `sla.breached`. Those are emitted from
 * cron/sla_breach_check.php, which already walks every open ticket and computes
 * its SLA state — so emitting there costs nothing extra and cannot drift from
 * what the SLA emails believe. Schedule BOTH crons if you want SLA workflows.
 *
 * Fire-once is guaranteed by the `workflow_scheduled_emissions` ledger (a UNIQUE
 * key + INSERT IGNORE), not by this script — so overlapping runs are safe, and a
 * still-true condition (a breached SLA stays breached) cannot re-fire on every
 * pass. Run it as often as you like; hourly is plenty for expiry windows.
 *
 * SECURITY (HTTP invocation only), mirroring the other crons:
 *   - Shared-secret token via ?token=<value> matching `workflow_cron_token`.
 *   - Min interval between runs, defeating double-scheduling / runaway loops.
 * CLI invocation skips the token (there is no untrusted caller).
 */

set_time_limit(120);
if (PHP_SAPI !== 'cli') header('Content-Type: text/plain; charset=utf-8');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/workflow_scheduled.php';

$isCli = (PHP_SAPI === 'cli');

try {
    $conn = connectToDatabase();

    $settings = [];
    foreach ($conn->query(
        "SELECT setting_key, setting_value FROM system_settings
          WHERE setting_key IN ('workflow_cron_token','workflow_cron_min_interval_seconds','workflow_cron_last_run')"
    )->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
    $minInterval = max(0, (int)($settings['workflow_cron_min_interval_seconds'] ?? 300));

    // ---- HTTP token auth ----
    if (!$isCli) {
        $expected = $settings['workflow_cron_token'] ?? null;
        if (empty($expected)) {
            http_response_code(503);
            echo "Cron token not set. Run Database Verification to seed workflow_cron_token.\n";
            exit;
        }
        if (!hash_equals((string)$expected, (string)($_GET['token'] ?? ''))) {
            http_response_code(403);
            echo "Forbidden\n";
            exit;
        }
    }

    // ---- Min interval between runs (CLI + HTTP) ----
    if ($minInterval > 0 && !empty($settings['workflow_cron_last_run'])) {
        $ageStmt = $conn->prepare("SELECT TIMESTAMPDIFF(SECOND, ?, UTC_TIMESTAMP())");
        $ageStmt->execute([$settings['workflow_cron_last_run']]);
        $age = (int)$ageStmt->fetchColumn();
        if ($age >= 0 && $age < $minInterval) {
            http_response_code(429);
            echo "Rate limited. Last run {$age}s ago; minimum interval is {$minInterval}s.\n";
            exit;
        }
    }

    $conn->prepare(
        "INSERT INTO system_settings (setting_key, setting_value) VALUES ('workflow_cron_last_run', UTC_TIMESTAMP())
         ON DUPLICATE KEY UPDATE setting_value = UTC_TIMESTAMP()"
    )->execute();

    // ---- Emit ----
    $counts = workflowScheduledRun($conn);
    $pruned = workflowPruneEmissions($conn);

    $parts = [];
    foreach ($counts as $event => $n) $parts[] = "$event: $n";
    echo "OK — " . implode(', ', $parts) . ", pruned {$pruned} old emission(s).\n";
} catch (Throwable $e) {
    http_response_code(500);
    error_log('workflow_scheduled cron failed: ' . $e->getMessage());
    echo "Error: " . $e->getMessage() . "\n";
}
