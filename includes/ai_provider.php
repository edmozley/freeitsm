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
 *   - azure       → POST {endpoint}/openai/deployments/{deployment}/chat/completions
 *                        ?api-version={version}
 *                   Azure OpenAI's DEPLOYMENT-BASED endpoints (discussion #86).
 *                   Same request and response shape as `openai`; three things
 *                   differ, and they are the whole of the new code:
 *                     1. the URL names a DEPLOYMENT you created in your own
 *                        Azure tenant, and carries a REQUIRED query string
 *                     2. the key goes in an `api-key:` header, NOT
 *                        `Authorization: Bearer`
 *                     3. the deployment IS the model, so no `model` is sent
 *                   Asked for by organisations whose governance will not let
 *                   traffic leave their own Azure tenant — for them this is not
 *                   a preference, it is the only way to switch AI on at all.
 *
 * Request shapes mirror the proven ones in includes/rfp_ai.php; this file is
 * intentionally independent so the RFP builder isn't affected.
 */

require_once __DIR__ . '/encryption.php';

const AI_PROVIDER_RETRY_MAX        = 3;
const AI_PROVIDER_RETRY_BACKOFF_MS = 2000;
const AI_PROVIDER_HTTP_TIMEOUT     = 120;
const AI_PROVIDER_VALID            = ['anthropic', 'openai', 'openrouter', 'azure'];

/** Used when an Azure config names no api-version. A widely-deployed, stable one. */
const AI_AZURE_DEFAULT_API_VERSION = '2024-02-01';

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
 * @return array ['content','tokens_in','tokens_out','provider','model','duration_ms',
 *                'finish_reason','reasoning_tokens']
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
    /* ⚠️ Azure names a DEPLOYMENT rather than a model, so it is checked against
       its own fields. Saying "No model configured" to somebody who has correctly
       filled in a deployment would send them looking for a box that is not on
       their screen. */
    if ($provider === 'azure') {
        if (trim((string)($cfg['azure_endpoint'] ?? '')) === '') {
            throw new RuntimeException('No Azure endpoint configured.');
        }
        if (trim((string)($cfg['azure_deployment'] ?? '')) === '') {
            throw new RuntimeException('No Azure deployment name configured.');
        }
    } elseif ($model === '') {
        throw new RuntimeException('No model configured.');
    }

    $opts['max_tokens']  = $opts['max_tokens']  ?? 1024;
    $opts['temperature'] = $opts['temperature'] ?? 0.0;
    /* Extended thinking is a property of the FEATURE, so it arrives on $cfg with
       everything else aiSettingsLoad() reads — no caller has to know about it and
       all nine features get it at once. An explicit $opts value still wins, for a
       caller that has a reason. */
    $opts['reasoning']   = $opts['reasoning'] ?? (array_key_exists('reasoning', $cfg) ? (bool)$cfg['reasoning'] : true);

    $start = microtime(true);

    if ($provider === 'anthropic') {
        $result = aiProviderCallAnthropic($model, $apiKey, $verify, $opts);
    } elseif ($provider === 'azure') {
        /* Azure IS the OpenAI wire format, at a different address and behind a
           different header — so it reuses that call rather than copying it. The
           deployment is reported back as the "model" because that is the name
           the administrator chose and the only one they will recognise.

           ⚠️ NO `model` IN THE BODY. On a deployment-based endpoint the
           deployment already decides the model; some api-versions reject a
           `model` that does not match the deployment, and there is nothing
           useful to send that the URL has not already said. */
        $model  = trim((string)$cfg['azure_deployment']);
        $result = aiProviderCallOpenAICompatible(
            aiAzureChatUrl($cfg),
            '',
            ['api-key: ' . $apiKey],
            $verify,
            $opts
        );
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
        $result = aiProviderCallOpenAICompatible(
            rtrim($base, '/') . '/chat/completions',
            $model,
            ['Authorization: Bearer ' . $apiKey],
            $verify,
            $opts,
            $extraHeaders
        );
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
        // Why it stopped. 'max_tokens' means the answer was CUT OFF, which
        // reads as a short answer rather than as a failure — see the note on
        // the OpenAI-compatible path below. Additive: every existing caller
        // reads content/tokens_* and is unaffected.
        'finish_reason' => $data['stop_reason'] ?? null,
        'reasoning_tokens' => null,
    ];
}

/**
 * Build the URL for an Azure OpenAI deployment-based chat call.
 *
 * ⚠️ THE QUERY STRING IS NOT OPTIONAL. Azure refuses the request outright
 * without `api-version`, which is why this cannot be expressed as a base URL the
 * way OpenAI and OpenRouter are — and why this helper exists rather than another
 * entry in the base-URL ternary above.
 *
 * The endpoint is normalised: administrators copy it out of the Azure portal
 * with or without a trailing slash, and sometimes with `/openai` already on the
 * end. Both are the same intention and neither should be a support question.
 */
