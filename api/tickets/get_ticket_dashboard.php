<?php
/**
 * API Endpoint: Get analyst's saved ticket dashboard widgets
 */
session_start();
require_once '../../config.php';
require_once '../../includes/functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$analyst_id = $_SESSION['analyst_id'];

try {
    $conn = connectToDatabase();

    $stmt = $conn->prepare("SELECT atdw.id, atdw.widget_id, atdw.sort_order, atdw.status_filter,
                                   w.title, w.description, w.chart_type, w.aggregate_property,
                                   w.series_property, w.is_status_filterable, w.default_status
                            FROM analyst_ticket_dashboard_widgets atdw
                            INNER JOIN ticket_dashboard_widgets w ON w.id = atdw.widget_id
                            WHERE atdw.analyst_id = ? AND w.is_active = 1
                            ORDER BY atdw.sort_order ASC");
    $stmt->execute([$analyst_id]);
    $widgets = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'widgets' => $widgets]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
