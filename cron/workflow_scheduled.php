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
require_once __DIR__ . '/../includes/encryption.php';   // decryptValue() for the cron token
require_once __DIR__ . '/../includes/workflow_scheduled.php';
require_once __DIR__ . '/../includes/job_runs.php';   // run history (#182)

$isCli = (PHP_SAPI === 'cli');
$trigger = JobRun::scheduledTrigger();
$runId = null;

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
        // Stored encrypted (isEncryptedSettingKey matches *_token). decryptValue()
        // returns an unencrypted value untouched, so installs that pre-date the
        // change keep authenticating on the same plaintext token.
        $expected = decryptValue($settings['workflow_cron_token'] ?? null);
        if (empty($expected)) {
            http_response_code(503);
            JobRun::refused($conn, 'workflow_scheduled', 'token not set');
            echo "Cron token not set. Run Database Verification to seed workflow_cron_token.\n";
            exit;
        }
        if (!hash_equals((string)$expected, (string)($_GET['token'] ?? ''))) {
            http_response_code(403);
            JobRun::refused($conn, 'workflow_scheduled', 'wrong token');
            echo "Forbidden\n";
            exit;
        }
    }

    if (!JobRun::lock($conn, 'workflow_scheduled', $trigger)) exit;

    // ---- Min interval between runs (CLI + HTTP) ----
    if ($minInterval > 0 && !empty($settings['workflow_cron_last_run'])) {
        $ageStmt = $conn->prepare("SELECT TIMESTAMPDIFF(SECOND, ?, UTC_TIMESTAMP())");
        $ageStmt->execute([$settings['workflow_cron_last_run']]);
        $age = (int)$ageStmt->fetchColumn();
        if ($age >= 0 && $age < $minInterval) {
            http_response_code(429);
            JobRun::skipped($conn, 'workflow_scheduled', $trigger, "Minimum interval: last run {$age}s ago, minimum {$minInterval}s.");
            echo "Rate limited. Last run {$age}s ago; minimum interval is {$minInterval}s.\n";
            exit;
        }
    }

    $conn->prepare(
        "INSERT INTO system_settings (setting_key, setting_value) VALUES ('workflow_cron_last_run', UTC_TIMESTAMP())
         ON DUPLICATE KEY UPDATE setting_value = UTC_TIMESTAMP()"
    )->execute();

    // ---- Emit ----
    // #182: its three parts are its three sources, so one failing part makes the
    // run PARTIAL and is named, instead of the old "OK" over a swallowed error.
    $runId = JobRun::start($conn, 'workflow_scheduled', $trigger);
    $partResults = [];
    $counts = workflowScheduledRun($conn, $partResults);
    $pruned = workflowPruneEmissions($conn);
    $labels = ['expiries' => 'Contract and warranty expiry', 'planned_maintenance' => 'Planned maintenance', 'projects' => 'Project alerts'];
    foreach ($partResults as $key => $r) {
        JobRun::source($conn, $runId, 'workflow_scheduled', 'part:' . $key, $labels[$key] ?? $key, $r['ok'], $r['error']);
    }
    $failedParts = array_keys(array_filter($partResults, fn($r) => !$r['ok']));

    $parts = [];
    foreach ($counts as $event => $n) $parts[] = "$event: $n";
    JobRun::finish($conn, $runId, null, $counts, implode(', ', $parts) . '.');
    echo ($failedParts ? 'PARTIAL — ' : 'OK — ') . implode(', ', $parts) . ", pruned {$pruned} old emission(s).\n";
    if ($failedParts) {
        echo "Failed: " . implode(', ', $failedParts) . " - see Reporting > System logs > Background jobs.\n";
        exit(1);
    }
} catch (Throwable $e) {
    if (isset($conn)) JobRun::fail($conn, $runId, $e);
    if (!$isCli) http_response_code(500);
    error_log('workflow_scheduled cron failed: ' . $e->getMessage());
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