function aiAzureChatUrl(array $cfg): string
{
    $endpoint = rtrim(trim((string)$cfg['azure_endpoint']), '/');
    // Tolerate a pasted ".../openai" so it is not doubled below.
    if (substr($endpoint, -7) === '/openai') {
        $endpoint = substr($endpoint, 0, -7);
    }
    $deployment = trim((string)$cfg['azure_deployment']);
    $version    = trim((string)($cfg['azure_api_version'] ?? '')) ?: AI_AZURE_DEFAULT_API_VERSION;

    return $endpoint . '/openai/deployments/' . rawurlencode($deployment)
         . '/chat/completions?api-version=' . rawurlencode($version);
}

/**
 * The OpenAI chat-completions wire format, at whatever URL and behind whatever
 * auth header the caller names.
 *
 * ⚠️ It takes a FULL URL and an AUTH HEADER LIST rather than a base and a key,
 * because Azure differs in exactly those two places (a required query string,
 * and `api-key:` instead of `Authorization: Bearer`). Passing them in keeps one
 * copy of the body-building, response-parsing and reasoning-token handling
 * below — all of which Azure shares — instead of a near-identical second one
 * that would drift.
 *
 * An empty $model omits the field entirely, which is what a deployment-based
 * endpoint wants.
 */
function aiProviderCallOpenAICompatible(string $url, string $model, array $authHeaders, bool $verify, array $opts, array $extraHeaders = []): array
{
    $base = $url;                 // the reasoning guard below tests the address

    $payload = [
        'max_tokens'  => $opts['max_tokens'],
        'temperature' => $opts['temperature'],
        'messages'    => [
            ['role' => 'system', 'content' => (string)($opts['system'] ?? '')],
            ['role' => 'user',   'content' => (string)($opts['user']   ?? '')],
        ],
    ];
    if ($model !== '') {
        $payload = ['model' => $model] + $payload;
    }

    /* ⚠️ ONLY ON OPENROUTER. `reasoning` is OpenRouter's own parameter; OpenAI's
       /chat/completions rejects an unknown field outright, so sending it to both
       would break every OpenAI-configured feature. The base URL is the test
       because it is the thing that actually decides where the request lands.

       Sent only to switch thinking OFF. Leaving it out is "whatever the model
       does by default", which is what an administrator who wants thinking has
       asked for — so there is nothing to send in that case. */
    if (($opts['reasoning'] ?? true) === false && strpos($base, 'openrouter.ai') !== false) {
        $payload['reasoning'] = ['enabled' => false];
    }

    $headers = array_merge($authHeaders, ['content-type: application/json'], $extraHeaders);

    try {
        $resp = aiProviderHttpPost($url, $headers, json_encode($payload), $verify);
    } catch (RuntimeException $e) {
        /* ⚠️ `max_tokens` VS `max_completion_tokens`. Newer deployments — the
           o-series and after — refuse `max_tokens` outright and say so by name.
           The two mean the same thing here, and which one is required depends on
           the deployed model rather than on anything we can see from the config,
           so the only honest way to know is to be told.

           Retried ONCE, and only when the provider's own words name the
           replacement — so this cannot loop, and it cannot fire on an unrelated
           400 such as a bad key or a content filter. Without it, an
           administrator with a current Azure deployment gets a flat refusal for
           a field they never chose and cannot see. */
        if (strpos($e->getMessage(), 'max_completion_tokens') === false) {
            throw $e;
        }
        $payload['max_completion_tokens'] = $payload['max_tokens'];
        unset($payload['max_tokens']);
        $resp = aiProviderHttpPost($url, $headers, json_encode($payload), $verify);
    }
    $data = $resp['data'];

    $text = $data['choices'][0]['message']['content'] ?? '';

    /* ⚠️ A REASONING MODEL CAN RETURN NOTHING AND LOOK LIKE A SUCCESS.
       OpenRouter serves plenty of them, and they spend `completion_tokens` on a
       `reasoning` field before writing a single character of `content`. Ask one
       a real question with a small max_tokens and the whole budget goes on
       thinking: HTTP 200, usage full, content "". Every feature here then reports
       its own generic failure for something that is neither a network problem nor
       a bad key — it is a budget that ran out before the answer started.

       So the reason is returned alongside the text. 'length' with no content and
       a pile of reasoning tokens is a nameable case an administrator can act on
       (raise the limit, or choose a model that does not think out loud), and a
       caller that ignores these keys behaves exactly as it did before. */
    $choice = $data['choices'][0] ?? [];

    return [
        'content'    => trim((string)$text),
        'tokens_in'  => $data['usage']['prompt_tokens']     ?? null,
        'tokens_out' => $data['usage']['completion_tokens'] ?? null,
        'finish_reason' => $choice['finish_reason'] ?? null,
        'reasoning_tokens' => $data['usage']['completion_tokens_details']['reasoning_tokens'] ?? null,
    ];
}

