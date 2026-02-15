<?php
/**
 * API Endpoint: Reorder Morning Checks
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
    $input = json_decode(file_get_contents('php://input'), true);
    $order = $input['order'] ?? null;

    if (!$order || !is_array($order)) {
        throw new Exception('Order array is required');
    }

    $conn = connectToDatabase();

    $stmt = $conn->prepare("UPDATE morningChecks_Checks SET SortOrder = ?, ModifiedDate = GETUTCDATE() WHERE CheckID = ?");

    foreach ($order as $index => $checkId) {
        $stmt->execute([(int)$index, (int)$checkId]);
    }

    echo json_encode(['success' => true]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
