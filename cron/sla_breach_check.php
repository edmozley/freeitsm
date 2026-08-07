<?php
/**
 * SLA Breach Check — cron entry point.
 *
 * Walks every open SLA-tracked ticket, fires warning + breach email
 * notifications according to the rules configured under
 * Tickets > Settings > SLA > Breach Notifications. Dedups via the
 * sla_notifications_sent table so each (ticket, target, trigger) fires
 * at most once.
 *
 * Run on a schedule (every 5 minutes is the sweet spot — frequent enough
 * to catch breaches promptly, sparse enough to avoid hammering the
 * mailbox API). See docs/sla-cron-setup.md for Windows Task Scheduler +
 * Linux cron configuration.
 *
 * SECURITY LAYERS (HTTP invocation only):
 *   1. Shared-secret token via ?token=<value> matching sla_cron_token in
 *      system_settings. ~128 bits of entropy, hash_equals() comparison.
 *   2. Per-IP failed-auth lockout: >10 wrong-token attempts from the same
 *      IP in the past hour triggers a 1-hour 429 lockout.
 *   3. Min interval between successful runs (sla_cron_min_interval_seconds,
 *      default 30s) — defeats double-scheduling, runaway loops, post-leak
 *      abuse. CLI invocations also honour this.
 *
 * LOGGING: every invocation (accepted or rejected) is logged to
 * sla_cron_runs with started_at / ended_at / outcome / counts / IP.
 * Pruned at end-of-run based on sla_cron_log_retention_days (default 30).
 */

set_time_limit(120);
header('Content-Type: text/plain; charset=utf-8');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/encryption.php';   // decryptValue() for the cron token
require_once __DIR__ . '/../includes/sla_notifications.php';

$isCli = (PHP_SAPI === 'cli');
$clientIp = $isCli ? null : ($_SERVER['REMOTE_ADDR'] ?? null);
$startedAt = microtime(true);
$runId = null;

/**
 * Helper — record a run row. Returns the inserted id (or null if logging fails).
 */
function sla_cron_log_start(PDO $conn, bool $isCli, ?string $clientIp): ?int {
    try {
        $stmt = $conn->prepare("INSERT INTO sla_cron_runs (started_at, invocation, client_ip, outcome) VALUES (UTC_TIMESTAMP(), ?, ?, 'error')");
        $stmt->execute([$isCli ? 'cli' : 'http', $clientIp]);
        return (int)$conn->lastInsertId();
    } catch (Exception $e) {
        return null;
    }
}

