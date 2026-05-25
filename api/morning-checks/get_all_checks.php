<?php
/**
 * API Endpoint: Get All Morning Checks
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
    $conn = connectToDatabase();

    $sql = "SELECT CheckID, CheckName, CheckDescription, IsActive, SortOrder, CreatedDate, ModifiedDate
            FROM morningChecks_Checks
            ORDER BY SortOrder, CheckName";

    $stmt = $conn->prepare($sql);
    $stmt->execute();
    $checks = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Convert types for JS
    foreach ($checks as &$check) {
        $check['CheckID'] = (int)$check['CheckID'];
        $check['IsActive'] = (bool)$check['IsActive'];
        $check['SortOrder'] = (int)$check['SortOrder'];
    }

    echo json_encode($checks);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>
