<?php
/**
 * Getting attached documents into search (discussion #76).
 *
 * 🔑 WHY THIS IS NOT PART OF THE ATTACHMENT QUEUE. Email attachments already have
 * an extraction pipeline, and it looks reusable until you read what it does with
 * the result: it walks attachment → email → TICKET and re-indexes the whole
 * ticket, because an attachment's text belongs to the ticket's corpus row. An
 * attached document has no ticket. It is its own row, whose visibility comes from
 * whatever it is attached to.
 *
 * So the two pipelines share the part that is genuinely the same — the extractor,
 * attTextExtractFile(), which takes a path and gives back text — and keep their
 * own queues, because "what do I do once I have the text" is a different answer.
 * A single table would also have needed a composite primary key, since a document
 * id and an email-attachment id are both small integers from different tables.
 *
 * ⚠️ A LINK CANNOT BE INDEXED. There is no document, only a URL. A linked
 * document is findable by its title and description and nothing else, which
 * quietly makes the description field load-bearing — worth saying in the UI
 * rather than labelling it "optional".
 *
 * ⚠️ CONFIGURED IS NOT AVAILABLE. A file whose extractor is unreachable stays
 * `pending` and is retried; it never becomes `failed`. That distinction is the
 * whole reason a stuck queue is visible instead of looking like an empty one.
 */

require_once __DIR__ . '/corpus.php';
require_once __DIR__ . '/extract.php';
require_once __DIR__ . '/tika.php';
require_once __DIR__ . '/../documents.php';

/** Its own corpus source type: a document belongs to no ticket. */
const SEARCH_SOURCE_DOCUMENT = 'document';

/**
 * Can ANYTHING read this file — either tier?
 *
 * ⚠️ attTextSupports() is TIER 1 ONLY: plain text and OOXML. PDFs, images and
 * scans need tier 2 (Tika). Using the tier-1 test alone marked every PDF
 * `unsupported` at upload, which reads as "we looked and there is nothing to
 * read" — permanently — when the truth was "we never asked the thing that could".
 *
 * @param string $filename  the file's name - only its extension is judged
 */
function documentTextReadable(PDO $conn, string $filename): bool
{
    return attTextSupports($filename)
        || (tikaConfigured($conn) && tikaHandles($filename));
}

/**
 * Queue a document for text extraction.
 *
 * Called after an upload. A link, or a file type nothing can read, is recorded
 * with a terminal status rather than left out — "we looked and there is nothing
 * to read" and "we have not looked yet" must not be the same row.
 *
 * @param int $documentId  the documents id
 * @param string $kind  the document kind: 'file' or 'link' (DOCUMENT_KIND_*); a link is marked unsupported
 * @param ?string $filename  the file's original name; null when there is none
 */
