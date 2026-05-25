<?php
/**
 * API Endpoint: Save Morning Check Result
 *
 * Normalised: takes a numeric statusId, looks up the row, writes the
 * FK and NULLs the legacy Status snapshot. The snapshot column is now
 * only populated for orphan/historical rows (managed via
 * normalise_status.php and delete_status.php).
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

    $checkId   = $input['checkId'] ?? null;
    $statusId  = isset($input['statusId']) ? (int)$input['statusId'] : 0;
    $notes     = isset($input['notes']) ? $input['notes'] : '';
    $checkDate = $input['checkDate'] ?? date('Y-m-d');

    if (!$checkId || !$statusId) {
        throw new Exception('Missing required fields: checkId and statusId');
    }

    // Validate the date format
    $dateObj = DateTime::createFromFormat('Y-m-d', $checkDate);
    if (!$dateObj || $dateObj->format('Y-m-d') !== $checkDate) {
        $checkDate = date('Y-m-d');
    }

    $conn = connectToDatabase();

    // Look up the chosen status — must exist and be active. The
    // RequiresNotes flag drives whether notes are mandatory.
    $sStmt = $conn->prepare(
        "SELECT StatusID, Label, RequiresNotes FROM morningChecks_Statuses
         WHERE StatusID = ? AND IsActive = 1 LIMIT 1"
    );
    $sStmt->execute([$statusId]);
    $statusRow = $sStmt->fetch(PDO::FETCH_ASSOC);
    if (!$statusRow) {
        throw new Exception('Invalid or inactive status');
    }
    if ((int)$statusRow['RequiresNotes'] === 1 && trim((string)$notes) === '') {
        throw new Exception('Notes are required for ' . $statusRow['Label'] . ' status');
    }

    // Check if a result already exists for this check + date.
    $sql = "SELECT ResultID FROM morningChecks_Results WHERE CheckID = ? AND CheckDate = ?";
    $stmt = $conn->prepare($sql);
    $stmt->execute([(int)$checkId, $checkDate]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        // Update — set StatusID, clear the snapshot (StatusID is the
        // source of truth now), update notes/modified date.
        $sql = "UPDATE morningChecks_Results
                SET StatusID = ?, Status = NULL, Notes = ?, ModifiedDate = UTC_TIMESTAMP()
                WHERE CheckID = ? AND CheckDate = ?";
        $stmt = $conn->prepare($sql);
        $stmt->execute([(int)$statusRow['StatusID'], $notes, (int)$checkId, $checkDate]);
    } else {
        $sql = "INSERT INTO morningChecks_Results
                    (CheckID, CheckDate, StatusID, Status, Notes, CreatedDate, ModifiedDate)
                VALUES (?, ?, ?, NULL, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())";
        $stmt = $conn->prepare($sql);
        $stmt->execute([(int)$checkId, $checkDate, (int)$statusRow['StatusID'], $notes]);
    }

    echo json_encode(['success' => true]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
