<?php
/**
 * Splitting a ticket.
 *
 * A customer replies to "printer offline" with "…also my monitor flickers". That is
 * two pieces of work on one ticket: one SLA clock, one status, one assignee, and
 * whichever problem is fixed second looks late. Splitting moves the unrelated
 * messages onto a ticket of their own.
 *
 * WHY THIS IS NOT THE MIRROR IMAGE OF MERGING
 * -------------------------------------------
 * Merging had to preserve the old reference because the customer already held it and
 * could reply to it — hence merged_into_id and the inbound-mail redirect. A split
 * creates a reference NOBODY HAS EVER SEEN. There is nothing to redirect, nothing to
 * close, and no equivalent pointer: both tickets come out of this live and
 * independent. That asymmetry is why this is a separate file rather than a flag on
 * the merge engine.
 *
 * THE MODEL, AND WHY
 * ------------------
 * Split at a chosen message, optionally taking every LATER message with it.
 *
 * There is no industry standard here — Freshdesk splits a single customer response
 * and leaves later replies behind, Zendesk ships no split at all (it is a third-party
 * app), and Jira/ServiceNow use linked or child issues instead. The recurring
 * complaint across all of them is ambiguity about whether a split COPIES or MOVES.
 * So this MOVES, and says so: the messages leave the original, and a marker is left
 * in the thread where they went, because a conversation that silently jumps from
 * Tuesday to Friday is worse than one with an explicit gap.
 *
 * ⚠️ THE THREAD IS DISPLAYED NEWEST-FIRST. "Everything after" means LATER IN TIME,
 * which is ABOVE the chosen message on screen. The UI says "newer messages" for
 * exactly this reason; keep that wording if you touch it.
 */

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/services/tickets.php';

/**
 * Which messages a split would move, oldest first.
 *
 * Shared by the preview and the split itself so the dialog can never promise
 * something different from what happens.
 *
 * @return array rows of [id, subject, from_name, from_address, received_datetime, direction]
 */
