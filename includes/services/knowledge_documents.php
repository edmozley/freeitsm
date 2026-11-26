<?php
/**
 * KnowledgeDocumentService - the documents on a Knowledge article, for the REST API (GH #416).
 *
 * The PDFs, Office files and links in an article's Documents panel, which the Help
 * Centre can offer to customers. Nothing here is a second copy of the document rules:
 * every file check, row and link is DocumentsService's (the code behind the Documents
 * panel and the project-documents API, GH #287), and every access question is the
 * article's (includes/knowledge/visibility.php).
 *
 * 🔑 ACCESS IS THE ARTICLE'S, AS THE KEY SEES IT. KnowledgeTransferService::object()
 * answers for the API key - its company scope (KnowledgeViewer::forApiKey), the
 * article's audience and access list, the analyst's Knowledge module and write level.
 * NOT documentCanWriteParent(): for an article that asks KnowledgeViewer::forAnalyst(),
 * which follows the header switcher of a session an API call does not have, so on a
 * multi-company install every company but the analyst's default would be refused.
 *
 * 🔑 A DOCUMENT IS ONLY EVER REACHED THROUGH THE ARTICLE IT IS ON. An article the key
 * cannot reach is 404, and so is a document that is not on THAT article - even one the
 * key could reach through another article. Losing access to article A stops A's route
 * at once; a grant through article B is B's route, not A's.
 *
 * 🔑 RETRIES. client_reference is scoped to the key's analyst and the article, and is
 * recorded in knowledge_api_references in the same transaction as the document - as
 * pictures are (KnowledgeTransferService::createOnce). The same request again returns
 * the document as it is NOW and writes nothing: no metadata or Help Centre setting is
 * put back, the revision does not move, and a document someone has since removed from
 * the article is not attached again (404).
 *
 * 🔑 THE HELP CENTRE. A link made here is hidden unless the request says otherwise, and
 * the setting is written in the same insert as the link (documentInsertLink) - never
 * shown first and hidden after. It lives on the LINK: showing a document on article A
 * never publishes it on article B.
 *
 * Side effects are the Documents panel's: the title is searchable at once and the text
 * is extracted in the background. No AI provider sees the file, the article's embedding
 * does not change, and no workflow or notification runs. Each change is in the
 * article's history and moves its revision (KnowledgeTransferService::revision()).
 *
 * @wiki API-Uploads-and-Knowledge-Documents-Design
 */

require_once __DIR__ . '/knowledge_transfer.php';
require_once __DIR__ . '/documents.php';
require_once __DIR__ . '/api_uploads.php';
require_once __DIR__ . '/../documents.php';
require_once __DIR__ . '/../knowledge/audit.php';
require_once __DIR__ . '/../service_context.php';   // throws ServiceError itself (tests/service-error-loaded.php)

class KnowledgeDocumentService
{
    /** knowledge_api_references.operation for an article's documents. */
    public const OPERATION = 'document';

    /** document_links.parent_type of a Knowledge article. */
    public const PARENT_TYPE = 'knowledge_article';

    /** documents.title is VARCHAR(255); description is TEXT. Longer is refused, never cut short. */
    public const TITLE_MAX = 255;
    public const DESCRIPTION_MAX_BYTES = 65535;

    /**
     * The article, as the key may reach it - 404 (not_found) when it cannot, 403 when its
     * analyst may only read Knowledge and a write was asked for.
     *
     * @param bool $write  true for a change; inside a transaction the row is also locked
     */
    public static function article(PDO $conn, ActorContext $ctx, int $articleId, bool $write = false): array
    {
        return KnowledgeTransferService::object($conn, $ctx, 'article', $articleId, $write);
    }

