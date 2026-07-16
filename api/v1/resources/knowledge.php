<?php
/**
 * FreeITSM REST API v1 — knowledge resource (Knowledge Base).
 *
 * Mirrors the module's internal endpoints so an article touched via the API
 * is indistinguishable from one touched in the UI:
 *   - create/update mirror api/knowledge/knowledge_save.php: articles are
 *     created published (there is no draft workflow in the product), tags are
 *     get-or-created by name and re-linked as a set, "save as version" first
 *     snapshots the current row into knowledge_article_versions and bumps the
 *     version number, and — when the module's OpenAI key is configured — the
 *     search EMBEDDING is regenerated after the save (best-effort, silent on
 *     failure), so AI chat/vector search keeps working for API-written
 *     articles.
 *   - delete/restore/purge mirror the recycle-bin semantics
 *     (knowledge_delete.php / knowledge_archive.php): DELETE archives,
 *     restore un-archives, permanent delete only works on archived articles
 *     and cleans up orphaned tags; listing the bin runs the same retention
 *     auto-purge the UI runs when the bin is opened.
 *
 * Deliberate divergence from the UI, documented: GET /knowledge/articles/{id}
 * does NOT bump view_count unless ?count_view=true is passed — machine reads
 * (sync jobs, chatbots re-fetching) would otherwise inflate the stats the
 * review screens rely on.
 *
 * Articles carry a `company` (knowledge_articles.tenant_id; null = shared with
 * every company) and an `audience` (internal | customer | public). Lists are
 * scoped to the key's companies via apiKeyKnowledgeFilter() — NOT the generic
 * apiKeyTenantFilter(), because a NULL tenant_id here means "shared with all"
 * rather than "the Default company's"; see that function's docblock. Bodies are
 * TinyMCE HTML stored verbatim, exactly like the UI (no server-side
 * sanitisation exists in the product) — consumers render at their own risk,
 * and integrations should send trusted HTML only.
 *
 * Article WRITES (create/update/archive/restore/purge) are delegated to
 * KnowledgeService (includes/services/knowledge.php); the read handlers,
 * serializers + the bin's retention auto-purge stay here.
 */

require_once dirname(__DIR__, 3) . '/includes/service_context.php';
require_once dirname(__DIR__, 3) . '/includes/services/knowledge.php';
require_once dirname(__DIR__, 3) . '/includes/knowledge/audience.php';   // Audience:: used directly below

// ---------------------------------------------------------------------------
// Shared helpers
// ---------------------------------------------------------------------------

function apiKnowledgeSelect(): string {
    return "SELECT a.*,
                   au.full_name AS author_name,
                   ow.full_name AS owner_name,
                   ar.full_name AS archived_by_name,
                   tn.name AS tenant_name
            FROM knowledge_articles a
            LEFT JOIN analysts au ON au.id = a.author_id
            LEFT JOIN analysts ow ON ow.id = a.owner_id
            LEFT JOIN analysts ar ON ar.id = a.archived_by_id
            LEFT JOIN tenants tn ON tn.id = a.tenant_id";
}

