<?php
/**
 * Basic IMAP / SMTP Mailbox Helper Functions
 *
 * Provides read (IMAP) + send (SMTP) operations for "basic" mailboxes that
 * authenticate with a plain username + password instead of OAuth — a hosting /
 * cPanel inbox, Fastmail, Zoho, etc.
 *
 * Mirrors the shape of includes/mailbox_graph.php (Microsoft) and includes/gmail.php
 * (Google): every message is normalised to the SAME Graph-like array structure so the
 * downstream import code in check_mailbox_email.php needs no provider-specific handling.
 *
 * Reading uses includes/imap_client.php, a small IMAP client in plain PHP - NOT
 * PHP's `imap_*` extension, which PHP 8.4 removed and Debian 13 cannot build
 * (GH #257, #259, #50). Sending uses a minimal, dependency-free SMTP client
 * (fsockopen) - the repo vendors no mail library, so a small self-contained
 * sender is proportional; the reader is the same idea. The message itself (HTML body, inline
 * images, attachments) is built by includes/mime_message.php, shared with the
 * Gmail send path. See the Basic-IMAP-Mailboxes wiki page for the design.
 */

require_once __DIR__ . '/encryption.php';
require_once __DIR__ . '/mime_message.php';
require_once __DIR__ . '/imap_client.php';
require_once __DIR__ . '/mail_ports.php';

/**
 * Open (and cache within the request) a signed-in connection with a folder selected.
 * The cache lets the inbound poll reuse one connection across the fetch and the
 * per-email post-actions rather than signing in for each.
 *
 * GH #257, #259: this no longer needs PHP's imap extension. includes/imap_client.php
 * speaks the protocol itself, so it works the same in Docker, on Windows and on Linux.
 *
 * @param array $mailbox  the target_mailboxes row, decrypted (imap_* settings and password, id)
 * @param ?string $folder  the folder to open; null uses the mailbox's email_folder, or INBOX
 */
function imapStreamFor(array $mailbox, ?string $folder = null): ImapClient {
    static $cache = [];

    $folder = $folder ?? ($mailbox['email_folder'] ?: 'INBOX');
    $key = ($mailbox['id'] ?? '0') . '|' . $folder;
    if (isset($cache[$key])) {
        return $cache[$key];
    }

    $client = new ImapClient(
        (string) ($mailbox['imap_server'] ?? ''),
        (int) ($mailbox['imap_port'] ?? 993),
        (string) ($mailbox['imap_encryption'] ?? 'ssl')
    );
    try {
        $client->connect();
        $client->login((string) ($mailbox['imap_username'] ?? ''), (string) ($mailbox['imap_password'] ?? ''));
        $client->select($folder);
    } catch (ImapException $e) {
        // Same prefix as before, so logs and the mailbox screen read the same.
        throw new Exception(str_starts_with($e->getMessage(), 'IMAP') ? $e->getMessage() : 'IMAP connection failed: ' . $e->getMessage());
    }

    $cache[$key] = $client;
    return $client;
}

/**
 * Fetch unread messages, normalised to the Graph-like structure the importer expects.
 * Parts are fetched with BODY.PEEK so messages stay unread until the configured
 * post-import action runs (mark read / delete / move).
 *
 * @param array $mailbox  the target_mailboxes row, decrypted (max_emails_per_check caps how many are read)
 */
function imapGetEmails(array $mailbox): array {
    $client = imapStreamFor($mailbox);
    $max = (int) ($mailbox['max_emails_per_check'] ?? 10);

    // UIDs are stable per folder, so we search and act by UID. Oldest first.
    $uids = array_slice($client->uidSearch('UNSEEN'), 0, $max);

    $emails = [];
    foreach ($uids as $uid) {
        $msg = imapNormaliseMessage($client, $uid, $mailbox);
        if ($msg) {
            $emails[] = $msg;
        }
    }
    return $emails;
}

