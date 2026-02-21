<?php
/**
 * API Endpoint: Create or update a dashboard widget definition
 * POST: { id?, title, description, chart_type, aggregate_property, is_status_filterable, default_status_id? }
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
$is_status_filterable = !empty($data['is_status_filterable']) ? 1 : 0;
$default_status_id = $data['default_status_id'] ?? null;

if (empty($title)) {
    echo json_encode(['success' => false, 'error' => 'Title is required']);
    exit;
}

$allowed_properties = [
    'operating_system', 'manufacturer', 'model', 'asset_type_id',
    'asset_status_id', 'feature_release', 'domain', 'cpu_name',
    'memory', 'gpu_name', 'tpm_version', 'bitlocker_status', 'bios_version'
];

if (!in_array($aggregate_property, $allowed_properties)) {
    echo json_encode(['success' => false, 'error' => 'Invalid aggregate property']);
    exit;
}

$allowed_chart_types = ['bar', 'pie', 'doughnut'];
if (!in_array($chart_type, $allowed_chart_types)) {
    echo json_encode(['success' => false, 'error' => 'Invalid chart type']);
    exit;
}

if ($is_status_filterable) {
    $default_status_id = null;
}

try {
    $conn = connectToDatabase();

    if ($id) {
        $stmt = $conn->prepare("UPDATE asset_dashboard_widgets
            SET title = ?, description = ?, chart_type = ?, aggregate_property = ?,
                is_status_filterable = ?, default_status_id = ?
            WHERE id = ?");
        $stmt->execute([$title, $description, $chart_type, $aggregate_property, $is_status_filterable, $default_status_id, $id]);
    } else {
        $maxStmt = $conn->query("SELECT COALESCE(MAX(display_order), 0) + 1 AS next_order FROM asset_dashboard_widgets");
        $nextOrder = (int)$maxStmt->fetch(PDO::FETCH_ASSOC)['next_order'];

        $stmt = $conn->prepare("INSERT INTO asset_dashboard_widgets
            (title, description, chart_type, aggregate_property, is_status_filterable, default_status_id, display_order)
            VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$title, $description, $chart_type, $aggregate_property, $is_status_filterable, $default_status_id, $nextOrder]);
        $id = $conn->lastInsertId();
    }

    $fetchStmt = $conn->prepare("SELECT * FROM asset_dashboard_widgets WHERE id = ?");
    $fetchStmt->execute([$id]);
    $widget = $fetchStmt->fetch(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'widget' => $widget]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
