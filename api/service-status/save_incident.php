<?php
/**
 * API: Save (create or update) an incident
 * POST - JSON body: { id?, title, status, comment, services: [{ service_id, impact_level }] }
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
    $data = json_decode(file_get_contents('php://input'), true);

    $id = $data['id'] ?? null;
    $title = trim($data['title'] ?? '');
    $status = trim($data['status'] ?? 'Investigating');
    $comment = trim($data['comment'] ?? '');
    $services = $data['services'] ?? [];

    if (empty($title)) {
        throw new Exception('Title is required');
    }

    $validStatuses = ['3rd Party', 'Identified', 'Investigating', 'Monitoring', 'Resolved'];
    if (!in_array($status, $validStatuses)) {
        throw new Exception('Invalid status');
    }

    $validImpacts = ['Major Outage', 'Partial Outage', 'Degraded', 'Maintenance', 'Operational', 'No Disruption'];

    $conn = connectToDatabase();

    if ($id) {
        // Get current status to detect resolution
        $curStmt = $conn->prepare("SELECT status FROM status_incidents WHERE id = ?");
        $curStmt->execute([$id]);
        $current = $curStmt->fetch(PDO::FETCH_ASSOC);

        $resolvedDatetime = null;
        if ($status === 'Resolved' && (!$current || $current['status'] !== 'Resolved')) {
            $resolvedDatetime = 'GETUTCDATE()';
        }

        if ($status === 'Resolved') {
            $sql = "UPDATE status_incidents SET title = ?, status = ?, comment = ?, updated_datetime = GETUTCDATE(), resolved_datetime = COALESCE(resolved_datetime, GETUTCDATE()) WHERE id = ?";
            $conn->prepare($sql)->execute([$title, $status, $comment, $id]);
        } else {
            $sql = "UPDATE status_incidents SET title = ?, status = ?, comment = ?, updated_datetime = GETUTCDATE(), resolved_datetime = NULL WHERE id = ?";
            $conn->prepare($sql)->execute([$title, $status, $comment, $id]);
        }
    } else {
        // Insert new incident
        if ($status === 'Resolved') {
            $sql = "INSERT INTO status_incidents (title, status, comment, created_by_id, resolved_datetime) VALUES (?, ?, ?, ?, GETUTCDATE())";
        } else {
            $sql = "INSERT INTO status_incidents (title, status, comment, created_by_id) VALUES (?, ?, ?, ?)";
        }
        $conn->prepare($sql)->execute([$title, $status, $comment, $_SESSION['analyst_id']]);

        // Get the new ID
        $id = $conn->lastInsertId();
    }

    // Re-insert affected services
    $conn->prepare("DELETE FROM status_incident_services WHERE incident_id = ?")->execute([$id]);

    if (!empty($services)) {
        $insStmt = $conn->prepare("INSERT INTO status_incident_services (incident_id, service_id, impact_level) VALUES (?, ?, ?)");
        foreach ($services as $svc) {
            $svcId = (int)($svc['service_id'] ?? 0);
            $impact = $svc['impact_level'] ?? 'Operational';
            if ($svcId > 0 && in_array($impact, $validImpacts)) {
                $insStmt->execute([$id, $svcId, $impact]);
            }
        }
    }

    echo json_encode(['success' => true, 'id' => $id]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
