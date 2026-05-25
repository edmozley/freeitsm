<?php
/**
 * API Endpoint: Log ticket audit entry
 * Records changes to ticket properties
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';

header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

// Get POST data
$data = json_decode(file_get_contents('php://input'), true);

$ticketId = $data['ticket_id'] ?? null;
$fieldName = $data['field_name'] ?? null;
$oldValue = $data['old_value'] ?? null;
$newValue = $data['new_value'] ?? null;
$analystId = $_SESSION['analyst_id'];

if (!$ticketId || !$fieldName) {
    echo json_encode(['success' => false, 'error' => 'Missing required fields']);
    exit;
}

try {
    $conn = connectToDatabase();

    $sql = "INSERT INTO ticket_audit (ticket_id, analyst_id, field_name, old_value, new_value, created_datetime)
            VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP())";

    $stmt = $conn->prepare($sql);
    $stmt->execute([$ticketId, $analystId, $fieldName, $oldValue, $newValue]);

    echo json_encode(['success' => true]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}

?>
