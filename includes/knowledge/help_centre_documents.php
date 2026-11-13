<?php
/**
 * Documents on a knowledge article, in the self-service Help Centre (GH #177).
 *
 * THE RULE, in one sentence: a document attached to an article can be
 * downloaded from the Help Centre by anyone who may read that article there,
 * unless that attachment is set to Hidden.
 *
 *   - "may read that article there" is NOT restated here. It is
 *     portalCanReadArticle() (includes/knowledge/portal_reader.php), built on the
 *     same portalKnowledgeScope() the Help Centre's own list and article use - so
 *     company, audience, published/archived and the access list all follow,
 *     including rules added later.
 *   - "Hidden" is document_links.show_in_help_centre = 0. It lives on the LINK,
 *     so the same file can be shown on one article and held back on another.
 *   - Links made before 3.7.0 start hidden; new ones on an article start shown.
 *     See documentLinkHelpCentreDefault() in includes/documents.php.
 *
 * Analyst-facing help: knowledge/help/documents-help-centre.php.
 *
 * @wiki Knowledge
 */

require_once __DIR__ . '/../documents.php';
require_once __DIR__ . '/audience.php';
require_once __DIR__ . '/portal_reader.php';

/**
 * Is this article one that customers could read in the Help Centre at all -
 * published, not archived, and with an audience that includes signed-in
 * customers? Drives the explanation on the analyst's Documents panel only; it
 * is NOT an access check (that is portalCanReadArticle(), which also knows the
 * requester's company and the access list).
 *
 * @param int $articleId  knowledge_articles.id
 */
function helpCentreArticleIsCustomerFacing(PDO $conn, int $articleId): bool {
    $aud = Audience::visibleTo(Audience::CUSTOMER);
    $marks = implode(',', array_fill(0, count($aud), '?'));
    $st = $conn->prepare("SELECT 1 FROM knowledge_articles
                           WHERE id = ? AND is_published = 1 AND is_archived = 0 AND audience IN ($marks)");
    $st->execute(array_merge([$articleId], $aud));
    return (bool) $st->fetchColumn();
}

/**
 * The documents on an article that the Help Centre shows, newest first.
 *
 * The caller MUST already have established that the requester may read the
 * article (get_knowledge_article.php does, in the same query that fetched it).
 * Returns [] before Database Verification has added the column.
 *
 * @param int $articleId  knowledge_articles.id
 * @return array<int,array<string,mixed>> id, kind, title, description, original_name, size_bytes, external_url
 */
function helpCentreArticleDocuments(PDO $conn, int $articleId): array {
    if (!documentHelpCentreReady($conn)) return [];
    $st = $conn->prepare(
        "SELECT d.id, d.kind, d.title, d.description, d.original_name, d.size_bytes, d.external_url
           FROM document_links dl
           JOIN documents d ON d.id = dl.document_id AND d.deleted_datetime IS NULL AND d.kind <> 'files'   -- GH #303
          WHERE dl.parent_type = 'knowledge_article' AND dl.parent_id = ?
            AND dl.show_in_help_centre = 1
       ORDER BY d.created_datetime DESC, d.id DESC"
    );
    $st->execute([$articleId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) {
        $r['id'] = (int) $r['id'];
        $r['size_bytes'] = $r['size_bytes'] !== null ? (int) $r['size_bytes'] : null;
    }
    unset($r);
    return $rows;
}

/**
 * How many documents each article shows in the Help Centre - for the "N
 * documents" note on the list. Hidden ones are not counted: the list must not
 * promise a file the article will not offer. Same caveat as above: the caller
 * passes only ids the requester may already read.
 *
 * @param int[] $articleIds  knowledge_articles.id values from a scoped list
 * @return array<int,int> article id => count; articles with none are absent
 */
function helpCentreDocumentCounts(PDO $conn, array $articleIds): array {
    $ids = array_values(array_filter(array_map('intval', $articleIds)));
    if (!$ids || !documentHelpCentreReady($conn)) return [];
    $marks = implode(',', array_fill(0, count($ids), '?'));
    $st = $conn->prepare(
        "SELECT dl.parent_id, COUNT(*) AS n
           FROM document_links dl
           JOIN documents d ON d.id = dl.document_id AND d.deleted_datetime IS NULL AND d.kind <> 'files'   -- GH #303
          WHERE dl.parent_type = 'knowledge_article' AND dl.show_in_help_centre = 1
            AND dl.parent_id IN ($marks)
       GROUP BY dl.parent_id"
    );
    $st->execute($ids);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $out[(int) $r['parent_id']] = (int) $r['n'];
    return $out;
}

/**
 * The document a portal requester may download, or null.
 *
 * Served if ANY article it is shown on is one this requester may read - one
 * document can be attached to several articles, so every link is tried, not
 * just the first. Null for every refusal alike (missing, deleted, hidden, an
 * article they cannot read, attached only to some other kind of record), so
 * the endpoint's 404 cannot be used to learn which.
 *
 * @param int $userId  the portal user's users.id (from the session, never the request)
 * @param int $documentId  documents.id from the request
 * @return ?array{id:int,kind:string,title:string,storage_key:?string,original_name:?string,external_url:?string}
 */
function helpCentreDocumentForPortalUser(PDO $conn, int $userId, int $documentId): ?array {
    if ($documentId <= 0 || !documentHelpCentreReady($conn)) return null;
    $st = $conn->prepare(
        "SELECT d.id, d.kind, d.title, d.storage_key, d.original_name, d.external_url, dl.parent_id AS article_id
           FROM documents d
           JOIN document_links dl ON dl.document_id = d.id
                                 AND dl.parent_type = 'knowledge_article'
                                 AND dl.show_in_help_centre = 1
          WHERE d.id = ? AND d.deleted_datetime IS NULL AND d.kind <> 'files'"   // GH #303
    );
    $st->execute([$documentId]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (portalCanReadArticle($conn, $userId, (int) $row['article_id'])) {
            unset($row['article_id']);
            $row['id'] = (int) $row['id'];
            return $row;
        }
    }
    return null;
}

/**
 * Show or hide one article attachment in the Help Centre. Only ever touches a
 * knowledge_article link. Returns false when there is no such link (or the
 * column does not exist yet).
 *
 * @param int $documentId  documents.id
 * @param int $articleId  knowledge_articles.id the document is attached to
 * @param bool $show  true = downloadable in the Help Centre, false = hidden
 */
function helpCentreSetShown(PDO $conn, int $documentId, int $articleId, bool $show): bool {
    if (!documentHelpCentreReady($conn)) return false;
    $exists = $conn->prepare("SELECT 1 FROM document_links
                               WHERE document_id = ? AND parent_type = 'knowledge_article' AND parent_id = ?");
    $exists->execute([$documentId, $articleId]);
    if (!$exists->fetchColumn()) return false;
    $conn->prepare("UPDATE document_links SET show_in_help_centre = ?
                     WHERE document_id = ? AND parent_type = 'knowledge_article' AND parent_id = ?")
         ->execute([$show ? 1 : 0, $documentId, $articleId]);
    return true;
}
