<?php
/**
 * API: Map a batch of orphan label strings to a target StatusID.
 *
 * POST body:
 *   {
 *     mappings: [
 *       { label: "Yellow",  statusId: 2 },
 *       { label: "OK",      statusId: 1 },
 *       ...
 *     ]
 *   }
 *
 * For each mapping, every orphan row (StatusID IS NULL) whose Status
 * column equals the given label is updated to point at the target
 * StatusID. The Status snapshot column is also cleared on those rows
 * so they look identical to normally-written rows from that point on.
 *
 * Done inside a transaction so a partial failure rolls everything
 * back. Returns the total rows updated.
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
    $mappings = isset($input['mappings']) && is_array($input['mappings']) ? $input['mappings'] : null;
    if ($mappings === null) throw new Exception('mappings array is required');

    $conn = connectToDatabase();
    $conn->beginTransaction();

    // Pre-validate target IDs — every referenced StatusID must exist
    // and be active. Cheaper to fail fast than to roll back later.
    $valid = $conn->query("SELECT StatusID FROM morningChecks_Statuses WHERE IsActive = 1")
                  ->fetchAll(PDO::FETCH_COLUMN);
    $validSet = array_flip(array_map('intval', $valid));

    $upd = $conn->prepare(
        "UPDATE morningChecks_Results
         SET StatusID = ?, Status = NULL, ModifiedDate = UTC_TIMESTAMP()
         WHERE StatusID IS NULL AND Status = ?"
    );

    $totalUpdated = 0;
    foreach ($mappings as $m) {
        $label = isset($m['label']) ? (string)$m['label'] : '';
        $sid   = isset($m['statusId']) ? (int)$m['statusId'] : 0;
        if ($label === '' || $sid <= 0) continue;
        if (!isset($validSet[$sid])) {
            throw new Exception('Invalid target StatusID ' . $sid . ' for label "' . $label . '"');
        }
        $upd->execute([$sid, $label]);
        $totalUpdated += $upd->rowCount();
    }

    $conn->commit();
    echo json_encode(['success' => true, 'updated' => $totalUpdated]);

} catch (Exception $e) {
    if (isset($conn) && $conn->inTransaction()) $conn->rollBack();
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