    /**
     * Add a new document - a file, a REST upload, or a link - to an article.
     *
     * A file sent as a multipart form and the same bytes sent as an upload are the same
     * request for client_reference: both are identified by their name and their bytes'
     * hash, so a client may switch to pieces and still retry safely.
     *
     * @param array  $in      title, description, external_url, client_reference, show_in_help_centre (bool),
     *                        expected_revision; already type-checked by the caller
     * @param ?array $file    the $_FILES entry, or null
     * @param ?array $upload  an upload row from ApiUploadService::forHandOver() (purpose knowledge_document), or null
     * @return array{id:int, replayed:bool}
     * @throws ServiceError not_found, forbidden, conflict (archived, reference_conflict, revision_conflict, upload_used), validation
     */
    public static function create(PDO $conn, ActorContext $ctx, int $articleId, array $in, ?array $file, ?array $upload = null): array
    {
        // 1. ACCESS FIRST, before a byte of the file is written (DocumentsService's rule).
        $article = self::article($conn, $ctx, $articleId, true);
        if ((int)$article['is_archived']) throw self::archived();
        $show = (bool)($in['show_in_help_centre'] ?? false);
        $title = (string)($in['title'] ?? '');
        $description = (string)($in['description'] ?? '');
        $reference = $in['client_reference'] ?? null;
        self::checkText($title, $description);

        // 3 (for every path). What identifies this request for a retry: what was asked
        //    for, with a file as its name and its bytes' hash. expected_revision is a
        //    precondition, not content: a retry carries the revision from before its
        //    first attempt.
        $identity = ['title' => trim($title), 'description' => trim($description), 'show_in_help_centre' => $show];
        if ($reference !== null) $identity['client_reference'] = $reference;

        $prepared = null;
        if ($upload !== null && $upload['used_datetime'] !== null) {
            // A used upload is never stored again. Only the retry of its own hand-over -
            // same article, same client_reference - gets an answer, from createOnce below.
            if (!ApiUploadService::isRetryOf($upload, $articleId, $reference)) throw ApiUploadService::usedError();
            $identity += ['kind' => DOCUMENT_KIND_FILE, 'filename' => uploadCleanName((string)$upload['filename']), 'sha256' => $upload['sha256']];
        } else {
            // 2. The file's checks and storage - or the link's - exactly as the Documents
            //    panel. An upload is checked now, against the limit and types in force now.
            $prepared = DocumentsService::prepare($conn, [
                'file'         => $file,
                'local_file'   => $upload === null ? null : ['path' => ApiUploadService::path($upload), 'name' => (string)$upload['filename']],
                'external_url' => (string)($in['external_url'] ?? ''),   // two sources = "a file or a link, not both"
                'title'        => $title,
                'description'  => $description,
            ]);
            $doc = $prepared['doc'];
            $identity['kind'] = $doc['kind'];
            if ($doc['kind'] === DOCUMENT_KIND_FILE) {
                $identity['filename'] = $doc['original_name'];
                $identity['sha256']   = $doc['content_hash'];
            } else {
                $identity['external_url'] = $doc['external_url'];
            }
        }

        try {
            $result = KnowledgeTransferService::createOnce($conn, $ctx, self::OPERATION, $articleId, $identity,
                static function () use ($conn, $ctx, $articleId, $in, $prepared, $show, $upload, $reference) {
                    // Reached only when the reference names nothing yet: a used upload has nothing to add.
                    if ($prepared === null) throw ApiUploadService::usedError();
                    // Lock the article: document changes serialize with article saves.
                    $article = self::article($conn, $ctx, $articleId, true);
                    if ((int)$article['is_archived']) throw self::archived();
                    self::checkExpectedRevision($conn, $articleId, $in);
                    $tenantId = $article['tenant_id'] === null ? null : (int)$article['tenant_id'];
                    $id = DocumentsService::insert($conn, $ctx->actorId, self::PARENT_TYPE, $articleId, $prepared, $tenantId, null, $show);
                    // The receipt in the same transaction: the upload is used exactly when the document exists.
                    if ($upload !== null) ApiUploadService::markUsed($conn, $upload, $articleId, $id, $reference);
                    knowledgeAuditLog($conn, 'article', $articleId, 'edit', $ctx->actorId, ['document_added' => $id]);
                    return ['id' => $id];
                });
        } catch (Throwable $e) {
            if ($prepared !== null) DocumentsService::discard($prepared);   // nothing points at it
            throw $e;
        }

        if ($result['replayed']) {
            if ($prepared !== null) DocumentsService::discard($prepared);   // the copy just written is not needed
            // TRAP: removed since is removed. Answering with the old id would hand back a
            // document that is no longer on the article; attaching it again would undo
            // somebody's deliberate removal. Same as a deleted picture.
            if (!DocumentsService::isOn($conn, $result['id'], self::PARENT_TYPE, $articleId)) {
                throw new ServiceError('not_found', 'not_found', 'The document this client_reference added is no longer on this article, and is not attached again. Use a new client_reference to add it again.');
            }
            return $result;
        }
        // After the commit: the upload's pieces are not needed; findable by title at once, the text queued.
        if ($upload !== null) ApiUploadService::afterUse($upload);
        DocumentsService::indexAdded($conn, $result['id'], $prepared);
        return $result;
    }

