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
 * Reuses the rfp_ai.php Anthropic streaming helper. The provider/model/key
 * comes from the same system_settings entries the RFP Builder uses
 * (Contracts → Settings → RFP AI).
 */

session_start();
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/rfp_ai.php';

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

if ($description === '') {
    sse_send('error', ['message' => 'Please describe the form you want to build']);
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

# CRITICAL FORMAT RULES

- Return ONLY valid JSON. No markdown code fences. No explanation prose. No leading or trailing text.
- Every field MUST include all four keys: field_type, label, is_required, options.
- "field_type" must be exactly one of: "text", "textarea", "checkbox", "dropdown".
- "is_required" must be a boolean (true or false), not a string or number.
- "options" must always be an array (empty [] for non-dropdown fields).
PROMPT;

try {
    $conn = connectToDatabase();

    $accumulated = '';
    $finalUsage  = ['tokens_in' => null, 'tokens_out' => null, 'cache_read' => null, 'cache_write' => null];

    $resp = rfpAiCallAnthropicStreaming($conn, [
        'system'      => FORM_AI_SYSTEM,
        'user'        => "Form description from the user:\n\n" . $description,
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
    });

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
