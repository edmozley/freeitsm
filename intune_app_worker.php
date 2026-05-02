<?php
/**
 * CLI-only worker that runs an InTune app-sync job in the background.
 * Spawned by api/intune/create_app_sync_job.php via popen(). Argv: <jobId>.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "intune_app_worker.php is CLI-only";
    exit(1);
}

$jobId = isset($argv[1]) ? (int)$argv[1] : 0;
if ($jobId <= 0) {
    fwrite(STDERR, "Usage: php intune_app_worker.php <jobId>\n");
    exit(2);
}

set_time_limit(0);
ignore_user_abort(true);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/intune.php';

$conn = connectToDatabase();
intuneRunAppSyncJob($conn, $jobId);
