<?php
/**
 * Update an existing invitation row — demo date and notes. The
 * supplier itself isn't editable here (that's the suppliers module);
 * this endpoint covers per-RFP fields only.
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
    $id       = isset($data['id']) ? (int)$data['id'] : 0;
    $demoDate = isset($data['demo_date']) && trim($data['demo_date']) !== '' ? trim($data['demo_date']) : null;
    $notes    = isset($data['notes']) ? trim($data['notes']) : null;
    if ($notes === '') $notes = null;
    if ($id <= 0) throw new Exception('Missing or invalid id');

    $conn = connectToDatabase();

    $row = $conn->prepare("SELECT rfp_id FROM rfp_invited_suppliers WHERE id = ?");
    $row->execute([$id]);
    $existing = $row->fetch(PDO::FETCH_ASSOC);
    if (!$existing) throw new Exception('Invitation not found');

    $upd = $conn->prepare(
        "UPDATE rfp_invited_suppliers SET demo_date = ?, notes = ? WHERE id = ?"
    );
    $upd->execute([$demoDate, $notes, $id]);

    $conn->prepare("UPDATE rfps SET updated_datetime = CURRENT_TIMESTAMP WHERE id = ?")
         ->execute([(int)$existing['rfp_id']]);

    echo json_encode(['success' => true, 'id' => $id]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
