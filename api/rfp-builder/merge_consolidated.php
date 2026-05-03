<?php
/**
 * Merge two or more consolidated requirements into one — used when the
 * AI under-merged true duplicates. Caller supplies the IDs to merge
 * and the merged row's text/priority/category. Source links from all
 * the merged-away rows are union'd onto the new row (INSERT IGNORE
 * handles the rare case of duplicate links).
 *
 * Conflicts attached to the merged-away rows are deleted along with
 * those rows (cascade) — re-detection on next consolidation re-run
 * is the right recovery path.
 *
 * All work happens inside one transaction so we never end up with a
 * partial merge.
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
    $ids = $data['ids'] ?? [];
    if (!is_array($ids) || count($ids) < 2) {
        throw new Exception('Provide at least 2 ids to merge');
    }
    $ids = array_values(array_unique(array_map('intval', $ids)));
    if (count($ids) < 2) throw new Exception('Provide at least 2 distinct ids to merge');

    $merged   = $data['merged'] ?? [];
    $text     = trim($merged['requirement_text'] ?? '');
    $type     = $merged['requirement_type'] ?? 'requirement';
    $priority = $merged['priority']         ?? 'medium';
    $catId    = isset($merged['category_id']) && $merged['category_id'] !== '' ? (int)$merged['category_id'] : null;
    $rationale = isset($merged['ai_rationale']) && trim($merged['ai_rationale']) !== ''
        ? trim($merged['ai_rationale'])
        : 'Merged from #' . implode(', #', $ids);

    if ($text === '') throw new Exception('Merged requirement text is required');
    $validTypes      = ['requirement', 'pain_point', 'challenge'];
    $validPriorities = ['critical', 'high', 'medium', 'low'];
    if (!in_array($type, $validTypes, true))         throw new Exception('Invalid type');
    if (!in_array($priority, $validPriorities, true)) throw new Exception('Invalid priority');

    $conn = connectToDatabase();

    // All source rows must belong to the same RFP.
    $place = implode(',', array_fill(0, count($ids), '?'));
    $check = $conn->prepare(
        "SELECT id, rfp_id FROM rfp_consolidated_requirements WHERE id IN ($place)"
    );
    $check->execute($ids);
    $rows = $check->fetchAll(PDO::FETCH_ASSOC);
    if (count($rows) !== count($ids)) {
        throw new Exception('One or more rows to merge were not found');
    }
    $rfpIds = array_unique(array_map(fn($r) => (int)$r['rfp_id'], $rows));
    if (count($rfpIds) !== 1) {
        throw new Exception('Cannot merge rows from different RFPs');
    }
    $rfpId = (int)$rfpIds[0];

    if ($catId !== null) {
        $cat = $conn->prepare("SELECT id FROM rfp_categories WHERE id = ? AND rfp_id = ?");
        $cat->execute([$catId, $rfpId]);
        if (!$cat->fetch()) throw new Exception('Category does not belong to this RFP');
    }

    // Union all source extracted_ids from the rows being merged.
    $srcStmt = $conn->prepare(
        "SELECT DISTINCT extracted_id FROM rfp_consolidated_sources WHERE consolidated_id IN ($place)"
    );
    $srcStmt->execute($ids);
    $allSources = array_map('intval', $srcStmt->fetchAll(PDO::FETCH_COLUMN));

    $conn->beginTransaction();
    try {
        $ins = $conn->prepare(
            "INSERT INTO rfp_consolidated_requirements
                (rfp_id, category_id, requirement_text, requirement_type, priority, ai_rationale)
             VALUES (?, ?, ?, ?, ?, ?)"
        );
        $ins->execute([$rfpId, $catId, $text, $type, $priority, $rationale]);
        $newId = (int)$conn->lastInsertId();

        if (!empty($allSources)) {
            $linkIns = $conn->prepare(
                "INSERT IGNORE INTO rfp_consolidated_sources (consolidated_id, extracted_id) VALUES (?, ?)"
            );
            foreach ($allSources as $sid) {
                $linkIns->execute([$newId, $sid]);
            }
        }

        // Delete the merged-away rows. CASCADE clears the now-orphaned
        // source links and any conflicts referring to them.
        $del = $conn->prepare(
            "DELETE FROM rfp_consolidated_requirements WHERE id IN ($place)"
        );
        $del->execute($ids);

        $conn->prepare("UPDATE rfps SET updated_datetime = CURRENT_TIMESTAMP WHERE id = ?")
             ->execute([$rfpId]);

        $conn->commit();
    } catch (Throwable $tx) {
        $conn->rollBack();
        throw $tx;
    }

    echo json_encode([
        'success' => true,
        'id'      => $newId,
        'merged_from' => $ids,
        'source_count' => count($allSources),
    ]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
