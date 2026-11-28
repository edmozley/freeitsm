<?php
/**
 * Automatic replies on inbound mail (GH #432): out-of-office messages and other
 * auto-responders.
 *
 * Such a message is still stored on its ticket - nothing is ever lost - but it is
 * flagged (emails.is_auto_reply) and never acts as a customer reply: it does not
 * reopen a closed ticket, wake a snoozed one, bump the ticket's last update or fire
 * ticket.reply_received. One that matches no ticket still becomes a ticket, but is
 * not acknowledged, so two auto-responders can't answer each other forever.
 *
 * Recognised by headers only, never by subject ("Automatic reply:", "Out of office"):
 * subjects are translated, and a person replying to such a message keeps the words.
 *
 * Two answers, because they protect against different things:
 *   - An AUTOMATIC REPLY (emailIsAutoReply) is not the requester coming back:
 *       Auto-Submitted: auto-replied (RFC 3834's out-of-office), X-Autoreply or
 *       X-Autorespond (not empty), Precedence: auto_reply.
 *     Auto-Submitted: auto-generated / auto-notified are NOT counted: ticketing and
 *     notification systems (a supplier's service desk, Jira comments) stamp them on
 *     mail that carries what a person wrote, and that must still reopen the ticket.
 *   - AUTOMATED mail (emailIsAutomated) is any of those, or Auto-Submitted with any
 *     value but "no". It is never sent the new-ticket acknowledgement or the oversize
 *     auto-reply: answering a machine is how two systems end up mailing each other.
 * Not counted at all: Precedence bulk / list / junk (a mailing list can carry a
 * person's reply), and X-Auto-Response-Suppress on its own (a sender asking others
 * not to auto-reply to it - it says nothing about the message itself).
 */

/** The headers a Basic IMAP fetch asks for, beside References / In-Reply-To. */
const EMAIL_AUTO_REPLY_HEADERS = ['AUTO-SUBMITTED', 'PRECEDENCE', 'X-AUTOREPLY', 'X-AUTORESPOND'];

/**
 * The keyword of an Auto-Submitted value, lower case: "auto-replied; reason=x" and
 * "no (comment)" are legal (RFC 3834), and only the first token means anything.
 */
function emailAutoSubmittedKeyword(array $headers): string
{
    $raw = strtolower(trim((string)($headers['auto-submitted'] ?? '')));
    return preg_match('/^[a-z0-9-]+/', $raw, $m) ? $m[0] : '';
}

/**
 * Whether a message's headers mark it as an automatic reply (an out-of-office) -
 * kept on the ticket, but never treated as the requester coming back.
 *
 * @param array<string,string> $headers  lower-cased header name => value
 */
function emailIsAutoReply(array $headers): bool
{
    $v = static fn(string $k): string => strtolower(trim((string)($headers[$k] ?? '')));
    if (emailAutoSubmittedKeyword($headers) === 'auto-replied') return true;
    if ($v('x-autoreply') !== '' || $v('x-autorespond') !== '') return true;
    return $v('precedence') === 'auto_reply';
}

/**
 * Whether a message's headers mark it as sent by a machine at all - an automatic
 * reply, or Auto-Submitted with any value but "no". Never acknowledged or answered.
 *
 * @param array<string,string> $headers  lower-cased header name => value
 */
function emailIsAutomated(array $headers): bool
{
    if (emailIsAutoReply($headers)) return true;
    $k = emailAutoSubmittedKeyword($headers);
    return $k !== '' && $k !== 'no';
}

/**
 * A raw header block ("Name: value" lines, folded lines allowed) as a lower-cased map.
 * A header that appears twice keeps its first value.
 *
 * @return array<string,string>
 */
function emailHeaderMap(string $raw): array
{
    $raw = preg_replace('/\r?\n[ \t]+/', ' ', $raw);
    $out = [];
    foreach (preg_split('/\r?\n/', (string)$raw) as $line) {
        if (!preg_match('/^([!-9;-~]+):\s*(.*)$/', $line, $m)) continue;
        $k = strtolower($m[1]);
        if (!array_key_exists($k, $out)) $out[$k] = trim($m[2]);
    }
    return $out;
}

/**
 * The header map from one Basic IMAP uidFetch() result: the BODY[HEADER.FIELDS ...]
 * block, found by the start of its key (servers vary the case and drop PEEK).
 *
 * @return array<string,string>
 */
function emailImapHeaderMap(array $item): array
{
    foreach ($item as $k => $v) {
        if (is_string($k) && stripos($k, 'BODY[HEADER.FIELDS') === 0 && is_string($v)) return emailHeaderMap($v);
    }
    return [];
}

/**
 * The header map from Microsoft Graph's internetMessageHeaders ([{name, value}, ...]).
 *
 * @return array<string,string>
 */
function emailHeaderMapFromList(?array $list): array
{
    $out = [];
    foreach ($list ?? [] as $h) {
        if (!is_array($h) || !isset($h['name'])) continue;
        $k = strtolower(trim((string)$h['name']));
        if ($k !== '' && !array_key_exists($k, $out)) $out[$k] = trim((string)($h['value'] ?? ''));
    }
    return $out;
}

/**
 * Whether an inbound message, as a provider returned it, is an automatic reply:
 * the 'auto_reply' flag Basic IMAP and Gmail set, or Microsoft Graph's
 * internetMessageHeaders.
 */
function inboundIsAutoReply(array $email): bool
{
    if (!empty($email['auto_reply'])) return true;
    return emailIsAutoReply(emailHeaderMapFromList(is_array($email['internetMessageHeaders'] ?? null) ? $email['internetMessageHeaders'] : null));
}

/** Whether an inbound message is automated mail (see emailIsAutomated()): the provider flags or Graph's headers. */
function inboundIsAutomated(array $email): bool
{
    if (!empty($email['auto_reply']) || !empty($email['automated'])) return true;
    return emailIsAutomated(emailHeaderMapFromList(is_array($email['internetMessageHeaders'] ?? null) ? $email['internetMessageHeaders'] : null));
}

/**
 * Whether emails.is_auto_reply exists yet. It arrives with Database Verify, and
 * mail collection must keep working on an install that has not run it - so the
 * importer only writes the column once it is there.
 */
function emailAutoReplyReady(PDO $conn): bool
{
    static $ready = null;
    if ($ready !== null) return $ready;
    try {
        $ready = (bool)$conn->query("SHOW COLUMNS FROM emails LIKE 'is_auto_reply'")->fetch();
    } catch (Throwable) {
        $ready = false;
    }
    return $ready;
}

/**
 * Whether the importer sends the new-ticket acknowledgement: only for a new ticket,
 * and never to automated mail (it would answer the machine, which may answer back).
 */
function inboundShouldAcknowledge(bool $isNewTicket, bool $isAutomated): bool
{
    return $isNewTicket && !$isAutomated;
}
