<?php
/**
 * Invite an existing supplier to an RFP. The schema's UQ(rfp_id,
 * supplier_id) prevents duplicate invitations; we surface that as a
 * friendly error rather than a PDO exception.
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
    $rfpId      = isset($data['rfp_id'])      ? (int)$data['rfp_id'] : 0;
    $supplierId = isset($data['supplier_id']) ? (int)$data['supplier_id'] : 0;
    $demoDate   = isset($data['demo_date']) && trim($data['demo_date']) !== '' ? trim($data['demo_date']) : null;
    $notes      = isset($data['notes']) ? trim($data['notes']) : null;
    if ($notes === '') $notes = null;

    if ($rfpId <= 0 || $supplierId <= 0) {
        throw new Exception('Missing or invalid rfp_id / supplier_id');
    }

    $conn = connectToDatabase();

    $rfp = $conn->prepare("SELECT id FROM rfps WHERE id = ?");
    $rfp->execute([$rfpId]);
    if (!$rfp->fetch()) throw new Exception('RFP not found');

    $sup = $conn->prepare("SELECT id FROM suppliers WHERE id = ?");
    $sup->execute([$supplierId]);
    if (!$sup->fetch()) throw new Exception('Supplier not found');

    $dup = $conn->prepare("SELECT id FROM rfp_invited_suppliers WHERE rfp_id = ? AND supplier_id = ?");
    $dup->execute([$rfpId, $supplierId]);
    if ($dup->fetch()) throw new Exception('That supplier is already invited to this RFP');

    $ins = $conn->prepare(
        "INSERT INTO rfp_invited_suppliers (rfp_id, supplier_id, demo_date, notes)
         VALUES (?, ?, ?, ?)"
    );
    $ins->execute([$rfpId, $supplierId, $demoDate, $notes]);

    $conn->prepare("UPDATE rfps SET updated_datetime = CURRENT_TIMESTAMP WHERE id = ?")
         ->execute([$rfpId]);

    echo json_encode(['success' => true, 'id' => (int)$conn->lastInsertId()]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
