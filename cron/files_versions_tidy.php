<?php
/**
 * Files versions tidy-up (GH #303) - cron entry point, command line only.
 *
 * Files -> Settings -> Versions -> "Versions to keep" (files_keep_versions) keeps the
 * newest N versions of each file. A file is tidied the moment it gets a new version;
 * this job does the rest once a day - files untouched since the setting was lowered.
 * The current version is always kept, and bytes another version or a copied file
 * still uses are never deleted (FilesVersionsService::applyKeep ->
 * filesPurgeBytesIfUnused).
 *
 * Started by the built-in scheduler (cron/run.php) once a day while the setting keeps
 * fewer than every version; nothing to set up.
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
require_once __DIR__ . '/../includes/services/files_versions.php';

try {
    $conn = connectToDatabase();
} catch (Throwable $e) {
    fwrite(STDERR, "Database unavailable\n");
    exit(1);
}

$trigger = JobRun::scheduledTrigger();
if (cronModuleSwitchedOff($conn, 'files', 'files_versions_tidy', $trigger)) exit(0);
if (!JobRun::lock($conn, 'files_versions_tidy', $trigger)) exit(0);
$runId = JobRun::start($conn, 'files_versions_tidy', $trigger);
try {
    $keep = FilesVersionsService::settings($conn)['keep'];
    $files = 0; $deleted = 0;
    if ($keep > 0) {
        $st = $conn->prepare("SELECT item_id FROM files_versions GROUP BY item_id HAVING COUNT(*) > ?");
        $st->execute([$keep]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $itemId) {
            $n = FilesVersionsService::applyKeep($conn, (int)$itemId, $keep);
            if ($n > 0) { $files++; $deleted += $n; }
        }
    }
    $summary = $keep > 0
        ? sprintf('%d old versions deleted from %d files (keeping the newest %d).', $deleted, $files, $keep)
        : 'Every version is kept - nothing to do.';
    JobRun::finish($conn, $runId, 'ok', ['files' => $files, 'versions_deleted' => $deleted], $summary);
    echo "Files versions tidy-up: $summary\n";
} catch (Throwable $e) {
    JobRun::fail($conn, $runId, $e);
    fwrite(STDERR, 'Files versions tidy-up failed: ' . $e->getMessage() . "\n");
    exit(1);
} finally {
    JobRun::unlock($conn, 'files_versions_tidy');
}
exit(0);
