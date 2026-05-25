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

You may use ONLY these four field types — no others exist:

- "text" — single-line text input. Use for names, short answers, dates (formatted as text), email addresses, phone numbers, reference numbers.
- "textarea" — multi-line text input. Use for longer free-text answers like descriptions, comments, notes, justifications, explanations.
- "checkbox" — single yes/no toggle. Use for confirmations, agreements, consent flags, or any single boolean answer.
- "dropdown" — single-select from a fixed list of options. Use ONLY when there is a clear, finite set of choices. ALWAYS provide an "options" array with 2-12 items.

# RULES

- Pick a sensible "title" of 3-8 words if the user didn't give one explicitly.
- "description" should be one short sentence (8-25 words) explaining the form's purpose. Optional but encouraged.
- Aim for 3-12 fields. Fewer is better — capture the user's intent with the minimum needed.
- Mark a field as "is_required": true ONLY when the field is clearly essential to the form's purpose (e.g. requester name on a request form, dates on a leave request, the confirmation checkbox on a consent form). Default to false otherwise.
- For "dropdown" fields, the "options" array must contain real, sensible choices the user would expect to see — do not output placeholder values like "Option 1" / "Option 2".
- For all other field types, "options" should be an empty array [].
- If the user mentions something that doesn't fit the four field types (e.g. file upload, date picker, multi-select, signature), pick the closest type and reflect the constraint in the label. For example, a date question becomes "text" with a label like "Start date (DD/MM/YYYY)". A multi-select becomes "dropdown" with the most-likely single choice.
- Use British English spelling.
- Use clear, professional, neutral language for labels and the description. No marketing copy.
- Order the fields in a sensible flow: identifying information first (name, email, reference), then context (dates, type, category), then free-text fields (descriptions, notes), then any agreement / consent checkbox at the end.

# MODIFICATION MODE

If the user's message contains a "Current form" JSON block (i.e. they're editing an existing form rather than building one from scratch), treat their request as an instruction to MODIFY that existing form, not to replace it. Specifically:

- Start from the current form's title, description and fields exactly as given.
- Apply the user's requested change (add / remove / reorder / rename / re-type a field; rewrite the description; change required flags; edit dropdown options; etc.).
- Keep everything the user didn't ask you to change exactly as it is — don't paraphrase labels, don't reorder unaffected fields, don't bump unrelated required flags.
- If the user's request is ambiguous, make the smallest sensible change that satisfies it.
- The output JSON must still be a complete form definition in the same shape — return the entire updated form, not a diff.

If there is no "Current form" block, generate a new form from scratch as usual.

# CRITICAL FORMAT RULES

- Return ONLY valid JSON. No markdown code fences. No explanation prose. No leading or trailing text.
- Every field MUST include all four keys: field_type, label, is_required, options.
- "field_type" must be exactly one of: "text", "textarea", "checkbox", "dropdown".
- "is_required" must be a boolean (true or false), not a string or number.
- "options" must always be an array (empty [] for non-dropdown fields).
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

    $resp = rfpAiCallAnthropicStreaming($conn, [
        'system'      => FORM_AI_SYSTEM,
        'user'        => $userMessage,
        'max_tokens'  => 4000,
        'temperature' => 0.2,
    ], function (string $eventType, array $data) use (&$accumulated, &$finalUsage) {
        if ($eventType === 'text') {
            $accumulated .= $data['delta'] ?? '';
            sse_send('text', ['delta' => $data['delta'] ?? '']);
        } elseif ($eventType === 'usage') {
            $finalUsage = array_merge($finalUsage, $data);
            sse_send('usage', $data);
        }
    }, $settingsOverride);

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
    $allowedTypes = ['text', 'textarea', 'checkbox', 'dropdown'];
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

        if ($type === 'dropdown' && count($options) === 0) {
            $options = ['Option 1'];
        }
        if ($type !== 'dropdown') {
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
