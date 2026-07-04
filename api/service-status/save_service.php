<?php
/**
 * API: Save (create or update) a service.
 * POST - JSON body: { id?, name, description, display_order, is_active }
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

try {
    $conn = connectToDatabase();
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    ServiceStatusService::saveService($conn, ActorContext::fromSession($conn), $data);
    echo json_encode(['success' => true]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
