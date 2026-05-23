<?php
/**
 * Workflows AI helper — Stage 3 (AI co-author).
 *
 * The co-author turns a plain-English description into a structured workflow
 * proposal (trigger + conditions + actions) that the editor can apply to
 * the canvas.
 *
 * For Stage 3 MVP we reuse the CMDB AI API key + model preference rather than
 * standing up a workflow-specific settings page. Rationale: an install that
 * already has any Claude-powered FreeITSM feature configured (CMDB AI, Ask AI,
 * Reply Cleanup, etc.) almost always wants the workflow co-author to "just
 * work" using the same Anthropic account. A dedicated `workflow_ai_*` block
 * in `system_settings` is planned but deferred until there's a real reason
 * to split them (different model per feature, separate billing scopes, etc.).
 *
 * Public surface:
 *   loadWorkflowAiConfig(PDO) -> ['api_key', 'model']
 *   callAnthropicJson(cfg, system, user, maxTokens=2500) -> array
 *   parseClaudeJson(text) -> array
 */

require_once __DIR__ . '/../../includes/encryption.php';

const WORKFLOW_AI_VALID_MODELS = [
    'claude-haiku-4-5-20251001',
    'claude-sonnet-4-6',
    'claude-opus-4-7',
];

/**
 * Load the Anthropic config used by the workflow co-author. Reads the same
 * `cmdb_ai_api_key` + `cmdb_ai_model` keys as the CMDB AI features so installs
 * only need to configure once.
 *
 * Throws if no key is set so the caller can surface "configure your Anthropic
 * key in CMDB → Settings → AI Integration first" to the user.
 */
function loadWorkflowAiConfig(PDO $conn): array
{
    $stmt = $conn->prepare(
        "SELECT setting_key, setting_value FROM system_settings
          WHERE setting_key IN ('cmdb_ai_api_key', 'cmdb_ai_model')"
    );
    $stmt->execute();

    $cfg = ['api_key' => '', 'model' => 'claude-sonnet-4-6'];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $key = $row['setting_key'];
        $val = $row['setting_value'];
        if ($key === 'cmdb_ai_api_key') {
            $cfg['api_key'] = decryptValue($val) ?? '';
        } elseif ($key === 'cmdb_ai_model' && $val !== '') {
            $cfg['model'] = $val;
        }
    }

    if ($cfg['api_key'] === '') {
        throw new Exception('AI co-author is not configured. Set your Anthropic key in CMDB → Settings → AI Integration first — the workflow co-author uses the same key for now.');
    }
    if (!in_array($cfg['model'], WORKFLOW_AI_VALID_MODELS, true)) {
        // Sonnet is the sweet spot for structured-output generation — fast
        // enough for an interactive UX, capable enough not to mangle the
        // JSON schema. Haiku occasionally drops nested keys on long prompts.
        $cfg['model'] = 'claude-sonnet-4-6';
    }
    return $cfg;
}

/**
 * One-shot (non-streaming) Anthropic call. Returns the decoded JSON envelope.
 * Streaming UX is planned for a follow-up commit; for the MVP a single
 * round-trip keeps the prompt + parsing simple.
 */
function callAnthropicJson(array $cfg, string $systemPrompt, string $userMessage, int $maxTokens = 2500): array
{
    $body = json_encode([
        'model'      => $cfg['model'],
        'max_tokens' => $maxTokens,
        'system'     => $systemPrompt,
        'messages'   => [['role' => 'user', 'content' => $userMessage]],
    ]);

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
        CURLOPT_SSL_VERIFYPEER => defined('SSL_VERIFY_PEER') ? SSL_VERIFY_PEER : true,
        CURLOPT_TIMEOUT        => 60,
    ]);
    $resp = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($resp === false) {
        throw new Exception('Network error talking to Anthropic: ' . $err);
    }
    $data = json_decode($resp, true);
    if ($http !== 200) {
        $msg = $data['error']['message'] ?? ('HTTP ' . $http);
        throw new Exception('Anthropic error: ' . $msg);
    }
    return $data;
}

/**
 * Extract the assistant's text out of an Anthropic /v1/messages response.
 */
function anthropicResponseText(array $resp): string
{
    $blocks = $resp['content'] ?? [];
    $text = '';
    foreach ($blocks as $b) {
        if (($b['type'] ?? '') === 'text') {
            $text .= $b['text'] ?? '';
        }
    }
    return trim($text);
}

/**
 * Robust JSON extractor — strips markdown fences and pulls the first
 * object out of the assistant's reply.
 */
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