function sla_cron_log_finish(PDO $conn, ?int $runId, string $outcome, array $counts = [], ?string $notes = null): void {
    if (!$runId) return;
    try {
        $stmt = $conn->prepare("
            UPDATE sla_cron_runs
               SET ended_at = UTC_TIMESTAMP(),
                   duration_ms = TIMESTAMPDIFF(MICROSECOND, started_at, UTC_TIMESTAMP()) DIV 1000,
                   outcome = ?,
                   sent_count = ?,
                   skipped_count = ?,
                   error_count = ?,
                   notes = ?
             WHERE id = ?
        ");
        $stmt->execute([
            $outcome,
            (int)($counts['sent'] ?? 0),
            (int)($counts['skipped'] ?? 0),
            (int)($counts['errors'] ?? 0),
            $notes ? mb_substr($notes, 0, 65000) : null,
            $runId,
        ]);
    } catch (Exception $e) {
        error_log("sla_cron_log_finish failed: " . $e->getMessage());
    }
}

try {
    $conn = connectToDatabase();
    $runId = sla_cron_log_start($conn, $isCli, $clientIp);

    // Load relevant settings; fall back to defaults if missing
    $settingsStmt = $conn->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('sla_cron_token','sla_cron_min_interval_seconds','sla_cron_log_retention_days')");
    $settings = [];
    foreach ($settingsStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
    $minInterval = max(0, (int)($settings['sla_cron_min_interval_seconds'] ?? 30));
    $retentionDays = max(1, (int)($settings['sla_cron_log_retention_days'] ?? 30));

    // ---- LAYER 1+2: HTTP auth + per-IP brute-force lockout ----
    if (!$isCli) {
        // Per-IP failure lockout: 10 auth_failed rows from this IP in last hour = locked out
        if ($clientIp) {
            $lockStmt = $conn->prepare("
                SELECT COUNT(*) FROM sla_cron_runs
                 WHERE outcome = 'auth_failed'
                   AND client_ip = ?
                   AND started_at >= UTC_TIMESTAMP() - INTERVAL 1 HOUR
            ");
            $lockStmt->execute([$clientIp]);
            if ((int)$lockStmt->fetchColumn() >= 10) {
                http_response_code(429);
                sla_cron_log_finish($conn, $runId, 'auth_failed', [], "IP locked out (>=10 failed attempts in last hour)");
                echo "Too many failed attempts from this IP. Locked out for 1 hour.\n";
                exit;
            }
        }

        // Stored encrypted (isEncryptedSettingKey matches *_token). decryptValue()
        // returns an unencrypted value untouched, so installs that pre-date the
        // change keep authenticating on the same plaintext token.
        $expected = decryptValue($settings['sla_cron_token'] ?? null);
        if (empty($expected)) {
            http_response_code(503);
            sla_cron_log_finish($conn, $runId, 'config_missing', [], "sla_cron_token not seeded");
            echo "Cron token not set. Run api/system/db_verify.php to seed it, or insert manually.\n";
            exit;
        }
        $supplied = $_GET['token'] ?? '';
        if (!hash_equals($expected, (string)$supplied)) {
            http_response_code(403);
            sla_cron_log_finish($conn, $runId, 'auth_failed', [], "wrong or missing token");
            echo "Forbidden\n";
            exit;
        }
    }

    // ---- LAYER 3: Min interval between successful runs ----
    // Both CLI + HTTP — protects against double-scheduling regardless of source.
    if ($minInterval > 0) {
        $lastStmt = $conn->prepare("
            SELECT TIMESTAMPDIFF(SECOND, started_at, UTC_TIMESTAMP()) AS age
              FROM sla_cron_runs
             WHERE outcome = 'ok'
               AND id <> ?
          ORDER BY started_at DESC
             LIMIT 1
        ");
        $lastStmt->execute([(int)$runId]);
        $age = $lastStmt->fetchColumn();
        if ($age !== false && (int)$age < $minInterval) {
            $wait = $minInterval - (int)$age;
            http_response_code(429);
            sla_cron_log_finish($conn, $runId, 'rate_limited', [], "min interval not met (last ok run {$age}s ago, need {$minInterval}s)");
            echo "Rate limited. Last successful run was {$age}s ago; minimum interval is {$minInterval}s. Try again in {$wait}s.\n";
            exit;
        }
    }

    // ---- Run the actual check ----
    $summary = sla_run_breach_check($conn);
    $elapsed = round((microtime(true) - $startedAt) * 1000);
    $counts = [
        'sent'    => count($summary['sent']),
        'skipped' => count($summary['skipped']),
        'errors'  => count($summary['errors']),
    ];
    $notesLines = [];
    if (!empty($summary['sent']))   $notesLines[] = "SENT: " . implode(' | ', $summary['sent']);
    if (!empty($summary['errors'])) $notesLines[] = "ERRORS: " . implode(' | ', $summary['errors']);
    $notes = $notesLines ? implode("\n", $notesLines) : null;

    $outcome = !empty($summary['errors']) && empty($summary['sent']) ? 'error' : 'ok';
    sla_cron_log_finish($conn, $runId, $outcome, $counts, $notes);

    // ---- Retention prune ----
    // Keep last N days; runs at end of every invocation. Cheap (indexed scan).
    try {
        $conn->prepare("DELETE FROM sla_cron_runs WHERE started_at < UTC_TIMESTAMP() - INTERVAL ? DAY")
             ->execute([$retentionDays]);
    } catch (Exception $e) {
        error_log("sla_cron prune failed: " . $e->getMessage());
    }

    // ---- Plain-text response (Task Scheduler / cron-mail compatible) ----
    echo "SLA breach check completed in {$elapsed}ms\n";
    echo "  Sent:    {$counts['sent']}\n";
    echo "  Skipped: {$counts['skipped']}\n";
    echo "  Errors:  {$counts['errors']}\n\n";

    if (!empty($summary['sent'])) {
        echo "SENT:\n";
        foreach ($summary['sent'] as $line) echo "  - $line\n";
        echo "\n";
    }
    if (!empty($summary['errors'])) {
        echo "ERRORS:\n";
        foreach ($summary['errors'] as $line) echo "  - $line\n";
        echo "\n";
    }
    if (!empty($summary['skipped']) && (!empty($_GET['verbose']) || ($isCli && in_array('--verbose', $argv ?? [], true)))) {
        echo "SKIPPED:\n";
        foreach ($summary['skipped'] as $line) echo "  - $line\n";
    }

} catch (Exception $e) {
    http_response_code(500);
    if (isset($conn) && $runId) {
        sla_cron_log_finish($conn, $runId, 'error', [], $e->getMessage());
    }
    echo "ERROR: " . $e->getMessage() . "\n";
    error_log("SLA breach cron error: " . $e->getMessage());
    exit(1);
}
