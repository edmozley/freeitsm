<?php
/**
 * API Endpoint: Get Chart Data for Morning Checks (Last 30 Days)
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

    // Embed validated dates directly - PDO ODBC has issues with date parameters
    // Use CONVERT to get just the date portion as VARCHAR (format 23 = yyyy-mm-dd)
    $sql = "SELECT CONVERT(VARCHAR(10), r.CheckDate, 23) as CheckDate, r.Status, COUNT(*) as Count
            FROM morningChecks_Results r
            INNER JOIN morningChecks_Checks c ON r.CheckID = c.CheckID
            WHERE r.CheckDate >= '$startDate' AND r.CheckDate <= '$endDate'
            GROUP BY CONVERT(VARCHAR(10), r.CheckDate, 23), r.Status
            ORDER BY CONVERT(VARCHAR(10), r.CheckDate, 23)";

    $stmt = $conn->prepare($sql);
    $stmt->execute();
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Build data structure - generate all dates for the 30 days ending on endDate
    $dates = [];
    $data = [];

    for ($i = 29; $i >= 0; $i--) {
        $date = date('Y-m-d', strtotime($endDate . " -$i days"));
        $dates[] = date('M j', strtotime($date));
        $data[$date] = [
            'Green' => 0,
            'Amber' => 0,
            'Red' => 0
        ];
    }

    // Fill in the actual data
    foreach ($results as $row) {
        $date = $row['CheckDate'];
        $status = $row['Status'];
        $count = (int)$row['Count'];

        if (isset($data[$date])) {
            $data[$date][$status] = $count;
        }
    }

    // Convert to format expected by Chart.js
    $green = [];
    $amber = [];
    $red = [];

    foreach ($data as $dateData) {
        $green[] = $dateData['Green'];
        $amber[] = $dateData['Amber'];
        $red[] = $dateData['Red'];
    }

    echo json_encode([
        'dates' => $dates,
        'rawDates' => array_keys($data),
        'green' => $green,
        'amber' => $amber,
        'red' => $red
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>
