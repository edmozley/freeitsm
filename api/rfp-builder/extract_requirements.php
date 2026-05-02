<?php
/**
 * Run Pass 1 (AI extraction) on a single uploaded document.
 *
 * Wipes any previous extracted_requirements rows for the document,
 * calls the AI helper, inserts the parsed items, and flips the
 * document status to "processed". On failure, the document is left
 * in its previous state (raw_text untouched) and the failure is
 * logged to rfp_processing_log.
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

// AI calls can take a while; bump the request timeout so the worker
// has room to retry on 429s before the request gets killed.
set_time_limit(300);

try {
    $data = json_decode(file_get_contents('php://input'), true);
    $documentId = isset($data['document_id']) ? (int)$data['document_id'] : 0;
    if ($documentId <= 0) {
        throw new Exception('Missing or invalid document_id');
    }

    $conn = connectToDatabase();

    $stmt = $conn->prepare(
        "SELECT d.id, d.rfp_id, d.original_filename, d.raw_text, d.status,
                dept.name AS department_name
           FROM rfp_documents d
      LEFT JOIN rfp_departments dept ON d.department_id = dept.id
          WHERE d.id = ?"
    );
    $stmt->execute([$documentId]);
    $doc = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$doc) {
        throw new Exception('Document not found');
    }
    if (empty($doc['raw_text'])) {
        throw new Exception('Document has no extracted text yet — re-extract the source first');
    }

    $rfpId          = (int)$doc['rfp_id'];
    $departmentName = $doc['department_name'] ?: 'Unassigned';

    // Re-running is destructive: wipe prior extracted rows for this doc
    // so the new run is clean. FK ON DELETE CASCADE on
    // rfp_consolidated_sources will tidy up any orphaned m2m rows
    // (relevant once Phase 3 lands; safe today).
    $del = $conn->prepare("DELETE FROM rfp_extracted_requirements WHERE document_id = ?");
    $del->execute([$documentId]);

    $result = rfpAiExtractRequirements($conn, $rfpId, $documentId, $doc['raw_text'], $departmentName);

    $insert = $conn->prepare(
        "INSERT INTO rfp_extracted_requirements
            (rfp_id, document_id, requirement_text, requirement_type, source_quote, ai_confidence)
         VALUES (?, ?, ?, ?, ?, ?)"
    );

    $validTypes = ['requirement', 'pain_point', 'challenge'];
    $insertCount = 0;
    foreach ($result['items'] as $item) {
        if (!is_array($item)) continue;
        $text = trim($item['requirement_text'] ?? '');
        if ($text === '') continue;

        $type = $item['requirement_type'] ?? 'requirement';
        if (!in_array($type, $validTypes, true)) {
            $type = 'requirement';
        }

        $quote      = $item['source_quote'] ?? null;
        $confidence = isset($item['confidence']) ? max(0.0, min(1.0, (float)$item['confidence'])) : null;

        $insert->execute([$rfpId, $documentId, $text, $type, $quote, $confidence]);
        $insertCount++;
    }

    $upd = $conn->prepare(
        "UPDATE rfp_documents
            SET status = 'processed', updated_datetime = CURRENT_TIMESTAMP
          WHERE id = ?"
    );
    $upd->execute([$documentId]);

    $conn->prepare("UPDATE rfps SET updated_datetime = CURRENT_TIMESTAMP WHERE id = ?")
         ->execute([$rfpId]);

    echo json_encode([
        'success'     => true,
        'document_id' => $documentId,
        'count'       => $insertCount,
        'tokens_in'   => $result['tokens_in'],
        'tokens_out'  => $result['tokens_out'],
        'cache_read'  => $result['cache_read'],
        'cache_write' => $result['cache_write'],
        'duration_ms' => $result['duration_ms'],
    ]);
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
