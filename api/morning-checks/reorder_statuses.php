<?php
/**
 * API: Reorder morning-check statuses.
 *
 * POST { order: [statusId, statusId, ...] } — positions become 10, 20,
 * 30, ... in array order. Done inside a transaction so a partial
 * failure rolls everything back.
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
    $order = isset($input['order']) && is_array($input['order']) ? $input['order'] : null;
    if ($order === null) throw new Exception('order array is required');

    $conn = connectToDatabase();
    $conn->beginTransaction();

    $upd = $conn->prepare("UPDATE morningChecks_Statuses SET SortOrder = ?, ModifiedDate = UTC_TIMESTAMP() WHERE StatusID = ?");
    foreach ($order as $i => $sid) {
        $upd->execute([($i + 1) * 10, (int)$sid]);
    }

    $conn->commit();
    echo json_encode(['success' => true]);

} catch (Exception $e) {
    if (isset($conn) && $conn->inTransaction()) $conn->rollBack();
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
