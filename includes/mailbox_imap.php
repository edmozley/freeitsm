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
 * Reading uses PHP's `imap_*` extension. Sending uses a minimal, dependency-free
 * SMTP client (fsockopen) — the repo vendors no mail library, and outbound is
 * HTML-only (matching the Gmail send path), so a small self-contained sender is
 * proportional. See the Basic-IMAP-Mailboxes wiki page for the design.
 */

require_once __DIR__ . '/encryption.php';

/** True when the PHP IMAP extension is loaded (self-hosters must enable it). */
function imapExtensionAvailable(): bool {
    return function_exists('imap_open');
}

/**
 * Build the IMAP connection reference string, e.g. {mail.example.com:993/imap/ssl}INBOX.
 * Encryption: 'ssl' → implicit TLS, 'tls' → STARTTLS, anything else → plaintext.
 */
function imapMailboxRef(array $mailbox, ?string $folder = null): string {
    $host = $mailbox['imap_server'] ?? '';
    $port = (int) ($mailbox['imap_port'] ?? 993);
    $enc  = strtolower($mailbox['imap_encryption'] ?? 'ssl');
    $folder = $folder ?? ($mailbox['email_folder'] ?: 'INBOX');

    $flags = '/imap';
    if ($enc === 'ssl') {
        $flags .= '/ssl';
    } elseif ($enc === 'tls') {
        $flags .= '/tls';
    } else {
        $flags .= '/notls';
    }

    return '{' . $host . ':' . $port . $flags . '}' . $folder;
}

/**
 * Open (and cache within the request) an IMAP stream for a mailbox/folder.
 * The cache lets the inbound poll reuse one authenticated connection across the
 * fetch + per-email post-actions rather than logging in repeatedly.
 */
function imapStreamFor(array $mailbox, ?string $folder = null) {
    static $cache = [];

    if (!imapExtensionAvailable()) {
        throw new Exception('The PHP IMAP extension is not enabled on this server. Enable it (php.ini: extension=imap) to use Basic IMAP mailboxes.');
    }

    $folder = $folder ?? ($mailbox['email_folder'] ?: 'INBOX');
    $key = ($mailbox['id'] ?? '0') . '|' . $folder;
    if (isset($cache[$key]) && $cache[$key] !== false) {
        return $cache[$key];
    }

    $ref = imapMailboxRef($mailbox, $folder);
    $user = $mailbox['imap_username'] ?? '';
    $pass = $mailbox['imap_password'] ?? '';

    // OP_SECURE forbids plaintext auth over an unencrypted link. Suppress the
    // native warning and surface imap_last_error() as a clean exception instead.
    $stream = @imap_open($ref, $user, $pass, 0, 1);
    if ($stream === false) {
        $err = imap_last_error() ?: 'unknown error';
        imap_errors(); // drain the error stack so it doesn't leak into later output
        throw new Exception('IMAP connection failed: ' . $err);
    }

    $cache[$key] = $stream;
    return $stream;
}

/**
 * Fetch unread messages, normalised to the Graph-like structure the importer expects.
 * Bodies are fetched with FT_PEEK so messages stay UNSEEN until the configured
 * post-import action runs (mark read / delete / move).
 */
function imapGetEmails(array $mailbox): array {
    $stream = imapStreamFor($mailbox);
    $max = (int) ($mailbox['max_emails_per_check'] ?? 10);

    // UIDs are stable per-mailbox, so we search + act by UID.
    $uids = imap_search($stream, 'UNSEEN', SE_UID);
    imap_errors();
    if (!$uids) {
        return [];
    }

    // Oldest first, capped at the per-check limit.
    sort($uids);
    $uids = array_slice($uids, 0, $max);

    $emails = [];
    foreach ($uids as $uid) {
        $msg = imapNormaliseMessage($stream, (int) $uid, $mailbox);
        if ($msg) {
            $emails[] = $msg;
        }
    }
    return $emails;
}

/**
 * Build one normalised (Graph-like) message array from a UID.
 */
