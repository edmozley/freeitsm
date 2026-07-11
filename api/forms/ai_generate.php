<?php
/**
 * API: Forms — AI Assist (streaming)
 *
 * POST { description: string }
 * Streams Server-Sent Events as the AI generates a JSON form definition.
 *
 * Events emitted:
 *   text  { delta: "..." }              token chunks (the raw JSON, useful for live preview)
 *   usage { tokens_in, tokens_out, ... } token counters
 *   done  { form: { title, description, fields: [...] }, duration_ms, tokens_in, tokens_out }
 *   error { message: "..." }
 *
 * Per-module billing (#436): config now resolves from `forms_ai_*`
 * system_settings entries via loadFormsAiConfig() so admins can use a
 * different API key for the Forms AI than for the RFP Builder, and
 * the spend shows up against the Forms feature in their provider
 * console. The streaming helper from rfp_ai.php is still reused but
 * with a settingsOverride so it picks up the forms-specific config.
 */

session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/rfp_ai.php';
require_once __DIR__ . '/_ai_helpers.php';

// Disable output buffering at every level so SSE events flush immediately.
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

function sse_send(string $event, array $data): void
{
    echo "event: {$event}\n";
    echo 'data: ' . json_encode($data, JSON_UNESCAPED_SLASHES) . "\n\n";
    @flush();
}

if (!isset($_SESSION['analyst_id'])) {
    sse_send('error', ['message' => 'Not authenticated']);
    exit;
}
requireModuleAccessJson('forms');

$rawBody = file_get_contents('php://input');
$input   = json_decode($rawBody, true);
$description = trim($input['description'] ?? '');

// Optional — the form the user is currently editing. When present we
// switch from "build from scratch" mode to "modify this form based on
// the user's request" mode (#439). Validated lightly: title is a
// string, fields is an array. Anything else gets ignored.
$currentForm = null;
if (isset($input['current_form']) && is_array($input['current_form'])) {
    $cf = $input['current_form'];
    if (isset($cf['title']) && isset($cf['fields']) && is_array($cf['fields'])) {
        $currentForm = [
            'title'       => (string)$cf['title'],
            'description' => (string)($cf['description'] ?? ''),
            'fields'      => array_values($cf['fields']),
        ];
    }
}

if ($description === '') {
    sse_send('error', ['message' => $currentForm
        ? 'Please describe what you want to change'
        : 'Please describe the form you want to build']);
    exit;
}
if (mb_strlen($description) > 2000) {
    sse_send('error', ['message' => 'Description is too long (max 2000 characters)']);
    exit;
}

const FORM_AI_SYSTEM = <<<PROMPT
You are a form designer. Given a user's plain-English description of a form they want to build, generate a complete form definition as a single JSON object.

# OUTPUT FORMAT

Return EXACTLY ONE JSON object with this shape:

{
  "title": "...",
  "description": "...",
  "fields": [
    {
      "field_type": "text",
      "label": "...",
      "is_required": true,
      "options": []
    }
  ]
}

# FIELD TYPES

You may use ONLY these eight field types — no others exist:

- "text" — single-line free text. Use for names, short answers, phone numbers, reference numbers, dates formatted as text.
- "textarea" — multi-line text input. Use for longer free-text answers like descriptions, comments, notes, justifications, explanations.
- "email" — text input with email-address format validation. PREFER over "text" whenever the question asks for an email address.
- "number" — numeric input (rejects letters). Use for quantities, ages, counts, amounts, hours, days. PREFER over "text" whenever the question asks for a number.
- "checkbox" — SINGLE yes/no toggle (one checkbox). Use for confirmations, agreements, consent flags, or any one-question boolean. The label IS the question. NEVER use this when the user wants to pick multiple things from a list (use "checkboxes" for that).
- "checkboxes" — MULTI-SELECT from a list of options (zero or more can be picked). Use when the user wants to capture "tick everything that applies". ALWAYS provide an "options" array with 2-12 items.
- "radio" — SINGLE-select from a small visible list of 2-5 options (radio buttons). Use when there are a small number of mutually-exclusive choices and you want them all visible at once. ALWAYS provide an "options" array with 2-5 items. For more than 5 options prefer "dropdown".
- "dropdown" — SINGLE-select from a fixed list of options (collapsed dropdown). Use when there are 6+ mutually-exclusive choices, or when space is tight. ALWAYS provide an "options" array with 2-12 items.

