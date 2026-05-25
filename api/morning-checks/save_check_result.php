<?php
/**
 * API Endpoint: Save Morning Check Result
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
    $status = $input['status'] ?? null;
    $notes = $input['notes'] ?? '';
    $checkDate = $input['checkDate'] ?? date('Y-m-d');

    if (!$checkId || !$status) {
        throw new Exception('Missing required fields: checkId and status');
    }

    if (!in_array($status, ['Red', 'Amber', 'Green'])) {
        throw new Exception('Invalid status. Must be Red, Amber, or Green');
    }

    if (($status === 'Red' || $status === 'Amber') && empty(trim($notes))) {
        throw new Exception('Notes are required for Red or Amber status');
    }

    // Validate date format
    $dateObj = DateTime::createFromFormat('Y-m-d', $checkDate);
    if (!$dateObj || $dateObj->format('Y-m-d') !== $checkDate) {
        $checkDate = date('Y-m-d');
    }

    $conn = connectToDatabase();

    // Check if result already exists for the selected date
    $sql = "SELECT ResultID FROM morningChecks_Results WHERE CheckID = ? AND CheckDate = ?";
    $stmt = $conn->prepare($sql);
    $stmt->execute([(int)$checkId, $checkDate]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        // Update existing result
        $sql = "UPDATE morningChecks_Results SET Status = ?, Notes = ?, ModifiedDate = UTC_TIMESTAMP()
                WHERE CheckID = ? AND CheckDate = ?";
        $stmt = $conn->prepare($sql);
        $stmt->execute([$status, $notes, (int)$checkId, $checkDate]);
    } else {
        // Insert new result
        $sql = "INSERT INTO morningChecks_Results (CheckID, CheckDate, Status, Notes, CreatedDate, ModifiedDate)
                VALUES (?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())";
        $stmt = $conn->prepare($sql);
        $stmt->execute([(int)$checkId, $checkDate, $status, $notes]);
    }

    echo json_encode(['success' => true]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>