function splitMessagesFrom(PDO $conn, int $ticketId, int $fromEmailId, bool $includeNewer): array {
    $stmt = $conn->prepare(
        "SELECT id, subject, from_name, from_address, received_datetime, direction
           FROM emails WHERE id = ? AND ticket_id = ?"
    );
    $stmt->execute([$fromEmailId, $ticketId]);
    $anchor = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$anchor) {
        throw new Exception('That message is not on this ticket');
    }
    if (!$includeNewer) {
        return [$anchor];
    }

    // Ordered by (received_datetime, id) — the id breaks ties so two messages that
    // arrived in the same second still split deterministically rather than by
    // whatever order the storage engine felt like.
    $stmt = $conn->prepare(
        "SELECT id, subject, from_name, from_address, received_datetime, direction
           FROM emails
          WHERE ticket_id = ?
            AND (received_datetime > ? OR (received_datetime = ? AND id >= ?))
       ORDER BY received_datetime, id"
    );
    $stmt->execute([$ticketId, $anchor['received_datetime'], $anchor['received_datetime'], $fromEmailId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Split messages off a ticket into a brand-new one.
 *
 * @return array {new_ticket_id, new_ticket_number, moved: int}
 */
function splitTicket(PDO $conn, int $actorId, int $ticketId, int $fromEmailId, bool $includeNewer, ?string $newSubject = null): array {
    $ctx = ActorContext::fromSession($conn);

    // Access first, before anything moves.
    try {
        $source = TicketsService::loadTicket($conn, $ctx, $ticketId);
    } catch (Throwable $e) {
        throw new Exception('Could not load ticket #' . $ticketId . ' (' . $e->getMessage() . ')');
    }
    if ($source['deleted_datetime'] !== null) {
        throw new Exception('Ticket ' . $source['ticket_number'] . ' is in the trash — restore it first');
    }
    if (!empty($source['merged_into_id'])) {
        throw new Exception('Ticket ' . $source['ticket_number'] . ' has been merged into another ticket — split that one instead');
    }

    $moving = splitMessagesFrom($conn, $ticketId, $fromEmailId, $includeNewer);
    if (!$moving) {
        throw new Exception('Nothing to split');
    }

    // A ticket with no messages left is a broken ticket: the reading pane has nothing
    // to show, the requester's original request is gone, and the reference the
    // customer holds now points at an empty shell. If somebody wants ALL of it
    // elsewhere they want a move or a merge, not a split.
    $total = (int)$conn->query("SELECT COUNT(*) FROM emails WHERE ticket_id = " . (int)$ticketId)->fetchColumn();
    if (count($moving) >= $total) {
        throw new Exception('That would move every message and leave ' . $source['ticket_number'] . ' empty — keep at least one message on the original');
    }

    $conn->beginTransaction();
    try {
        $newId     = splitCreateTicket($conn, $source, $moving, $actorId, $newSubject);
        $newNumber = (string)$conn->query("SELECT ticket_number FROM tickets WHERE id = " . (int)$newId)->fetchColumn();

        $ids = array_map(fn($m) => (int)$m['id'], $moving);
        $in  = implode(',', $ids);

        // Attachments follow their message via email_attachments.email_id, so moving
        // the email row is enough — nothing else to re-point.
        $conn->exec("UPDATE emails SET ticket_id = " . (int)$newId . " WHERE id IN ($in)");

        splitLeaveMarker($conn, $ticketId, $newNumber, $moving);

        $conn->prepare(
            "INSERT INTO ticket_splits
                (source_ticket_id, source_ticket_number, new_ticket_id, new_ticket_number, message_count, split_by_id, split_datetime)
             VALUES (?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())"
        )->execute([$ticketId, $source['ticket_number'] ?? null, $newId, $newNumber, count($moving), $actorId ?: null]);

        splitAudit($conn, $ticketId, $actorId, 'Split', null, count($moving) . ' message(s) split to ' . $newNumber);
        splitAudit($conn, $newId, $actorId, 'Split', null, 'Split from ' . ($source['ticket_number'] ?? ('#' . $ticketId)));

        // Related, NOT duplicate_of: these two tickets are about different things —
        // that is the entire reason for splitting. Merging uses duplicate_of because
        // there the tickets genuinely were the same issue.
        try {
            $conn->prepare(
                "INSERT INTO ticket_links (source_ticket_id, target_ticket_id, relation_type, created_by_id, created_datetime)
                 VALUES (?, ?, 'related', ?, UTC_TIMESTAMP())"
            )->execute([$ticketId, $newId, $actorId ?: null]);
        } catch (Exception $e) { /* linking not installed, or already linked */ }

        $conn->commit();
        return ['new_ticket_id' => $newId, 'new_ticket_number' => $newNumber, 'moved' => count($moving)];
    } catch (Exception $e) {
        $conn->rollBack();
        throw $e;
    }
}

/**
 * Create the ticket the messages move into.
 *
 * Inherits the requester, company, department and type from the source — a split is
 * the same person and the same context, just a different problem. Priority and
 * assignee are deliberately NOT inherited... except the assignee is, because whoever
 * is holding the conversation should keep holding both halves until they decide
 * otherwise. Status starts at the install's first OPEN status: the new work is by
 * definition unfinished, even if the original had been resolved.
 */
function splitCreateTicket(PDO $conn, array $source, array $moving, int $actorId, ?string $newSubject): int {
    for ($attempt = 0; $attempt < 10; $attempt++) {
        $letters = chr(rand(65, 90)) . chr(rand(65, 90)) . chr(rand(65, 90));
        $number  = $letters . '-' . rand(100, 999) . '-' . str_pad((string)rand(0, 99999), 5, '0', STR_PAD_LEFT);
        $check = $conn->prepare("SELECT COUNT(*) FROM tickets WHERE ticket_number = ?");
        $check->execute([$number]);
        if (!(int)$check->fetchColumn()) break;
        $number = null;
    }
    if (empty($number)) throw new Exception('Failed to generate a ticket number');

    // Subject: the analyst's if they gave one, else the first moved message's, else
    // the source's. The first moved message is usually the customer saying what the
    // new problem is, which is exactly the subject you want.
    $subject = trim((string)$newSubject);
    if ($subject === '') $subject = trim((string)($moving[0]['subject'] ?? ''));
    if ($subject === '') $subject = trim((string)($source['subject'] ?? 'Split ticket'));
    if (mb_strlen($subject) > 255) $subject = mb_substr($subject, 0, 255);

    $openStatus = null;
    try {
        $openStatus = $conn->query(
            "SELECT id FROM ticket_statuses WHERE is_closed = 0 ORDER BY display_order, id LIMIT 1"
        )->fetchColumn() ?: null;
    } catch (Exception $e) { /* fall back to the source's status */ }

    $stmt = $conn->prepare(
        "INSERT INTO tickets
            (ticket_number, subject, status_id, priority_id, department_id, ticket_type_id,
             assigned_analyst_id, origin_id, user_id, owner_id, tenant_id, created_datetime, updated_datetime)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())"
    );
    $stmt->execute([
        $number,
        $subject,
        $openStatus ?: ($source['status_id'] ?? null),
        $source['priority_id'] ?? null,
        $source['department_id'] ?? null,
        $source['ticket_type_id'] ?? null,
        $source['assigned_analyst_id'] ?? null,
        $source['origin_id'] ?? null,
        $source['user_id'] ?? null,
        $source['owner_id'] ?? null,
        $source['tenant_id'] ?? null,
    ]);
    return (int)$conn->lastInsertId();
}

/**
 * Leave a visible marker in the original thread where the messages left.
 *
 * Timestamped to the FIRST moved message so it sits in the gap those messages left
 * rather than jumping to the top of the conversation — the point is to explain the
 * hole, and a marker somewhere else explains nothing.
 */
function splitLeaveMarker(PDO $conn, int $ticketId, string $newNumber, array $moving): void {
    $when  = $moving[0]['received_datetime'] ?? null;
    $count = count($moving);
    $body  = '<p><em>' . $count . ' message' . ($count === 1 ? '' : 's')
           . ' moved to ticket <strong>' . htmlspecialchars($newNumber, ENT_QUOTES, 'UTF-8')
           . '</strong>.</em></p>';

    $stmt = $conn->prepare(
        "INSERT INTO emails
            (subject, from_address, from_name, received_datetime, body_content, body_type,
             has_attachments, is_read, ticket_id, direction, channel, is_initial, processed_datetime, ticket_created)
         VALUES (?, '', 'FreeITSM', COALESCE(?, UTC_TIMESTAMP()), ?, 'html', 0, 1, ?, 'Manual', 'email', 0, UTC_TIMESTAMP(), 1)"
    );
    $stmt->execute(['Split to ' . $newNumber, $when, $body, $ticketId]);
}

function splitAudit(PDO $conn, int $ticketId, int $actorId, string $field, ?string $old, ?string $new): void {
    try {
        $conn->prepare(
            "INSERT INTO ticket_audit (ticket_id, analyst_id, field_name, old_value, new_value, created_datetime)
             VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP())"
        )->execute([$ticketId, $actorId ?: null, $field, $old, $new]);
    } catch (Exception $e) { /* audit is best-effort, never fail a split for it */ }
}

/** Splits recorded against a ticket, in both directions, for the banner. */
function splitInfoFor(PDO $conn, int $ticketId): array {
    $out = ['split_out' => [], 'split_from' => null];
    try {
        $s = $conn->prepare("SELECT new_ticket_id, new_ticket_number, message_count, split_datetime
                               FROM ticket_splits WHERE source_ticket_id = ? ORDER BY id");
        $s->execute([$ticketId]);
        $out['split_out'] = $s->fetchAll(PDO::FETCH_ASSOC);

        $s = $conn->prepare("SELECT source_ticket_id, source_ticket_number, message_count, split_datetime
                               FROM ticket_splits WHERE new_ticket_id = ? LIMIT 1");
        $s->execute([$ticketId]);
        $out['split_from'] = $s->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Exception $e) { /* pre-upgrade install */ }
    return $out;
}
