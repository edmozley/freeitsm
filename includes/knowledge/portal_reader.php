<?php
/**
 * What a self-service requester may read of the knowledge base — in ONE place.
 *
 * WHY THIS EXISTS
 * ---------------
 * Three portal surfaces now read articles: the Help Centre list, the single
 * article, and the dashboard's popular list — with ticket deflection reusing the
 * list endpoint. Each needs the same three-part scope:
 *
 *     published AND not archived          — the article is live
 *     AND audience >= customer            — it is meant for customers
 *     AND (their company OR shared)       — it belongs to them
 *
 * That was already copy-pasted across two endpoints before this file existed.
 * Duplicated scope is the dangerous kind of duplication: nothing breaks when the
 * copies drift, one of them just quietly starts showing more than it should.
 * Adding a third copy for the dashboard is what prompted pulling it out.
 *
 * TWO TRAPS THIS ENCODES SO CALLERS CANNOT GET THEM WRONG
 * -------------------------------------------------------
 * 1. ⚠️ knowledgeTenantFilterForCompany(), never activeTenantFilter(). For
 *    knowledge a NULL tenant_id means SHARED WITH EVERY COMPANY — the opposite
 *    of tickets and assets, where NULL means the Default company's. Feeding a
 *    non-Default company through the ticket-shaped helper makes every shared
 *    article silently vanish. This has bitten twice.
 * 2. ⚠️ Archiving does NOT unpublish. Both flags must be checked; omitting the
 *    archive half is exactly how deleted articles once reached anonymous web
 *    chat visitors.
 *
 * The audience is HARD-CODED to CUSTOMER here and is not a parameter. Whoever is
 * asking does not get to declare how trusted they are — the same rule the web
 * chat reader follows (includes/webchat/ai.php).
 */

require_once __DIR__ . '/audience.php';
require_once __DIR__ . '/../tenancy.php';
require_once __DIR__ . '/visibility.php';
require_once __DIR__ . '/article_preview.php';

/**
 * The company a portal user belongs to, or null for "shared articles only".
 *
 * The portal session carries only ss_user_id, so this is a lookup rather than
 * something the caller can pass in — which also means a caller cannot pass in
 * somebody else's company.
 *
 * @param int $userId  the portal user's users.id (from the session's ss_user_id)
 */
function portalUserTenantId(PDO $conn, int $userId): ?int {
    $stmt = $conn->prepare("SELECT tenant_id FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return ($row && $row['tenant_id'] !== null) ? (int) $row['tenant_id'] : null;
}

/**
 * The WHERE fragment and bound params for "articles this requester may read".
 *
 * Returns SQL beginning with a condition (no leading AND), so a caller writes:
 *
 *     [$where, $params] = portalKnowledgeScope($conn, $tenantId, 'a');
 *     $sql = "SELECT ... FROM knowledge_articles a WHERE $where ORDER BY ...";
 *
 * Callers add their own extra conditions AFTER, appending their params in the
 * same order.
 *
 * @param ?int   $tenantId the requester's company (null = shared only)
 * @param string $alias    the table alias used in the caller's query
 * @return array{0:string,1:array}
 */
function portalKnowledgeScope(PDO $conn, ?int $tenantId, string $alias = 'a'): array {
    // Delegates to the module-wide resolver so the portal cannot drift from
    // every other reader — including when the access list lands, which this
    // function then inherits without being touched. The three traps documented
    // above still hold; they are simply enforced in one place now rather than
    // re-stated per caller.
    //
    // The CUSTOMER rung stays hard-coded (via forPortalUser), for the reason in
    // the header: whoever is asking does not get to declare how trusted they are.
    $viewer = KnowledgeViewer::forPortalUser($conn, null, $tenantId);
    [$sql, $params] = knowledgeVisibilitySql($conn, $viewer, $alias, ['lifecycle' => 'live']);

    // This function's contract is a WHERE fragment with NO leading AND — three
    // callers interpolate it straight after "WHERE". Keep that.
    $where = preg_replace('/^\s*AND\s+/', '', $sql);

    return [$where, $params];
}

/**
 * May this portal requester read this article in the Help Centre?
 *
 * The single-article form of portalKnowledgeScope(), for a caller that already
 * has an id - the Help Centre's document download (GH #177). Built on the scope
 * rather than beside it, so it cannot disagree with the article page that
 * listed the document.
 *
 * @param int $userId  the portal user's users.id, from the session's ss_user_id
 * @param int $articleId  knowledge_articles.id
 */
function portalCanReadArticle(PDO $conn, int $userId, int $articleId): bool {
    if ($articleId <= 0) return false;
    [$where, $params] = portalKnowledgeScope($conn, portalUserTenantId($conn, $userId), 'a');
    $st = $conn->prepare("SELECT 1 FROM knowledge_articles a WHERE $where AND a.id = ? LIMIT 1");
    $st->execute(array_merge($params, [$articleId]));
    return (bool) $st->fetchColumn();
}

/**
 * The portal's name for knowledgeArticlePreview() - kept so its callers and
 * tests read as before. The function itself lives in article_preview.php,
 * shared with the analyst list, the record preview and the REST API.
 */
function portalArticlePreview(?string $body, int $length = 400, bool $wasCut = false): string {
    return knowledgeArticlePreview($body, $length, $wasCut);
}
