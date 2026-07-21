<?php
/**
 * Merging tickets.
 *
 * WHAT A MERGE ACTUALLY IS
 * ------------------------
 * Two people reported the same printer. Or a monitoring system opened forty tickets
 * for one outage. Merging folds those conversations together so there is one place to
 * answer from — without destroying the references the requesters already hold.
 *
 * THE REFERENCE IS THE HARD PART
 * ------------------------------
 * Every notification FreeITSM has ever sent carries `[SDREF:ABC-452-98881]` in its
 * subject, and check_mailbox_email.php routes inbound replies by matching it. So a
 * merged-away ticket can never simply cease to exist: somewhere out there are emails
 * in a customer's inbox whose Reply button still points at it.
 *
 * That is why `tickets.merged_into_id` exists and why the source row is NEVER deleted.
 * The source stays, closed and flagged, and both the UI and the mail importer follow
 * the pointer. "Whatever happened to ABC?" is answerable forever, and a reply to a
 * two-year-old ABC notification still lands on the live ticket.
 *
 * TWO POLICIES, BOTH THE ADMIN'S CHOICE
 * -------------------------------------
 * Tickets → Settings → Merge behaviour, install-wide (not per-analyst: whether a
 * requester's reference survives is a promise to the CUSTOMER, and two analysts on
 * the same mailbox must not be able to disagree about it).
 *
 *   reference_mode  'survivor' — one existing ticket lives on and keeps its number.
 *                                Nothing the requester has seen becomes a dead ref.
 *                   'new'      — a brand-new ticket is minted and EVERY source folds
 *                                into it, including the one the analyst picked. All
 *                                the old refs become redirects.
 *
 *   originals_mode  'thread'      messages move onto the target, tagged with where
 *                                 they came from. Searchable, quotable, inline.
 *                   'thread_html' as above, PLUS a self-contained HTML snapshot of
 *                                 each source attached to the target.
 *                   'html'        the messages stay on their original tickets and the
 *                                 target gets only the snapshots. Tidiest target;
 *                                 the trade-off is that inbox search no longer finds
 *                                 the merged content, because search reads message
 *                                 bodies and not attachments.
 *
 * WHAT MOVES AND WHAT STAYS
 * -------------------------
 * Fourteen tables hang off a ticket, and the split is not arbitrary — the test is
 * "does this record describe the CONVERSATION, or does it describe what happened to
 * THIS TICKET?" The first kind moves; the second kind would be falsified by moving.
 *
 *   MOVE   emails (attachments follow by email_id), ticket_notes, ticket_time_entries,
 *          ticket_recordings, ticket_cmdb_objects, tasks, problem_tickets,
 *          change_tickets, form_submissions, webchat_conversations
 *
 *   STAY   ticket_audit          the source's own history. Moving it would rewrite
 *                                the past and make the source look like it never had one.
 *          sla_notifications_sent what the SLA engine did to THAT ticket, at the time.
 *          ticket_csat_responses  a survey somebody answered ABOUT that ticket.
 *          mailbox_activity_log   an append-only log; logs are not editable records.
 */

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/services/tickets.php';

/** Tables whose rows describe the conversation and therefore follow it. */
const MERGE_MOVE_TABLES = [
    'ticket_notes', 'ticket_time_entries', 'ticket_recordings',
    'tasks', 'form_submissions', 'webchat_conversations',
];

/** Move tables where a duplicate row on the target must not be created. */
const MERGE_MOVE_DEDUPE = [
    'ticket_cmdb_objects' => 'object_id',
    'problem_tickets'     => 'problem_id',
    'change_tickets'      => 'change_id',
];

/**
 * Read the install's merge policy. Defaults are the conservative pair: keep the
 * requester's reference alive, and keep the conversation searchable.
 */
function mergeSettings(PDO $conn): array {
    $out = [
        'reference_mode' => 'survivor',
        'originals_mode' => 'thread',
        'ai_summary'     => '1',
    ];
    try {
        $stmt = $conn->query(
            "SELECT setting_key, setting_value FROM system_settings
              WHERE setting_key IN ('merge_reference_mode','merge_originals_mode','merge_ai_summary')"
        );
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $k = str_replace('merge_', '', $row['setting_key']);
            if ($row['setting_value'] !== null && $row['setting_value'] !== '') {
                $out[$k] = $row['setting_value'];
            }
        }
    } catch (Exception $e) { /* defaults stand */ }

    if (!in_array($out['reference_mode'], ['survivor', 'new'], true))               $out['reference_mode'] = 'survivor';
    if (!in_array($out['originals_mode'], ['thread', 'thread_html', 'html'], true)) $out['originals_mode'] = 'thread';
    return $out;
}

