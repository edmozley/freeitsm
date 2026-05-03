<?php
/**
 * Add a custom consolidated requirement that the AI missed entirely.
 * Optionally link to existing extracted source items (rare — usually
 * the analyst is adding something extracted didn't catch either).
 *
 * Auto-marked as "manually added" via ai_rationale = "Manually added".
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
    if ($rfpId <= 0) throw new Exception('Missing or invalid rfp_id');

    $text     = trim($data['requirement_text'] ?? '');
    $type     = $data['requirement_type'] ?? 'requirement';
    $priority = $data['priority']         ?? 'medium';
    $catId    = isset($data['category_id']) && $data['category_id'] !== '' ? (int)$data['category_id'] : null;
    $rationale = isset($data['ai_rationale']) && trim($data['ai_rationale']) !== ''
        ? trim($data['ai_rationale'])
        : 'Manually added by analyst';
    $sourceIds = isset($data['source_extracted_ids']) && is_array($data['source_extracted_ids'])
        ? $data['source_extracted_ids']
        : [];

    if ($text === '') throw new Exception('Requirement text is required');
    $validTypes      = ['requirement', 'pain_point', 'challenge'];
    $validPriorities = ['critical', 'high', 'medium', 'low'];
    if (!in_array($type, $validTypes, true))         throw new Exception('Invalid type');
    if (!in_array($priority, $validPriorities, true)) throw new Exception('Invalid priority');

    $conn = connectToDatabase();

    // RFP must exist
    $rfp = $conn->prepare("SELECT id FROM rfps WHERE id = ?");
    $rfp->execute([$rfpId]);
    if (!$rfp->fetch()) throw new Exception('RFP not found');

    // Category, if supplied, must belong to this RFP
    if ($catId !== null) {
        $cat = $conn->prepare("SELECT id FROM rfp_categories WHERE id = ? AND rfp_id = ?");
        $cat->execute([$catId, $rfpId]);
        if (!$cat->fetch()) throw new Exception('Category does not belong to this RFP');
    }

    // Validate any source IDs against this RFP's extracted set
    $validatedSources = [];
    if (!empty($sourceIds)) {
        $place = implode(',', array_fill(0, count($sourceIds), '?'));
        $check = $conn->prepare(
            "SELECT id FROM rfp_extracted_requirements WHERE rfp_id = ? AND id IN ($place)"
        );
        $check->execute(array_merge([$rfpId], array_map('intval', $sourceIds)));
        foreach ($check->fetchAll(PDO::FETCH_COLUMN) as $okId) {
            $validatedSources[] = (int)$okId;
        }
    }

    $conn->beginTransaction();
    try {
        $ins = $conn->prepare(
            "INSERT INTO rfp_consolidated_requirements
                (rfp_id, category_id, requirement_text, requirement_type, priority, ai_rationale)
             VALUES (?, ?, ?, ?, ?, ?)"
        );
        $ins->execute([$rfpId, $catId, $text, $type, $priority, $rationale]);
        $newId = (int)$conn->lastInsertId();

        if (!empty($validatedSources)) {
            $linkIns = $conn->prepare(
                "INSERT IGNORE INTO rfp_consolidated_sources (consolidated_id, extracted_id) VALUES (?, ?)"
            );
            foreach ($validatedSources as $sid) {
                $linkIns->execute([$newId, $sid]);
            }
        }

        $conn->prepare("UPDATE rfps SET updated_datetime = CURRENT_TIMESTAMP WHERE id = ?")
             ->execute([$rfpId]);

        $conn->commit();
    } catch (Throwable $tx) {
        $conn->rollBack();
        throw $tx;
    }

    echo json_encode(['success' => true, 'id' => $newId]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
