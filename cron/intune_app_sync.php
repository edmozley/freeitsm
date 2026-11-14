<?php
/**
 * Intune software sync - scheduled entry point (CLI only).
 *
 *   php cron/intune_app_sync.php
 *
 * The same sync as Sync software on Assets -> Settings -> Intune: the apps
 * installed on each Intune device, into the software inventory. The button runs
 * ONE batch (the batch size on that tab) and expects somebody to keep pressing
 * it; this runs batch after batch until every eligible device has been read once
 * - the ones tried longest ago first - or until it has run for
 * INTUNE_APP_SYNC_BUDGET seconds, when the next run carries on where it stopped.
 * The work, and how its outcome is decided, is intuneScheduledAppSync().
 *
 * Devices whose software the agent already reports are skipped, as the button
 * skips them (intuneGetAppSyncCandidates()). Never two at once: its run lock,
 * and a batch already running from the settings tab is left to finish (the run
 * is then recorded as skipped).
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

/** Seconds one run may spend starting batches; a batch already started finishes. */
const INTUNE_APP_SYNC_BUDGET = 45 * 60;

$conn = connectToDatabase();
$trigger = JobRun::scheduledTrigger();
if (cronModuleSwitchedOff($conn, 'assets', 'intune_app_sync', $trigger)) exit(0);
if (intuneGetSettings($conn) === null) {
    JobRun::skipped($conn, 'intune_app_sync', $trigger, 'Intune is not set up: no tenant, client id or secret.');
    exit(0);
}
if (!JobRun::lock($conn, 'intune_app_sync', $trigger)) exit(0);
$runId = JobRun::start($conn, 'intune_app_sync', $trigger);

$r = intuneScheduledAppSync($conn, INTUNE_APP_SYNC_BUDGET);
if ($r['outcome'] !== 'skipped') {
    JobRun::source($conn, $runId, 'intune_app_sync', 'part:software', 'Software', $r['outcome'] !== 'failed', $r['summary']);
}
echo date('c') . ' ' . $r['outcome'] . ' - ' . $r['summary'] . "\n";
JobRun::finish($conn, $runId, $r['outcome'], $r['counts'], $r['summary']);
exit($r['outcome'] === 'failed' ? 1 : 0);
