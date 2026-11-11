<?php
/**
 * Embeddings - the ONE place FreeITSM turns text into a vector and compares two.
 *
 * Used by the Knowledge module (article embeddings, its AI chat, the web chat's
 * answers, gap analysis) and by the help assistant (wiki Help-Assistant-Design).
 * Before this file there were four copies of the OpenAI call and two of the
 * comparison.
 *
 * Providers (aiEmbedBatch()): OpenAI, Azure OpenAI, and any OpenAI-compatible
 * server (Ollama, LM Studio, vLLM...). Anthropic has no embedding model.
 *
 * 🔴 Vectors from different models cannot be compared - a question must be
 * embedded by exactly the model its candidates were. aiEmbeddingModelId() is the
 * identity to store beside every vector and to check before comparing.
 *
 * It knows nothing about articles, tickets or help - callers bring their own text,
 * settings and storage. Keys arrive decrypted; they are stored encrypted by
 * aiSettingsSave() (includes/ai_settings.php).
 */

require_once __DIR__ . '/ssl.php';

/** The model the Knowledge module's vectors are made with, and the help assistant's default. */
const AI_EMBEDDING_MODEL = 'text-embedding-3-small';

/**
 * The most text sent per input. The model takes ~8k tokens; at ~4 bytes a token,
 * 30,000 bytes stays under it.
 */
const AI_EMBEDDING_MAX_BYTES = 30000;

/** The providers that can embed. Anthropic and OpenRouter are not among them. */
const AI_EMBEDDING_PROVIDERS = ['openai', 'azure', 'openai_compatible'];

/** OpenAI's embedding models, for the dropdown and for Azure (whose deployments run these). */
const AI_EMBEDDING_OPENAI_MODELS = ['text-embedding-3-small', 'text-embedding-3-large', 'text-embedding-ada-002'];

// ─── The Knowledge module's calls (OpenAI, its own key) ──────────────────────

/**
 * Embed a text with OpenAI's small model, with the reason when it fails.
 *
 * TRAP: the text is cut with mb_strcut(), never substr(). substr() at a byte limit
 * can split a multi-byte character (é, ł, any non-Latin script), and json_encode()
 * then refuses the whole string and returns false - the request was sent with no
 * text and the embedding silently never made. All four old copies did this.
 *
 * @param string $text  the text to embed; cut to AI_EMBEDDING_MAX_BYTES on a character boundary
 * @param string $apiKey  the OpenAI API key, decrypted
 * @return array{embedding: ?array, error: ?string}  the vector, or null and why
 */
function aiEmbedRequest(string $text, string $apiKey): array
{
    $r = aiEmbedBatch(['provider' => 'openai', 'api_key' => $apiKey, 'model' => AI_EMBEDDING_MODEL], [$text]);
    return ['embedding' => $r['vectors'][0] ?? null, 'error' => $r['error']];
}

/**
 * The JSON sent to OpenAI for one text: the model and the text, cut on a character
 * boundary (see the TRAP on aiEmbedRequest()). Null only if it cannot be encoded.
 *
 * @param string $text  the text to embed
 */
function aiEmbeddingRequestBody(string $text): ?string
{
    return aiEmbeddingPayload(['provider' => 'openai', 'model' => AI_EMBEDDING_MODEL], [$text]);
}

/**
 * Embed a text; the vector, or null on any failure. For callers that treat an
 * embedding as a bonus and do not report why.
 *
 * @param string $text  the text to embed
 * @param string $apiKey  the OpenAI API key, decrypted
 */
function aiEmbed(string $text, string $apiKey): ?array
{
    return aiEmbedRequest($text, $apiKey)['embedding'];
}

// ─── Any provider ───────────────────────────────────────────────────────────

/**
 * Embed several texts in one request.
 *
 * @param array $cfg  provider ('openai' | 'azure' | 'openai_compatible'), api_key (decrypted),
 *                    model, and for Azure azure_endpoint / azure_deployment / azure_api_version,
 *                    for a compatible server compatible_base_url; optional dimensions (int) -
 *                    OpenAI's text-embedding-3 models can return shorter vectors
 * @param list<string> $texts  each cut to AI_EMBEDDING_MAX_BYTES on a character boundary
 * @return array{vectors: list<?array>, error: ?string}  one vector per text, in order; all null and why on failure
 */
