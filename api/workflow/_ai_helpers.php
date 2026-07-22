<?php
/**
 * Workflows AI helpers — Stage 3 + Settings (#343).
 *
 * The co-author turns a plain-English description into a structured workflow
 * proposal (trigger + conditions + actions) that the editor applies to the
 * canvas. Provider-aware: supports Anthropic (Claude) and OpenAI (GPT) so an
 * install can pick whichever it has an account with.
 *
 * Per-module billing: the workflow co-author uses its OWN `workflow_ai_*`
 * keys in `system_settings`, not the CMDB ones. Configured under
 * **Workflow → Settings → AI**. The API key is encrypted at rest and masked
 * when returned to the client (same pattern RFP / Tickets / CMDB use).
 *
 * Public surface:
 *   loadWorkflowAiConfig(PDO)        -> ['provider', 'model', 'api_key', 'verify_ssl']
 *   callWorkflowAi($cfg, $sys, $msg) -> string (the assistant's reply text)
 *   parseClaudeJson($text)           -> array (robust JSON extractor)
 *
 * Lower-level provider drivers:
 *   wfCallAnthropic(...)
 *   wfCallOpenAI(...)
 */

require_once __DIR__ . '/../../includes/encryption.php';
require_once __DIR__ . '/../../includes/ai_settings.php';

const WORKFLOW_AI_VALID_PROVIDERS = ['anthropic', 'openai'];

/**
 * Default model per provider — used when nothing is saved yet, and as a
 * sanity fallback if the saved value is empty.
 */
const WORKFLOW_AI_DEFAULT_MODEL = [
    'anthropic' => 'claude-sonnet-4-6',
    'openai'    => 'gpt-4o',
];

/**
 * Suggested model lists — surfaced as <datalist> hints on the settings page
 * but the user can paste any provider-supported model id.
 */
const WORKFLOW_AI_MODEL_OPTIONS = [
    'anthropic' => [
        ['id' => 'claude-opus-4-7',           'label' => 'Opus 4.7 — most capable'],
        ['id' => 'claude-sonnet-4-6',         'label' => 'Sonnet 4.6 — recommended (best balance)'],
        ['id' => 'claude-haiku-4-5-20251001', 'label' => 'Haiku 4.5 — fastest and cheapest'],
    ],
    'openai' => [
        ['id' => 'gpt-4.1',     'label' => 'GPT-4.1 — most capable'],
        ['id' => 'gpt-4o',      'label' => 'GPT-4o — recommended default'],
        ['id' => 'gpt-4o-mini', 'label' => 'GPT-4o mini — fastest and cheapest'],
    ],
];

/**
 * Resolve the effective SSL_VERIFYPEER flag for an outbound curl call.
 *
 * The global `SSL_VERIFY_PEER` constant from `config.php` acts as a
 * **kill switch** — when set to false (the default for dev installs that
 * don't have a CA bundle wired into php.ini), all outbound HTTPS skips
 * verification regardless of any per-form toggle, otherwise the user would
 * have to remember to flip the toggle for every module's AI key test.
 *
 * In production set SSL_VERIFY_PEER = true in config.php and use the
 * per-form toggle only when you genuinely need to opt out of verification
 * for a single integration (e.g. corporate inspecting proxy with a
 * self-signed cert).
 */
function workflowEffectiveSslVerify(bool $perCallVerify): bool
{
    $global = defined('SSL_VERIFY_PEER') ? (bool)SSL_VERIFY_PEER : true;
    return $global && $perCallVerify;
}

/**
 * Load the workflow-specific AI settings from `system_settings`. Throws if
 * no key is configured so the caller can surface "configure your AI provider
 * under Workflow → Settings → AI Integration first" to the user.
 */
function loadWorkflowAiConfig(PDO $conn): array
{
    // Provider / model / key / verify_ssl now come from the shared building
    // block (ns=workflow_ai), which adds OpenRouter alongside Anthropic/OpenAI.
    $cfg = aiSettingsLoad($conn, 'workflow_ai');
    if (($cfg['api_key'] ?? '') === '') {
        throw new Exception('AI co-author is not configured. Set your provider, model and API key under Workflow → Settings → AI Integration.');
    }
    return $cfg;
}

/**
 * Provider-agnostic one-shot call via the shared client (Anthropic / OpenAI /
 * OpenRouter). Returns the assistant's response text (a plain string).
 */
function callWorkflowAi(array $cfg, string $systemPrompt, string $userMessage, int $maxTokens = 2500): string
{
    $r = aiProviderChat($cfg, [
        'system'     => $systemPrompt,
        'user'       => $userMessage,
        'max_tokens' => $maxTokens,
    ]);
    return (string)($r['content'] ?? '');
}

// ---------------------------------------------------------------------
//  Provider drivers
// ---------------------------------------------------------------------

function wfCallAnthropic(array $cfg, string $systemPrompt, string $userMessage, int $maxTokens): string
{
    $body = json_encode([
        'model'      => $cfg['model'],
        'max_tokens' => $maxTokens,
        'system'     => $systemPrompt,
        'messages'   => [['role' => 'user', 'content' => $userMessage]],
    ]);

    $verifyPeer = workflowEffectiveSslVerify((bool)$cfg['verify_ssl']);
    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_HTTPHEADER     => [
            'x-api-key: ' . $cfg['api_key'],
            'anthropic-version: 2023-06-01',
            'content-type: application/json',
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 60,
    ]);
    sslApplyCurl($ch);
    $resp = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($resp === false) throw new Exception('Network error talking to Anthropic: ' . $err);
    $data = json_decode($resp, true);
    if ($http !== 200) {
        $msg = $data['error']['message'] ?? ('HTTP ' . $http);
        throw new Exception('Anthropic error: ' . $msg);
    }

    $text = '';
    foreach (($data['content'] ?? []) as $b) {
        if (($b['type'] ?? '') === 'text') $text .= ($b['text'] ?? '');
    }
    return trim($text);
}

function wfCallOpenAI(array $cfg, string $systemPrompt, string $userMessage, int $maxTokens): string
{
    $body = json_encode([
        'model'      => $cfg['model'],
        'max_tokens' => $maxTokens,
        'messages'   => [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user',   'content' => $userMessage],
        ],
    ]);

    $verifyPeer = workflowEffectiveSslVerify((bool)$cfg['verify_ssl']);
    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $cfg['api_key'],
            'content-type: application/json',
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 60,
    ]);
    sslApplyCurl($ch);
    $resp = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($resp === false) throw new Exception('Network error talking to OpenAI: ' . $err);
    $data = json_decode($resp, true);
    if ($http !== 200) {
        $msg = $data['error']['message'] ?? ('HTTP ' . $http);
        throw new Exception('OpenAI error: ' . $msg);
    }

    return trim((string)($data['choices'][0]['message']['content'] ?? ''));
}

// ---------------------------------------------------------------------
//  Robust JSON extractor — strips ```json fences, finds first { or [
// ---------------------------------------------------------------------

function parseClaudeJson(string $text): array
{
    $text = preg_replace('/^```(?:json)?\s*|\s*```$/m', '', $text);
    $text = trim($text);
    $start = strcspn($text, '{[');
    if ($start === strlen($text)) {
        throw new Exception('AI did not return JSON');
    }
    $text = substr($text, $start);
    $decoded = json_decode($text, true);
    if (!is_array($decoded)) {
        throw new Exception('Could not parse AI JSON response');
    }
    return $decoded;
}
