<?php
/** Thin UI adapter over ContractsService — create or update a contract. */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/services/contracts.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireModuleAccessJson('contracts');

try {
    $conn = connectToDatabase();
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    // The UI already sends canonical snake_case keys.
    $res = ContractsService::saveContract($conn, ActorContext::fromSession($conn), $data);
    echo json_encode(['success' => true, 'id' => $res['id']]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
