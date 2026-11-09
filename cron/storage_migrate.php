<?php
/**
 * Files storage move - cron entry point.
 *
 * Copies Files' stored bytes to a new location after Files -> Settings ->
 * Storage changed it (another folder, S3-compatible storage, another bucket),
 * and later removes the old copies when someone asks. See
 * includes/files/storage_migration.php.
 *
 * Started by the built-in scheduler (cron/run.php) every minute, but only
 * while a move is running. Each run works for up to eight minutes; a large move
 * is spread over as many runs as it needs.
 *
 *   php cron/storage_migrate.php
 *
 * Command line only: copying gigabytes is not something to start from a URL.
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
require_once __DIR__ . '/../includes/files/storage_migration.php';

try {
    $conn = connectToDatabase();
} catch (Throwable $e) {
    fwrite(STDERR, "Database unavailable\n");
    exit(1);
}

$trigger = JobRun::scheduledTrigger();
if (cronModuleSwitchedOff($conn, 'files', 'storage_migrate', $trigger)) exit(0);
if (!JobRun::lock($conn, 'storage_migrate', $trigger)) exit(0);

$runId = JobRun::start($conn, 'storage_migrate', $trigger);
try {
    $r = ['copied' => 0, 'failed' => 0, 'removed' => 0, 'done' => false];
    $deadline = time() + 8 * 60;
    do {
        $b = storageMigrateBatch($conn, $deadline);
        foreach (['copied', 'failed', 'removed'] as $k) $r[$k] += $b[$k];
        $r['done'] = $b['done'];
        $unavailable = !empty($b['unavailable']);
        $didWork = $b['copied'] + $b['removed'] > 0;
    } while (!$r['done'] && $didWork && !$unavailable && time() < $deadline);

    $summary = sprintf('%d copied, %d removed, %d failed attempts%s.', $r['copied'], $r['removed'], $r['failed'],
                       $unavailable ? ' - storage could not be reached, the next run tries again'
                                    : ($r['done'] ? '' : ' (more to do: the next run carries on)'));
    JobRun::finish($conn, $runId, ($r['failed'] === 0 && !$unavailable) ? 'ok' : ($r['copied'] + $r['removed'] > 0 ? 'partial' : 'failed'),
                   ['copied' => $r['copied'], 'removed' => $r['removed'], 'failed' => $r['failed']], $summary);
    echo "Files storage move: $summary\n";
} catch (Throwable $e) {
    JobRun::fail($conn, $runId, $e);
    fwrite(STDERR, 'Files storage move failed: ' . $e->getMessage() . "\n");
    exit(1);
} finally {
    JobRun::unlock($conn, 'storage_migrate');
}
exit(0);
