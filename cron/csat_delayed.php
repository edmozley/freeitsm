<?php
/**
 * CSAT surveys sent after "Delay before sending", and the optional one reminder
 * for an unanswered survey (GitHub #301) - cron entry point.
 *
 * Tickets -> Settings -> CSAT -> "Delay before sending (minutes)". A ticket that
 * closes with a delay set has its survey queued (csat_queue); this sends the ones
 * now due - and only while the ticket is still closed. One reopened in the
 * meantime gets no survey; closed again, it was queued afresh.
 *
 * Started by the built-in scheduler (cron/run.php) every five minutes; nothing to
 * set up. With no delay set, surveys go the moment a ticket closes and this has
 * nothing to do.
 *
 * SECURITY (HTTP invocation only), as the other crons: the shared
 * workflow_cron_token as ?token=. CLI invocation skips the token.
 */

set_time_limit(120);
if (PHP_SAPI !== 'cli') header('Content-Type: text/plain; charset=utf-8');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/encryption.php';   // decryptValue() for the token
require_once __DIR__ . '/../includes/csat.php';
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
            JobRun::refused($conn, 'csat_delayed', 'token not set');
            echo "Cron token not set. Run Database Verification to seed workflow_cron_token.\n";
            exit;
        }
        if (!hash_equals((string)$expected, (string)($_GET['token'] ?? ''))) {
            http_response_code(403);
            JobRun::refused($conn, 'csat_delayed', 'wrong token');
            echo "Forbidden\n";
            exit;
        }
    }

    // GH #217: CSAT switched off for the install - nothing is sent (the queue waits).
    if (cronModuleSwitchedOff($conn, 'csat', 'csat_delayed', $trigger)) exit(0);
    if (!JobRun::lock($conn, 'csat_delayed', $trigger)) exit;

    $runId = JobRun::start($conn, 'csat_delayed', $trigger);
    $r = csatSendDue($conn);
    $rem = csatSendReminders($conn);   // the optional one reminder (Tickets -> Settings -> CSAT)
    $failed = $r['failed'] + $rem['failed'];
    $summary = "Sent {$r['sent']} survey(s); {$r['reopened']} ticket(s) reopened or gone, {$r['skipped']} not sent"
             . ($r['failed'] ? ", {$r['failed']} failed" : '')
             . "; {$rem['sent']} reminder(s)" . ($rem['failed'] ? ", {$rem['failed']} failed" : '') . '.';
    JobRun::finish($conn, $runId, $failed ? 'partial' : null,
        ['sent' => $r['sent'], 'reopened' => $r['reopened'], 'skipped' => $r['skipped'], 'failed' => $failed,
         'reminders' => $rem['sent']], $summary);
    echo "CSAT delayed surveys: $summary\n";
    if ($failed) exit(1);

} catch (Throwable $e) {
    if (isset($conn)) JobRun::fail($conn, $runId, $e);
    if (!$isCli) http_response_code(500);
    echo "CSAT delayed surveys failed: " . $e->getMessage() . "\n";
    error_log('cron/csat_delayed.php: ' . $e->getMessage());
    exit(1);
}
