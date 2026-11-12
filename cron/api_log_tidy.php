<?php
/**
 * The API activity log's tidy-up (GitHub #287) - cron entry point.
 *
 * System -> API -> Activity keeps one row per REST / MCP call for
 * "Keep for (days)" (api_log_keep_days, default 30). This removes the older ones -
 * or, when "Older rows" says Archive, first writes each day to a zip (a CSV inside)
 * in uploads/api-log-archive/, checked before the day is deleted. The zips are
 * downloaded or deleted on the Activity tab.
 *
 * Started by the built-in scheduler (cron/run.php) once a day; nothing to set up.
 *
 * SECURITY (HTTP invocation only), as the other crons: the shared
 * workflow_cron_token as ?token=. CLI invocation skips the token.
 */

set_time_limit(600);
if (PHP_SAPI !== 'cli') header('Content-Type: text/plain; charset=utf-8');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/encryption.php';   // decryptValue() for the token
require_once __DIR__ . '/../includes/api_security.php';
require_once __DIR__ . '/../includes/job_runs.php';     // run history (#182)

$isCli = (PHP_SAPI === 'cli');
$trigger = JobRun::scheduledTrigger();
$runId = null;

try {
    $conn = connectToDatabase();

    if (!$isCli) {
        $st = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'workflow_cron_token'");
        $st->execute();
        $expected = decryptValue($st->fetchColumn() ?: null);
        if (empty($expected)) {
            http_response_code(503);
            JobRun::refused($conn, 'api_log_tidy', 'token not set');
            echo "Cron token not set. Run Database Verification to seed workflow_cron_token.\n";
            exit;
        }
        if (!hash_equals((string)$expected, (string)($_GET['token'] ?? ''))) {
            http_response_code(403);
            JobRun::refused($conn, 'api_log_tidy', 'wrong token');
            echo "Forbidden\n";
            exit;
        }
    }

    if (!JobRun::lock($conn, 'api_log_tidy', $trigger)) exit;
    $runId = JobRun::start($conn, 'api_log_tidy', $trigger);
    $r = apiLogTidy($conn);
    $summary = $r['days']
        ? "Tidied {$r['days']} day(s): {$r['rows']} row(s) removed" . ($r['files'] ? ", {$r['archived']} archived to " . count($r['files']) . ' zip(s)' : '') . '.'
        : 'Nothing older than the days kept.';
    JobRun::finish($conn, $runId, null, ['days' => $r['days'], 'rows' => $r['rows'], 'archived' => $r['archived']], $summary);
    echo "API log tidy: $summary\n";

} catch (Throwable $e) {
    if (isset($conn)) JobRun::fail($conn, $runId, $e);
    if (!$isCli) http_response_code(500);
    echo "API log tidy failed: " . $e->getMessage() . "\n";
    error_log('cron/api_log_tidy.php: ' . $e->getMessage());
    exit(1);
}
