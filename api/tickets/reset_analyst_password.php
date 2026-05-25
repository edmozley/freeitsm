<?php
/**
 * API Endpoint: Reset an analyst's password
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

if (!$data || !isset($data['id']) || !isset($data['password'])) {
    echo json_encode(['success' => false, 'error' => 'Invalid request data']);
    exit;
}

$id = (int)$data['id'];
$password = $data['password'];

// Validation
if (strlen($password) < 6) {
    echo json_encode(['success' => false, 'error' => 'Password must be at least 6 characters']);
    exit;
}

try {
    $conn = connectToDatabase();

    // Check if analyst exists
    $checkSql = "SELECT id FROM analysts WHERE id = ?";
    $checkStmt = $conn->prepare($checkSql);
    $checkStmt->execute([$id]);
    if (!$checkStmt->fetch()) {
        echo json_encode(['success' => false, 'error' => 'Analyst not found']);
        exit;
    }

    // Update password
    $passwordHash = password_hash($password, PASSWORD_DEFAULT);

    $sql = "UPDATE analysts SET password_hash = ?, last_modified_datetime = UTC_TIMESTAMP() WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$passwordHash, $id]);

    echo json_encode(['success' => true, 'message' => 'Password reset successfully']);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

?>
