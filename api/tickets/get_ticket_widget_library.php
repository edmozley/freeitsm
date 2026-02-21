<?php
/**
 * API Endpoint: Get all active ticket dashboard widgets from the library
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

    $stmt = $conn->query("SELECT id, title, description, chart_type, aggregate_property, series_property,
                                 is_status_filterable, default_status, display_order
                          FROM ticket_dashboard_widgets
                          WHERE is_active = 1
                          ORDER BY display_order ASC");
    $widgets = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'widgets' => $widgets]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
