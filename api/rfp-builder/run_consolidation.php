<?php
/**
 * Run Pass 2 (AI consolidation + categorisation + conflict detection) for an RFP.
 *
 * Fetches every extracted requirement for the RFP, calls the AI helper,
 * then in a single transaction wipes any prior consolidation output
 * (categories, consolidated rows, sources, conflicts) and inserts the
 * fresh result. The wipe is destructive — re-running discards any manual
 * edits the analyst made in the consolidate page. Once `is_locked` is set
 * on rows in Phase 3e we'll guard re-runs more strongly.
 *
 * The AI call itself is uncommitted DB work — we open the transaction
 * AFTER the call returns so we don't hold a write lock for 60+ seconds.
 */
session_start();
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/rfp_ai.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

// Pass 2 is the heaviest call in the whole app — give it room to retry on 429.
set_time_limit(600);

try {
    $data = json_decode(file_get_contents('php://input'), true);
    $rfpId = isset($data['rfp_id']) ? (int)$data['rfp_id'] : 0;
    if ($rfpId <= 0) {
        throw new Exception('Missing or invalid rfp_id');
    }

    $conn = connectToDatabase();

    $extractStmt = $conn->prepare(
        "SELECT er.id, er.requirement_text, er.requirement_type, er.source_quote,
                er.document_id, dept.name AS department_name
           FROM rfp_extracted_requirements er
      LEFT JOIN rfp_documents   d    ON er.document_id = d.id
      LEFT JOIN rfp_departments dept ON d.department_id = dept.id
          WHERE er.rfp_id = ?
       ORDER BY er.document_id, er.id"
    );
    $extractStmt->execute([$rfpId]);
    $extracted = $extractStmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($extracted)) {
        throw new Exception('No extracted requirements yet — run Pass 1 extraction on each document first');
    }

    $validExtractedIds = [];
    foreach ($extracted as $row) {
        $validExtractedIds[(int)$row['id']] = true;
    }

    $result  = rfpAiConsolidate($conn, $rfpId, $extracted);
    $payload = $result['payload'];

    $validTypes      = ['requirement', 'pain_point', 'challenge'];
    $validPriorities = ['critical', 'high', 'medium', 'low'];

    $conn->beginTransaction();
    try {
        // Wipe prior consolidation. Order matters: consolidated first
        // so its CASCADE on rfp_consolidated_sources / rfp_conflicts
        // fires before we drop the categories. Categories use SET NULL
        // on the consolidated.category_id FK so they could go either way,
        // but doing consolidated first keeps the dependency direction obvious.
        $conn->prepare("DELETE FROM rfp_consolidated_requirements WHERE rfp_id = ?")
             ->execute([$rfpId]);
        $conn->prepare("DELETE FROM rfp_categories WHERE rfp_id = ?")
             ->execute([$rfpId]);

        // Insert categories, capturing the new DB id keyed by the
        // 0-based index the AI used in its output.
        $catInsert = $conn->prepare(
            "INSERT INTO rfp_categories (rfp_id, name, description, sort_order)
             VALUES (?, ?, ?, ?)"
        );
        $catIdByIndex = [];
        foreach ($payload['categories'] as $idx => $cat) {
            $name = trim($cat['name'] ?? '');
            if ($name === '') continue;
            $desc      = isset($cat['description']) ? trim($cat['description']) : null;
            $sortOrder = isset($cat['sort_order']) ? (int)$cat['sort_order'] : ($idx + 1);
            $catInsert->execute([$rfpId, $name, $desc, $sortOrder]);
            $catIdByIndex[$idx] = (int)$conn->lastInsertId();
        }

        // Insert consolidated rows next, capturing new IDs by index.
        // Conflicts reference rows by index, so the index → id map has
        // to be complete before we touch conflicts.
        $consInsert = $conn->prepare(
            "INSERT INTO rfp_consolidated_requirements
                (rfp_id, category_id, requirement_text, requirement_type, priority, ai_rationale)
             VALUES (?, ?, ?, ?, ?, ?)"
        );
        $sourceInsert = $conn->prepare(
            "INSERT IGNORE INTO rfp_consolidated_sources (consolidated_id, extracted_id) VALUES (?, ?)"
        );
        $consIdByIndex   = [];
        $linkedSourceIds = [];

        foreach ($payload['consolidated_requirements'] as $idx => $req) {
            $text = trim($req['requirement_text'] ?? '');
            if ($text === '') continue;

            $type = $req['type'] ?? 'requirement';
            if (!in_array($type, $validTypes, true)) $type = 'requirement';

            $priority = $req['priority'] ?? 'medium';
            if (!in_array($priority, $validPriorities, true)) $priority = 'medium';

            $catIndex = isset($req['category_index']) ? (int)$req['category_index'] : -1;
            $catId    = $catIdByIndex[$catIndex] ?? null;

            $rationale = isset($req['ai_rationale']) ? trim($req['ai_rationale']) : null;

            $consInsert->execute([$rfpId, $catId, $text, $type, $priority, $rationale]);
            $newId = (int)$conn->lastInsertId();
            $consIdByIndex[$idx] = $newId;

            $sourceIds = $req['source_extracted_ids'] ?? [];
            if (is_array($sourceIds)) {
                foreach ($sourceIds as $sid) {
                    $sid = (int)$sid;
                    // Hallucination guard — only link IDs that actually
                    // exist in this RFP's extracted set.
                    if (!isset($validExtractedIds[$sid])) continue;
                    $sourceInsert->execute([$newId, $sid]);
                    $linkedSourceIds[$sid] = true;
                }
            }
        }

        // Insert conflicts, dropping any pair that references an index
        // we never saved (e.g. AI referenced a consolidated row whose
        // text was empty and got skipped above).
        $conflictInsert = $conn->prepare(
            "INSERT INTO rfp_conflicts
                (rfp_id, consolidated_id_a, consolidated_id_b, ai_explanation, resolution)
             VALUES (?, ?, ?, ?, 'open')"
        );
        $conflictsInserted = 0;
        foreach ($payload['conflicts'] as $conf) {
            $aIdx = isset($conf['consolidated_a_index']) ? (int)$conf['consolidated_a_index'] : -1;
            $bIdx = isset($conf['consolidated_b_index']) ? (int)$conf['consolidated_b_index'] : -1;
            $aId  = $consIdByIndex[$aIdx] ?? null;
            $bId  = $consIdByIndex[$bIdx] ?? null;
            if (!$aId || !$bId || $aId === $bId) continue;
            $expl = trim($conf['explanation'] ?? '');
            $conflictInsert->execute([$rfpId, $aId, $bId, $expl]);
            $conflictsInserted++;
        }

        // Status transitions: collecting → consolidating once we have a
        // first consolidation result. Don't downgrade further-along statuses.
        $conn->prepare(
            "UPDATE rfps
                SET status = CASE WHEN status IN ('draft','collecting') THEN 'consolidating' ELSE status END,
                    updated_datetime = CURRENT_TIMESTAMP
              WHERE id = ?"
        )->execute([$rfpId]);

        $conn->commit();
    } catch (Throwable $tx) {
        $conn->rollBack();
        throw $tx;
    }

    $orphanCount = 0;
    foreach ($validExtractedIds as $sid => $_) {
        if (!isset($linkedSourceIds[$sid])) $orphanCount++;
    }

    echo json_encode([
        'success' => true,
        'rfp_id'  => $rfpId,
        'counts'  => [
            'extracted_input'     => count($extracted),
            'categories'          => count($catIdByIndex),
            'consolidated'        => count($consIdByIndex),
            'conflicts'           => $conflictsInserted,
            'orphan_extracted'    => $orphanCount, // input items the AI didn't link to any consolidated row
        ],
        'tokens_in'   => $result['tokens_in'],
        'tokens_out'  => $result['tokens_out'],
        'cache_read'  => $result['cache_read'],
        'cache_write' => $result['cache_write'],
        'duration_ms' => $result['duration_ms'],
    ]);
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
