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
require_once __DIR__ . '/audience.php';
require_once __DIR__ . '/../tenancy.php';

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
    sslApplyCurl($ch);
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
 * Find the Knowledge articles most relevant to a question. Uses embedding similarity
 * when an OpenAI key + embedded articles exist, else falls back to every in-scope
 * article. Returns ['articles' => [{id,title,body}], 'method' => 'vector'|'all'].
 *
 * SCOPE — both arguments narrow what can possibly be returned, and they answer
 * different questions:
 *   $tenantId — WHOSE articles: the company asking (e.g. a web chat widget's).
 *               Its own articles plus shared (tenant_id IS NULL) ones. NULL means
 *               no company context => shared only. No-op on a single-company install.
 *   $viewer   — WHO is reading, as an Audience:: level. Web chat is anonymous, so it
 *               passes Audience::PUBLIC and can only ever see articles an author has
 *               deliberately marked public.
 *
 * The default is Audience::PUBLIC — the MOST restrictive rung — on purpose. This
 * function's only caller is the anonymous web chat, so a future caller that forgets
 * to think about scope gets too little, never too much.
 *
 * Filtering happens in SQL, before scoring: it is both the security boundary and
 * strictly less work than decoding vectors we would discard.
 */
function kbRetrieveArticles(PDO $conn, string $question, int $limit = 5, ?int $tenantId = null, string $viewer = Audience::PUBLIC): array
{
    // Company scope + audience. Both degrade to no-op on installs that predate the
    // columns, where every article is shared and public by definition anyway.
    [$tenantSql, $tenantParams] = knowledgeTenantFilterForCompany($conn, $tenantId, '');
    [$audSql, $audParams] = tenancyColumnExists($conn, 'knowledge_articles', 'audience')
        ? Audience::sqlFilter($viewer, '')
        : ['', []];
    $scopeSql    = $tenantSql . $audSql;
    $scopeParams = array_merge($tenantParams, $audParams);

    $openaiKey = '';
    try {
        $st = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'knowledge_openai_api_key'");
        $st->execute();
        $openaiKey = decryptValue((string) ($st->fetchColumn() ?: ''));
    } catch (Exception $e) { $openaiKey = ''; }

    // "Are there embeddings?" must ask within the SAME scope. Counting the whole
    // install would take the vector path on the strength of articles this caller
    // cannot see, then score an empty set.
    $haveEmbeddings = false;
    try {
        $st = $conn->prepare("SELECT COUNT(*) FROM knowledge_articles WHERE " . KB_VISIBLE_SQL . $scopeSql . " AND embedding IS NOT NULL AND LENGTH(embedding) > 0");
        $st->execute($scopeParams);
        $haveEmbeddings = (int) $st->fetchColumn() > 0;
    } catch (Exception $e) { $haveEmbeddings = false; }

    if ($openaiKey !== '' && $haveEmbeddings) {
        $qvec = kbGenerateEmbedding($question, $openaiKey);
        if ($qvec) {
            $st = $conn->prepare("SELECT id, title, body, embedding FROM knowledge_articles WHERE " . KB_VISIBLE_SQL . $scopeSql . " AND embedding IS NOT NULL AND LENGTH(embedding) > 0");
            $st->execute($scopeParams);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
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

    // Fallback: every in-scope article (context is capped by kbBuildContext anyway).
    // This path is the one that would hurt most if unscoped — with no API key it
    // hands the caller the whole knowledge base.
    $st = $conn->prepare("SELECT id, title, body FROM knowledge_articles WHERE " . KB_VISIBLE_SQL . $scopeSql . " ORDER BY title");
    $st->execute($scopeParams);
    $all = $st->fetchAll(PDO::FETCH_ASSOC);
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