function imapNormaliseMessage($stream, int $uid, array $mailbox): ?array {
    $overview = imap_fetch_overview($stream, (string) $uid, FT_UID);
    if (empty($overview)) {
        return null;
    }
    $ov = $overview[0];

    $subject = isset($ov->subject) ? imapDecodeHeader($ov->subject) : '(No Subject)';
    if ($subject === '') {
        $subject = '(No Subject)';
    }

    // Sender: prefer the parsed address list for a clean name/address split.
    $fromName = '';
    $fromAddress = '';
    $fromList = imapParseAddressList($ov->from ?? '');
    if (!empty($fromList)) {
        $fromAddress = $fromList[0]['emailAddress']['address'];
        $fromName = $fromList[0]['emailAddress']['name'];
    }

    $toRecipients = imapParseAddressList($ov->to ?? '');
    $ccRecipients = imapParseAddressList($ov->cc ?? '');

    $received = isset($ov->date) && $ov->date
        ? gmdate('Y-m-d\TH:i:s\Z', strtotime($ov->date))
        : gmdate('Y-m-d\TH:i:s\Z');

    // Walk the MIME structure once for the body (prefer HTML) and any attachments.
    $html = '';
    $plain = '';
    $attachments = [];
    $structure = imap_fetchstructure($stream, $uid, FT_UID);
    if ($structure) {
        imapWalkStructure($stream, $uid, $structure, '', $html, $plain, $attachments);
    }

    if ($html !== '') {
        $body = $html;
        $bodyType = 'html';
    } elseif ($plain !== '') {
        $body = nl2br(htmlspecialchars($plain));
        $bodyType = 'html';
    } else {
        $body = '';
        $bodyType = 'html';
    }

    // Globally-unique dedup key: the Message-ID header (falls back to a
    // mailbox-scoped UID so two mailboxes can't collide on the same UID number).
    $messageId = isset($ov->message_id) ? trim($ov->message_id) : '';
    $dedupId = $messageId !== ''
        ? $messageId
        : ('imap-' . ($mailbox['id'] ?? '0') . '-' . $uid);

    return [
        // 'id' carries the UID — imapHandleAfterProcessing acts on it.
        'id' => (string) $uid,
        // Distinct, globally-unique key used for de-duplication / exchange_message_id.
        'internet_message_id' => $dedupId,
        'subject' => $subject,
        'from' => [
            'emailAddress' => [
                'name' => $fromName,
                'address' => $fromAddress,
            ],
        ],
        'toRecipients' => $toRecipients,
        'ccRecipients' => $ccRecipients,
        'receivedDateTime' => $received,
        'bodyPreview' => mb_substr(trim(strip_tags($body)), 0, 255),
        'body' => [
            'contentType' => $bodyType,
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
 * Recursively walk an imap_fetchstructure() tree, accumulating decoded HTML/plain
 * body text and Graph-shaped attachment entries.
 */
function imapWalkStructure($stream, int $uid, $part, string $partNo, string &$html, string &$plain, array &$attachments): void {
    $type = $part->type ?? 0; // 0=text 1=multipart 2=message 3=application 5=image ...
    $encoding = $part->encoding ?? 0;
    $subtype = strtoupper($part->subtype ?? '');

    $params = [];
    if (!empty($part->parameters)) {
        foreach ($part->parameters as $p) {
            $params[strtolower($p->attribute)] = $p->value;
        }
    }
    if (!empty($part->dparameters)) {
        foreach ($part->dparameters as $p) {
            $params['d_' . strtolower($p->attribute)] = $p->value;
        }
    }

    // multipart/* — recurse into children (part numbers 1, 1.1, 1.2, ...).
    if ($type === 1) {
        if (!empty($part->parts)) {
            foreach ($part->parts as $i => $sub) {
                $subNo = ($partNo === '') ? (string) ($i + 1) : $partNo . '.' . ($i + 1);
                imapWalkStructure($stream, $uid, $sub, $subNo, $html, $plain, $attachments);
            }
        }
        return;
    }

    // For a single-part message the body lives at part "1".
    $fetchNo = ($partNo === '') ? '1' : $partNo;

    $filename = imapDecodeHeader($params['d_filename'] ?? $params['name'] ?? '');
    $isDisposedAttachment = !empty($part->ifdisposition)
        && in_array(strtolower($part->disposition), ['attachment', 'inline'], true);

    // Treat as attachment if it has a filename or an attachment/inline disposition,
    // and it isn't the main text/plain or text/html body.
    $isBodyText = ($type === 0 && in_array($subtype, ['PLAIN', 'HTML'], true) && $filename === '');
    if (!$isBodyText && ($filename !== '' || $isDisposedAttachment || $type !== 0)) {
        $raw = imap_fetchbody($stream, $uid, $fetchNo, FT_UID | FT_PEEK);
        if ($raw === false || $raw === '') {
            return;
        }
        $data = imapDecodePart($raw, $encoding);

        $typeMap = [0 => 'text', 1 => 'multipart', 2 => 'message', 3 => 'application', 4 => 'audio', 5 => 'image', 6 => 'video', 7 => 'other'];
        $primary = $typeMap[$type] ?? 'application';
        $contentType = $primary . '/' . strtolower($part->subtype ?? 'octet-stream');

        $attachments[] = [
            '@odata.type' => '#microsoft.graph.fileAttachment',
            'id' => $fetchNo,
            'name' => $filename !== '' ? $filename : 'attachment',
            'contentType' => $contentType,
            'contentBytes' => base64_encode($data),
            'isInline' => (!empty($part->ifdisposition) && strtolower($part->disposition) === 'inline') || !empty($part->ifid),
            'contentId' => !empty($part->id) ? trim($part->id, '<>') : null,
            'size' => strlen($data),
        ];
        return;
    }

    // text/plain or text/html body part.
    if ($type === 0) {
        $raw = imap_fetchbody($stream, $uid, $fetchNo, FT_UID | FT_PEEK);
        if ($raw === false) {
            return;
        }
        $data = imapDecodePart($raw, $encoding);
        $data = imapToUtf8($data, $params['charset'] ?? 'UTF-8');
        if ($subtype === 'HTML') {
            $html .= $data;
        } elseif ($subtype === 'PLAIN') {
            $plain .= $data;
        }
    }
}

/** Decode a transfer-encoded body part (base64 / quoted-printable / raw). */
function imapDecodePart(string $data, int $encoding): string {
    switch ($encoding) {
        case 3: // base64
            return base64_decode($data) ?: '';
        case 4: // quoted-printable
            return quoted_printable_decode($data);
        default: // 7bit / 8bit / binary / other
            return $data;
    }
}

/** Convert a string to UTF-8 from the part's declared charset (best effort). */
function imapToUtf8(string $data, string $charset): string {
    $charset = trim($charset) ?: 'UTF-8';
    if (strcasecmp($charset, 'UTF-8') === 0 || strcasecmp($charset, 'US-ASCII') === 0) {
        return $data;
    }
    if (function_exists('mb_convert_encoding')) {
        $converted = @mb_convert_encoding($data, 'UTF-8', $charset);
        if ($converted !== false && $converted !== '') {
            return $converted;
        }
    }
    if (function_exists('iconv')) {
        $converted = @iconv($charset, 'UTF-8//IGNORE', $data);
        if ($converted !== false) {
            return $converted;
        }
    }
    return $data;
}

/** Decode a MIME-encoded header value (e.g. =?UTF-8?B?...?=) to a UTF-8 string. */
function imapDecodeHeader(string $value): string {
    if ($value === '') {
        return '';
    }
    if (!function_exists('imap_mime_header_decode')) {
        return $value;
    }
    $out = '';
    foreach (imap_mime_header_decode($value) as $part) {
        $charset = $part->charset ?? 'default';
        $text = $part->text ?? '';
        $out .= ($charset === 'default' || strcasecmp($charset, 'UTF-8') === 0)
            ? $text
            : imapToUtf8($text, $charset);
    }
    return trim($out);
}

/**
 * Parse an RFC 2822 address-list header into the Graph-like recipient shape.
 * Uses imap_rfc822_parse_adrlist when available; falls back to a light regex.
 */
function imapParseAddressList(string $raw): array {
    $raw = trim($raw);
    if ($raw === '') {
        return [];
    }

    $result = [];
    if (function_exists('imap_rfc822_parse_adrlist')) {
        foreach (imap_rfc822_parse_adrlist($raw, '') as $addr) {
            if (empty($addr->mailbox) || empty($addr->host) || $addr->host === '.SYNTAX-ERROR.') {
                continue;
            }
            $result[] = [
                'emailAddress' => [
                    'name' => isset($addr->personal) ? imapDecodeHeader($addr->personal) : '',
                    'address' => $addr->mailbox . '@' . $addr->host,
                ],
            ];
        }
        return $result;
    }

    // Fallback parser: "Name <a@b>, c@d"
    foreach (preg_split('/,(?=(?:[^"]*"[^"]*")*[^"]*$)/', $raw) as $part) {
        $part = trim($part);
        if (preg_match('/^(.*?)\s*<(.+?)>$/', $part, $m)) {
            $result[] = ['emailAddress' => ['name' => imapDecodeHeader(trim($m[1], '" ')), 'address' => $m[2]]];
        } elseif (filter_var($part, FILTER_VALIDATE_EMAIL)) {
            $result[] = ['emailAddress' => ['name' => '', 'address' => $part]];
        }
    }
    return $result;
}

/**
 * Apply the post-import (or post-reject) action to a message by UID.
 * Mirrors handleEmailAfterProcessing()'s action vocabulary for Microsoft/Google.
 */
function imapHandleAfterProcessing(array $mailbox, int $uid, string $action, ?string $folderName = null): int {
    $stream = imapStreamFor($mailbox);

    switch ($action) {
        case 'mark_read':
            imap_setflag_full($stream, (string) $uid, '\\Seen', ST_UID);
            imap_errors();
            return 200;

        case 'move_to_folder':
            if ($folderName) {
                if (!imap_mail_move($stream, (string) $uid, $folderName, CP_UID)) {
                    $err = imap_last_error() ?: 'move failed';
                    imap_errors();
                    throw new Exception('IMAP move to "' . $folderName . '" failed: ' . $err);
                }
                imap_expunge($stream);
                return 200;
            }
            // No folder given — fall through to delete.
            // no break
        case 'move_to_deleted':
        case 'delete':
        default:
            imap_delete($stream, (string) $uid, FT_UID);
            imap_expunge($stream);
            imap_errors();
            return 200;
    }
}

/**
 * Send an HTML email via SMTP (with auth). Minimal, dependency-free.
 *
 * $to / $cc are semicolon/comma separated address strings. Outbound attachments
 * are not supported (parity with the Gmail send path) — replies are HTML only.
 */
function imapSmtpSend(array $mailbox, string $to, string $cc, string $subject, string $htmlBody): void {
    $host = $mailbox['smtp_server'] ?? '';
    $port = (int) ($mailbox['smtp_port'] ?? 587);
    $enc  = strtolower($mailbox['smtp_encryption'] ?? 'tls');
    $user = $mailbox['imap_username'] ?? '';
    $pass = $mailbox['imap_password'] ?? '';
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
    $context = stream_context_create([
        'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
    ]);
    $errno = 0;
    $errstr = '';
    $fp = @stream_socket_client($transport . $host . ':' . $port, $errno, $errstr, 30, STREAM_CLIENT_CONNECT, $context);
    if (!$fp) {
        throw new Exception('SMTP connection to ' . $host . ':' . $port . ' failed: ' . $errstr . ' (' . $errno . ')');
    }
    stream_set_timeout($fp, 30);

    try {
        imapSmtpExpect($fp, [220]);
        $ehloHost = imapSmtpClientHostname($from);
        imapSmtpCommand($fp, 'EHLO ' . $ehloHost, [250]);

        // STARTTLS upgrade for the 'tls' setting.
        if ($enc === 'tls') {
            imapSmtpCommand($fp, 'STARTTLS', [220]);
            $crypto = STREAM_CRYPTO_METHOD_TLS_CLIENT;
            if (defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT')) {
                $crypto |= STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_1_CLIENT;
            }
            if (!@stream_socket_enable_crypto($fp, true, $crypto)) {
                throw new Exception('SMTP STARTTLS negotiation failed.');
            }
            imapSmtpCommand($fp, 'EHLO ' . $ehloHost, [250]);
        }

        // AUTH LOGIN (username + password, each base64).
        if ($user !== '') {
            imapSmtpCommand($fp, 'AUTH LOGIN', [334]);
            imapSmtpCommand($fp, base64_encode($user), [334]);
            imapSmtpCommand($fp, base64_encode($pass), [235]);
        }

        imapSmtpCommand($fp, 'MAIL FROM:<' . $from . '>', [250]);
        foreach (array_merge($toList, $ccList) as $rcpt) {
            imapSmtpCommand($fp, 'RCPT TO:<' . $rcpt . '>', [250, 251]);
        }

        imapSmtpCommand($fp, 'DATA', [354]);
        $message = imapSmtpBuildMessage($from, $mailbox['name'] ?? '', $toList, $ccList, $subject, $htmlBody);
        // Dot-stuff and terminate.
        $message = preg_replace('/^\./m', '..', $message);
        fwrite($fp, $message . "\r\n.\r\n");
        imapSmtpExpect($fp, [250]);

        imapSmtpCommand($fp, 'QUIT', [221]);
    } finally {
        fclose($fp);
    }
}

/** Assemble the raw RFC 2822 HTML message. */
function imapSmtpBuildMessage(string $from, string $fromName, array $toList, array $ccList, string $subject, string $htmlBody): string {
    $headers = [];
    $fromHeader = $fromName !== ''
        ? imapSmtpEncodeHeader($fromName) . ' <' . $from . '>'
        : $from;
    $headers[] = 'From: ' . $fromHeader;
    $headers[] = 'To: ' . implode(', ', $toList);
    if (!empty($ccList)) {
        $headers[] = 'Cc: ' . implode(', ', $ccList);
    }
    $headers[] = 'Subject: ' . imapSmtpEncodeHeader($subject);
    $headers[] = 'MIME-Version: 1.0';
    $headers[] = 'Content-Type: text/html; charset=UTF-8';
    $headers[] = 'Content-Transfer-Encoding: base64';

    $encodedBody = chunk_split(base64_encode($htmlBody), 76, "\r\n");

    return implode("\r\n", $headers) . "\r\n\r\n" . $encodedBody;
}

/** RFC 2047 encode a header value if it contains non-ASCII. */
function imapSmtpEncodeHeader(string $value): string {
    if (preg_match('/[\x80-\xFF]/', $value)) {
        return '=?UTF-8?B?' . base64_encode($value) . '?=';
    }
    return $value;
}

/** Derive a sensible EHLO hostname from the sender address. */
function imapSmtpClientHostname(string $from): string {
    $domain = substr(strrchr($from, '@') ?: '', 1);
    return $domain !== '' ? $domain : 'localhost';
}

/** Split a "a@b; c@d, e@f" string into a list of valid addresses. */
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

/** Send one SMTP command line and assert the reply code. */
function imapSmtpCommand($fp, string $command, array $expected): string {
    fwrite($fp, $command . "\r\n");
    return imapSmtpExpect($fp, $expected);
}

/** Read a (possibly multi-line) SMTP reply and assert its status code. */
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
        throw new Exception('SMTP error: expected ' . implode('/', $expected) . ', got "' . trim($response) . '"');
    }
    return $response;
}
