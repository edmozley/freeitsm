<?php
/**
 * API: Delete a form submission
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
$submissionId = (int)($input['id'] ?? 0);

if ($submissionId <= 0) {
    echo json_encode(['success' => false, 'error' => 'Missing submission ID']);
    exit;
}

try {
    $conn = connectToDatabase();

    // Data cascades via FK
    $stmt = $conn->prepare("DELETE FROM form_submissions WHERE id = ?");
    $stmt->execute([$submissionId]);

    echo json_encode(['success' => true, 'message' => 'Submission deleted']);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
