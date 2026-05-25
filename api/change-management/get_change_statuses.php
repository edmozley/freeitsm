<?php
/**
 * API Endpoint: Get change statuses
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

    $sql = "SELECT id, name, is_closed, colour, is_default, display_order, is_active, created_datetime
            FROM change_statuses
            ORDER BY display_order, name";

    $stmt = $conn->prepare($sql);
    $stmt->execute();
    $statuses = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($statuses as &$s) {
        $s['is_closed']  = (bool)$s['is_closed'];
        $s['is_default'] = (bool)$s['is_default'];
        $s['is_active']  = (bool)$s['is_active'];
    }

    echo json_encode([
        'success' => true,
        'statuses' => $statuses
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
