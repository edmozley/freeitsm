<?php
/**
 * API: progress poll for an InTune app-sync job. Returns the job header plus
 * a roll-up of child statuses so the UI can show "X of Y assets done, Z failed".
 *
 * GET ?id=<jobId> for a specific job; no id returns the latest job (or null).
 */
session_start();
require_once '../../config.php';
require_once '../../includes/functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

try {
    $conn = connectToDatabase();
    $id   = isset($_GET['id']) ? (int)$_GET['id'] : 0;

    if ($id > 0) {
        $stmt = $conn->prepare(
            "SELECT id, started_datetime, finished_datetime, status, total, processed, failed, message
               FROM intune_app_sync_jobs WHERE id = ?"
        );
        $stmt->execute([$id]);
    } else {
        $stmt = $conn->query(
            "SELECT id, started_datetime, finished_datetime, status, total, processed, failed, message
               FROM intune_app_sync_jobs ORDER BY id DESC LIMIT 1"
        );
    }

    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        echo json_encode(['success' => true, 'job' => null]);
        exit;
    }

    $jobId = (int)$row['id'];

    // Roll up child statuses
    $rollup = $conn->prepare(
        "SELECT status, COUNT(*) c FROM intune_app_sync_job_assets WHERE job_id = ? GROUP BY status"
    );
    $rollup->execute([$jobId]);
    $statuses = ['pending' => 0, 'done' => 0, 'error' => 0, 'obsolete' => 0];
    foreach ($rollup->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $statuses[$r['status']] = (int)$r['c'];
    }

    $pct = 0;
    $total = (int)$row['total'];
    if ($row['status'] === 'done') {
        $pct = 100;
    } elseif ($total > 0) {
        $pct = (int)floor((((int)$row['processed'] + (int)$row['failed']) / $total) * 100);
        if ($pct > 99) $pct = 99;
    }

    echo json_encode([
        'success' => true,
        'job' => [
            'id'                => $jobId,
            'started_datetime'  => $row['started_datetime'],
            'finished_datetime' => $row['finished_datetime'],
            'status'            => $row['status'],
            'total'             => $total,
            'processed'         => (int)$row['processed'],
            'failed'            => (int)$row['failed'],
            'message'           => $row['message'],
            'percent'           => $pct,
            'rollup'            => $statuses,
        ],
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
