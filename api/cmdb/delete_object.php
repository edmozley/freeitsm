<?php
/**
 * API: Delete a CMDB object (and its descendant tree).
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
    $res = CmdbService::deleteObject($conn, ActorContext::fromSession($conn), (int)($data['id'] ?? 0));
    echo json_encode(['success' => true, 'deleted_descendants' => $res['deleted_descendants']]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