/**
 * Build one normalised (Graph-like) message array from a UID.
 *
 * TRAP: the MAIL SERVER does the MIME parsing. BODYSTRUCTURE describes the parts
 * and BODY.PEEK[1.2] fetches one; ENVELOPE gives the addresses already split.
 * Nothing here reads raw MIME - that is what keeps the client small (see the
 * developer guide, "Why the server parses the message").
 *
 * @param ImapClient $client  the signed-in connection from imapStreamFor()
 * @param int $uid  the message's IMAP UID
 * @param array $mailbox  the target_mailboxes row (its id makes the fallback de-duplication key)
 */
function imapNormaliseMessage(ImapClient $client, int $uid, array $mailbox): ?array {
    // The two reply headers too (GH #120) - PEEK, so nothing is marked read.
    $item = $client->uidFetch($uid, '(UID ENVELOPE BODYSTRUCTURE INTERNALDATE BODY.PEEK[HEADER.FIELDS (REFERENCES IN-REPLY-TO)])');
    if (empty($item['ENVELOPE'])) {
        return null;
    }
    $env = imapEnvelope($item['ENVELOPE']);

    $subject = $env['subject'] !== '' ? $env['subject'] : '(No Subject)';

    $fromName = '';
    $fromAddress = '';
    if (!empty($env['from'])) {
        $fromAddress = $env['from'][0]['emailAddress']['address'];
        $fromName = $env['from'][0]['emailAddress']['name'];
    }

    // The Date header, else when the server received it, else now.
    $ts = $env['date'] !== '' ? strtotime($env['date']) : false;
    if ($ts === false && !empty($item['INTERNALDATE'])) {
        $ts = strtotime((string) $item['INTERNALDATE']);
    }
    $received = gmdate('Y-m-d\TH:i:s\Z', $ts === false ? time() : $ts);

    // Walk the structure once for the body (prefer HTML) and any attachments.
    $html = '';
    $plain = '';
    $attachments = [];
    $structure = imapBodyStructure($item['BODYSTRUCTURE'] ?? null);
    imapWalkStructure($client, $uid, $structure, '', $html, $plain, $attachments);

    if ($html !== '') {
        $body = $html;
    } elseif ($plain !== '') {
        $body = nl2br(htmlspecialchars($plain));
    } else {
        $body = '';
    }

    // Globally-unique dedup key: the Message-ID header (falls back to a
    // mailbox-scoped UID so two mailboxes can't collide on the same UID number).
    $dedupId = $env['message_id'] !== ''
        ? $env['message_id']
        : ('imap-' . ($mailbox['id'] ?? '0') . '-' . $uid);

    // GH #120: the conversation's root, from References / In-Reply-To.
    require_once __DIR__ . '/email_threading.php';
    [$references, $inReplyTo] = imapReplyHeaders($item);
    $threadKey = emailImapThreadKey($references, $inReplyTo, $env['message_id']);

    return [
        // 'id' carries the UID — imapHandleAfterProcessing acts on it.
        'id' => (string) $uid,
        'thread_key' => $threadKey,
        'in_reply_to' => preg_match('/<[^<>\s]+>/', $inReplyTo, $irt) ? $irt[0] : null,
        // Distinct, globally-unique key used for de-duplication / exchange_message_id.
        'internet_message_id' => $dedupId,
        'subject' => $subject,
        'from' => [
            'emailAddress' => [
                'name' => $fromName,
                'address' => $fromAddress,
            ],
        ],
        'toRecipients' => $env['to'],
        'ccRecipients' => $env['cc'],
        'receivedDateTime' => $received,
        'bodyPreview' => mb_substr(trim(strip_tags($body)), 0, 255),
        'body' => [
            'contentType' => 'html',
            'content' => $body,
        ],
        'hasAttachments' => !empty($attachments),
        // Pre-loaded, Graph-shaped attachments so saveEmailToDatabase can store them
        // without a second round-trip (IMAP can't re-fetch a part after we disconnect).
        'attachments_inline' => $attachments,
        'importance' => 'normal',
        'isRead' => false,
    ];
}

