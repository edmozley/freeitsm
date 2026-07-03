<?php
/**
 * API Endpoint: Delete Morning Check
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

try {
    $input = json_decode(file_get_contents('php://input'), true);

    $checkId = $input['checkId'] ?? null;

    if (!$checkId) {
        throw new Exception('Check ID is required');
    }

    $conn = connectToDatabase();

    // Results-then-check, in a transaction — a mid-way failure must not
    // leave the check stripped of its history but still present.
    $conn->beginTransaction();

    $sql = "DELETE FROM morningChecks_Results WHERE CheckID = ?";
    $stmt = $conn->prepare($sql);
    $stmt->execute([(int)$checkId]);

    $sql = "DELETE FROM morningChecks_Checks WHERE CheckID = ?";
    $stmt = $conn->prepare($sql);
    $stmt->execute([(int)$checkId]);

    $conn->commit();

    echo json_encode(['success' => true]);

} catch (Exception $e) {
    if (isset($conn) && $conn->inTransaction()) $conn->rollBack();
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>
