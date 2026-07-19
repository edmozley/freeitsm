<?php
/**
 * API: Self-Service Dashboard Data
 * GET - Returns ticket summary, recent tickets, and service status for the logged-in user
 */
session_start(['read_and_close' => true]);
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

    // Active statuses from the lookup — drives the summary card layout dynamically
    $statusListStmt = $conn->query(
        "SELECT name, colour, is_closed
         FROM ticket_statuses
         WHERE is_active = 1
         ORDER BY display_order, id"
    );
    $activeStatuses = $statusListStmt->fetchAll(PDO::FETCH_ASSOC);
    $statusListStmt->closeCursor();

    // Ticket counts by status for this user
    $countStmt = $conn->prepare(
        // Deleted tickets must not be counted — the customer can't open them, so
        // including them makes the summary disagree with the list underneath it.
        "SELECT ts.name AS status, COUNT(*) as count
         FROM tickets t
         LEFT JOIN ticket_statuses ts ON ts.id = t.status_id
         WHERE t.user_id = ? AND t.deleted_datetime IS NULL
         GROUP BY ts.name"
    );
    $countStmt->execute([$userId]);
    $rows = $countStmt->fetchAll(PDO::FETCH_ASSOC);

    $countsByName = [];
    foreach ($rows as $row) {
        if ($row['status'] !== null) {
            $countsByName[$row['status']] = (int)$row['count'];
        }
    }

    // Build the summary payload: one entry per active status (with colour + is_closed
    // so the frontend can render any layout) plus a total
    $statusSummary = array_map(function ($s) use ($countsByName) {
        return [
            'name'      => $s['name'],
            'colour'    => $s['colour'],
            'is_closed' => (int)$s['is_closed'],
            'count'     => $countsByName[$s['name']] ?? 0,
        ];
    }, $activeStatuses);

    $totalCount = 0;
    foreach ($rows as $row) {
        $totalCount += (int)$row['count'];
    }

    $ticketSummary = [
        'total'    => $totalCount,
        'statuses' => $statusSummary,
    ];

    // Recent tickets (last 10) — include status colour so the frontend can render
    // the badge inline without a hardcoded class lookup
    $ticketStmt = $conn->prepare(
        "SELECT t.id, t.ticket_number, t.subject,
                ts.name AS status, ts.colour AS status_colour,
                tp.name AS priority,
                t.created_datetime, t.updated_datetime,
                d.name as department_name
         FROM tickets t
         LEFT JOIN ticket_statuses ts ON ts.id = t.status_id
         LEFT JOIN ticket_priorities tp ON tp.id = t.priority_id
         LEFT JOIN departments d ON t.department_id = d.id
         WHERE t.user_id = ? AND t.deleted_datetime IS NULL
         ORDER BY t.updated_datetime DESC
         LIMIT 10"
    );
    $ticketStmt->execute([$userId]);
    $recentTickets = $ticketStmt->fetchAll(PDO::FETCH_ASSOC);

    // Service status - active services with worst current impact (severity_order from lookup)
    $svcStmt = $conn->prepare(
        "SELECT ss.id, ss.name,
            COALESCE(
                (SELECT il.name
                 FROM status_incident_services sis
                 JOIN status_incidents si ON sis.incident_id = si.id
                 JOIN service_impact_levels il ON il.id = sis.impact_level_id
                 LEFT JOIN service_incident_statuses sst ON sst.id = si.status_id
                 WHERE sis.service_id = ss.id
                   AND (sst.is_resolved = 0 OR sst.id IS NULL)
                 ORDER BY il.severity_order ASC
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