# RULES

- Pick a sensible "title" of 3-8 words if the user didn't give one explicitly.
- "description" should be one short sentence (8-25 words) explaining the form's purpose. Optional but encouraged.
- Aim for 3-12 fields. Fewer is better — capture the user's intent with the minimum needed.
- Mark a field as "is_required": true ONLY when the field is clearly essential to the form's purpose (e.g. requester name on a request form, dates on a leave request, the confirmation checkbox on a consent form). Default to false otherwise.
- For "dropdown", "radio" and "checkboxes" fields, the "options" array must contain real, sensible choices the user would expect to see — do not output placeholder values like "Option 1" / "Option 2".
- For all other field types, "options" should be an empty array [].
- If the user mentions something that doesn't fit the available types (e.g. file upload, date picker, signature), pick the closest type and reflect the constraint in the label. For example, a date question becomes "text" with a label like "Start date (DD/MM/YYYY)".
- Use British English spelling.
- Use clear, professional, neutral language for labels and the description. No marketing copy.
- Order the fields in a sensible flow: identifying information first (name, email, reference), then context (dates, type, category), then free-text fields (descriptions, notes), then any agreement / consent checkbox at the end.

# MODIFICATION MODE — VERY IMPORTANT

If the user's message contains a "Current form" JSON block, the user is EDITING an existing form. This is the most important rule in this prompt:

**DO NOT REBUILD THE FORM FROM SCRATCH.** Make the smallest possible change that satisfies the user's request.

Concretely:

- Start from the current form's title, description and fields EXACTLY as given.
- Apply ONLY the change the user asked for (add / remove / reorder / rename / re-type a field; rewrite the description; change required flags; edit dropdown options; etc.).
- Every field the user didn't explicitly ask you to change MUST come back IDENTICAL to how it was in the Current form — same field_type, same label (character-for-character), same is_required, same options array (in the same order).
- Do NOT paraphrase, "improve", or re-tone labels that weren't part of the request.
- Do NOT reorder unaffected fields.
- Do NOT bump is_required flags that weren't part of the request.
- Do NOT regenerate dropdown options that weren't part of the request.
- If the user's request is ambiguous, make the smallest sensible change that satisfies it. If you're unsure whether they want a change at all, prefer leaving the form alone.
- The output JSON must still be a complete form definition in the same shape — return the entire updated form, not a diff. But "the entire updated form" should be 99% identical to "Current form" for typical small-change requests.

If there is NO "Current form" block, generate a new form from scratch as usual.

# CRITICAL FORMAT RULES

