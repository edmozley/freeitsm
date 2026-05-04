<?php
/**
 * API Endpoint: Get Single Calendar Event
 */
session_start();
require_once '../../config.php';
require_once '../../includes/functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : null;

if (!$id) {
    echo json_encode(['success' => false, 'error' => 'Event ID is required']);
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
            WHERE e.id = ?";

    $stmt = $conn->prepare($sql);
    $stmt->execute([$id]);
    $event = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$event) {
        echo json_encode(['success' => false, 'error' => 'Event not found']);
        exit;
    }

    $event['all_day'] = (bool)$event['all_day'];

    echo json_encode([
        'success' => true,
        'event' => $event
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
