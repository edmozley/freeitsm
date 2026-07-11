<?php
/**
 * API: Submit a filled-in form.
 * Thin UI adapter over FormsService (which fires the form.submitted workflow).
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
    $formId = (int)($input['form_id'] ?? 0);
    $data = (isset($input['data']) && is_array($input['data'])) ? $input['data'] : [];
    $submissionId = FormsService::submitForm($conn, ActorContext::fromSession($conn), $formId, $data);
    echo json_encode(['success' => true, 'submission_id' => $submissionId, 'message' => 'Form submitted']);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
