<?php
/**
 * API: AI co-author — compose a workflow from a natural-language description.
 *
 * Body shape:
 *   { prompt: string, existing?: { trigger_event, conditions: [...], actions: [...] } }
 *
 * Returns:
 *   { success: true, workflow: { name, description, trigger_event,
 *                                conditions: [...], actions: [...] },
 *                    explanation: string }
 *
 * Stage 3 MVP: single round-trip, non-streaming. Streaming a la claude.ai is
 * planned for a follow-up. The system prompt gives Claude the engine's
 * catalogues so it can only propose triggers/operators/actions the engine
 * actually understands; the response is validated against the same catalogues
 * server-side before being returned to the client, with unknown items dropped
 * (logged in `warnings`) rather than failing the whole compose.
 */
session_start();
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once __DIR__ . '/../../workflow/includes/engine.php';
require_once __DIR__ . '/_ai_helpers.php';
header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$in = json_decode(file_get_contents('php://input'), true);
if (!is_array($in)) {
    echo json_encode(['success' => false, 'error' => 'Bad payload']);
    exit;
}
$userPrompt = trim((string)($in['prompt'] ?? ''));
$existing   = is_array($in['existing'] ?? null) ? $in['existing'] : null;
if ($userPrompt === '') {
    echo json_encode(['success' => false, 'error' => 'Prompt is required']);
    exit;
}

