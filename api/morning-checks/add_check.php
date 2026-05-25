<?php
/**
 * API Endpoint: Add New Morning Check
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
    $input = json_decode(file_get_contents('php://input'), true);

    $checkName = $input['checkName'] ?? null;
    $checkDescription = $input['checkDescription'] ?? '';
    $sortOrder = $input['sortOrder'] ?? 0;

    if (!$checkName || empty(trim($checkName))) {
        throw new Exception('Check name is required');
    }

    $conn = connectToDatabase();

    $sql = "INSERT INTO morningChecks_Checks (CheckName, CheckDescription, SortOrder, IsActive, CreatedDate, ModifiedDate)
            VALUES (?, ?, ?, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP())";

    $stmt = $conn->prepare($sql);
    $stmt->execute([trim($checkName), trim($checkDescription), (int)$sortOrder]);

    echo json_encode(['success' => true]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>
