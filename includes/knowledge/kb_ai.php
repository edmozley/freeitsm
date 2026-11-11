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
 * Turning text into a vector and comparing two live in includes/ai_embeddings.php,
 * shared with the rest of FreeITSM.
 */

require_once __DIR__ . '/../encryption.php';
require_once __DIR__ . '/../ai_embeddings.php';   // aiEmbed(), aiCosineSimilarity()
require_once __DIR__ . '/audience.php';
require_once __DIR__ . '/../tenancy.php';
require_once __DIR__ . '/visibility.php';

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
 * ⚠️ $viewer ALSO accepts a KnowledgeViewer, and that is the form to use. An
 * Audience:: string names a RUNG; it cannot name a PERSON, so it can never carry
 * the access list — and this is the retrieval path where a missed filter is the
 * worst kind of leak, because the model paraphrases the article into an answer
 * and nothing records that the article was ever read. The string form is kept so
 * existing callers (and the harness that guards them) stay valid; when a
 * KnowledgeViewer is passed it is authoritative and $tenantId is ignored, since
 * the viewer already carries its own company.
 *
 * Filtering happens in SQL, before scoring: it is both the security boundary and
 * strictly less work than decoding vectors we would discard. Filtering the
 * ANSWER afterwards would not work at all — by then the model has read the text.
 *
 * @param string $question  the reader's question, embedded and compared with each article
 * @param int $limit  the most articles returned on the vector path (the fallback returns at least 10)
 * @param mixed $viewer  a KnowledgeViewer (preferred, authoritative), or an Audience:: level string such as 'public'
 */
function kbRetrieveArticles(PDO $conn, string $question, int $limit = 5, ?int $tenantId = null, $viewer = Audience::PUBLIC): array
{
    // One clause for lifecycle + company + audience + access list. KB_VISIBLE_SQL
    // below is now only the 'live' half of it and stays for the constant's other
    // readers; this call is what makes the AI path inherit every future axis
    // without anyone remembering to come back here.
    $kv = ($viewer instanceof KnowledgeViewer)
        ? $viewer
        : KnowledgeViewer::fromLegacyAudienceString($conn, $tenantId, (string) $viewer);

    [$scopeSql, $scopeParams] = knowledgeVisibilitySql($conn, $kv, '', ['lifecycle' => 'live']);

    $openaiKey = '';
    try {
        $st = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'knowledge_openai_api_key'");
        $st->execute();
        $openaiKey = decryptValue((string) ($st->fetchColumn() ?: ''));
    } catch (Exception) { $openaiKey = ''; }

    // "Are there embeddings?" must ask within the SAME scope. Counting the whole
    // install would take the vector path on the strength of articles this caller
    // cannot see, then score an empty set.
    $haveEmbeddings = false;
    try {
        $st = $conn->prepare("SELECT COUNT(*) FROM knowledge_articles WHERE 1=1" . $scopeSql . " AND embedding IS NOT NULL AND LENGTH(embedding) > 0");
        $st->execute($scopeParams);
        $haveEmbeddings = (int) $st->fetchColumn() > 0;
    } catch (Exception) { $haveEmbeddings = false; }

    if ($openaiKey !== '' && $haveEmbeddings) {
        $qvec = aiEmbed($question, $openaiKey);
        if ($qvec) {
            $st = $conn->prepare("SELECT id, title, body, embedding FROM knowledge_articles WHERE 1=1" . $scopeSql . " AND embedding IS NOT NULL AND LENGTH(embedding) > 0");
            $st->execute($scopeParams);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            $scored = [];
            foreach ($rows as $r) {
                $vec = json_decode($r['embedding'], true);
                if (is_array($vec)) {
                    $scored[] = ['id' => $r['id'], 'title' => $r['title'], 'body' => $r['body'], 'similarity' => aiCosineSimilarity($qvec, $vec)];
                }
            }
            usort($scored, function ($a, $b) { return $b['similarity'] <=> $a['similarity']; });
            return ['articles' => array_slice($scored, 0, $limit), 'method' => 'vector'];
        }
    }

    // Fallback: every in-scope article (context is capped by kbBuildContext anyway).
    // This path is the one that would hurt most if unscoped — with no API key it
    // hands the caller the whole knowledge base.
    $st = $conn->prepare("SELECT id, title, body FROM knowledge_articles WHERE 1=1" . $scopeSql . " ORDER BY title");
    $st->execute($scopeParams);
    $all = $st->fetchAll(PDO::FETCH_ASSOC);
    return ['articles' => array_slice($all, 0, max($limit, 10)), 'method' => 'all'];
}

/**
 * Turn retrieved articles into a plain-text context block for the AI system prompt.
 *
 * @param array $articles  the 'articles' list from kbRetrieveArticles() - rows with title and HTML body
 */
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
