<?php
/**
 * API: list recent InTune app-sync jobs for the settings page.
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

    $rows = $conn->query(
        "SELECT id, started_datetime, finished_datetime, status, total, processed, failed, message
           FROM intune_app_sync_jobs
          ORDER BY id DESC
          LIMIT 20"
    )->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as &$r) {
        $r['id']        = (int)$r['id'];
        $r['total']     = (int)$r['total'];
        $r['processed'] = (int)$r['processed'];
        $r['failed']    = (int)$r['failed'];
    }

    // Tell the UI how many candidate assets are queued for the next job.
    $candidates = (int)$conn->query("
        SELECT COUNT(*)
          FROM assets a
          JOIN intune_devices id_dev ON id_dev.asset_id = a.id
         WHERE id_dev.intune_id IS NOT NULL AND id_dev.intune_id <> ''
           AND NOT EXISTS (
               SELECT 1 FROM software_inventory_detail d
                WHERE d.host_id = a.id AND d.source = 'agent'
           )
    ")->fetchColumn();

    echo json_encode([
        'success' => true,
        'jobs' => $rows,
        'eligible_assets' => $candidates,
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