function documentTextEnqueue(PDO $conn, int $documentId, string $kind, ?string $filename): void
{
    $status = ATT_TEXT_PENDING;
    if ($kind === DOCUMENT_KIND_LINK || $kind === DOCUMENT_KIND_FILES) {
        $status = ATT_TEXT_UNSUPPORTED;              // a URL, or a file whose bytes live in Files
    } elseif ($filename !== null && !documentTextReadable($conn, $filename)) {
        $status = ATT_TEXT_UNSUPPORTED;
    }

    try {
        $conn->prepare(
            "INSERT INTO document_text (document_id, status, extracted_datetime)
             VALUES (?, ?, UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE status = VALUES(status), extracted_datetime = UTC_TIMESTAMP()"
        )->execute([$documentId, $status]);
    } catch (Throwable $e) {
        // Never fail an upload because the search queue would not take it. The
        // document exists; the worst case is that it is not searchable yet.
        error_log('[documentTextEnqueue] ' . $e->getMessage());
    }
}

/** How many documents are waiting for their text. Drives the "indexing" state. */
function documentTextQueueDepth(PDO $conn): int
{
    try {
        $st = $conn->prepare("SELECT COUNT(*) FROM document_text WHERE status = ?");
        $st->execute([ATT_TEXT_PENDING]);
        return (int) $st->fetchColumn();
    } catch (Throwable) {
        return 0;
    }
}

/**
 * Put one document into the search corpus.
 *
 * The row carries title + description + whatever text was extracted. It carries
 * NO permission facts beyond the tenant, deliberately: who may see a document
 * depends on what it is attached to, which changes after indexing. That question
 * is answered at query time — see documentSearchVisibilityClause().
 *
 * @param int $documentId  the documents id
 */
function searchIndexDocument(PDO $conn, int $documentId): void
{
    if (!searchCorpusReady($conn)) return;

    $st = $conn->prepare(
        "SELECT d.id, d.kind, d.title, d.description, d.original_name, d.external_url,
                d.tenant_id, d.created_datetime, d.deleted_datetime,
                t.extracted_text
           FROM documents d
      LEFT JOIN document_text t ON t.document_id = d.id
          WHERE d.id = ?"
    );
    $st->execute([$documentId]);
    $d = $st->fetch(PDO::FETCH_ASSOC);

    // Gone, or soft-deleted as an orphan: it must leave the index too, or a
    // deleted document goes on being findable by its title.
    // GH #303: a link to a file in Files is never indexed - global search would show its
    // name to people who cannot open its folder. Files' own search finds it for those who can.
    if (!$d || $d['deleted_datetime'] !== null || $d['kind'] === DOCUMENT_KIND_FILES) {
        searchCorpusDelete($conn, SEARCH_SOURCE_DOCUMENT, $documentId);
        return;
    }

    $body = trim(implode("\n", array_filter([
        (string) ($d['description'] ?? ''),
        (string) ($d['original_name'] ?? ''),
        (string) ($d['external_url'] ?? ''),
        (string) ($d['extracted_text'] ?? ''),
    ])));

    list($tenantId, $scope) = searchCorpusTicketScope($d['tenant_id'] !== null ? (int) $d['tenant_id'] : null);

    searchCorpusUpsert($conn, [
        'source_type'     => SEARCH_SOURCE_DOCUMENT,
        'source_id'       => $documentId,
        'ticket_id'       => null,
        'tenant_id'       => $tenantId,
        'tenant_scope'    => $scope,
        'is_internal'     => 0,
        'title'           => (string) $d['title'],
        'body'            => $body,
        'source_datetime' => $d['created_datetime'],
    ]);
}

/**
 * Drop a document from the index — used when its last link goes.
 *
 * @param int $documentId  the documents id
 */
function searchUnindexDocument(PDO $conn, int $documentId): void
{
    try { searchCorpusDelete($conn, SEARCH_SOURCE_DOCUMENT, $documentId); }
    catch (Throwable $e) { error_log('[searchUnindexDocument] ' . $e->getMessage()); }
}

/**
 * Extract text for up to $limit queued documents, then index each one.
 *
 * Mirrors the attachment queue's discipline: claim rows before working on them
 * so two workers cannot collide, return abandoned claims to the queue, and stop
 * the moment the extractor looks unreachable rather than grinding through a
 * batch collecting timeouts.
 *
 * @param int $limit  the most queued documents to read this run
 * @param float $deadline  a microtime(true) moment to stop by; 0 means no time limit
 */
function documentTextDrain(PDO $conn, int $limit = 5, float $deadline = 0): array
{
    $out = ['done' => 0, 'still_pending' => 0, 'skipped_reason' => null];

    try {
        // Abandoned claims first — a worker that died mid-file leaves rows in
        // `extracting` that nothing would ever look at again.
        $conn->prepare(
            "UPDATE document_text SET status = ?
              WHERE status = ? AND extracted_datetime < DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? MINUTE)"
        )->execute([ATT_TEXT_PENDING, ATT_TEXT_EXTRACTING, ATT_TEXT_CLAIM_STALE_MINUTES]);

        $sel = $conn->prepare(
            "SELECT t.document_id, d.storage_key, d.original_name
               FROM document_text t
               JOIN documents d ON d.id = t.document_id
              WHERE t.status = ? AND d.deleted_datetime IS NULL AND d.storage_key IS NOT NULL
           ORDER BY t.extracted_datetime ASC
              LIMIT " . max(1, (int) $limit)
        );
        $sel->execute([ATT_TEXT_PENDING]);
        $rows = $sel->fetchAll(PDO::FETCH_ASSOC);

        if (!$rows) { $out['still_pending'] = documentTextQueueDepth($conn); return $out; }

        foreach ($rows as $r) {
            if ($deadline > 0 && microtime(true) >= $deadline) {
                $out['skipped_reason'] = 'time budget reached';
                break;
            }
            $id = (int) $r['document_id'];

            // Claim it, and only proceed if WE were the one who claimed it.
            $claim = $conn->prepare("UPDATE document_text SET status = ?, extracted_datetime = UTC_TIMESTAMP()
                                      WHERE document_id = ? AND status = ?");
            $claim->execute([ATT_TEXT_EXTRACTING, $id, ATT_TEXT_PENDING]);
            if ($claim->rowCount() === 0) continue;

            $path     = documentStoragePath((string) $r['storage_key']);
            $filename = (string) ($r['original_name'] ?: $r['storage_key']);

            // ⚠️ CONTAINMENT, as the ticket indexer does it. The storage key is
            // ours today, but a row written by anyone with a foothold in the
            // database must not be able to make this read config.php — and, with
            // tier 2, POST it to an extraction service. realpath() both sides,
            // because comparing a resolved path to an unresolved prefix fails on
            // Windows.
            $realBase = realpath(dirname(__DIR__, 2) . '/uploads/' . DOCUMENT_STORAGE_DIR);
            $realFile = realpath($path);
            if ($realBase === false || $realFile === false
                || strncmp($realFile, $realBase . DIRECTORY_SEPARATOR, strlen($realBase) + 1) !== 0) {
                error_log('[documentTextDrain] refused a path outside the documents directory — document ' . $id);
                $status = ATT_TEXT_FAILED; $text = ''; $extractor = 'builtin';
            } elseif (attTextSupports($filename)) {
                $res = attTextExtractFile($realFile, $filename);           // tier 1
                $status = $res['status']; $text = $res['text']; $extractor = 'builtin';
            } else {
                // ── Tier 2 ──────────────────────────────────────────────────
                // ⚠️ `pending` and `failed` are NOT interchangeable. `pending`
                // means we still owe this file; `failed` means we asked and were
                // answered. Writing `failed` while the service is merely down
                // would blacklist every PDF that arrived during the outage,
                // permanently and silently.
                $res = tikaExtract($conn, $realFile, $filename);
                $extractor = 'tika';
                if ($res['ok']) {
                    $text = $res['text']; $status = ATT_TEXT_EXTRACTED;
                } elseif ($res['retry']) {
                    $text = ''; $status = ATT_TEXT_PENDING;
                    error_log('[tika] deferring document ' . $id . ': ' . $res['error']);
                } else {
                    $text = ''; $status = ATT_TEXT_FAILED;
                    error_log('[tika] could not read document ' . $id . ': ' . $res['error']);
                }
            }

            $conn->prepare(
                "UPDATE document_text
                    SET status = ?, extractor = ?, extracted_text = ?, chars = ?, extracted_datetime = UTC_TIMESTAMP()
                  WHERE document_id = ?"
            )->execute([$status, $extractor, $text, mb_strlen((string) $text), $id]);

            searchIndexDocument($conn, $id);
            $out['done']++;
        }

        $out['still_pending'] = documentTextQueueDepth($conn);
    } catch (Throwable $e) {
        error_log('[documentTextDrain] ' . $e->getMessage());
        $out['skipped_reason'] = $e->getMessage();
    }

    return $out;
}

/**
 * The SQL that stops search returning a document you may not see.
 *
 * ⚠️ THIS IS THE HALF THAT MAKES THE FEATURE SAFE. Corpus rows carry a tenant and
 * an internal flag, which is enough for tickets. It is not enough for a document,
 * whose visibility depends on records in other tables entirely — so a document
 * row must additionally satisfy the same at-least-one-visible-parent rule the
 * download endpoint applies.
 *
 * Expressed as "not a document, OR a visible document", so it can be ANDed onto
 * the existing search WHERE without disturbing any other source type.
 *
 * @param ?array $allowedModules  the analyst's allowed module keys ($_SESSION['allowed_modules']); null means all
 * @param string $alias  the alias the search query gives the search_documents table
 * @return array [sqlFragment, params]
 */
function documentSearchVisibilityClause(PDO $conn, int $analystId, ?array $allowedModules, string $alias = 'sd'): array
{
    list($vis, $params) = documentVisibilityClause($conn, $analystId, $allowedModules, '__d');

    // documentVisibilityClause() returns a fragment starting " AND ("; here it has
    // to stand alone inside an EXISTS, so the leading AND is trimmed.
    $vis = preg_replace('/^\s*AND\s+/i', '', trim($vis));

    $sql = " AND ($alias.source_type <> ? OR EXISTS ("
         . "SELECT 1 FROM documents __d WHERE __d.id = $alias.source_id"
         . " AND __d.deleted_datetime IS NULL AND (" . $vis . ")))";

    return [$sql, array_merge([SEARCH_SOURCE_DOCUMENT], $params)];
}
