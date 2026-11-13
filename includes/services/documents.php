<?php
/**
 * DocumentsService - adding, attaching and detaching documents (GH #287).
 *
 * The one home for the rules the Documents panel and REST v1 share, so the two
 * cannot drift apart. Before this they lived inline in api/documents/save.php,
 * attach.php and unlink.php; those endpoints now call these methods and behave
 * exactly as before.
 *
 * Every method takes the analyst it acts for and that analyst's allowed modules
 * (null = all): a signed-in person in the app, or the analyst an API key acts as.
 * Nothing here can reach further than that analyst could in the app.
 *
 * 🔴 THE ACCESS CHECK RUNS FIRST, BEFORE ANY FILE IS WRITTEN (documentCanWriteParent).
 * Change management learned this the hard way: checking after move_uploaded_file()
 * stopped the database row, not the write.
 *
 * @wiki Project-Documents-API-Design
 */

require_once __DIR__ . '/../service_context.php';
require_once __DIR__ . '/../documents.php';
require_once __DIR__ . '/../uploads.php';

class DocumentsService
{
    /** A client_reference: 1-128 ASCII letters, digits, dots, underscores, colons or hyphens (as Change attachments). */
    private const REFERENCE_PATTERN = '/\A[A-Za-z0-9._:-]{1,128}\z/D';

