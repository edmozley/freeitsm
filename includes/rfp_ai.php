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
    $body = json_encode([
        'model'       => $settings['model'],
        'max_tokens'  => $opts['max_tokens'],
        'temperature' => $opts['temperature'],
        'system'      => $opts['system'],
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
