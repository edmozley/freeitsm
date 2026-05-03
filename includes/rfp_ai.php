<?php
/**
 * RFP Builder AI helper.
 *
 * One module to call out to either Anthropic or OpenAI for the
 * extraction / consolidation / generation prompts the RFP Builder
 * uses. Reads provider, model, encrypted API key and SSL-verify
 * preference from system_settings; logs every call to
 * rfp_processing_log; retries on 429 / 5xx / network errors with
 * exponential backoff.
 *
 * Public surface (others can grow as more passes land):
 *   rfpAiExtractRequirements(PDO, rfpId, documentId, rawText, departmentName)
 *   rfpAiConsolidate(PDO, rfpId, extractedRows)
 *   rfpAiGenerateSectionStreaming(PDO, rfpId, categoryId, eventCallback)
 *
 * Lower-level helpers (rfpAiCall, rfpAiGetSettings) are reusable for
 * the consolidation / generation passes coming in Phases 3 and 4.
 */

require_once __DIR__ . '/encryption.php';

const RFP_AI_RETRY_MAX           = 3;
const RFP_AI_RETRY_BACKOFF_MS    = 2000;
const RFP_AI_HTTP_TIMEOUT        = 120;
const RFP_AI_VALID_PROVIDERS     = ['anthropic', 'openai'];

/**
 * Load the configured provider, model, decrypted API key and
 * SSL-verify flag. Throws if any required piece is missing — callers
 * surface that as a user-friendly "configure under Contracts → Settings → RFP AI" message.
 */
function rfpAiGetSettings(PDO $conn): array
{
    $stmt = $conn->prepare(
        "SELECT setting_key, setting_value FROM system_settings
          WHERE setting_key IN ('rfp_ai_provider','rfp_ai_api_key','rfp_ai_model','rfp_ai_verify_ssl')"
    );
    $stmt->execute();

    $values = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $value = $row['setting_value'];
        if (isEncryptedSettingKey($row['setting_key'])) {
            $value = decryptValue($value);
        }
        $values[$row['setting_key']] = $value;
    }

    $provider  = $values['rfp_ai_provider']   ?? '';
    $apiKey    = $values['rfp_ai_api_key']    ?? '';
    $model     = $values['rfp_ai_model']      ?? '';
    $verifySsl = ($values['rfp_ai_verify_ssl'] ?? '1') !== '0';

    if (!in_array($provider, RFP_AI_VALID_PROVIDERS, true)) {
        throw new RuntimeException('AI provider not configured. Set it under Contracts → Settings → RFP AI.');
    }
    if ($apiKey === '') {
        throw new RuntimeException('AI API key not configured. Set it under Contracts → Settings → RFP AI.');
    }
    if ($model === '') {
        throw new RuntimeException('AI model not configured. Set it under Contracts → Settings → RFP AI.');
    }

    return [
        'provider'   => $provider,
        'api_key'    => $apiKey,
        'model'      => $model,
        'verify_ssl' => $verifySsl,
    ];
}

/**
 * Generic provider-agnostic call.
 *
 * Options:
 *   system      string  System prompt
 *   user        string  User prompt
 *   max_tokens  int     Default 4096
 *   temperature float   Default 0.0 (deterministic)
 *
 * Returns:
 *   content       string  Text returned by the model
 *   tokens_in     int     Input tokens billed
 *   tokens_out    int     Output tokens billed
 *   cache_read    int|null  Cached input tokens read (Anthropic / OpenAI cached_tokens)
 *   cache_write   int|null  Cached input tokens written (Anthropic only)
 *   provider      string
 *   model         string
 *   duration_ms   int
 */
function rfpAiCall(PDO $conn, array $opts): array
{
    $settings = rfpAiGetSettings($conn);

    $opts['max_tokens']  = $opts['max_tokens']  ?? 4096;
    $opts['temperature'] = $opts['temperature'] ?? 0.0;

    $start = microtime(true);
    $result = $settings['provider'] === 'anthropic'
        ? rfpAiCallAnthropic($settings, $opts)
        : rfpAiCallOpenAI($settings, $opts);

    $result['duration_ms'] = (int)((microtime(true) - $start) * 1000);
    $result['provider']    = $settings['provider'];
    $result['model']       = $settings['model'];
    return $result;
}

function rfpAiCallAnthropic(array $settings, array $opts): array
{
    // Wrap the system prompt as a single text block with a cache_control
    // breakpoint so identical system prompts across calls (e.g. extracting
    // 5 docs in a row) reuse a server-side cache for ~5 min — cache reads
    // cost ~10% of normal input tokens. The user message stays uncached
    // since it's the per-doc raw text and changes every call.
    $body = json_encode([
        'model'       => $settings['model'],
        'max_tokens'  => $opts['max_tokens'],
        'temperature' => $opts['temperature'],
        'system'      => [
            [
                'type'          => 'text',
                'text'          => $opts['system'],
                'cache_control' => ['type' => 'ephemeral'],
            ],
        ],
        'messages'    => [['role' => 'user', 'content' => $opts['user']]],
    ]);

    $headers = [
        'x-api-key: ' . $settings['api_key'],
        'anthropic-version: 2023-06-01',
        'content-type: application/json',
    ];

    $resp = rfpAiHttpPostWithRetry('https://api.anthropic.com/v1/messages', $headers, $body, $settings['verify_ssl']);
    $data = $resp['data'];

    $text = '';
    foreach (($data['content'] ?? []) as $block) {
        if (($block['type'] ?? '') === 'text') {
            $text .= $block['text'];
        }
    }

    return [
        'content'     => trim($text),
        'tokens_in'   => $data['usage']['input_tokens']               ?? null,
        'tokens_out'  => $data['usage']['output_tokens']              ?? null,
        'cache_read'  => $data['usage']['cache_read_input_tokens']    ?? null,
        'cache_write' => $data['usage']['cache_creation_input_tokens'] ?? null,
    ];
}

