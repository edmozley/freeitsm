<?php
/**
 * API: Delete a morning-check status.
 *
 * Hard delete — the morningChecks_Results table stores the label
 * string directly (not a FK to StatusID), so historical results
 * keep displaying the label even after the status row is gone.
 * Admins who want to keep a status as a record but stop offering it
 * on new checks can edit it and toggle IsActive instead.
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
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $statusId = isset($input['statusId']) ? (int)$input['statusId'] : 0;
    if ($statusId <= 0) throw new Exception('Status ID is required');

    $conn = connectToDatabase();
    $stmt = $conn->prepare("DELETE FROM morningChecks_Statuses WHERE StatusID = ?");
    $stmt->execute([$statusId]);

    echo json_encode(['success' => true, 'deleted' => $stmt->rowCount()]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