    /**
     * Attach a document that already exists - on another article, a project, a contract -
     * to this article too. Attaching one that is already there changes nothing, including
     * its Help Centre setting, and does not check expected_revision: it is a retry.
     *
     * @param ?array $allowed  the key's analyst's modules now (null = all)
     * @param array  $in       show_in_help_centre (bool), expected_revision
     * @return bool true when the link was made now; false when it was already there
     * @throws ServiceError not_found (the article, or a document the analyst cannot see), conflict, forbidden
     */
    public static function attach(PDO $conn, ActorContext $ctx, ?array $allowed, int $articleId, int $documentId, array $in): bool
    {
        $article = self::article($conn, $ctx, $articleId, true);
        if ($documentId <= 0) throw new ServiceError('not_found', 'not_found', 'Document not found.');
        // Already here: seen through this very article, and a retry changes nothing.
        if (DocumentsService::isOn($conn, $documentId, self::PARENT_TYPE, $articleId)) return false;
        // The document itself must already be visible, so attaching cannot launder a
        // document into an article (DocumentsService::attach).
        if (!self::canSee($conn, $ctx, $allowed, $documentId)) {
            throw new ServiceError('not_found', 'not_found', 'Document not found.');
        }
        if ((int)$article['is_archived']) throw self::archived();
        $show = (bool)($in['show_in_help_centre'] ?? false);

        $made = KnowledgeTransferService::transaction($conn, static function () use ($conn, $ctx, $articleId, $documentId, $in, $show) {
            $article = self::article($conn, $ctx, $articleId, true);
            if ((int)$article['is_archived']) throw self::archived();
            if (DocumentsService::isOn($conn, $documentId, self::PARENT_TYPE, $articleId)) return false;   // a concurrent retry won
            self::checkExpectedRevision($conn, $articleId, $in);
            $made = DocumentsService::link($conn, $documentId, self::PARENT_TYPE, $articleId, $ctx->actorId, $show);
            if ($made) knowledgeAuditLog($conn, 'article', $articleId, 'edit', $ctx->actorId, ['document_attached' => $documentId]);
            return $made;
        });
        if ($made) DocumentsService::reindex($conn, $documentId);
        return $made;
    }

