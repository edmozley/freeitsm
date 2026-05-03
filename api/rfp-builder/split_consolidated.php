<?php
/**
 * Split one consolidated requirement back into N — used when the AI
 * over-merged subtly different items. Caller supplies the original
 * row id and an array of new rows, each with its own text/priority/
 * category and the subset of the original's source-extracted IDs
 * that should belong to it.
 *
 * Source IDs that aren't assigned to any new row are dropped (the
 * analyst can add them back via "Add custom" if needed). Source
 * IDs not on the original row are silently ignored.
 *
 * The original row's conflicts are deleted along with the row —
 * conflicts referenced "this row" as A or B and that identity is
 * gone, so re-detection on next consolidation re-run is the right
 * recovery path. Inside one transaction so we never end up with
 * half-split state.
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

    $newRows = $data['new_rows'] ?? [];
    if (!is_array($newRows) || count($newRows) < 2) {
        throw new Exception('Provide at least 2 new rows to split into');
    }

    $validTypes      = ['requirement', 'pain_point', 'challenge'];
    $validPriorities = ['critical', 'high', 'medium', 'low'];

    $conn = connectToDatabase();

    $orig = $conn->prepare("SELECT rfp_id FROM rfp_consolidated_requirements WHERE id = ?");
    $orig->execute([$id]);
    $origRow = $orig->fetch(PDO::FETCH_ASSOC);
    if (!$origRow) throw new Exception('Consolidated requirement not found');
    $rfpId = (int)$origRow['rfp_id'];

    // Pull every source extracted_id currently linked to the original
    // row. Each new row must claim a subset of these.
    $srcStmt = $conn->prepare(
        "SELECT extracted_id FROM rfp_consolidated_sources WHERE consolidated_id = ?"
    );
    $srcStmt->execute([$id]);
    $allowedSources = array_map('intval', $srcStmt->fetchAll(PDO::FETCH_COLUMN));
    $allowedSet = array_flip($allowedSources);

    // Pre-validate everything before we touch the DB.
    $validatedRows = [];
    foreach ($newRows as $idx => $r) {
        $text = trim($r['requirement_text'] ?? '');
        if ($text === '') throw new Exception("New row " . ($idx + 1) . ": text is required");

        $type = $r['requirement_type'] ?? 'requirement';
        if (!in_array($type, $validTypes, true)) throw new Exception("New row " . ($idx + 1) . ": invalid type");

        $priority = $r['priority'] ?? 'medium';
        if (!in_array($priority, $validPriorities, true)) throw new Exception("New row " . ($idx + 1) . ": invalid priority");

        $catId = isset($r['category_id']) && $r['category_id'] !== '' ? (int)$r['category_id'] : null;
        if ($catId !== null) {
            $catCheck = $conn->prepare("SELECT id FROM rfp_categories WHERE id = ? AND rfp_id = ?");
            $catCheck->execute([$catId, $rfpId]);
            if (!$catCheck->fetch()) throw new Exception("New row " . ($idx + 1) . ": category not in this RFP");
        }

        $rationale = isset($r['ai_rationale']) && trim($r['ai_rationale']) !== ''
            ? trim($r['ai_rationale'])
            : 'Split from #' . $id;

        $rowSources = [];
        if (!empty($r['source_extracted_ids']) && is_array($r['source_extracted_ids'])) {
            foreach ($r['source_extracted_ids'] as $sid) {
                $sid = (int)$sid;
                if (isset($allowedSet[$sid])) $rowSources[] = $sid;
            }
        }

        $validatedRows[] = [
            'text'      => $text,
            'type'      => $type,
            'priority'  => $priority,
            'category_id' => $catId,
            'rationale' => $rationale,
            'sources'   => $rowSources,
        ];
    }

    $conn->beginTransaction();
    try {
        $ins = $conn->prepare(
            "INSERT INTO rfp_consolidated_requirements
                (rfp_id, category_id, requirement_text, requirement_type, priority, ai_rationale)
             VALUES (?, ?, ?, ?, ?, ?)"
        );
        $linkIns = $conn->prepare(
            "INSERT IGNORE INTO rfp_consolidated_sources (consolidated_id, extracted_id) VALUES (?, ?)"
        );

        $createdIds = [];
        foreach ($validatedRows as $r) {
            $ins->execute([$rfpId, $r['category_id'], $r['text'], $r['type'], $r['priority'], $r['rationale']]);
            $newId = (int)$conn->lastInsertId();
            $createdIds[] = $newId;
            foreach ($r['sources'] as $sid) {
                $linkIns->execute([$newId, $sid]);
            }
        }

        // Cascade-deletes the old row's source links and any conflicts
        // referencing it.
        $conn->prepare("DELETE FROM rfp_consolidated_requirements WHERE id = ?")
             ->execute([$id]);

        $conn->prepare("UPDATE rfps SET updated_datetime = CURRENT_TIMESTAMP WHERE id = ?")
             ->execute([$rfpId]);

        $conn->commit();
    } catch (Throwable $tx) {
        $conn->rollBack();
        throw $tx;
    }

    echo json_encode(['success' => true, 'created_ids' => $createdIds]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
