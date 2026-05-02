<?php
/**
 * API: progress poll for an InTune sync job.
 * GET ?id=<jobId> returns that job; no id returns the latest job (or null).
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
            "SELECT id, started_datetime, finished_datetime, status, total, processed, message
               FROM intune_sync_jobs WHERE id = ?"
        );
        $stmt->execute([$id]);
    } else {
        $stmt = $conn->query(
            "SELECT id, started_datetime, finished_datetime, status, total, processed, message
               FROM intune_sync_jobs ORDER BY id DESC LIMIT 1"
        );
    }

    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        echo json_encode(['success' => true, 'job' => null]);
        exit;
    }

    $pct = 0;
    if ($row['status'] === 'done') {
        $pct = 100;
    } elseif ((int)$row['total'] > 0) {
        $pct = (int)floor(((int)$row['processed'] / (int)$row['total']) * 100);
        if ($pct > 99) $pct = 99;
    }

    echo json_encode([
        'success' => true,
        'job' => [
            'id'                => (int)$row['id'],
            'started_datetime'  => $row['started_datetime'],
            'finished_datetime' => $row['finished_datetime'],
            'status'            => $row['status'],
            'total'             => (int)$row['total'],
            'processed'         => (int)$row['processed'],
            'message'           => $row['message'],
            'percent'           => $pct,
        ],
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
