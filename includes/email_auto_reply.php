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
 *   - Auto-Submitted, any value but "no" (RFC 3834: auto-replied, auto-generated, ...)
 *   - X-Autoreply / X-Autorespond, when not empty
 *   - Precedence: auto_reply
 * Not counted: Precedence bulk / list / junk (a mailing list can carry a person's
 * reply), and X-Auto-Response-Suppress on its own (a sender asking others not to
 * auto-reply to it - it says nothing about the message itself).
 */

/** The headers a Basic IMAP fetch asks for, beside References / In-Reply-To. */
const EMAIL_AUTO_REPLY_HEADERS = ['AUTO-SUBMITTED', 'PRECEDENCE', 'X-AUTOREPLY', 'X-AUTORESPOND'];

/**
 * Whether a message's headers mark it as an automatic reply.
 *
 * @param array<string,string> $headers  lower-cased header name => value
 */
function emailIsAutoReply(array $headers): bool
{
    $v = static fn(string $k): string => strtolower(trim((string)($headers[$k] ?? '')));
    $auto = $v('auto-submitted');
    if ($auto !== '' && $auto !== 'no') return true;
    if ($v('x-autoreply') !== '' || $v('x-autorespond') !== '') return true;
    return $v('precedence') === 'auto_reply';
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
 * Whether the importer sends the new-ticket acknowledgement: only for a new ticket,
 * and never to an automatic reply (it would answer the auto-responder, which may
 * answer back).
 */
function inboundShouldAcknowledge(bool $isNewTicket, bool $isAutoReply): bool
{
    return $isNewTicket && !$isAutoReply;
}
