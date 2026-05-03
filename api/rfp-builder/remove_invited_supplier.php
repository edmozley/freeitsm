<?php
/**
 * Remove a supplier from an RFP's invitation list. Cascade-deletes
 * any scores already submitted for that (rfp, supplier) pair via the
 * FK on rfp_scores — there's no cascade to suppliers, so the supplier
 * record itself stays put for use on other RFPs.
 *
 * The schema doesn't actually cascade rfp_scores -> rfp_invited_suppliers
 * (rfp_scores has its own supplier_id FK to suppliers). So we manually
 * tidy up rfp_scores in the same transaction to keep the page state
 * coherent.
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
    $id = isset($data['id']) ? (int)$data['id'] : 0;
    if ($id <= 0) throw new Exception('Missing or invalid id');

    $conn = connectToDatabase();

    $row = $conn->prepare(
        "SELECT id, rfp_id, supplier_id FROM rfp_invited_suppliers WHERE id = ?"
    );
    $row->execute([$id]);
    $inv = $row->fetch(PDO::FETCH_ASSOC);
    if (!$inv) throw new Exception('Invitation not found');

    $conn->beginTransaction();
    try {
        // Wipe any scores submitted against this (rfp, supplier) pair —
        // they're meaningless once the supplier is no longer being
        // evaluated. Other suppliers' scores untouched.
        $conn->prepare("DELETE FROM rfp_scores WHERE rfp_id = ? AND supplier_id = ?")
             ->execute([(int)$inv['rfp_id'], (int)$inv['supplier_id']]);

        $conn->prepare("DELETE FROM rfp_invited_suppliers WHERE id = ?")
             ->execute([$id]);

        $conn->prepare("UPDATE rfps SET updated_datetime = CURRENT_TIMESTAMP WHERE id = ?")
             ->execute([(int)$inv['rfp_id']]);

        $conn->commit();
    } catch (Throwable $tx) {
        $conn->rollBack();
        throw $tx;
    }

    echo json_encode(['success' => true, 'id' => $id]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
