<?php
/**
 * API Endpoint: Get Calendar Events
 * Returns events within a date range, optionally filtered by category
 */
session_start();
require_once '../../config.php';
require_once '../../includes/functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$startDate = $_GET['start'] ?? null;
$endDate = $_GET['end'] ?? null;
$categoryIds = isset($_GET['categories']) ? explode(',', $_GET['categories']) : null;
$contractId = isset($_GET['contract_id']) && $_GET['contract_id'] !== '' ? (int)$_GET['contract_id'] : null;

// Date range is required unless filtering by contract_id
if (!$contractId && (!$startDate || !$endDate)) {
    echo json_encode(['success' => false, 'error' => 'Start and end dates are required']);
    exit;
}

try {
    $conn = connectToDatabase();

    $sql = "SELECT e.id, e.title, e.description, e.category_id, e.start_datetime, e.end_datetime,
                   e.all_day, e.location, e.contract_id, e.created_by, e.created_at, e.updated_at,
                   c.name as category_name, c.color as category_color,
                   a.full_name as created_by_name
            FROM calendar_events e
            LEFT JOIN calendar_categories c ON e.category_id = c.id
            LEFT JOIN analysts a ON e.created_by = a.id
            WHERE 1=1";

    $params = [];

    if ($startDate && $endDate) {
        $sql .= " AND (
                    (e.start_datetime >= ? AND e.start_datetime < ?)
                    OR (e.end_datetime > ? AND e.end_datetime <= ?)
                    OR (e.start_datetime < ? AND e.end_datetime > ?)
                  )";
        array_push($params, $startDate, $endDate, $startDate, $endDate, $startDate, $endDate);
    }

    if ($contractId) {
        $sql .= " AND e.contract_id = ?";
        $params[] = $contractId;
    }

    // Filter by categories if specified
    if ($categoryIds && count($categoryIds) > 0) {
        $placeholders = implode(',', array_fill(0, count($categoryIds), '?'));
        $sql .= " AND e.category_id IN ($placeholders)";
        $params = array_merge($params, $categoryIds);
    }

    $sql .= " ORDER BY e.start_datetime";

    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $events = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Convert all_day to boolean
    foreach ($events as &$event) {
        $event['all_day'] = (bool)$event['all_day'];
    }

    echo json_encode([
        'success' => true,
        'events' => $events
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
