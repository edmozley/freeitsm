<?php
/**
 * KnowledgeService — the shared write rules for the Knowledge Base:
 * article create/update (tags + save-as-version + best-effort embedding
 * regeneration) and the recycle-bin trio archive / restore / purge.
 *
 * Shared by the UI endpoints (api/knowledge/*.php) and the REST API
 * (api/v1/resources/knowledge.php). Each caller passes an ActorContext +
 * canonical input; this layer validates + writes and returns the affected id(s)
 * or throws ServiceError. It never emits HTTP.
 *
 * Canonical behaviour = the API resource's: title required + ≤255 chars, owner
 * must be a real active analyst, next_review_date validated, updating an
 * archived article is refused (409), and the recycle-bin guards (archive an
 * archived article / restore a live one / purge a live one → 409). Articles are
 * created *published* unless the caller passes is_published => false; the only
 * caller that does is the Knowledge assistant, whose AI-written drafts must
 * reach a human before a reader. Bodies are TinyMCE HTML stored verbatim (no
 * sanitisation exists anywhere).
 *
 * ⚙️ Side effect: after a save, the OpenAI search embedding is regenerated so
 * AI chat / vector search keep working. It is deliberately BEST-EFFORT — it runs
 * *after* the article is committed, is wrapped in try/catch, and any failure
 * (no key configured, an invalid key, a missing key file, an OpenAI error/
 * timeout) is swallowed. The article is always saved regardless; the embedding
 * just doesn't get updated. This service requires encryption.php so the step
 * works on BOTH the UI and the API (the API path historically lacked
 * decryptValue() and silently skipped embeddings — #727 fixed that so
 * API-written articles are searchable too). No key configured → still a clean
 * no-op.
 *
 * Canonical input keys: title, body_html, tags[], owner_id, next_review_date,
 * save_as_version. The UI adapter maps its `body` to `body_html`.
 */

require_once __DIR__ . '/../service_context.php';
require_once __DIR__ . '/../encryption.php';   // decryptValue() for the embedding step (safe: only reads the key file when actually called)
require_once __DIR__ . '/../ai_embeddings.php';   // aiEmbed(), aiEmbeddingText()
require_once __DIR__ . '/../knowledge/audience.php';
require_once __DIR__ . '/../knowledge/audit.php';   // every write leaves a history row
require_once __DIR__ . '/../tenancy.php';
require_once __DIR__ . '/../knowledge/visibility.php';   // knowledgeCanRead(), knowledgeFolderIsReadable()
require_once dirname(__DIR__, 2) . '/workflow/includes/engine.php';

class KnowledgeService
{
    /**
     * Validate a submitted company for an article.
     *
     * '' / null => NULL => shared with every company (the default, and what every
     * pre-multi-tenancy article is). Otherwise it must be a real company the actor
     * can actually reach — an analyst restricted to two clients must not be able to
     * file an article against a third, and an API key inherits the same scope.
     *
     * Returns the id to store, or null for shared.
     *
     * @param mixed $raw  the tenant_id from the request; '', null or 0 means shared with every company
     */
    private static function resolveTenantId(PDO $conn, ActorContext $ctx, $raw): ?int
    {
        if ($raw === '' || $raw === null) return null;
        $tenantId = (int)$raw;
        if ($tenantId <= 0) return null;

        if (!getTenantById($conn, $tenantId)) {
            throw new ServiceError('validation', 'invalid_field', "'tenant_id' is not a known company.");
        }
        // companyScope null = every company (single-company install, or an
        // all-access analyst/key). Otherwise it is the explicit allowed list.
        $scope = $ctx->companyScope;
        if (is_array($scope) && !in_array($tenantId, array_map('intval', $scope), true)) {
            throw new ServiceError('forbidden', 'forbidden', 'You do not have access to that company.');
        }
        return $tenantId;
    }

