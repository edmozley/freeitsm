<?php
/**
 * API Endpoint: Delete an analyst
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

if (!$data || !isset($data['id'])) {
    echo json_encode(['success' => false, 'error' => 'Invalid request data']);
    exit;
}

$id = (int)$data['id'];

// Prevent self-deletion
if ($id === $_SESSION['analyst_id']) {
    echo json_encode(['success' => false, 'error' => 'You cannot delete your own account']);
    exit;
}

try {
    $conn = connectToDatabase();

    // Check if analyst exists
    $checkSql = "SELECT id, username FROM analysts WHERE id = ?";
    $checkStmt = $conn->prepare($checkSql);
    $checkStmt->execute([$id]);
    $analyst = $checkStmt->fetch(PDO::FETCH_ASSOC);

    if (!$analyst) {
        echo json_encode(['success' => false, 'error' => 'Analyst not found']);
        exit;
    }

    // Delete the analyst
    $sql = "DELETE FROM analysts WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$id]);

    echo json_encode(['success' => true, 'message' => 'Analyst deleted successfully']);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

?>
