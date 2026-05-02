<?php
/**
 * API: per-asset freshness buckets for the InTune software-sync chart.
 *
 * Scope: assets that are linked to an Intune managedDevice AND have NO
 * source='agent' rows in software_inventory_detail (i.e. the population
 * the Intune app sync actually targets).
 *
 * Returns counts in 9 buckets: <1d, 1d, 2d, 3d, 4d, 5d, 6d, 7+d, never —
 * based on the most recent source='intune' last_seen for each asset.
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

    $sql = "
        SELECT
            SUM(CASE WHEN last_sync IS NULL THEN 1 ELSE 0 END)                              AS never_synced,
            SUM(CASE WHEN TIMESTAMPDIFF(DAY, last_sync, UTC_TIMESTAMP()) = 0 THEN 1 ELSE 0 END) AS d0,
            SUM(CASE WHEN TIMESTAMPDIFF(DAY, last_sync, UTC_TIMESTAMP()) = 1 THEN 1 ELSE 0 END) AS d1,
            SUM(CASE WHEN TIMESTAMPDIFF(DAY, last_sync, UTC_TIMESTAMP()) = 2 THEN 1 ELSE 0 END) AS d2,
            SUM(CASE WHEN TIMESTAMPDIFF(DAY, last_sync, UTC_TIMESTAMP()) = 3 THEN 1 ELSE 0 END) AS d3,
            SUM(CASE WHEN TIMESTAMPDIFF(DAY, last_sync, UTC_TIMESTAMP()) = 4 THEN 1 ELSE 0 END) AS d4,
            SUM(CASE WHEN TIMESTAMPDIFF(DAY, last_sync, UTC_TIMESTAMP()) = 5 THEN 1 ELSE 0 END) AS d5,
            SUM(CASE WHEN TIMESTAMPDIFF(DAY, last_sync, UTC_TIMESTAMP()) = 6 THEN 1 ELSE 0 END) AS d6,
            SUM(CASE WHEN TIMESTAMPDIFF(DAY, last_sync, UTC_TIMESTAMP()) >= 7 THEN 1 ELSE 0 END) AS d7plus
        FROM (
            SELECT a.id AS asset_id,
                   (SELECT MAX(d.last_seen)
                      FROM software_inventory_detail d
                     WHERE d.host_id = a.id AND d.source = 'intune') AS last_sync
              FROM assets a
              JOIN intune_devices id_dev ON id_dev.asset_id = a.id
             WHERE id_dev.intune_id IS NOT NULL AND id_dev.intune_id <> ''
               AND NOT EXISTS (
                   SELECT 1 FROM software_inventory_detail d
                    WHERE d.host_id = a.id AND d.source = 'agent'
               )
        ) AS eligible
    ";

    $row = $conn->query($sql)->fetch(PDO::FETCH_ASSOC) ?: [];

    $buckets = [
        '<1d'  => (int)($row['d0']     ?? 0),
        '1d'   => (int)($row['d1']     ?? 0),
        '2d'   => (int)($row['d2']     ?? 0),
        '3d'   => (int)($row['d3']     ?? 0),
        '4d'   => (int)($row['d4']     ?? 0),
        '5d'   => (int)($row['d5']     ?? 0),
        '6d'   => (int)($row['d6']     ?? 0),
        '7+d'  => (int)($row['d7plus'] ?? 0),
        'never'=> (int)($row['never_synced'] ?? 0),
    ];

    echo json_encode(['success' => true, 'buckets' => $buckets]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
