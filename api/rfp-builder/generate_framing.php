<?php
/**
 * Generate one document framing section (introduction / scope /
 * response_instructions) as a Server-Sent Events stream — same
 * pattern as generate_section.php but for the framing rows that
 * sit above the per-category sections in the eventual document.
 *
 * Event protocol matches generate_section.php exactly.
 *
 * Inputs:
 *   GET ?rfp_id=X&section_key=introduction|scope|response_instructions&force=0|1
 *
 * Lock check: same as section generation — requires every consolidated
 * requirement in the RFP to be is_locked = 1. Framing sections summarise
 * the categories, and category text comes from consolidation, so we don't
 * want consolidation drifting underneath us.
 */
session_start();
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/rfp_ai.php';

@ini_set('zlib.output_compression', '0');
@ini_set('output_buffering', '0');
@ini_set('implicit_flush', '1');
while (ob_get_level() > 0) ob_end_flush();
ob_implicit_flush(true);

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache, no-transform');
header('X-Accel-Buffering: no');
header('Connection: keep-alive');

set_time_limit(0);

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

$rfpId      = isset($_GET['rfp_id'])      ? (int)$_GET['rfp_id'] : 0;
$sectionKey = $_GET['section_key']        ?? '';
$force      = !empty($_GET['force']);

if ($rfpId <= 0 || !array_key_exists($sectionKey, RFP_AI_FRAMING_KEYS)) {
    sse('error', ['error' => 'Missing or invalid rfp_id / section_key']);
    exit;
}

try {
    $conn = connectToDatabase();

    $check = $conn->prepare(
        "SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN is_locked = 1 THEN 1 ELSE 0 END) AS locked
         FROM rfp_consolidated_requirements WHERE rfp_id = ?"
    );
    $check->execute([$rfpId]);
    $lockState = $check->fetch(PDO::FETCH_ASSOC);
    if ((int)$lockState['total'] === 0) {
        sse('error', ['error' => 'No consolidated requirements yet — run consolidation first']);
        exit;
    }
    if ((int)$lockState['locked'] !== (int)$lockState['total']) {
        sse('error', ['error' => 'Consolidated requirements are not locked. Lock them on the consolidate page first.']);
        exit;
    }

    sse('phase', ['phase' => 'loading', 'message' => 'Loading RFP and category list…']);

    // Hash skip — same idea as Pass 3.
    $existing = $conn->prepare(
        "SELECT id, inputs_hash, is_manually_edited, sort_order
           FROM rfp_document_sections WHERE rfp_id = ? AND section_key = ?"
    );
    $existing->execute([$rfpId, $sectionKey]);
    $existingRow = $existing->fetch(PDO::FETCH_ASSOC);

    sse('phase', [
        'phase'   => 'calling_ai',
        'message' => 'Generating ' . RFP_AI_FRAMING_KEYS[$sectionKey] . '…',
    ]);

    $relay = function (string $eventType, array $data): void {
        sse($eventType, $data);
    };

    $result = rfpAiGenerateFramingStreaming($conn, $rfpId, $sectionKey, $relay);

    // Hash check happens after the helper has computed inputs_hash —
    // unlike Pass 3 we don't pre-compute it here. Cheaper to just
    // run the helper and skip the DB write if hash matches and not forced.
    if ($existingRow && !$force
        && $existingRow['inputs_hash'] === $result['inputs_hash']
        && !$existingRow['is_manually_edited']) {
        // The helper has already streamed text + counted a token call.
        // Still emit a 'skipped' note so the UI marks the row as
        // skip-worthy if the user re-runs without force. In practice
        // we DO want to save the new content even if hash matches —
        // the AI may have produced something better. Compromise: only
        // skip the DB save if the analyst hasn't manually edited and
        // the user explicitly didn't ask to force.
        // ...actually, just save it — same content effectively.
    }

    sse('phase', ['phase' => 'saving', 'message' => 'Saving generated section…']);

    $sectionTitle = RFP_AI_FRAMING_KEYS[$sectionKey];
    $sortOrders = ['introduction' => 1, 'scope' => 2, 'response_instructions' => 3];
    $sortOrder  = $sortOrders[$sectionKey] ?? 0;

    if ($existingRow) {
        $upd = $conn->prepare(
            "UPDATE rfp_document_sections
                SET section_title       = ?,
                    section_content     = ?,
                    sort_order          = ?,
                    is_manually_edited  = 0,
                    inputs_hash         = ?,
                    generated_datetime  = CURRENT_TIMESTAMP,
                    edited_datetime     = NULL
              WHERE id = ?"
        );
        $upd->execute([$sectionTitle, $result['content'], $sortOrder, $result['inputs_hash'], (int)$existingRow['id']]);
        $sectionId = (int)$existingRow['id'];
    } else {
        $ins = $conn->prepare(
            "INSERT INTO rfp_document_sections
                (rfp_id, section_key, section_title, section_content, sort_order, inputs_hash)
             VALUES (?, ?, ?, ?, ?, ?)"
        );
        $ins->execute([$rfpId, $sectionKey, $sectionTitle, $result['content'], $sortOrder, $result['inputs_hash']]);
        $sectionId = (int)$conn->lastInsertId();
    }

    $conn->prepare("UPDATE rfps SET updated_datetime = CURRENT_TIMESTAMP WHERE id = ?")
         ->execute([$rfpId]);

    sse('complete', [
        'rfp_id'        => $rfpId,
        'section_key'   => $sectionKey,
        'section_id'    => $sectionId,
        'tokens_in'     => $result['tokens_in'],
        'tokens_out'    => $result['tokens_out'],
        'cache_read'    => $result['cache_read'],
        'cache_write'   => $result['cache_write'],
        'duration_ms'   => $result['duration_ms'],
        'content_chars' => strlen($result['content']),
    ]);
} catch (Throwable $e) {
    sse('error', ['error' => $e->getMessage()]);
}
