<?php
/**
 * API: Delete a morning-check status.
 *
 * Hard delete. The FK (fk_results_status) is ON DELETE SET NULL so
 * any existing results referencing this status get StatusID = NULL.
 * BEFORE deleting we snapshot the label into morningChecks_Results.Status
 * for any rows that reference this status — that turns them into
 * orphans that the dashboard banner + Settings normalisation tool
 * surface, instead of losing the label entirely.
 *
 * Admins who want to retain a status as a record but stop offering it
 * on new checks should toggle IsActive instead — historical results
 * keep working and the option just disappears from the dashboard.
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

    // Snapshot the label into the Status column for affected results
    // so the dashboard/normalisation tool can show what the orphan
    // used to be. Done inside a transaction with the delete itself so
    // a partial failure rolls back.
    $conn->beginTransaction();

    $labelStmt = $conn->prepare("SELECT Label FROM morningChecks_Statuses WHERE StatusID = ?");
    $labelStmt->execute([$statusId]);
    $label = $labelStmt->fetchColumn();

    $orphaned = 0;
    if ($label !== false && $label !== null) {
        $snap = $conn->prepare("UPDATE morningChecks_Results SET Status = ? WHERE StatusID = ?");
        $snap->execute([$label, $statusId]);
        $orphaned = $snap->rowCount();
    }

    $del = $conn->prepare("DELETE FROM morningChecks_Statuses WHERE StatusID = ?");
    $del->execute([$statusId]);

    $conn->commit();
    echo json_encode(['success' => true, 'deleted' => $del->rowCount(), 'orphaned' => $orphaned]);

} catch (Exception $e) {
    if (isset($conn) && $conn->inTransaction()) $conn->rollBack();
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
