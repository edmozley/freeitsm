<?php
/**
 * Send a tiny "say ok" prompt to the chosen provider to verify that
 * the API key + model are valid. The user can submit a fresh key to
 * test BEFORE saving; passing the masked placeholder ("****abcd")
 * falls back to the stored key.
 */
session_start();
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/encryption.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

try {
    $data = json_decode(file_get_contents('php://input'), true);
    $provider  = $data['provider']   ?? '';
    $apiKey    = $data['api_key']    ?? '';
    $model     = trim($data['model'] ?? '');
    $verifySsl = !(isset($data['verify_ssl']) && $data['verify_ssl'] === '0');

    if (!in_array($provider, ['anthropic', 'openai'], true)) {
        throw new Exception('Provider must be anthropic or openai');
    }
    if ($model === '') {
        throw new Exception('Model is required');
    }

    if (isMaskedNoChangeValue($apiKey)) {
        $conn = connectToDatabase();
        $stmt = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'rfp_ai_api_key'");
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
        ? rfpTestAnthropic($apiKey, $model, $verifySsl)
        : rfpTestOpenAI($apiKey, $model, $verifySsl);
    $elapsedMs = (int)((microtime(true) - $start) * 1000);

    echo json_encode([
        'success'    => true,
        'provider'   => $provider,
        'model'      => $model,
        'latency_ms' => $elapsedMs,
        'tokens_in'  => $result['tokens_in'] ?? null,
        'tokens_out' => $result['tokens_out'] ?? null,
        'response'   => $result['response'] ?? '',
    ]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

function rfpTestAnthropic(string $apiKey, string $model, bool $verifySsl): array {
    $body = json_encode([
        'model'      => $model,
        'max_tokens' => 16,
        'messages'   => [['role' => 'user', 'content' => 'Reply with just the word: ok']],
    ]);

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
        CURLOPT_SSL_VERIFYPEER => $verifySsl,
        CURLOPT_SSL_VERIFYHOST => $verifySsl ? 2 : 0,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($resp === false) {
        throw new Exception('Network error: ' . $err);
    }

    $data = json_decode($resp, true);
    if ($code !== 200) {
        $msg = $data['error']['message'] ?? ('HTTP ' . $code);
        throw new Exception($msg);
    }

    $text = '';
    foreach (($data['content'] ?? []) as $block) {
        if (($block['type'] ?? '') === 'text') {
            $text .= $block['text'];
        }
    }

    return [
        'response'   => trim($text),
        'tokens_in'  => $data['usage']['input_tokens']  ?? null,
        'tokens_out' => $data['usage']['output_tokens'] ?? null,
    ];
}

function rfpTestOpenAI(string $apiKey, string $model, bool $verifySsl): array {
    $body = json_encode([
        'model'      => $model,
        'max_tokens' => 16,
        'messages'   => [['role' => 'user', 'content' => 'Reply with just the word: ok']],
    ]);

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
        CURLOPT_SSL_VERIFYPEER => $verifySsl,
        CURLOPT_SSL_VERIFYHOST => $verifySsl ? 2 : 0,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($resp === false) {
        throw new Exception('Network error: ' . $err);
    }

    $data = json_decode($resp, true);
    if ($code !== 200) {
        $msg = $data['error']['message'] ?? ('HTTP ' . $code);
        throw new Exception($msg);
    }

    $text = $data['choices'][0]['message']['content'] ?? '';
    return [
        'response'   => trim($text),
        'tokens_in'  => $data['usage']['prompt_tokens']     ?? null,
        'tokens_out' => $data['usage']['completion_tokens'] ?? null,
    ];
}