try {
    $conn = connectToDatabase();
    $cfg  = loadWorkflowAiConfig($conn);

    // ----- Build the system prompt from the live engine catalogues -----
    // Sourcing from the engine means there's exactly one place that defines
    // what the AI knows about — adding a trigger or action in the engine
    // automatically expands what the co-author can propose.
    $triggers = WorkflowEngine::availableTriggers();
    $ops      = WorkflowEngine::availableOperators();
    $actions  = WorkflowEngine::availableActions();
    $fieldsByTrigger = [];
    foreach (array_keys($triggers) as $t) {
        $fieldsByTrigger[$t] = WorkflowEngine::availableFields($t);
    }

    $triggerLines = [];
    foreach ($triggers as $slug => $label) {
        $fields = $fieldsByTrigger[$slug];
        $fieldList = empty($fields) ? '(no fields available)' : implode(', ', $fields);
        $triggerLines[] = "  - {$slug} — {$label}. Fields: {$fieldList}";
    }
    $opLines = [];
    foreach ($ops as $slug => $label) {
        $opLines[] = "  - {$slug} ({$label})";
    }
    $actionLines = [];
    foreach ($actions as $slug => $def) {
        $argsShape = isset($def['args']) ? json_encode($def['args']) : '{}';
        $actionLines[] = "  - {$slug} — {$def['label']}. Args shape: {$argsShape}. {$def['description']}";
    }

    $systemPrompt = <<<SYS
You are a workflow co-author for FreeITSM, an open-source ITSM platform. The user describes an automation in plain English; you respond with a structured JSON workflow proposal that the editor will apply to a visual canvas.

A workflow has three parts:
  - A single TRIGGER (event the workflow listens for)
  - Zero or more CONDITIONS (all must match — AND semantics)
  - One or more ACTIONS (run in order)

You MUST only use triggers, fields, operators and actions from these catalogues. Do NOT invent new ones — the engine will silently drop anything it doesn't recognise.

Available triggers (slug — description. Fields list valid `condition.field` values for that trigger):
{TRIGGERS}

Available operators (use the slug, not the label):
{OPS}

Available actions (slug — description. Args shape shows what keys go in `action.args`):
{ACTIONS}

IMPORTANT — only one action handler is implemented right now: `log_message`. Even if the user describes "send an email" or "create a ticket", the realistic action you can propose today is `log_message` with a message that documents the intent (e.g. "TODO: send email to manager when this fires"). Be honest about this limitation in your explanation if relevant.

Output format — respond ONLY with a single JSON object, no markdown fences, no commentary outside it:

{
  "name": "short human-readable name, ~40 chars max",
  "description": "one-sentence summary of what this workflow does",
  "trigger_event": "<one of the trigger slugs above>",
  "conditions": [
    { "field": "<a valid field for that trigger>", "op": "<an operator slug>", "value": "<string>" }
  ],
  "actions": [
    { "type": "<action slug>", "args": { ... } }
  ],
  "explanation": "2-4 sentences describing what you built and why, in plain English. Mention any caveats (e.g. 'the only action available today is log_message, so I've made it document the intent — a real send-email action lands in a future commit')."
}

If the user is iterating on an existing workflow, you'll see it in the input. Treat the request as a delta: keep what makes sense, change what they asked to change.
SYS;
    $systemPrompt = str_replace('{TRIGGERS}', implode("\n", $triggerLines), $systemPrompt);
    $systemPrompt = str_replace('{OPS}',      implode("\n", $opLines),      $systemPrompt);
    $systemPrompt = str_replace('{ACTIONS}',  implode("\n", $actionLines),  $systemPrompt);

    // User message = the description (+ existing state if iterating).
    $userMessage = "User request: " . $userPrompt;
    if ($existing) {
        $userMessage .= "\n\nExisting workflow (iterate on this — keep what makes sense, change what the user is asking for):\n"
                     .  json_encode($existing, JSON_PRETTY_PRINT);
    }

    $resp     = callAnthropicJson($cfg, $systemPrompt, $userMessage);
    $rawText  = anthropicResponseText($resp);
    $proposal = parseClaudeJson($rawText);

    // ----- Validate the proposal against the catalogues -----
    $warnings = [];
    $triggerEvent = $proposal['trigger_event'] ?? '';
    if (!isset($triggers[$triggerEvent])) {
        // Fall back to the first known trigger so the canvas still has
        // something coherent. Surface a warning the client can show.
        $warnings[] = "AI proposed unknown trigger '{$triggerEvent}' — falling back to first known.";
        $triggerEvent = array_key_first($triggers);
    }
    $validFields = $fieldsByTrigger[$triggerEvent] ?? [];

    $conditions = [];
    foreach (($proposal['conditions'] ?? []) as $c) {
        $field = $c['field'] ?? '';
        $op    = $c['op']    ?? '';
        $value = $c['value'] ?? '';
        if (!isset($ops[$op])) {
            $warnings[] = "Dropped condition with unknown operator '{$op}'.";
            continue;
        }
        // Field validation is soft — accept anything but warn on unknown.
        if ($field !== '' && !in_array($field, $validFields, true)) {
            $warnings[] = "Condition field '{$field}' isn't a known field for trigger '{$triggerEvent}'.";
        }
        $conditions[] = ['field' => $field, 'op' => $op, 'value' => (string)$value];
    }

    $cleanActions = [];
    foreach (($proposal['actions'] ?? []) as $a) {
        $type = $a['type'] ?? '';
        $args = is_array($a['args'] ?? null) ? $a['args'] : [];
        if (!isset($actions[$type])) {
            $warnings[] = "Dropped action of unknown type '{$type}'.";
            continue;
        }
        $cleanActions[] = ['type' => $type, 'args' => $args];
    }
    if (empty($cleanActions)) {
        // The engine requires at least one action — invent a log_message so
        // the canvas is still usable.
        $cleanActions[] = ['type' => 'log_message', 'args' => ['message' => 'Workflow ran (AI co-author produced no usable actions — please add some).']];
        $warnings[] = 'AI produced no valid actions; inserted a placeholder log_message.';
    }

    echo json_encode([
        'success'  => true,
        'workflow' => [
            'name'          => (string)($proposal['name'] ?? ''),
            'description'   => (string)($proposal['description'] ?? ''),
            'trigger_event' => $triggerEvent,
            'conditions'    => $conditions,
            'actions'       => $cleanActions,
        ],
        'explanation' => (string)($proposal['explanation'] ?? ''),
        'warnings'    => $warnings,
    ]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
