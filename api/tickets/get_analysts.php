<?php
/**
 * API Endpoint: Get all analysts
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

try {
    $conn = connectToDatabase();

    $sql = "SELECT id, username, full_name, email, is_active, created_datetime, last_login_datetime, last_modified_datetime
            FROM analysts
            ORDER BY full_name";

    $stmt = $conn->prepare($sql);
    $stmt->execute();
    $analysts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Convert fields to proper types
    foreach ($analysts as &$analyst) {
        $analyst['id'] = (int)$analyst['id'];
        $analyst['is_active'] = (bool)$analyst['is_active'];
    }

    echo json_encode(['success' => true, 'analysts' => $analysts]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

?>
