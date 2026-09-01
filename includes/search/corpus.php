<?php
/**
 * The search corpus — the ONE place anything writes to `search_documents`.
 *
 * A corpus row is DERIVED. Every one of them is rebuildable from its source, so
 * losing the table costs a reindex and nothing else. That is deliberate: it is
 * what stops a future change of search engine from turning into a re-extraction
 * of every attachment ever received.
 *
 * Two rules hold this together:
 *
 *  1. NOTHING ELSE WRITES TO THE TABLE. Indexing is an upsert keyed on
 *     (source_type, source_id), so re-indexing the same source updates in place
 *     and can never duplicate it.
 *
 *  2. `body` IS STRIPPED PLAINTEXT, never HTML. Indexing markup makes every
 *     ticket "contain" div, span and style, so a search for "table" matches the
 *     entire database.
 *
 * ⚠️ InnoDB does not expose rows written in an UNCOMMITTED transaction to
 * MATCH...AGAINST — its full-text cache is flushed at commit. Nothing here can
 * therefore write a row and then search for it in the same transaction.
 */

/** Source kinds. The value is stored, so these are a contract, not a display list. */
const SEARCH_SOURCE_TICKET     = 'ticket';       // the subject, as its own row
const SEARCH_SOURCE_EMAIL      = 'email';        // one message in the conversation
const SEARCH_SOURCE_NOTE       = 'note';         // an internal or shared note
const SEARCH_SOURCE_ATTACHMENT = 'attachment';   // text extracted from a file
const SEARCH_SOURCE_KB_ARTICLE = 'kb_article';   // a knowledge article

/**
 * How a row's company scope should be read.
 *
 * ⚠️ This exists because NULL means OPPOSITE things in the source tables: a
 * ticket with tenant_id IS NULL belongs to the DEFAULT company, while a
 * knowledge article with tenant_id IS NULL is shared with EVERY company.
 * Resolving that at index time keeps the search query from having to know which
 * source_type it is looking at.
 */
const SEARCH_SCOPE_COMPANY = 'company';   // visible to tenant_id only
const SEARCH_SCOPE_DEFAULT = 'default';   // the source's NULL meant "the default company"
const SEARCH_SCOPE_SHARED  = 'shared';    // the source's NULL meant "every company"

/**
 * Turn an email/ticket-style tenant_id into the pair the corpus stores.
 * Mirrors ticketTenantFilter()'s reading of NULL.
 *
 * @return array{0:?int,1:string} [tenant_id, tenant_scope]
 */
function searchCorpusTicketScope(?int $tenantId): array {
    return $tenantId === null ? [null, SEARCH_SCOPE_DEFAULT] : [$tenantId, SEARCH_SCOPE_COMPANY];
}

/**
 * Turn a knowledge-article tenant_id into the pair the corpus stores.
 * NULL is the OPPOSITE of the ticket case — see [[project_knowledge_visibility]].
 *
 * @return array{0:?int,1:string}
 */
function searchCorpusArticleScope(?int $tenantId): array {
    return $tenantId === null ? [null, SEARCH_SCOPE_SHARED] : [$tenantId, SEARCH_SCOPE_COMPANY];
}

/**
 * Reduce HTML to the plain text worth indexing.
 *
 * Order matters: <script> and <style> bodies have to go BEFORE tags are
 * stripped, or their contents survive as text and every ticket ends up
 * "containing" a stylesheet.
 */
function searchCorpusPlainText(?string $html, int $maxChars = 1000000): string {
    if ($html === null || $html === '') return '';
    $t = preg_replace('~<(script|style)\b[^>]*>.*?</\1>~is', ' ', $html);
    $t = preg_replace('~<br\s*/?>|</p>|</div>|</tr>|</li>~i', "\n", (string)$t);
    $t = strip_tags((string)$t);
    $t = html_entity_decode($t, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $t = str_replace("\xC2\xA0", ' ', $t);            // &nbsp; survives decoding as U+00A0
    $t = preg_replace('~[ \t]+~u', ' ', $t);
    $t = preg_replace('~\n{3,}~', "\n\n", (string)$t);
    $t = trim((string)$t);
    if ($maxChars > 0 && mb_strlen($t, 'UTF-8') > $maxChars) {
        $t = mb_substr($t, 0, $maxChars, 'UTF-8');
    }
    return $t;
}

/**
 * Index one source row, or update it if already indexed.
 *
 * @param array $doc source_type, source_id, and optionally ticket_id, tenant_id,
 *                   tenant_scope, is_internal, title, body, source_datetime.
 */
function searchCorpusUpsert(PDO $conn, array $doc): void {
    $type = (string)($doc['source_type'] ?? '');
    $id   = (int)   ($doc['source_id']   ?? 0);
    if ($type === '' || $id <= 0) {
        throw new InvalidArgumentException('searchCorpusUpsert needs a source_type and a source_id');
    }

    $sql = "INSERT INTO search_documents
              (source_type, source_id, ticket_id, tenant_id, tenant_scope, is_internal,
               title, body, source_datetime, indexed_datetime)
            VALUES (?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP())
            ON DUPLICATE KEY UPDATE
               ticket_id        = VALUES(ticket_id),
               tenant_id        = VALUES(tenant_id),
               tenant_scope     = VALUES(tenant_scope),
               is_internal      = VALUES(is_internal),
               title            = VALUES(title),
               body             = VALUES(body),
               source_datetime  = VALUES(source_datetime),
               indexed_datetime = UTC_TIMESTAMP()";

    $conn->prepare($sql)->execute([
        $type,
        $id,
        isset($doc['ticket_id']) ? (int)$doc['ticket_id'] : null,
        isset($doc['tenant_id']) ? (int)$doc['tenant_id'] : null,
        (string)($doc['tenant_scope'] ?? SEARCH_SCOPE_COMPANY),
        !empty($doc['is_internal']) ? 1 : 0,
        mb_substr((string)($doc['title'] ?? ''), 0, 500, 'UTF-8'),
        (string)($doc['body'] ?? ''),
        $doc['source_datetime'] ?? null,
    ]);
}

/** Remove one source's row. Safe to call for something never indexed. */
function searchCorpusDelete(PDO $conn, string $sourceType, int $sourceId): void {
    $conn->prepare("DELETE FROM search_documents WHERE source_type = ? AND source_id = ?")
         ->execute([$sourceType, $sourceId]);
}

/** Remove every row for a ticket. The FK cascades on ticket DELETE; this is for soft deletes. */
function searchCorpusDeleteTicket(PDO $conn, int $ticketId): void {
    $conn->prepare("DELETE FROM search_documents WHERE ticket_id = ?")->execute([$ticketId]);
}

/** Is the corpus available? False on an install that has not run Database Verification. */
function searchCorpusReady(PDO $conn): bool {
    static $ready = null;
    if ($ready !== null) return $ready;
    try {
        $conn->query("SELECT 1 FROM search_documents LIMIT 1");
        $ready = true;
    } catch (Throwable $e) {
        $ready = false;
    }
    return $ready;
}
