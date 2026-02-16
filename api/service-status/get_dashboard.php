<?php
/**
 * API: Get dashboard data for Service Status module
 * GET - Returns all active services with worst current impact + recent incidents
 */
session_start();
require_once '../../config.php';
require_once '../../includes/functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

try {
    $conn = connectToDatabase();

    // All active services with their worst current status from open incidents
    $svcSql = "SELECT ss.id, ss.name, ss.description, ss.display_order,
        COALESCE(
            (SELECT TOP 1 sis.impact_level
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
                     WHEN 'No Disruption' THEN 6
                 END ASC),
            'Operational'
        ) AS current_status
    FROM status_services ss
    WHERE ss.is_active = 1
    ORDER BY ss.display_order, ss.name";

    $svcStmt = $conn->prepare($svcSql);
    $svcStmt->execute();
    $services = $svcStmt->fetchAll(PDO::FETCH_ASSOC);

    // Recent incidents: active + resolved in last 30 days
    $incSql = "SELECT i.id, i.title, i.status, i.comment,
                      a.full_name AS created_by_name,
                      i.created_datetime, i.updated_datetime, i.resolved_datetime
               FROM status_incidents i
               LEFT JOIN analysts a ON i.created_by_id = a.id
               WHERE i.status != 'Resolved'
                  OR i.resolved_datetime >= DATEADD(DAY, -30, GETUTCDATE())
               ORDER BY
                   CASE WHEN i.status != 'Resolved' THEN 0 ELSE 1 END,
                   i.updated_datetime DESC";

    $incStmt = $conn->prepare($incSql);
    $incStmt->execute();
    $incidents = $incStmt->fetchAll(PDO::FETCH_ASSOC);

    // Get affected services for each incident
    $affStmt = $conn->prepare("SELECT sis.service_id, ss.name AS service_name, sis.impact_level
                                FROM status_incident_services sis
                                JOIN status_services ss ON sis.service_id = ss.id
                                WHERE sis.incident_id = ?
                                ORDER BY ss.display_order, ss.name");

    foreach ($incidents as &$inc) {
        $affStmt->execute([$inc['id']]);
        $inc['services'] = $affStmt->fetchAll(PDO::FETCH_ASSOC);
    }

    echo json_encode([
        'success' => true,
        'services' => $services,
        'incidents' => $incidents
    ]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