function rfpAiCallOpenAI(array $settings, array $opts): array
{
    $body = json_encode([
        'model'       => $settings['model'],
        'max_tokens'  => $opts['max_tokens'],
        'temperature' => $opts['temperature'],
        'messages'    => [
            ['role' => 'system', 'content' => $opts['system']],
            ['role' => 'user',   'content' => $opts['user']],
        ],
    ]);

    $headers = [
        'Authorization: Bearer ' . $settings['api_key'],
        'content-type: application/json',
    ];

    $resp = rfpAiHttpPostWithRetry('https://api.openai.com/v1/chat/completions', $headers, $body, $settings['verify_ssl']);
    $data = $resp['data'];

    $text = $data['choices'][0]['message']['content'] ?? '';

    return [
        'content'     => trim($text),
        'tokens_in'   => $data['usage']['prompt_tokens']     ?? null,
        'tokens_out'  => $data['usage']['completion_tokens'] ?? null,
        'cache_read'  => $data['usage']['prompt_tokens_details']['cached_tokens'] ?? null,
        'cache_write' => null, // OpenAI doesn't expose this separately
    ];
}

/**
 * Streaming Anthropic call. Receives token deltas as they arrive and
 * forwards them to a caller-supplied callback so the browser can show
 * live output (claude.ai-style) instead of waiting for the whole
 * response. Single attempt, no retry — once we've started streaming
 * to the browser we can't replay from the start.
 *
 * The callback signature is fn(string $eventType, array $data): void
 * where $eventType is one of:
 *   'text'   — { delta: string }    per-token text chunk
 *   'usage'  — { tokens_in, tokens_out, cache_read, cache_write }
 *
 * Returns:
 *   content     string  Full accumulated text
 *   tokens_in   int|null
 *   tokens_out  int|null
 *   cache_read  int|null
 *   cache_write int|null
 *   provider, model, duration_ms
 *
 * Throws on non-2xx response or stream-level errors. The caller
 * should catch and emit its own error event to the browser.
 *
 * Only Anthropic supported today — OpenAI streaming has a different
 * SSE shape and callers should pre-check the configured provider.
 */
