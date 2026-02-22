<?php
/**
 * API Endpoint: Create or update a ticket dashboard widget definition
 * POST: { id?, title, description, chart_type, aggregate_property, series_property,
 *          is_status_filterable, date_range, department_filter, time_grouping }
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
$date_range = $data['date_range'] ?? null;
$department_filter = $data['department_filter'] ?? null;
$time_grouping = $data['time_grouping'] ?? null;

if (empty($title)) {
    echo json_encode(['success' => false, 'error' => 'Title is required']);
    exit;
}

$allowed_properties = [
    'status', 'priority', 'department', 'ticket_type', 'analyst', 'owner', 'origin',
    'first_time_fix', 'training_provided',
    'created', 'closed', 'created_vs_closed'
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

// Validate date_range
$allowed_date_ranges = [null, '', 'all', '7d', '30d', 'this_month', '3m', '6m', '12m', 'this_year'];
if (!in_array($date_range, $allowed_date_ranges)) {
    echo json_encode(['success' => false, 'error' => 'Invalid date range']);
    exit;
}
if (empty($date_range) || $date_range === 'all') $date_range = null;

// Validate time_grouping
$time_aggregates = ['created', 'closed', 'created_vs_closed'];
$allowed_groupings = [null, '', 'day', 'month', 'year'];
if (!in_array($time_grouping, $allowed_groupings)) {
    echo json_encode(['success' => false, 'error' => 'Invalid time grouping']);
    exit;
}
if (in_array($aggregate_property, $time_aggregates) && empty($time_grouping)) {
    echo json_encode(['success' => false, 'error' => 'Time grouping is required for time-based aggregates']);
    exit;
}
if (!in_array($aggregate_property, $time_aggregates)) {
    $time_grouping = null;
}
if (empty($time_grouping)) $time_grouping = null;

// Validate department_filter
if (!empty($department_filter) && is_array($department_filter)) {
    $department_filter = json_encode(array_values(array_map('intval', $department_filter)));
} else {
    $department_filter = null;
}

try {
    $conn = connectToDatabase();

    if ($id) {
        $stmt = $conn->prepare("UPDATE ticket_dashboard_widgets
            SET title = ?, description = ?, chart_type = ?, aggregate_property = ?,
                series_property = ?, is_status_filterable = ?, date_range = ?,
                department_filter = ?, time_grouping = ?
            WHERE id = ?");
        $stmt->execute([$title, $description, $chart_type, $aggregate_property,
                        $series_property, $is_status_filterable, $date_range,
                        $department_filter, $time_grouping, $id]);
    } else {
        $maxStmt = $conn->query("SELECT COALESCE(MAX(display_order), 0) + 1 AS next_order FROM ticket_dashboard_widgets");
        $nextOrder = (int)$maxStmt->fetch(PDO::FETCH_ASSOC)['next_order'];

        $stmt = $conn->prepare("INSERT INTO ticket_dashboard_widgets
            (title, description, chart_type, aggregate_property, series_property,
             is_status_filterable, date_range, department_filter, time_grouping, display_order)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$title, $description, $chart_type, $aggregate_property,
                        $series_property, $is_status_filterable, $date_range,
                        $department_filter, $time_grouping, $nextOrder]);
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