    /**
     * Validate a submitted folder.
     *
     * '' / null => NULL => the root, which is where every article lived before
     * folders existed and where anything unfiled belongs.
     *
     * A folder that does not exist is REJECTED rather than quietly becoming the
     * root: filing a document somewhere and finding it at the top level instead
     * is the kind of silent miss that makes people distrust the whole tree. Same
     * reasoning as resolveAudience() rejecting a bogus value on the write path
     * while the read path normalises.
     *
     * @param mixed $raw  the folder_id from the request; '', null or 0 means the root
     */
    private static function resolveFolderId(PDO $conn, ActorContext $ctx, $raw): ?int
    {
        if ($raw === '' || $raw === null) return null;
        $id = (int)$raw;
        if ($id <= 0) return null;
        try {
            $st = $conn->prepare("SELECT 1 FROM knowledge_folders WHERE id = ?");
            $st->execute([$id]);
            // A folder you cannot read is, to you, not a folder - the same words
            // as one that does not exist, so an id gives nothing away. Filing an
            // article into it used to be allowed (report item 8, October 2026).
            $viewer = self::viewerFor($conn, $ctx);
            if (!$st->fetchColumn() || ($viewer && !knowledgeFolderIsReadable($conn, $viewer, $id))) {
                throw new ServiceError('validation', 'invalid_field', "'folder_id' is not a known folder.");
            }
        } catch (PDOException) {
            // Table absent (pre-Database-Verify): no folders exist, so the only
            // honest answer is the root.
            return null;
        }
        return $id;
    }

    /**
     * Validate a submitted audience. Unlike the read path — which normalises junk
     * down to 'internal' so a bad value can never widen visibility — a WRITE with a
     * bogus audience is a caller bug and is rejected outright, so nobody silently
     * saves an article as internal while believing they published it.
     *
     * @param mixed $raw  the audience from the request, an Audience value; '' or null means internal
     */
    private static function resolveAudience($raw): string
    {
        if ($raw === '' || $raw === null) return Audience::INTERNAL;
        if (!Audience::isValid($raw)) {
            throw new ServiceError('validation', 'invalid_field',
                "'audience' must be one of: " . implode(', ', Audience::all()) . '.');
        }
        return (string)$raw;
    }

