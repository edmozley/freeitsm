<?php
/**
 * Keep the search corpus current as tickets happen.
 *
 * Until this existed, `scripts/search_backfill.php` was the ONLY thing that ever
 * wrote to the corpus, and somebody had to remember to run it. A ticket raised
 * this morning was invisible to search until then. That is worse than having no
 * search: people trust it, find nothing, and conclude the thing never happened.
 *
 * HOW IT HOOKS IN
 * ---------------
 * It subscribes to `WorkflowEngine::dispatch`, the same seam the notification
 * bell uses, rather than adding calls at every place a ticket changes. See
 * workflow/includes/engine.php — subscribers run in their own try/catch, before
 * the workflow loop, so none of them can break the others or the host request.
 *
 * ⚠️ WHOLE TICKETS, NOT SINGLE ROWS
 * Every event reindexes the ticket entire: its subject, all its messages, all
 * its notes. That looks wasteful and is deliberate.
 *
 *  - It is ORDERING-IMMUNE. Some paths announce before the opening message is
 *    written, some after. Indexing "the row that just changed" would need every
 *    caller to fire at exactly the right moment; reindexing the ticket does not
 *    care when it is told.
 *  - It is SELF-HEALING. Any row a missed event would have left stale is
 *    rewritten by the next event on that ticket.
 *  - It is CHEAP. A ticket is a handful of rows, and every write is an upsert.
 *
 * ⚠️ It must be called AFTER the host's transaction commits. InnoDB does not
 * expose uncommitted rows to MATCH...AGAINST, and a rolled-back transaction
 * would otherwise leave corpus rows describing a ticket that never existed.
 *
 * The document shapes live here and `searchBackfillRun()` calls them, so a
 * live-indexed ticket and a backfilled one are byte-for-byte the same. If those
 * two ever disagreed, a result would depend on HOW a ticket came to be indexed,
 * which is close to undebuggable.
 */

require_once __DIR__ . '/corpus.php';

/** Default cap on how much of one body is indexed. Mirrors the backfill's. */
const SEARCH_INDEX_MAX_BODY = 200000;

/**
 * Index (or reindex) one ticket and everything on it.
 *
 * Safe to call for a ticket that does not exist, or one that is in the trash —
 * a deleted ticket's words are removed rather than left sitting in a searchable
 * table.
 *
 * @return array{tickets:int,emails:int,notes:int,attachments:int,skipped:int}
 */
