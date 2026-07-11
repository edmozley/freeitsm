<?php
/**
 * API: Fork the current form into a new version (#442).
 * Thin UI adapter over FormsService.
 *
 * POST { parent_form_id } -> { id, version_number }.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/services/forms.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireModuleAccessJson('forms');

try {
    $conn = connectToDatabase();
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $parentId = isset($data['parent_form_id']) ? (int)$data['parent_form_id'] : 0;
    $res = FormsService::createVersion($conn, ActorContext::fromSession($conn), $parentId);
    echo json_encode([
        'success'        => true,
        'id'             => $res['id'],
        'version_number' => $res['version_number'],
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
