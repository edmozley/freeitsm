<?php
/**
 * API: Self-Service Dashboard Data
 * GET - Returns ticket summary, recent tickets, and service status for the logged-in user
 */
session_start();
require_once '../../config.php';
require_once '../../includes/functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['ss_user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$userId = (int)$_SESSION['ss_user_id'];

try {
    $conn = connectToDatabase();

    // Ticket summary counts by status
    $countStmt = $conn->prepare("SELECT status, COUNT(*) as count FROM tickets WHERE user_id = ? GROUP BY status");
    $countStmt->execute([$userId]);
    $rows = $countStmt->fetchAll(PDO::FETCH_ASSOC);

    $ticketSummary = ['Open' => 0, 'In Progress' => 0, 'On Hold' => 0, 'Closed' => 0, 'total' => 0];
    foreach ($rows as $row) {
        $ticketSummary[$row['status']] = (int)$row['count'];
        $ticketSummary['total'] += (int)$row['count'];
    }

    // Recent tickets (last 10)
    $ticketStmt = $conn->prepare(
        "SELECT t.id, t.ticket_number, t.subject, t.status, t.priority,
                t.created_datetime, t.updated_datetime,
                d.name as department_name
         FROM tickets t
         LEFT JOIN departments d ON t.department_id = d.id
         WHERE t.user_id = ?
         ORDER BY t.updated_datetime DESC
         LIMIT 10"
    );
    $ticketStmt->execute([$userId]);
    $recentTickets = $ticketStmt->fetchAll(PDO::FETCH_ASSOC);

    // Service status - active services with worst current impact
    $svcStmt = $conn->prepare(
        "SELECT ss.id, ss.name,
            COALESCE(
                (SELECT sis.impact_level
                 FROM status_incident_services sis
                 JOIN status_incidents si ON sis.incident_id = si.id
                 WHERE sis.service_id = ss.id
                   AND si.status != 'Resolved'
                 ORDER BY
                     CASE sis.impact_level
                         WHEN 'Major Outage' THEN 1
                         WHEN 'Partial Outage' THEN 2
                         WHEN 'Degraded' THEN 3
                         WHEN 'Maintenance' THEN 4
                         WHEN 'Operational' THEN 5
                     END ASC
                 LIMIT 1),
                'Operational'
            ) AS current_status
        FROM status_services ss
        WHERE ss.is_active = 1
        ORDER BY ss.display_order, ss.name"
    );
    $svcStmt->execute();
    $services = $svcStmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'ticket_summary' => $ticketSummary,
        'recent_tickets' => $recentTickets,
        'services' => $services
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