- Return ONLY valid JSON. No markdown code fences. No explanation prose. No leading or trailing text.
- Every field MUST include all four keys: field_type, label, is_required, options.
- "field_type" must be exactly one of: "text", "textarea", "email", "number", "checkbox", "checkboxes", "radio", "dropdown".
- "is_required" must be a boolean (true or false), not a string or number.
- "options" must always be an array (empty [] for field types that don't use it: text, textarea, email, number, checkbox).
PROMPT;

try {
    $conn = connectToDatabase();

    // Resolve the forms-specific AI config — provider, model, api_key,
    // verify_ssl — and pass it as a settingsOverride so the streaming
    // helper bills against the forms_ai_* key rather than rfp_ai_*.
    $formsCfg = loadFormsAiConfig($conn);
    $settingsOverride = [
        'provider'   => $formsCfg['provider'],
        'model'      => $formsCfg['model'],
        'api_key'    => $formsCfg['api_key'],
        'verify_ssl' => $formsCfg['verify_ssl'] ? '1' : '0',
    ];

    $accumulated = '';
    $finalUsage  = ['tokens_in' => null, 'tokens_out' => null, 'cache_read' => null, 'cache_write' => null];

    // User message changes shape depending on whether we're modifying
    // an existing form or building a new one. The system prompt's
    // MODIFICATION MODE section keys off the "Current form" header.
    if ($currentForm) {
        $userMessage =
            "Current form:\n```json\n" . json_encode($currentForm, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n```\n\n" .
            "User's modification request:\n\n" . $description;
    } else {
        $userMessage = "Form description from the user:\n\n" . $description;
    }

    $onEvent = function (string $eventType, array $data) use (&$accumulated, &$finalUsage) {
        if ($eventType === 'text') {
            $accumulated .= $data['delta'] ?? '';
            sse_send('text', ['delta' => $data['delta'] ?? '']);
        } elseif ($eventType === 'usage') {
            $finalUsage = array_merge($finalUsage, $data);
            sse_send('usage', $data);
        }
    };

    $opts = [
        'system'      => FORM_AI_SYSTEM,
        'user'        => $userMessage,
        'max_tokens'  => 4000,
        'temperature' => 0.2,
    ];

    if ($formsCfg['provider'] === 'anthropic') {
        // Anthropic keeps live token-by-token streaming (unchanged engine).
        $resp = rfpAiCallAnthropicStreaming($conn, $opts, $onEvent, $settingsOverride);
    } else {
        // OpenRouter / OpenAI: one-shot via the shared client, emitted as a
        // single SSE chunk so the front-end SSE consumer is unaffected.
        require_once '../../includes/ai_provider.php';
        $one = aiProviderChat($formsCfg, $opts);
        $onEvent('text', ['delta' => $one['content']]);
        $onEvent('usage', ['tokens_in' => $one['tokens_in'], 'tokens_out' => $one['tokens_out']]);
        $resp = [
            'content'     => $one['content'],
            'tokens_in'   => $one['tokens_in'],
            'tokens_out'  => $one['tokens_out'],
            'cache_read'  => null,
            'cache_write' => null,
            'duration_ms' => $one['duration_ms'],
        ];
    }

    // Strip any stray fences (the prompt forbids them but be tolerant).
    $content = $resp['content'];
    $content = preg_replace('/^\s*```(?:json)?\s*\r?\n/', '', $content);
    $content = preg_replace('/\r?\n\s*```\s*$/', '', $content);
    $content = trim($content);

    $payload = json_decode($content, true);
    if (!is_array($payload)) {
        sse_send('error', ['message' => 'AI returned invalid JSON. Try rephrasing your description.']);
        exit;
    }

    // Validate + sanitise the shape so the front-end can trust it.
    // Allowed types must stay in sync with the renderer in
    // forms/edit/index.php's FIELD_TYPES_WITH_OPTIONS / preview switch.
    $allowedTypes = ['text', 'textarea', 'email', 'number', 'checkbox', 'checkboxes', 'radio', 'dropdown'];
    $typesWithOptions = ['dropdown', 'radio', 'checkboxes'];
    $cleanFields  = [];
    foreach (($payload['fields'] ?? []) as $f) {
        if (!is_array($f)) continue;
        $type = $f['field_type'] ?? '';
        if (!in_array($type, $allowedTypes, true)) {
            $type = 'text';
        }
        $label = trim((string)($f['label'] ?? ''));
        if ($label === '') continue;

        $options = $f['options'] ?? [];
        if (!is_array($options)) $options = [];
        $options = array_values(array_filter(array_map(fn($o) => trim((string)$o), $options), fn($o) => $o !== ''));

        if (in_array($type, $typesWithOptions, true) && count($options) === 0) {
            $options = ['Option 1'];
        }
        if (!in_array($type, $typesWithOptions, true)) {
            $options = [];
        }

        $cleanFields[] = [
            'field_type'  => $type,
            'label'       => $label,
            'is_required' => !empty($f['is_required']),
            'options'     => $options,
        ];
    }

    if (empty($cleanFields)) {
        sse_send('error', ['message' => 'AI returned a form with no usable fields. Try a more specific description.']);
        exit;
    }

    $form = [
        'title'       => trim((string)($payload['title'] ?? '')),
        'description' => trim((string)($payload['description'] ?? '')),
        'fields'      => $cleanFields,
    ];

    sse_send('done', [
        'form'        => $form,
        'duration_ms' => $resp['duration_ms'] ?? null,
        'tokens_in'   => $resp['tokens_in']   ?? null,
        'tokens_out'  => $resp['tokens_out']  ?? null,
        'cache_read'  => $resp['cache_read']  ?? null,
        'cache_write' => $resp['cache_write'] ?? null,
    ]);

} catch (Throwable $e) {
    sse_send('error', ['message' => $e->getMessage()]);
}
