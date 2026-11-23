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
 *                   An API version of "v1" uses Azure's newer route instead,
 *                   {endpoint}/openai/v1/chat/completions, which takes no
 *                   query string and DOES want the deployment as `model`
 *                   (GH #171).
 *   - openai_compatible → POST {base URL}/chat/completions
 *                   Any server that speaks the OpenAI wire format at an address
 *                   the administrator chooses - Ollama, LM Studio, vLLM, LocalAI,
 *                   a gateway (GH #192). The key is OPTIONAL: a local server
 *                   usually has none, and no Authorization header is sent then.
 *                   🔴 It NEVER falls back to a cloud provider: a failure is
 *                   reported as a failure, because an administrator who chose a
 *                   local model has usually chosen it so the text stays inside.
 * * Request shapes mirror the proven ones in includes/rfp_ai.php; this file is
 * intentionally independent so the RFP builder isn't affected.
 */

require_once __DIR__ . '/encryption.php';

/** A reply that arrived but is not the shape a chat completion has (GH #192). Never retried or "fixed". */
class AiResponseFormatError extends RuntimeException {}

const AI_PROVIDER_RETRY_MAX        = 3;
const AI_PROVIDER_RETRY_BACKOFF_MS = 2000;
const AI_PROVIDER_HTTP_TIMEOUT     = 120;

/** Used when an Azure config names no api-version. A widely-deployed, stable one. */
const AI_AZURE_DEFAULT_API_VERSION = '2024-02-01';

const AI_OPENROUTER_BASE  = 'https://openrouter.ai/api/v1';
const AI_OPENAI_BASE      = 'https://api.openai.com/v1';
const AI_ANTHROPIC_URL    = 'https://api.anthropic.com/v1/messages';
const AI_OPENROUTER_MODELS_URL = 'https://openrouter.ai/api/v1/models';
const AI_OPENROUTER_MODELS_TTL = 86400; // 24h

/**
 * The AI providers the client speaks to (4.0 - until then a list of strings, an
 * in_array() check, and an if/elseif chain in each of the two call paths).
 *
 * The value is what is stored in system_settings ('<ns>_provider'), so a case's
 * value must never change. Four of the five speak the OpenAI wire format and
 * differ only in WHERE (chatUrl), HOW THE KEY IS SENT (authHeaders) and a couple
 * of optional headers (extraHeaders) - so the call paths ask the case rather than
 * branching on its name. Anthropic has its own wire format and its own call.
 *
 * Adding a provider: add a case, and match() makes you answer every method for it.
 */
enum AiProvider: string
{
    case Anthropic        = 'anthropic';
    case OpenAI           = 'openai';
    case OpenRouter       = 'openrouter';
    case Azure            = 'azure';
    case OpenAICompatible = 'openai_compatible';

    /**
     * The provider a feature's settings name. 'anthropic' when none is saved, as
     * before; an unknown name throws, so a typo in a setting is never sent anywhere.
     *
     * @param array $cfg  a feature's AI settings; only 'provider' is read
     */
    public static function fromConfig(array $cfg): self
    {
        $name = (string)($cfg['provider'] ?? 'anthropic');
        return self::tryFrom($name) ?? throw new RuntimeException('Unknown AI provider: ' . $name);
    }

    /** The name shown to an administrator. */
    public function label(): string
    {
        return match ($this) {
            self::Anthropic        => 'Anthropic',
            self::OpenAI           => 'OpenAI',
            self::OpenRouter       => 'OpenRouter',
            self::Azure            => 'Azure OpenAI',
            self::OpenAICompatible => 'Custom endpoint',
        };
    }

    /** GH #192: a custom endpoint's key is optional - a local server usually has none. */
    public function needsApiKey(): bool
    {
        return $this !== self::OpenAICompatible;
    }

    /** True for the four that speak the OpenAI chat-completions format. */
    public function speaksOpenAI(): bool
    {
        return $this !== self::Anthropic;
    }

    /**
     * Where an OpenAI-format chat request goes.
     *
     * @param array $cfg  the feature's AI settings (base_url, azure_*, compatible_base_url)
     */
    public function chatUrl(array $cfg): string
    {
        return match ($this) {
            self::Anthropic        => AI_ANTHROPIC_URL,
            self::OpenAI           => rtrim($cfg['base_url'] ?? AI_OPENAI_BASE, '/') . '/chat/completions',
            self::OpenRouter       => rtrim($cfg['base_url'] ?? AI_OPENROUTER_BASE, '/') . '/chat/completions',
            self::Azure            => aiAzureChatUrl($cfg),
            self::OpenAICompatible => aiCompatibleChatUrl($cfg),
        };
    }

    /**
     * How the key is sent. Azure wants `api-key:`, not a Bearer token; a custom
     * endpoint sends nothing when no key is saved.
     *
     * @param string $apiKey  the plaintext key
     */
    public function authHeaders(string $apiKey): array
    {
        return match ($this) {
            self::Azure            => ['api-key: ' . $apiKey],
            self::OpenAICompatible => aiCompatibleAuthHeaders($apiKey),
            self::Anthropic        => ['x-api-key: ' . $apiKey],
            self::OpenAI, self::OpenRouter => ['Authorization: Bearer ' . $apiKey],
        };
    }

    /**
     * Optional headers. OpenRouter's attribution headers put FreeITSM on its dashboard.
     *
     * @param array $opts  the call's options; 'referer' and 'title' override the defaults
     */
    public function extraHeaders(array $opts): array
    {
        return match ($this) {
            self::OpenRouter => [
                'HTTP-Referer: ' . ($opts['referer'] ?? 'https://freeitsm.co.uk'),
                'X-Title: ' . ($opts['title'] ?? 'FreeITSM'),
            ],
            default => [],
        };
    }
}

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
    $p        = AiProvider::fromConfig($cfg);   // throws on an unknown provider
    $provider = $p->value;
    $model    = trim((string)($cfg['model'] ?? ''));
    $apiKey   = (string)($cfg['api_key'] ?? '');
    $verify   = !empty($cfg['verify_ssl']);

    if ($apiKey === '' && $p->needsApiKey()) {
        throw new RuntimeException('No API key configured.');
    }
    /* ⚠️ Azure names a DEPLOYMENT rather than a model, so it is checked against
       its own fields. Saying "No model configured" to somebody who has correctly
       filled in a deployment would send them looking for a box that is not on
       their screen. */
    if ($p === AiProvider::Azure) {
        if (trim((string)($cfg['azure_endpoint'] ?? '')) === '') {
            throw new RuntimeException('No Azure endpoint configured.');
        }
        if (trim((string)($cfg['azure_deployment'] ?? '')) === '') {
            throw new RuntimeException('No Azure deployment name configured.');
        }
    } elseif ($p === AiProvider::OpenAICompatible && trim((string)($cfg['compatible_base_url'] ?? '')) === '') {
        throw new RuntimeException('No base URL configured.');
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

    if ($p === AiProvider::Anthropic) {
        $result = aiProviderCallAnthropic($model, $apiKey, $verify, $opts);
    } else {
        /* The other four share the OpenAI wire format; the case supplies where,
           how the key is sent, and any extra headers (AiProvider::chatUrl() etc).

           Azure: the deployment is reported back as the "model" because that is
           the name the administrator chose and the only one they will recognise.
           ⚠️ NO `model` IN THE BODY on a deployment-based endpoint. The deployment
           already decides the model; some api-versions reject a `model` that does
           not match it. The v1 route is the exception and wants it (GH #171).

           Custom endpoint (GH #192): a Bearer header only when a key is saved - a
           local server needs none, and a dummy credential is not something anyone
           should have to invent. */
        $bodyModel = $model;
        if ($p === AiProvider::Azure) {
            $model     = trim((string)$cfg['azure_deployment']);
            $bodyModel = aiAzureIsV1($cfg) ? $model : '';
        }
        $result = aiProviderCallOpenAICompatible(
            $p->chatUrl($cfg),
            $bodyModel,
            $p->authHeaders($apiKey),
            $verify,
            $opts,
            $p->extraHeaders($opts)
        );
    }

    $result['provider']    = $provider;
    $result['model']       = $model;
    $result['duration_ms'] = (int)((microtime(true) - $start) * 1000);
    return $result;
}

/**
 * Whether an Anthropic model still accepts the `temperature` parameter.
 *
 * Only the older generations do: Claude 2 and 3, and the 4-family up to 4.6
 * (Opus 4.6, Sonnet 4.6, Haiku 4.5, and the 4.0-4.5 releases, dated or not).
 * Opus 4.7 and 4.8, the 5-family and Fable reject a non-default value with a 400.
 * Written as "the old ones that accept it" so that any model released later is
 * treated like the newest ones - no temperature - rather than breaking.
 *
 * @param string $model  an Anthropic model id, e.g. 'claude-haiku-5-5' or 'claude-sonnet-4-5-20250929'
 */
function aiAnthropicAcceptsTemperature(string $model): bool
{
    return (bool) preg_match('/^claude-(?:[23]|instant|(?:opus|sonnet|haiku)-4(?:-[0-6](?:-|$)|-\d{8}|$))/i', trim($model));
}

/**
 * Sends one system + user prompt to the Anthropic Messages API and returns the joined text blocks with token counts and the stop reason.
 *
 * @param string $model  the Anthropic model id, already trimmed and non-empty
 * @param string $apiKey  the plaintext API key, sent as x-api-key
 * @param bool $verify  the feature's verify-SSL setting, passed on to aiProviderHttpPost()
 * @param array $opts  aiProviderChat()'s options with max_tokens and temperature filled in; system and user are read
 * @return array  {content, tokens_in, tokens_out, finish_reason, reasoning_tokens (always null here)}
 */
function aiProviderCallAnthropic(string $model, string $apiKey, bool $verify, array $opts): array
{
    $req = [
        'model'       => $model,
        'max_tokens'  => $opts['max_tokens'],
        'system'      => (string)($opts['system'] ?? ''),
        'messages'    => [['role' => 'user', 'content' => (string)($opts['user'] ?? '')]],
    ];
    // TRAP: the newer Claude models refuse `temperature` with a 400 - Haiku 5.5 and
    // Sonnet 5.5 accept only the default, Opus 4.7 onwards, Sonnet 5 and Fable not at
    // all - so sending our 0.0 to them failed EVERY AI feature (5.9.0). Only the older
    // models that accept it get it; everything newer runs at its default.
    if (aiAnthropicAcceptsTemperature($model)) $req['temperature'] = $opts['temperature'];
    $body = json_encode($req);

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
 *
 * @param array $cfg  the AI settings - azure_endpoint, azure_deployment and azure_api_version are read
 */
function aiAzureChatUrl(array $cfg): string
{
    $endpoint = trim((string)$cfg['azure_endpoint']);
    /* GH #171: the portal shows a whole "Target URI" beside each deployment
       (.../openai/deployments/x/chat/completions?api-version=...), and a Foundry
       project shows .../api/projects/<name>. Either pasted into the endpoint box
       used to be doubled up into an address Azure answers with 404. Everything
       from the path onwards is ours to build, so keep only the resource. */
    foreach (['/openai/', '/api/projects/', '/models/'] as $cut) {
        $at = stripos($endpoint . '/', $cut);
        if ($at !== false) $endpoint = substr($endpoint, 0, $at);
    }
    $endpoint = rtrim($endpoint, '/');
    // Tolerate a pasted ".../openai" so it is not doubled below.
    if (substr($endpoint, -7) === '/openai') {
        $endpoint = substr($endpoint, 0, -7);
    }
    if (aiAzureIsV1($cfg)) {
        return $endpoint . '/openai/v1/chat/completions';
    }
    $deployment = trim((string)$cfg['azure_deployment']);
    $version    = trim((string)($cfg['azure_api_version'] ?? '')) ?: AI_AZURE_DEFAULT_API_VERSION;

    return $endpoint . '/openai/deployments/' . rawurlencode($deployment)
         . '/chat/completions?api-version=' . rawurlencode($version);
}

/**
 * An API version of "v1" means Azure's newer route, which has no api-version.
 *
 * @param array $cfg  the AI settings - only azure_api_version is read
 */
function aiAzureIsV1(array $cfg): bool
{
    return strtolower(trim((string)($cfg['azure_api_version'] ?? ''))) === 'v1';
}

/**
 * Whether an API version looks like one Azure publishes.
 *
 * ⚠️ GH #171: the portal shows a DATE beside every model - gpt-5-nano's is
 * 2025-08-07 - and it is the model's version, not an API version. It has exactly
 * the shape of one, Azure answers it with a bare 404 "Resource not found", and
 * nothing on that screen says which box was wrong. Used only to EXPLAIN a
 * failure, never to refuse a value: Azure publishes new versions and this list
 * will fall behind, which costs nothing because a working call never asks.
 *
 * @param string $v  the api-version as typed by the administrator; '' counts as valid (the default is used)
 */
function aiAzureApiVersionLooksValid(string $v): bool
{
    $v = strtolower(trim($v));
    if ($v === '' || in_array($v, ['v1', 'latest', 'preview'], true)) return true;
    if (preg_match('/^\d{4}-\d{2}-\d{2}-preview$/', $v)) return true;
    return in_array($v, ['2022-12-01', '2023-05-15', '2024-02-01', '2024-06-01', '2024-10-21'], true);
}

/**
 * The chat-completions URL of a custom OpenAI-compatible endpoint (GH #192).
 *
 * The administrator gives the BASE URL - for Ollama `http://host:11434/v1` - and
 * FreeITSM appends `/chat/completions`, as the OpenAI clients do. A pasted full
 * address (ending in /chat/completions) is cut back rather than doubled, and a
 * trailing slash is tolerated: all three are the same intention.
 *
 * @param array $cfg  the AI settings - only compatible_base_url is read
 */
function aiCompatibleChatUrl(array $cfg): string
{
    $base = rtrim(trim((string)($cfg['compatible_base_url'] ?? '')), '/');
    if (strtolower(substr($base, -17)) === '/chat/completions') {
        $base = substr($base, 0, -17);
    }
    return rtrim($base, '/') . '/chat/completions';
}

/**
 * The auth header for a custom endpoint: Bearer when a key is saved, none when not (GH #192).
 *
 * @param string $apiKey  the plaintext key; '' for a server that needs none
 */
function aiCompatibleAuthHeaders(string $apiKey): array
{
    return $apiKey !== '' ? ['Authorization: Bearer ' . $apiKey] : [];
}

/**
 * Whether a feature's AI settings are complete enough to try a call.
 *
 * TRAP: do not test `$cfg['api_key'] === ''` for "AI is not set up". That was the
 * test in every feature until GH #192, and it is wrong for a custom endpoint, whose
 * key is optional - a local Ollama with no key would have been reported as "not
 * configured" by every feature while the Test button said it worked.
 *
 * @param array $cfg  a feature's AI settings, as aiSettingsLoad() returns them
 */
function aiConfigured(array $cfg): bool
{
    if (AiProvider::tryFrom((string)($cfg['provider'] ?? '')) === AiProvider::OpenAICompatible) {
        return trim((string)($cfg['compatible_base_url'] ?? '')) !== ''
            && trim((string)($cfg['model'] ?? '')) !== '';
    }
    return (string)($cfg['api_key'] ?? '') !== '';
}

/**
 * Whether a custom endpoint's address is one FreeITSM will send to: http or https
 * with a host, and nothing else (GH #192). Used when saving, so a typo is caught
 * on the settings page rather than at the first AI request.
 *
 * @param string $url  the base URL as typed
 */
function aiCompatibleBaseUrlValid(string $url): bool
{
    $url = trim($url);
    if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) return false;
    $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
    return in_array($scheme, ['http', 'https'], true) && (string)parse_url($url, PHP_URL_HOST) !== '';
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
 *
 * @param string $url  the full chat-completions URL, including Azure's api-version query string where needed
 * @param string $model  the model id to send; '' leaves the model field out (an Azure deployment-based endpoint)
 * @param array $authHeaders  the auth header lines - 'Authorization: Bearer ...' or Azure's 'api-key: ...'
 * @param bool $verify  the feature's verify-SSL setting, passed on to aiProviderHttpPost()
 * @param array $opts  aiProviderChat()'s options with max_tokens and temperature filled in; system, user, reasoning read
 * @param array $extraHeaders  further header lines, such as OpenRouter's HTTP-Referer and X-Title; [] for none
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
    if (($opts['reasoning'] ?? true) === false && str_contains($base, 'openrouter.ai')) {
        $payload['reasoning'] = ['enabled' => false];
    }

    $headers = array_merge($authHeaders, ['content-type: application/json'], $extraHeaders);

    $resp = aiOpenAICompatPost($url, $headers, $payload, $verify);
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
 * POST an OpenAI-format chat request, putting right the two parameters newer
 * models refuse by name.
 *
 * ⚠️ `max_tokens` VS `max_completion_tokens`. Newer deployments — the o-series
 * and after — refuse `max_tokens` outright and say so by name. The two mean the
 * same thing here, and which one is required depends on the deployed model
 * rather than on anything we can see from the config, so the only honest way to
 * know is to be told.
 *
 * ⚠️ `temperature`. The same models (GH #171: gpt-5-nano) accept only their
 * default temperature and refuse 0 with "Unsupported value: 'temperature'".
 * Leaving it out means "the model's default", which is all they allow.
 *
 * Each fix is applied at most once, and only when the provider's own words name
 * it — so this cannot loop, and it cannot fire on an unrelated 400 such as a bad
 * key or a content filter. $payload is passed by reference so a caller making
 * several calls (tool calling) learns the fix once rather than per round.
 *
 * @param string $url  the full chat-completions URL to POST to
 * @param array $headers  every header line to send, auth and content-type included
 * @param array $payload  the request body before JSON encoding; by reference, so a corrected field stays corrected
 * @param bool $verify  the feature's verify-SSL setting, passed on to aiProviderHttpPost()
 */
function aiOpenAICompatPost(string $url, array $headers, array &$payload, bool $verify): array
{
    for ($fixes = 0; ; $fixes++) {
        try {
            $resp = aiProviderHttpPost($url, $headers, json_encode($payload), $verify);
            /* GH #192: a custom address can answer 200 with JSON that is not a chat
               completion at all - a gateway's status page, another API. Reading it as
               an empty answer would blame the model; say what it is instead. Every
               real chat-completions server, Azure's content filter included, sends
               `choices`. */
            if (!isset($resp['data']['choices'][0])) {
                throw new AiResponseFormatError('The address answered, but not with a chat completion (no "choices" in the reply). Check the base URL points at the OpenAI-compatible API, usually ending in /v1.');
            }
            return $resp;
        } catch (AiResponseFormatError $e) {
            throw $e;
        } catch (RuntimeException $e) {
            $msg = $e->getMessage();
            if ($fixes >= 2) throw $e;
            if (array_key_exists('max_tokens', $payload) && str_contains($msg, 'max_completion_tokens')) {
                $payload['max_completion_tokens'] = $payload['max_tokens'];
                unset($payload['max_tokens']);
                continue;
            }
            if (array_key_exists('temperature', $payload) && stripos($msg, 'temperature') !== false
                && (stripos($msg, 'unsupported') !== false || stripos($msg, 'not support') !== false)) {
                unset($payload['temperature']);
                continue;
            }
            throw $e;
        }
    }
}

/**
 * Record every HTTP exchange this file makes until aiProviderTraceTake().
 *
 * For debug tool D014 (GH #171: "is there a way to inspect the payload and
 * response without dev tools?"). Browser dev tools can never show it, because
 * the call is made by the SERVER - the browser only ever talks to FreeITSM.
 * Off unless started, so no ordinary request pays for it.
 */
function aiProviderTraceStart(): void
{
    $GLOBALS['AI_PROVIDER_TRACE'] = [];
}

/** Stop recording and return what was recorded. */
function aiProviderTraceTake(): array
{
    $t = $GLOBALS['AI_PROVIDER_TRACE'] ?? [];
    unset($GLOBALS['AI_PROVIDER_TRACE']);
    return is_array($t) ? $t : [];
}

/**
 * What a failed call most likely means, in a sentence an administrator can act
 * on - or '' when the error says it plainly enough already.
 *
 * Shared by the Test button and debug tool D014, so the two never disagree.
 *
 * @param array $cfg  the AI settings the call used - provider, model, azure_deployment and azure_api_version are read
 * @param string $msg  the failure's message, as thrown by aiProviderHttpPost() (includes "(HTTP nnn)")
 */
function aiExplainFailure(array $cfg, string $msg): string
{
    // tryFrom: explaining a failure must not itself throw on an unknown provider.
    $p        = AiProvider::tryFrom((string)($cfg['provider'] ?? ''));
    $azure    = $p === AiProvider::Azure;
    $version  = trim((string)($cfg['azure_api_version'] ?? ''));

    if ($p === AiProvider::OpenAICompatible) {
        $hint = aiExplainCompatibleFailure($cfg, $msg);
        if ($hint !== '') return $hint;
    }
    if (stripos($msg, 'Network error') !== false || stripos($msg, 'Could not resolve') !== false
        || stripos($msg, 'timed out') !== false || stripos($msg, 'certificate') !== false) {
        return 'The request never reached the provider. That is a network, DNS or certificate problem on this server rather than a setting - debug tool D006 checks HTTPS from here.';
    }
    if (str_contains($msg, '(HTTP 401)') || str_contains($msg, '(HTTP 403)')) {
        return $azure
            ? 'Azure refused the key. Keys belong to one resource, so a key from a different resource is refused even when the endpoint and deployment are right.'
            : 'The provider refused the key. Check it was copied in full and belongs to this provider.';
    }
    if ($azure && stripos($msg, 'content') !== false && (stripos($msg, 'filter') !== false || stripos($msg, 'management policy') !== false)) {
        return 'Azure\'s content filter refused the request. That is a policy on the deployment, not a fault here.';
    }
    if ($azure && stripos($msg, 'api version') !== false && stripos($msg, 'enabled only') !== false) {
        return 'This model needs a newer API version than the one in use. Copy the api-version from the end of the deployment\'s Target URI in the portal, or enter v1.';
    }
    if (str_contains($msg, '(HTTP 404)')) {
        if (!$azure) {
            return 'The provider does not recognise the model "' . trim((string)($cfg['model'] ?? '')) . '". Check its exact name in the provider\'s model list.';
        }
        if (stripos($msg, 'DeploymentNotFound') !== false || stripos($msg, 'deployment for this resource does not exist') !== false) {
            return 'Azure found the resource but no deployment called "' . trim((string)($cfg['azure_deployment'] ?? '')) . '" on it. That is the name you chose under Deployments, which need not match the model.';
        }
        if (!aiAzureApiVersionLooksValid($version)) {
            return 'Azure answers 404 when it does not recognise the API version, and "' . $version . '" does not look like one. The date shown beside a model in the portal is the MODEL\'s version, not the API version. Leave the box blank, enter v1, or copy the api-version from the end of the deployment\'s Target URI.';
        }
        return 'Azure did not recognise the address. That is usually the API version (too old or too new for this deployment), sometimes the endpoint.';
    }
    if (str_contains($msg, '(HTTP 429)')) {
        return 'The provider is rate limiting this key, or its quota or spending limit is used up.';
    }
    return '';
}

/**
 * What a custom endpoint's failure most likely means (GH #192), or '' to fall
 * through to the general explanations.
 *
 * ⚠️ The request is made by the FreeITSM SERVER. "localhost" in the base URL is
 * that server - in Docker, the FreeITSM container - not the machine the
 * administrator is sitting at, and not the host running the model. That is the
 * commonest way a working Ollama looks broken, so it is named first.
 *
 * @param array $cfg  the AI settings the call used - compatible_base_url and model are read
 * @param string $msg  the failure's message, as thrown by aiProviderHttpPost()
 */
function aiExplainCompatibleFailure(array $cfg, string $msg): string
{
    $host  = strtolower((string)parse_url(trim((string)($cfg['compatible_base_url'] ?? '')), PHP_URL_HOST));
    $local = in_array($host, ['localhost', '127.0.0.1', '::1', '[::1]'], true);
    $model = trim((string)($cfg['model'] ?? ''));

    if (stripos($msg, 'Network error') !== false && (stripos($msg, 'connect') !== false || stripos($msg, 'refused') !== false)) {
        return $local
            ? 'Nothing answered at that address. The request comes from the FreeITSM server, so "' . $host . '" means the FreeITSM server itself - in Docker, the FreeITSM container, not the machine running the model. Use the model host\'s name or address (with Docker Desktop, host.docker.internal).'
            : 'Nothing answered at that address and port from the FreeITSM server. Check the model server is running, is listening on a network address rather than only on its own localhost (Ollama: OLLAMA_HOST=0.0.0.0), and that no firewall is in the way.';
    }
    if (stripos($msg, 'timed out') !== false) {
        return 'The model server took longer than ' . AI_PROVIDER_HTTP_TIMEOUT . ' seconds to answer. A large model on a CPU can; a smaller model, or one already loaded into memory, answers faster.';
    }
    if (stripos($msg, 'tool') !== false && (stripos($msg, 'support') !== false || stripos($msg, 'enable-auto-tool-choice') !== false)) {
        return 'This model does not support tool calling, which this feature needs. Choose a model that does - Ollama marks them "tools" in its library - or use a different provider for this feature.';
    }
    if (str_contains($msg, '(HTTP 401)') || str_contains($msg, '(HTTP 403)')) {
        return 'The server refused the request. It needs an API key, or the saved one is wrong.';
    }
    if (str_contains($msg, '(HTTP 404)')) {
        return stripos($msg, 'model') !== false
            ? 'The server does not have a model called "' . $model . '". Use its exact name as the server lists it (Ollama: ollama list), and pull it first if it is not there.'
            : 'Nothing was found at that address. The base URL is usually the server\'s address ending in /v1 (Ollama: http://host:11434/v1) - FreeITSM adds /chat/completions itself.';
    }
    if (stripos($msg, 'not with a chat completion') !== false || stripos($msg, 'Syntax error') !== false) {
        return 'Something answered, but it is not an OpenAI-compatible API. The base URL is usually the server\'s address ending in /v1.';
    }
    return '';
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
 * $opts['history'] (3.3.0, the Projects assistant): earlier turns of the same
 * conversation, oldest first, as [['role' => 'user'|'assistant', 'content' => text], ...].
 * They go in before $opts['user'], so a conversation resumes rather than starting cold.
 * Plain text only - the tool calls of earlier turns are not replayed.
 *
 * Azure (3.3.0): the OpenAI wire format at the deployment's address, behind an
 * api-key header and with no model in the body, as aiProviderChat() already does.
 * Before this it fell through to api.openai.com with the Azure key, so tool
 * calling - Warbot - could not work on Azure at all.
 *
 * @param array    $cfg      the AI settings - provider, model, api_key, verify_ssl, base_url and azure_* are read
 * @param array    $opts     system, user, history, max_rounds (1-6, default 4), max_tokens, temperature, referer, title
 * @param array    $tools    [['name'=>…,'description'=>…,'schema'=>[JSON Schema]], …]
 * @param callable $runTool  fn(string $name, array $args): string
 * @return array ['content','calls'=>[['name','args','result'],…],'tokens_in','tokens_out','provider','model','duration_ms']
 */
function aiProviderChatTools(array $cfg, array $opts, array $tools, callable $runTool): array
{
    $p        = AiProvider::fromConfig($cfg);   // throws on an unknown provider
    $provider = $p->value;
    $model    = trim((string)($cfg['model'] ?? ''));
    $apiKey   = (string)($cfg['api_key'] ?? '');
    $verify   = !empty($cfg['verify_ssl']);

    if ($apiKey === '' && $p->needsApiKey()) throw new RuntimeException('No API key configured.');
    if ($p === AiProvider::OpenAICompatible && trim((string)($cfg['compatible_base_url'] ?? '')) === '') {
        throw new RuntimeException('No base URL configured.');
    }
    if ($p === AiProvider::Azure) {
        if (trim((string)($cfg['azure_endpoint'] ?? '')) === '' || trim((string)($cfg['azure_deployment'] ?? '')) === '') {
            throw new RuntimeException('No Azure endpoint or deployment configured.');
        }
        $model = trim((string)$cfg['azure_deployment']);
    }
    if ($model  === '') throw new RuntimeException('No model configured.');
    // Earlier turns of the conversation (3.3.0), text only, oldest first.
    $history = [];
    foreach ((array)($opts['history'] ?? []) as $h) {
        $role = ($h['role'] ?? '') === 'assistant' ? 'assistant' : 'user';
        $text = trim((string)($h['content'] ?? ''));
        if ($text !== '') $history[] = ['role' => $role, 'content' => $text];
    }
    // Anthropic wants turns to alternate, starting with the user: merge neighbours
    // from the same side, and drop a leading assistant turn.
    $alt = [];
    foreach ($history as $h) {
        if ($alt && end($alt)['role'] === $h['role']) { $alt[count($alt) - 1]['content'] .= "\n\n" . $h['content']; continue; }
        if (!$alt && $h['role'] === 'assistant') $alt[] = ['role' => 'user', 'content' => '(Earlier in this conversation.)'];
        $alt[] = $h;
    }
    if ($alt && end($alt)['role'] === 'user') $alt[] = ['role' => 'assistant', 'content' => '(Noted.)'];
    $history = $alt;

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

    if ($p === AiProvider::Anthropic) {
        $messages = array_merge($history, [['role' => 'user', 'content' => (string)($opts['user'] ?? '')]]);
        $wire = array_map(function ($t) {
            return ['name' => $t['name'], 'description' => $t['description'], 'input_schema' => $t['schema']];
        }, $tools);

        for ($round = 0; $round < $maxRounds; $round++) {
            $req = [
                'model'       => $model,
                'max_tokens'  => $maxTokens,
                'system'      => (string)($opts['system'] ?? ''),
                'tools'       => $wire,
                'messages'    => $messages,
            ];
            // TRAP: newer Claude models refuse `temperature` - see aiAnthropicAcceptsTemperature().
            if (aiAnthropicAcceptsTemperature($model)) $req['temperature'] = $temperature;
            $body = json_encode($req);
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

    // ── OpenAI-compatible: openai, openrouter, azure and a custom endpoint ──
    // The case supplies the address and headers (AiProvider::chatUrl() etc).
    $headers = array_merge($p->authHeaders($apiKey), ['content-type: application/json'], $p->extraHeaders($opts));

    $messages = array_merge(
        [['role' => 'system', 'content' => (string)($opts['system'] ?? '')]],
        $history,
        [['role' => 'user',   'content' => (string)($opts['user']   ?? '')]]
    );
    $wire = array_map(function ($t) {
        return ['type' => 'function', 'function' => [
            'name' => $t['name'], 'description' => $t['description'], 'parameters' => $t['schema'],
        ]];
    }, $tools);

    // The token and temperature fields, as corrected by aiOpenAICompatPost() -
    // carried across rounds so a refusal is learned once, not every round.
    $params = ['max_tokens' => $maxTokens, 'temperature' => $temperature];
    for ($round = 0; $round < $maxRounds; $round++) {
        $payload = ['model' => $model] + $params + [
            'tools'       => $wire,
            'messages'    => $messages,
        ];
        // Azure: the deployment decides the model (see aiProviderChat) - except on v1.
        if ($p === AiProvider::Azure && !aiAzureIsV1($cfg)) unset($payload['model']);
        // A custom endpoint goes to the administrator's own address - never api.openai.com (GH #192).
        $resp = aiOpenAICompatPost($p->chatUrl($cfg), $headers, $payload, $verify);
        $params = array_intersect_key($payload, array_flip(['max_tokens', 'max_completion_tokens', 'temperature']));
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

/**
 * Shared return shape for aiProviderChatTools.
 *
 * @param string $content  the model's final answer text; '' when the round limit ran out
 * @param array $calls  every tool call made, as [['name','args','result'], ...]
 * @param int $tokIn  input tokens summed over every round
 * @param int $tokOut  output tokens summed over every round
 * @param string $provider  the provider used (anthropic, openai, openrouter or azure)
 * @param string $model  the model id, or the Azure deployment name
 * @param float $start  microtime(true) when the conversation began, for duration_ms
 */
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
 *
 * @param string $url  the full URL to POST to
 * @param array $headers  every header line to send
 * @param string $body  the request body, already JSON-encoded
 * @param bool $verifySsl  the feature's verify-SSL setting; not applied - sslApplyCurl() uses the install-wide setting
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
            // GH #192: an administrator can now name the address, so only ever speak
            // HTTP(S) to it - never file://, gopher:// or the like. Redirects are not
            // followed (cURL's default), so the address saved is the one used.
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        ]);
        sslApplyCurl($ch);
        $t0   = microtime(true);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        if (isset($GLOBALS['AI_PROVIDER_TRACE']) && is_array($GLOBALS['AI_PROVIDER_TRACE'])) {
            $GLOBALS['AI_PROVIDER_TRACE'][] = [
                'url' => $url, 'headers' => $headers, 'body' => $body, 'attempt' => $attempt,
                'code' => (int)$code, 'response' => $resp === false ? null : (string)$resp,
                'error' => $err, 'ms' => (int)((microtime(true) - $t0) * 1000),
            ];
        }

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

        // OpenAI's shape is {error:{message}}; Ollama's native errors and some
        // gateways send {error:"text"} - read both rather than reporting a bare code.
        $errMsg  = $data['error']['message'] ?? (is_string($data['error'] ?? null) ? $data['error'] : ('HTTP ' . $code));
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
 * @param bool $force  true to fetch afresh even when the 24-hour cache is still fresh
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
