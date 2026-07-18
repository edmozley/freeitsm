<?php
/**
 * API: Help Centre article list / search, for the self-service portal.
 * GET ?q=&limit=
 *
 * The FIRST reader at the Audience::CUSTOMER rung. That rung has been built,
 * tested and documented since #869 with nothing reading it — the editor has had
 * to label it "(not yet in use)".
 *
 * TWO SCOPES, BOTH SERVER-DERIVED, NEITHER ACCEPTED FROM THE REQUEST:
 *
 *   1. AUDIENCE is hard-coded to CUSTOMER. It is never a parameter — the same
 *      rule the web chat reader follows (includes/webchat/ai.php), for the same
 *      reason: whoever is asking does not get to say how trusted they are.
 *      CUSTOMER also includes PUBLIC, since the ladder is cumulative.
 *
 *   2. COMPANY comes from users.tenant_id, looked up here. The portal session
 *      holds only ss_user_id.
 *
 * ⚠️ knowledgeTenantFilterForCompany() — NOT activeTenantFilter(). For knowledge
 * a NULL tenant_id means SHARED WITH EVERY COMPANY, the opposite of tickets and
 * assets. Feeding a non-Default company through the ticket-shaped helper makes
 * every shared article silently vanish. This trap has bitten twice.
 *
 * Archiving does NOT unpublish, so both flags are checked (KB_VISIBLE_SQL) —
 * omitting the archive check is exactly how deleted articles once reached
 * anonymous web chat visitors.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/tenancy.php';
require_once '../../includes/knowledge/audience.php';

header('Content-Type: application/json');

if (!isset($_SESSION['ss_user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$userId = (int)$_SESSION['ss_user_id'];
$search = trim((string)($_GET['q'] ?? ''));
$limit  = (int)($_GET['limit'] ?? 50);
if ($limit < 1 || $limit > 100) $limit = 50;

try {
    $conn = connectToDatabase();

    $uStmt = $conn->prepare("SELECT tenant_id FROM users WHERE id = ?");
    $uStmt->execute([$userId]);
    $row = $uStmt->fetch(PDO::FETCH_ASSOC);
    $userTenantId = ($row && $row['tenant_id'] !== null) ? (int)$row['tenant_id'] : null;

    // Published AND not archived — archiving doesn't unpublish.
    $where  = "a.is_published = 1 AND (a.is_archived = 0 OR a.is_archived IS NULL)";
    $params = [];

    [$tenantSql, $tenantParams] = knowledgeTenantFilterForCompany($conn, $userTenantId, 'a');
    $where  .= $tenantSql;
    $params  = array_merge($params, $tenantParams);

    // Defensive: an install that predates the column has no audience to filter on
    // (every article is shared and internal-by-absence there anyway).
    if (tenancyColumnExists($conn, 'knowledge_articles', 'audience')) {
        [$audSql, $audParams] = Audience::sqlFilter(Audience::CUSTOMER, 'a');
        $where  .= $audSql;
        $params  = array_merge($params, $audParams);
    }

    if ($search !== '') {
        $where .= " AND (a.title LIKE ? OR a.body LIKE ?)";
        $like = '%' . $search . '%';
        $params[] = $like;
        $params[] = $like;
    }

    $sql = "SELECT a.id, a.title, a.modified_datetime, LEFT(a.body, 400) AS preview
            FROM knowledge_articles a
            WHERE $where
            ORDER BY a.title ASC
            LIMIT $limit";

    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $articles = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Tags, for browsing. One grouped query rather than the analyst list's N+1.
    $tagsById = [];
    if ($articles) {
        $ids = array_column($articles, 'id');
        $ph  = implode(',', array_fill(0, count($ids), '?'));
        try {
            $tStmt = $conn->prepare(
                "SELECT at.article_id, t.name
                 FROM knowledge_article_tags at
                 JOIN knowledge_tags t ON t.id = at.tag_id
                 WHERE at.article_id IN ($ph)
                 ORDER BY t.name"
            );
            $tStmt->execute($ids);
            foreach ($tStmt->fetchAll(PDO::FETCH_ASSOC) as $t) {
                $tagsById[(int)$t['article_id']][] = $t['name'];
            }
        } catch (Exception $e) { /* tag tables absent → no tags, not an error */ }
    }

    foreach ($articles as &$a) {
        // The preview is plain text: the body is TinyMCE HTML and a truncated
        // fragment would be unbalanced markup.
        //
        // strip_tags() removes the TAGS but keeps their CONTENTS, so a <script> or
        // <style> block would spill its source into the preview as readable text
        // ("...sign in.alert(1)"). Drop those elements whole first. Not a security
        // control — the preview is escaped on render — just correctness.
        $raw = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', ' ', (string)$a['preview']);
        $a['preview'] = trim(preg_replace('/\s+/', ' ', strip_tags((string)$raw)));
        $a['tags']    = $tagsById[(int)$a['id']] ?? [];
    }
    unset($a);

    echo json_encode(['success' => true, 'articles' => $articles]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