/**
 * Follow the merge chain to the ticket that is actually live.
 *
 * A ticket merged into a ticket that was later merged again must resolve all the way
 * through, or an old email reply would land on a ticket that is itself closed and
 * redirected. Bounded to 10 hops so a cycle — which should be impossible, but a
 * database is not a proof — cannot hang a mailbox poller.
 *
 * @return int the id of the live ticket (the input id if it was never merged)
 */
function resolveMergedTicket(PDO $conn, int $ticketId): int {
    $seen = [];
    for ($i = 0; $i < 10; $i++) {
        if (isset($seen[$ticketId])) break;      // cycle: stop where we are
        $seen[$ticketId] = true;
        try {
            $stmt = $conn->prepare("SELECT merged_into_id FROM tickets WHERE id = ?");
            $stmt->execute([$ticketId]);
            $next = $stmt->fetchColumn();
        } catch (Exception $e) {
            return $ticketId;                    // column not there yet (pre-upgrade)
        }
        if ($next === false || $next === null) return $ticketId;
        $ticketId = (int)$next;
    }
    return $ticketId;
}

/** Is this ticket a merged-away one? Returns the target row, or null. */
function mergedAwayInfo(PDO $conn, int $ticketId): ?array {
    try {
        $stmt = $conn->prepare(
            "SELECT t.merged_into_id, tgt.ticket_number, tgt.subject
               FROM tickets t
               LEFT JOIN tickets tgt ON tgt.id = t.merged_into_id
              WHERE t.id = ? AND t.merged_into_id IS NOT NULL"
        );
        $stmt->execute([$ticketId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (Exception $e) {
        return null;
    }
}

/**
 * Merge one or more source tickets into a target.
 *
 * @param int[] $sourceIds tickets to fold away
 * @param int   $targetId  the ticket the analyst chose to keep
 * @return array {target_id, target_number, merged: int[], created_new: bool}
 * @throws Exception on anything that would leave a half-merge
 */
function mergeTickets(PDO $conn, int $actorId, array $sourceIds, int $targetId): array {
    $settings = mergeSettings($conn);

    $sourceIds = array_values(array_unique(array_map('intval', $sourceIds)));
    $sourceIds = array_filter($sourceIds, fn($id) => $id > 0 && $id !== $targetId);
    if (!$sourceIds) {
        throw new Exception('Nothing to merge');
    }

    $ctx = ActorContext::fromSession($conn);

    // Access check FIRST, for every ticket involved, before a single row moves. A
    // merge is a multi-ticket write and a half-done one is far worse than a refused
    // one — loadTicket() throws for anything unknown or out of this analyst's scope.
    $all = array_merge($sourceIds, [$targetId]);
    $tickets = [];
    foreach ($all as $id) {
        try {
            $tickets[$id] = TicketsService::loadTicket($conn, $ctx, $id);
        } catch (Throwable $e) {
            // The service says a bare "Ticket not found." which, in a multi-ticket
            // action, tells the analyst nothing about WHICH one — and it means two
            // different things (no such id, or out of your company scope). Name the id
            // so a failed merge is diagnosable from the toast alone.
            throw new Exception('Could not load ticket #' . $id . ' (' . $e->getMessage() . ')');
        }
        if ($tickets[$id]['deleted_datetime'] !== null) {
            throw new Exception('Ticket ' . $tickets[$id]['ticket_number'] . ' is in the trash — restore it first');
        }
        if (!empty($tickets[$id]['merged_into_id'])) {
            throw new Exception('Ticket ' . $tickets[$id]['ticket_number'] . ' has already been merged');
        }
    }

    // Same-company only. Folding a client's conversation into another client's ticket
    // would be a data leak dressed up as an action, and it must be refused for an
    // all-access analyst too — a same-company invariant binds every actor.
    //
    // ⚠️ `tickets.tenant_id` NULL does NOT mean "no company": for this table it means
    // the DEFAULT company (an unrouted ticket). Comparing the raw values rejected a
    // perfectly ordinary merge between an older NULL ticket and a Default-company one
    // as "different companies" — which is most merges on an install that predates
    // multi-tenancy. Normalise both sides through the default id before comparing.
    // (This is the scoped-data meaning of NULL; config lists like ticket_types use
    // the opposite convention. Always check which shape the table is.)
    $normaliseTenant = function ($raw) use ($conn) {
        if ($raw === null || $raw === '') return getDefaultTenantId($conn);
        return (int)$raw;
    };
    $targetTenant = $normaliseTenant($tickets[$targetId]['tenant_id'] ?? null);
    foreach ($sourceIds as $sid) {
        if ($normaliseTenant($tickets[$sid]['tenant_id'] ?? null) !== $targetTenant) {
            throw new Exception(
                'Ticket ' . ($tickets[$sid]['ticket_number'] ?? $sid) . ' belongs to a different company from '
                . ($tickets[$targetId]['ticket_number'] ?? $targetId) . ' — tickets can only be merged within one company'
            );
        }
    }

    $conn->beginTransaction();
    try {
        $createdNew = false;
        $realTarget = $targetId;

        // 'new' mode: mint a fresh ticket and fold EVERYTHING into it, including the
        // ticket the analyst nominated. The nominated one supplies the new ticket's
        // properties so nothing is lost, but it is no more "the survivor" than any
        // other source — that is the whole point of this mode.
        if ($settings['reference_mode'] === 'new') {
            $realTarget = mergeCreateTargetTicket($conn, $tickets[$targetId], $actorId);
            $createdNew = true;
            $sourceIds[] = $targetId;
            $tickets[$realTarget] = TicketsService::loadTicket($conn, $ctx, $realTarget);
        }

        $merged = [];
        foreach ($sourceIds as $sid) {
            mergeOneTicket($conn, $actorId, $sid, $realTarget, $tickets[$sid], $settings);
            $merged[] = $sid;
        }

        $conn->commit();
        return [
            'target_id'     => $realTarget,
            'target_number' => $tickets[$realTarget]['ticket_number'] ?? '',
            'merged'        => $merged,
            'created_new'   => $createdNew,
            'settings'      => $settings,
        ];
    } catch (Exception $e) {
        $conn->rollBack();
        throw $e;
    }
}

/** Create the brand-new ticket that everything folds into ('new' reference mode). */
function mergeCreateTargetTicket(PDO $conn, array $model, int $actorId): int {
    // Reuse the service's own generator via a plain insert: the service's createTicket
    // would also raise an initial email row and fire workflow triggers, neither of
    // which belongs to a merge.
    for ($attempt = 0; $attempt < 10; $attempt++) {
        $letters = chr(rand(65, 90)) . chr(rand(65, 90)) . chr(rand(65, 90));
        $number  = $letters . '-' . rand(100, 999) . '-' . str_pad((string)rand(0, 99999), 5, '0', STR_PAD_LEFT);
        $check = $conn->prepare("SELECT COUNT(*) FROM tickets WHERE ticket_number = ?");
        $check->execute([$number]);
        if (!(int)$check->fetchColumn()) break;
        $number = null;
    }
    if (empty($number)) throw new Exception('Failed to generate a ticket number');

    $stmt = $conn->prepare(
        "INSERT INTO tickets
            (ticket_number, subject, status_id, priority_id, department_id, ticket_type_id,
             assigned_analyst_id, origin_id, user_id, owner_id, tenant_id, created_datetime, updated_datetime)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())"
    );
    $stmt->execute([
        $number,
        $model['subject'] ?? 'Merged ticket',
        $model['status_id'] ?? null,
        $model['priority_id'] ?? null,
        $model['department_id'] ?? null,
        $model['ticket_type_id'] ?? null,
        $model['assigned_analyst_id'] ?? null,
        $model['origin_id'] ?? null,
        $model['user_id'] ?? null,
        $model['owner_id'] ?? null,
        $model['tenant_id'] ?? null,
    ]);
    $newId = (int)$conn->lastInsertId();

    mergeAudit($conn, $newId, $actorId, 'Ticket Created', null, 'Created by merging tickets');
    return $newId;
}

/** Fold a single source into the target. Assumes the caller holds a transaction. */
function mergeOneTicket(PDO $conn, int $actorId, int $sourceId, int $targetId, array $source, array $settings): void {
    $moveMessages = ($settings['originals_mode'] !== 'html');
    $makeSnapshot = ($settings['originals_mode'] !== 'thread');

    // The HTML snapshot is built BEFORE anything moves — once the messages are on the
    // target there is no longer a "source conversation" to render.
    $snapshotHtml = $makeSnapshot ? mergeBuildSnapshotHtml($conn, $sourceId, $source) : null;

    if ($moveMessages) {
        $stmt = $conn->prepare("UPDATE emails SET ticket_id = ? WHERE ticket_id = ?");
        $stmt->execute([$targetId, $sourceId]);
    }

    foreach (MERGE_MOVE_TABLES as $table) {
        try {
            $conn->prepare("UPDATE `$table` SET ticket_id = ? WHERE ticket_id = ?")->execute([$targetId, $sourceId]);
        } catch (Exception $e) { /* table absent on a part-upgraded install */ }
    }

    // Dedupe-on-move: linking the same CMDB object or problem twice would show the
    // analyst a duplicate row for no reason.
    foreach (MERGE_MOVE_DEDUPE as $table => $col) {
        try {
            $conn->prepare(
                "DELETE FROM `$table`
                  WHERE ticket_id = ?
                    AND `$col` IN (SELECT * FROM (SELECT `$col` FROM `$table` WHERE ticket_id = ?) x)"
            )->execute([$sourceId, $targetId]);
            $conn->prepare("UPDATE `$table` SET ticket_id = ? WHERE ticket_id = ?")->execute([$targetId, $sourceId]);
        } catch (Exception $e) { /* absent */ }
    }

    // Ticket-to-ticket links: re-point at the target, then drop self-links and any
    // duplicate pair the re-point just created.
    try {
        $conn->prepare("UPDATE ticket_links SET source_ticket_id = ? WHERE source_ticket_id = ?")->execute([$targetId, $sourceId]);
        $conn->prepare("UPDATE ticket_links SET target_ticket_id = ? WHERE target_ticket_id = ?")->execute([$targetId, $sourceId]);
        $conn->prepare("DELETE FROM ticket_links WHERE source_ticket_id = target_ticket_id")->execute();
        $conn->exec(
            "DELETE l1 FROM ticket_links l1
               INNER JOIN ticket_links l2
                  ON l1.source_ticket_id = l2.source_ticket_id
                 AND l1.target_ticket_id = l2.target_ticket_id
                 AND l1.relation_type   = l2.relation_type
                 AND l1.id > l2.id"
        );
    } catch (Exception $e) { /* linking not installed */ }

    if ($snapshotHtml !== null) {
        mergeAttachSnapshot($conn, $targetId, $source, $snapshotHtml);
    }

    // Close the source and point it at the target. The status is the install's OWN
    // first closed status — never a hardcoded name, because an install may have
    // renamed or added its own (the same rule the reopen-on-reply logic follows).
    $closedId = mergeFirstClosedStatusId($conn);
    $conn->prepare(
        "UPDATE tickets
            SET merged_into_id = ?,
                status_id = COALESCE(?, status_id),
                closed_datetime = COALESCE(closed_datetime, UTC_TIMESTAMP()),
                updated_datetime = UTC_TIMESTAMP()
          WHERE id = ?"
    )->execute([$targetId, $closedId, $sourceId]);

    // The merge log, and an audit entry on BOTH tickets — the source needs to say
    // where it went, the target needs to say what arrived.
    $targetNumber = mergeTicketNumber($conn, $targetId);
    $conn->prepare(
        "INSERT INTO ticket_merges
            (source_ticket_id, source_ticket_number, target_ticket_id, reference_mode, originals_mode, merged_by_id, merged_datetime)
         VALUES (?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())"
    )->execute([
        $sourceId, $source['ticket_number'] ?? null, $targetId,
        $settings['reference_mode'], $settings['originals_mode'], $actorId ?: null,
    ]);

    mergeAudit($conn, $sourceId, $actorId, 'Merged', $source['ticket_number'] ?? '', 'Merged into ' . $targetNumber);
    mergeAudit($conn, $targetId, $actorId, 'Merged', null, 'Merged in ' . ($source['ticket_number'] ?? ('#' . $sourceId)));

    // Record the relationship in the existing linking feature too, so the Links
    // section and the merge banner agree rather than telling two stories.
    try {
        $conn->prepare(
            "INSERT INTO ticket_links (source_ticket_id, target_ticket_id, relation_type, created_by_id, created_datetime)
             VALUES (?, ?, 'duplicate_of', ?, UTC_TIMESTAMP())"
        )->execute([$sourceId, $targetId, $actorId ?: null]);
    } catch (Exception $e) { /* linking not installed, or already linked */ }
}

/** The install's first closed status id, or null if none is flagged. */
function mergeFirstClosedStatusId(PDO $conn): ?int {
    try {
        $row = $conn->query("SELECT id FROM ticket_statuses WHERE is_closed = 1 ORDER BY display_order, id LIMIT 1")->fetchColumn();
        return $row ? (int)$row : null;
    } catch (Exception $e) {
        return null;
    }
}

function mergeTicketNumber(PDO $conn, int $ticketId): string {
    try {
        $stmt = $conn->prepare("SELECT ticket_number FROM tickets WHERE id = ?");
        $stmt->execute([$ticketId]);
        return (string)$stmt->fetchColumn();
    } catch (Exception $e) {
        return '#' . $ticketId;
    }
}

function mergeAudit(PDO $conn, int $ticketId, int $actorId, string $field, ?string $old, ?string $new): void {
    try {
        $conn->prepare(
            "INSERT INTO ticket_audit (ticket_id, analyst_id, field_name, old_value, new_value, created_datetime)
             VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP())"
        )->execute([$ticketId, $actorId ?: null, $field, $old, $new]);
    } catch (Exception $e) { /* audit is best-effort, never fail a merge for it */ }
}

/**
 * Render a source ticket's whole conversation as a self-contained HTML document.
 *
 * ⚠️ THIS FILE IS OPENED OUTSIDE THE APP. An analyst downloads it and it renders in a
 * browser tab with no Content-Security-Policy and none of the app's protections, and
 * the message bodies in it were written by whoever emailed the service desk.
 *
 * So every body goes through sanitiseUserHtml() — the same customer allow-list the
 * portal uses on the way in — which keeps a short list of formatting tags and drops
 * <script>, <style>, <iframe>, every on* handler and <img>. Plain-text bodies are
 * escaped and <br>-ed instead. Nothing else here is interpolated without
 * htmlspecialchars().
 *
 * Stripping <script> alone would NOT be enough: `onerror` on a surviving tag fires
 * with no script element anywhere. That is why this uses the one shared allow-list
 * rather than a hand-rolled strip.
 */
function mergeBuildSnapshotHtml(PDO $conn, int $sourceId, array $source): string {
    require_once __DIR__ . '/html_sanitise.php';

    $esc = function ($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); };

    try {
        $stmt = $conn->prepare(
            "SELECT subject, from_name, from_address, received_datetime,
                    body_content, body_type, direction
               FROM emails WHERE ticket_id = ? ORDER BY received_datetime, id"
        );
        $stmt->execute([$sourceId]);
        $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { $messages = []; }

    try {
        $stmt = $conn->prepare(
            "SELECT n.note_text, n.created_datetime, a.full_name
               FROM ticket_notes n LEFT JOIN analysts a ON a.id = n.analyst_id
              WHERE n.ticket_id = ? ORDER BY n.created_datetime, n.id"
        );
        $stmt->execute([$sourceId]);
        $notes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { $notes = []; }

    $rows = '';
    foreach ($messages as $m) {
        $body = (string)($m['body_content'] ?? '');
        $safe = (strtolower((string)$m['body_type']) === 'html')
            ? sanitiseUserHtml($body)
            : nl2br($esc($body));

        $who = trim(($m['from_name'] ?? '') . ' <' . ($m['from_address'] ?? '') . '>');
        $rows .= '<div class="msg"><div class="meta"><strong>' . $esc($m['direction'] ?? '') . '</strong> &mdash; '
               . $esc($who) . ' &mdash; ' . $esc($m['received_datetime'] ?? '') . '</div>'
               . '<div class="subj">' . $esc($m['subject'] ?? '') . '</div>'
               . '<div class="body">' . $safe . '</div></div>';
    }
    if ($rows === '') $rows = '<p class="empty">This ticket had no messages.</p>';

    $noteRows = '';
    foreach ($notes as $n) {
        $noteRows .= '<div class="note"><div class="meta">' . $esc($n['full_name'] ?? 'Analyst')
                   . ' &mdash; ' . $esc($n['created_datetime'] ?? '') . '</div>'
                   . '<div class="body">' . nl2br($esc($n['note_text'] ?? '')) . '</div></div>';
    }
    if ($noteRows !== '') $noteRows = '<h2>Internal notes</h2>' . $noteRows;

    $ref     = $esc($source['ticket_number'] ?? '');
    $subject = $esc($source['subject'] ?? '');
    $created = $esc($source['created_datetime'] ?? '');
    $stamp   = gmdate('Y-m-d H:i') . ' UTC';

    // Styles are inline because the document must render standalone, off-server.
    $css = 'body{font-family:Segoe UI,Tahoma,Geneva,Verdana,sans-serif;font-size:14px;color:#222;background:#f6f7f9;margin:0;padding:24px;}'
         . '.wrap{max-width:860px;margin:0 auto;background:#fff;border:1px solid #e2e5e9;border-radius:8px;padding:28px;}'
         . 'h1{font-size:19px;margin:0 0 4px;}h2{font-size:15px;margin:26px 0 10px;border-bottom:1px solid #eee;padding-bottom:6px;}'
         . '.hdr{color:#666;font-size:12px;margin-bottom:18px;}'
         . '.msg,.note{border:1px solid #e6e9ec;border-radius:6px;padding:14px 16px;margin-bottom:12px;}'
         . '.note{background:#fffdf3;border-color:#f0e6c0;}'
         . '.meta{color:#666;font-size:12px;margin-bottom:6px;}'
         . '.subj{font-weight:600;margin-bottom:8px;}'
         . '.body{line-height:1.55;overflow-wrap:anywhere;}'
         . '.body table{max-width:100%;border-collapse:collapse;}.body td,.body th{border:1px solid #ddd;padding:4px 6px;}'
         . '.empty{color:#888;font-style:italic;}'
         . '.foot{margin-top:26px;color:#888;font-size:11px;border-top:1px solid #eee;padding-top:10px;}';

    return '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
         . '<title>' . $ref . ' &mdash; ' . $subject . '</title><style>' . $css . '</style></head>'
         . '<body><div class="wrap">'
         . '<h1>' . $ref . ' &mdash; ' . $subject . '</h1>'
         . '<div class="hdr">Raised ' . $created . '. Snapshot taken when this ticket was merged, ' . $esc($stamp) . '.</div>'
         . '<h2>Conversation</h2>' . $rows . $noteRows
         . '<div class="foot">Generated by FreeITSM. Scripts and remote images were removed when this snapshot was made.</div>'
         . '</div></body></html>';
}

/**
 * Attach a snapshot to the target ticket.
 *
 * A ticket's attachments are, in this schema, ALWAYS `email_attachments` reached
 * through `emails.ticket_id` — there is no standalone ticket-attachment table. So the
 * snapshot arrives as a system-generated message on the thread carrying the file.
 * That is not a workaround dressed as a design: it puts the snapshot in the
 * conversation where an analyst will notice it AND in the Attachments list, from one
 * insert, downloadable through the same endpoint as every other attachment.
 */
function mergeAttachSnapshot(PDO $conn, int $targetId, array $source, string $html): void {
    $ref = $source['ticket_number'] ?? ('ticket-' . (int)($source['id'] ?? 0));

    $conn->prepare(
        "INSERT INTO emails
            (subject, from_address, from_name, received_datetime, body_content, body_type,
             has_attachments, is_read, ticket_id, direction, channel, is_initial, processed_datetime, ticket_created)
         VALUES (?, '', 'FreeITSM', UTC_TIMESTAMP(), ?, 'html', 1, 1, ?, 'Manual', 'email', 0, UTC_TIMESTAMP(), 1)"
    )->execute([
        'Merged from ' . $ref,
        '<p>Ticket <strong>' . htmlspecialchars((string)$ref, ENT_QUOTES, 'UTF-8')
            . '</strong> was merged into this one. Its full conversation is attached as an HTML file.</p>',
        $targetId,
    ]);
    $emailId = (int)$conn->lastInsertId();

    // Same on-disk layout the mailbox importer uses, so anything that already walks
    // tickets/attachments keeps working.
    $baseDir = dirname(__DIR__) . '/tickets/attachments';
    $subDir  = (string)floor($emailId / 1000);
    $dir     = $baseDir . '/' . $subDir . '/' . $emailId;
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new Exception('Could not create the attachment directory for the merge snapshot');
    }

    $safeRef  = preg_replace('/[^a-zA-Z0-9._-]/', '_', (string)$ref);
    $filename = $safeRef . '.html';
    $relPath  = $subDir . '/' . $emailId . '/' . $filename;
    if (file_put_contents($baseDir . '/' . $relPath, $html) === false) {
        throw new Exception('Could not write the merge snapshot file');
    }

    $conn->prepare(
        "INSERT INTO email_attachments (email_id, filename, content_type, file_path, file_size, is_inline, created_datetime)
         VALUES (?, ?, 'text/html', ?, ?, 0, UTC_TIMESTAMP())"
    )->execute([$emailId, $filename, $relPath, strlen($html)]);
}
