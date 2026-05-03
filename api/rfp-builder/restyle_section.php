<?php
/**
 * Restyle one category section: re-run the AI over the existing HTML
 * to apply the style guide without changing the substance. Same
 * streaming SSE protocol as generate_section.php so the document
 * page can drop a restyle into the same batch UI.
 *
 * Inputs:
 *   GET ?rfp_id=X&section_id=Y
 *
 * Snapshots the current version into rfp_section_history before
 * overwriting, like a manual edit, so restyles are reversible. Marks
 * is_manually_edited = 0 since the AI produced the result, but the
 * version pill still bumps so the analyst can see a restyle happened.
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

$rfpId     = isset($_GET['rfp_id'])     ? (int)$_GET['rfp_id']     : 0;
$sectionId = isset($_GET['section_id']) ? (int)$_GET['section_id'] : 0;
if ($rfpId <= 0 || $sectionId <= 0) {
    sse('error', ['error' => 'Missing or invalid rfp_id / section_id']);
    exit;
}

try {
    $conn = connectToDatabase();

    // Lock gate — same as the other generation paths.
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

    sse('phase', ['phase' => 'loading', 'message' => 'Loading section…']);

    $secStmt = $conn->prepare(
        "SELECT s.id, s.rfp_id, s.category_id, s.section_content, s.version,
                s.is_manually_edited, c.name AS category_name
           FROM rfp_output_sections s
      LEFT JOIN rfp_categories c ON s.category_id = c.id
          WHERE s.id = ? AND s.rfp_id = ?"
    );
    $secStmt->execute([$sectionId, $rfpId]);
    $section = $secStmt->fetch(PDO::FETCH_ASSOC);
    if (!$section) {
        sse('error', ['error' => 'Section not found for this RFP']);
        exit;
    }
    if (empty(trim($section['section_content']))) {
        sse('error', ['error' => 'Section has no content yet — generate before restyling']);
        exit;
    }

    sse('phase', [
        'phase'   => 'calling_ai',
        'message' => 'Restyling "' . $section['category_name'] . '"…',
    ]);

    $relay = function (string $eventType, array $data): void {
        sse($eventType, $data);
    };

    $result = rfpAiRestyleStreaming(
        $conn,
        $rfpId,
        $section['section_content'],
        'category section #' . (int)$section['id'] . ' (' . $section['category_name'] . ')',
        $relay
    );

    sse('phase', ['phase' => 'saving', 'message' => 'Saving restyled section…']);

    $conn->beginTransaction();
    try {
        // Snapshot the version we're about to overwrite.
        $hist = $conn->prepare(
            "INSERT INTO rfp_section_history
                (section_id, version, section_content, is_manually_edited)
             VALUES (?, ?, ?, ?)"
        );
        $hist->execute([
            (int)$section['id'],
            (int)$section['version'],
            $section['section_content'],
            (int)$section['is_manually_edited']
        ]);

        $newVersion = (int)$section['version'] + 1;

        // Restyle is AI-produced output, so is_manually_edited goes back
        // to 0 — the section is once again something the analyst can let
        // Generate-all hash-skip past in future.
        $upd = $conn->prepare(
            "UPDATE rfp_output_sections
                SET section_content    = ?,
                    version            = ?,
                    is_manually_edited = 0,
                    edited_datetime    = NULL,
                    generated_datetime = CURRENT_TIMESTAMP
              WHERE id = ?"
        );
        $upd->execute([$result['content'], $newVersion, (int)$section['id']]);

        $conn->prepare("UPDATE rfps SET updated_datetime = CURRENT_TIMESTAMP WHERE id = ?")
             ->execute([$rfpId]);

        $conn->commit();
    } catch (Throwable $tx) {
        $conn->rollBack();
        throw $tx;
    }

    sse('complete', [
        'rfp_id'      => $rfpId,
        'section_id'  => (int)$section['id'],
        'version'     => $newVersion,
        'tokens_in'   => $result['tokens_in'],
        'tokens_out'  => $result['tokens_out'],
        'cache_read'  => $result['cache_read'],
        'cache_write' => $result['cache_write'],
        'duration_ms' => $result['duration_ms'],
        'content_chars' => strlen($result['content']),
    ]);
} catch (Throwable $e) {
    sse('error', ['error' => $e->getMessage()]);
}
