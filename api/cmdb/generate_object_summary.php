<?php
/**
 * API: Generate (or regenerate) the AI summary for a CMDB object.
 *
 * Pulls the object's full context — properties (resolved values), parent,
 * children, relationships (both directions), and impact (things that depend
 * on it) — packs it into a structured user message, asks Claude for a 2-3
 * sentence prose synthesis, and saves the result on the object so we don't
 * re-call the AI on every page load.
 *
 * The frontend hits this when the analyst clicks "Regenerate". The cached
 * summary is returned by get_object.php for free on every page render.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/tenancy.php';
require_once __DIR__ . '/_ai_helpers.php';

// Changes data: never on a GET, which a link on another site can send (csrf.php).
csrfRequireWriteMethod();

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

requireModuleAccessJson('cmdb');

try {
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $id = isset($data['id']) ? (int)$data['id'] : 0;
    if ($id <= 0) throw new Exception('id is required');

    $conn = connectToDatabase();

    // Company gate. This one both reads the CI's whole neighbourhood (properties,
    // children, relationships) AND writes back ai_summary, so an ungated call
    // would leak another company's estate into an AI prompt and then persist the
    // generated prose onto their record.
    if (!analystCanAccessCmdbObject($conn, (int) $_SESSION['analyst_id'], $id)) {
        echo json_encode(['success' => false, 'error' => 'Object not found']);
        exit;
    }

    $cfg = loadCmdbAiConfig($conn);

    // The message: object, properties, children, relationships, references, tickets.
    require_once __DIR__ . '/../../includes/cmdb_summary_prompt.php';
    $msg = cmdbObjectSummaryMessage($conn, $id);

    $systemPrompt = <<<PROMPT
You are summarising one CMDB object for an IT analyst's quick reference. Read everything provided about the object and write a short prose synthesis — 2 to 3 sentences only.

Rules:
- 2 to 3 sentences. Plain prose. No bullet points. No headings. No markdown.
- Mention what the object is (class), where it sits (parent if any), who owns it (only if there's an Owner-style property), and how it connects to other objects (only if it does). Each relationship is given as a sentence - source, verb, target - so keep that direction exactly and never reverse it.
- ONLY state what's in the data. Do NOT speculate, infer, or invent details.
- Refer to the object by its name. Refer to other objects by their names too.
- Tone: factual, concise, like a senior engineer briefing a colleague.
- If many properties are missing, you may briefly note "key fields like X are not yet filled in" — but only if it's worth flagging.
- If the object has Status: PLANNED, frame the summary in future tense ("will host…", "is planned to…", "is a proposed…") and surface the planned status near the start of the first sentence so the reader knows immediately the object isn't yet in service.
PROMPT;

    if ($cfg['custom_instructions'] !== '') {
        $systemPrompt .= "\n\nAdditional admin instructions:\n" . $cfg['custom_instructions'];
    }

    $resp = callAnthropic($cfg, $systemPrompt, $msg, 400);
    $summary = anthropicResponseText($resp);
    if ($summary === '') throw new Exception('AI returned an empty summary');

    // Save on the object
    $upd = $conn->prepare(
        "UPDATE cmdb_objects SET ai_summary = ?, ai_summary_generated_at = UTC_TIMESTAMP() WHERE id = ?"
    );
    $upd->execute([$summary, $id]);

    echo json_encode([
        'success'      => true,
        'summary'      => $summary,
        'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
    ]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