/**
 * Walk a message's structure (from imapBodyStructure()), collecting decoded
 * HTML/plain body text and Graph-shaped attachment entries. Each part is fetched
 * by its part number with BODY.PEEK, so nothing is marked read.
 *
 * @param ImapClient $client  the signed-in connection
 * @param int $uid  the message's IMAP UID
 * @param array $part  one node of the imapBodyStructure() tree
 * @param string $partNo  the IMAP part number of $part, e.g. '1.2'; '' for the top of the message
 * @param string $html  collects the decoded HTML body, by reference
 * @param string $plain  collects the decoded plain-text body, by reference
 * @param array $attachments  collects Graph-shaped fileAttachment arrays, by reference
 */
function imapWalkStructure(ImapClient $client, int $uid, array $part, string $partNo, string &$html, string &$plain, array &$attachments): void {
    $type = $part['type'];
    $subtype = $part['subtype'];

    // multipart/* — recurse into children (part numbers 1, 1.1, 1.2, ...).
    if ($type === 'multipart') {
        foreach ($part['parts'] as $i => $sub) {
            $subNo = ($partNo === '') ? (string) ($i + 1) : $partNo . '.' . ($i + 1);
            imapWalkStructure($client, $uid, $sub, $subNo, $html, $plain, $attachments);
        }
        return;
    }

    // For a single-part message the body lives at part "1".
    $fetchNo = ($partNo === '') ? '1' : $partNo;

    $filename = imapPartFilename($part);
    $disposition = $part['disposition'];

    // An attachment if it has a filename, an attachment/inline disposition, or is
    // not text - unless it is the main text/plain or text/html body.
    $isBodyText = ($type === 'text' && in_array($subtype, ['plain', 'html'], true) && $filename === '');
    $isDisposed = in_array($disposition, ['attachment', 'inline'], true);
    if (!$isBodyText && ($filename !== '' || $isDisposed || $type !== 'text')) {
        $data = imapDecodeTransfer(imapFetchPart($client, $uid, $fetchNo), $part['encoding']);
        if ($data === '') {
            return;
        }
        $contentId = $part['id'] !== null && $part['id'] !== '' ? trim($part['id'], '<>') : null;
        $attachments[] = [
            '@odata.type' => '#microsoft.graph.fileAttachment',
            'id' => $fetchNo,
            'name' => $filename !== '' ? $filename : 'attachment',
            'contentType' => $type . '/' . ($subtype !== '' ? $subtype : 'octet-stream'),
            'contentBytes' => base64_encode($data),
            'isInline' => $disposition === 'inline' || $contentId !== null,
            'contentId' => $contentId,
            'size' => strlen($data),
        ];
        return;
    }

    // text/plain or text/html body part.
    if ($type === 'text') {
        $data = imapDecodeTransfer(imapFetchPart($client, $uid, $fetchNo), $part['encoding']);
        $data = imapCharsetToUtf8($data, $part['params']['charset'] ?? 'UTF-8');
        if ($subtype === 'html') {
            $html .= $data;
        } elseif ($subtype === 'plain') {
            $plain .= $data;
        }
    }
}

/**
 * One part of a message, still transfer-encoded, without marking the message read.
 *
 * @param ImapClient $client  the signed-in connection
 * @param int $uid  the message's IMAP UID
 * @param string $partNo  the part number, e.g. '1' or '2.1'
 */
function imapFetchPart(ImapClient $client, int $uid, string $partNo): string {
    $item = $client->uidFetch($uid, 'BODY.PEEK[' . $partNo . ']');
    // The server echoes the section WITHOUT .PEEK: BODY[2.1].
    return (string) ($item['BODY[' . $partNo . ']'] ?? '');
}

/**
 * Apply the post-import (or post-reject) action to a message by UID.
 * Mirrors handleEmailAfterProcessing()'s action vocabulary for Microsoft/Google.
 *
 * @param array $mailbox  the target_mailboxes row, decrypted
 * @param int $uid  the message's IMAP UID
 * @param string $action  'mark_read', 'move_to_folder', 'move_to_deleted' or 'delete'; anything else deletes
 * @param ?string $folderName  the folder to move to for 'move_to_folder'; null or '' deletes instead
 */
