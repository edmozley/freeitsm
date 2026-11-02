<?php
/**
 * Recurring tasks on a fixed schedule — cron entry point (discussion #94).
 *
 * Only 'schedule' series need this. A series set to repeat ON COMPLETION makes
 * its next occurrence the moment somebody finishes the last one, which happens
 * inside the web request that completed it — nothing has to run in the
 * background for that to work, and nothing here touches those series.
 *
 * So an installation that never schedules this cron still gets recurring tasks;
 * it just does not get the fixed-date kind. That is a deliberate split, because
 * ⚠️ A CRON THAT WAS NEVER SET UP DOES NOT ANNOUNCE ITSELF, and the commonest
 * outcome of a feature that silently depends on one is a user reporting that it
 * "doesn't work". System → Debug Tools reports when this last ran.
 *
 * Missed days are CAUGHT UP, not skipped: a quarterly access review that did not
 * appear because the server was down is still a review somebody owes. The
 * catch-up is capped per series per run so a rule left dormant for two years
 * cannot produce hundreds of tasks in one pass, and creating an occurrence is
 * idempotent on (series, due date) so overlapping or repeated runs are safe.
 *
 * SECURITY (HTTP invocation only), mirroring the other crons:
 *   - Shared-secret token via ?token=<value> matching `workflow_cron_token`.
 *   - Minimum interval between runs, defeating double-scheduling.
 * CLI invocation skips the token — there is no untrusted caller.
 *
 * Run it once a day; more often is harmless.
 */

set_time_limit(120);
if (PHP_SAPI !== 'cli') header('Content-Type: text/plain; charset=utf-8');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/encryption.php';            // decryptValue() for the token
require_once __DIR__ . '/../includes/services/task_recurrence.php';
require_once __DIR__ . '/../includes/job_runs.php';             // run history (#182)

$isCli = (PHP_SAPI === 'cli');
$trigger = JobRun::scheduledTrigger();
$runId = null;

try {
    $conn = connectToDatabase();

    $settings = [];
    foreach ($conn->query(
        "SELECT setting_key, setting_value FROM system_settings
          WHERE setting_key IN ('workflow_cron_token','task_recurrence_min_interval_seconds','task_recurrence_last_run')"
    )->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
    // Shares workflow_cron_token rather than inventing a second secret: an
    // administrator setting up FreeITSM's crons should copy one token, not
    // collect one per worker.
    $minInterval = max(0, (int)($settings['task_recurrence_min_interval_seconds'] ?? 300));

    if (!$isCli) {
        $expected = decryptValue($settings['workflow_cron_token'] ?? null);
        if (empty($expected)) {
            http_response_code(503);
            JobRun::refused($conn, 'task_recurrence', 'token not set');
            echo "Cron token not set. Run Database Verification to seed workflow_cron_token.\n";
            exit;
        }
        if (!hash_equals((string)$expected, (string)($_GET['token'] ?? ''))) {
            http_response_code(403);
            JobRun::refused($conn, 'task_recurrence', 'wrong token');
            echo "Forbidden\n";
            exit;
        }
    }

    // GH #217: nothing to do while the module is switched off for the install.
    if (cronModuleSwitchedOff($conn, 'tasks', 'task_recurrence', $trigger)) exit(0);
    if (!JobRun::lock($conn, 'task_recurrence', $trigger)) exit;

    if ($minInterval > 0 && !empty($settings['task_recurrence_last_run'])) {
        $ageStmt = $conn->prepare("SELECT TIMESTAMPDIFF(SECOND, ?, UTC_TIMESTAMP())");
        $ageStmt->execute([$settings['task_recurrence_last_run']]);
        $age = (int)$ageStmt->fetchColumn();
        if ($age >= 0 && $age < $minInterval) {
            http_response_code(429);
            JobRun::skipped($conn, 'task_recurrence', $trigger, "Minimum interval: last run {$age}s ago, minimum {$minInterval}s.");
            echo "Rate limited. Last run {$age}s ago; minimum interval is {$minInterval}s.\n";
            exit;
        }
    }

    // The "last run" stamp is the minimum-interval guard's, written before the work.
    // Health never reads it (#182): a run that crashes after this line looks
    // exactly like one that worked. It reads job_runs, below.
    $conn->prepare(
        "INSERT INTO system_settings (setting_key, setting_value) VALUES ('task_recurrence_last_run', UTC_TIMESTAMP())
         ON DUPLICATE KEY UPDATE setting_value = UTC_TIMESTAMP()"
    )->execute();

    // #182: one recorded run, one source per series, so a series that fails is
    // named and the run is PARTIAL rather than quietly OK. #181's evidence report
    // reads these runs to say what happened when an occurrence was missing or late,
    // and each occurrence this run creates records the run's id.
    $runId = JobRun::start($conn, 'task_recurrence', $trigger);
    $report = [];
    $made = TaskRecurrence::runDue($conn, null, 24, null, $report);
    foreach ($report as $rid => $r) {
        JobRun::source($conn, $runId, 'task_recurrence', 'series:' . $rid, $r['title'], $r['ok'],
            $r['ok'] ? ($r['made'] ? $r['made'] . ' created' : null) : $r['error']);
    }
    $failedSeries = count(array_filter($report, fn($r) => !$r['ok']));
    JobRun::finish($conn, $runId, null, ['created' => count($made), 'series' => count($report), 'failed' => $failedSeries],
        'Created ' . count($made) . ' occurrence(s) across ' . count($report) . ' series' . ($failedSeries ? ", {$failedSeries} failed" : '') . '.');

    echo "Task recurrence: created " . count($made) . " occurrence(s)";
    echo $made ? " (task ids: " . implode(', ', $made) . ")\n" : ".\n";
    // A failed series is worth a non-zero exit, so a scheduler can notice too.
    if ($failedSeries) {
        echo "{$failedSeries} series failed - see Reporting > System logs > Background jobs.\n";
        exit(1);
    }

} catch (Throwable $e) {
    if (isset($conn)) JobRun::fail($conn, $runId, $e);
    if (!$isCli) http_response_code(500);
    echo "Task recurrence failed: " . $e->getMessage() . "\n";
    error_log('cron/task_recurrence.php: ' . $e->getMessage());
    exit(1);
}
