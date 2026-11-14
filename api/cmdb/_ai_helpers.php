<?php
/**
 * Shared CMDB AI helpers — load saved settings, call Anthropic, parse JSON.
 * Keeps the per-feature endpoints thin and consistent.
 */

require_once __DIR__ . '/../../includes/encryption.php';
require_once __DIR__ . '/../../includes/ai_settings.php';

// cmdbPropertyTypes(): the one list of property types (GH #313), including the
// record types (GH #312) so an owner points at the real person rather than a
// "Person" CI the wizard used to invent.
require_once __DIR__ . '/../../includes/services/cmdb_schema.php';

/**
 * Load CMDB AI config via the shared building block (ns=cmdb_ai), then attach
 * the CMDB-specific custom instructions. Returns provider/model/api_key/
 * verify_ssl/custom_instructions. Throws if no key is set.
 */
function loadCmdbAiConfig(PDO $conn): array {
    $cfg = aiSettingsLoad($conn, 'cmdb_ai'); // provider, model, api_key, verify_ssl
    if (!aiConfigured($cfg)) {
        throw new Exception('CMDB AI is not configured. Set your provider and key in CMDB → Settings → AI Integration.');
    }

    $stmt = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'cmdb_ai_custom_instructions'");
    $stmt->execute();
    $ci = $stmt->fetchColumn();
    $cfg['custom_instructions'] = $ci !== false ? (string)$ci : '';

    return $cfg;
}

/**
 * One-shot AI call via the shared, provider-agnostic client (Anthropic /
 * OpenAI / OpenRouter). Name kept for backward compatibility with callers.
 * Returns ['content' => string, 'tokens_in' => ?int, 'tokens_out' => ?int, ...].
 *
 * @param array $cfg  the AI connection settings (provider, model, key...) as passed to aiProviderChat()
 * @param string $systemPrompt  the system prompt
 * @param string $userMessage  the user message
 * @param int $maxTokens  the most tokens the reply may use
 */
function callAnthropic(array $cfg, string $systemPrompt, string $userMessage, int $maxTokens = 1500): array {
    return aiProviderChat($cfg, [
        'system'     => $systemPrompt,
        'user'       => $userMessage,
        'max_tokens' => $maxTokens,
    ]);
}

/**
 * Pull the assistant's text out of the shared client's response shape.
 *
 * @param array $resp  the response array returned by callAnthropic()
 */
function anthropicResponseText(array $resp): string {
    return trim((string)($resp['content'] ?? ''));
}

/**
 * Parse a JSON response from Claude robustly: strip markdown fences,
 * extract the first JSON object/array, and json_decode it.
 *
 * @param string $text  the model's reply, which may wrap the JSON in markdown fences or prose
 */
function parseClaudeJson(string $text): array {
    // Strip ```json ... ``` fences if present
    $text = preg_replace('/^```(?:json)?\s*|\s*```$/m', '', $text);
    $text = trim($text);

    // Find the first { or [ and use everything to the matching close.
    // Simple but robust enough for our short structured replies.
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