function imapHandleAfterProcessing(array $mailbox, int $uid, string $action, ?string $folderName = null): int {
    $client = imapStreamFor($mailbox);

    switch ($action) {
        case 'mark_read':
            $client->uidAddFlags($uid, ['\\Seen']);
            return 200;

        case 'move_to_folder':
            if ($folderName) {
                try {
                    $client->uidMove($uid, $folderName);
                } catch (ImapException $e) {
                    throw new Exception('IMAP move to "' . $folderName . '" failed: ' . $e->getMessage());
                }
                return 200;
            }
            // No folder given — fall through to delete.
            // no break
        case 'move_to_deleted':
        case 'delete':
        default:
            $client->uidDelete($uid);
            return 200;
    }
}

/**
 * Send an HTML email via SMTP (with auth). Minimal, dependency-free.
 *
 * $to / $cc are semicolon/comma separated address strings. $parts is the list of
 * attachments and inline images, Graph-shaped — see includes/mime_message.php.
 * Before GH #158 there was no $parts, and an analyst's attachment never left.
 *
 * @param array $mailbox  the target_mailboxes row, decrypted (smtp_* settings, falling back to the imap_* login)
 * @param string $to  recipient addresses, separated by ; or ,
 * @param string $cc  copy addresses, separated by ; or ,; '' for none
 * @param string $subject  the subject line
 * @param string $htmlBody  the HTML body
 * @param array $parts  attachments and inline images, Graph-shaped - see includes/mime_message.php
 * @param bool $saveCopy  false for mail that must never be filed in Sent (internal notifications) -
 *                        the same rule as saveToSentItems on Microsoft 365. A copy is only saved when
 *                        the mailbox has "Save a copy of sent mail" on (GH #270); see imapSaveSentCopy().
 */
