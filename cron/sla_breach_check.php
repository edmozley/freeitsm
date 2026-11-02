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
require_once __DIR__ . '/../includes/job_runs.php';   // run history (#182)

$isCli = (PHP_SAPI === 'cli');
$clientIp = $isCli ? null : ($_SERVER['REMOTE_ADDR'] ?? null);
$startedAt = microtime(true);
$runId = null;
$jobRunId = null;   // #182's shared run history; sla_cron_runs stays for the SLA screen
$trigger = JobRun::scheduledTrigger();

/**
 * Helper — record a run row. Returns the inserted id (or null if logging fails).
 *
 * @param bool $isCli  true when run from the command line (logged as 'cli'), false for an HTTP call ('http')
 * @param ?string $clientIp  the caller's REMOTE_ADDR for an HTTP call; null from the command line
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

/**
 * Completes this run's sla_cron_runs row with its end time, duration, outcome, counts and notes; does nothing when the start row was never written.
 *
 * @param ?int $runId  the row id from sla_cron_log_start(); null when that insert failed
 * @param string $outcome  ok, error, auth_failed, config_missing or rate_limited
 * @param array $counts  array{sent?:int,skipped?:int,errors?:int} - missing keys are logged as 0
 * @param ?string $notes  free text for the log (cut to 65,000 characters); null or empty stores NULL
 * @writes sla_cron_runs(ended_at, duration_ms, outcome, sent_count, skipped_count, error_count, notes) - the run log shown in SLA settings
 */
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
                JobRun::refused($conn, 'sla_breach', 'caller locked out after repeated wrong tokens');
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
            JobRun::refused($conn, 'sla_breach', 'token not set');
            echo "Cron token not set. Run api/system/db_verify.php to seed it, or insert manually.\n";
            exit;
        }
        $supplied = $_GET['token'] ?? '';
        if (!hash_equals($expected, (string)$supplied)) {
            http_response_code(403);
            sla_cron_log_finish($conn, $runId, 'auth_failed', [], "wrong or missing token");
            JobRun::refused($conn, 'sla_breach', 'wrong token');
            echo "Forbidden\n";
            exit;
        }
    }

    if (!JobRun::lock($conn, 'sla_breach', $trigger)) {
        sla_cron_log_finish($conn, $runId, 'rate_limited', [], 'another run is still going');
        exit;
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
            JobRun::skipped($conn, 'sla_breach', $trigger, "Minimum interval: last good run {$age}s ago, minimum {$minInterval}s.");
            echo "Rate limited. Last successful run was {$age}s ago; minimum interval is {$minInterval}s. Try again in {$wait}s.\n";
            exit;
        }
    }

    // GH #217: nothing to judge while SLA is switched off for the install. Logged as a
    // skip in both histories; switching back on decides what happens to what was missed.
    if (!moduleIsEnabled($conn, 'sla')) {
        sla_cron_log_finish($conn, $runId, 'ok', [], 'Skipped: ' . moduleDisabledMessage('sla'));   // no 'skipped' in its ENUM
        cronModuleSwitchedOff($conn, 'sla', 'sla_breach', $trigger);
        exit(0);
    }

    // ---- Run the actual check ----
    $jobRunId = JobRun::start($conn, 'sla_breach', $trigger);
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
    // #182: a run that sent some emails and hit errors on others is PARTIAL, not
    // the plain success sla_cron_runs records it as. The per-email lines stay in
    // sla_cron_runs (they name tickets and addresses); the shared history gets counts.
    JobRun::finish($conn, $jobRunId,
        empty($summary['errors']) ? 'ok' : (empty($summary['sent']) ? 'failed' : 'partial'),
        $counts, "Sent {$counts['sent']}, errors {$counts['errors']}.");

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

    // Non-zero when any notification failed (#182), like the other jobs, so a
    // scheduler watching exit codes can tell too. The run is already recorded.
    if (!empty($summary['errors'])) exit(1);

} catch (Exception $e) {
    http_response_code(500);
    if (isset($conn) && $runId) {
        sla_cron_log_finish($conn, $runId, 'error', [], $e->getMessage());
    }
    if (isset($conn)) JobRun::fail($conn, $jobRunId, $e);
    echo "ERROR: " . $e->getMessage() . "\n";
    error_log("SLA breach cron error: " . $e->getMessage());
    exit(1);
}