function rfpAiCallAnthropicStreaming(PDO $conn, array $opts, callable $onEvent): array
{
    $settings = rfpAiGetSettings($conn);
    if ($settings['provider'] !== 'anthropic') {
        throw new RuntimeException('Streaming is currently only supported with the Anthropic provider. Switch under Contracts → Settings → RFP AI.');
    }

    $opts['max_tokens']  = $opts['max_tokens']  ?? 4096;
    $opts['temperature'] = $opts['temperature'] ?? 0.0;

    $body = json_encode([
        'model'       => $settings['model'],
        'max_tokens'  => $opts['max_tokens'],
        'temperature' => $opts['temperature'],
        'stream'      => true,
        'system'      => [
            [
                'type'          => 'text',
                'text'          => $opts['system'],
                'cache_control' => ['type' => 'ephemeral'],
            ],
        ],
        'messages'    => [['role' => 'user', 'content' => $opts['user']]],
    ]);

    $headers = [
        'x-api-key: ' . $settings['api_key'],
        'anthropic-version: 2023-06-01',
        'content-type: application/json',
        'accept: text/event-stream',
    ];

    // Streaming state. The cURL write callback is invoked for each
    // chunk of bytes; we hold a buffer because event boundaries may
    // not align with chunk boundaries.
    $accumulated = '';   // full assistant text
    $sseBuffer   = '';   // raw SSE bytes not yet split into events
    $usage       = [
        'tokens_in'   => null,
        'tokens_out'  => null,
        'cache_read'  => null,
        'cache_write' => null,
    ];
    $streamError = null;
    $httpStatus  = 0;

    $start = microtime(true);

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_RETURNTRANSFER => false, // we handle the body via WRITEFUNCTION
        CURLOPT_HEADER         => false,
        // Streaming: no overall timeout, but a generous low-speed safety
        // net so a totally hung connection eventually dies. 60s with no
        // bytes received at all = abort.
        CURLOPT_TIMEOUT        => 0,
        CURLOPT_LOW_SPEED_TIME => 60,
        CURLOPT_LOW_SPEED_LIMIT=> 1,
        CURLOPT_SSL_VERIFYPEER => $settings['verify_ssl'],
        CURLOPT_SSL_VERIFYHOST => $settings['verify_ssl'] ? 2 : 0,
        CURLOPT_WRITEFUNCTION  => function ($ch, $chunk) use (&$sseBuffer, &$accumulated, &$usage, &$streamError, $onEvent) {
            $sseBuffer .= $chunk;
            // SSE events end with a blank line (\n\n). Split there.
            while (($pos = strpos($sseBuffer, "\n\n")) !== false) {
                $eventBlock = substr($sseBuffer, 0, $pos);
                $sseBuffer  = substr($sseBuffer, $pos + 2);

                $eventName = '';
                $dataLine  = '';
                foreach (explode("\n", $eventBlock) as $line) {
                    if (str_starts_with($line, 'event: ')) {
                        $eventName = trim(substr($line, 7));
                    } elseif (str_starts_with($line, 'data: ')) {
                        $dataLine .= substr($line, 6);
                    }
                }
                if ($dataLine === '') continue;

                $payload = json_decode($dataLine, true);
                if (!is_array($payload)) continue;

                switch ($eventName) {
                    case 'content_block_delta':
                        $deltaText = $payload['delta']['text'] ?? '';
                        if ($deltaText !== '') {
                            $accumulated .= $deltaText;
                            $onEvent('text', ['delta' => $deltaText]);
                        }
                        break;
                    case 'message_start':
                        $u = $payload['message']['usage'] ?? [];
                        $usage['tokens_in']   = $u['input_tokens']                ?? $usage['tokens_in'];
                        $usage['cache_read']  = $u['cache_read_input_tokens']     ?? $usage['cache_read'];
                        $usage['cache_write'] = $u['cache_creation_input_tokens'] ?? $usage['cache_write'];
                        $onEvent('usage', $usage);
                        break;
                    case 'message_delta':
                        $u = $payload['usage'] ?? [];
                        if (isset($u['output_tokens'])) {
                            $usage['tokens_out'] = $u['output_tokens'];
                            $onEvent('usage', $usage);
                        }
                        break;
                    case 'error':
                        $streamError = $payload['error']['message'] ?? 'Anthropic stream error';
                        break;
                    // ping / content_block_start / content_block_stop / message_stop — ignore
                }
            }
            return strlen($chunk);
        },
    ]);

    $ok = curl_exec($ch);
    $httpStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr    = curl_error($ch);
    curl_close($ch);

    $duration = (int)((microtime(true) - $start) * 1000);

    if ($ok === false) {
        throw new RuntimeException('Network error: ' . $curlErr);
    }
    if ($httpStatus < 200 || $httpStatus >= 300) {
        // Non-2xx — Anthropic still sends JSON in the body even on
        // streaming requests, but our WRITEFUNCTION discarded it as SSE.
        // The streamError captured above usually has the message;
        // otherwise fall back to the HTTP code.
        throw new RuntimeException($streamError ?: ('HTTP ' . $httpStatus));
    }
    if ($streamError !== null) {
        throw new RuntimeException($streamError);
    }

    return [
        'content'     => trim($accumulated),
        'tokens_in'   => $usage['tokens_in'],
        'tokens_out'  => $usage['tokens_out'],
        'cache_read'  => $usage['cache_read'],
        'cache_write' => $usage['cache_write'],
        'provider'    => 'anthropic',
        'model'       => $settings['model'],
        'duration_ms' => $duration,
    ];
}

/**
 * POST with retry on 429 / 5xx / network errors. Exponential backoff
 * starting at RFP_AI_RETRY_BACKOFF_MS. Throws on final failure.
 */
function rfpAiHttpPostWithRetry(string $url, array $headers, string $body, bool $verifySsl): array
{
    $attempt = 0;
    $lastErr = '';

    while ($attempt < RFP_AI_RETRY_MAX) {
        $attempt++;

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => RFP_AI_HTTP_TIMEOUT,
            CURLOPT_SSL_VERIFYPEER => $verifySsl,
            CURLOPT_SSL_VERIFYHOST => $verifySsl ? 2 : 0,
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($resp === false) {
            $lastErr = 'Network error: ' . $err;
            if ($attempt < RFP_AI_RETRY_MAX) {
                usleep(RFP_AI_RETRY_BACKOFF_MS * 1000 * (2 ** ($attempt - 1)));
                continue;
            }
            throw new RuntimeException($lastErr);
        }

        $data = json_decode($resp, true);

        if ($code >= 200 && $code < 300) {
            return ['code' => $code, 'data' => $data];
        }

        $errMsg  = $data['error']['message'] ?? ('HTTP ' . $code);
        $lastErr = "$errMsg (HTTP $code)";

        // Retry on rate-limits and server errors; everything else is fatal.
        $retryable = ($code === 429 || ($code >= 500 && $code < 600));
        if ($retryable && $attempt < RFP_AI_RETRY_MAX) {
            usleep(RFP_AI_RETRY_BACKOFF_MS * 1000 * (2 ** ($attempt - 1)));
            continue;
        }
        throw new RuntimeException($lastErr);
    }

    throw new RuntimeException('Failed after ' . RFP_AI_RETRY_MAX . ' attempts: ' . $lastErr);
}

/**
 * Insert a row into rfp_processing_log. Silently no-ops if rfp_id is
 * null (the column is NOT NULL in the schema; callers should always
 * have an rfp_id by the time they're calling AI).
 */
function rfpAiLogAction(
    PDO $conn,
    ?int $rfpId,
    ?int $documentId,
    ?int $sectionId,
    string $action,
    string $status,
    ?string $details,
    ?int $tokensIn,
    ?int $tokensOut
): void {
    if ($rfpId === null) return;
    $stmt = $conn->prepare(
        "INSERT INTO rfp_processing_log
            (rfp_id, document_id, section_id, action, status, details, tokens_in, tokens_out)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
    );
    $stmt->execute([$rfpId, $documentId, $sectionId, $action, $status, $details, $tokensIn, $tokensOut]);
}

