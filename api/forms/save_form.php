<?php
/**
 * API: Create or update a form with its fields.
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

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    echo json_encode(['success' => false, 'error' => 'Invalid JSON']);
    exit;
}

try {
    $conn = connectToDatabase();
    // The UI already sends canonical keys (id, title, description, fields).
    $res = FormsService::saveForm($conn, ActorContext::fromSession($conn), $input);
    echo json_encode(['success' => true, 'form_id' => $res['id'], 'message' => 'Form saved']);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
