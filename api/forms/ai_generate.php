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
require_once '../../includes/services/forms.php';   // LOOKUP_SOURCES — one list, not a second copy
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

/**
 * Sends one server-sent event (an event name and a JSON data line) and flushes it to the
 * browser straight away.
 *
 * @param string $event  the event name the browser listens for, e.g. 'text', 'usage' or 'error'
 * @param array $data  the payload, sent as one JSON line
 */
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
requireModuleWriteJson('forms');   // Read only access (#180) changes nothing here
require_once '../../includes/rbac.php';
requireActionJson(Cap::FORMS_DESIGN);   // designing - #180; module access is enough until Forms → Settings → Access restricts it

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

You may use ONLY these field types — no others exist:

- "text" — single-line free text. Use for names, short answers, phone numbers, reference numbers.
- "textarea" — multi-line text input. Use for longer free-text answers like descriptions, comments, notes, justifications, explanations.
- "email" — text input with email-address format validation. PREFER over "text" whenever the question asks for an email address.
- "number" — numeric input (rejects letters). Use for quantities, ages, counts, amounts, hours, days. PREFER over "text" whenever the question asks for a number.
- "checkbox" — SINGLE yes/no toggle (one checkbox). Use for confirmations, agreements, consent flags, or any one-question boolean. The label IS the question. NEVER use this when the user wants to pick multiple things from a list (use "checkboxes" for that).
- "checkboxes" — MULTI-SELECT from a list of options (zero or more can be picked). Use when the user wants to capture "tick everything that applies". ALWAYS provide an "options" array with 2-12 items.
- "radio" — SINGLE-select from a small visible list of 2-5 options (radio buttons). Use when there are a small number of mutually-exclusive choices and you want them all visible at once. ALWAYS provide an "options" array with 2-5 items. For more than 5 options prefer "dropdown".
- "dropdown" — SINGLE-select from a fixed list of options (collapsed dropdown). Use when there are 6+ mutually-exclusive choices, or when space is tight. ALWAYS provide an "options" array with 2-12 items.
- "datetime" — a real date and/or time picker. ALWAYS use this for any date or time question — start dates, needed-by dates, appointment slots, last working day, when an error occurred. NEVER ask for a date as "text". Set "date_mode" to one of: "date" (a calendar date — the usual choice), "time" (a clock time only, with no date), "datetime" (both together, for a specific moment).
- "lookup" — a search box that looks up a REAL RECORD already held in FreeITSM, so the answer is the record itself rather than someone's description of it. Use whenever the question is "which one?" about something the ITSM system already knows: which laptop, which server, which person. Set "lookup_source" to one of: "asset" (a piece of equipment — laptop, desktop, phone, monitor, printer; searchable by name, asset tag or serial), "cmdb" (an infrastructure item — a server, service, application, database), "user" (a person on the staff directory), "location" (a site, building, floor or room from the organisation's locations list). PREFER this over "text" for questions like "Which machine is affected?", "Which server is down?", "Who is the new starter's manager?", "Which office are you in?". Do NOT use it for something the system does not hold, such as a supplier name — use "text" for those.
- "section" — NOT a question. A heading that groups every field after it until the next "section". It collects no answer, so it must NEVER be "is_required": true and its "options" must be []. Use 2-4 of these to break a long form (roughly 8+ fields) into named groups such as "Your details", "Equipment needed", "Approval". Do not use any on a short form.
- "note" — NOT a question, and NOT a heading. Standing text the person must read before answering: a rule, a deadline, a consequence. Its "label" is the message itself, kept to one sentence. It collects no answer, so it must NEVER be "is_required": true and its "options" must be []. Set "note_style" to one of: "info" (the default — guidance), "warning" (something that will delay them, such as "requests after 3pm are handled the next working day"), "danger" (a consequence that cannot be undone, or a spending threshold that needs sign-off), "success", "plain". Use AT MOST ONE, and only when the form genuinely carries a rule a person would otherwise get wrong — a note on every form is noise, and a form full of warnings is a form nobody reads.

