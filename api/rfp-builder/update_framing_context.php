<?php
/**
 * Save the optional "context note" the analyst supplies for an RFP —
 * the short paragraph explaining why the procurement is happening,
 * what the organisation is replacing, etc. Fed into the framing-
 * generation prompt so the introduction doesn't have to guess at
 * the business context.
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
    $rfpId = isset($data['rfp_id']) ? (int)$data['rfp_id'] : 0;
    $context = isset($data['context']) ? trim($data['context']) : '';
    if ($rfpId <= 0) throw new Exception('Missing or invalid rfp_id');

    $conn = connectToDatabase();

    $row = $conn->prepare("SELECT id FROM rfps WHERE id = ?");
    $row->execute([$rfpId]);
    if (!$row->fetch()) throw new Exception('RFP not found');

    $upd = $conn->prepare(
        "UPDATE rfps
            SET framing_context_text = ?,
                updated_datetime = CURRENT_TIMESTAMP
          WHERE id = ?"
    );
    $upd->execute([$context !== '' ? $context : null, $rfpId]);

    echo json_encode(['success' => true, 'rfp_id' => $rfpId]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
