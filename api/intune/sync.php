<?php
/**
 * API: kick off an InTune sync job.
 *
 * Inserts a row into intune_sync_jobs, spawns the CLI worker, returns the
 * job id so the UI can poll sync_status.php.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/rbac.php';
require_once '../../includes/intune.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

// The Intune connection is an Asset Management settings tab: configuring it and running
// its syncs belong to whoever administers it, not to anyone who is merely logged in.
requireModuleAccessJson('assets');
requireCapabilityJson(Cap::ASSETS_INTUNE);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'POST method required']);
    exit;
}

try {
    $conn = connectToDatabase();

    $settings = intuneGetSettings($conn);
    if ($settings === null) {
        echo json_encode(['success' => false, 'error' => 'InTune credentials not configured. Save them on the InTune settings tab first.']);
        exit;
    }

    // One claim for the button and the scheduled job (cron/intune_sync.php): a
    // sync already running - from either - is reused, never raced.
    $jobId = intuneClaimDeviceSyncJob($conn);
    if ($jobId === null) {
        $running = $conn->query("SELECT id FROM intune_sync_jobs WHERE status = 'running' ORDER BY id DESC LIMIT 1")->fetchColumn();
        echo json_encode(['success' => true, 'id' => (int)$running, 'reused' => true]);
        exit;
    }

    $spawned = intuneSpawnWorker($jobId, $settings['php_exe']);
    if (!$spawned) {
        $conn->prepare(
            "UPDATE intune_sync_jobs
                SET status = 'error',
                    message = 'Could not spawn worker process. Set intune_php_exe in system_settings to your php.exe path.',
                    finished_datetime = UTC_TIMESTAMP()
              WHERE id = ?"
        )->execute([$jobId]);
        echo json_encode(['success' => false, 'error' => 'Failed to spawn sync worker process']);
        exit;
    }

    echo json_encode(['success' => true, 'id' => $jobId, 'reused' => false]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
