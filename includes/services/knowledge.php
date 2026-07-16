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
 * always created *published* — the product has no draft workflow. Bodies are
 * TinyMCE HTML stored verbatim (no sanitisation exists anywhere).
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
require_once __DIR__ . '/../knowledge/audience.php';
require_once __DIR__ . '/../tenancy.php';
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
     * Validate a submitted audience. Unlike the read path — which normalises junk
     * down to 'internal' so a bad value can never widen visibility — a WRITE with a
     * bogus audience is a caller bug and is rejected outright, so nobody silently
     * saves an article as internal while believing they published it.
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

    // ======================================================================
    //  Articles
    // ======================================================================

    /** Create (no id) or update (id present) an article. Returns ['id','created','embedding_generated']. */
    public static function saveArticle(PDO $conn, ActorContext $ctx, array $in): array
    {
        if (!empty($in['id'])) {
            $articleId = (int)$in['id'];
            $current   = self::loadArticleRow($conn, $articleId);     // 404 if gone
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
            WorkflowEngine::dispatch('knowledge.updated', ['article' => ['id' => $articleId, 'title' => $newTitle]]);
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

        $conn->beginTransaction();
        try {
            $conn->prepare(
                "INSERT INTO knowledge_articles
                    (title, body, author_id, owner_id, next_review_date,
                     created_datetime, modified_datetime, is_published, view_count,
                     tenant_id, audience)
                 VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP(), 1, 0, ?, ?)"
            )->execute([$title, $bodyHtml, $ctx->actorId, $ownerId, $nextReview, $tenantId, $audience]);
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

        try {
            WorkflowEngine::dispatch('knowledge.published', [
                'article' => ['id' => $articleId, 'title' => $title],
            ]);
        } catch (Exception $wfEx) {
            error_log('Workflow dispatch error in knowledge service (published): ' . $wfEx->getMessage());
        }

        return ['id' => $articleId, 'created' => true, 'embedding_generated' => $embGen];
    }

    /** Soft-archive an article (move to recycle bin). 404 if gone, 409 if already archived. */
    public static function archiveArticle(PDO $conn, ActorContext $ctx, int $id): int
    {
        $row = self::loadArticleRow($conn, $id);
        $stmt = $conn->prepare(
            "UPDATE knowledge_articles
             SET is_archived = 1, archived_datetime = UTC_TIMESTAMP(), archived_by_id = ?
             WHERE id = ? AND (is_archived = 0 OR is_archived IS NULL)"
        );
        $stmt->execute([$ctx->actorId, $id]);
        if ($stmt->rowCount() === 0) {
            throw new ServiceError('conflict', 'conflict', 'Article is already in the recycle bin.');
        }
        WorkflowEngine::dispatch('knowledge.archived', ['article' => ['id' => $id, 'title' => $row['title'] ?? null]]);
        return $id;
    }

    /** Restore an archived article. 404 if gone, 409 if not archived. */
    public static function restoreArticle(PDO $conn, ActorContext $ctx, int $id): int
    {
        self::loadArticleRow($conn, $id);
        $stmt = $conn->prepare(
            "UPDATE knowledge_articles
             SET is_archived = 0, archived_datetime = NULL, archived_by_id = NULL
             WHERE id = ? AND is_archived = 1"
        );
        $stmt->execute([$id]);
        if ($stmt->rowCount() === 0) {
            throw new ServiceError('conflict', 'conflict', 'Article is not in the recycle bin.');
        }
        return $id;
    }

    /** Permanently delete an archived article (+ versions/tags, orphan-tag cleanup). 404/409 guards. */
    public static function purgeArticle(PDO $conn, ActorContext $ctx, int $id): int
    {
        $row = self::loadArticleRow($conn, $id);
        if (!(int)$row['is_archived']) {
            throw new ServiceError('conflict', 'conflict', 'Only archived articles can be permanently deleted. DELETE (archive) it first.');
        }
        // Children explicitly, not via cascade (versions FK has no cascade; grown installs may lack FKs).
        $conn->prepare("DELETE FROM knowledge_article_versions WHERE article_id = ?")->execute([$id]);
        $conn->prepare("DELETE FROM knowledge_article_tags WHERE article_id = ?")->execute([$id]);
        $conn->prepare("DELETE FROM knowledge_articles WHERE id = ? AND is_archived = 1")->execute([$id]);
        $conn->exec("DELETE FROM knowledge_tags WHERE id NOT IN (SELECT DISTINCT tag_id FROM knowledge_article_tags)");
        return $id;
    }

    // ======================================================================
    //  Internals
    // ======================================================================

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

    /** Replace the article's tag set: get-or-create each name, relink (mirrors knowledge_save.php). */
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
                    return true;
                }
            }
        } catch (Exception $e) {
            // Embedding is a bonus, never a blocker — same as the UI.
        }
        return false;
    }

    /** Validate an analyst id exists + is active (throwing twin of apiResolveAnalyst). */
    private static function resolveAnalyst(PDO $conn, int $analystId): void
    {
        $stmt = $conn->prepare("SELECT full_name FROM analysts WHERE id = ? AND is_active = 1");
        $stmt->execute([$analystId]);
        if ($stmt->fetchColumn() === false) {
            throw new ServiceError('validation', 'invalid_field', "Unknown or inactive analyst id: {$analystId}");
        }
    }

    /** Validate an optional YYYY-MM-DD date (throwing twin of apiParseDateOnly). */
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