function imapSmtpSend(array $mailbox, string $to, string $cc, string $subject, string $htmlBody, array $parts = [], bool $saveCopy = true): void {
    imapSentCopyOutcome(false);   // forget the last send's outcome
    $host = $mailbox['smtp_server'] ?? '';
    $port = (int) ($mailbox['smtp_port'] ?? 587);
    $enc  = strtolower($mailbox['smtp_encryption'] ?? 'tls');

    // Sending credentials, falling back to the reading ones. A provider that
    // issues one login for IMAP and another for SMTP is common enough to be
    // worth its own pair of columns; a mailbox that uses the same account for
    // both — and every mailbox saved before those columns existed — leaves them
    // blank and lands here exactly as it did before.
    //
    // ⚠️ Blank means "same as IMAP", NOT "send without authenticating". The
    // AUTH LOGIN below is still skipped when the resolved username is empty, but
    // an IMAP mailbox always has one, so an unauthenticated relay is not
    // reachable through these fields and the help text does not claim it is.
    $user = trim((string) ($mailbox['smtp_username'] ?? ''));
    $pass = (string) ($mailbox['smtp_password'] ?? '');
    if ($user === '') {
        $user = $mailbox['imap_username'] ?? '';
        $pass = $mailbox['imap_password'] ?? '';
    }
    $from = $mailbox['target_mailbox'] ?? $user;

    if ($host === '') {
        throw new Exception('No SMTP server configured for this mailbox.');
    }

    $toList = imapSplitAddresses($to);
    $ccList = imapSplitAddresses($cc);
    if (empty($toList)) {
        throw new Exception('No valid recipient address to send to.');
    }

    $transport = ($enc === 'ssl') ? 'ssl://' : 'tcp://';
    // The app's one TLS policy (includes/ssl.php): verify, with the CA bundle PHP
    // on Windows lacks. Before #257 this verified with no bundle at all.
    $context = imapStreamContext();
    $errno = 0;
    $errstr = '';
    // TRAP: every failure below names its STEP, and a port/encryption mismatch says
    // what to change (includes/mail_ports.php). Before 4.11.0 STARTTLS on port 465
    // waited 30 seconds for a greeting that never comes and reported only
    // 'expected 220, got ""' - which told nobody that SSL was the fix.
    $hint = mailPortMismatch('smtp', $port, $enc);
    $fp = @stream_socket_client($transport . $host . ':' . $port, $errno, $errstr, 30, STREAM_CLIENT_CONNECT, $context);
    if (!$fp) {
        throw new Exception('SMTP connection to ' . $host . ':' . $port . ' failed: ' . $errstr . ' (' . $errno . ')' . ($hint ? ' ' . $hint : ''));
    }
    stream_set_timeout($fp, 30);

    try {
        try {
            imapSmtpExpect($fp, [220]);
        } catch (SmtpReplyException $e) {
            throw new Exception($e->reply === ''
                ? $host . ':' . $port . ' accepted the connection but never said hello (no SMTP greeting). '
                    . ($hint ?? 'Check the port and the encryption setting - they usually go together: 465 with SSL, 587 with STARTTLS.')
                : 'The SMTP server refused the connection: ' . $e->reply);
        }
        $ehloHost = imapSmtpClientHostname($from);
        imapSmtpCommand($fp, 'EHLO ' . $ehloHost, [250]);

        // STARTTLS upgrade for the 'tls' setting.
        if ($enc === 'tls') {
            try {
                imapSmtpCommand($fp, 'STARTTLS', [220]);
            } catch (SmtpReplyException $e) {
                throw new Exception('The SMTP server does not offer STARTTLS: ' . $e->reply . ($hint ? ' ' . $hint : ''));
            }
            $crypto = STREAM_CRYPTO_METHOD_TLS_CLIENT;
            if (defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT')) {
                $crypto |= STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_1_CLIENT;
            }
            if (!@stream_socket_enable_crypto($fp, true, $crypto)) {
                throw new Exception('SMTP STARTTLS negotiation failed - the server\'s certificate could not be verified, or it offers no TLS version this PHP accepts.');
            }
            imapSmtpCommand($fp, 'EHLO ' . $ehloHost, [250]);
        }

        // AUTH LOGIN (username + password, each base64).
        if ($user !== '') {
            try {
                imapSmtpCommand($fp, 'AUTH LOGIN', [334]);
                imapSmtpCommand($fp, base64_encode($user), [334]);
                imapSmtpCommand($fp, base64_encode($pass), [235]);
            } catch (SmtpReplyException $e) {
                throw new Exception('SMTP sign-in failed: ' . $e->reply);
            }
        }

        try {
            imapSmtpCommand($fp, 'MAIL FROM:<' . $from . '>', [250]);
        } catch (SmtpReplyException $e) {
            throw new Exception('The SMTP server will not send as ' . $from . ': ' . $e->reply);
        }
        foreach (array_merge($toList, $ccList) as $rcpt) {
            try {
                imapSmtpCommand($fp, 'RCPT TO:<' . $rcpt . '>', [250, 251]);
            } catch (SmtpReplyException $e) {
                throw new Exception('The SMTP server refused the recipient ' . $rcpt . ': ' . $e->reply);
            }
        }

        imapSmtpCommand($fp, 'DATA', [354]);
        $message = mimeBuildMessage([
            'from'     => $from,
            'fromName' => $mailbox['name'] ?? '',
            'to'       => $toList,
            'cc'       => $ccList,
            'subject'  => $subject,
            'html'     => $htmlBody,
            'parts'    => $parts,
            'envelope' => true,   // nothing downstream adds Date / Message-ID for us
        ]);
        // Dot-stuff and terminate. The copy for Sent is the message BEFORE stuffing:
        // stuffing is SMTP's transport encoding, and APPEND stores the message as it is.
        $raw = $message;
        $message = preg_replace('/^\./m', '..', $message);
        fwrite($fp, $message . "\r\n.\r\n");
        imapSmtpExpect($fp, [250]);

        imapSmtpCommand($fp, 'QUIT', [221]);
    } finally {
        fclose($fp);
    }

    // Sent - only now, so a failed send is never filed as sent. Best effort: never throws.
    if ($saveCopy && !empty($mailbox['save_sent_copy'])) {
        imapSaveSentCopy($mailbox, $raw, implode(', ', array_merge($toList, $ccList)), $subject);
    }
}

