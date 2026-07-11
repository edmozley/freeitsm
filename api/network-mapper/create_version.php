<?php
/**
 * API: Save the current diagram as a new version (clones nodes + connectors).
 * Thin UI adapter over NetworkMapperService.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/services/network_mapper.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

requireModuleAccessJson('network-mapper');

try {
    $conn = connectToDatabase();
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    // The UI addresses the parent in the body; the service takes it as $parentId.
    $newId = NetworkMapperService::createVersion($conn, ActorContext::fromSession($conn), (int)($data['parent_diagram_id'] ?? 0), $data);
    echo json_encode(['success' => true, 'id' => $newId]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
