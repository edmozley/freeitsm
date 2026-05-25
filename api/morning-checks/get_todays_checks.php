<?php
/**
 * API Endpoint: Get Today's Morning Checks with Results
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
    // Get date from query parameter or default to today
    $checkDate = $_GET['date'] ?? date('Y-m-d');

    // Validate date format
    $dateObj = DateTime::createFromFormat('Y-m-d', $checkDate);
    if (!$dateObj || $dateObj->format('Y-m-d') !== $checkDate) {
        $checkDate = date('Y-m-d');
    }

    $conn = connectToDatabase();

    $sql = "SELECT c.CheckID, c.CheckName, c.CheckDescription, c.SortOrder, r.Status, r.Notes
            FROM morningChecks_Checks c
            LEFT JOIN morningChecks_Results r ON c.CheckID = r.CheckID AND r.CheckDate = ?
            WHERE c.IsActive = 1
            ORDER BY c.SortOrder, c.CheckName";

    $stmt = $conn->prepare($sql);
    $stmt->execute([$checkDate]);
    $checks = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Convert types for JS
    foreach ($checks as &$check) {
        $check['CheckID'] = (int)$check['CheckID'];
        $check['SortOrder'] = (int)$check['SortOrder'];
    }

    echo json_encode($checks);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>
