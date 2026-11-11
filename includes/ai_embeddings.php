<?php
/**
 * Embeddings - the ONE place FreeITSM turns text into a vector and compares two.
 *
 * Used by the Knowledge module (article embeddings, its AI chat, the web chat's
 * answers, gap analysis) and by the help assistant (wiki Help-Assistant-Design).
 * Before this file there were four copies of the OpenAI call and two of the
 * comparison; a second embedding provider (Azure OpenAI, a local model) is now a
 * change here, which every caller gets at once.
 *
 * It knows nothing about articles, tickets or help - callers bring their own text,
 * key and storage.
 */

require_once __DIR__ . '/ssl.php';

/** The model every stored vector so far was made with. Vectors from different models cannot be compared. */
const AI_EMBEDDING_MODEL = 'text-embedding-3-small';

/**
 * The most text sent in one request. The model takes ~8k tokens; at ~4 bytes a
 * token, 30,000 bytes stays under it.
 */
const AI_EMBEDDING_MAX_BYTES = 30000;

/**
 * Embed a text, with the reason when it fails.
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
    $body = aiEmbeddingRequestBody($text);
    if ($body === null) {
        return ['embedding' => null, 'error' => 'The text could not be encoded'];
    }

    $ch = curl_init('https://api.openai.com/v1/embeddings');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'Authorization: Bearer ' . $apiKey]);
    sslApplyCurl($ch);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    unset($ch);   // curl_close() has done nothing since PHP 8.0 and is deprecated in 8.5

    if ($curlError) {
        return ['embedding' => null, 'error' => 'Connection error: ' . $curlError];
    }
    $data = json_decode((string)$response, true);
    if ($httpCode !== 200) {
        return ['embedding' => null, 'error' => 'OpenAI API error: ' . ($data['error']['message'] ?? 'API returned HTTP ' . $httpCode)];
    }
    $embedding = $data['data'][0]['embedding'] ?? null;
    if (!$embedding || !is_array($embedding)) {
        return ['embedding' => null, 'error' => 'Invalid embedding response'];
    }
    return ['embedding' => $embedding, 'error' => null];
}

/**
 * The JSON sent to the embeddings API: the model and the text, cut on a character
 * boundary to AI_EMBEDDING_MAX_BYTES (see the TRAP on aiEmbedRequest()). Null only
 * if it cannot be encoded at all.
 *
 * @param string $text  the text to embed
 */
function aiEmbeddingRequestBody(string $text): ?string
{
    if (strlen($text) > AI_EMBEDDING_MAX_BYTES) {
        $text = mb_strcut($text, 0, AI_EMBEDDING_MAX_BYTES, 'UTF-8');
    }
    $body = json_encode(['model' => AI_EMBEDDING_MODEL, 'input' => $text], JSON_INVALID_UTF8_SUBSTITUTE);
    return $body === false ? null : $body;
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