# RULES

- Pick a sensible "title" of 3-8 words if the user didn't give one explicitly.
- "description" should be one short sentence (8-25 words) explaining the form's purpose. Optional but encouraged.
- Aim for 3-12 fields. Fewer is better — capture the user's intent with the minimum needed.
- Mark a field as "is_required": true ONLY when the field is clearly essential to the form's purpose (e.g. requester name on a request form, dates on a leave request, the confirmation checkbox on a consent form). Default to false otherwise.
- For "dropdown", "radio" and "checkboxes" fields, the "options" array must contain real, sensible choices the user would expect to see — do not output placeholder values like "Option 1" / "Option 2".
- For all other field types, "options" should be an empty array [].
- If the user mentions something that doesn't fit the available types (e.g. file upload, signature), pick the closest type and reflect the constraint in the label.
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
- "field_type" must be exactly one of the types listed under FIELD TYPES above, spelled exactly as written there. Do not invent a type.
- A "datetime" field must also carry "date_mode", and a "lookup" field must also carry "lookup_source", as described under FIELD TYPES.
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
    // 'section' is allowed so the generator can group a long form under headings.
    // It is presentational — no options, never required — and the generator is not
    // asked to propose conditional rules, which stay something the author sets.
    // 'note' is allowed for the same reason as 'section' — presentational, never
    // required — but its named style has to be whitelisted too, below, or a model
    // could propose a colour word that no stylesheet matches.
    $allowedTypes = ['text', 'textarea', 'email', 'number', 'checkbox', 'checkboxes', 'radio', 'dropdown', 'datetime', 'lookup', 'section', 'note'];
    $typesWithOptions = ['dropdown', 'radio', 'checkboxes'];
    // A 'datetime' field may arrive with a mode. Anything else is dropped, and an
    // absent or unrecognised one falls back to a plain date — the common case.
    $dateModes = ['date', 'time', 'datetime'];
    // A 'lookup' must name a source we actually serve. An unrecognised one would
    // leave a field that can never return a result, so the field becomes plain
    // text instead — a worse answer, but a working form.
    $lookupSources = array_keys(FormsService::LOOKUP_SOURCES);
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

        $clean = [
            'field_type'  => $type,
            'label'       => $label,
            // A presentational item collects nothing, so it can never be required
            // however the model labelled it — the service rejects that outright.
            // Asked of the one list rather than by naming 'section', so a second
            // presentational type cannot arrive here still marked required.
            'is_required' => FormsService::isAnswerable($type) && !empty($f['is_required']),
            'options'     => $options,
        ];
        if ($type === 'datetime') {
            $mode = $f['date_mode'] ?? '';
            $clean['config'] = ['date_mode' => in_array($mode, $dateModes, true) ? $mode : 'date'];
        }
        if ($type === 'note') {
            /* Whitelisted against the service's own list, never trusted through.
               A model asked for a "style" will happily answer "amber" or "red",
               and an unrecognised value reaches the DOM as an attribute selector
               that matches no rule — an unstyled box that looks like a bug.
               ⚠️ note_body is deliberately NOT taken from the model: the label is
               the message, and a generator that writes two paragraphs of policy
               it invented is worse than one that writes a single sentence. */
            $style = $f['note_style'] ?? '';
            $clean['config'] = ['note_style' => in_array($style, FormsService::NOTE_STYLES, true)
                ? $style : FormsService::NOTE_STYLE_DEFAULT];
        }
        if ($type === 'lookup') {
            $src = $f['lookup_source'] ?? '';
            if (in_array($src, $lookupSources, true)) {
                // Never portal_lookup: exposing records to customers is a decision
                // for whoever owns the form, not one the generator may make.
                $clean['config'] = ['lookup_source' => $src];
            } else {
                $clean['field_type'] = 'text';
            }
        }
        $cleanFields[] = $clean;
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
