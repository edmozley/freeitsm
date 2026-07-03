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
 * Knowledge is install-wide (no tenant_id — matches the UI). Bodies are
 * TinyMCE HTML stored verbatim, exactly like the UI (no server-side
 * sanitisation exists in the product) — consumers render at their own risk,
 * and integrations should send trusted HTML only.
 */

// ---------------------------------------------------------------------------
// Shared helpers
// ---------------------------------------------------------------------------

function apiKnowledgeSelect(): string {
    return "SELECT a.*,
                   au.full_name AS author_name,
                   ow.full_name AS owner_name,
                   ar.full_name AS archived_by_name
            FROM knowledge_articles a
            LEFT JOIN analysts au ON au.id = a.author_id
            LEFT JOIN analysts ow ON ow.id = a.owner_id
            LEFT JOIN analysts ar ON ar.id = a.archived_by_id";
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

function apiLoadArticle(PDO $conn, int $articleId): array {
    $stmt = $conn->prepare(apiKnowledgeSelect() . " WHERE a.id = ?");
    $stmt->execute([$articleId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        apiError(404, 'not_found', 'Article not found.');
    }
    return $row;
}

/** Replace the article's tag set: get-or-create each name, relink (mirrors knowledge_save.php). */
function apiKnowledgeSetTags(PDO $conn, int $articleId, array $tags): void {
    $conn->prepare("DELETE FROM knowledge_article_tags WHERE article_id = ?")->execute([$articleId]);
    foreach ($tags as $raw) {
        $name = trim((string)$raw);
        if ($name === '' || mb_strlen($name) > 50) {
            continue;
        }
        $find = $conn->prepare("SELECT id FROM knowledge_tags WHERE name = ?");
        $find->execute([$name]);
        $tagId = $find->fetchColumn();
        if ($tagId === false) {
            $conn->prepare("INSERT INTO knowledge_tags (name, created_datetime) VALUES (?, UTC_TIMESTAMP())")->execute([$name]);
            $tagId = $conn->lastInsertId();
        }
        $conn->prepare("INSERT IGNORE INTO knowledge_article_tags (article_id, tag_id) VALUES (?, ?)")
             ->execute([$articleId, (int)$tagId]);
    }
}

/**
 * Regenerate the article's OpenAI embedding — the same best-effort post-save
 * step knowledge_save.php runs, so API-written articles stay searchable by
 * the AI chat. Silently no-ops without a key or on any failure.
 */
function apiKnowledgeUpdateEmbedding(PDO $conn, int $articleId, string $title, string $body): void {
    try {
        $stmt = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'knowledge_openai_api_key'");
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row || empty($row['setting_value']) || !function_exists('decryptValue')) {
            return;
        }
        $apiKeyValue = decryptValue($row['setting_value']);
        if (!$apiKeyValue) {
            return;
        }

        $plainText = trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($body), ENT_QUOTES, 'UTF-8')));
        $textToEmbed = $title . "\n\n" . $plainText;
        if (strlen($textToEmbed) > 30000) {
            $textToEmbed = substr($textToEmbed, 0, 30000);
        }

        $ch = curl_init('https://api.openai.com/v1/embeddings');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['model' => 'text-embedding-3-small', 'input' => $textToEmbed]));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'Authorization: Bearer ' . $apiKeyValue]);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, defined('SSL_VERIFY_PEER') ? SSL_VERIFY_PEER : true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200) {
            $data = json_decode($response, true);
            $embedding = $data['data'][0]['embedding'] ?? null;
            if ($embedding && is_array($embedding)) {
                $conn->prepare("UPDATE knowledge_articles SET embedding = ?, embedding_updated = UTC_TIMESTAMP() WHERE id = ?")
                     ->execute([json_encode($embedding), $articleId]);
            }
        }
    } catch (Exception $e) {
        // Embedding is a bonus, never a blocker — same as the UI.
    }
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
    $row = apiLoadArticle($conn, $params[0]);

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
    $title = trim((string)($body['title'] ?? ''));
    if ($title === '') {
        apiError(422, 'missing_field', "'title' is required.");
    }
    if (mb_strlen($title) > 255) {
        apiError(422, 'invalid_field', "'title' must be at most 255 characters.");
    }
    $bodyHtml = (string)($body['body_html'] ?? '');

    $ownerId = null;
    if (isset($body['owner_id']) && $body['owner_id'] !== '' && $body['owner_id'] !== null) {
        $ownerId = (int)$body['owner_id'];
        apiResolveAnalyst($conn, $ownerId);
    }
    $nextReview = apiParseDateOnly($body['next_review_date'] ?? null, 'next_review_date');

    $conn->beginTransaction();
    try {
        // Articles are created published — the product has no draft workflow.
        $ins = $conn->prepare(
            "INSERT INTO knowledge_articles
                (title, body, author_id, owner_id, next_review_date,
                 created_datetime, modified_datetime, is_published, view_count)
             VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP(), 1, 0)"
        );
        $ins->execute([$title, $bodyHtml, (int)$apiKey['analyst_id'], $ownerId, $nextReview]);
        $articleId = (int)$conn->lastInsertId();

        if (isset($body['tags']) && is_array($body['tags'])) {
            apiKnowledgeSetTags($conn, $articleId, $body['tags']);
        }
        $conn->commit();
    } catch (Exception $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        throw $e;
    }

    apiKnowledgeUpdateEmbedding($conn, $articleId, $title, $bodyHtml);

    apiRespond(apiSerializeArticle($conn, apiLoadArticle($conn, $articleId), true), 201);
}

