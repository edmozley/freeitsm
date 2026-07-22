<?php
/**
 * Reusable, storage-agnostic AI provider client.
 *
 * One place that knows how to send a chat/completion request to any of the
 * supported providers and normalise the response. It takes a plain config
 * array (provider/model/key/...) — it does NOT read settings itself, so it
 * can be reused by any module. The companion `ai_settings.php` loads config
 * from system_settings and hands it here.
 *
 * Providers:
 *   - anthropic   → POST https://api.anthropic.com/v1/messages
 *   - openai      → POST https://api.openai.com/v1/chat/completions
 *   - openrouter  → POST https://openrouter.ai/api/v1/chat/completions
 *                   (OpenAI-wire-compatible; one key reaches hundreds of
 *                    models, model ids are namespaced e.g. "anthropic/claude-3.5-sonnet")
 *
 * Request shapes mirror the proven ones in includes/rfp_ai.php; this file is
 * intentionally independent so the RFP builder isn't affected.
 */

require_once __DIR__ . '/encryption.php';

const AI_PROVIDER_RETRY_MAX        = 3;
const AI_PROVIDER_RETRY_BACKOFF_MS = 2000;
const AI_PROVIDER_HTTP_TIMEOUT     = 120;
const AI_PROVIDER_VALID            = ['anthropic', 'openai', 'openrouter'];

const AI_OPENROUTER_BASE  = 'https://openrouter.ai/api/v1';
const AI_OPENAI_BASE      = 'https://api.openai.com/v1';
const AI_ANTHROPIC_URL    = 'https://api.anthropic.com/v1/messages';
const AI_OPENROUTER_MODELS_URL = 'https://openrouter.ai/api/v1/models';
const AI_OPENROUTER_MODELS_TTL = 86400; // 24h

/**
 * Send a one-shot chat request and return a normalised result.
 *
 * @param array $cfg  ['provider','model','api_key','verify_ssl'(bool),'base_url'?]
 * @param array $opts ['system','user','max_tokens'?=1024,'temperature'?=0.0,
 *                     'referer'?,'title'?]  (referer/title attribute the call on
 *                     OpenRouter's dashboard — defaults to FreeITSM)
 * @return array ['content','tokens_in','tokens_out','provider','model','duration_ms']
 * @throws RuntimeException on misconfiguration or API/network failure.
 */
function aiProviderChat(array $cfg, array $opts): array
{
    $provider = $cfg['provider'] ?? 'anthropic';
    $model    = trim((string)($cfg['model'] ?? ''));
    $apiKey   = (string)($cfg['api_key'] ?? '');
    $verify   = !empty($cfg['verify_ssl']);

    if (!in_array($provider, AI_PROVIDER_VALID, true)) {
        throw new RuntimeException('Unknown AI provider: ' . $provider);
    }
    if ($apiKey === '') {
        throw new RuntimeException('No API key configured.');
    }
    if ($model === '') {
        throw new RuntimeException('No model configured.');
    }

    $opts['max_tokens']  = $opts['max_tokens']  ?? 1024;
    $opts['temperature'] = $opts['temperature'] ?? 0.0;

    $start = microtime(true);

    if ($provider === 'anthropic') {
        $result = aiProviderCallAnthropic($model, $apiKey, $verify, $opts);
    } else {
        // openai + openrouter share the OpenAI-compatible chat-completions wire format
        $base = $provider === 'openrouter'
            ? ($cfg['base_url'] ?? AI_OPENROUTER_BASE)
            : ($cfg['base_url'] ?? AI_OPENAI_BASE);
        $extraHeaders = [];
        if ($provider === 'openrouter') {
            // Optional attribution headers — surface the app on the OpenRouter dashboard.
            $extraHeaders[] = 'HTTP-Referer: ' . ($opts['referer'] ?? 'https://freeitsm.co.uk');
            $extraHeaders[] = 'X-Title: ' . ($opts['title'] ?? 'FreeITSM');
        }
        $result = aiProviderCallOpenAICompatible($base, $model, $apiKey, $verify, $opts, $extraHeaders);
    }

    $result['provider']    = $provider;
    $result['model']       = $model;
    $result['duration_ms'] = (int)((microtime(true) - $start) * 1000);
    return $result;
}

function aiProviderCallAnthropic(string $model, string $apiKey, bool $verify, array $opts): array
{
    $body = json_encode([
        'model'       => $model,
        'max_tokens'  => $opts['max_tokens'],
        'temperature' => $opts['temperature'],
        'system'      => (string)($opts['system'] ?? ''),
        'messages'    => [['role' => 'user', 'content' => (string)($opts['user'] ?? '')]],
    ]);

    $headers = [
        'x-api-key: ' . $apiKey,
        'anthropic-version: 2023-06-01',
        'content-type: application/json',
    ];

    $resp = aiProviderHttpPost(AI_ANTHROPIC_URL, $headers, $body, $verify);
    $data = $resp['data'];

    $text = '';
    foreach (($data['content'] ?? []) as $block) {
        if (($block['type'] ?? '') === 'text') {
            $text .= $block['text'];
        }
    }

    return [
        'content'    => trim($text),
        'tokens_in'  => $data['usage']['input_tokens']  ?? null,
        'tokens_out' => $data['usage']['output_tokens'] ?? null,
    ];
}