function searchIndexTicket(PDO $conn, int $ticketId, int $maxBody = SEARCH_INDEX_MAX_BODY): array
{
    $counts = ['tickets' => 0, 'emails' => 0, 'notes' => 0, 'attachments' => 0, 'skipped' => 0];
    if ($ticketId <= 0) return $counts;

    $tStmt = $conn->prepare(
        "SELECT id, subject, tenant_id, created_datetime, deleted_datetime
           FROM tickets WHERE id = ?"
    );
    $tStmt->execute([$ticketId]);
    $t = $tStmt->fetch(PDO::FETCH_ASSOC);

    // Gone, or in the trash: drop whatever we had rather than keep it findable.
    if (!$t || $t['deleted_datetime'] !== null) {
        searchCorpusDeleteTicket($conn, $ticketId);
        return $counts;
    }

    [$tenantId, $scope] = searchCorpusTicketScope(
        $t['tenant_id'] === null ? null : (int)$t['tenant_id']
    );

    // 1. the subject, as its own row — so "matched the subject" can be stated to
    //    the user and weighted separately from body text
    searchCorpusUpsert($conn, [
        'source_type'     => SEARCH_SOURCE_TICKET,
        'source_id'       => $ticketId,
        'ticket_id'       => $ticketId,
        'tenant_id'       => $tenantId,
        'tenant_scope'    => $scope,
        'is_internal'     => 0,
        'title'           => (string)$t['subject'],
        'body'            => '',
        'source_datetime' => $t['created_datetime'],
    ]);
    $counts['tickets']++;

    // 2. every message on the ticket
    $eStmt = $conn->prepare("SELECT id, subject, body_content, received_datetime
                               FROM emails WHERE ticket_id = ?");
    $eStmt->execute([$ticketId]);
    foreach ($eStmt->fetchAll(PDO::FETCH_ASSOC) as $e) {
        $body = searchCorpusPlainText((string)($e['body_content'] ?? ''), $maxBody);
        if ($body === '' && (string)($e['subject'] ?? '') === '') { $counts['skipped']++; continue; }
        searchCorpusUpsert($conn, [
            'source_type'     => SEARCH_SOURCE_EMAIL,
            'source_id'       => (int)$e['id'],
            'ticket_id'       => $ticketId,
            'tenant_id'       => $tenantId,
            'tenant_scope'    => $scope,
            'is_internal'     => 0,
            'title'           => (string)($e['subject'] ?? ''),
            'body'            => $body,
            'source_datetime' => $e['received_datetime'],
        ]);
        $counts['emails']++;
    }

    // 3. every note. is_internal is carried as a FACT, so the search predicate
    //    can exclude them rather than the caller filtering after.
    $nStmt = $conn->prepare("SELECT id, note_text, is_internal, created_datetime
                               FROM ticket_notes WHERE ticket_id = ?");
    $nStmt->execute([$ticketId]);
    foreach ($nStmt->fetchAll(PDO::FETCH_ASSOC) as $n) {
        $body = searchCorpusPlainText((string)$n['note_text'], $maxBody);
        if ($body === '') { $counts['skipped']++; continue; }
        searchCorpusUpsert($conn, [
            'source_type'     => SEARCH_SOURCE_NOTE,
            'source_id'       => (int)$n['id'],
            'ticket_id'       => $ticketId,
            'tenant_id'       => $tenantId,
            'tenant_scope'    => $scope,
            // NULL defaults to internal in ticket_notes, so treat it as internal.
            'is_internal'     => ($n['is_internal'] === null ? 1 : (int)$n['is_internal']),
            'title'           => '',
            'body'            => $body,
            'source_datetime' => $n['created_datetime'],
        ]);
        $counts['notes']++;
    }

    // 4. text pulled out of attachments
    $counts['attachments'] = searchIndexTicketAttachments($conn, $ticketId, $tenantId, $scope, $maxBody);

    return $counts;
}

/**
 * Index the readable text of a ticket's attachments (discussion #53, tier 1).
 *
 * ⚠️ EXTRACTION IS CACHED IN `attachment_text`, WHICH IS THE POINT.
 * searchIndexTicket() reindexes the WHOLE ticket on every event, so without a
 * durable store a ticket with ten attachments would re-open and re-unzip all ten
 * every time somebody added a note. The row also survives a corpus rebuild, so
 * rebuilding the index never means re-reading a single file — which for tier 2's
 * PDFs and OCR would be hours of work and, with a paid extractor, a bill.
 *
 * @return int corpus rows written
 */
function searchIndexTicketAttachments(PDO $conn, int $ticketId, ?int $tenantId, string $scope, int $maxBody): int
{
    require_once __DIR__ . '/extract.php';
    require_once __DIR__ . '/tika.php';   // tier 2, when an extractor is configured

    // Inline images (the cid: ones in an HTML signature) are skipped: they are
    // never documents, and a signature logo on every reply would otherwise be
    // "extracted" hundreds of times to produce nothing.
    $stmt = $conn->prepare(
        "SELECT a.id, a.filename, a.file_path, a.file_size,
                t.status AS text_status, t.extracted_text
           FROM email_attachments a
           JOIN emails e ON e.id = a.email_id
      LEFT JOIN attachment_text t ON t.attachment_id = a.id
          WHERE e.ticket_id = ? AND (a.is_inline = 0 OR a.is_inline IS NULL)"
    );
    $stmt->execute([$ticketId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) return 0;

    // Same base directory the download endpoint serves from.
    $baseDir  = dirname(__DIR__, 2) . '/tickets/attachments';
    $realBase = realpath($baseDir);
    $written  = 0;

    foreach ($rows as $r) {
        $attId  = (int)$r['id'];
        $status = $r['text_status'];
        $text   = (string)($r['extracted_text'] ?? '');

        // Two statuses are NOT final, and both must be revisited or the queue can
        // never drain:
        //
        //   unsupported  the built-in tier could not read it — but an extraction
        //                service may have been configured since
        //   pending      it IS meant to be read; the service was unreachable at
        //                the time, or it has only just been queued
        //
        // ⚠️ Leaving `pending` out of this list is exactly the bug that makes a
        // queue look like it is working while clearing nothing: the drain
        // reindexes the ticket, the ticket declines to reconsider the row, and
        // the depth never moves.
        //   extracting   a worker has CLAIMED it and is about to read it — that
        //                worker is the caller here, so this is where the work
        //                actually happens. It must be in this list or a claimed
        //                row would be skipped and never processed at all.
        $reconsider = in_array($status, [ATT_TEXT_UNSUPPORTED, ATT_TEXT_PENDING, ATT_TEXT_EXTRACTING], true)
                      && tikaConfigured($conn)
                      && tikaHandles((string)$r['filename']);

        if ($status === null || $reconsider) {
            $filename  = (string)$r['filename'];
            $builtIn   = attTextSupports($filename);                              // tier 1
            $viaTika   = tikaConfigured($conn) && tikaHandles($filename);         // tier 2
            $extractor = 'builtin';

            if (!$builtIn && !$viaTika) {
                // Nothing here can read it. Recorded honestly, and revisited on
                // its own the moment an extraction service is configured.
                $status = ATT_TEXT_UNSUPPORTED;
                $text   = '';
            } else {
                // ⚠️ Containment, copied from api/tickets/get_attachment.php: the
                // resolved file must sit INSIDE the attachments directory. The
                // stored path is not attacker-supplied today, but a row written
                // before the upload rules existed — or by anyone with a foothold
                // in the database — must not be able to make the indexer read
                // config.php and put it in a searchable table. realpath() both
                // sides, because comparing a resolved path against an unresolved
                // prefix fails on Windows.
                //
                // It matters twice over now: without it, tier 2 would happily
                // POST the contents of that file to an external service.
                $full     = $baseDir . '/' . $r['file_path'];
                $realFile = realpath($full);
                if ($realBase === false || $realFile === false
                    || strncmp($realFile, $realBase . DIRECTORY_SEPARATOR, strlen($realBase) + 1) !== 0) {
                    error_log('[searchIndexTicketAttachments] refused a path outside the attachments '
                              . 'directory — attachment ' . $attId . ', stored path ' . (string)$r['file_path']);
                    $status = ATT_TEXT_FAILED;
                    $text   = '';
                } elseif ((int)$r['file_size'] > ATT_TEXT_MAX_FILE_BYTES) {
                    // The size gate applies to BOTH tiers. There is no point
                    // shipping 200 MB across the network to be told it is big.
                    $status = ATT_TEXT_TOO_LARGE;
                    $text   = '';
                } elseif ($builtIn) {
                    $res    = attTextExtractFile($realFile, $filename, $maxBody);
                    $status = $res['status'];
                    $text   = $res['text'];
                } else {
                    // ── Tier 2 ──────────────────────────────────────────────
                    // ⚠️ The three outcomes are NOT interchangeable. `pending`
                    // means "we still owe this file"; `failed` means "asked and
                    // answered". Writing `failed` when the service is merely
                    // down would blacklist every PDF that arrived during a
                    // five-minute outage, permanently and silently.
                    $res       = tikaExtract($conn, $realFile, $filename);
                    $extractor = 'tika';
                    if ($res['ok']) {
                        $text   = $res['text'];
                        $status = mb_strlen($text, 'UTF-8') > $maxBody ? ATT_TEXT_TRUNCATED : ATT_TEXT_EXTRACTED;
                        if ($status === ATT_TEXT_TRUNCATED) $text = mb_substr($text, 0, $maxBody, 'UTF-8');
                    } elseif ($res['retry']) {
                        $status = ATT_TEXT_PENDING;
                        $text   = '';
                        error_log('[tika] deferring attachment ' . $attId . ': ' . $res['error']);
                    } else {
                        $status = ATT_TEXT_FAILED;
                        $text   = '';
                        error_log('[tika] could not read attachment ' . $attId . ': ' . $res['error']);
                    }
                }
            }

            try {
                $ins = $conn->prepare(
                    "INSERT INTO attachment_text
                       (attachment_id, status, extractor, extracted_text, chars, extracted_datetime)
                     VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP())
                     ON DUPLICATE KEY UPDATE
                       status = VALUES(status), extractor = VALUES(extractor),
                       extracted_text = VALUES(extracted_text), chars = VALUES(chars),
                       extracted_datetime = UTC_TIMESTAMP()"
                );
                $ins->execute([$attId, $status, $extractor, $text, mb_strlen($text, 'UTF-8')]);
            } catch (Exception $e) {
                // No attachment_text table yet (Database Verification not run).
                // Indexing the rest of the ticket still succeeded.
                error_log('[searchIndexTicketAttachments] ' . $e->getMessage());
                return $written;
            }
        }

        // Only text we actually have goes in the corpus. unsupported / too_large
        // / failed are recorded above and surfaced in the UI, but there is
        // nothing to search.
        if ($text === '') continue;

        searchCorpusUpsert($conn, [
            'source_type'     => SEARCH_SOURCE_ATTACHMENT,
            'source_id'       => $attId,
            'ticket_id'       => $ticketId,
            'tenant_id'       => $tenantId,
            'tenant_scope'    => $scope,
            // An attachment is as visible as the ticket it hangs off. It is not a
            // note, so it is not internal-only.
            'is_internal'     => 0,
            'title'           => (string)$r['filename'],
            'body'            => $text,
            'source_datetime' => null,
        ]);
        $written++;
    }

    return $written;
}

/**
 * Index (or reindex) one knowledge article.
 *
 * ⚠️ WHY THIS IS CALLED DIRECTLY AND TICKETS ARE NOT
 * Tickets use the dispatch seam because three separate paths create them and
 * none of them shares code. Articles are the opposite: `KnowledgeService` is the
 * only thing that writes `knowledge_articles`, so calling from there is both
 * complete and obvious.
 *
 * The events would also be the wrong hook. A newly created DRAFT fires nothing
 * at all — `knowledge.published` is deliberately withheld so a workflow does not
 * announce a page nobody can open yet — but a draft still needs indexing,
 * because the palette deliberately shows analysts their own work in progress.
 * "The text changed" and "tell people about it" are different questions here.
 *
 * An archived or deleted article has its row removed rather than hidden: the
 * command palette excludes archived articles, so leaving them searchable would
 * disagree with the rest of the product.
 */
function searchIndexArticle(PDO $conn, int $articleId, int $maxBody = SEARCH_INDEX_MAX_BODY): bool
{
    if ($articleId <= 0) return false;

    // ⚠️ DELIBERATELY UNFILTERED, and it must stay that way. The indexer builds
    // rows the corpus filters at QUERY time (tenant_scope + is_internal below,
    // and the access list when it lands, via a clause built where the reader is
    // known — the same shape as documentSearchVisibilityClause). Filtering here
    // instead would bake one reader's permissions into a shared index and make
    // the article unfindable by everyone else.
    $stmt = $conn->prepare(
        "SELECT id, title, body, tenant_id, audience, is_archived, modified_datetime
           FROM knowledge_articles WHERE id = ?"
    );
    $stmt->execute([$articleId]);
    $a = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$a || !empty($a['is_archived'])) {
        searchCorpusDelete($conn, SEARCH_SOURCE_KB_ARTICLE, $articleId);
        return false;
    }

    // ⚠️ NULL tenant_id means the OPPOSITE of what it means on a ticket: an
    // article with no company is shared with EVERY company. That is the whole
    // reason searchCorpusArticleScope() exists as a separate function.
    [$tenantId, $scope] = searchCorpusArticleScope(
        $a['tenant_id'] === null ? null : (int)$a['tenant_id']
    );

    // Map the audience ladder onto is_internal, failing CLOSED: anything not
    // explicitly opened up to customers or the public is treated as internal, so
    // a future portal-facing search cannot leak an internal article by default.
    $audience   = (string)($a['audience'] ?? 'internal');
    $isInternal = ($audience === 'internal') ? 1 : 0;

    searchCorpusUpsert($conn, [
        'source_type'     => SEARCH_SOURCE_KB_ARTICLE,
        'source_id'       => (int)$a['id'],
        'ticket_id'       => null,          // an article hangs off no ticket
        'tenant_id'       => $tenantId,
        'tenant_scope'    => $scope,
        'is_internal'     => $isInternal,
        'title'           => (string)$a['title'],
        'body'            => searchCorpusPlainText((string)($a['body'] ?? ''), $maxBody),
        'source_datetime' => $a['modified_datetime'],
    ]);
    return true;
}

/**
 * The dispatch subscriber. Called for EVERY workflow event, so it decides
 * quickly whether it cares and gets out of the way.
 *
 * Never throws: a search index that cannot be updated must not cost anybody
 * their ticket, their note or their reply.
 */
function searchIndexHandleEvent(string $event, array $payload): void
{
    // Events that mean "this ticket's text may have changed". Deliberately a
    // short list: status and priority changes move no words about, so indexing
    // on them would be pure cost.
    static $INTERESTING = [
        'ticket.created'        => true,
        'ticket.note_added'     => true,
        'ticket.reply_received' => true,
        'ticket.subject_changed'=> true,
        'ticket.restored'       => true,
        'ticket.deleted'        => true,
    ];
    if (!isset($INTERESTING[$event])) return;

    try {
        $ticketId = 0;
        if (isset($payload['ticket']['id']))  $ticketId = (int)$payload['ticket']['id'];
        elseif (isset($payload['ticket_id'])) $ticketId = (int)$payload['ticket_id'];
        if ($ticketId <= 0) return;

        $conn = connectToDatabase();

        // An install that has never run Database Verification has no corpus.
        // That is a normal state, not a fault, so say nothing.
        if (!searchCorpusReady($conn)) return;

        // A delete is handled inside searchIndexTicket too (it re-reads the row
        // and finds deleted_datetime set), but calling it explicitly here means
        // the removal does not depend on the soft-delete having landed first.
        if ($event === 'ticket.deleted') {
            searchCorpusDeleteTicket($conn, $ticketId);
            return;
        }

        searchIndexTicket($conn, $ticketId);
    } catch (Throwable $e) {
        error_log('[searchIndexHandleEvent] ' . $event . ': ' . $e->getMessage());
    }
}
