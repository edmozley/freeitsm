<?php
/**
 * API: Create or update a CMDB object.
 * Thin UI adapter over CmdbService. The UI sends property_values as an
 * id-addressed list ([{property_id, value}]) — the service normalises that.
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
    $res = CmdbService::saveObject($conn, ActorContext::fromSession($conn), $data);
    echo json_encode(['success' => true, 'id' => $res['id']]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
