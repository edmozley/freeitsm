<?php
/**
 * API: kick off an InTune sync job.
 *
 * Inserts a row into intune_sync_jobs, spawns the CLI worker, returns the
 * job id so the UI can poll sync_status.php.
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
        echo json_encode(['success' => false, 'error' => 'InTune credentials not configured. Save them on the InTune settings tab first.']);
        exit;
    }

    // Self-heal: any "running" job older than 2 minutes that hasn't processed
    // anything is a worker that died before reporting. Mark it errored so the
    // running-job check below can ignore it.
    $conn->exec(
        "UPDATE intune_sync_jobs
            SET status = 'error',
                message = 'Auto-failed: worker did not start within 2 minutes',
                finished_datetime = UTC_TIMESTAMP()
          WHERE status = 'running'
            AND processed = 0
            AND started_datetime < (UTC_TIMESTAMP() - INTERVAL 2 MINUTE)"
    );

    // Reject if a job is genuinely already running, otherwise we'd race on upserts.
    $running = $conn->query(
        "SELECT id FROM intune_sync_jobs WHERE status = 'running' ORDER BY id DESC LIMIT 1"
    )->fetchColumn();
    if ($running) {
        echo json_encode(['success' => true, 'id' => (int)$running, 'reused' => true]);
        exit;
    }

    $stmt = $conn->prepare(
        "INSERT INTO intune_sync_jobs (started_datetime, status, total, processed, message)
         VALUES (UTC_TIMESTAMP(), 'running', 0, 0, 'Starting...')"
    );
    $stmt->execute();
    $jobId = (int)$conn->lastInsertId();

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