// ---------------------------------------------------------------
// Pass 1 — Extract (per document)
// ---------------------------------------------------------------

const RFP_AI_EXTRACT_SYSTEM = <<<PROMPT
You are an expert business analyst. Your job is to extract every individual requirement, pain point, challenge, and need from a department's feedback document about a system or service the organisation wants to procure.

Return your response as a JSON array of objects. Each object must have these fields:
- "requirement_text": A clear, concise description of the requirement, pain point, or challenge. Rewrite messy or rambling text into clear professional language but preserve the meaning. Be specific — do not generalise away the detail.
- "requirement_type": One of "requirement", "pain_point", or "challenge".
- "source_quote": The closest matching original text from the document (a brief excerpt of up to about 30 words).
- "confidence": A number from 0.0 to 1.0 indicating how confident you are this is a distinct, valid item worth tracking.

CLASSIFICATION RULES:
- "requirement" describes what the department needs from the new system ("we need...", "the system must...", "it should..."). Most items will fall here.
- "pain_point" describes a current problem with the existing way of working ("we struggle with...", "it's difficult to...", "there is no way to..."). Use this only when the document is explicitly describing something that is broken today.
- "challenge" describes an obstacle, concern or risk about the future change ("we worry about...", "migration will be difficult because...", "we are conscious of...").

EXTRACTION RULES:
- Extract EVERY distinct point. Do not merge or summarise multiple points into one.
- A single bullet point or sentence may yield multiple items if it covers multiple distinct needs.
- Skip background paragraphs, introductions, conclusions, and meta-commentary about the document or the procurement process itself.
- Skip headings on their own (they are typically section titles, not requirements).
- Do not invent items that are not in the source text.
- Return ONLY valid JSON. No markdown code fences. No explanation text. No leading or trailing prose.
PROMPT;

/**
 * Run Pass 1 extraction on a single document and return parsed items.
 * Callers are responsible for inserting the items into
 * rfp_extracted_requirements; this function only calls the AI and
 * logs the call.
 *
 * Returns:
 *   items        array of parsed requirement objects from the AI
 *   tokens_in    int
 *   tokens_out   int
 *   cache_read   int|null
 *   cache_write  int|null
 *   duration_ms  int
 */
function rfpAiExtractRequirements(PDO $conn, int $rfpId, int $documentId, string $rawText, string $departmentName): array
{
    if (trim($rawText) === '') {
        throw new RuntimeException('Document has no text to extract from');
    }

    $userPrompt = "Department: {$departmentName}\n\nDocument content:\n\n{$rawText}";

    try {
        $resp = rfpAiCall($conn, [
            'system'      => RFP_AI_EXTRACT_SYSTEM,
            'user'        => $userPrompt,
            'max_tokens'  => 8192,
            'temperature' => 0.0,
        ]);

        $content = $resp['content'];
        // The system prompt asks for raw JSON, but be tolerant if a fenced
        // block sneaks through (some models really like markdown).
        $content = preg_replace('/^\s*```(?:json)?\s*\r?\n/', '', $content);
        $content = preg_replace('/\r?\n\s*```\s*$/', '', $content);
        $content = trim($content);

        $items = json_decode($content, true);
        if (!is_array($items)) {
            throw new RuntimeException('AI returned invalid JSON: ' . substr($content, 0, 200));
        }

        rfpAiLogAction(
            $conn, $rfpId, $documentId, null,
            'extract', 'success',
            json_encode([
                'item_count'  => count($items),
                'duration_ms' => $resp['duration_ms'],
                'cache_read'  => $resp['cache_read'],
                'cache_write' => $resp['cache_write'],
                'model'       => $resp['model'],
            ]),
            $resp['tokens_in'],
            $resp['tokens_out']
        );

        return [
            'items'       => $items,
            'tokens_in'   => $resp['tokens_in'],
            'tokens_out'  => $resp['tokens_out'],
            'cache_read'  => $resp['cache_read'],
            'cache_write' => $resp['cache_write'],
            'duration_ms' => $resp['duration_ms'],
        ];
    } catch (Throwable $e) {
        rfpAiLogAction(
            $conn, $rfpId, $documentId, null,
            'extract', 'error',
            $e->getMessage(),
            null, null
        );
        throw $e;
    }
}

// ---------------------------------------------------------------
// Pass 2 — Consolidate + Categorise + Detect Conflicts (single call)
// ---------------------------------------------------------------

const RFP_AI_CONSOLIDATE_SYSTEM = <<<PROMPT
You are a senior business analyst working on a Request for Proposal (RFP) for a system or service the organisation wants to procure.

Multiple internal departments have submitted feedback documents. The raw requirements, pain points, and challenges have been extracted from those documents and are presented to you as a flat list, each tagged with a unique numeric ID, the source department, the type, and a verbatim quote from the source document.

Your job is to perform THREE tasks in a single pass and return one JSON object containing all three results.

# TASK 1 — Propose categories

Look across all of the input items and propose a coherent set of categories that organises the requirements logically. Categories should:

