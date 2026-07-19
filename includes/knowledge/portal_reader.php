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

/**
 * The company a portal user belongs to, or null for "shared articles only".
 *
 * The portal session carries only ss_user_id, so this is a lookup rather than
 * something the caller can pass in — which also means a caller cannot pass in
 * somebody else's company.
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
    $a = $alias === '' ? '' : $alias . '.';

    // Live: published AND not archived (see trap 2).
    $where  = "{$a}is_published = 1 AND ({$a}is_archived = 0 OR {$a}is_archived IS NULL)";
    $params = [];

    // Company (see trap 1).
    [$tenantSql, $tenantParams] = knowledgeTenantFilterForCompany($conn, $tenantId, $alias);
    $where  .= $tenantSql;                       // already starts " AND ..."
    $params  = array_merge($params, $tenantParams);

    // Audience. Skipped on an install that predates the column, where every
    // article is shared and there is no rung to compare against.
    if (tenancyColumnExists($conn, 'knowledge_articles', 'audience')) {
        [$audSql, $audParams] = Audience::sqlFilter(Audience::CUSTOMER, $alias);
        $where  .= $audSql;                      // already starts " AND ..."
        $params  = array_merge($params, $audParams);
    }

    return [$where, $params];
}

/**
 * Turn an article body into a short plain-text preview.
 *
 * strip_tags() removes TAGS but keeps their CONTENTS, so a <script> or <style>
 * block spills its source into the preview as readable text ("…sign in.
 * alert(1)"). Those elements are dropped whole first. Not a security control —
 * the preview is escaped on render — just correctness.
 *
 * strip_tags() also leaves NO separator where a tag was, so "<h3>Password
 * Reset</h3><p>You can…" comes out as "Password ResetYou can…". A space is
 * pushed in front of every tag first, then the run is collapsed — which keeps
 * strip_tags() (and its tolerance of malformed markup) rather than swapping in
 * a hand-rolled tag regex.
 */
function portalArticlePreview(?string $body, int $length = 400): string {
    $raw = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', ' ', (string) $body);
    $raw = str_replace('<', ' <', (string) $raw);
    $text = trim(preg_replace('/\s+/', ' ', strip_tags($raw)));

    // The caller already LEFT()s the body, so this is a tidy-up of the tail
    // rather than the real truncation.
    if (function_exists('mb_substr') && mb_strlen($text) > $length) {
        $text = rtrim(mb_substr($text, 0, $length)) . '…';
    }
    return $text;
}