/**
 * What happened to the last send's copy for Sent (GH #270) - for the mailbox Test button,
 * which reports it. Call with an array to record, false to forget, nothing to read.
 *
 * @param array|false|null $set  ['folder' => ?string, 'error' => ?string] to record; false to clear
 * @return ?array the last outcome, or null when no copy was attempted
 */
function imapSentCopyOutcome($set = null): ?array {
    static $last = null;
    if ($set === false) {
        $last = null;
    } elseif (is_array($set)) {
        $last = $set;
    }
    return $last;
}

/**
 * The folder this mailbox's server uses for sent mail: the one LIST marks \Sent
 * (RFC 6154 SPECIAL-USE - Dovecot, Gmail, Exchange, most hosts), else the first
 * folder with a common Sent name. Null when there is neither.
 *
 * @param ImapClient $client  a signed-in connection
 */
function imapFindSentFolder(ImapClient $client): ?string {
    $folders = $client->listFolders();
    foreach ($folders as $f) {
        if (in_array('\\SENT', array_map('strtoupper', $f['flags']), true)) {
            return $f['name'];
        }
    }
    // No SPECIAL-USE: the names servers actually use, most common first.
    foreach (['Sent', 'Sent Items', 'Sent Messages', 'Sent Mail', 'INBOX.Sent', 'INBOX/Sent', 'INBOX.Sent Items'] as $want) {
        foreach ($folders as $f) {
            if (strcasecmp($f['name'], $want) === 0) {
                return $f['name'];
            }
        }
    }
    return null;
}

/**
 * Saves a copy of an email this mailbox has just sent into its Sent folder over IMAP
 * (GH #270), so people reading the same mailbox in Thunderbird or webmail see what the
 * service desk sent. Microsoft 365 does this with saveToSentItems; Gmail files sent
 * mail itself; a Basic IMAP mailbox sends through SMTP, which files nothing.
 *
 * The folder is the mailbox's sent_folder (matched to the server's own spelling), or
 * when that is blank, the one the server marks \Sent. Stored as read (\Seen).
 *
 * BEST EFFORT, NEVER THROWS: the email has already gone. A failure is written to the
 * outbound email log (route 'sent_copy') with the reason, and the send still counts
 * as sent. A second IMAP connection per send, closed straight away - so attachments
 * are uploaded twice (wiki Basic-IMAP-Mailboxes says so; the in-app help does not).
 *
 * TRAP: off by default, per mailbox (save_sent_copy). Some SMTP servers already file
 * sent mail themselves (Gmail SMTP, Microsoft 365 SMTP AUTH, some cPanel hosts), and
 * turning this on there gives two copies of everything.
 *
 * @param array $mailbox  the target_mailboxes row, decrypted (imap_* login, sent_folder)
 * @param string $raw  the message exactly as built, CRLF, not dot-stuffed
 * @param string $to  the recipients, for the log row if it fails
 * @param string $subject  the subject, for the log row if it fails
 * @return array{folder:?string, error:?string}
 */
function imapSaveSentCopy(array $mailbox, string $raw, string $to, string $subject): array {
    $folder = null;
    try {
        $client = new ImapClient(
            (string) ($mailbox['imap_server'] ?? ''),
            (int) ($mailbox['imap_port'] ?? 993),
            (string) ($mailbox['imap_encryption'] ?? 'ssl')
        );
        $client->connect();
        try {
            $client->login((string) ($mailbox['imap_username'] ?? ''), (string) ($mailbox['imap_password'] ?? ''));
            $want = trim((string) ($mailbox['sent_folder'] ?? ''));
            if ($want === '') {
                $folder = imapFindSentFolder($client);
                if ($folder === null) {
                    throw new Exception('the server marks no folder as Sent - name the folder in the mailbox settings');
                }
            } else {
                // The server's own spelling, so "sent" finds "Sent" and "Inbox/Sent" finds "INBOX.Sent".
                $folder = $want;
                foreach ($client->listFolders() as $f) {
                    $alt = $f['delimiter'] !== '' ? str_replace($f['delimiter'], '/', $f['name']) : $f['name'];
                    if (strcasecmp($f['name'], $want) === 0 || strcasecmp($alt, $want) === 0) {
                        $folder = $f['name'];
                        break;
                    }
                }
            }
            $client->append($folder, $raw, ['\\Seen']);
        } finally {
            $client->logout();
        }
        return imapSentCopyOutcome(['folder' => $folder, 'error' => null]);
    } catch (Throwable $e) {
        $why = 'Sent, but the copy could not be saved' . ($folder !== null ? ' to "' . $folder . '"' : '') . ': ' . $e->getMessage();
        error_log('IMAP sent copy (mailbox ' . ($mailbox['id'] ?? '?') . '): ' . $why);
        try {
            require_once __DIR__ . '/email_log.php';
            emailLogFailed(function_exists('connectToDatabase') ? connectToDatabase() : null, $mailbox, 'sent_copy', $to, $subject, $why);
        } catch (Throwable) { /* the log must never break a send */ }
        return imapSentCopyOutcome(['folder' => $folder, 'error' => $why]);
    }
}



