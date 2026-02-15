<?php
/**
 * API Endpoint: Get Calendar Changes
 * Returns changes within a date range, optionally filtered by status
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
$statuses = isset($_GET['statuses']) ? explode(',', $_GET['statuses']) : null;

if (!$startDate || !$endDate) {
    echo json_encode(['success' => false, 'error' => 'Start and end dates are required']);
    exit;
}

// Status to color mapping
$statusColors = [
    'Draft'            => '#9e9e9e',
    'Pending Approval' => '#e65100',
    'Approved'         => '#2e7d32',
    'In Progress'      => '#1565c0',
    'Completed'        => '#1b5e20',
    'Failed'           => '#c62828',
    'Cancelled'        => '#bdbdbd'
];

try {
    $conn = connectToDatabase();

    $sql = "SELECT c.id, c.title, c.change_type, c.status, c.priority, c.impact,
                   c.work_start_datetime, c.work_end_datetime,
                   c.outage_start_datetime, c.outage_end_datetime,
                   a.full_name as assigned_to_name
            FROM changes c
            LEFT JOIN analysts a ON c.assigned_to_id = a.id
            WHERE c.work_start_datetime IS NOT NULL
              AND (
                (c.work_start_datetime >= ? AND c.work_start_datetime < ?)
                OR (c.work_end_datetime > ? AND c.work_end_datetime <= ?)
                OR (c.work_start_datetime < ? AND c.work_end_datetime > ?)
              )";

    $params = [$startDate, $endDate, $startDate, $endDate, $startDate, $endDate];

    // Filter by statuses if specified
    if ($statuses && count($statuses) > 0) {
        $placeholders = implode(',', array_fill(0, count($statuses), '?'));
        $sql .= " AND c.status IN ($placeholders)";
        $params = array_merge($params, $statuses);
    }

    $sql .= " ORDER BY c.work_start_datetime";

    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $changes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Map to calendar-compatible structure
    $events = [];
    foreach ($changes as $change) {
        $events[] = [
            'id'                    => (int)$change['id'],
            'title'                 => $change['title'],
            'change_type'           => $change['change_type'],
            'status'                => $change['status'],
            'priority'              => $change['priority'],
            'impact'                => $change['impact'],
            'start_datetime'        => $change['work_start_datetime'],
            'end_datetime'          => $change['work_end_datetime'],
            'outage_start_datetime' => $change['outage_start_datetime'],
            'outage_end_datetime'   => $change['outage_end_datetime'],
            'assigned_to_name'      => $change['assigned_to_name'],
            'status_color'          => $statusColors[$change['status']] ?? '#9e9e9e'
        ];
    }

    echo json_encode([
        'success' => true,
        'events'  => $events
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
