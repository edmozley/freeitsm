<?php
/**
 * API: Intune Dashboard Data
 * Returns all the aggregations the reporting/intune dashboard needs in a
 * single JSON payload — KPI numbers + every chart's data — so the page
 * makes one request rather than ten.
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

    // ---------- KPI strip ----------

    $stmt = $conn->query("SELECT COUNT(*) FROM intune_devices");
    $totalDevices = (int)$stmt->fetchColumn();

    $stmt = $conn->query("SELECT compliance_state, COUNT(*) AS c FROM intune_devices GROUP BY compliance_state");
    $complianceCounts = [];
    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $complianceCounts[$r['compliance_state'] ?: 'Unknown'] = (int)$r['c'];
    }
    $compliantCount = $complianceCounts['compliant'] ?? 0;
    $compliantPct = $totalDevices > 0 ? round(($compliantCount / $totalDevices) * 100) : 0;

    $stmt = $conn->query("SELECT COUNT(*) FROM intune_devices WHERE is_encrypted = 1");
    $encryptedCount = (int)$stmt->fetchColumn();
    $encryptedPct = $totalDevices > 0 ? round(($encryptedCount / $totalDevices) * 100) : 0;

    // Stale = no sync in the last 30 days (or never synced)
    $stmt = $conn->query(
        "SELECT COUNT(*) FROM intune_devices
         WHERE last_sync_datetime IS NULL
            OR last_sync_datetime < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 30 DAY)"
    );
    $staleCount = (int)$stmt->fetchColumn();

    // Enrolled in last 30 days
    $stmt = $conn->query(
        "SELECT COUNT(*) FROM intune_devices
         WHERE enrolled_datetime IS NOT NULL
           AND enrolled_datetime >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 30 DAY)"
    );
    $enrolledRecently = (int)$stmt->fetchColumn();

    // ---------- Compliance breakdown (doughnut) ----------

    $compliance = [];
    foreach ($complianceCounts as $state => $count) {
        $compliance[] = ['label' => ucwords(str_replace(['_', '-'], ' ', $state)), 'value' => $count];
    }

    // ---------- Operating system breakdown (doughnut) ----------

    $stmt = $conn->query(
        "SELECT COALESCE(NULLIF(operating_system,''), 'Unknown') AS os, COUNT(*) AS c
           FROM intune_devices
       GROUP BY os
       ORDER BY c DESC"
    );
    $osBreakdown = [];
    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $osBreakdown[] = ['label' => $r['os'], 'value' => (int)$r['c']];
    }

    // ---------- Owner type (doughnut) ----------

    $stmt = $conn->query(
        "SELECT COALESCE(NULLIF(managed_device_owner_type,''), 'Unknown') AS owner, COUNT(*) AS c
           FROM intune_devices
       GROUP BY owner
       ORDER BY c DESC"
    );
    $ownerType = [];
    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $ownerType[] = ['label' => ucwords(str_replace(['_', '-'], ' ', $r['owner'])), 'value' => (int)$r['c']];
    }

    // ---------- Manufacturer top 10 (bar) ----------

    $stmt = $conn->query(
        "SELECT COALESCE(NULLIF(manufacturer,''), 'Unknown') AS m, COUNT(*) AS c
           FROM intune_devices
       GROUP BY m
       ORDER BY c DESC
          LIMIT 10"
    );
    $manufacturers = [];
    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $manufacturers[] = ['label' => $r['m'], 'value' => (int)$r['c']];
    }

    // ---------- OS version top 10 (bar) ----------

    $stmt = $conn->query(
        "SELECT CONCAT(
                  COALESCE(NULLIF(operating_system,''), '?'),
                  ' ',
                  COALESCE(NULLIF(os_version,''), '?')
                ) AS v,
                COUNT(*) AS c
           FROM intune_devices
       GROUP BY v
       ORDER BY c DESC
          LIMIT 10"
    );
    $osVersions = [];
    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $osVersions[] = ['label' => $r['v'], 'value' => (int)$r['c']];
    }

    // ---------- Enrolment trend last 90 days (line) ----------

    $stmt = $conn->query(
        "SELECT DATE(enrolled_datetime) AS d, COUNT(*) AS c
           FROM intune_devices
          WHERE enrolled_datetime IS NOT NULL
            AND enrolled_datetime >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)
       GROUP BY d
       ORDER BY d ASC"
    );
    $byDay = [];
    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $byDay[$r['d']] = (int)$r['c'];
    }
    // Fill the 90-day window, even days with zero enrolments
    $enrolmentTrend = [];
    for ($i = 89; $i >= 0; $i--) {
        $day = date('Y-m-d', strtotime("-{$i} days"));
        $enrolmentTrend[] = ['label' => $day, 'value' => $byDay[$day] ?? 0];
    }

    // ---------- Last sync distribution (bar) ----------

    $bucketSql = "
        SELECT
          SUM(CASE WHEN last_sync_datetime >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 DAY)  THEN 1 ELSE 0 END) AS today,
          SUM(CASE WHEN last_sync_datetime >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 7 DAY)
                AND last_sync_datetime <  DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 DAY) THEN 1 ELSE 0 END) AS week,
          SUM(CASE WHEN last_sync_datetime >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 30 DAY)
                AND last_sync_datetime <  DATE_SUB(UTC_TIMESTAMP(), INTERVAL 7 DAY)  THEN 1 ELSE 0 END) AS month,
          SUM(CASE WHEN last_sync_datetime >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 90 DAY)
                AND last_sync_datetime <  DATE_SUB(UTC_TIMESTAMP(), INTERVAL 30 DAY) THEN 1 ELSE 0 END) AS quarter,
          SUM(CASE WHEN last_sync_datetime <  DATE_SUB(UTC_TIMESTAMP(), INTERVAL 90 DAY) THEN 1 ELSE 0 END) AS old,
          SUM(CASE WHEN last_sync_datetime IS NULL THEN 1 ELSE 0 END) AS never
        FROM intune_devices";
    $row = $conn->query($bucketSql)->fetch(PDO::FETCH_ASSOC);
    $lastSync = [
        ['label' => 'Today',     'value' => (int)$row['today']],
        ['label' => '1-7 days',  'value' => (int)$row['week']],
        ['label' => '8-30 days', 'value' => (int)$row['month']],
        ['label' => '31-90 days','value' => (int)$row['quarter']],
        ['label' => '90+ days',  'value' => (int)$row['old']],
        ['label' => 'Never',     'value' => (int)$row['never']],
    ];

    // ---------- Encryption by OS (stacked bar) ----------

    $stmt = $conn->query(
        "SELECT COALESCE(NULLIF(operating_system,''), 'Unknown') AS os,
                SUM(CASE WHEN is_encrypted = 1 THEN 1 ELSE 0 END) AS encrypted,
                SUM(CASE WHEN is_encrypted = 0 OR is_encrypted IS NULL THEN 1 ELSE 0 END) AS not_encrypted
           FROM intune_devices
       GROUP BY os
       ORDER BY (encrypted + not_encrypted) DESC
          LIMIT 8"
    );
    $encByOsLabels = [];
    $encByOsEnc    = [];
    $encByOsUnenc  = [];
    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $encByOsLabels[] = $r['os'];
        $encByOsEnc[]    = (int)$r['encrypted'];
        $encByOsUnenc[]  = (int)$r['not_encrypted'];
    }

    // ---------- Last sync metadata ----------

    $stmt = $conn->query(
        "SELECT id, started_datetime, finished_datetime, status, total, processed
           FROM intune_sync_jobs ORDER BY id DESC LIMIT 1"
    );
    $lastSyncJob = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

    echo json_encode([
        'success' => true,
        'kpi' => [
            'total_devices'      => $totalDevices,
            'compliant_pct'      => $compliantPct,
            'compliant_count'    => $compliantCount,
            'encrypted_pct'      => $encryptedPct,
            'encrypted_count'    => $encryptedCount,
            'stale_count'        => $staleCount,
            'enrolled_recently'  => $enrolledRecently,
        ],
        'charts' => [
            'compliance'      => $compliance,
            'os_breakdown'    => $osBreakdown,
            'owner_type'      => $ownerType,
            'manufacturers'   => $manufacturers,
            'os_versions'     => $osVersions,
            'enrolment_trend' => $enrolmentTrend,
            'last_sync'       => $lastSync,
            'encryption_by_os' => [
                'labels' => $encByOsLabels,
                'series' => [
                    ['label' => 'Encrypted',     'values' => $encByOsEnc],
                    ['label' => 'Not encrypted', 'values' => $encByOsUnenc],
                ],
            ],
        ],
        'last_sync_job' => $lastSyncJob,
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
