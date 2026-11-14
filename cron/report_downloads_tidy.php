<?php
/**
 * Emailed reports' download links tidy-up (5.3.0) - cron entry point, command line only.
 *
 * Deletes the stored PDFs of links that have expired or been revoked (the row is
 * kept, marked file_deleted_at, so the list and the audit still make sense), removes
 * rows REPORT_DL_KEEP_DAYS after they expired, and deletes stored files no row knows.
 * See reportDownloadsTidy() in includes/report_downloads.php.
 *
 * Started by the built-in scheduler (cron/run.php) once a day while any link still
 * has a file; nothing to set up.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Run from the command line.\n");
}
set_time_limit(600);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/job_runs.php';
require_once __DIR__ . '/../includes/report_downloads.php';

try {
    $conn = connectToDatabase();
} catch (Throwable $e) {
    fwrite(STDERR, "Database unavailable\n");
    exit(1);
}

$trigger = JobRun::scheduledTrigger();
if (!JobRun::lock($conn, 'report_downloads_tidy', $trigger)) exit(0);
$runId = JobRun::start($conn, 'report_downloads_tidy', $trigger);
try {
    $r = reportDownloadsTidy($conn);
    $summary = sprintf('%d file(s) of expired or revoked links deleted, %d old row(s) removed, %d stray file(s) deleted.',
        $r['files'], $r['rows'], $r['orphans']);
    JobRun::finish($conn, $runId, 'ok', $r, $summary);
    echo "Report downloads tidy-up: $summary\n";
} catch (Throwable $e) {
    JobRun::fail($conn, $runId, $e);
    fwrite(STDERR, 'Report downloads tidy-up failed: ' . $e->getMessage() . "\n");
    exit(1);
} finally {
    JobRun::unlock($conn, 'report_downloads_tidy');
}
exit(0);
