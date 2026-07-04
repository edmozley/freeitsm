<?php
/**
 * API: Delete a service.
 * POST - JSON body: { id }
 *
 * Thin UI adapter over ServiceStatusService.
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
    ServiceStatusService::deleteService($conn, ActorContext::fromSession($conn), (int)($data['id'] ?? 0));
    echo json_encode(['success' => true]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