    /**
     * Refuse to touch an article belonging to a company this actor cannot reach.
     *
     * These mutations previously took a bare id and checked nothing, which was
     * harmless while Knowledge was install-wide and every analyst could see every
     * article. Now that articles have owners, an id is a guess away.
     *
     * A SHARED article (tenant_id NULL) is everyone's, so anyone may act on it.
     * companyScope null = every company (single-company install, or an all-access
     * analyst/key).
     *
     * @param int $articleId  the knowledge article being changed
     */
    private static function assertCanAccessArticle(PDO $conn, ActorContext $ctx, int $articleId): void
    {
        $stmt = $conn->prepare("SELECT tenant_id FROM knowledge_articles WHERE id = ?");
        $stmt->execute([$articleId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return;                                  // loadArticleRow raises the 404

        $scope = $ctx->companyScope;
        if (is_array($scope) && $row['tenant_id'] !== null   // null = shared with everyone
            && !in_array((int)$row['tenant_id'], array_map('intval', $scope), true)) {
            throw new ServiceError('forbidden', 'forbidden', 'That article belongs to a company you do not have access to.');
        }

        // 🔴 And the article must be one this actor may READ. The company check
        // alone let an analyst who was denied an article - by its folder or its
        // own access list - edit, move, archive, restore or purge it by id. The
        // folder drag-and-drop already checked both ends; these paths did not.
        // Reported privately, October 2026. Same words as a missing article.
        $viewer = self::viewerFor($conn, $ctx);
        if ($viewer && !knowledgeCanRead($conn, $viewer, $articleId, ['lifecycle' => 'any'])) {
            throw new ServiceError('not_found', 'not_found', 'Article not found.');
        }
    }

    /**
     * Who is acting, as Knowledge's read rules see them: this analyst (for the
     * access lists), across the companies the context already allows - the same
     * scope the company check above uses. Null for the system actor: internal
     * callers are not restricted.
     *
     * TRAP: not KnowledgeViewer::forAnalyst(). That reads the analyst's ACTIVE
     *   company from the header switcher, which is right for a LIST and wrong for
     *   a write by id: an administrator switched to company A could no longer edit
     *   an article in company B they can perfectly well reach.
     */
    private static function viewerFor(PDO $conn, ActorContext $ctx): ?KnowledgeViewer
    {
        if ($ctx->actorId <= 0) return null;
        return KnowledgeViewer::forApiKey($conn, ['analyst_id' => $ctx->actorId, 'company_scope' => $ctx->companyScope]);
    }

    // ======================================================================
    //  Articles
    // ======================================================================

    /**
     * Create (no id) or update (id present) an article. Returns ['id','created','embedding_generated'].
     *
     * @param array $in  id (absent = new), title, body_html, owner_id, next_review_date, tenant_id, audience, folder_id,
     *     tags, is_published, save_as_version - on update only keys sent are changed
     */
    public static function saveArticle(PDO $conn, ActorContext $ctx, array $in): array
    {
        if (!empty($in['id'])) {
            $articleId = (int)$in['id'];
            $current   = self::loadArticleRow($conn, $articleId);     // 404 if gone
            self::assertCanAccessArticle($conn, $ctx, $articleId);    // not another company's
            if ((int)$current['is_archived']) {
                throw new ServiceError('conflict', 'conflict', 'Article is in the recycle bin. Restore it before updating.');
            }
            if (!array_diff_key($in, ['id' => true])) {
                throw new ServiceError('validation', 'missing_field', 'No fields to update.');
            }

            $newTitle = $current['title'];
            if (array_key_exists('title', $in)) {
                $newTitle = trim((string)$in['title']);
                if ($newTitle === '') {
                    throw new ServiceError('validation', 'invalid_field', "'title' cannot be empty.");
                }
                if (mb_strlen($newTitle) > 255) {
                    throw new ServiceError('validation', 'invalid_field', "'title' must be at most 255 characters.");
                }
            }
            $newBody = array_key_exists('body_html', $in) ? (string)$in['body_html'] : (string)$current['body'];

            $updates = ['title = ?', 'body = ?', 'modified_datetime = UTC_TIMESTAMP()'];
            $args    = [$newTitle, $newBody];

            if (array_key_exists('owner_id', $in)) {
                $ownerId = ($in['owner_id'] === '' || $in['owner_id'] === null) ? null : (int)$in['owner_id'];
                if ($ownerId !== null) {
                    self::resolveAnalyst($conn, $ownerId);
                }
                $updates[] = 'owner_id = ?';
                $args[]    = $ownerId;
            }
            if (array_key_exists('next_review_date', $in)) {
                $updates[] = 'next_review_date = ?';
                $args[]    = self::parseDateOnly($in['next_review_date'], 'next_review_date');
            }
            // Company + audience are only touched when the caller sends them, so an
            // adapter that knows nothing about either (or a partial PATCH) can never
            // silently reset an article to shared+internal.
            if (array_key_exists('tenant_id', $in)) {
                $updates[] = 'tenant_id = ?';
                $args[]    = self::resolveTenantId($conn, $ctx, $in['tenant_id']);
            }
            if (array_key_exists('audience', $in)) {
                $updates[] = 'audience = ?';
                $args[]    = self::resolveAudience($in['audience']);
            }
            // Which folder it is filed in. Same "only when sent" rule as the two
            // above, for the same reason: an adapter that knows nothing about
            // folders (the REST API today, an importer) must not silently move
            // an article to the root every time it saves a title.
            if (array_key_exists('folder_id', $in)) {
                $updates[] = 'folder_id = ?';
                $args[]    = self::resolveFolderId($conn, $ctx, $in['folder_id']);
            }

            $saveAsVersion = !empty($in['save_as_version']);

            $conn->beginTransaction();
            try {
                if ($saveAsVersion) {
                    $conn->prepare(
                        "INSERT INTO knowledge_article_versions (article_id, version, title, body, saved_by_id, saved_datetime)
                         SELECT id, version, title, body, ?, UTC_TIMESTAMP() FROM knowledge_articles WHERE id = ?"
                    )->execute([$ctx->actorId, $articleId]);
                    $updates[] = 'version = version + 1';
                }
                $args[] = $articleId;
                $conn->prepare('UPDATE knowledge_articles SET ' . implode(', ', $updates) . ' WHERE id = ?')->execute($args);

                if (isset($in['tags']) && is_array($in['tags'])) {
                    self::setTags($conn, $articleId, $in['tags']);
                }
                $conn->commit();
            } catch (Exception $e) {
                if ($conn->inTransaction()) $conn->rollBack();
                throw $e;
            }
            $embGen = self::updateEmbedding($conn, $articleId, $newTitle, $newBody);
            self::reindexForSearch($conn, $articleId);
            WorkflowEngine::dispatch('knowledge.updated', ['article' => ['id' => $articleId, 'title' => $newTitle]]);
            // Every write leaves a history row. Which FIELDS changed, not their
            // values: an audit trail holding old article bodies is a second
            // uncontrolled copy of the content, and knowledge_article_versions
            // already keeps the versions people actually want to restore.
            knowledgeAuditLog($conn, 'article', $articleId, 'edit', $ctx->actorId, [
                'fields' => array_values(array_intersect(array_keys($in),
                    ['title','body_html','tags','owner_id','next_review_date','tenant_id','audience','folder_id'])),
                'save_as_version' => !empty($in['save_as_version']),
            ]);
            return ['id' => $articleId, 'created' => false, 'embedding_generated' => $embGen];
        }

        $title = trim((string)($in['title'] ?? ''));
        if ($title === '') {
            throw new ServiceError('validation', 'missing_field', "'title' is required.");
        }
        if (mb_strlen($title) > 255) {
            throw new ServiceError('validation', 'invalid_field', "'title' must be at most 255 characters.");
        }
        $bodyHtml = (string)($in['body_html'] ?? '');

        $ownerId = null;
        if (isset($in['owner_id']) && $in['owner_id'] !== '' && $in['owner_id'] !== null) {
            $ownerId = (int)$in['owner_id'];
            self::resolveAnalyst($conn, $ownerId);
        }
        $nextReview = self::parseDateOnly($in['next_review_date'] ?? null, 'next_review_date');
        // Omitted => shared with every company, and internal. Both are the safe end:
        // a new article is never narrower than the author expects, and never visible
        // to the public until they say so.
        $tenantId = self::resolveTenantId($conn, $ctx, $in['tenant_id'] ?? null);
        $audience = self::resolveAudience($in['audience'] ?? null);

        // Published unless the caller explicitly asks for a draft. Defaulting to
        // 1 keeps every existing caller (the editor, the REST API, the importer)
        // behaving exactly as before; the Knowledge assistant is the first caller
        // that needs the other answer, because an AI-written article must always
        // land in front of a human before anyone can read it.
        $isPublished = array_key_exists('is_published', $in) ? (int)(bool)$in['is_published'] : 1;

        $conn->beginTransaction();
        try {
            $conn->prepare(
                "INSERT INTO knowledge_articles
                    (title, body, author_id, owner_id, next_review_date,
                     created_datetime, modified_datetime, is_published, view_count,
                     tenant_id, audience, folder_id)
                 VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP(), ?, 0, ?, ?, ?)"
            )->execute([$title, $bodyHtml, $ctx->actorId, $ownerId, $nextReview, $isPublished, $tenantId, $audience,
                        self::resolveFolderId($conn, $ctx, $in['folder_id'] ?? null)]);
            $articleId = (int)$conn->lastInsertId();

            if (isset($in['tags']) && is_array($in['tags'])) {
                self::setTags($conn, $articleId, $in['tags']);
            }
            $conn->commit();
        } catch (Exception $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            throw $e;
        }
        $embGen = self::updateEmbedding($conn, $articleId, $title, $bodyHtml);

        // Indexed whether or not it is published, unlike the event below: an
        // analyst is meant to find their own unfinished drafts.
        self::reindexForSearch($conn, $articleId);

        // ⚠️ Only a genuinely published article fires knowledge.published. A draft
        // has not been published to anyone, and a workflow that announces new
        // articles would otherwise email the whole company about a page nobody
        // can open.
        if ($isPublished) {
            try {
                WorkflowEngine::dispatch('knowledge.published', [
                    'article' => ['id' => $articleId, 'title' => $title],
                ]);
            } catch (Exception $wfEx) {
                error_log('Workflow dispatch error in knowledge service (published): ' . $wfEx->getMessage());
            }
        }
        knowledgeAuditLog($conn, 'article', $articleId, 'create', $ctx->actorId, ['title' => $title]);

        return ['id' => $articleId, 'created' => true, 'embedding_generated' => $embGen];
    }

    /**
     * Keep the search corpus in step with an article (discussion #53).
     *
     * Called from every path that writes `knowledge_articles`, which is only
     * this service — see the note on searchIndexArticle() for why articles are
     * indexed by direct call while tickets go through the dispatch seam.
     *
     * Never throws. An index that cannot be written must not cost somebody the
     * article they just saved, and an install that has not run Database
     * Verification has no corpus at all, which is a normal state rather than a
     * fault.
     *
     * @param int $articleId  the knowledge article to reindex (or remove from the index)
     */
    private static function reindexForSearch(PDO $conn, int $articleId): void
    {
        try {
            require_once __DIR__ . '/../search/indexer.php';
            if (!searchCorpusReady($conn)) return;
            searchIndexArticle($conn, $articleId);
        } catch (Throwable $e) {
            error_log('[KnowledgeService::reindexForSearch] ' . $e->getMessage());
        }
    }

    /**
     * Soft-archive an article (move to recycle bin). 404 if gone, 409 if already archived.
     *
     * @param int $id  the knowledge article to move to the recycle bin
     */
    public static function archiveArticle(PDO $conn, ActorContext $ctx, int $id): int
    {
        $row = self::loadArticleRow($conn, $id);
        self::assertCanAccessArticle($conn, $ctx, $id);
        $stmt = $conn->prepare(
            "UPDATE knowledge_articles
             SET is_archived = 1, archived_datetime = UTC_TIMESTAMP(), archived_by_id = ?
             WHERE id = ? AND (is_archived = 0 OR is_archived IS NULL)"
        );
        $stmt->execute([$ctx->actorId, $id]);
        if ($stmt->rowCount() === 0) {
            throw new ServiceError('conflict', 'conflict', 'Article is already in the recycle bin.');
        }
        // Out of the recycle bin's way: an archived article drops out of search,
        // matching the command palette, which has always excluded them.
        self::reindexForSearch($conn, $id);
        WorkflowEngine::dispatch('knowledge.archived', ['article' => ['id' => $id, 'title' => $row['title'] ?? null]]);
        knowledgeAuditLog($conn, 'article', $id, 'delete', $ctx->actorId, ['title' => $row['title'] ?? null]);
        return $id;
    }

    /**
     * Restore an archived article. 404 if gone, 409 if not archived.
     *
     * @param int $id  the archived knowledge article to restore
     */
    public static function restoreArticle(PDO $conn, ActorContext $ctx, int $id): int
    {
        self::loadArticleRow($conn, $id);
        self::assertCanAccessArticle($conn, $ctx, $id);
        $stmt = $conn->prepare(
            "UPDATE knowledge_articles
             SET is_archived = 0, archived_datetime = NULL, archived_by_id = NULL
             WHERE id = ? AND is_archived = 1"
        );
        $stmt->execute([$id]);
        if ($stmt->rowCount() === 0) {
            throw new ServiceError('conflict', 'conflict', 'Article is not in the recycle bin.');
        }
        self::reindexForSearch($conn, $id);   // back out of the bin, back into search
        knowledgeAuditLog($conn, 'article', $id, 'restore', $ctx->actorId, null);
        return $id;
    }

    /**
     * Permanently delete an archived article (+ versions/tags, orphan-tag cleanup). 404/409 guards.
     *
     * @param int $id  the archived knowledge article to delete for good
     */
    public static function purgeArticle(PDO $conn, ActorContext $ctx, int $id): int
    {
        $row = self::loadArticleRow($conn, $id);
        self::assertCanAccessArticle($conn, $ctx, $id);
        if (!(int)$row['is_archived']) {
            throw new ServiceError('conflict', 'conflict', 'Only archived articles can be permanently deleted. DELETE (archive) it first.');
        }
        // Children explicitly, not via cascade (versions FK has no cascade; grown installs may lack FKs).
        $conn->prepare("DELETE FROM knowledge_article_versions WHERE article_id = ?")->execute([$id]);
        $conn->prepare("DELETE FROM knowledge_article_tags WHERE article_id = ?")->execute([$id]);
        $conn->prepare("DELETE FROM knowledge_articles WHERE id = ? AND is_archived = 1")->execute([$id]);
        $conn->exec("DELETE FROM knowledge_tags WHERE id NOT IN (SELECT DISTINCT tag_id FROM knowledge_article_tags)");
        // The article row has gone, so this removes the corpus row rather than
        // rewriting it. There is no foreign key from search_documents to
        // knowledge_articles to do it for us — the FK is on ticket_id only.
        self::reindexForSearch($conn, $id);
        // Written AFTER the row is gone, deliberately: the history outlives the
        // article, and "who permanently deleted this" is the single most likely
        // question anybody will ever ask of this table.
        knowledgeAuditLog($conn, 'article', $id, 'purge', $ctx->actorId, ['title' => $row['title'] ?? null]);
        return $id;
    }

    // ======================================================================
    //  Internals
    // ======================================================================

    /**
     * Loads one Knowledge article; throws not found when it does not exist.
     *
     * @param int $id  the knowledge article to load
     */
    private static function loadArticleRow(PDO $conn, int $id): array
    {
        $stmt = $conn->prepare("SELECT * FROM knowledge_articles WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new ServiceError('not_found', 'not_found', 'Article not found.');
        }
        return $row;
    }

    /**
     * Replace the article's tag set: get-or-create each name, relink (mirrors knowledge_save.php).
     *
     * @param int $articleId  the knowledge article whose tags are replaced
     * @param array $tags  tag names; blanks and names over 50 characters are skipped, new names are created
     */
    private static function setTags(PDO $conn, int $articleId, array $tags): void
    {
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
     * Regenerate the article's OpenAI embedding — best-effort, after commit,
     * silent on failure. Returns true only if an embedding was stored. No-ops
     * unless decryptValue() is loaded and a key is configured (see class header).
     *
     * @param int $articleId  the knowledge article
     * @param string $title  the article's title
     * @param string $body  the article's body as HTML; tags are stripped before it is sent
     */
    private static function updateEmbedding(PDO $conn, int $articleId, string $title, string $body): bool
    {
        try {
            $stmt = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'knowledge_openai_api_key'");
            $stmt->execute();
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row || empty($row['setting_value']) || !function_exists('decryptValue')) {
                return false;
            }
            $apiKeyValue = decryptValue($row['setting_value']);
            if (!$apiKeyValue) {
                return false;
            }

            $embedding = aiEmbed(aiEmbeddingText($title, $body), $apiKeyValue);
            if ($embedding) {
                $conn->prepare("UPDATE knowledge_articles SET embedding = ?, embedding_updated = UTC_TIMESTAMP() WHERE id = ?")
                     ->execute([json_encode($embedding), $articleId]);
                return true;
            }
        } catch (Exception) {
            // Embedding is a bonus, never a blocker — same as the UI.
        }
        return false;
    }

    /**
     * Validate an analyst id exists + is active (throwing twin of apiResolveAnalyst).
     *
     * @param int $analystId  the analyst named as the article's owner - not the actor
     */
    private static function resolveAnalyst(PDO $conn, int $analystId): void
    {
        $stmt = $conn->prepare("SELECT full_name FROM analysts WHERE id = ? AND is_active = 1");
        $stmt->execute([$analystId]);
        if ($stmt->fetchColumn() === false) {
            throw new ServiceError('validation', 'invalid_field', "Unknown or inactive analyst id: {$analystId}");
        }
    }

    /**
     * Validate an optional YYYY-MM-DD date (throwing twin of apiParseDateOnly).
     *
     * @param mixed $value  the date from the request, Y-m-d; null or '' gives null
     * @param string $field  the request field name, used in the error message
     */
    private static function parseDateOnly($value, string $field): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $d = DateTimeImmutable::createFromFormat('Y-m-d', (string)$value);
        if (!$d || $d->format('Y-m-d') !== (string)$value) {
            throw new ServiceError('validation', 'invalid_field', "'{$field}' must be a date in YYYY-MM-DD format.");
        }
        return (string)$value;
    }
}
