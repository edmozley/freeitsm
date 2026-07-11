<?php
/**
 * API: Send a tiny "say ok" prompt to the chosen provider so the user
 * can verify their key + model BEFORE saving. Supports both Anthropic
 * and OpenAI. If the masked placeholder ("****abcd") is submitted, the
 * stored key is used instead — so the user can test the saved config
 * without re-typing the secret.
 *
 * Mirrors the test pattern from api/rfp-builder/test_ai_connection.php.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/encryption.php';
require_once __DIR__ . '/_ai_helpers.php';   // for workflowEffectiveSslVerify()
header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireModuleAccessJson('workflow');

try {
    $data      = json_decode(file_get_contents('php://input'), true) ?: [];
    $provider  = (string)($data['provider'] ?? '');
    $model     = trim((string)($data['model'] ?? ''));
    $apiKey    = (string)($data['api_key'] ?? '');
    $verifySsl = !(isset($data['verify_ssl']) && $data['verify_ssl'] === '0');

    if (!in_array($provider, ['anthropic', 'openai'], true)) {
        throw new Exception('Provider must be anthropic or openai');
    }
    if ($model === '') {
        throw new Exception('Model is required');
    }

    // Masked placeholder => fall back to the stored key so the user can test
    // the saved config without re-typing their secret.
    if (isMaskedNoChangeValue($apiKey)) {
        $conn = connectToDatabase();
        $stmt = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'workflow_ai_api_key'");
        $stmt->execute();
        $stored = $stmt->fetchColumn();
        if (!$stored) {
            throw new Exception('No saved API key — paste a fresh key into the form to test it');
        }
        $apiKey = decryptValue($stored);
    }
    if ($apiKey === '') {
        throw new Exception('API key is required');
    }

    $start = microtime(true);
    $result = $provider === 'anthropic'
        ? wfTestAnthropic($apiKey, $model, $verifySsl)
        : wfTestOpenAI($apiKey, $model, $verifySsl);
    $elapsedMs = (int)((microtime(true) - $start) * 1000);

    echo json_encode([
        'success'    => true,
        'provider'   => $provider,
        'model'      => $model,
        'latency_ms' => $elapsedMs,
        'tokens_in'  => $result['tokens_in']  ?? null,
        'tokens_out' => $result['tokens_out'] ?? null,
        'response'   => $result['response']   ?? '',
    ]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

function wfTestAnthropic(string $apiKey, string $model, bool $verifySsl): array
{
    $body = json_encode([
        'model'      => $model,
        'max_tokens' => 16,
        'messages'   => [['role' => 'user', 'content' => 'Reply with just the word: ok']],
    ]);
    // Combine the per-form toggle with the global SSL_VERIFY_PEER kill switch
    // from config.php — set to false on dev installs without a CA bundle.
    $verifyPeer = workflowEffectiveSslVerify($verifySsl);
    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_HTTPHEADER     => [
            'x-api-key: ' . $apiKey,
            'anthropic-version: 2023-06-01',
            'content-type: application/json',
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => $verifyPeer,
        CURLOPT_SSL_VERIFYHOST => $verifyPeer ? 2 : 0,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($resp === false) throw new Exception('Network error: ' . $err);
    $data = json_decode($resp, true);
    if ($code !== 200) {
        throw new Exception($data['error']['message'] ?? ('HTTP ' . $code));
    }
    $text = '';
    foreach (($data['content'] ?? []) as $b) {
        if (($b['type'] ?? '') === 'text') $text .= ($b['text'] ?? '');
    }
    return [
        'response'   => trim($text),
        'tokens_in'  => $data['usage']['input_tokens']  ?? null,
        'tokens_out' => $data['usage']['output_tokens'] ?? null,
    ];
}

function wfTestOpenAI(string $apiKey, string $model, bool $verifySsl): array
{
    $body = json_encode([
        'model'      => $model,
        'max_tokens' => 16,
        'messages'   => [['role' => 'user', 'content' => 'Reply with just the word: ok']],
    ]);
    $verifyPeer = workflowEffectiveSslVerify($verifySsl);
    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $apiKey,
            'content-type: application/json',
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => $verifyPeer,
        CURLOPT_SSL_VERIFYHOST => $verifyPeer ? 2 : 0,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($resp === false) throw new Exception('Network error: ' . $err);
    $data = json_decode($resp, true);
    if ($code !== 200) {
        throw new Exception($data['error']['message'] ?? ('HTTP ' . $code));
    }
    return [
        'response'   => trim((string)($data['choices'][0]['message']['content'] ?? '')),
        'tokens_in'  => $data['usage']['prompt_tokens']     ?? null,
        'tokens_out' => $data['usage']['completion_tokens'] ?? null,
    ];
}
