<?php
/**
 * API: Create a relationship between two CMDB objects.
 * Thin UI adapter over CmdbService.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/services/cmdb.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

requireModuleAccessJson('cmdb');

try {
    $conn = connectToDatabase();
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    // The UI addresses the source object in the body; the service takes it as $fromId.
    $res = CmdbService::createRelationship($conn, ActorContext::fromSession($conn), (int)($data['from_object_id'] ?? 0), $data);
    echo json_encode(['success' => true, 'id' => $res['id']]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
