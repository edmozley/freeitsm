<?php
/**
 * API: create a new InTune app-sync job for the next batch of candidate assets
 * and spawn the CLI worker. Returns the job id (or the existing one if a job
 * is already pending/running).
 */
session_start();
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/intune.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'POST method required']);
    exit;
}

try {
    $conn = connectToDatabase();

    $settings = intuneGetSettings($conn);
    if ($settings === null) {
        echo json_encode(['success' => false, 'error' => 'InTune credentials not configured.']);
        exit;
    }

    $result = intuneCreateAppSyncJob($conn);

    if ($result['job_id'] === 0) {
        echo json_encode([
            'success' => false,
            'error' => 'No assets need an app sync — all eligible Intune-linked devices either have agent-sourced software or are already queued in another job.',
        ]);
        exit;
    }

    if (!$result['reused']) {
        $spawned = intuneSpawnAppWorker($result['job_id'], $settings['php_exe']);
        if (!$spawned) {
            $conn->prepare(
                "UPDATE intune_app_sync_jobs
                    SET status = 'error',
                        message = 'Could not spawn worker process. Set intune_php_exe in system_settings to your php.exe path.',
                        finished_datetime = UTC_TIMESTAMP()
                  WHERE id = ?"
            )->execute([$result['job_id']]);
            echo json_encode(['success' => false, 'error' => 'Failed to spawn sync worker process']);
            exit;
        }
    }

    echo json_encode([
        'success' => true,
        'id' => $result['job_id'],
        'asset_count' => $result['asset_count'],
        'reused' => $result['reused'],
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
