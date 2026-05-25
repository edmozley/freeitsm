<?php
/**
 * API Endpoint: Get Chart Data for Morning Checks (Last 30 Days)
 *
 * Returns one dataset per ACTIVE status (so admins can add/remove
 * status options and the chart picks up the change). Historical
 * results saved against a status that's since been deactivated /
 * deleted won't appear in the chart but the underlying row keeps
 * its label string.
 *
 * Response shape:
 *   {
 *     dates:    ["May 25", ...]            // 30 human-readable labels
 *     rawDates: ["2026-05-25", ...]        // matching ISO dates (for click-through)
 *     datasets: [
 *       { label: "Green", colour: "#28a745", data: [0,1,2,...] },
 *       ...
 *     ]
 *   }
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

try {
    // Get end date from query parameter or default to today
    $endDate = $_GET['endDate'] ?? date('Y-m-d');

    // Validate date format
    $dateObj = DateTime::createFromFormat('Y-m-d', $endDate);
    if (!$dateObj || $dateObj->format('Y-m-d') !== $endDate) {
        $endDate = date('Y-m-d');
    }

    // Calculate start date (29 days before end date to get 30 days total)
    $startDate = date('Y-m-d', strtotime($endDate . ' -29 days'));

    $conn = connectToDatabase();

    // Active statuses define the chart's datasets.
    $statusStmt = $conn->query(
        "SELECT StatusID, Label, Colour FROM morningChecks_Statuses
         WHERE IsActive = 1 ORDER BY SortOrder, StatusID"
    );
    $activeStatuses = $statusStmt->fetchAll(PDO::FETCH_ASSOC);

    // Normalised query — group by StatusID via the FK. Orphan rows
    // (r.StatusID IS NULL) are excluded from the chart; admin sees
    // the dashboard banner and uses the normalisation tool to remap
    // them, after which they'll re-appear in subsequent chart loads.
    $sql = "SELECT DATE_FORMAT(r.CheckDate, '%Y-%m-%d') as CheckDate,
                   s.StatusID, COUNT(*) as Count
            FROM morningChecks_Results r
            INNER JOIN morningChecks_Checks c ON r.CheckID = c.CheckID
            INNER JOIN morningChecks_Statuses s ON r.StatusID = s.StatusID
            WHERE r.CheckDate >= ? AND r.CheckDate <= ?
              AND s.IsActive = 1
            GROUP BY DATE_FORMAT(r.CheckDate, '%Y-%m-%d'), s.StatusID
            ORDER BY DATE_FORMAT(r.CheckDate, '%Y-%m-%d')";

    $stmt = $conn->prepare($sql);
    $stmt->execute([$startDate, $endDate]);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Initialise: each of 30 days × each active status → 0.
    $dates = [];
    $rawDates = [];
    $countsByDate = [];   // [yyyy-mm-dd][statusId] => count
    for ($i = 29; $i >= 0; $i--) {
        $date = date('Y-m-d', strtotime($endDate . " -$i days"));
        $rawDates[] = $date;
        $dates[] = date('M j', strtotime($date));
        $countsByDate[$date] = [];
        foreach ($activeStatuses as $s) {
            $countsByDate[$date][(int)$s['StatusID']] = 0;
        }
    }

    foreach ($results as $row) {
        $date = $row['CheckDate'];
        $sid = (int)$row['StatusID'];
        if (isset($countsByDate[$date]) && array_key_exists($sid, $countsByDate[$date])) {
            $countsByDate[$date][$sid] = (int)$row['Count'];
        }
    }

    // Build one dataset per active status, preserving SortOrder.
    $datasets = [];
    foreach ($activeStatuses as $s) {
        $sid = (int)$s['StatusID'];
        $arr = [];
        foreach ($rawDates as $d) {
            $arr[] = $countsByDate[$d][$sid];
        }
        $datasets[] = [
            'label'  => $s['Label'],
            'colour' => $s['Colour'],
            'data'   => $arr,
        ];
    }

    echo json_encode([
        'dates'    => $dates,
        'rawDates' => $rawDates,
        'datasets' => $datasets,
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
