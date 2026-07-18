<?php
/**
 * Reopening a finished ticket when the customer comes back.
 *
 * WHY THIS IS SHARED
 * ------------------
 * A customer can come back through more than one door — they can reply in the
 * self-service portal, or reply to the notification email. "Reply to a finished
 * ticket" means the same thing whichever door they used, so the rule lives here
 * once and both callers use it. Two copies would drift, and the symptom would be
 * a customer whose reply reopens their ticket on Monday (email) but not on
 * Tuesday (portal), which is impossible to explain to them.
 *
 * The email template FreeITSM sends already tells requesters "just reply to this
 * email and it will be reopened" (workflow/includes/templates.php) — before this,
 * nothing anywhere honoured that promise on any channel.
 *
 * WHAT COUNTS AS "FINISHED"
 * -------------------------
 * `ticket_statuses.is_closed` — the app's own definition of a finished ticket,
 * configurable per install in Tickets → Settings → Statuses. We deliberately do
 * NOT hardcode status names: an install that renames "Closed" to "Completed", or
 * marks "Resolved" as closed too, gets the behaviour it configured. Note the
 * shipped default flags only "Closed" and not "Resolved", so out of the box a
 * reply to a Resolved ticket appends without reopening.
 */

require_once __DIR__ . '/functions.php';

/**
 * Is reopen-on-customer-reply switched on? Tickets → Settings → General.
 *
 * Defaults to ON when the row is absent: the requester-facing email template has
 * always promised this behaviour, so honouring it is the answer that matches what
 * customers were already told. Reopening is non-destructive and visible (the
 * ticket comes back into the queue); the opposite failure — a customer's reply
 * silently landing on a closed ticket nobody looks at again — is not.
 */
function customerReplyReopensTickets(PDO $conn): bool {
    static $cached = null;
    if ($cached !== null) return $cached;

    try {
        $stmt = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'reopen_on_customer_reply'");
        $stmt->execute();
        $val = $stmt->fetchColumn();
        // Absent (never saved) → on. Present → honour it exactly.
        $cached = ($val === false || $val === null || $val === '') ? true : ((string)$val === '1');
    } catch (Exception $e) {
        $cached = true;   // settings table unreadable → keep the promise.
    }
    return $cached;
}

/**
 * Reopen a ticket because its requester replied. No-op unless the setting is on
 * AND the ticket is currently in a status flagged is_closed.
 *
 * Reopens to the install's first OPEN status, preferring one literally called
 * "Open" and otherwise falling back to the lowest-ordered non-closed status — so
 * an install with a renamed workflow still lands somewhere sane rather than
 * having a status name hardcoded at it.
 *
 * Deliberately defensive: a reply must never fail because reopening did. The
 * customer's message is the thing that matters; the status is a convenience.
 *
 * @return bool true if the ticket was actually reopened (for the caller's UI message)
 */
function reopenTicketForCustomerReply(PDO $conn, int $ticketId): bool {
    if ($ticketId <= 0) return false;

    try {
        if (!customerReplyReopensTickets($conn)) return false;

        // Is it finished? Join through so an unknown/NULL status never counts.
        $stmt = $conn->prepare(
            "SELECT ts.is_closed
             FROM tickets t
             JOIN ticket_statuses ts ON ts.id = t.status_id
             WHERE t.id = ?"
        );
        $stmt->execute([$ticketId]);
        $isClosed = $stmt->fetchColumn();
        if ($isClosed === false || (int)$isClosed !== 1) return false;

        // Prefer an ACTIVE status literally called "Open", then the install's
        // default, then whatever comes first. is_active sorts first rather than
        // filtering, so an install that retired every status still gets an answer
        // instead of leaving the ticket stuck closed.
        $openId = $conn->query(
            "SELECT id FROM ticket_statuses
             WHERE is_closed = 0
             ORDER BY is_active DESC, (name = 'Open') DESC, is_default DESC, display_order ASC, id ASC
             LIMIT 1"
        )->fetchColumn();
        if ($openId === false || $openId === null) return false;   // every status is closed?!

        $conn->prepare("UPDATE tickets SET status_id = ?, updated_datetime = UTC_TIMESTAMP() WHERE id = ?")
             ->execute([(int)$openId, $ticketId]);
        return true;
    } catch (Exception $e) {
        error_log('reopenTicketForCustomerReply failed for ticket ' . $ticketId . ': ' . $e->getMessage());
        return false;
    }
}
