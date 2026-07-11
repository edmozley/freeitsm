<?php
/**
 * API: Stage 2 of the "suggest properties" wizard.
 * Given a class + the analyst's answers to the stage-1 questions,
 * Claude returns 6-12 suggested properties tailored to their environment.
 *
 * Returned properties are validated against the property-type allow-list and
 * the existing labels/keys before being sent back, so the client can show
 * them in a checklist UI for confirmation.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once __DIR__ . '/_ai_helpers.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

requireModuleAccessJson('cmdb');

function slugifyProp($name) {
    $slug = strtolower(trim($name));
    $slug = preg_replace('/[^a-z0-9]+/', '_', $slug);
    $slug = trim($slug, '_');
    return $slug !== '' ? $slug : 'property';
}

try {
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $classId = isset($data['class_id']) ? (int)$data['class_id'] : 0;
    $answers = $data['answers'] ?? []; // [{question, answer}, ...]
    if ($classId <= 0) throw new Exception('class_id is required');
    if (!is_array($answers)) $answers = [];

    $conn = connectToDatabase();

    $stmt = $conn->prepare("SELECT id, name, description FROM cmdb_classes WHERE id = ?");
    $stmt->execute([$classId]);
    $cls = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$cls) throw new Exception('Class not found');

    // Pull existing properties to avoid duplicate suggestions
    $propsStmt = $conn->prepare("SELECT label, property_key FROM cmdb_class_properties WHERE class_id = ?");
    $propsStmt->execute([$classId]);
    $existing = $propsStmt->fetchAll(PDO::FETCH_ASSOC);
    $existingKeys   = array_map('strtolower', array_column($existing, 'property_key'));
    $existingLabels = array_map('strtolower', array_column($existing, 'label'));

    $cfg = loadCmdbAiConfig($conn);

    $allowedTypes = implode(' | ', CMDB_AI_PROPERTY_TYPES);
    $systemPrompt = <<<PROMPT
You are designing CMDB properties for a class, given context the admin has provided about THEIR specific environment.

Rules:
- Suggest between 6 and 12 properties total.
- Each property MUST have these fields:
  - label: human-readable Title Case (e.g. "Operating System")
  - property_key: snake_case identifier matching the label (e.g. "operating_system")
  - property_type: one of: {$allowedTypes}
  - is_required: true ONLY if the property is essential (rare — most should be false)
  - why: one short sentence explaining why this matters in plain English
- For property_type "dropdown", include an "options" array of 3-8 likely values based on the admin's answers.
- For property_type "object_ref", include "target_class_hint" — a short string suggesting what kind of object this should reference (e.g. "Person", "Server"). The admin will pick the actual class in the UI.
- Lean towards properties an analyst will actually USE on a daily basis (status, owner, criticality, version, location, environment, lifecycle dates) over exhaustive low-value detail.
- Use the admin's answers to tailor suggestions — don't suggest SQL Server-specific properties if they said MongoDB.
- Do NOT suggest properties that already exist (listed below if any).
- Do NOT include any property whose label or key matches an existing one (case-insensitive).

Output STRICT JSON only, no prose, no markdown fences:
{"properties": [{"label": "...", "property_key": "...", "property_type": "...", "is_required": false, "why": "...", "options": ["..."], "target_class_hint": "..."}, ...]}
The "options" field is only required for dropdown type. The "target_class_hint" field is only required for object_ref type.
PROMPT;

    if ($cfg['custom_instructions'] !== '') {
        $systemPrompt .= "\n\nAdditional admin instructions:\n" . $cfg['custom_instructions'];
    }

    $userMessage = "Class name: " . $cls['name'] . "\n";
    if (!empty($cls['description'])) {
        $userMessage .= "Class description: " . $cls['description'] . "\n";
    }
    if (!empty($existing)) {
        $userMessage .= "Existing properties (do not duplicate): " . implode(', ', array_column($existing, 'label')) . "\n";
    }
    if (!empty($answers)) {
        $userMessage .= "\nAdmin's answers about their environment:\n";
        foreach ($answers as $qa) {
            if (!is_array($qa)) continue;
            $q = trim((string)($qa['question'] ?? ''));
            $a = trim((string)($qa['answer'] ?? ''));
            if ($q === '' || $a === '') continue;
            $userMessage .= "- Q: $q\n  A: $a\n";
        }
    }
    $userMessage .= "\nReturn the property suggestions now.";

    $resp = callAnthropic($cfg, $systemPrompt, $userMessage, 2000);
    $text = anthropicResponseText($resp);
    $parsed = parseClaudeJson($text);
    $raw = $parsed['properties'] ?? [];

    // Validate and clean each suggestion
    $clean = [];
    $seenKeys = [];
    foreach ($raw as $p) {
        if (!is_array($p)) continue;
        $label = trim((string)($p['label'] ?? ''));
        $key   = trim((string)($p['property_key'] ?? ''));
        $type  = trim((string)($p['property_type'] ?? ''));
        if ($label === '' || !in_array($type, CMDB_AI_PROPERTY_TYPES, true)) continue;

        // Reject duplicates of existing or earlier suggestions (case-insensitive)
        if (in_array(strtolower($label), $existingLabels, true)) continue;
        if ($key !== '' && in_array(strtolower($key), $existingKeys, true)) continue;
        if ($key === '') $key = slugifyProp($label);
        if (in_array(strtolower($key), $seenKeys, true)) continue;
        $seenKeys[] = strtolower($key);

        $entry = [
            'label'         => substr($label, 0, 150),
            'property_key'  => substr($key, 0, 100),
            'property_type' => $type,
            'is_required'   => !empty($p['is_required']),
            'why'           => substr(trim((string)($p['why'] ?? '')), 0, 240),
        ];

        if ($type === 'dropdown') {
            $opts = [];
            if (isset($p['options']) && is_array($p['options'])) {
                foreach ($p['options'] as $o) {
                    $o = trim((string)$o);
                    if ($o !== '') $opts[] = substr($o, 0, 100);
                    if (count($opts) >= 12) break;
                }
            }
            if (empty($opts)) continue; // skip dropdown with no options
            $entry['options'] = $opts;
        }

        if ($type === 'object_ref') {
            $entry['target_class_hint'] = substr(trim((string)($p['target_class_hint'] ?? '')), 0, 100);
        }

        $clean[] = $entry;
        if (count($clean) >= 12) break;
    }

    if (empty($clean)) {
        throw new Exception('AI returned no usable suggestions — try refining your answers and run again.');
    }

    echo json_encode(['success' => true, 'properties' => $clean]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