- Be specific to the actual content of THIS RFP, not generic procurement headings.
- Cover every input item (every consolidated requirement must fall into a category).
- Number between 8 and 20 — too few and the categories are too broad to be useful; too many and the document fragments.
- Use clear, professional names of 1-4 words each.
- Have a one-sentence description explaining what the category covers.

Examples of good category names: "Identity and access management", "Reporting and dashboards", "Mobile experience", "Data migration", "Vendor support model".

Examples of bad category names: "Functional requirements" (too generic), "Misc" (lazy), "Things the IT department wants" (does not generalise across departments).

# TASK 2 — Consolidate requirements

For every input item, decide:
- Whether it stands alone, or merges with one or more other input items that say the same thing in different words.
- Which of your proposed categories it belongs to.
- What its priority is (critical, high, medium, or low).
- A clean, professional re-statement of the requirement.

Output one row per CONSOLIDATED requirement (not per input item). Multiple input items may collapse into one consolidated row.

CONSOLIDATION RULES:
- Merge items that ask for the same thing, even if the phrasing differs across departments. For example, "we need a way to track licences" plus "the system should manage software entitlements" together become one consolidated row covering software-licence management.
- Do NOT over-merge. If two departments want subtly different things — for example one wants real-time sync and another wants overnight batch — keep them as separate consolidated rows. They may end up flagged as conflicts in Task 3.
- Do NOT merge across types. A requirement, a pain point, and a challenge that all touch the same topic stay as separate consolidated rows.
- Every consolidated row MUST list the source IDs of every input item that fed into it via `source_extracted_ids`. This traceability is essential — the analyst will use it to expand the original verbatim quotes from each source department.
- The consolidated `requirement_text` should be a clean, neutral, professional restatement. Do not editorialise. Do not invent capability the input did not mention.

PRIORITY RULES:
- "critical" — items the organisation cannot operate without; explicit must-haves; compliance-driven items.
- "high" — items asked for by multiple departments, OR single-department items where the impact of not having them is severe.
- "medium" — single-department items with moderate impact; "nice to have" items with strong rationale.
- "low" — items that are clearly optional, future-looking, or stretch goals.

When an item is asked for by multiple departments, lean toward "high" or "critical" unless the item itself is minor.

For each consolidated row, write a 1-2 sentence `ai_rationale` explaining why those source items merged together, or for stand-alone rows, why nothing else merged in. This is the audit trail for the analyst reviewing your output, so be specific.

# TASK 3 — Detect conflicts

Look at your set of consolidated requirements and identify pairs that contradict each other. A conflict is a genuine logical incompatibility, not merely a priority difference or a phrasing overlap.

EXAMPLES OF CONFLICTS:
- Department A wants on-premise hosting only; Department B wants cloud-only.
- Department A wants synchronous real-time integration; Department B wants asynchronous batch updates.
- Department A wants role-based access controls; Department B wants attribute-based access controls.
- Department A wants strict audit retention of seven years; Department B wants automatic deletion after twelve months.

EXAMPLES OF NON-CONFLICTS — do NOT flag these:
- Two departments asking for slightly different reporting features (these are complementary, not conflicting).
- Two departments emphasising different priorities (procurement decides priorities, not technology).
- Differences in language or framing of similar requirements (these should have been merged in Task 2).

For each conflict, give a clear 1-2 sentence explanation of WHY the two consolidated requirements cannot both be true at the same time.

# OUTPUT FORMAT

Return EXACTLY ONE JSON object with this shape:

{
  "categories": [
    { "name": "...", "description": "...", "sort_order": 1 }
  ],
  "consolidated_requirements": [
    {
      "requirement_text": "...",
      "type": "requirement",
      "category_index": 0,
      "priority": "high",
      "source_extracted_ids": [12, 47, 91],
      "ai_rationale": "..."
    }
  ],
  "conflicts": [
    {
      "consolidated_a_index": 3,
      "consolidated_b_index": 17,
      "explanation": "..."
    }
  ]
}

CRITICAL FORMAT RULES:
- `type` must be exactly one of "requirement", "pain_point", or "challenge" — match the type of the source items.
- `category_index` is the 0-based index into your `categories` array.
- `consolidated_a_index` and `consolidated_b_index` are 0-based indices into your `consolidated_requirements` array.
- `source_extracted_ids` are the EXACT numeric IDs from the input list — never invent or modify these.
- `sort_order` should reflect the logical reading order of the categories in the eventual RFP document, starting at 1.
- Every input item must appear in `source_extracted_ids` of at least one consolidated requirement — no input item is left orphaned.
- If there are no conflicts, return `"conflicts": []` — do not omit the key.
- Return ONLY valid JSON. No markdown code fences. No explanation prose. No leading or trailing text.
PROMPT;

/**
 * Run Pass 2 (consolidate + categorise + detect conflicts) for an RFP.
 *
 * Input is an array of associative arrays, each representing one row
 * from rfp_extracted_requirements joined with department info:
 *   id, requirement_text, requirement_type, source_quote, department_name
 *
 * Returns the parsed JSON structure plus token usage. Caller is
 * responsible for inserting the categories / consolidated rows /
 * sources / conflicts; this function only calls the AI and logs.
 *
 * Output:
 *   payload      array  Parsed { categories, consolidated_requirements, conflicts }
 *   tokens_in    int
 *   tokens_out   int
 *   cache_read   int|null
 *   cache_write  int|null
 *   duration_ms  int
 */