// ---------------------------------------------------------------------------
// PATCH /knowledge/articles/{id}
// ---------------------------------------------------------------------------
function apiKnowledgeArticlesUpdate(PDO $conn, array $apiKey, array $params, array $body): void {
    $articleId = $params[0];
    $current = apiLoadArticle($conn, $articleId);
    if ((int)$current['is_archived']) {
        apiError(409, 'conflict', 'Article is in the recycle bin. Restore it before updating.');
    }
    if (!$body) {
        apiError(422, 'missing_field', 'No fields to update.');
    }

    $newTitle = $current['title'];
    if (array_key_exists('title', $body)) {
        $newTitle = trim((string)$body['title']);
        if ($newTitle === '') {
            apiError(422, 'invalid_field', "'title' cannot be empty.");
        }
        if (mb_strlen($newTitle) > 255) {
            apiError(422, 'invalid_field', "'title' must be at most 255 characters.");
        }
    }
    $newBody = array_key_exists('body_html', $body) ? (string)$body['body_html'] : (string)$current['body'];

    $updates = ['title = ?', 'body = ?', 'modified_datetime = UTC_TIMESTAMP()'];
    $args    = [$newTitle, $newBody];

    if (array_key_exists('owner_id', $body)) {
        $ownerId = ($body['owner_id'] === '' || $body['owner_id'] === null) ? null : (int)$body['owner_id'];
        if ($ownerId !== null) {
            apiResolveAnalyst($conn, $ownerId);
        }
        $updates[] = 'owner_id = ?';
        $args[]    = $ownerId;
    }
    if (array_key_exists('next_review_date', $body)) {
        $updates[] = 'next_review_date = ?';
        $args[]    = apiParseDateOnly($body['next_review_date'], 'next_review_date');
    }

    $saveAsVersion = !empty($body['save_as_version']);

    $conn->beginTransaction();
    try {
        if ($saveAsVersion) {
            // Snapshot the CURRENT content into history, then bump the version
            // — exactly knowledge_save.php's save-as-version path.
            $conn->prepare(
                "INSERT INTO knowledge_article_versions (article_id, version, title, body, saved_by_id, saved_datetime)
                 SELECT id, version, title, body, ?, UTC_TIMESTAMP() FROM knowledge_articles WHERE id = ?"
            )->execute([(int)$apiKey['analyst_id'], $articleId]);
            $updates[] = 'version = version + 1';
        }
        $args[] = $articleId;
        $conn->prepare('UPDATE knowledge_articles SET ' . implode(', ', $updates) . ' WHERE id = ?')->execute($args);

        if (isset($body['tags']) && is_array($body['tags'])) {
            apiKnowledgeSetTags($conn, $articleId, $body['tags']);
        }
        $conn->commit();
    } catch (Exception $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        throw $e;
    }

    apiKnowledgeUpdateEmbedding($conn, $articleId, $newTitle, $newBody);

    apiRespond(apiSerializeArticle($conn, apiLoadArticle($conn, $articleId), true));
}

// ---------------------------------------------------------------------------
// DELETE (archive) / restore / permanent purge — the recycle-bin semantics
// ---------------------------------------------------------------------------
function apiKnowledgeArticlesDelete(PDO $conn, array $apiKey, array $params, array $body): void {
    apiLoadArticle($conn, $params[0]);
    $stmt = $conn->prepare(
        "UPDATE knowledge_articles
         SET is_archived = 1, archived_datetime = UTC_TIMESTAMP(), archived_by_id = ?
         WHERE id = ? AND (is_archived = 0 OR is_archived IS NULL)"
    );
    $stmt->execute([(int)$apiKey['analyst_id'], $params[0]]);
    if ($stmt->rowCount() === 0) {
        apiError(409, 'conflict', 'Article is already in the recycle bin.');
    }
    apiRespond(['id' => $params[0], 'archived' => true]);
}

function apiKnowledgeArticlesRestore(PDO $conn, array $apiKey, array $params, array $body): void {
    apiLoadArticle($conn, $params[0]);
    $stmt = $conn->prepare(
        "UPDATE knowledge_articles
         SET is_archived = 0, archived_datetime = NULL, archived_by_id = NULL
         WHERE id = ? AND is_archived = 1"
    );
    $stmt->execute([$params[0]]);
    if ($stmt->rowCount() === 0) {
        apiError(409, 'conflict', 'Article is not in the recycle bin.');
    }
    apiRespond(apiSerializeArticle($conn, apiLoadArticle($conn, $params[0]), true));
}

function apiKnowledgeArticlesPurge(PDO $conn, array $apiKey, array $params, array $body): void {
    $row = apiLoadArticle($conn, $params[0]);
    // Permanent delete only works from the bin — same guard as the UI.
    if (!(int)$row['is_archived']) {
        apiError(409, 'conflict', 'Only archived articles can be permanently deleted. DELETE (archive) it first.');
    }
    // Children explicitly, not via cascade: the tag-junction FK cascades but
    // the versions FK does not, and installs grown via Database Verify may
    // have neither (FKs are added separately from columns).
    $conn->prepare("DELETE FROM knowledge_article_versions WHERE article_id = ?")->execute([$params[0]]);
    $conn->prepare("DELETE FROM knowledge_article_tags WHERE article_id = ?")->execute([$params[0]]);
    $conn->prepare("DELETE FROM knowledge_articles WHERE id = ? AND is_archived = 1")->execute([$params[0]]);
    $conn->exec("DELETE FROM knowledge_tags WHERE id NOT IN (SELECT DISTINCT tag_id FROM knowledge_article_tags)");
    apiRespond(['id' => $params[0], 'deleted' => true]);
}

// ---------------------------------------------------------------------------
// Versions
// ---------------------------------------------------------------------------
function apiKnowledgeVersionsList(PDO $conn, array $apiKey, array $params, array $body): void {
    apiLoadArticle($conn, $params[0]);
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
    apiLoadArticle($conn, $params[0]);
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