    /**
     * Add a new document - a file or a link - and attach it to a record.
     *
     * @param int    $analystId  who it is added as
     * @param ?array $allowed    that analyst's allowed modules; null = all
     * @param string $parentType a documentEntityRegistry() key, e.g. 'project'
     * @param int    $parentId   the record's id
     * @param array  $in         file (a $_FILES entry) OR external_url; title, description, client_reference,
     *                           tenant_id (the document's company; default the analyst's active company)
     * @return array{id:int, replayed:bool} replayed = a retry of an upload already made with this client_reference
     * @throws ServiceError forbidden (no access - the same for "does not exist"), validation, conflict
     */
    public static function add(PDO $conn, int $analystId, ?array $allowed, string $parentType, int $parentId, array $in): array
    {
        if (!documentEntityDef($parentType) || $parentId <= 0) {
            throw new ServiceError('validation', 'invalid_field', 'A valid parent_type and parent_id are required.');
        }
        // 1. ACCESS FIRST. The same refusal whether it does not exist or is not
        //    the caller's: probing should not map out the estate.
        if (!documentCanWriteParent($conn, $analystId, $allowed, $parentType, $parentId)) {
            throw new ServiceError('forbidden', 'forbidden', 'You do not have access to that record.');
        }

        $file        = $in['file'] ?? null;
        $hasFile     = is_array($file) && ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
        $externalUrl = trim((string)($in['external_url'] ?? ''));
        $title       = trim((string)($in['title'] ?? ''));
        $description = trim((string)($in['description'] ?? ''));
        $reference   = isset($in['client_reference']) && $in['client_reference'] !== '' ? (string)$in['client_reference'] : null;

        if (!$hasFile && $externalUrl === '') throw new ServiceError('validation', 'missing_field', 'Attach a file, or give a link to one.');
        if ($hasFile && $externalUrl !== '')  throw new ServiceError('validation', 'invalid_field', 'Give a file or a link, not both.');
        if ($reference !== null && !preg_match(self::REFERENCE_PATTERN, $reference)) {
            throw new ServiceError('validation', 'invalid_field', 'client_reference must be 1-128 ASCII letters, digits, dots, underscores, colons or hyphens.');
        }
        if ($reference !== null && !self::referenceReady($conn)) $reference = null;   // before Database Verification

        // 2. Build the document row.
        $stored = null;
        if ($hasFile) {
            try {
                $stored = uploadStoreFile($file, dirname(__DIR__, 2) . '/uploads/' . DOCUMENT_STORAGE_DIR, attachmentAllowedTypes($conn));
            } catch (Exception $e) {
                throw new ServiceError('validation', 'invalid_file', $e->getMessage());
            }
            $doc = [
                'kind'          => DOCUMENT_KIND_FILE,
                'title'         => $title !== '' ? $title : $stored['original_name'],
                'storage_key'   => documentStorageKey($stored['stored_name']),
                'original_name' => $stored['original_name'],
                'mime_type'     => $stored['mime'],
                'size_bytes'    => $stored['size'],
                // Lets the same PDF attached to eleven assets be recognised as one file.
                'content_hash'  => @hash_file('sha256', $stored['path']) ?: null,
                'external_url'  => null,
            ];
        } else {
            // Only http(s). A javascript: or file: "link" is not a document, and this
            // string ends up in an href.
            if (!preg_match('#^https?://#i', $externalUrl)) throw new ServiceError('validation', 'invalid_field', 'A link must start with http:// or https://');
            if (strlen($externalUrl) > 2048) throw new ServiceError('validation', 'invalid_field', 'That link is too long.');
            $doc = [
                'kind' => DOCUMENT_KIND_LINK, 'title' => $title !== '' ? $title : $externalUrl, 'storage_key' => null,
                'original_name' => null, 'mime_type' => null, 'size_bytes' => null, 'content_hash' => null, 'external_url' => $externalUrl,
            ];
        }

        // 3. A retry: the same reference from the same person returns what it made
        //    the first time - provided it is the same thing, or it is a conflict.
        if ($reference !== null) {
            $find = $conn->prepare("SELECT d.id, d.kind, d.original_name, d.content_hash, d.external_url,
                                           EXISTS (SELECT 1 FROM document_links dl WHERE dl.document_id = d.id AND dl.parent_type = ? AND dl.parent_id = ?) AS here
                                      FROM documents d WHERE d.uploaded_by_id = ? AND d.client_reference = ? AND d.deleted_datetime IS NULL");
            $find->execute([$parentType, $parentId, $analystId, $reference]);
            if ($existing = $find->fetch(PDO::FETCH_ASSOC)) {
                $same = (int)$existing['here'] === 1 && $existing['kind'] === $doc['kind']
                    && ($doc['kind'] === DOCUMENT_KIND_FILE
                        ? ($existing['original_name'] === $doc['original_name'] && $existing['content_hash'] === $doc['content_hash'])
                        : $existing['external_url'] === $doc['external_url']);
                if ($stored && is_file($stored['path'])) @unlink($stored['path']);   // the copy just written is not needed
                if (!$same) throw new ServiceError('conflict', 'reference_conflict', 'This client_reference already names a different document.');
                return ['id' => (int)$existing['id'], 'replayed' => true];
            }
        }

        // 4. Write the document and its first link, together.
        $tenantId = array_key_exists('tenant_id', $in) ? ($in['tenant_id'] !== null ? (int)$in['tenant_id'] : null) : (getActiveTenantId($conn, $analystId) ?: null);
        $conn->beginTransaction();
        try {
            $cols = 'kind, title, description, storage_key, original_name, mime_type, size_bytes, content_hash, external_url, tenant_id, uploaded_by_id';
            $vals = [
                $doc['kind'], mb_substr($doc['title'], 0, 255), $description !== '' ? $description : null,
                $doc['storage_key'], $doc['original_name'], $doc['mime_type'],
                $doc['size_bytes'], $doc['content_hash'], $doc['external_url'], $tenantId, $analystId,
            ];
            if ($reference !== null) { $cols .= ', client_reference'; $vals[] = $reference; }
            $conn->prepare("INSERT INTO documents ($cols) VALUES (" . implode(',', array_fill(0, count($vals), '?')) . ")")->execute($vals);
            $documentId = (int)$conn->lastInsertId();
            // documentInsertLink() also sets the Help Centre flag for an article (#177).
            documentInsertLink($conn, $documentId, $parentType, $parentId, $analystId);
            $conn->commit();
        } catch (Throwable $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            // The row failed, so the file on disk is now an orphan nothing points at.
            if ($stored && is_file($stored['path'])) @unlink($stored['path']);
            throw $e;
        }

        // 5. Index what we have now (findable by title at once), queue the text.
        try {
            require_once __DIR__ . '/../search/documents_index.php';
            documentTextEnqueue($conn, $documentId, $doc['kind'], $doc['original_name']);
            searchIndexDocument($conn, $documentId);
            // Opportunistic: a little extraction now, bounded, so a quiet install
            // with no cron still ends up searchable.
            documentTextDrain($conn, 1, microtime(true) + 4);
        } catch (Throwable $e) {
            error_log('[DocumentsService::add] indexing: ' . $e->getMessage());   // never fail the upload
        }
        return ['id' => $documentId, 'replayed' => false];
    }

    /**
     * Attach a document that already exists to another record. Attaching twice is a no-op.
     *
     * @throws ServiceError forbidden (the record), not_found (the document - the same for "no such" and "not yours")
     */
    public static function attach(PDO $conn, int $analystId, ?array $allowed, string $parentType, int $parentId, int $documentId): void
    {
        if ($documentId <= 0 || !documentEntityDef($parentType) || $parentId <= 0) {
            throw new ServiceError('validation', 'invalid_field', 'A document_id, parent_type and parent_id are required.');
        }
        // documentCanWriteParent: seeing the record is not enough - Read only access to its
        // module (Change Management, Forms, Tasks) attaches and removes nothing (#181).
        if (!documentCanWriteParent($conn, $analystId, $allowed, $parentType, $parentId)) {
            throw new ServiceError('forbidden', 'forbidden', 'You do not have access to that record.');
        }
        // The document itself - so attaching cannot launder a document into somewhere you can read.
        if (!documentCanView($conn, $analystId, $allowed, $documentId)) {
            throw new ServiceError('not_found', 'not_found', 'Not found.');
        }
        documentInsertLink($conn, $documentId, $parentType, $parentId, $analystId, true);
        // The corpus row's VISIBILITY just changed - resolved live by the search
        // clause - but reindex to keep source_datetime and title honest.
        try {
            require_once __DIR__ . '/../search/documents_index.php';
            searchIndexDocument($conn, $documentId);
        } catch (Throwable $e) { error_log('[DocumentsService::attach] ' . $e->getMessage()); }
    }

    /**
     * Take a document off a record. Its last link soft-deletes it (and removes the
     * stored file when no other document shares it).
     *
     * @return bool true = that was its last link, so the document is gone
     * @throws ServiceError forbidden (the record), not_found (the document)
     */
    public static function detach(PDO $conn, int $analystId, ?array $allowed, string $parentType, int $parentId, int $documentId): bool
    {
        if ($documentId <= 0 || !documentEntityDef($parentType) || $parentId <= 0) {
            throw new ServiceError('validation', 'invalid_field', 'A document_id, parent_type and parent_id are required.');
        }
        if (!documentCanWriteParent($conn, $analystId, $allowed, $parentType, $parentId)) {
            throw new ServiceError('forbidden', 'forbidden', 'You do not have access to that record.');
        }
        // ...and the document itself, so a guessed id cannot strip a document off a record.
        if (!documentCanView($conn, $analystId, $allowed, $documentId)) {
            throw new ServiceError('not_found', 'not_found', 'Not found.');
        }

        $conn->beginTransaction();
        try {
            $conn->prepare("DELETE FROM document_links WHERE document_id = ? AND parent_type = ? AND parent_id = ?")
                 ->execute([$documentId, $parentType, $parentId]);
            $st = $conn->prepare("SELECT COUNT(*) FROM document_links WHERE document_id = ?");
            $st->execute([$documentId]);
            $orphaned = (int)$st->fetchColumn() === 0;
            $keyToRemove = null;
            if ($orphaned) {
                $st = $conn->prepare("SELECT storage_key FROM documents WHERE id = ?");
                $st->execute([$documentId]);
                $keyToRemove = $st->fetchColumn() ?: null;
                $conn->prepare("UPDATE documents SET deleted_datetime = UTC_TIMESTAMP() WHERE id = ?")->execute([$documentId]);
            }
            $conn->commit();
        } catch (Throwable $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            throw $e;
        }

        // Only touch the disk AFTER the transaction committed. A file deleted inside a
        // transaction that then rolls back leaves a row pointing at nothing.
        if ($orphaned && $keyToRemove) {
            // Another document row may share the same stored file - check before unlinking.
            $st = $conn->prepare("SELECT COUNT(*) FROM documents WHERE storage_key = ? AND deleted_datetime IS NULL");
            $st->execute([$keyToRemove]);
            if ((int)$st->fetchColumn() === 0) {
                $path = documentStoragePath((string)$keyToRemove);
                if (is_file($path)) @unlink($path);
            }
        }
        // An orphan is soft-deleted, so it must leave the index too.
        if ($orphaned) {
            try {
                require_once __DIR__ . '/../search/documents_index.php';
                searchUnindexDocument($conn, $documentId);
            } catch (Throwable $e) { error_log('[DocumentsService::detach] ' . $e->getMessage()); }
        }
        return $orphaned;
    }

    /**
     * One record's documents, newest first - metadata only.
     *
     * @param ?string $reference  only documents this analyst uploaded with this client_reference
     * @return array{total:int, rows:array}
     * @throws ServiceError forbidden when the analyst cannot see the record
     */
    public static function listFor(PDO $conn, int $analystId, ?array $allowed, string $parentType, int $parentId, int $offset = 0, int $limit = 50, ?string $reference = null): array
    {
        if (!documentCanViewParent($conn, $analystId, $allowed, $parentType, $parentId)) {
            throw new ServiceError('forbidden', 'forbidden', 'You do not have access to that record.');
        }
        $where = "dl.parent_type = ? AND dl.parent_id = ? AND d.deleted_datetime IS NULL";
        $args  = [$parentType, $parentId];
        if ($reference !== null && $reference !== '') {
            if (!self::referenceReady($conn)) return ['total' => 0, 'rows' => []];
            $where .= " AND d.uploaded_by_id = ? AND d.client_reference = ?";
            array_push($args, $analystId, $reference);
        }
        $c = $conn->prepare("SELECT COUNT(*) FROM document_links dl JOIN documents d ON d.id = dl.document_id WHERE $where");
        $c->execute($args);
        $total = (int)$c->fetchColumn();
        $refCol = self::referenceReady($conn) ? 'd.client_reference' : 'NULL AS client_reference';
        $st = $conn->prepare(
            "SELECT d.id, d.kind, d.title, d.description, d.original_name, d.mime_type, d.size_bytes, d.content_hash,
                    d.external_url, d.created_datetime, d.uploaded_by_id, a.full_name AS uploaded_by_name, $refCol,
                    " . (self::filesLinkReady($conn) ? 'd.files_item_id' : 'NULL AS files_item_id') . "
               FROM document_links dl
               JOIN documents d ON d.id = dl.document_id
          LEFT JOIN analysts a ON a.id = d.uploaded_by_id
              WHERE $where
           ORDER BY d.created_datetime DESC, d.id DESC
              LIMIT " . max(1, min(200, $limit)) . " OFFSET " . max(0, $offset));
        $st->execute($args);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        // GH #303: a file in Files - its name and a way in only for someone who can open
        // its folder (Files' "hidden means not found"); for anyone else, that one exists.
        foreach ($rows as &$r) {
            if ($r['kind'] !== DOCUMENT_KIND_FILES) continue;
            require_once __DIR__ . '/files_attach.php';
            $v = FilesAttach::viewFor($conn, $analystId, (int)$r['files_item_id']);
            $r['files_access'] = $v['access'];
            $r['files_gone']   = $v['gone'];
            $r['open_url']     = $v['open_url'];
            if (!$v['access']) { $r['title'] = ''; $r['description'] = null; }
            unset($r['files_item_id']);
        }
        unset($r);
        return ['total' => $total, 'rows' => $rows];
    }

    /** True once Database Verification has added documents.files_item_id (GH #303). */
    public static function filesLinkReady(PDO $conn): bool
    {
        static $ready = null;
        if ($ready === null) {
            try { $ready = (bool)$conn->query("SHOW COLUMNS FROM documents LIKE 'files_item_id'")->fetch(); } catch (Throwable) { $ready = false; }
        }
        return $ready;
    }

    /** Is the document on this record? (A document is only ever served through its record.) */
    public static function isOn(PDO $conn, int $documentId, string $parentType, int $parentId): bool
    {
        $st = $conn->prepare("SELECT 1 FROM document_links dl JOIN documents d ON d.id = dl.document_id AND d.deleted_datetime IS NULL
                               WHERE dl.document_id = ? AND dl.parent_type = ? AND dl.parent_id = ? LIMIT 1");
        $st->execute([$documentId, $parentType, $parentId]);
        return (bool)$st->fetchColumn();
    }

    /** documents.client_reference exists (Database Verification has run since 5.1.0). */
    public static function referenceReady(PDO $conn): bool
    {
        static $ok = null;
        if ($ok !== null) return $ok;
        try { $conn->query("SELECT client_reference FROM documents LIMIT 0"); return $ok = true; } catch (Throwable) { return $ok = false; }
    }
}
