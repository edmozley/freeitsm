<?php
/**
 * Files recycle bin - cron entry point.
 *
 * Deletes for good whatever has been in the Files recycle bin longer than
 * Files -> Settings -> Recycle bin allows (rows, shortcuts and the stored bytes),
 * then tries again to delete stored bytes whose first deletion found storage
 * unreachable (files_purge_pending). See includes/services/files_recycle.php.
 *
 * Started by the built-in scheduler (cron/run.php) hourly, but only once the
 * Recycle bin tab has been saved with a period, or while bytes are waiting.
 * Each run works for up to eight minutes.
 *
 *   php cron/files_recycle_purge.php
 *
 * Command line only: deleting files is not something to start from a URL.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Run from the command line.\n");
}
set_time_limit(0);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/job_runs.php';
require_once __DIR__ . '/../includes/module_availability.php';
require_once __DIR__ . '/../includes/services/files_recycle.php';

try {
    $conn = connectToDatabase();
} catch (Throwable $e) {
    fwrite(STDERR, "Database unavailable\n");
    exit(1);
}

$trigger = JobRun::scheduledTrigger();
if (cronModuleSwitchedOff($conn, 'files', 'files_recycle_purge', $trigger)) exit(0);
if (!JobRun::lock($conn, 'files_recycle_purge', $trigger)) exit(0);

$runId = JobRun::start($conn, 'files_recycle_purge', $trigger);
try {
    $deadline = time() + 8 * 60;
    $p = FilesRecycleService::purgeExpired($conn, $deadline);
    $b = FilesRecycleService::retryPendingBytes($conn, $deadline);

    $summary = sprintf('%d deleted for good (%d files, %s bytes); %d waiting stored files removed%s%s.',
                       $p['entries'], $p['files'], number_format($p['bytes']), $b['deleted'],
                       $b['still_pending'] ? ' - storage could not be reached, the next run tries again' : '',
                       $p['done'] ? '' : ' (more to do: the next run carries on)');
    JobRun::finish($conn, $runId, $b['still_pending'] ? ($p['entries'] + $b['deleted'] > 0 ? 'partial' : 'failed') : 'ok',
                   ['entries' => $p['entries'], 'files' => $p['files'], 'bytes' => $p['bytes'], 'bytes_retried' => $b['deleted']], $summary);
    echo "Files recycle bin: $summary\n";
} catch (Throwable $e) {
    JobRun::fail($conn, $runId, $e);
    fwrite(STDERR, 'Files recycle bin failed: ' . $e->getMessage() . "\n");
    exit(1);
} finally {
    JobRun::unlock($conn, 'files_recycle_purge');
}
exit(0);
