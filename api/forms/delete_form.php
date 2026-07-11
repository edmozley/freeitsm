<?php
/**
 * API: Delete a form (leaf-only; whole-chain deletes are an API-only option).
 * Thin UI adapter over FormsService.
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
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    FormsService::deleteForm($conn, ActorContext::fromSession($conn), (int)($input['id'] ?? 0), false);
    echo json_encode(['success' => true, 'message' => 'Form deleted']);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
