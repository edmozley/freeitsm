<?php
/**
 * Update one extracted requirement row (text, type, source_quote).
 * Triggered from the inline edit modal on the extracted-requirements page.
 */
session_start();
require_once '../../config.php';
require_once '../../includes/functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$VALID_TYPES = ['requirement', 'pain_point', 'challenge'];

try {
    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data)) throw new Exception('Invalid request');

    $id    = isset($data['id']) ? (int)$data['id'] : 0;
    $text  = trim($data['requirement_text'] ?? '');
    $type  = $data['requirement_type'] ?? '';
    $quote = isset($data['source_quote']) ? trim($data['source_quote']) : null;

    if ($id <= 0)        throw new Exception('Missing id');
    if ($text === '')    throw new Exception('Requirement text cannot be empty');
    if (!in_array($type, $VALID_TYPES, true)) {
        throw new Exception('Invalid requirement type');
    }
    if ($quote === '') $quote = null;

    $conn = connectToDatabase();

    // Find the rfp_id for the housekeeping update at the end.
    $look = $conn->prepare("SELECT rfp_id FROM rfp_extracted_requirements WHERE id = ?");
    $look->execute([$id]);
    $rfpId = $look->fetchColumn();
    if (!$rfpId) throw new Exception('Requirement not found');

    $upd = $conn->prepare(
        "UPDATE rfp_extracted_requirements
            SET requirement_text = ?, requirement_type = ?, source_quote = ?,
                updated_datetime = CURRENT_TIMESTAMP
          WHERE id = ?"
    );
    $upd->execute([$text, $type, $quote, $id]);

    $conn->prepare("UPDATE rfps SET updated_datetime = CURRENT_TIMESTAMP WHERE id = ?")
         ->execute([$rfpId]);

    echo json_encode(['success' => true]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
