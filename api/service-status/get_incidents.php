<?php
/**
 * API: Get incidents for the Service Status module
 * GET - Returns incidents with affected services. Optional ?filter=active for non-resolved only.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

try {
    $conn = connectToDatabase();
    $filter = $_GET['filter'] ?? '';

    $sql = "SELECT i.id, i.title,
                   sis.name AS status, sis.is_resolved AS status_is_resolved, sis.colour AS status_colour,
                   i.comment, i.created_by_id,
                   a.full_name AS created_by_name,
                   i.created_datetime, i.updated_datetime, i.resolved_datetime
            FROM status_incidents i
            LEFT JOIN service_incident_statuses sis ON sis.id = i.status_id
            LEFT JOIN analysts a ON i.created_by_id = a.id";

    if ($filter === 'active') {
        $sql .= " WHERE (sis.is_resolved = 0 OR sis.id IS NULL)";
    }

    $sql .= " ORDER BY
                CASE WHEN (sis.is_resolved = 0 OR sis.id IS NULL) THEN 0 ELSE 1 END,
                i.updated_datetime DESC";

    $stmt = $conn->prepare($sql);
    $stmt->execute();
    $incidents = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get affected services for each incident
    $svcStmt = $conn->prepare("SELECT sis.service_id, ss.name AS service_name,
                                       il.name AS impact_level, il.colour AS impact_colour, il.severity_order
                                FROM status_incident_services sis
                                JOIN status_services ss ON sis.service_id = ss.id
                                LEFT JOIN service_impact_levels il ON il.id = sis.impact_level_id
                                WHERE sis.incident_id = ?
                                ORDER BY ss.display_order, ss.name");

    foreach ($incidents as &$inc) {
        $svcStmt->execute([$inc['id']]);
        $inc['services'] = $svcStmt->fetchAll(PDO::FETCH_ASSOC);
    }

    echo json_encode(['success' => true, 'incidents' => $incidents]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
