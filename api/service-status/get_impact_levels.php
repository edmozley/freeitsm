<?php
/**
 * API Endpoint: Get service impact levels
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

    $sql = "SELECT id, name, colour, is_default, severity_order, display_order, is_active, created_datetime
            FROM service_impact_levels
            ORDER BY display_order, severity_order, name";

    $stmt = $conn->prepare($sql);
    $stmt->execute();
    $levels = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($levels as &$l) {
        $l['is_default'] = (bool)$l['is_default'];
        $l['is_active']  = (bool)$l['is_active'];
    }

    echo json_encode([
        'success' => true,
        'impact_levels' => $levels
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