function aiProviderCallOpenAICompatible(string $base, string $model, string $apiKey, bool $verify, array $opts, array $extraHeaders = []): array
{
    $body = json_encode([
        'model'       => $model,
        'max_tokens'  => $opts['max_tokens'],
        'temperature' => $opts['temperature'],
        'messages'    => [
            ['role' => 'system', 'content' => (string)($opts['system'] ?? '')],
            ['role' => 'user',   'content' => (string)($opts['user']   ?? '')],
        ],
    ]);

    $headers = array_merge([
        'Authorization: Bearer ' . $apiKey,
        'content-type: application/json',
    ], $extraHeaders);

    $resp = aiProviderHttpPost(rtrim($base, '/') . '/chat/completions', $headers, $body, $verify);
    $data = $resp['data'];

    $text = $data['choices'][0]['message']['content'] ?? '';

    return [
        'content'    => trim((string)$text),
        'tokens_in'  => $data['usage']['prompt_tokens']     ?? null,
        'tokens_out' => $data['usage']['completion_tokens'] ?? null,
    ];
}

/**
 * POST with retry/backoff on 429 / 5xx / network errors. Ported from
 * rfpAiHttpPostWithRetry so this file stands alone.
 */
function aiProviderHttpPost(string $url, array $headers, string $body, bool $verifySsl): array
{
    $attempt = 0;
    $lastErr = '';

    while ($attempt < AI_PROVIDER_RETRY_MAX) {
        $attempt++;

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => AI_PROVIDER_HTTP_TIMEOUT,
        ]);
        sslApplyCurl($ch);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($resp === false) {
            $lastErr = 'Network error: ' . $err;
            if ($attempt < AI_PROVIDER_RETRY_MAX) {
                usleep(AI_PROVIDER_RETRY_BACKOFF_MS * 1000 * (2 ** ($attempt - 1)));
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

        $retryable = ($code === 429 || ($code >= 500 && $code < 600));
        if ($retryable && $attempt < AI_PROVIDER_RETRY_MAX) {
            usleep(AI_PROVIDER_RETRY_BACKOFF_MS * 1000 * (2 ** ($attempt - 1)));
            continue;
        }
        throw new RuntimeException($lastErr);
    }

    throw new RuntimeException('Failed after ' . AI_PROVIDER_RETRY_MAX . ' attempts: ' . $lastErr);
}

/**
 * Fetch (and cache) the OpenRouter model catalogue. No API key required.
 * Cached in system_settings as JSON for 24h to keep the model picker snappy.
 * Falls back to a stale cache if a refresh fetch fails.
 *
 * @return array{models: array<int,array>, cached_at: int, stale: bool}
 *   models: [{id,name,context_length,prompt_price,completion_price}]
 */
function aiProviderListOpenRouterModels(PDO $conn, bool $force = false): array
{
    $readSetting = function (string $key) use ($conn) {
        $stmt = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ?");
        $stmt->execute([$key]);
        $v = $stmt->fetchColumn();
        return $v === false ? null : $v;
    };

    $cachedAt = (int)($readSetting('openrouter_models_cached_at') ?? 0);
    $cacheRaw = $readSetting('openrouter_models_cache');
    $fresh    = $cacheRaw !== null && (time() - $cachedAt) < AI_OPENROUTER_MODELS_TTL;

    if ($fresh && !$force) {
        $decoded = json_decode($cacheRaw, true);
        if (is_array($decoded)) {
            return ['models' => $decoded, 'cached_at' => $cachedAt, 'stale' => false];
        }
    }

    // Fetch fresh
    try {
        $ch = curl_init(AI_OPENROUTER_MODELS_URL);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        ]);
        sslApplyCurl($ch);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($resp === false || $code < 200 || $code >= 300) {
            throw new RuntimeException('OpenRouter models fetch failed (HTTP ' . $code . ')');
        }

        $json = json_decode($resp, true);
        $raw  = $json['data'] ?? [];
        $models = [];
        foreach ($raw as $m) {
            if (empty($m['id'])) continue;
            $models[] = [
                'id'              => $m['id'],
                'name'            => $m['name'] ?? $m['id'],
                'context_length'  => $m['context_length'] ?? ($m['top_provider']['context_length'] ?? null),
                'prompt_price'    => isset($m['pricing']['prompt'])     ? (float)$m['pricing']['prompt']     : null,
                'completion_price'=> isset($m['pricing']['completion']) ? (float)$m['pricing']['completion'] : null,
            ];
        }

        // Persist cache (plain JSON, no secrets)
        $now = time();
        $upsert = function (string $key, string $value) use ($conn) {
            $stmt = $conn->prepare(
                "INSERT INTO system_settings (setting_key, setting_value) VALUES (:k, :v)
                 ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
            );
            $stmt->execute([':k' => $key, ':v' => $value]);
        };
        $upsert('openrouter_models_cache', json_encode($models));
        $upsert('openrouter_models_cached_at', (string)$now);

        return ['models' => $models, 'cached_at' => $now, 'stale' => false];
    } catch (Throwable $e) {
        // Fall back to whatever stale cache we have rather than failing the picker.
        if ($cacheRaw !== null) {
            $decoded = json_decode($cacheRaw, true);
            if (is_array($decoded)) {
                return ['models' => $decoded, 'cached_at' => $cachedAt, 'stale' => true];
            }
        }
        throw new RuntimeException('Could not load the OpenRouter model list: ' . $e->getMessage());
    }
}
