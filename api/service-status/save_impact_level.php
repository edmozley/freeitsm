<?php
/**
 * API Endpoint: Save service impact level (create or update).
 *
 * severity_order drives "worst current impact" ordering on dashboards
 * (1 = worst e.g. Major Outage, higher = less severe). Two rows can share the
 * same severity_order; ties break on the lookup id.
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
requireModuleAccessJson('service-status');

try {
    $conn = connectToDatabase();
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    ServiceStatusService::saveImpactLevel($conn, ActorContext::fromSession($conn), $data);
    echo json_encode(['success' => true]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
