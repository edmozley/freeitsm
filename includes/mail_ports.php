<?php
/**
 * Which encryption a well-known mail port expects - one rule, used in three places:
 * the SMTP and IMAP error messages, the mailbox's "!" warnings (mailbox_health.php),
 * and the mailbox Test button (api/tickets/test_mailbox_send.php).
 *
 * Why it exists: a port and an encryption setting that do not belong together fail
 * in the least helpful way possible. STARTTLS on port 465 opens a plain connection to
 * a server waiting for a TLS handshake; neither side speaks, and all the old error
 * could say was 'expected 220, got ""'. SSL on 587 fails the other way round, with a
 * TLS error about a "wrong version number". Both are one dropdown away from working.
 *
 * Only the four ports whose meaning is fixed by convention are judged. Anything else -
 * 25, 2525, a provider's own port - is left alone, because guessing would be wrong.
 */

/**
 * The encryption a well-known port expects, or null when the port does not say.
 *
 * @param string $kind  'smtp' or 'imap'
 * @param int $port  the port number
 * @return ?string 'ssl' (TLS from the first byte) or 'tls' (STARTTLS)
 */
function mailPortExpectedEncryption(string $kind, int $port): ?string
{
    $table = [
        'smtp' => [465 => 'ssl', 587 => 'tls'],
        'imap' => [993 => 'ssl', 143 => 'tls'],
    ];
    return $table[strtolower($kind)][$port] ?? null;
}

/**
 * A sentence saying the port and encryption do not match, and what to change - or null when they do (or the port does not say).
 *
 * 'none' on a STARTTLS port is NOT flagged: plain 143 or 587 is a real, if unwise,
 * choice that works with some local servers. 'none' on an SSL port is, because that
 * can never work.
 *
 * @param string $kind  'smtp' or 'imap'
 * @param int $port  the port number
 * @param string $encryption  'ssl', 'tls' (STARTTLS) or 'none'
 */
function mailPortMismatch(string $kind, int $port, string $encryption): ?string
{
    $expected = mailPortExpectedEncryption($kind, $port);
    $encryption = strtolower(trim($encryption));
    if ($expected === null || $encryption === $expected) {
        return null;
    }
    if ($expected === 'tls' && $encryption === 'none') {
        return null;
    }
    $label = strtoupper($kind);
    $names = ['ssl' => 'SSL', 'tls' => 'STARTTLS', 'none' => 'no encryption'];
    $other = $kind === 'smtp'
        ? ($expected === 'ssl' ? 'port 587 with STARTTLS' : 'port 465 with SSL')
        : ($expected === 'ssl' ? 'port 143 with STARTTLS' : 'port 993 with SSL');
    return 'Port ' . $port . ' expects ' . $names[$expected] . ', not ' . ($names[$encryption] ?? $encryption)
        . ' - set ' . $label . ' encryption to ' . $names[$expected] . ' (or use ' . $other . ').';
}
