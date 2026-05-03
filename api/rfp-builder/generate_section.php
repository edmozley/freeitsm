<?php
/**
 * Run Pass 3 (AI section generation) for a single category as a
 * Server-Sent Events stream. Same pattern as run_consolidation.php
 * but per-category — the browser opens this endpoint once per
 * category for "Generate all", or just once for a per-section
 * regenerate.
 *
 * Event protocol:
 *   event: phase    data: { phase, message }
 *   event: text     data: { delta }                  per-token chunk
 *   event: usage    data: { tokens_in, tokens_out, cache_read, cache_write }
 *   event: skipped  data: { reason }                 fired when hash matches and force=0
 *   event: complete data: { section_id, version, duration_ms, ... }
 *   event: error    data: { error }
 *
 * Inputs:
 *   GET ?rfp_id=X&category_id=Y&force=0|1
 *
 * Lock check: section generation requires every consolidated row in
 * the RFP to be is_locked = 1. Locking is the gate the analyst flips
 * once they're happy with consolidation; sections built off unlocked
 * rows would risk drift if the analyst edited consolidation
 * mid-generation.
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

$rfpId      = isset($_GET['rfp_id'])      ? (int)$_GET['rfp_id']      : 0;
$categoryId = isset($_GET['category_id']) ? (int)$_GET['category_id'] : 0;
$force      = !empty($_GET['force']);

if ($rfpId <= 0 || $categoryId <= 0) {
    sse('error', ['error' => 'Missing or invalid rfp_id / category_id']);
    exit;
}

try {
    $conn = connectToDatabase();

    // Lock gate — we require ALL consolidated rows for the RFP to be
    // locked before any section generation. Anything less and we'd be
    // building sections off shifting inputs.
    $check = $conn->prepare(
        "SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN is_locked = 1 THEN 1 ELSE 0 END) AS locked
         FROM rfp_consolidated_requirements WHERE rfp_id = ?"
    );
    $check->execute([$rfpId]);
    $lockState = $check->fetch(PDO::FETCH_ASSOC);
    if ((int)$lockState['total'] === 0) {
        sse('error', ['error' => 'No consolidated requirements for this RFP — run consolidation first']);
        exit;
    }
    if ((int)$lockState['locked'] !== (int)$lockState['total']) {
        sse('error', ['error' => 'Consolidated requirements are not locked. Lock them on the consolidate page first to prevent drift while sections generate.']);
        exit;
    }

    sse('phase', ['phase' => 'loading', 'message' => 'Loading category and requirements…']);

    // Hash skip — load existing section's hash before calling AI.
    // Lifted from the prototype: regeneration of a section whose
    // inputs haven't changed is wasteful, so skip unless forced.
    $existingStmt = $conn->prepare(
        "SELECT id, version, requirements_hash, is_manually_edited
           FROM rfp_output_sections
          WHERE rfp_id = ? AND category_id = ?"
    );
    $existingStmt->execute([$rfpId, $categoryId]);
    $existing = $existingStmt->fetch(PDO::FETCH_ASSOC);

    // We need the inputs_hash before we can decide to skip — but the
    // hash is computed inside rfpAiGenerateSectionStreaming() which
    // also calls the AI. To compute the hash without paying the AI
    // call, we'd duplicate the input-loading logic. Cleaner approach:
    // call a hash-only helper. But for simplicity we let the streaming
    // helper run and emit the AI tokens; the skip optimisation kicks
    // in only at API level after we've loaded the inputs but before
    // the AI call. Implement here.
    //
    // We replicate the input-loading just enough to compute the hash;
    // if it matches existing and !force, emit 'skipped' and return.
    $catStmt = $conn->prepare(
        "SELECT id, name, description FROM rfp_categories WHERE id = ? AND rfp_id = ?"
    );
    $catStmt->execute([$categoryId, $rfpId]);
    $category = $catStmt->fetch(PDO::FETCH_ASSOC);
    if (!$category) {
        sse('error', ['error' => 'Category not found']);
        exit;
    }

    $reqStmt = $conn->prepare(
        "SELECT id, requirement_text, requirement_type, priority
           FROM rfp_consolidated_requirements
          WHERE rfp_id = ? AND category_id = ?
       ORDER BY FIELD(priority,'critical','high','medium','low'), id"
    );
    $reqStmt->execute([$rfpId, $categoryId]);
    $requirements = $reqStmt->fetchAll(PDO::FETCH_ASSOC);
    if (empty($requirements)) {
        sse('error', ['error' => 'Category has no consolidated requirements assigned']);
        exit;
    }

    $reqIds = array_map(fn($r) => (int)$r['id'], $requirements);
    $place  = implode(',', array_fill(0, count($reqIds), '?'));
    $deptStmt = $conn->prepare(
        "SELECT cs.consolidated_id, dept.name AS department_name
           FROM rfp_consolidated_sources cs
      INNER JOIN rfp_extracted_requirements er ON cs.extracted_id = er.id
       LEFT JOIN rfp_documents   d    ON er.document_id = d.id
       LEFT JOIN rfp_departments dept ON d.department_id = dept.id
          WHERE cs.consolidated_id IN ($place)
       GROUP BY cs.consolidated_id, dept.name
       ORDER BY dept.name"
    );
    $deptStmt->execute($reqIds);
    $deptsByReq = [];
    foreach ($deptStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $rid = (int)$row['consolidated_id'];
        $deptsByReq[$rid][] = $row['department_name'] ?: 'Unassigned';
    }

    $sgStmt = $conn->prepare("SELECT style_guide FROM rfps WHERE id = ?");
    $sgStmt->execute([$rfpId]);
    $rfpStyle = $sgStmt->fetchColumn();
    $styleGuide = (is_string($rfpStyle) && trim($rfpStyle) !== '')
        ? trim($rfpStyle)
        : "Default style: clear, formal, neutral. British English. Active voice where natural. No marketing language. Keep paragraphs short — three sentences maximum where possible. Avoid jargon; spell out acronyms on first use within a section.";

    $inputsHash = md5(serialize([
        'cat'         => trim($category['name']),
        'desc'        => trim($category['description'] ?? ''),
        'requirements' => array_map(fn($r) => [
            'id'       => (int)$r['id'],
            'text'     => trim($r['requirement_text']),
            'type'     => $r['requirement_type'],
            'priority' => $r['priority'],
            'depts'    => $deptsByReq[(int)$r['id']] ?? [],
        ], $requirements),
        'style' => $styleGuide,
    ]));

    if ($existing && !$force && $existing['requirements_hash'] === $inputsHash && !$existing['is_manually_edited']) {
        sse('skipped', [
            'reason'     => 'Inputs unchanged since last generation',
            'section_id' => (int)$existing['id'],
            'version'    => (int)$existing['version'],
        ]);
        exit;
    }

    sse('phase', [
        'phase'   => 'calling_ai',
        'message' => 'Generating "' . $category['name'] . '" — ' . count($requirements) . ' requirements…',
    ]);

    $relay = function (string $eventType, array $data): void {
        sse($eventType, $data);
    };

    $result = rfpAiGenerateSectionStreaming($conn, $rfpId, $categoryId, $relay);

    sse('phase', ['phase' => 'saving', 'message' => 'Saving generated section…']);

    $conn->beginTransaction();
    try {
        $newVersion = $existing ? (int)$existing['version'] + 1 : 1;

        if ($existing) {
            // Snapshot the prior version into history before overwriting.
            // Fetch the prior content first so we can roundtrip it.
            $prior = $conn->prepare(
                "SELECT version, section_content, is_manually_edited
                   FROM rfp_output_sections WHERE id = ?"
            );
            $prior->execute([(int)$existing['id']]);
            $priorRow = $prior->fetch(PDO::FETCH_ASSOC);
            if ($priorRow && $priorRow['section_content'] !== null) {
                $hist = $conn->prepare(
                    "INSERT INTO rfp_section_history
                        (section_id, version, section_content, is_manually_edited)
                     VALUES (?, ?, ?, ?)"
                );
                $hist->execute([
                    (int)$existing['id'],
                    (int)$priorRow['version'],
                    $priorRow['section_content'],
                    (int)$priorRow['is_manually_edited']
                ]);
            }

            $upd = $conn->prepare(
                "UPDATE rfp_output_sections
                    SET section_content    = ?,
                        version            = ?,
                        is_manually_edited = 0,
                        requirements_hash  = ?,
                        generated_datetime = CURRENT_TIMESTAMP,
                        edited_datetime    = NULL
                  WHERE id = ?"
            );
            $upd->execute([$result['content'], $newVersion, $result['inputs_hash'], (int)$existing['id']]);
            $sectionId = (int)$existing['id'];
        } else {
            $ins = $conn->prepare(
                "INSERT INTO rfp_output_sections
                    (rfp_id, category_id, section_title, section_content, version, requirements_hash)
                 VALUES (?, ?, ?, ?, 1, ?)"
            );
            $ins->execute([$rfpId, $categoryId, $category['name'], $result['content'], $result['inputs_hash']]);
            $sectionId = (int)$conn->lastInsertId();
        }

        $conn->prepare("UPDATE rfps SET updated_datetime = CURRENT_TIMESTAMP WHERE id = ?")
             ->execute([$rfpId]);

        $conn->commit();
    } catch (Throwable $tx) {
        $conn->rollBack();
        throw $tx;
    }

    sse('complete', [
        'rfp_id'      => $rfpId,
        'category_id' => $categoryId,
        'section_id'  => $sectionId,
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
