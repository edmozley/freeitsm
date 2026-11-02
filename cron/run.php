<?php
/**
 * The built-in scheduler (#233) - the ONE scheduled entry FreeITSM needs.
 *
 *   * * * * *  php /path/to/freeitsm/cron/run.php --quiet
 *
 * Run it every minute. Each run starts the background jobs that are due, each as its
 * own process, and returns in well under a second. When each job runs is set in
 * FreeITSM, on Reporting -> System logs -> Background jobs, and a job added by a later
 * release is picked up with no change here. See includes/job_scheduler.php.
 *
 * Options:
 *   --dry-run   say what is due, start nothing and write nothing
 *   --quiet     print nothing (for a scheduled task that keeps no log)
 *
 * Keeping the old per-job cron lines as well is safe: every job takes its own run
 * lock, so a job started twice still runs once.
 *
 * Command line only: there is no token web address, because starting processes is
 * not something to trigger from a URL.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Run from the command line.\n");
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/job_scheduler.php';

$opts     = getopt('', ['dry-run', 'quiet', 'fallback']);
$dryRun   = isset($opts['dry-run']);
$quiet    = isset($opts['quiet']);
// --fallback: started from a page because no scheduled task runs this (the switch on
// Background jobs). Every job it starts inherits FREEITSM_JOB_TRIGGER=page, so each
// run is recorded as a page run and health never counts it as the schedule working.
$fallback = isset($opts['fallback']);
if ($fallback) putenv('FREEITSM_JOB_TRIGGER=page');

try {
    $conn = connectToDatabase();
    // A real scheduler started since the page asked: it does the work, not us.
    if ($fallback && jobSchedulerRunning($conn)) exit(0);
    // PHP_BINARY is this process's own PHP: the one the scheduled task already runs,
    // with whatever extensions the jobs need.
    $result = jobSchedulerTick($conn, PHP_BINARY, $dryRun, $fallback);
} catch (Throwable $e) {
    fwrite(STDERR, 'Scheduler failed: ' . $e->getMessage() . "\n");
    exit(1);
}

if (!$quiet) {
    foreach ($result as $job => $what) printf("%-22s %s\n", $job, str_replace('_', ' ', $what));
}
exit(in_array('failed_to_start', $result, true) ? 1 : 0);
