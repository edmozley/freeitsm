<?php
/**
 * Update a single consolidated requirement — text, type, priority,
 * category, AI rationale. The analyst can hand-tune any of these
 * after the AI's first pass. category_id is validated against the
 * RFP's own categories; null is allowed (uncategorised).
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
    if ($id <= 0) {
        throw new Exception('Missing or invalid id');
    }

    $text     = trim($data['requirement_text'] ?? '');
    $type     = $data['requirement_type'] ?? 'requirement';
    $priority = $data['priority']         ?? 'medium';
    $catId    = isset($data['category_id']) && $data['category_id'] !== '' ? (int)$data['category_id'] : null;
    $rationale = isset($data['ai_rationale']) ? trim($data['ai_rationale']) : null;

    if ($text === '') throw new Exception('Requirement text is required');

    $validTypes      = ['requirement', 'pain_point', 'challenge'];
    $validPriorities = ['critical', 'high', 'medium', 'low'];
    if (!in_array($type, $validTypes, true))         throw new Exception('Invalid type');
    if (!in_array($priority, $validPriorities, true)) throw new Exception('Invalid priority');

    $conn = connectToDatabase();

    // Check the row exists and capture its rfp_id so we can validate
    // the category belongs to the same RFP.
    $row = $conn->prepare("SELECT rfp_id FROM rfp_consolidated_requirements WHERE id = ?");
    $row->execute([$id]);
    $existing = $row->fetch(PDO::FETCH_ASSOC);
    if (!$existing) throw new Exception('Consolidated requirement not found');

    if ($catId !== null) {
        $cat = $conn->prepare("SELECT id FROM rfp_categories WHERE id = ? AND rfp_id = ?");
        $cat->execute([$catId, (int)$existing['rfp_id']]);
        if (!$cat->fetch()) throw new Exception('Category does not belong to this RFP');
    }

    $upd = $conn->prepare(
        "UPDATE rfp_consolidated_requirements
            SET requirement_text = ?, requirement_type = ?, priority = ?,
                category_id = ?, ai_rationale = ?, updated_datetime = CURRENT_TIMESTAMP
          WHERE id = ?"
    );
    $upd->execute([$text, $type, $priority, $catId, $rationale, $id]);

    $conn->prepare("UPDATE rfps SET updated_datetime = CURRENT_TIMESTAMP WHERE id = ?")
         ->execute([(int)$existing['rfp_id']]);

    echo json_encode(['success' => true, 'id' => $id]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
