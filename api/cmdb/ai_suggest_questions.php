<?php
/**
 * API: Stage 1 of the "suggest properties" wizard.
 * Given a class, ask Claude for 3-5 short clarifying questions that will
 * sharpen the property suggestions in stage 2.
 *
 * Different organisations run very different stacks — the questions exist
 * so we don't end up suggesting Oracle properties for someone's MongoDB.
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

try {
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $classId = isset($data['class_id']) ? (int)$data['class_id'] : 0;
    if ($classId <= 0) throw new Exception('class_id is required');

    $conn = connectToDatabase();

    $stmt = $conn->prepare("SELECT id, name, description FROM cmdb_classes WHERE id = ?");
    $stmt->execute([$classId]);
    $cls = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$cls) throw new Exception('Class not found');

    // Existing properties to avoid asking redundant questions
    $propsStmt = $conn->prepare("SELECT label FROM cmdb_class_properties WHERE class_id = ?");
    $propsStmt->execute([$classId]);
    $existingLabels = array_column($propsStmt->fetchAll(PDO::FETCH_ASSOC), 'label');

    $cfg = loadCmdbAiConfig($conn);

    $systemPrompt = <<<PROMPT
You are helping an IT administrator design properties for a CMDB (Configuration Management Database) class. Different organisations run very different IT stacks, so before suggesting properties you should ask the admin a few short clarifying questions about THEIR specific environment.

Rules:
- Generate exactly 3 to 5 short questions.
- Each question must help disambiguate what kind of "{$cls['name']}" this organisation actually has, so the next step can suggest the right properties.
- Examples:
  - For "Database": ask what kind (SQL Server / Postgres / MongoDB / Redis / etc), whether they track schemas separately, what level of operational detail they need.
  - For "Server": ask physical / virtual / cloud, what OS family, what role (web / app / db / file / domain), whether they manage hardware lifecycle.
  - For "Application": ask whether it's internal / SaaS / vendor on-prem, who owns it, whether they track versions and integrations.
- Keep each question under 100 characters.
- Optionally include "examples" — a short comma-separated list of typical answers — to make the input field easier to fill in.
- Do NOT ask about anything already implied by an existing property listed below.
- Do NOT ask whether they want a property — only ask about THEIR ENVIRONMENT.

Output STRICT JSON only, no prose, no markdown fences:
{"questions": [{"id": "q1", "question": "...", "examples": "..."}, ...]}
PROMPT;

    if ($cfg['custom_instructions'] !== '') {
        $systemPrompt .= "\n\nAdditional admin instructions:\n" . $cfg['custom_instructions'];
    }

    $userMessage = "Class name: " . $cls['name'] . "\n";
    if (!empty($cls['description'])) {
        $userMessage .= "Class description: " . $cls['description'] . "\n";
    }
    if (!empty($existingLabels)) {
        $userMessage .= "Properties already defined on this class (don't ask about these): " . implode(', ', $existingLabels) . "\n";
    }
    $userMessage .= "\nGenerate the clarifying questions now.";

    $resp = callAnthropic($cfg, $systemPrompt, $userMessage, 600);
    $text = anthropicResponseText($resp);
    $parsed = parseClaudeJson($text);
    $questions = $parsed['questions'] ?? [];

    // Validate / clean
    $clean = [];
    foreach ($questions as $i => $q) {
        if (!is_array($q) || empty($q['question'])) continue;
        $clean[] = [
            'id'       => is_string($q['id'] ?? null) ? $q['id'] : ('q' . ($i + 1)),
            'question' => substr(trim((string)$q['question']), 0, 200),
            'examples' => isset($q['examples']) ? substr(trim((string)$q['examples']), 0, 200) : '',
        ];
        if (count($clean) >= 5) break;
    }
    if (empty($clean)) {
        throw new Exception('AI did not return any usable questions — try again or check your API key.');
    }

    echo json_encode(['success' => true, 'questions' => $clean]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
