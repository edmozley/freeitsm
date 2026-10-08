<?php
/**
 * API: Get dashboard data for Service Status module
 * GET - Returns all active services with worst current impact + recent incidents
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/service_impact_levels.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireModuleAccessJson('service-status');

try {
    $conn = connectToDatabase();
    // Planned maintenance due to start or end becomes (or stops being) an
    // incident BEFORE the board is read, so it is on time even with no cron.
    require_once '../../includes/service_status_planned.php';
    statusPlannedDue($conn);

    // All active services with their worst current status from open incidents.
    // Severity ranking comes from service_impact_levels.severity_order (1 = worst).
    // No open incident => the configured default impact level, NOT a hardcoded
    // "Operational" (GH #70 — renaming the level left half the board on the old
    // name). The colour is returned with it so a renamed OR deactivated level
    // still paints its badge.
    $default = defaultImpactLevel($conn);

    $svcSql = "SELECT ss.id, ss.name, ss.description, ss.display_order,
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
    ORDER BY ss.display_order, ss.name";

    $svcStmt = $conn->prepare($svcSql);
    $svcStmt->execute([':def_name' => $default['name'], ':def_colour' => $default['colour']]);
    $services = $svcStmt->fetchAll(PDO::FETCH_ASSOC);

    // Recent incidents: active + resolved in last 30 days
    $incSql = "SELECT i.id, i.title,
                      sst.name AS status, sst.is_resolved AS status_is_resolved, sst.colour AS status_colour,
                      i.comment,
                      a.full_name AS created_by_name,
                      i.created_datetime, i.updated_datetime, i.resolved_datetime
               FROM status_incidents i
               LEFT JOIN service_incident_statuses sst ON sst.id = i.status_id
               LEFT JOIN analysts a ON i.created_by_id = a.id
               WHERE (sst.is_resolved = 0 OR sst.id IS NULL)
                  OR i.resolved_datetime >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 30 DAY)
               ORDER BY
                   CASE WHEN (sst.is_resolved = 0 OR sst.id IS NULL) THEN 0 ELSE 1 END,
                   i.updated_datetime DESC";

    $incStmt = $conn->prepare($incSql);
    $incStmt->execute();
    $incidents = $incStmt->fetchAll(PDO::FETCH_ASSOC);

    // Get affected services for each incident
    $affStmt = $conn->prepare("SELECT sis.service_id, ss.name AS service_name,
                                       il.name AS impact_level, il.colour AS impact_colour
                                FROM status_incident_services sis
                                JOIN status_services ss ON sis.service_id = ss.id
                                LEFT JOIN service_impact_levels il ON il.id = sis.impact_level_id
                                WHERE sis.incident_id = ?
                                ORDER BY ss.display_order, ss.name");

    foreach ($incidents as &$inc) {
        $affStmt->execute([$inc['id']]);
        $inc['services'] = $affStmt->fetchAll(PDO::FETCH_ASSOC);
    }

    echo json_encode([
        'success' => true,
        'services' => $services,
        'incidents' => $incidents,
        // Planned maintenance not yet started, and in progress (3.2.0).
        'planned' => statusPlannedList($conn),
        // Which level counts as "all clear" — the board uses it to decide whether
        // a service is healthy without matching on a name that can be renamed.
        'default_impact' => $default
    ]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
