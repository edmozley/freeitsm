<?php
/**
 * Intune device sync - scheduled entry point (CLI only).
 *
 *   php cron/intune_sync.php
 *
 * The same sync as Sync on Assets -> Settings -> Intune (intuneRunSync()): every
 * managed device, linked to its asset, its type, its primary user, the changes
 * of primary user it follows and the history it keeps. It ran only when somebody
 * pressed that button; the built-in scheduler (cron/run.php) now starts it on its
 * own, on the schedule set in Reporting -> Logs -> Background jobs.
 *
 * Writes the same intune_sync_jobs row the button does, so the settings tab shows
 * a scheduled run like any other. Never two at once: its run lock, and the claim
 * (intuneClaimDeviceSyncJob()) - a sync already running from the settings tab is
 * left to finish, and this run is recorded as skipped.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Run from the command line.\n");
}
set_time_limit(0);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/intune.php';
require_once __DIR__ . '/../includes/job_runs.php';   // run history (#182)

$conn = connectToDatabase();
$trigger = JobRun::scheduledTrigger();
// GH #217: nothing to do while Assets is switched off for the install.
if (cronModuleSwitchedOff($conn, 'assets', 'intune_sync', $trigger)) exit(0);
if (intuneGetSettings($conn) === null) {
    JobRun::skipped($conn, 'intune_sync', $trigger, 'Intune is not set up: no tenant, client id or secret.');
    exit(0);
}
if (!JobRun::lock($conn, 'intune_sync', $trigger)) exit(0);

// Claim first: a run turned away did no work, so it is a skip, not an ok run.
$jobId = intuneClaimDeviceSyncJob($conn);
if ($jobId === null) {
    echo date('c') . " a sync started from the settings tab is still running - left to finish\n";
    JobRun::skipped($conn, 'intune_sync', $trigger, 'A sync started from the settings tab was already running - left to finish.');
    exit(0);
}
$runId = JobRun::start($conn, 'intune_sync', $trigger);

$r = intuneRunSync($conn, $jobId);

$st = $conn->prepare("SELECT status, processed, message FROM intune_sync_jobs WHERE id = ?");
$st->execute([$jobId]);
$job = $st->fetch(PDO::FETCH_ASSOC) ?: ['status' => 'error', 'processed' => 0, 'message' => 'The sync left no record.'];
// Failed when the sync stopped; partial when some devices were written and some
// could not be; failed too when none could be.
if ($r['error'] !== null || $job['status'] !== 'done') {
    $outcome = 'failed';
} elseif ($r['failed'] > 0) {
    $outcome = $r['processed'] > 0 ? 'partial' : 'failed';
} else {
    $outcome = 'ok';
}
JobRun::source($conn, $runId, 'intune_sync', 'part:devices', 'Devices', $outcome !== 'failed', $job['message']);
echo date('c') . ' ' . $outcome . ' - ' . $job['message'] . "\n";
JobRun::finish($conn, $runId, $outcome, ['devices' => $r['processed'], 'failed' => $r['failed']], (string)$job['message']);
exit($outcome === 'failed' ? 1 : 0);