/**
 * ─── Tool calling ────────────────────────────────────────────────────────────
 *
 * A conversation where the model may ask us to run something and then answer
 * using the result. Everything above is single-turn; this is the loop.
 *
 * Deliberately ADDITIVE — aiProviderChat() and its two callees are untouched, so
 * the eight existing AI features cannot be affected by anything here.
 *
 * The two wire formats differ more than they look:
 *
 *   Anthropic  tools:[{name,description,input_schema}]; the reply carries
 *              content blocks of type tool_use; the result goes back as a USER
 *              message containing tool_result blocks, and the assistant's own
 *              turn must be echoed back verbatim first.
 *   OpenAI     tools:[{type:'function',function:{...,parameters}}]; the reply
 *              carries message.tool_calls with arguments as a JSON STRING; each
 *              result goes back as its own message with role:'tool'.
 *
 * $runTool receives (string $name, array $args) and returns a string — whatever
 * the model should see. It must NEVER throw: a tool that fails should say so in
 * words, because "the CMDB lookup failed" is a useful thing for the model to
 * tell the reader, and an exception here would lose the whole conversation.
 *
 * @param array    $tools    [['name'=>…,'description'=>…,'schema'=>[JSON Schema]], …]
 * @param callable $runTool  fn(string $name, array $args): string
 * @return array ['content','calls'=>[['name','args','result'],…],'tokens_in','tokens_out','provider','model','duration_ms']
 */