function aiEmbedBatch(array $cfg, array $texts): array
{
    $none = ['vectors' => array_fill(0, count($texts), null), 'error' => null];
    if (!$texts) return $none;
    $provider = (string)($cfg['provider'] ?? 'openai');
    if (!in_array($provider, AI_EMBEDDING_PROVIDERS, true)) return ['error' => "$provider cannot make embeddings"] + $none;
    $body = aiEmbeddingPayload($cfg, $texts);
    if ($body === null) return ['error' => 'The text could not be encoded'] + $none;

    $ch = curl_init(aiEmbeddingUrl($cfg));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array_merge(['Content-Type: application/json'], aiEmbeddingHeaders($cfg)));
    sslApplyCurl($ch);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    unset($ch);   // curl_close() has done nothing since PHP 8.0 and is deprecated in 8.5

    if ($curlError) return ['error' => 'Connection error: ' . $curlError] + $none;
    $data = json_decode((string)$response, true);
    if ($httpCode !== 200) {
        $label = $provider === 'openai' ? 'OpenAI API error: ' : 'Embedding API error: ';
        return ['error' => $label . ($data['error']['message'] ?? (is_string($data['error'] ?? null) ? $data['error'] : 'API returned HTTP ' . $httpCode))] + $none;
    }
    $vectors = $none['vectors'];
    foreach ((array)($data['data'] ?? []) as $i => $row) {
        $at = isset($row['index']) ? (int)$row['index'] : $i;   // the answer says which input it is
        if (array_key_exists($at, $vectors) && is_array($row['embedding'] ?? null) && $row['embedding']) $vectors[$at] = $row['embedding'];
    }
    if (in_array(null, $vectors, true)) return ['error' => 'Invalid embedding response'] + $none;
    return ['vectors' => $vectors, 'error' => null];
}

/**
 * The JSON body for an embedding request. Azure's deployment URLs name the model
 * in the path, so it is left out there; dimensions are only sent when asked for -
 * a compatible server may reject a field it does not know.
 *
 * @param list<string> $texts
 */
function aiEmbeddingPayload(array $cfg, array $texts): ?string
{
    $in = [];
    foreach ($texts as $t) {
        $t = (string)$t;
        $in[] = strlen($t) > AI_EMBEDDING_MAX_BYTES ? mb_strcut($t, 0, AI_EMBEDDING_MAX_BYTES, 'UTF-8') : $t;
    }
    $payload = ['input' => count($in) === 1 ? $in[0] : $in];
    $azureDeployment = ($cfg['provider'] ?? '') === 'azure' && !aiEmbeddingAzureV1($cfg);
    if (!$azureDeployment) $payload = ['model' => (string)($cfg['model'] ?? AI_EMBEDDING_MODEL)] + $payload;
    if (!empty($cfg['dimensions'])) $payload['dimensions'] = (int)$cfg['dimensions'];
    $body = json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE);
    return $body === false ? null : $body;
}

/** Where to send an embedding request. */
function aiEmbeddingUrl(array $cfg): string
{
    switch ($cfg['provider'] ?? 'openai') {
        case 'azure':
            $endpoint = trim((string)($cfg['azure_endpoint'] ?? ''));
            // Keep only the resource - a pasted Target URI or ".../openai" is cut back (as aiAzureChatUrl() does).
            foreach (['/openai/', '/api/projects/', '/models/'] as $cut) {
                $at = stripos($endpoint . '/', $cut);
                if ($at !== false) $endpoint = substr($endpoint, 0, $at);
            }
            $endpoint = preg_replace('~/openai$~i', '', rtrim($endpoint, '/'));
            if (aiEmbeddingAzureV1($cfg)) return $endpoint . '/openai/v1/embeddings';
            $version = trim((string)($cfg['azure_api_version'] ?? '')) ?: '2024-02-01';
            return $endpoint . '/openai/deployments/' . rawurlencode(trim((string)($cfg['azure_deployment'] ?? ''))) . '/embeddings?api-version=' . rawurlencode($version);
        case 'openai_compatible':
            $base = preg_replace('~/(embeddings|chat/completions)$~i', '', rtrim(trim((string)($cfg['compatible_base_url'] ?? '')), '/'));
            return rtrim($base, '/') . '/embeddings';
        default:
            return 'https://api.openai.com/v1/embeddings';
    }
}

/** Azure's newer "v1" API takes the model in the body like OpenAI, not a deployment in the path. */
function aiEmbeddingAzureV1(array $cfg): bool
{
    return strtolower(trim((string)($cfg['azure_api_version'] ?? ''))) === 'v1';
}