function rfpAiConsolidate(PDO $conn, int $rfpId, array $extractedRows): array
{
    if (empty($extractedRows)) {
        throw new RuntimeException('No extracted requirements to consolidate');
    }

    // Build the user prompt as a flat list. Each row is one block so the
    // model has clear separators between input items.
    $lines = [];
    foreach ($extractedRows as $row) {
        $id   = (int)$row['id'];
        $dept = $row['department_name'] ?: 'Unassigned';
        $type = $row['requirement_type'] ?? 'requirement';
        $text = trim($row['requirement_text'] ?? '');
        $quote = trim($row['source_quote'] ?? '');

        $lines[] = "[ID {$id}] [Department: {$dept}] [Type: {$type}]\n"
                 . "Text: {$text}\n"
                 . ($quote !== '' ? "Source quote: \"{$quote}\"\n" : '');
    }
    $userPrompt = "INPUT ITEMS — " . count($extractedRows) . " total:\n\n" . implode("\n", $lines);

    try {
        $resp = rfpAiCall($conn, [
            'system'      => RFP_AI_CONSOLIDATE_SYSTEM,
            'user'        => $userPrompt,
            // The output is a single big JSON object — categories +
            // consolidated rows + conflicts. Allow plenty of headroom.
            'max_tokens'  => 16000,
            'temperature' => 0.0,
        ]);

        $content = $resp['content'];
        // Tolerate fenced JSON even though the prompt asks for raw.
        $content = preg_replace('/^\s*```(?:json)?\s*\r?\n/', '', $content);
        $content = preg_replace('/\r?\n\s*```\s*$/', '', $content);
        $content = trim($content);

        $payload = json_decode($content, true);
        if (!is_array($payload)) {
            throw new RuntimeException('AI returned invalid JSON: ' . substr($content, 0, 200));
        }

        // Defensive shape check — every key must be present (even empty arrays).
        foreach (['categories', 'consolidated_requirements', 'conflicts'] as $key) {
            if (!isset($payload[$key]) || !is_array($payload[$key])) {
                throw new RuntimeException('AI response missing or non-array key: ' . $key);
            }
        }

        rfpAiLogAction(
            $conn, $rfpId, null, null,
            'consolidate', 'success',
            json_encode([
                'input_count'        => count($extractedRows),
                'category_count'     => count($payload['categories']),
                'consolidated_count' => count($payload['consolidated_requirements']),
                'conflict_count'     => count($payload['conflicts']),
                'duration_ms'        => $resp['duration_ms'],
                'cache_read'         => $resp['cache_read'],
                'cache_write'        => $resp['cache_write'],
                'model'              => $resp['model'],
            ]),
            $resp['tokens_in'],
            $resp['tokens_out']
        );

        return [
            'payload'     => $payload,
            'tokens_in'   => $resp['tokens_in'],
            'tokens_out'  => $resp['tokens_out'],
            'cache_read'  => $resp['cache_read'],
            'cache_write' => $resp['cache_write'],
            'duration_ms' => $resp['duration_ms'],
        ];
    } catch (Throwable $e) {
        rfpAiLogAction(
            $conn, $rfpId, null, null,
            'consolidate', 'error',
            $e->getMessage(),
            null, null
        );
        throw $e;
    }
}

/**
 * Streaming variant of Pass 2 consolidation. Behaves like
 * rfpAiConsolidate() but forwards token deltas + usage updates to a
 * caller-supplied callback so the browser can render live output.
 *
 * The callback signature is fn(string $eventType, array $data): void
 * forwarded straight from rfpAiCallAnthropicStreaming(). The caller
 * (typically an SSE endpoint) is responsible for relaying these as
 * SSE events to the browser.
 *
 * Returns the same shape as rfpAiConsolidate(): { payload, tokens_in,
 * tokens_out, cache_read, cache_write, duration_ms }.
 */
