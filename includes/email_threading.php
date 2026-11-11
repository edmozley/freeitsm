<?php
/**
 * Recognising a reply that carries no ticket reference (GH #120).
 *
 * Every email FreeITSM sends has [SDREF:...] in its subject, and a reply quoting
 * it lands on its ticket. But a requester who replies to their OWN first email -
 * "forgot the attachment" - quotes nothing of ours, so that reply used to become
 * a second ticket.
 *
 * Mail clients and providers already know which conversation a message belongs
 * to. Each provider gives one value, stored on every inbound email as its
 * THREAD KEY (emails.thread_key):
 *
 *   Microsoft 365 - the message's conversationId
 *   Gmail         - its threadId
 *   Basic IMAP    - the first Message-ID in References (the conversation's root),
 *                   else In-Reply-To, else the message's own Message-ID - so an
 *                   original and every reply to it share one key
 *
 * Tickets -> Settings -> General -> "A reply without a ticket number" decides
 * whether a reply with no [SDREF] joins the ticket its conversation already
 * belongs to (same mailbox), or starts a new ticket as before (the default).
 */

enum TicketThreadReplies: string
{
    case NewTicket  = 'new_ticket';    // as it always was
    case SameTicket = 'same_ticket';   // join the ticket the conversation belongs to
}

/** The install's choice; NewTicket when never saved or unknown. */
function ticketThreadReplies(PDO $conn): TicketThreadReplies
{
    try {
        $st = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'ticket_thread_replies'");
        $st->execute();
        return TicketThreadReplies::tryFrom((string)$st->fetchColumn()) ?? TicketThreadReplies::NewTicket;
    } catch (Throwable) {
        return TicketThreadReplies::NewTicket;
    }
}

/**
 * Whether emails.thread_key exists yet. TRAP: the column arrives with Database
 * Verify, and mail collection must keep working on an install that has not run
 * it - so the importer only writes or reads the column once it is there.
 */
function emailThreadingReady(PDO $conn): bool
{
    static $ready = null;
    if ($ready !== null) return $ready;
    try {
        $ready = (bool)$conn->query("SHOW COLUMNS FROM emails LIKE 'thread_key'")->fetch();
    } catch (Throwable) {
        $ready = false;
    }
    return $ready;
}

/**
 * The thread key of a Basic IMAP message, from its References and In-Reply-To
 * headers and its own Message-ID: the conversation's root, so an original email
 * and every reply to it agree.
 *
 * @param string $references  the raw References header ('' when absent)
 * @param string $inReplyTo  the raw In-Reply-To header ('' when absent)
 * @param string $messageId  the message's own Message-ID ('' when absent)
 */
function emailImapThreadKey(string $references, string $inReplyTo, string $messageId): ?string
{
    foreach ([$references, $inReplyTo, $messageId] as $h) {
        if (preg_match('/<[^<>\s]+>/', $h, $m)) return mb_substr($m[0], 0, 255);
    }
    return null;
}

/**
 * The ticket a conversation already belongs to: the newest email in the same
 * mailbox with this thread key (or, for a Basic IMAP reply to an email stored
 * before thread keys existed, whose Message-ID is the reply's root or
 * In-Reply-To) that is on a ticket - following merges, as a quoted ticket
 * number is followed.
 *
 * @param int $mailboxId  only this mailbox's mail is looked at - two mailboxes are two inboxes
 * @param ?string $threadKey  the incoming email's thread key
 * @param ?string $inReplyTo  a Basic IMAP reply's In-Reply-To, also tried against stored Message-IDs
 * @return ?int  the live ticket id, or null to start a new ticket
 */
function emailThreadTicket(PDO $conn, int $mailboxId, ?string $threadKey, ?string $inReplyTo = null): ?int
{
    if (!emailThreadingReady($conn)) return null;
    $keys = array_values(array_unique(array_filter([$threadKey, $inReplyTo], fn($k) => is_string($k) && $k !== '')));
    if (!$keys || $mailboxId <= 0) return null;
    $in = implode(',', array_fill(0, count($keys), '?'));
    // exchange_message_id holds a Basic IMAP message's Message-ID, so an original
    // email stored before thread keys existed is still found by a reply's root.
    $st = $conn->prepare(
        "SELECT ticket_id FROM emails
          WHERE mailbox_id = ? AND ticket_id IS NOT NULL
            AND (thread_key IN ($in) OR exchange_message_id IN ($in))
          ORDER BY id DESC LIMIT 1"
    );
    $st->execute(array_merge([$mailboxId], $keys, $keys));
    $ticketId = $st->fetchColumn();
    if ($ticketId === false) return null;
    require_once __DIR__ . '/ticket_merge.php';
    return resolveMergedTicket($conn, (int)$ticketId);
}
