<?php
/**
 * API Endpoint: Create or update a ticket dashboard widget definition
 * POST: { id?, title, description, chart_type, aggregate_property, series_property, is_status_filterable }
 */
session_start();
require_once '../../config.php';
require_once '../../includes/functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);

$id = $data['id'] ?? null;
$title = trim($data['title'] ?? '');
$description = trim($data['description'] ?? '');
$chart_type = $data['chart_type'] ?? 'bar';
$aggregate_property = $data['aggregate_property'] ?? '';
$series_property = $data['series_property'] ?? null;
$is_status_filterable = !empty($data['is_status_filterable']) ? 1 : 0;

if (empty($title)) {
    echo json_encode(['success' => false, 'error' => 'Title is required']);
    exit;
}

$allowed_properties = [
    'status', 'priority', 'department', 'ticket_type', 'analyst', 'owner', 'origin',
    'first_time_fix', 'training_provided',
    'created_daily', 'created_monthly', 'closed_daily', 'closed_monthly',
    'created_vs_closed_daily', 'created_vs_closed_monthly'
];

if (!in_array($aggregate_property, $allowed_properties)) {
    echo json_encode(['success' => false, 'error' => 'Invalid aggregate property']);
    exit;
}

$allowed_chart_types = ['bar', 'pie', 'doughnut', 'line'];
if (!in_array($chart_type, $allowed_chart_types)) {
    echo json_encode(['success' => false, 'error' => 'Invalid chart type']);
    exit;
}

$allowed_series = [null, '', 'status', 'priority'];
if (!in_array($series_property, $allowed_series)) {
    echo json_encode(['success' => false, 'error' => 'Invalid series property']);
    exit;
}

if (empty($series_property)) $series_property = null;

try {
    $conn = connectToDatabase();

    if ($id) {
        $stmt = $conn->prepare("UPDATE ticket_dashboard_widgets
            SET title = ?, description = ?, chart_type = ?, aggregate_property = ?,
                series_property = ?, is_status_filterable = ?
            WHERE id = ?");
        $stmt->execute([$title, $description, $chart_type, $aggregate_property, $series_property, $is_status_filterable, $id]);
    } else {
        $maxStmt = $conn->query("SELECT COALESCE(MAX(display_order), 0) + 1 AS next_order FROM ticket_dashboard_widgets");
        $nextOrder = (int)$maxStmt->fetch(PDO::FETCH_ASSOC)['next_order'];

        $stmt = $conn->prepare("INSERT INTO ticket_dashboard_widgets
            (title, description, chart_type, aggregate_property, series_property, is_status_filterable, display_order)
            VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$title, $description, $chart_type, $aggregate_property, $series_property, $is_status_filterable, $nextOrder]);
        $id = $conn->lastInsertId();
    }

    $fetchStmt = $conn->prepare("SELECT * FROM ticket_dashboard_widgets WHERE id = ?");
    $fetchStmt->execute([$id]);
    $widget = $fetchStmt->fetch(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'widget' => $widget]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
