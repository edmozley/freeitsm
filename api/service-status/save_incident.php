<?php
/**
 * API: Save (create or update) an incident
 * POST - JSON body: { id?, title, status, comment, services: [{ service_id, impact_level }] }
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
    $data = json_decode(file_get_contents('php://input'), true);

    $id = $data['id'] ?? null;
    $title = trim($data['title'] ?? '');
    $status = trim($data['status'] ?? 'Investigating');
    $comment = trim($data['comment'] ?? '');
    $services = $data['services'] ?? [];

    if (empty($title)) {
        throw new Exception('Title is required');
    }

    $conn = connectToDatabase();

    // Resolve incoming status name -> (id, is_resolved)
    $stsStmt = $conn->prepare("SELECT id, is_resolved FROM service_incident_statuses WHERE name = ? AND is_active = 1 LIMIT 1");
    $stsStmt->execute([$status]);
    $stsRow = $stsStmt->fetch(PDO::FETCH_ASSOC);
    if (!$stsRow) {
        throw new Exception('Invalid status: ' . $status);
    }
    $statusId   = (int)$stsRow['id'];
    $isResolved = (bool)$stsRow['is_resolved'];

    if ($id) {
        // Get current resolved-state to drive resolved_datetime transitions
        $curStmt = $conn->prepare(
            "SELECT s.is_resolved
             FROM status_incidents i
             LEFT JOIN service_incident_statuses s ON s.id = i.status_id
             WHERE i.id = ?"
        );
        $curStmt->execute([$id]);
        $wasResolved = (bool)($curStmt->fetchColumn() ?: 0);

        if ($isResolved) {
            $sql = "UPDATE status_incidents SET title = ?, status_id = ?, comment = ?, updated_datetime = UTC_TIMESTAMP(), resolved_datetime = COALESCE(resolved_datetime, UTC_TIMESTAMP()) WHERE id = ?";
            $conn->prepare($sql)->execute([$title, $statusId, $comment, $id]);
        } else {
            $sql = "UPDATE status_incidents SET title = ?, status_id = ?, comment = ?, updated_datetime = UTC_TIMESTAMP(), resolved_datetime = NULL WHERE id = ?";
            $conn->prepare($sql)->execute([$title, $statusId, $comment, $id]);
        }
    } else {
        // Insert new incident
        if ($isResolved) {
            $sql = "INSERT INTO status_incidents (title, status_id, comment, created_by_id, resolved_datetime)
                    VALUES (?, ?, ?, ?, UTC_TIMESTAMP())";
        } else {
            $sql = "INSERT INTO status_incidents (title, status_id, comment, created_by_id)
                    VALUES (?, ?, ?, ?)";
        }
        $stmt = $conn->prepare($sql);
        $stmt->execute([$title, $statusId, $comment, $_SESSION['analyst_id']]);
        $id = $conn->lastInsertId();
    }

    // Re-insert affected services. Resolve impact_level name -> id per row.
    $conn->prepare("DELETE FROM status_incident_services WHERE incident_id = ?")->execute([$id]);

    if (!empty($services)) {
        $impactLookup = $conn->prepare("SELECT id FROM service_impact_levels WHERE name = ? AND is_active = 1 LIMIT 1");
        $insStmt = $conn->prepare("INSERT INTO status_incident_services (incident_id, service_id, impact_level_id) VALUES (?, ?, ?)");
        foreach ($services as $svc) {
            $svcId = (int)($svc['service_id'] ?? 0);
            $impactName = $svc['impact_level'] ?? 'Operational';
            $impactLookup->execute([$impactName]);
            $impactId = $impactLookup->fetchColumn();
            if ($svcId > 0 && $impactId) {
                $insStmt->execute([$id, $svcId, (int)$impactId]);
            }
        }
    }

    echo json_encode(['success' => true, 'id' => $id]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