    /**
     * Change a document on an article: title and description (the document's own), and
     * show_in_help_centre (this article's link only).
     *
     * TRAP: title and description belong to the DOCUMENT, which every record it is
     * attached to shows. Changing them from one article would change what other
     * articles - and contracts, projects, assets the key's analyst may not even see -
     * show, and their revisions with them. So a document attached anywhere else
     * refuses them (409 document_shared); upload a separate copy to name it
     * differently here. show_in_help_centre is per link and always allowed.
     *
     * @param array $in  any of title, description (string or null), show_in_help_centre (bool); expected_revision
     * @return string[] the fields that changed; [] when the request matched what was there
     * @throws ServiceError not_found, conflict (document_shared, revision_conflict), validation
     */
    public static function update(PDO $conn, ActorContext $ctx, int $articleId, int $documentId, array $in): array
    {
        $wantsText = array_key_exists('title', $in) || array_key_exists('description', $in);
        if (!$wantsText && !array_key_exists('show_in_help_centre', $in)) {
            throw new ServiceError('validation', 'missing_field', 'Send title, description or show_in_help_centre.');
        }
        if (array_key_exists('title', $in) && trim((string)$in['title']) === '') {
            throw new ServiceError('validation', 'invalid_field', 'A title cannot be empty.');
        }
        self::checkText((string)($in['title'] ?? ''), (string)($in['description'] ?? ''));
        self::article($conn, $ctx, $articleId, true);

        $changed = KnowledgeTransferService::transaction($conn, static function () use ($conn, $ctx, $articleId, $documentId, $in, $wantsText) {
            self::article($conn, $ctx, $articleId, true);   // locks the article
            $hc = documentHelpCentreReady($conn) ? 'dl.show_in_help_centre' : '0 AS show_in_help_centre';
            $st = $conn->prepare("SELECT d.id, d.title, d.description, $hc
                                    FROM document_links dl
                                    JOIN documents d ON d.id = dl.document_id AND d.deleted_datetime IS NULL
                                   WHERE dl.document_id = ? AND dl.parent_type = ? AND dl.parent_id = ?
                                     FOR UPDATE");
            $st->execute([$documentId, self::PARENT_TYPE, $articleId]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row) throw new ServiceError('not_found', 'not_found', 'Document not found on this article.');
            self::checkExpectedRevision($conn, $articleId, $in);

            $changed = [];
            $title = array_key_exists('title', $in) ? trim((string)$in['title']) : (string)$row['title'];
            $description = array_key_exists('description', $in)
                ? (($in['description'] === null || trim((string)$in['description']) === '') ? null : trim((string)$in['description']))
                : $row['description'];
            if ($title !== (string)$row['title']) $changed[] = 'title';
            if ($description !== $row['description']) $changed[] = 'description';
            if ($wantsText && $changed) {
                $links = $conn->prepare('SELECT COUNT(*) FROM document_links WHERE document_id = ?');
                $links->execute([$documentId]);
                if ((int)$links->fetchColumn() > 1) {
                    throw new ServiceError('conflict', 'document_shared', 'This document is also attached elsewhere, and its title and description are shown there too. Only show_in_help_centre can be changed for this article; upload a separate copy to give it a different title or description here.');
                }
                $conn->prepare('UPDATE documents SET title = ?, description = ?, updated_datetime = UTC_TIMESTAMP() WHERE id = ?')
                     ->execute([$title, $description, $documentId]);
            }
            if (array_key_exists('show_in_help_centre', $in) && (bool)$in['show_in_help_centre'] !== ((int)$row['show_in_help_centre'] === 1)) {
                require_once __DIR__ . '/../knowledge/help_centre_documents.php';
                if (!helpCentreSetShown($conn, $documentId, $articleId, (bool)$in['show_in_help_centre'])) {
                    throw new ServiceError('conflict', 'database_verification_required', 'Run System > Database Verification before changing show_in_help_centre.');
                }
                $changed[] = 'show_in_help_centre';
            }
            if ($changed) {
                knowledgeAuditLog($conn, 'article', $articleId, 'edit', $ctx->actorId, ['document_changed' => $documentId, 'fields' => $changed]
                    + (in_array('show_in_help_centre', $changed, true) ? ['shown' => (bool)$in['show_in_help_centre']] : []));
            }
            return $changed;
        });
        if (array_intersect($changed, ['title', 'description'])) DocumentsService::reindex($conn, $documentId);
        return $changed;
    }

    /**
     * Take a document off this article only. Still attached elsewhere, it stays there;
     * this was its last link, it is deleted as in the app.
     *
     * @param mixed $expectedRevision  null = no check; otherwise the article's revision as last read
     * @return bool true when that was the document's last link, so it is gone
     * @throws ServiceError not_found, conflict (revision_conflict), forbidden
     */
    public static function detach(PDO $conn, ActorContext $ctx, int $articleId, int $documentId, $expectedRevision = null): bool
    {
        self::article($conn, $ctx, $articleId, true);
        $removed = KnowledgeTransferService::transaction($conn, static function () use ($conn, $ctx, $articleId, $documentId, $expectedRevision) {
            self::article($conn, $ctx, $articleId, true);   // locks the article
            if (!DocumentsService::isOn($conn, $documentId, self::PARENT_TYPE, $articleId)) {
                throw new ServiceError('not_found', 'not_found', 'Document not found on this article.');
            }
            if ($expectedRevision !== null) KnowledgeTransferService::checkRevision($conn, 'article', $articleId, $expectedRevision);
            $removed = DocumentsService::unlink($conn, $documentId, self::PARENT_TYPE, $articleId);
            knowledgeAuditLog($conn, 'article', $articleId, 'edit', $ctx->actorId, ['document_removed' => $documentId]);
            return $removed;
        });
        DocumentsService::afterUnlink($conn, $documentId, $removed);
        return $removed['orphaned'];
    }

    /**
     * An article's documents, newest first - metadata only, every document on the
     * article whether or not its HTML links to it.
     *
     * @param ?string $reference   only the document this analyst added here with this client_reference
     * @param ?int    $documentId  only this one (404 when it is not on the article)
     * @return array{total:int, rows:array}
     */
    public static function rows(PDO $conn, ActorContext $ctx, int $articleId, int $offset = 0, int $limit = 25, ?string $reference = null, ?int $documentId = null): array
    {
        self::article($conn, $ctx, $articleId);
        $where = 'dl.parent_type = ? AND dl.parent_id = ? AND d.deleted_datetime IS NULL';
        $args  = [self::PARENT_TYPE, $articleId];
        if ($documentId !== null) {
            $where .= ' AND d.id = ?';
            $args[] = $documentId;
        }
        if ($reference !== null) {
            $where .= ' AND EXISTS (SELECT 1 FROM knowledge_api_references r
                                     WHERE r.analyst_id = ? AND r.operation = ? AND r.parent_id = dl.parent_id
                                       AND r.client_reference = ? AND r.object_id = d.id)';
            array_push($args, $ctx->actorId, self::OPERATION, $reference);
        }
        $c = $conn->prepare("SELECT COUNT(*) FROM document_links dl JOIN documents d ON d.id = dl.document_id WHERE $where");
        $c->execute($args);
        $total = (int)$c->fetchColumn();

        $hc    = documentHelpCentreReady($conn) ? 'dl.show_in_help_centre' : '0 AS show_in_help_centre';
        $files = DocumentsService::filesLinkReady($conn) ? 'd.files_item_id' : 'NULL AS files_item_id';
        // The reference shown is this analyst's own for this article; another analyst's is not theirs to see.
        $st = $conn->prepare(
            "SELECT d.id, d.kind, d.title, d.description, d.original_name, d.mime_type, d.size_bytes, d.content_hash,
                    d.external_url, d.created_datetime, d.uploaded_by_id, a.full_name AS uploaded_by_name, $hc, $files,
                    (SELECT r.client_reference FROM knowledge_api_references r
                      WHERE r.analyst_id = ? AND r.operation = ? AND r.parent_id = dl.parent_id AND r.object_id = d.id
                      ORDER BY r.id LIMIT 1) AS client_reference
               FROM document_links dl
               JOIN documents d ON d.id = dl.document_id
          LEFT JOIN analysts a ON a.id = d.uploaded_by_id
              WHERE $where
           ORDER BY d.created_datetime DESC, d.id DESC
              LIMIT " . max(1, min(100, $limit)) . ' OFFSET ' . max(0, $offset));
        $st->execute(array_merge([$ctx->actorId, self::OPERATION], $args));
        $rows = DocumentsService::filesView($conn, $ctx->actorId, $st->fetchAll(PDO::FETCH_ASSOC));
        if ($documentId !== null && !$rows) throw new ServiceError('not_found', 'not_found', 'Document not found on this article.');
        return ['total' => $total, 'rows' => $rows];
    }

    /**
     * One document on the article, with what serving it needs (storage_key). 404 when the
     * article is out of reach or the document is not on it.
     */
    public static function forContent(PDO $conn, ActorContext $ctx, int $articleId, int $documentId): array
    {
        self::article($conn, $ctx, $articleId);
        $st = $conn->prepare("SELECT d.id, d.kind, d.title, d.storage_key, d.original_name, d.mime_type, d.external_url
                                FROM document_links dl
                                JOIN documents d ON d.id = dl.document_id AND d.deleted_datetime IS NULL
                               WHERE dl.document_id = ? AND dl.parent_type = ? AND dl.parent_id = ?");
        $st->execute([$documentId, self::PARENT_TYPE, $articleId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new ServiceError('not_found', 'not_found', 'Document not found on this article.');
        return $row;
    }

    /**
     * The address an article's HTML uses for one of its files: the session endpoint
     * api/knowledge/document.php, which checks the reader - analyst or portal user -
     * against THIS article on every request. Null for a link (use its external_url) and
     * for a file in Files, which opens through Files.
     *
     * @param string $kind  documents.kind
     */
    public static function sessionUrl(int $articleId, int $documentId, string $kind): ?string
    {
        if ($kind !== DOCUMENT_KIND_FILE) return null;
        // BASE_URL makes this reference work when FreeITSM lives in a subfolder.
        return BASE_URL . 'api/knowledge/document.php?article_id=' . $articleId . '&id=' . $documentId;
    }

    /**
     * May the key see this document - the condition for attaching it somewhere else?
     * Through any record its analyst can see (documentCanView, the app's rule), or through
     * a Knowledge article the KEY can read.
     *
     * TRAP: documentCanView() alone is not enough here. For an article it asks
     * KnowledgeViewer::forAnalyst(), which without a session reads the analyst's DEFAULT
     * company - so on a multi-company install a key scoped to company B could not attach
     * a document from one of B's articles to another. The second path is the same
     * article rule every other route in this API applies, and reaches nothing the key
     * could not already list and download.
     *
     * @param ?array $allowed  the analyst's modules now (null = all)
     */
    private static function canSee(PDO $conn, ActorContext $ctx, ?array $allowed, int $documentId): bool
    {
        if (documentCanView($conn, $ctx->actorId, $allowed, $documentId)) return true;
        $st = $conn->prepare("SELECT dl.parent_id FROM document_links dl
                                JOIN documents d ON d.id = dl.document_id AND d.deleted_datetime IS NULL
                               WHERE dl.document_id = ? AND dl.parent_type = ?");
        $st->execute([$documentId, self::PARENT_TYPE]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $otherArticle) {
            try {
                self::article($conn, $ctx, (int)$otherArticle);
                return true;
            } catch (ServiceError $e) {
                if ($e->kind !== 'not_found') throw $e;   // forbidden is the key's answer for all of them
            }
        }
        return false;
    }

    /** A stale expected_revision stops the change; none given, none checked. */
    private static function checkExpectedRevision(PDO $conn, int $articleId, array $in): void
    {
        if (array_key_exists('expected_revision', $in) && $in['expected_revision'] !== null) {
            KnowledgeTransferService::checkRevision($conn, 'article', $articleId, $in['expected_revision']);
        }
    }

    /** Refuse, rather than cut short, a title or description the columns cannot hold. */
    private static function checkText(string $title, string $description): void
    {
        if (mb_strlen(trim($title)) > self::TITLE_MAX) {
            throw new ServiceError('validation', 'invalid_field', 'title can be at most ' . self::TITLE_MAX . ' characters.');
        }
        if (strlen(trim($description)) > self::DESCRIPTION_MAX_BYTES) {
            throw new ServiceError('validation', 'invalid_field', 'description can be at most ' . self::DESCRIPTION_MAX_BYTES . ' bytes.');
        }
    }

    private static function archived(): ServiceError
    {
        return new ServiceError('conflict', 'archived', 'Restore the article before adding documents to it.');
    }
}
