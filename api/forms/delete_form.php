<?php
/**
 * API: Delete a form (cascades to fields, submissions cascade separately)
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$formId = (int)($input['id'] ?? 0);

if ($formId <= 0) {
    echo json_encode(['success' => false, 'error' => 'Missing form ID']);
    exit;
}

try {
    $conn = connectToDatabase();

    // Delete submission data first (FK constraint)
    $stmt = $conn->prepare("DELETE sd FROM form_submission_data sd
                            INNER JOIN form_submissions s ON sd.submission_id = s.id
                            WHERE s.form_id = ?");
    $stmt->execute([$formId]);

    // Delete submissions
    $stmt = $conn->prepare("DELETE FROM form_submissions WHERE form_id = ?");
    $stmt->execute([$formId]);

    // Delete form (fields cascade)
    $stmt = $conn->prepare("DELETE FROM forms WHERE id = ?");
    $stmt->execute([$formId]);

    echo json_encode(['success' => true, 'message' => 'Form deleted']);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
