<?php
/**
 * Knowledge-base retrieval for AI answers — the shared RAG building blocks.
 *
 * Factored out so more than one caller can "ask the Knowledge base" without each
 * re-implementing embedding search: the Knowledge module's own AI chat
 * (api/knowledge/ai_chat.php) and the web chat widget's AI answers
 * (includes/webchat/ai.php). Each caller supplies its OWN system prompt and makes its
 * own aiProviderChat() call — this file only finds the relevant articles and builds the
 * context block.
 *
 * NOTE: api/knowledge/ai_chat.php still carries its own equivalent copy for now; when
 * it's next touched it should be switched to these helpers. Kept separate here to avoid
 * refactoring a working endpoint as part of the web chat build.
 */

require_once __DIR__ . '/../encryption.php';

/** Cosine similarity of two equal-ish length vectors (0 if either is a zero vector). */
function kbCosineSimilarity(array $a, array $b): float
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

/** Embed a query with OpenAI's small embedding model. Returns the vector or null. */
function kbGenerateEmbedding(string $text, string $apiKey): ?array
{
    if (strlen($text) > 30000) {
        $text = substr($text, 0, 30000);
    }
    $ch = curl_init('https://api.openai.com/v1/embeddings');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['model' => 'text-embedding-3-small', 'input' => $text]));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'Authorization: Bearer ' . $apiKey]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, defined('SSL_VERIFY_PEER') ? SSL_VERIFY_PEER : true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200) {
        return null;
    }
    $data = json_decode($res, true);
    return $data['data'][0]['embedding'] ?? null;
}

/**
 * The rows this file will ever consider: published, and NOT in the recycle bin.
 *
 * `is_archived` matters as much as `is_published` here. Archiving does NOT
 * unpublish (KnowledgeService::archiveArticle only sets is_archived), so an
 * article deleted into the recycle bin keeps is_published = 1 — and every other
 * reader in the codebase pairs the two checks. Omitting it here meant a deleted
 * article was still being fed to anonymous web chat visitors.
 */
const KB_VISIBLE_SQL = "is_published = 1 AND (is_archived = 0 OR is_archived IS NULL)";

/**
 * Find the published Knowledge articles most relevant to a question. Uses embedding
 * similarity when an OpenAI key + embedded articles exist, else returns all published
 * articles. Returns ['articles' => [{id,title,body}], 'method' => 'vector'|'all'].
 *
 * (KB is not yet company-scoped — no tenant_id on knowledge_articles — so this searches
 * all published articles. Add a tenant filter here once Knowledge multi-tenancy lands.)
 */
function kbRetrieveArticles(PDO $conn, string $question, int $limit = 5): array
{
    $openaiKey = '';
    try {
        $st = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'knowledge_openai_api_key'");
        $st->execute();
        $openaiKey = decryptValue((string) ($st->fetchColumn() ?: ''));
    } catch (Exception $e) { $openaiKey = ''; }

    $haveEmbeddings = false;
    try {
        $cnt = $conn->query("SELECT COUNT(*) FROM knowledge_articles WHERE " . KB_VISIBLE_SQL . " AND embedding IS NOT NULL AND LENGTH(embedding) > 0")->fetchColumn();
        $haveEmbeddings = (int) $cnt > 0;
    } catch (Exception $e) { $haveEmbeddings = false; }

    if ($openaiKey !== '' && $haveEmbeddings) {
        $qvec = kbGenerateEmbedding($question, $openaiKey);
        if ($qvec) {
            $rows = $conn->query("SELECT id, title, body, embedding FROM knowledge_articles WHERE " . KB_VISIBLE_SQL . " AND embedding IS NOT NULL AND LENGTH(embedding) > 0")->fetchAll(PDO::FETCH_ASSOC);
            $scored = [];
            foreach ($rows as $r) {
                $vec = json_decode($r['embedding'], true);
                if (is_array($vec)) {
                    $scored[] = ['id' => $r['id'], 'title' => $r['title'], 'body' => $r['body'], 'similarity' => kbCosineSimilarity($qvec, $vec)];
                }
            }
            usort($scored, function ($a, $b) { return $b['similarity'] <=> $a['similarity']; });
            return ['articles' => array_slice($scored, 0, $limit), 'method' => 'vector'];
        }
    }

    // Fallback: all published articles (context is capped by kbBuildContext anyway).
    $all = $conn->query("SELECT id, title, body FROM knowledge_articles WHERE " . KB_VISIBLE_SQL . " ORDER BY title")->fetchAll(PDO::FETCH_ASSOC);
    return ['articles' => array_slice($all, 0, max($limit, 10)), 'method' => 'all'];
}

/** Turn retrieved articles into a plain-text context block for the AI system prompt. */
function kbBuildContext(array $articles): string
{
    $ctx = '';
    foreach ($articles as $a) {
        $plain = trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($a['body'] ?? ''), ENT_QUOTES, 'UTF-8')));
        if ($plain !== '') {
            $ctx .= '=== Article: ' . ($a['title'] ?? '') . " ===\n" . $plain . "\n\n";
        }
    }
    return $ctx;
}
