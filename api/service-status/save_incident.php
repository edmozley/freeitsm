<?php
/**
 * API: Save (create or update) an incident.
 * POST - JSON body: { id?, title, status, comment, services: [{ service_id, impact_level }] }
 *
 * Thin UI adapter over ServiceStatusService (shared with the REST API).
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/services/service_status.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireModuleAccessJson('service-status');

try {
    $conn = connectToDatabase();
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $id = ServiceStatusService::saveIncident($conn, ActorContext::fromSession($conn), $data);
    echo json_encode(['success' => true, 'id' => $id]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
