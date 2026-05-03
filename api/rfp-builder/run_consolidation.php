<?php
/**
 * Run Pass 2 (AI consolidation) as a Server-Sent Events stream so the
 * browser can show live output (claude.ai-style) instead of waiting
 * 60-180s on a spinner. Same DB shape as before — categories,
 * consolidated rows, M:N source links, conflicts — but the AI call
 * is streamed and progress is forwarded to the browser.
 *
 * Event protocol emitted to the browser:
 *   event: phase   data: { phase, message }
 *   event: text    data: { delta }              per-token chunk
 *   event: usage   data: { tokens_in, tokens_out, cache_read, cache_write }
 *   event: complete data: { counts, tokens_*, duration_ms, ... }
 *   event: error   data: { error }
 *
 * The DB transaction only runs after the stream finishes successfully —
 * if anything fails mid-stream, no data is written.
 */
session_start();
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/rfp_ai.php';

// Disable output buffering at every level so SSE events flush immediately.
@ini_set('zlib.output_compression', '0');
@ini_set('output_buffering', '0');
@ini_set('implicit_flush', '1');
while (ob_get_level() > 0) ob_end_flush();
ob_implicit_flush(true);

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache, no-transform');
header('X-Accel-Buffering: no'); // disable nginx proxy buffering
header('Connection: keep-alive');

set_time_limit(0);

/**
 * Send one SSE event to the browser. Always flushes.
 */
function sse(string $event, array $data): void
{
    echo "event: {$event}\n";
    echo "data: " . json_encode($data, JSON_UNESCAPED_SLASHES) . "\n\n";
    @flush();
}

if (!isset($_SESSION['analyst_id'])) {
    sse('error', ['error' => 'Not authenticated']);
    exit;
}

// Browser sends rfp_id as a query parameter on EventSource (which only
// supports GET) — POST body would force fetch+ReadableStream complexity
// for no real gain.
$rfpId = isset($_GET['rfp_id']) ? (int)$_GET['rfp_id'] : 0;
if ($rfpId <= 0) {
    sse('error', ['error' => 'Missing or invalid rfp_id']);
    exit;
}

try {
    $conn = connectToDatabase();

    sse('phase', ['phase' => 'loading', 'message' => 'Loading extracted requirements…']);

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
        sse('error', ['error' => 'No extracted requirements yet — run Pass 1 extraction on each document first']);
        exit;
    }

    $validExtractedIds = [];
    foreach ($extracted as $row) {
        $validExtractedIds[(int)$row['id']] = true;
    }

    sse('phase', [
        'phase'   => 'calling_ai',
        'message' => 'Calling Claude — categorising, deduplicating and detecting conflicts across ' . count($extracted) . ' extracted items…',
    ]);

    // The streaming helper invokes this callback for every text delta
    // and usage update. We just relay each to the browser.
    $relay = function (string $eventType, array $data): void {
        sse($eventType, $data);
    };

    $result  = rfpAiConsolidateStreaming($conn, $rfpId, $extracted, $relay);
    $payload = $result['payload'];

    sse('phase', ['phase' => 'saving', 'message' => 'AI complete — saving categories, requirements and conflicts…']);

    $validTypes      = ['requirement', 'pain_point', 'challenge'];
    $validPriorities = ['critical', 'high', 'medium', 'low'];

    $conn->beginTransaction();
    try {
        $conn->prepare("DELETE FROM rfp_consolidated_requirements WHERE rfp_id = ?")
             ->execute([$rfpId]);
        $conn->prepare("DELETE FROM rfp_categories WHERE rfp_id = ?")
             ->execute([$rfpId]);

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
                    if (!isset($validExtractedIds[$sid])) continue;
                    $sourceInsert->execute([$newId, $sid]);
                    $linkedSourceIds[$sid] = true;
                }
            }
        }

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

    sse('complete', [
        'rfp_id' => $rfpId,
        'counts' => [
            'extracted_input'  => count($extracted),
            'categories'       => count($catIdByIndex),
            'consolidated'     => count($consIdByIndex),
            'conflicts'        => $conflictsInserted,
            'orphan_extracted' => $orphanCount,
        ],
        'tokens_in'   => $result['tokens_in'],
        'tokens_out'  => $result['tokens_out'],
        'cache_read'  => $result['cache_read'],
        'cache_write' => $result['cache_write'],
        'duration_ms' => $result['duration_ms'],
    ]);
} catch (Throwable $e) {
    sse('error', ['error' => $e->getMessage()]);
}
