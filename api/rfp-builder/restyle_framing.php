<?php
/**
 * Restyle one framing section (introduction / scope /
 * response_instructions). Same pattern as restyle_section.php, but
 * for the rfp_document_sections table. Framing has no version table,
 * so we just overwrite — the analyst can re-generate from scratch
 * if they don't like the restyle.
 *
 * Inputs:
 *   GET ?rfp_id=X&section_key=introduction|scope|response_instructions
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
if ($rfpId <= 0 || !array_key_exists($sectionKey, RFP_AI_FRAMING_KEYS)) {
    sse('error', ['error' => 'Missing or invalid rfp_id / section_key']);
    exit;
}

try {
    $conn = connectToDatabase();

    $check = $conn->prepare(
        "SELECT COUNT(*) AS total,
                SUM(CASE WHEN is_locked = 1 THEN 1 ELSE 0 END) AS locked
           FROM rfp_consolidated_requirements WHERE rfp_id = ?"
    );
    $check->execute([$rfpId]);
    $lockState = $check->fetch(PDO::FETCH_ASSOC);
    if ((int)$lockState['total'] === 0 || (int)$lockState['locked'] !== (int)$lockState['total']) {
        sse('error', ['error' => 'Consolidated requirements must be fully locked before restyling.']);
        exit;
    }

    sse('phase', ['phase' => 'loading', 'message' => 'Loading framing section…']);

    $stmt = $conn->prepare(
        "SELECT id, section_title, section_content
           FROM rfp_document_sections
          WHERE rfp_id = ? AND section_key = ?"
    );
    $stmt->execute([$rfpId, $sectionKey]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        sse('error', ['error' => 'Framing section not yet drafted — generate before restyling']);
        exit;
    }
    if (empty(trim($row['section_content']))) {
        sse('error', ['error' => 'Framing section has no content yet']);
        exit;
    }

    sse('phase', [
        'phase'   => 'calling_ai',
        'message' => 'Restyling ' . RFP_AI_FRAMING_KEYS[$sectionKey] . '…',
    ]);

    $relay = function (string $eventType, array $data): void {
        sse($eventType, $data);
    };

    $result = rfpAiRestyleStreaming(
        $conn,
        $rfpId,
        $row['section_content'],
        'framing ' . $sectionKey,
        $relay
    );

    sse('phase', ['phase' => 'saving', 'message' => 'Saving restyled section…']);

    $upd = $conn->prepare(
        "UPDATE rfp_document_sections
            SET section_content    = ?,
                is_manually_edited = 0,
                edited_datetime    = NULL,
                generated_datetime = CURRENT_TIMESTAMP
          WHERE id = ?"
    );
    $upd->execute([$result['content'], (int)$row['id']]);

    $conn->prepare("UPDATE rfps SET updated_datetime = CURRENT_TIMESTAMP WHERE id = ?")
         ->execute([$rfpId]);

    sse('complete', [
        'rfp_id'        => $rfpId,
        'section_key'   => $sectionKey,
        'section_id'    => (int)$row['id'],
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