/**
 * Derive a sensible EHLO hostname from the sender address.
 *
 * @param string $from  the sender address; its domain is used, or 'localhost' when it has none
 */
function imapSmtpClientHostname(string $from): string {
    $domain = substr(strrchr($from, '@') ?: '', 1);
    return $domain !== '' ? $domain : 'localhost';
}

/**
 * Split a "a@b; c@d, e@f" string into a list of valid addresses.
 *
 * @param string $raw  addresses separated by ; or ,; invalid ones are dropped
 */
function imapSplitAddresses(string $raw): array {
    $out = [];
    foreach (preg_split('/[;,]/', $raw) as $addr) {
        $addr = trim($addr);
        if ($addr !== '' && filter_var($addr, FILTER_VALIDATE_EMAIL)) {
            $out[] = $addr;
        }
    }
    return $out;
}

/**
 * Send one SMTP command line and assert the reply code.
 *
 * @param mixed $fp  the open SMTP socket
 * @param string $command  the command line to send, without the CRLF
 * @param array $expected  the reply codes that count as success; anything else throws
 */
function imapSmtpCommand($fp, string $command, array $expected): string {
    fwrite($fp, $command . "\r\n");
    return imapSmtpExpect($fp, $expected);
}

/**
 * Read a (possibly multi-line) SMTP reply and assert its status code.
 *
 * @param mixed $fp  the open SMTP socket
 * @param array $expected  the reply codes that count as success; anything else throws
 */
function imapSmtpExpect($fp, array $expected): string {
    $response = '';
    while (($line = fgets($fp, 515)) !== false) {
        $response .= $line;
        // Continuation lines have a '-' as the 4th char; the final line has a space.
        if (strlen($line) < 4 || $line[3] === ' ') {
            break;
        }
    }
    $code = (int) substr($response, 0, 3);
    if (!in_array($code, $expected, true)) {
        throw new SmtpReplyException('SMTP error: expected ' . implode('/', $expected) . ', got "' . trim($response) . '"', trim($response));
    }
    return $response;
}

/** An SMTP reply that was not the one expected. $reply is the server's own words ('' when it said nothing), so a caller can name the step that failed. */
class SmtpReplyException extends Exception
{
    /**
     * @param string $message  the full message, as before
     * @param string $reply  what the server actually said, trimmed; '' for silence
     */
    public function __construct(string $message, public string $reply = '')
    {
        parent::__construct($message);
    }
}

/**
 * List every folder on the server, names decoded from IMAP's modified UTF-7,
 * so a folder called "Support/Übergabe" is reported back as it reads.
 *
 * @param ImapClient $client  a signed-in connection for the mailbox
 * @param array $mailbox  the target_mailboxes row (unused now; kept so callers need not change)
 * @return array<int, array{name:string, delimiter:string}>
 */
function imapListFolders(ImapClient $client, array $mailbox): array {
    $out = [];
    foreach ($client->listFolders() as $f) {
        // \Noselect entries are namespace placeholders, not folders you can open.
        if (in_array('\\NOSELECT', array_map('strtoupper', $f['flags']), true)) {
            continue;
        }
        $out[] = ['name' => $f['name'], 'delimiter' => $f['delimiter']];
    }
    return $out;
}