/** The authentication header: Azure wants api-key, the rest a Bearer token (a compatible server may need none). */
function aiEmbeddingHeaders(array $cfg): array
{
    $key = (string)($cfg['api_key'] ?? '');
    if (($cfg['provider'] ?? 'openai') === 'azure') return $key !== '' ? ['api-key: ' . $key] : [];
    return $key !== '' ? ['Authorization: Bearer ' . $key] : [];
}

/**
 * The identity of the vectors a configuration makes - store it beside every
 * vector, and only compare vectors with the same identity.
 *
 * OpenAI and Azure OpenAI running the same model make the same vectors, so they
 * share an identity ("openai:text-embedding-3-small@512"); for Azure the model is
 * the one its deployment runs. A compatible server's models are its own
 * ("compatible:nomic-embed-text").
 */
function aiEmbeddingModelId(array $cfg): string
{
    $family = ($cfg['provider'] ?? 'openai') === 'openai_compatible' ? 'compatible' : 'openai';
    $id = $family . ':' . trim((string)($cfg['model'] ?? ''));
    return !empty($cfg['dimensions']) ? $id . '@' . (int)$cfg['dimensions'] : $id;
}

/**
 * The embedding models a provider offers, for a dropdown - never a box to type a
 * model into. OpenAI: its embedding models, asked of the API when the key works,
 * else the known list. A compatible server: every model it lists (it cannot say
 * which embed). Azure: the OpenAI models - choose the one the deployment runs.
 *
 * @return array{models: list<string>, error: ?string}
 */
function aiEmbeddingModels(array $cfg): array
{
    $provider = (string)($cfg['provider'] ?? 'openai');
    if ($provider === 'azure') return ['models' => AI_EMBEDDING_OPENAI_MODELS, 'error' => null];
    $url = $provider === 'openai_compatible'
        ? preg_replace('~/embeddings$~', '/models', aiEmbeddingUrl($cfg))
        : 'https://api.openai.com/v1/models';
    if ($provider === 'openai' && ($cfg['api_key'] ?? '') === '') return ['models' => AI_EMBEDDING_OPENAI_MODELS, 'error' => null];
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, aiEmbeddingHeaders($cfg));
    sslApplyCurl($ch);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    unset($ch);
    $fallback = $provider === 'openai' ? AI_EMBEDDING_OPENAI_MODELS : [];
    if ($curlError || $httpCode !== 200) return ['models' => $fallback, 'error' => $curlError ?: 'The server answered HTTP ' . $httpCode];
    $ids = [];
    foreach ((array)(json_decode((string)$response, true)['data'] ?? []) as $m) {
        $id = (string)($m['id'] ?? '');
        if ($id === '') continue;
        if ($provider === 'openai' && !str_contains($id, 'embedding')) continue;
        $ids[] = $id;
    }
    sort($ids);
    return ['models' => $ids ?: $fallback, 'error' => null];
}

// ─── Storing and comparing ──────────────────────────────────────────────────

/**
 * A vector as compact text for storage: float32, base64 - about a third of the
 * size of JSON, and quicker to read back.
 *
 * @param list<float> $v
 */
function aiVectorPack(array $v): string
{
    return base64_encode(pack('g*', ...$v));
}

/** The vector aiVectorPack() stored. @return list<float> */
function aiVectorUnpack(string $packed): array
{
    $bin = base64_decode($packed, true);
    return $bin === false || $bin === '' ? [] : array_values(unpack('g*', $bin));
}

/**
 * The text an article-like record is embedded as: its title, a blank line, then
 * the body as plain text (tags stripped, entities decoded, whitespace collapsed).
 *
 * @param string $title  the record's title
 * @param string $html  its body, as HTML
 */
function aiEmbeddingText(string $title, string $html): string
{
    // No /u: on invalid UTF-8 it would return null and drop the whole body.
    $plain = trim((string)preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES, 'UTF-8')));
    return $title . "\n\n" . $plain;
}

/**
 * Cosine similarity of two vectors: 1 is the same direction, 0 unrelated (and 0
 * when either is all zeros). Only the length both share is compared.
 *
 * @param array $a  a vector (list of floats)
 * @param array $b  another vector
 */
function aiCosineSimilarity(array $a, array $b): float
{
    $dot = 0.0; $na = 0.0; $nb = 0.0;
    $len = min(count($a), count($b));
    for ($i = 0; $i < $len; $i++) {
        $dot += $a[$i] * $b[$i];
        $na  += $a[$i] * $a[$i];
        $nb  += $b[$i] * $b[$i];
    }
    if ($na == 0.0 || $nb == 0.0) {
        return 0.0;
    }
    return $dot / (sqrt($na) * sqrt($nb));
}
