<?php
/**
 * Upsert one analyst's score for a (rfp, supplier, requirement). Score
 * can be NULL (the analyst explicitly hasn't scored this requirement
 * yet, or chose N/A) or 0-5. Notes are an optional free-text field.
 *
 * The schema's UQ(rfp_id, supplier_id, analyst_id, consolidated_id)
 * means there's exactly one row per (analyst, requirement) pair —
 * this endpoint keeps it that way via INSERT...ON DUPLICATE KEY UPDATE.
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
    $rfpId          = isset($data['rfp_id'])          ? (int)$data['rfp_id'] : 0;
    $supplierId     = isset($data['supplier_id'])     ? (int)$data['supplier_id'] : 0;
    $consolidatedId = isset($data['consolidated_id']) ? (int)$data['consolidated_id'] : 0;
    if ($rfpId <= 0 || $supplierId <= 0 || $consolidatedId <= 0) {
        throw new Exception('Missing or invalid rfp_id / supplier_id / consolidated_id');
    }
    $analystId = (int)$_SESSION['analyst_id'];

    // Score: null/N-A or integer 0..5. Anything else is rejected.
    $score = $data['score'] ?? null;
    if ($score !== null && $score !== '') {
        $score = (int)$score;
        if ($score < 0 || $score > 5) throw new Exception('Score must be between 0 and 5');
    } else {
        $score = null;
    }

    $notes = isset($data['notes']) ? trim($data['notes']) : null;
    if ($notes === '') $notes = null;

    $conn = connectToDatabase();

    // Sanity: requirement must belong to this RFP, supplier must be invited
    $reqCheck = $conn->prepare(
        "SELECT id FROM rfp_consolidated_requirements WHERE id = ? AND rfp_id = ?"
    );
    $reqCheck->execute([$consolidatedId, $rfpId]);
    if (!$reqCheck->fetch()) throw new Exception('Requirement not in this RFP');

    $invCheck = $conn->prepare(
        "SELECT id FROM rfp_invited_suppliers WHERE rfp_id = ? AND supplier_id = ?"
    );
    $invCheck->execute([$rfpId, $supplierId]);
    if (!$invCheck->fetch()) throw new Exception('Supplier is not on this RFP');

    $upsert = $conn->prepare(
        "INSERT INTO rfp_scores
            (rfp_id, supplier_id, analyst_id, consolidated_id, score, notes)
         VALUES (?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            score = VALUES(score),
            notes = VALUES(notes),
            updated_datetime = CURRENT_TIMESTAMP"
    );
    $upsert->execute([$rfpId, $supplierId, $analystId, $consolidatedId, $score, $notes]);

    $conn->prepare("UPDATE rfps SET updated_datetime = CURRENT_TIMESTAMP WHERE id = ?")
         ->execute([$rfpId]);

    echo json_encode(['success' => true]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