/**
 * Verify a folder exists in a basic IMAP mailbox and report its message counts.
 *
 * ⚠️ WHY THIS EXISTS. verify_mailbox_folder.php was written for Microsoft Graph
 * and gated on an OAuth token before doing anything else. A basic IMAP mailbox
 * never has one — it authenticates with the stored username + password — so
 * Verify answered "Mailbox is not authenticated" for EVERY IMAP mailbox on every
 * install, no matter how correct the credentials were, without making a single
 * network call.
 *
 * The connection is opened through imapStreamFor() — the SAME helper the inbound
 * poll uses — so Verify cannot pass a folder that reading then rejects. That
 * divergence is exactly what GH #77 was on the Graph side.
 *
 * A missing folder is reported separately from a failed login, and names the
 * folders that DO exist: on servers with an "INBOX." personal namespace the real
 * name is "INBOX.Support", and "Support" alone genuinely will not open.
 *
 * @param array $mailbox  the target_mailboxes row, decrypted
 * @param string $folderName  the folder as typed in the mailbox settings
 * @return array{displayName:string, totalItemCount:int|null, unreadItemCount:int}
 * @throws Exception naming the folder, or explaining the connection failure.
 */
function imapVerifyFolder(array $mailbox, string $folderName): array {
    $folderName = trim($folderName);
    if ($folderName === '') {
        throw new Exception('No mail folder configured.');
    }

    // Connect against INBOX, which always exists, so a failure here is
    // unambiguously credentials or TLS rather than a bad folder name.
    $client = imapStreamFor($mailbox, 'INBOX');

    $folders = imapListFolders($client, $mailbox);

    $match = null;
    foreach ($folders as $folder) {
        $candidates = [$folder['name']];
        if ($folder['delimiter'] !== '') {
            // Accept "Inbox/Support" for a server that names it "INBOX.Support".
            $candidates[] = str_replace($folder['delimiter'], '/', $folder['name']);
        }
        foreach ($candidates as $candidate) {
            if (strcasecmp($candidate, $folderName) === 0) {
                $match = $folder;
                break 2;
            }
        }
    }

    if ($match === null) {
        $names = array_slice(array_column($folders, 'name'), 0, 12);
        throw new Exception(
            'Folder "' . $folderName . '" was not found in this mailbox.'
            . ($names ? ' Folders on the server: ' . implode(', ', $names) . '.' : '')
        );
    }

    // Open the matched folder BY THE NAME THE SERVER USES, which is the name
    // reading will use too. Counts come from the selected folder rather than a
    // separate STATUS call, so what is reported is the folder actually opened.
    $folderClient = imapStreamFor($mailbox, $match['name']);

    return [
        'displayName'     => $match['name'],
        'totalItemCount'  => $folderClient->messageCount(),
        'unreadItemCount' => count($folderClient->uidSearch('UNSEEN')),
    ];
}
/**
 * References and In-Reply-To from a FETCH of BODY.PEEK[HEADER.FIELDS (REFERENCES IN-REPLY-TO)] (GH #120).
 *
 * The server answers under the key BODY[HEADER.FIELDS (REFERENCES IN-REPLY-TO)] - without PEEK,
 * and some servers change the case or spacing - so the key is found by its start. Folded header
 * lines are unfolded before reading.
 *
 * @param array $item  one uidFetch() result
 * @return array{0:string,1:string}  [References, In-Reply-To], '' when absent
 */
function imapReplyHeaders(array $item): array
{
    $raw = '';
    foreach ($item as $k => $v) {
        if (is_string($k) && stripos($k, 'BODY[HEADER.FIELDS') === 0 && is_string($v)) { $raw = $v; break; }
    }
    $raw = preg_replace('/\r?\n[ \t]+/', ' ', $raw);
    $refs = preg_match('/^References:\s*(.*)$/mi', $raw, $m) ? trim($m[1]) : '';
    $irt  = preg_match('/^In-Reply-To:\s*(.*)$/mi', $raw, $m) ? trim($m[1]) : '';
    return [$refs, $irt];
}