function aiProviderChatTools(array $cfg, array $opts, array $tools, callable $runTool): array
{
    $provider = $cfg['provider'] ?? 'anthropic';
    $model    = trim((string)($cfg['model'] ?? ''));
    $apiKey   = (string)($cfg['api_key'] ?? '');
    $verify   = !empty($cfg['verify_ssl']);

    if (!in_array($provider, AI_PROVIDER_VALID, true)) throw new RuntimeException('Unknown AI provider: ' . $provider);
    if ($apiKey === '') throw new RuntimeException('No API key configured.');
    if ($model  === '') throw new RuntimeException('No model configured.');

    // A hard ceiling on round trips. A model that keeps asking for tools would
    // otherwise loop until the request times out — during an incident, on the
    // box everyone is relying on.
    $maxRounds  = max(1, min(6, (int)($opts['max_rounds'] ?? 4)));
    $maxTokens  = $opts['max_tokens']  ?? 1024;
    $temperature = $opts['temperature'] ?? 0.0;

    $start = microtime(true);
    $calls = [];
    $tokIn = 0;
    $tokOut = 0;

    if ($provider === 'anthropic') {
        $messages = [['role' => 'user', 'content' => (string)($opts['user'] ?? '')]];
        $wire = array_map(function ($t) {
            return ['name' => $t['name'], 'description' => $t['description'], 'input_schema' => $t['schema']];
        }, $tools);

        for ($round = 0; $round < $maxRounds; $round++) {
            $body = json_encode([
                'model'       => $model,
                'max_tokens'  => $maxTokens,
                'temperature' => $temperature,
                'system'      => (string)($opts['system'] ?? ''),
                'tools'       => $wire,
                'messages'    => $messages,
            ]);
            $resp = aiProviderHttpPost(AI_ANTHROPIC_URL, [
                'x-api-key: ' . $apiKey,
                'anthropic-version: 2023-06-01',
                'content-type: application/json',
            ], $body, $verify);
            $data = $resp['data'];

            $tokIn  += (int)($data['usage']['input_tokens']  ?? 0);
            $tokOut += (int)($data['usage']['output_tokens'] ?? 0);

            $text = '';
            $toolUses = [];
            foreach (($data['content'] ?? []) as $block) {
                if (($block['type'] ?? '') === 'text')     $text .= $block['text'];
                if (($block['type'] ?? '') === 'tool_use') $toolUses[] = $block;
            }

            if (!$toolUses) {
                return aiToolsResult(trim($text), $calls, $tokIn, $tokOut, $provider, $model, $start);
            }

            // The assistant's turn goes back exactly as received, or the API
            // rejects the tool_result that follows it.
            //
            // ⚠️ EXCEPT FOR ONE THING, AND IT IS A PHP TRAP RATHER THAN AN API ONE.
            // A tool that takes no arguments arrives as "input": {}. json_decode
            // with assoc=true turns that into an EMPTY PHP ARRAY, and re-encoding
            // an empty array produces [] — a JSON array. Anthropic then rejects
            // the echoed turn with:
            //   messages.N.content.M.tool_use.input: Input should be an object
            // The failure is intermittent in the worst way: it only happens once
            // the model reaches for a parameterless tool, so it looks like a flaky
            // provider rather than a bug. Cast every tool_use input back to an
            // object before sending it home.
            $echo = $data['content'];
            foreach ($echo as $i => $block) {
                if (($block['type'] ?? '') === 'tool_use') {
                    $echo[$i]['input'] = (object) ($block['input'] ?? []);
                }
            }
            $messages[] = ['role' => 'assistant', 'content' => $echo];

            $results = [];
            foreach ($toolUses as $u) {
                $out = (string) $runTool((string)$u['name'], (array)($u['input'] ?? []));
                $calls[] = ['name' => $u['name'], 'args' => $u['input'] ?? [], 'result' => $out];
                $results[] = ['type' => 'tool_result', 'tool_use_id' => $u['id'], 'content' => $out];
            }
            $messages[] = ['role' => 'user', 'content' => $results];
        }

        // Out of rounds. Return what we have rather than nothing.
        return aiToolsResult('', $calls, $tokIn, $tokOut, $provider, $model, $start);
    }

    // ── OpenAI-compatible (openai, openrouter) ──
    $base = $provider === 'openrouter'
        ? ($cfg['base_url'] ?? AI_OPENROUTER_BASE)
        : ($cfg['base_url'] ?? AI_OPENAI_BASE);
    $extraHeaders = [];
    if ($provider === 'openrouter') {
        $extraHeaders[] = 'HTTP-Referer: ' . ($opts['referer'] ?? 'https://freeitsm.co.uk');
        $extraHeaders[] = 'X-Title: ' . ($opts['title'] ?? 'FreeITSM');
    }

    $messages = [
        ['role' => 'system', 'content' => (string)($opts['system'] ?? '')],
        ['role' => 'user',   'content' => (string)($opts['user']   ?? '')],
    ];
    $wire = array_map(function ($t) {
        return ['type' => 'function', 'function' => [
            'name' => $t['name'], 'description' => $t['description'], 'parameters' => $t['schema'],
        ]];
    }, $tools);

    for ($round = 0; $round < $maxRounds; $round++) {
        $body = json_encode([
            'model'       => $model,
            'max_tokens'  => $maxTokens,
            'temperature' => $temperature,
            'tools'       => $wire,
            'messages'    => $messages,
        ]);
        $resp = aiProviderHttpPost(rtrim($base, '/') . '/chat/completions', array_merge([
            'Authorization: Bearer ' . $apiKey,
            'content-type: application/json',
        ], $extraHeaders), $body, $verify);
        $data = $resp['data'];

        $tokIn  += (int)($data['usage']['prompt_tokens']     ?? 0);
        $tokOut += (int)($data['usage']['completion_tokens'] ?? 0);

        $msg   = $data['choices'][0]['message'] ?? [];
        $tcs   = $msg['tool_calls'] ?? [];

        if (!$tcs) {
            return aiToolsResult(trim((string)($msg['content'] ?? '')), $calls, $tokIn, $tokOut, $provider, $model, $start);
        }

        $messages[] = $msg;
        foreach ($tcs as $tc) {
            $name = (string)($tc['function']['name'] ?? '');
            // ⚠️ arguments arrive as a JSON STRING here, not an object — decoding
            // it is not optional, and a model occasionally sends '' for a tool
            // that takes none.
            $args = json_decode((string)($tc['function']['arguments'] ?? '{}'), true);
            if (!is_array($args)) $args = [];
            $out = (string) $runTool($name, $args);
            $calls[] = ['name' => $name, 'args' => $args, 'result' => $out];
            $messages[] = ['role' => 'tool', 'tool_call_id' => $tc['id'] ?? '', 'content' => $out];
        }
    }

    return aiToolsResult('', $calls, $tokIn, $tokOut, $provider, $model, $start);
}

/** Shared return shape for aiProviderChatTools. */
function aiToolsResult(string $content, array $calls, int $tokIn, int $tokOut, string $provider, string $model, float $start): array
{
    return [
        'content'     => $content,
        'calls'       => $calls,
        'tokens_in'   => $tokIn,
        'tokens_out'  => $tokOut,
        'provider'    => $provider,
        'model'       => $model,
        'duration_ms' => (int)((microtime(true) - $start) * 1000),
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
