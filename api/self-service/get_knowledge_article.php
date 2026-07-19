<?php
/**
 * API: one Help Centre article, for the self-service portal.
 * GET ?id=
 *
 * Same two server-derived scopes as the list (see get_knowledge_articles.php):
 * audience hard-coded to CUSTOMER, company from users.tenant_id.
 *
 * The scope is applied IN THE QUERY rather than fetched-then-checked, so an
 * article the requester may not see is indistinguishable from one that does not
 * exist — the same "no existence oracle" behaviour the analyst endpoint uses,
 * and the same reason the portal's ticket endpoints all return a flat
 * "not found".
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/knowledge/portal_reader.php';

header('Content-Type: application/json');

if (!isset($_SESSION['ss_user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$userId    = (int)$_SESSION['ss_user_id'];
$articleId = (int)($_GET['id'] ?? 0);

if (!$articleId) {
    echo json_encode(['success' => false, 'error' => 'Article not found']);
    exit;
}

try {
    $conn = connectToDatabase();

    // The same one scope the list uses — see includes/knowledge/portal_reader.php.
    $userTenantId = portalUserTenantId($conn, $userId);
    [$where, $params] = portalKnowledgeScope($conn, $userTenantId, 'a');

    $where   .= " AND a.id = ?";
    $params[] = $articleId;

    $stmt = $conn->prepare(
        "SELECT a.id, a.title, a.body, a.modified_datetime
         FROM knowledge_articles a
         WHERE $where
         LIMIT 1"
    );
    $stmt->execute($params);
    $article = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$article) {
        echo json_encode(['success' => false, 'error' => 'Article not found']);
        exit;
    }

    try {
        $tStmt = $conn->prepare(
            "SELECT t.name FROM knowledge_article_tags at
             JOIN knowledge_tags t ON t.id = at.tag_id
             WHERE at.article_id = ? ORDER BY t.name"
        );
        $tStmt->execute([$articleId]);
        $article['tags'] = $tStmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (Exception $e) {
        $article['tags'] = [];
    }

    // Reading it in the portal is a view like any other. Best-effort: a counter
    // must never cost the reader their article.
    try {
        $conn->prepare("UPDATE knowledge_articles SET view_count = view_count + 1 WHERE id = ?")
             ->execute([$articleId]);
    } catch (Exception $e) { /* ignore */ }

    // NOTE: `body` is TinyMCE HTML stored verbatim — the product has no
    // server-side sanitiser. The portal renders it through safeHtmlFragment()
    // (assets/js/safe-html.js), the same cleaner the inbox uses, because this is
    // analyst-authored markup being moved into an unprivileged session.
    echo json_encode(['success' => true, 'article' => $article]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
