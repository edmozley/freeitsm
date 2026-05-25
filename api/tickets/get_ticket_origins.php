<?php
/**
 * Get Ticket Origins API
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

    $sql = "SELECT id, name, description, display_order, is_active
            FROM ticket_origins
            ORDER BY display_order, name";

    $stmt = $conn->prepare($sql);
    $stmt->execute();

    $origins = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Convert bit fields to boolean
    foreach ($origins as &$origin) {
        $origin['is_active'] = (bool)$origin['is_active'];
    }

    echo json_encode([
        'success' => true,
        'origins' => $origins
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>
