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
 * - oldest first, as the button picks them - or until it has run for
 * INTUNE_APP_SYNC_BUDGET seconds, when the next run carries on where it stopped.
 *
 * Devices whose software the agent already reports are skipped, as the button
 * skips them (intuneGetAppSyncCandidates()). Never two at once: its run lock,
 * and a batch already running from the settings tab is left to finish.
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

// Enough batches to read every eligible device once - no more, or the oldest
// ones would be read again within the same run.
$batch    = max(1, intuneGetAppBatchSize($conn));
$eligible = count(intuneGetAppSyncCandidates($conn, 1000000));
$batches  = (int)ceil($eligible / $batch);
$started  = time();
$devices = 0; $failed = 0; $ran = 0;

for ($i = 0; $i < $batches && time() - $started < INTUNE_APP_SYNC_BUDGET; $i++) {
    $job = intuneCreateAppSyncJob($conn);
    if ($job['reused']) {
        echo date('c') . " a batch started from the settings tab is still running - left to finish\n";
        break;
    }
    if ($job['job_id'] === 0) break;   // nothing left to read
    intuneRunAppSyncJob($conn, $job['job_id']);
    $st = $conn->prepare("SELECT processed, failed FROM intune_app_sync_jobs WHERE id = ?");
    $st->execute([$job['job_id']]);
    $r = $st->fetch(PDO::FETCH_ASSOC) ?: ['processed' => 0, 'failed' => $job['asset_count']];
    $devices += (int)$r['processed'];
    $failed  += (int)$r['failed'];
    $ran++;
    echo date('c') . " batch {$ran}: {$r['processed']} device(s), {$r['failed']} failed\n";
}

$summary = "{$devices} device(s) read in {$ran} batch(es), {$failed} failed, of {$eligible} eligible.";
JobRun::source($conn, $runId, 'intune_app_sync', 'part:software', 'Software', $failed === 0, $summary);
echo date('c') . " done - $summary\n";
JobRun::finish($conn, $runId, null, ['devices' => $devices, 'failed' => $failed, 'batches' => $ran], $summary);
exit($failed > 0 ? 1 : 0);
