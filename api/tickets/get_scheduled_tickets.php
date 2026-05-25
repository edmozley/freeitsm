<?php
/**
 * API Endpoint: Get scheduled tickets for calendar view
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

// New: support explicit start/end (YYYY-MM-DD) for week/day views.
// Fallback: legacy year/month query for backwards compatibility.
$start = $_GET['start'] ?? null;
$end = $_GET['end'] ?? null;

if ($start && $end && preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $end)) {
    $startDate = $start;
    $endDate = $end;
} else {
    $year = (int)($_GET['year'] ?? date('Y'));
    $month = (int)($_GET['month'] ?? date('n'));
    $startDate = date('Y-m-d', strtotime("$year-$month-01 -7 days"));
    $endDate = date('Y-m-d', strtotime("$year-$month-01 +40 days"));
}

try {
    $conn = connectToDatabase();

    $sql = "SELECT
                t.id,
                t.ticket_number,
                t.subject,
                ts.name AS status,
                tp.name AS priority,
                t.work_start_datetime,
                u.display_name AS requester_name,
                u.email AS requester_email,
                d.name as department_name,
                a.full_name as owner_name
            FROM tickets t
            LEFT JOIN ticket_statuses ts ON ts.id = t.status_id
            LEFT JOIN ticket_priorities tp ON tp.id = t.priority_id
            LEFT JOIN departments d ON d.id = t.department_id
            LEFT JOIN analysts a ON a.id = t.owner_id
            LEFT JOIN users u ON u.id = t.user_id
            WHERE t.work_start_datetime IS NOT NULL
              AND t.work_start_datetime >= ?
              AND t.work_start_datetime < ?
              AND ts.is_closed = 0
            ORDER BY t.work_start_datetime ASC";

    $stmt = $conn->prepare($sql);
    $stmt->execute([$startDate, $endDate]);
    $tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Format datetime for JavaScript
    foreach ($tickets as &$ticket) {
        if ($ticket['work_start_datetime']) {
            $ticket['work_start_datetime'] = date('Y-m-d\TH:i:s', strtotime($ticket['work_start_datetime']));
        }
    }

    echo json_encode([
        'success' => true,
        'tickets' => $tickets
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}

?>