function rfpAiConsolidateStreaming(PDO $conn, int $rfpId, array $extractedRows, callable $onEvent): array
{
    if (empty($extractedRows)) {
        throw new RuntimeException('No extracted requirements to consolidate');
    }

    $lines = [];
    foreach ($extractedRows as $row) {
        $id    = (int)$row['id'];
        $dept  = $row['department_name'] ?: 'Unassigned';
        $type  = $row['requirement_type'] ?? 'requirement';
        $text  = trim($row['requirement_text'] ?? '');
        $quote = trim($row['source_quote'] ?? '');

        $lines[] = "[ID {$id}] [Department: {$dept}] [Type: {$type}]\n"
                 . "Text: {$text}\n"
                 . ($quote !== '' ? "Source quote: \"{$quote}\"\n" : '');
    }
    $userPrompt = "INPUT ITEMS — " . count($extractedRows) . " total:\n\n" . implode("\n", $lines);

    try {
        $resp = rfpAiCallAnthropicStreaming($conn, [
            'system'      => RFP_AI_CONSOLIDATE_SYSTEM,
            'user'        => $userPrompt,
            'max_tokens'  => 16000,
            'temperature' => 0.0,
        ], $onEvent);

        $content = $resp['content'];
        $content = preg_replace('/^\s*```(?:json)?\s*\r?\n/', '', $content);
        $content = preg_replace('/\r?\n\s*```\s*$/', '', $content);
        $content = trim($content);

        $payload = json_decode($content, true);
        if (!is_array($payload)) {
            throw new RuntimeException('AI returned invalid JSON: ' . substr($content, 0, 200));
        }
        foreach (['categories', 'consolidated_requirements', 'conflicts'] as $key) {
            if (!isset($payload[$key]) || !is_array($payload[$key])) {
                throw new RuntimeException('AI response missing or non-array key: ' . $key);
            }
        }

        rfpAiLogAction(
            $conn, $rfpId, null, null,
            'consolidate', 'success',
            json_encode([
                'input_count'        => count($extractedRows),
                'category_count'     => count($payload['categories']),
                'consolidated_count' => count($payload['consolidated_requirements']),
                'conflict_count'     => count($payload['conflicts']),
                'duration_ms'        => $resp['duration_ms'],
                'cache_read'         => $resp['cache_read'],
                'cache_write'        => $resp['cache_write'],
                'model'              => $resp['model'],
                'streamed'           => true,
            ]),
            $resp['tokens_in'],
            $resp['tokens_out']
        );

        return [
            'payload'     => $payload,
            'tokens_in'   => $resp['tokens_in'],
            'tokens_out'  => $resp['tokens_out'],
            'cache_read'  => $resp['cache_read'],
            'cache_write' => $resp['cache_write'],
            'duration_ms' => $resp['duration_ms'],
        ];
    } catch (Throwable $e) {
        rfpAiLogAction(
            $conn, $rfpId, null, null,
            'consolidate', 'error',
            $e->getMessage(),
            null, null
        );
        throw $e;
    }
}

// ---------------------------------------------------------------
// Pass 3 — Generate Section (per category, streaming)
// ---------------------------------------------------------------

const RFP_AI_GENERATE_SYSTEM = <<<PROMPT
You are an expert business analyst writing a section of a Request for Proposal (RFP) document. The organisation is procuring a new system or service, and your job is to translate raw consolidated requirements into clear, professional prose suitable for a formal procurement document that will be sent to suppliers.

The user message will tell you which section you are writing (its title and description) and provide the consolidated requirements that belong to that section, each tagged with its priority, type, and the contributing departments. Your job is to write the section's body content.

# OUTPUT FORMAT

Return ONLY the HTML for this section's body content. Do not include the section title (the document layer renders that separately). Do not wrap in <html>, <body>, or any page-level scaffolding. Use:

- <h3> for sub-headings within the section
- <h4> sparingly for sub-sub-headings if the structure genuinely needs them
- <p> for narrative paragraphs
- <ul> and <li> for bulleted lists of requirements or pain points
- <ol> and <li> for ordered/numbered lists where order genuinely matters
- <strong> and <em> for emphasis used sparingly
- <table> with <thead> and <tbody> for tabular data only when the content is genuinely tabular (e.g. comparison rows, parameter/value listings)

Do NOT use:
- <h1> or <h2> — reserved for the document and section title levels above this one
- Inline styles (style="..."), CSS classes (class="..."), or data-* attributes
- <div>, <span>, or any non-semantic wrappers
- Markdown of any kind — output must be valid HTML
- Code fences, leading prose, trailing prose — return only the HTML, nothing else

# SECTION STRUCTURE

Each section should typically follow this pattern. Adjust as needed for the content — do not force every sub-heading if there is no material to put under it:

1. **Overview paragraph** — one paragraph at the top (no sub-heading) summarising what this section covers and why it matters to the organisation. Two or three sentences.

2. **Requirements** — under an <h3>Requirements</h3> sub-heading, a <ul> listing the consolidated requirements as professionally re-stated bullet points. Critical and high-priority items first, then medium, then low — but do NOT state priority levels in the output text (the document layer handles priority visually elsewhere). Each list item ends with a parenthetical naming the contributing departments, e.g. "(IT, Finance)".

3. **Current pain points** — under an <h3>Current pain points</h3> sub-heading, a <ul> describing what is broken in the current way of working. Brief — these contextualise the requirements above. Include this sub-heading only if the input has any items typed pain_point.

4. **Challenges and concerns** — under an <h3>Challenges and concerns</h3> sub-heading, a <ul> noting the obstacles, risks, or change-management concerns the organisation has flagged for the migration or future state. Include this sub-heading only if the input has any items typed challenge.

5. **Department-specific considerations** — under an <h3>Department-specific considerations</h3> sub-heading, a short paragraph or list noting any items unique to a single department that are unusual or niche enough to call out separately. Include this only if the section genuinely has such items — otherwise omit.

# RULES

- Every consolidated requirement, pain point, and challenge from the input MUST be addressed somewhere in the output. Do not omit any.
- Do not introduce capability or constraints that the input does not mention. Do not editorialise.
- Do not state priority levels in the output text — departments yes, priorities no.
- Use formal, neutral, professional language suitable for a senior procurement audience. No marketing copy, no sales language, no first-person plurals beyond an occasional "the organisation".
- British English spelling unless the user style guide says otherwise.
- Tables only when the data is genuinely tabular. Most RFP requirements are list-of-bullets, not table rows.
- If the style guide below conflicts with anything above, the style guide wins.
PROMPT;

