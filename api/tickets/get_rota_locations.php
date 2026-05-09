<?php
/**
 * API Endpoint: Get rota locations
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
    $conn = connectToDatabase();

    $sql = "SELECT id, name, colour, is_default, display_order, is_active, created_datetime
            FROM rota_locations
            ORDER BY display_order, name";

    $stmt = $conn->prepare($sql);
    $stmt->execute();
    $locations = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($locations as &$l) {
        $l['is_default'] = (bool)$l['is_default'];
        $l['is_active']  = (bool)$l['is_active'];
    }

    echo json_encode([
        'success' => true,
        'locations' => $locations
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
