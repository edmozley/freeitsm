<?php
/**
 * Create a new "prospective" supplier inline from the RFP suppliers
 * page and immediately invite them to the current RFP. Used when the
 * supplier the analyst wants to evaluate isn't yet in FreeITSM's
 * suppliers list.
 *
 * The supplier is created with just legal_name (and optional trading_name
 * + comments) — analyst can flesh out the record from the suppliers
 * module later if the procurement progresses.
 *
 * Returns the new supplier_id and the resulting invitation_id.
 */
session_start();
require_once '../../config.php';
require_once '../../includes/functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

try {
    $data = json_decode(file_get_contents('php://input'), true);
    $rfpId       = isset($data['rfp_id']) ? (int)$data['rfp_id'] : 0;
    $legalName   = isset($data['legal_name'])   ? trim($data['legal_name'])   : '';
    $tradingName = isset($data['trading_name']) ? trim($data['trading_name']) : '';
    $comments    = isset($data['comments'])     ? trim($data['comments'])     : '';
    $demoDate    = isset($data['demo_date']) && trim($data['demo_date']) !== '' ? trim($data['demo_date']) : null;
    $notes       = isset($data['notes']) ? trim($data['notes']) : null;
    if ($notes === '') $notes = null;

    if ($rfpId <= 0) throw new Exception('Missing or invalid rfp_id');
    if ($legalName === '') throw new Exception('Supplier name is required');

    $conn = connectToDatabase();

    $rfp = $conn->prepare("SELECT id FROM rfps WHERE id = ?");
    $rfp->execute([$rfpId]);
    if (!$rfp->fetch()) throw new Exception('RFP not found');

    // Look up the "Prospective" status if it exists; otherwise leave
    // status null. Don't auto-create the status — managing the
    // supplier_statuses lookup belongs to the suppliers module.
    $statusStmt = $conn->prepare(
        "SELECT id FROM supplier_statuses WHERE LOWER(name) = 'prospective' AND is_active = 1 LIMIT 1"
    );
    $statusStmt->execute();
    $statusId = $statusStmt->fetchColumn();
    if ($statusId === false) $statusId = null;

    $conn->beginTransaction();
    try {
        $ins = $conn->prepare(
            "INSERT INTO suppliers (legal_name, trading_name, supplier_status_id, comments, is_active)
             VALUES (?, ?, ?, ?, 1)"
        );
        $ins->execute([
            $legalName,
            $tradingName !== '' ? $tradingName : null,
            $statusId,
            $comments !== '' ? $comments : null,
        ]);
        $supplierId = (int)$conn->lastInsertId();

        $invIns = $conn->prepare(
            "INSERT INTO rfp_invited_suppliers (rfp_id, supplier_id, demo_date, notes)
             VALUES (?, ?, ?, ?)"
        );
        $invIns->execute([$rfpId, $supplierId, $demoDate, $notes]);
        $invitationId = (int)$conn->lastInsertId();

        $conn->prepare("UPDATE rfps SET updated_datetime = CURRENT_TIMESTAMP WHERE id = ?")
             ->execute([$rfpId]);

        $conn->commit();
    } catch (Throwable $tx) {
        $conn->rollBack();
        throw $tx;
    }

    echo json_encode([
        'success'       => true,
        'supplier_id'   => $supplierId,
        'invitation_id' => $invitationId,
    ]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
