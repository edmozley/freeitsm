<?php
/**
 * API: Self-Service Dashboard Data
 * GET - Returns ticket summary, recent tickets, and service status for the logged-in user
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/service_impact_levels.php';
require_once '../../includes/service_status_portal.php';

header('Content-Type: application/json');

if (!isset($_SESSION['ss_user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$userId = (int)$_SESSION['ss_user_id'];

try {
    $conn = connectToDatabase();
    require_once __DIR__ . '/../../includes/service_status_planned.php';
    statusPlannedDue($conn);   // a start or end due now is seen now, cron or not

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

    // Service status - active services with worst current impact (severity_order from lookup).
    // No open incident => the configured default impact level and its colour, not a
    // hardcoded 'Operational' (GH #70). Mirrors api/service-status/get_dashboard.php.
    $defaultImpact = defaultImpactLevel($conn);

    $svcStmt = $conn->prepare(
        "SELECT ss.id, ss.name,
            COALESCE(il.name, :def_name)     AS current_status,
            COALESCE(il.colour, :def_colour) AS current_status_colour
        FROM status_services ss
        LEFT JOIN service_impact_levels il ON il.id = (
            SELECT sis.impact_level_id
            FROM status_incident_services sis
            JOIN status_incidents si ON sis.incident_id = si.id
            JOIN service_impact_levels worst ON worst.id = sis.impact_level_id
            LEFT JOIN service_incident_statuses sst ON sst.id = si.status_id
            WHERE sis.service_id = ss.id
              AND (sst.is_resolved = 0 OR sst.id IS NULL)
            ORDER BY worst.severity_order ASC
            LIMIT 1
        )
        WHERE ss.is_active = 1
        ORDER BY ss.display_order, ss.name"
    );
    $svcStmt->execute([':def_name' => $defaultImpact['name'], ':def_colour' => $defaultImpact['colour']]);
    $services = $svcStmt->fetchAll(PDO::FETCH_ASSOC);

    // Catalogue requests the user has submitted that carry an approval state (#928).
    // A pending request is NOT a ticket yet, so without this the requester has no way
    // to see it — it would just vanish until (and unless) it is approved. Guarded so a
    // pre-upgrade install without the approval columns degrades to "no requests"
    // rather than a broken dashboard. Only gated submissions have a state to show.
    $requests = [];
    try {
        $reqStmt = $conn->prepare(
            "SELECT s.id, s.approval_status, s.submitted_date, s.ticket_id,
                    f.title AS form_title, t.ticket_number
               FROM form_submissions s
               JOIN forms f       ON f.id = s.form_id
               LEFT JOIN tickets t ON t.id = s.ticket_id AND t.deleted_datetime IS NULL
              WHERE s.submitted_by_user_id = ?
                AND s.approval_status IN ('pending','approved','rejected')
           ORDER BY s.submitted_date DESC
              LIMIT 15"
        );
        $reqStmt->execute([$userId]);
        $requests = $reqStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $requests = [];
    }

    echo json_encode([
        'success' => true,
        'ticket_summary' => $ticketSummary,
        'recent_tickets' => $recentTickets,
        'requests' => $requests,
        'services' => $services,
        // Incidents behind an outage, and how many external updates each has
        // (#99). ssPortalIncidents() returns [] unless an administrator has
        // switched this on, so the portal is unchanged until they do — the
        // check lives in there rather than here, because it must hold for
        // every caller rather than for the ones that remember.
        'incidents' => ssPortalIncidents($conn),
        // Planned maintenance not yet started (3.2.0) - behind the same switch as
        // the incidents, so the portal is unchanged until an administrator opts in.
        'planned' => ssPortalUpdatesEnabled($conn) ? array_map(fn($p) => [
            'title' => $p['title'], 'comment' => $p['comment'], 'start' => $p['start'], 'end' => $p['end'],
            'services' => array_map(fn($s) => ['name' => $s['name'], 'impact' => $s['impact'], 'colour' => $s['colour']], $p['services']),
        ], statusPlannedList($conn, ['states' => ['scheduled'], 'limit' => 10])) : [],
        // Which level counts as "all clear" — the portal's "All systems operational"
        // banner tests against this instead of the literal name (GH #70).
        'default_impact' => $defaultImpact
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
