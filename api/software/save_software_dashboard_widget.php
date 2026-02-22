<?php
/**
 * API Endpoint: Create or update a software dashboard widget definition
 * POST: { id?, title, description, chart_type, aggregate_property, app_id?, exclude_system_components }
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
$aggregate_property = $data['aggregate_property'] ?? 'version_distribution';
$app_id = $data['app_id'] ?? null;
$exclude_system_components = !empty($data['exclude_system_components']) ? 1 : 0;

if (empty($title)) {
    echo json_encode(['success' => false, 'error' => 'Title is required']);
    exit;
}

$allowed_properties = ['version_distribution', 'top_installed', 'publisher_distribution'];
if (!in_array($aggregate_property, $allowed_properties)) {
    echo json_encode(['success' => false, 'error' => 'Invalid aggregate property']);
    exit;
}

$allowed_chart_types = ['bar', 'pie', 'doughnut'];
if (!in_array($chart_type, $allowed_chart_types)) {
    echo json_encode(['success' => false, 'error' => 'Invalid chart type']);
    exit;
}

if ($aggregate_property === 'version_distribution' && empty($app_id)) {
    echo json_encode(['success' => false, 'error' => 'Application is required for version distribution']);
    exit;
}

// Clear app_id for non-version types
if ($aggregate_property !== 'version_distribution') {
    $app_id = null;
}

try {
    $conn = connectToDatabase();

    if ($id) {
        $stmt = $conn->prepare("UPDATE software_dashboard_widgets
            SET title = ?, description = ?, chart_type = ?, aggregate_property = ?,
                app_id = ?, exclude_system_components = ?
            WHERE id = ?");
        $stmt->execute([$title, $description, $chart_type, $aggregate_property, $app_id, $exclude_system_components, $id]);
    } else {
        $maxStmt = $conn->query("SELECT COALESCE(MAX(display_order), 0) + 1 AS next_order FROM software_dashboard_widgets");
        $nextOrder = (int)$maxStmt->fetch(PDO::FETCH_ASSOC)['next_order'];

        $stmt = $conn->prepare("INSERT INTO software_dashboard_widgets
            (title, description, chart_type, aggregate_property, app_id, exclude_system_components, display_order)
            VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$title, $description, $chart_type, $aggregate_property, $app_id, $exclude_system_components, $nextOrder]);
        $id = $conn->lastInsertId();
    }

    $fetchStmt = $conn->prepare("SELECT w.*, COALESCE(a.display_name, '') AS app_name
                                 FROM software_dashboard_widgets w
                                 LEFT JOIN software_inventory_apps a ON a.id = w.app_id
                                 WHERE w.id = ?");
    $fetchStmt->execute([$id]);
    $widget = $fetchStmt->fetch(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'widget' => $widget]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