/**
 * Run Pass 3 (generate one section, streaming) for a single category.
 *
 * Pulls the consolidated requirements for the category, joins out to
 * the department info via the M:N source-link table, builds the user
 * prompt, and streams the AI response. The caller (typically an SSE
 * endpoint) is responsible for relaying the streaming events to the
 * browser via the supplied callback.
 *
 * Returns:
 *   content       string  Full generated HTML
 *   tokens_in     int|null
 *   tokens_out    int|null
 *   cache_read    int|null
 *   cache_write   int|null
 *   duration_ms   int
 *   inputs_hash   string  md5 of the input requirements (used for skip-if-unchanged)
 *
 * Throws if the category has no consolidated requirements assigned.
 */
function rfpAiGenerateSectionStreaming(PDO $conn, int $rfpId, int $categoryId, callable $onEvent): array
{
    $catStmt = $conn->prepare(
        "SELECT id, name, description FROM rfp_categories WHERE id = ? AND rfp_id = ?"
    );
    $catStmt->execute([$categoryId, $rfpId]);
    $category = $catStmt->fetch(PDO::FETCH_ASSOC);
    if (!$category) {
        throw new RuntimeException('Category not found for this RFP');
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
        throw new RuntimeException('No consolidated requirements in this category');
    }

    // Department contributions per consolidated row, traversed via the
    // M:N source link table.
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

    // Style guide — per-RFP override, falling back to a sensible default.
    // The system_settings-level default is a Phase 6 polish task.
    $sgStmt = $conn->prepare("SELECT style_guide FROM rfps WHERE id = ?");
    $sgStmt->execute([$rfpId]);
    $rfpStyle = $sgStmt->fetchColumn();
    $styleGuide = (is_string($rfpStyle) && trim($rfpStyle) !== '')
        ? trim($rfpStyle)
        : "Default style: clear, formal, neutral. British English. Active voice where natural. No marketing language. Keep paragraphs short — three sentences maximum where possible. Avoid jargon; spell out acronyms on first use within a section.";

    // System prompt = static instructions + the style guide. Both get
    // cached together so re-running on more categories within the same
    // RFP reuses the cached prefix at ~10% of input-token cost.
    $systemPrompt = RFP_AI_GENERATE_SYSTEM . "\n\n# STYLE GUIDE FOR THIS RFP\n\n" . $styleGuide;

    $lines = [];
    foreach ($requirements as $req) {
        $rid     = (int)$req['id'];
        $depts   = $deptsByReq[$rid] ?? [];
        $deptStr = !empty($depts) ? '[Departments: ' . implode(', ', $depts) . ']' : '[Departments: none linked]';
        $lines[] = "[Priority: {$req['priority']}] [Type: {$req['requirement_type']}] {$deptStr}\n"
                 . trim($req['requirement_text'] ?? '');
    }

    $catName = trim($category['name']);
    $catDesc = trim($category['description'] ?? '');

    $userPrompt = "Section title: {$catName}\n";
    if ($catDesc !== '') {
        $userPrompt .= "Category description: {$catDesc}\n";
    }
    $userPrompt .= "\nWrite the body of the \"{$catName}\" section using these consolidated requirements:\n\n"
                . implode("\n\n", $lines);

    // md5 of the input requirements + style guide. Used by the caller
    // to skip regeneration if nothing has changed since last run.
    $inputsHash = md5(serialize([
        'cat'         => $catName,
        'desc'        => $catDesc,
        'requirements' => array_map(fn($r) => [
            'id'       => (int)$r['id'],
            'text'     => trim($r['requirement_text']),
            'type'     => $r['requirement_type'],
            'priority' => $r['priority'],
            'depts'    => $deptsByReq[(int)$r['id']] ?? [],
        ], $requirements),
        'style' => $styleGuide,
    ]));

    try {
        $resp = rfpAiCallAnthropicStreaming($conn, [
            'system'      => $systemPrompt,
            'user'        => $userPrompt,
            'max_tokens'  => 8000,
            'temperature' => 0.2,
        ], $onEvent);

        $content = $resp['content'];
        $content = preg_replace('/^\s*```(?:html)?\s*\r?\n/', '', $content);
        $content = preg_replace('/\r?\n\s*```\s*$/', '', $content);
        $content = trim($content);

        rfpAiLogAction(
            $conn, $rfpId, null, null,
            'generate_section', 'success',
            json_encode([
                'category_id'   => $categoryId,
                'category_name' => $catName,
                'req_count'     => count($requirements),
                'duration_ms'   => $resp['duration_ms'],
                'cache_read'    => $resp['cache_read'],
                'cache_write'   => $resp['cache_write'],
                'model'         => $resp['model'],
                'inputs_hash'   => $inputsHash,
            ]),
            $resp['tokens_in'],
            $resp['tokens_out']
        );

        return [
            'content'     => $content,
            'tokens_in'   => $resp['tokens_in'],
            'tokens_out'  => $resp['tokens_out'],
            'cache_read'  => $resp['cache_read'],
            'cache_write' => $resp['cache_write'],
            'duration_ms' => $resp['duration_ms'],
            'inputs_hash' => $inputsHash,
        ];
    } catch (Throwable $e) {
        rfpAiLogAction(
            $conn, $rfpId, null, null,
            'generate_section', 'error',
            $e->getMessage(),
            null, null
        );
        throw $e;
    }
}