function apiKnowledgeTags(PDO $conn, int $articleId): array {
    $stmt = $conn->prepare(
        "SELECT t.name FROM knowledge_article_tags at
         JOIN knowledge_tags t ON t.id = at.tag_id
         WHERE at.article_id = ? ORDER BY t.name"
    );
    $stmt->execute([$articleId]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

/** Summary shape (lists) — preview instead of the full body. Detail adds body_html. */
function apiSerializeArticle(PDO $conn, array $r, bool $detail = false): array {
    $rel = function ($id, $name) {
        return $id === null ? null : ['id' => (int)$id, 'name' => $name];
    };
    $out = [
        'id'               => (int)$r['id'],
        'title'            => $r['title'],
        'tags'             => apiKnowledgeTags($conn, (int)$r['id']),
        'author'           => $rel($r['author_id'], $r['author_name']),
        'owner'            => $rel($r['owner_id'], $r['owner_name']),
        'version'          => (int)$r['version'],
        'view_count'       => (int)$r['view_count'],
        'next_review_date' => $r['next_review_date'],
        // Which company owns it (null = shared with all) and who may read it.
        'company'          => $rel($r['tenant_id'] ?? null, $r['tenant_name'] ?? null),
        'audience'         => $r['audience'] ?? Audience::INTERNAL,
        'is_archived'      => (bool)$r['is_archived'],
        'created_at'       => apiIsoDate($r['created_datetime']),
        'modified_at'      => apiIsoDate($r['modified_datetime']),
    ];
    if ($detail) {
        $out['body_html'] = $r['body'];
        $out['has_embedding'] = $r['embedding'] !== null && $r['embedding'] !== '';
    } else {
        $preview = trim(preg_replace('/\s+/', ' ', strip_tags((string)$r['body'])));
        $out['preview'] = mb_substr($preview, 0, 300);
    }
    if ((bool)$r['is_archived']) {
        $out['archived_at'] = apiIsoDate($r['archived_datetime']);
        $out['archived_by'] = $rel($r['archived_by_id'], $r['archived_by_name']);
    }
    return $out;
}

/**
 * Load an article by id, 404ing if it's gone.
 *
 * Pass $apiKey on a READ path and it also refuses an article belonging to a
 * company the key can't reach — reported as 404, not 403, so an id can't be used
 * to probe which articles exist elsewhere. The post-write callers omit it: the
 * service layer has already run the same check by then.
 */
function apiLoadArticle(PDO $conn, int $articleId, ?array $apiKey = null): array {
    $stmt = $conn->prepare(apiKnowledgeSelect() . " WHERE a.id = ?");
    $stmt->execute([$articleId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        apiError(404, 'not_found', 'Article not found.');
    }
    if ($apiKey !== null && !apiKeyCanAccessArticle($conn, $apiKey, $articleId)) {
        apiError(404, 'not_found', 'Article not found.');
    }
    return $row;
}

/** The recycle bin's retention auto-purge (mirrors knowledge_archive.php). */
function apiKnowledgePurgeExpired(PDO $conn): void {
    try {
        $stmt = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'knowledge_recycle_bin_days'");
        $stmt->execute();
        $val = $stmt->fetchColumn();
        $days = ($val !== false && is_numeric($val)) ? (int)$val : 30;
        if ($days <= 0) {
            return; // 0 = keep forever
        }
        // Children first (versions FK has no cascade; grown installs may lack FKs entirely).
        $expired = "SELECT id FROM knowledge_articles WHERE is_archived = 1 AND archived_datetime < DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? DAY)";
        $ids = $conn->prepare($expired);
        $ids->execute([$days]);
        $idList = $ids->fetchAll(PDO::FETCH_COLUMN);
        if ($idList) {
            $placeholders = implode(',', array_fill(0, count($idList), '?'));
            $conn->prepare("DELETE FROM knowledge_article_versions WHERE article_id IN ($placeholders)")->execute($idList);
            $conn->prepare("DELETE FROM knowledge_article_tags WHERE article_id IN ($placeholders)")->execute($idList);
            $conn->prepare("DELETE FROM knowledge_articles WHERE id IN ($placeholders)")->execute($idList);
            $conn->exec("DELETE FROM knowledge_tags WHERE id NOT IN (SELECT DISTINCT tag_id FROM knowledge_article_tags)");
        }
    } catch (Exception $e) { /* best-effort */ }
}

// ---------------------------------------------------------------------------
// GET /knowledge/articles
// ---------------------------------------------------------------------------
function apiKnowledgeArticlesList(PDO $conn, array $apiKey, array $params, array $body): void {
    $archived = (($_GET['archived'] ?? '') === 'true');
    if ($archived) {
        // Opening the bin runs the retention purge, exactly like the UI.
        apiKnowledgePurgeExpired($conn);
        $where = ['a.is_archived = 1'];
    } else {
        $where = ['a.is_published = 1', '(a.is_archived = 0 OR a.is_archived IS NULL)'];
    }
    $args = [];

    if (isset($_GET['q']) && trim($_GET['q']) !== '') {
        $where[] = '(a.title LIKE ? OR a.body LIKE ?)';
        $like = '%' . trim($_GET['q']) . '%';
        array_push($args, $like, $like);
    }
    if (isset($_GET['tag']) && trim($_GET['tag']) !== '') {
        $where[] = 'a.id IN (SELECT at.article_id FROM knowledge_article_tags at
                             JOIN knowledge_tags t ON t.id = at.tag_id WHERE t.name = ?)';
        $args[] = trim($_GET['tag']);
    }
    foreach (['author_id' => 'a.author_id', 'owner_id' => 'a.owner_id'] as $param => $col) {
        if (isset($_GET[$param]) && $_GET[$param] !== '') {
            $where[] = "$col = ?";
            $args[]  = (int)$_GET[$param];
        }
    }
    // Review-cycle filters — the same windows as the module's review screen.
    $review = trim($_GET['review'] ?? '');
    if ($review === 'overdue') {
        $where[] = 'a.next_review_date < DATE(UTC_TIMESTAMP())';
    } elseif ($review === 'upcoming') {
        $where[] = 'a.next_review_date >= DATE(UTC_TIMESTAMP()) AND a.next_review_date <= DATE_ADD(UTC_TIMESTAMP(), INTERVAL 30 DAY)';
    } elseif ($review === 'none') {
        $where[] = 'a.next_review_date IS NULL';
    } elseif ($review !== '') {
        apiError(400, 'invalid_parameter', "'review' must be overdue, upcoming or none.");
    }
    if (isset($_GET['modified_since']) && $_GET['modified_since'] !== '') {
        $where[] = 'a.modified_datetime >= ?';
        $args[]  = apiParseDate($_GET['modified_since'], 'modified_since');
    }
    // Filter by owning company. '' / 'shared' selects the shared ones.
    if (isset($_GET['company']) && $_GET['company'] !== '') {
        if ($_GET['company'] === 'shared') {
            $where[] = 'a.tenant_id IS NULL';
        } else {
            $companyId = (int)$_GET['company'];
            if (!apiKeyCanAccessTenant($conn, $apiKey, $companyId)) {
                apiError(403, 'forbidden', 'Your API key does not have access to that company.');
            }
            $where[] = 'a.tenant_id = ?';
            $args[]  = $companyId;
        }
    }
    if (isset($_GET['audience']) && $_GET['audience'] !== '') {
        if (!Audience::isValid($_GET['audience'])) {
            apiError(400, 'invalid_parameter', "'audience' must be one of: " . implode(', ', Audience::all()) . '.');
        }
        $where[] = 'a.audience = ?';
        $args[]  = $_GET['audience'];
    }

    // The key's company scope. NOTE: apiKeyKnowledgeFilter, NOT apiKeyTenantFilter —
    // shared (NULL) articles belong to every company here, not to Default. See the
    // docblock on that function.
    [$tenantSql, $tenantParams] = apiKeyKnowledgeFilter($conn, $apiKey, 'a');
    if ($tenantSql !== '') {
        $where[] = ltrim(substr($tenantSql, 5));   // strip the leading ' AND '
        $args    = array_merge($args, $tenantParams);
    }

    $sortable = [
        'modified_at' => 'a.modified_datetime', 'created_at' => 'a.created_datetime',
        'title' => 'a.title', 'view_count' => 'a.view_count', 'id' => 'a.id',
        'next_review_date' => 'a.next_review_date',
    ];
    $sortParam = trim($_GET['sort'] ?? '-modified_at');
    $desc = strncmp($sortParam, '-', 1) === 0;
    $sortKey = ltrim($sortParam, '-');
    if (!isset($sortable[$sortKey])) {
        apiError(400, 'invalid_parameter', "Unknown sort field '{$sortKey}'. Sortable: " . implode(', ', array_keys($sortable)));
    }
    $orderSql = $sortable[$sortKey] . ($desc ? ' DESC' : ' ASC');

    [$page, $perPage, $offset] = apiPagination();
    $whereSql = implode(' AND ', $where);

    $countStmt = $conn->prepare("SELECT COUNT(*) FROM knowledge_articles a WHERE $whereSql");
    $countStmt->execute($args);
    $total = (int)$countStmt->fetchColumn();

    $stmt = $conn->prepare(apiKnowledgeSelect() . " WHERE $whereSql ORDER BY $orderSql LIMIT $perPage OFFSET $offset");
    $stmt->execute($args);
    $articles = array_map(function ($r) use ($conn) {
        return apiSerializeArticle($conn, $r);
    }, $stmt->fetchAll(PDO::FETCH_ASSOC));

    apiRespond($articles, 200, [
        'page'        => $page,
        'per_page'    => $perPage,
        'total'       => $total,
        'total_pages' => (int)ceil($total / $perPage),
    ]);
}

// ---------------------------------------------------------------------------
// GET /knowledge/articles/{id}
// ---------------------------------------------------------------------------
function apiKnowledgeArticlesGet(PDO $conn, array $apiKey, array $params, array $body): void {
    $row = apiLoadArticle($conn, $params[0], $apiKey);

    // Machine reads don't inflate view stats unless asked to (deliberate
    // divergence from the UI, which counts every open).
    if (($_GET['count_view'] ?? '') === 'true' && !(int)$row['is_archived']) {
        $conn->prepare("UPDATE knowledge_articles SET view_count = view_count + 1 WHERE id = ?")->execute([$params[0]]);
        $row['view_count'] = (int)$row['view_count'] + 1;
    }

    apiRespond(apiSerializeArticle($conn, $row, true));
}

// ---------------------------------------------------------------------------
// POST /knowledge/articles
// ---------------------------------------------------------------------------
function apiKnowledgeArticlesCreate(PDO $conn, array $apiKey, array $params, array $body): void {
    try {
        $res = KnowledgeService::saveArticle($conn, ActorContext::fromApiKey($apiKey), $body);
        apiRespond(apiSerializeArticle($conn, apiLoadArticle($conn, $res['id']), true), 201);
    } catch (ServiceError $e) { apiFailFromService($e); }
}

// ---------------------------------------------------------------------------
// PATCH /knowledge/articles/{id}
// ---------------------------------------------------------------------------
function apiKnowledgeArticlesUpdate(PDO $conn, array $apiKey, array $params, array $body): void {
    try {
        $res = KnowledgeService::saveArticle($conn, ActorContext::fromApiKey($apiKey), array_merge($body, ['id' => (int)$params[0]]));
        apiRespond(apiSerializeArticle($conn, apiLoadArticle($conn, $res['id']), true));
    } catch (ServiceError $e) { apiFailFromService($e); }
}

// ---------------------------------------------------------------------------
// DELETE (archive) / restore / permanent purge — the recycle-bin semantics
// ---------------------------------------------------------------------------
function apiKnowledgeArticlesDelete(PDO $conn, array $apiKey, array $params, array $body): void {
    try {
        KnowledgeService::archiveArticle($conn, ActorContext::fromApiKey($apiKey), (int)$params[0]);
        apiRespond(['id' => $params[0], 'archived' => true]);
    } catch (ServiceError $e) { apiFailFromService($e); }
}

function apiKnowledgeArticlesRestore(PDO $conn, array $apiKey, array $params, array $body): void {
    try {
        KnowledgeService::restoreArticle($conn, ActorContext::fromApiKey($apiKey), (int)$params[0]);
        apiRespond(apiSerializeArticle($conn, apiLoadArticle($conn, $params[0]), true));
    } catch (ServiceError $e) { apiFailFromService($e); }
}

function apiKnowledgeArticlesPurge(PDO $conn, array $apiKey, array $params, array $body): void {
    try {
        KnowledgeService::purgeArticle($conn, ActorContext::fromApiKey($apiKey), (int)$params[0]);
        apiRespond(['id' => $params[0], 'deleted' => true]);
    } catch (ServiceError $e) { apiFailFromService($e); }
}

// ---------------------------------------------------------------------------
// Versions
// ---------------------------------------------------------------------------
function apiKnowledgeVersionsList(PDO $conn, array $apiKey, array $params, array $body): void {
    apiLoadArticle($conn, $params[0], $apiKey);
    $stmt = $conn->prepare(
        "SELECT v.version, v.title, v.saved_datetime, v.saved_by_id, a.full_name AS saved_by_name
         FROM knowledge_article_versions v LEFT JOIN analysts a ON a.id = v.saved_by_id
         WHERE v.article_id = ? ORDER BY v.version DESC"
    );
    $stmt->execute([$params[0]]);
    apiRespond(array_map(function ($v) {
        return [
            'version'  => (int)$v['version'],
            'title'    => $v['title'],
            'saved_by' => $v['saved_by_id'] === null ? null : ['id' => (int)$v['saved_by_id'], 'name' => $v['saved_by_name']],
            'saved_at' => apiIsoDate($v['saved_datetime']),
        ];
    }, $stmt->fetchAll(PDO::FETCH_ASSOC)));
}

function apiKnowledgeVersionsGet(PDO $conn, array $apiKey, array $params, array $body): void {
    apiLoadArticle($conn, $params[0], $apiKey);
    $stmt = $conn->prepare(
        "SELECT v.version, v.title, v.body, v.saved_datetime, v.saved_by_id, a.full_name AS saved_by_name
         FROM knowledge_article_versions v LEFT JOIN analysts a ON a.id = v.saved_by_id
         WHERE v.article_id = ? AND v.version = ?"
    );
    $stmt->execute([$params[0], $params[1]]);
    $v = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$v) {
        apiError(404, 'not_found', 'Version not found.');
    }
    apiRespond([
        'version'   => (int)$v['version'],
        'title'     => $v['title'],
        'body_html' => $v['body'],
        'saved_by'  => $v['saved_by_id'] === null ? null : ['id' => (int)$v['saved_by_id'], 'name' => $v['saved_by_name']],
        'saved_at'  => apiIsoDate($v['saved_datetime']),
    ]);
}

// ---------------------------------------------------------------------------
// GET /knowledge/tags — tag list with published article counts
// ---------------------------------------------------------------------------
function apiKnowledgeTagsList(PDO $conn, array $apiKey, array $params, array $body): void {
    $rows = $conn->query(
        "SELECT t.id, t.name,
                COUNT(DISTINCT CASE WHEN a.is_published = 1 AND (a.is_archived = 0 OR a.is_archived IS NULL)
                                    THEN a.id END) AS article_count
         FROM knowledge_tags t
         LEFT JOIN knowledge_article_tags at ON at.tag_id = t.id
         LEFT JOIN knowledge_articles a ON a.id = at.article_id
         GROUP BY t.id, t.name
         ORDER BY t.name"
    )->fetchAll(PDO::FETCH_ASSOC);
    apiRespond(array_map(function ($t) {
        return [
            'id'            => (int)$t['id'],
            'name'          => $t['name'],
            'article_count' => (int)$t['article_count'],
        ];
    }, $rows));
}
