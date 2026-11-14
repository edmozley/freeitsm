<?php
/**
 * Self-service system emails (account verification, etc.).
 *
 * The ticket email path (includes/template_email.php) is bound to a ticket +
 * a template. Account verification has neither, so this sends a plain system
 * email from the first active mailbox, reusing the same provider-branched send
 * logic (Gmail API / Microsoft Graph / IMAP-SMTP).
 */
require_once __DIR__ . '/mailbox_provider.php';   // MailboxProvider
require_once __DIR__ . '/encryption.php';       // decryptMailboxRow
require_once __DIR__ . '/template_email.php';   // templateGetValidAccessToken, templateSendViaGraph
require_once __DIR__ . '/session_security.php';  // requestScheme() — proxy-aware (GH #152)

/** First active, send-capable mailbox (has credentials), decrypted. Null if none. */
function ssGetSendingMailbox(PDO $conn): ?array {
    require_once __DIR__ . '/mailbox_graph.php';   // mailboxCanSend
    $rows = $conn->query("SELECT * FROM target_mailboxes WHERE is_active = 1 ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $row) {
        $m = decryptMailboxRow($row);
        // mailboxCanSend() knows an app-only mailbox is sendable on its client secret
        // alone; testing for stored token_data would skip one that hasn't polled yet.
        if (mailboxCanSend($m)) return $m;
    }
    return null;
}

/**
 * The most a system email may carry in attachments, all files together (5.3.0).
 *
 * TRAP: 3 MB, not 10. Microsoft Graph refuses a sendMail request over 4 MB, and the
 * files travel inside it as base64, which adds a third - so 3 MB of files is the
 * most that sends on EVERY provider. A bigger cap would work on SMTP and Gmail and
 * fail only on Microsoft 365 mailboxes, which is the worst kind of limit to find.
 * The same 4 MB ceiling is why api/tickets/send_email.php caps a thread's pictures
 * (INLINE_THREAD_BUDGET). Going past it needs Graph's upload sessions, not a bigger
 * number here.
 */
const SYSTEM_EMAIL_ATTACH_MAX_BYTES = 3 * 1024 * 1024;

/** At most this many files on one system email. */
const SYSTEM_EMAIL_ATTACH_MAX_FILES = 10;

/**
 * Content types a system email attachment keeps. Anything else goes out as
 * application/octet-stream - still delivered, just not labelled by us as
 * something a mail client should open in place.
 */
const SYSTEM_EMAIL_ATTACH_TYPES = [
    'application/pdf',
    'text/csv',
    'text/plain',
    'image/png',
    'image/jpeg',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
];

/**
 * A file name safe to put in an email: no folder part, no control characters
 * (so no CR/LF to start a header of its own), no quotes or backslashes, at most
 * 150 characters with the extension kept. '' becomes 'attachment'.
 *
 * @param string $name  the file name as the caller gave it, possibly with a path
 */
function ssAttachmentFilename(string $name): string {
    $name = str_replace('\\', '/', $name);
    $name = (string)substr($name, (int)strrpos('/' . $name, '/'));   // basename, also for "a/b/" and Windows paths
    $name = preg_replace('/[\x00-\x1F\x7F"\\\\]/', '', $name);
    $name = trim($name, " .\t");
    if (mb_strlen($name, 'UTF-8') > 150) {
        $ext = pathinfo($name, PATHINFO_EXTENSION);
        $ext = ($ext !== '' && mb_strlen($ext, 'UTF-8') <= 10) ? '.' . $ext : '';
        $name = mb_substr($name, 0, 150 - mb_strlen($ext, 'UTF-8'), 'UTF-8') . $ext;
    }
    return $name !== '' ? $name : 'attachment';
}

/**
 * Check a list of attachments and turn it into the Graph fileAttachment shape that
 * all three providers already take (Graph directly; SMTP and Gmail through
 * includes/mime_message.php, the same parts an analyst's reply sends).
 *
 * Throws InvalidArgumentException, with a sentence fit to show, when the list is
 * malformed, holds an empty file, has too many files or is over
 * SYSTEM_EMAIL_ATTACH_MAX_BYTES in total. Callers that take files from a browser
 * should call this first to report the reason; ssSendSystemEmail() calls it again.
 *
 * @param array $attachments  list of ['name' => string, 'mime' => string, 'content' => raw bytes]
 * @return array  list of ['@odata.type', 'name', 'contentType', 'contentBytes' (base64)]
 */
function ssSystemEmailAttachmentParts(array $attachments): array {
    if (count($attachments) > SYSTEM_EMAIL_ATTACH_MAX_FILES) {
        throw new InvalidArgumentException('An email can carry at most ' . SYSTEM_EMAIL_ATTACH_MAX_FILES . ' files.');
    }
    $parts = [];
    $total = 0;
    foreach (array_values($attachments) as $i => $a) {
        if (!is_array($a) || !isset($a['content']) || !is_string($a['content'])) {
            throw new InvalidArgumentException('Attachment ' . ($i + 1) . ' has no content.');
        }
        $name = ssAttachmentFilename((string)($a['name'] ?? ''));
        $size = strlen($a['content']);
        if ($size === 0) {
            throw new InvalidArgumentException('The file "' . $name . '" is empty.');
        }
        $total += $size;
        if ($total > SYSTEM_EMAIL_ATTACH_MAX_BYTES) {
            throw new InvalidArgumentException('The attachments are too large to email (the limit is '
                . round(SYSTEM_EMAIL_ATTACH_MAX_BYTES / 1048576) . ' MB in total).');
        }
        $mime = strtolower(trim((string)($a['mime'] ?? '')));
        $parts[] = [
            '@odata.type'  => '#microsoft.graph.fileAttachment',
            'name'         => $name,
            'contentType'  => in_array($mime, SYSTEM_EMAIL_ATTACH_TYPES, true) ? $mime : 'application/octet-stream',
            'contentBytes' => base64_encode($a['content']),
        ];
    }
    return $parts;
}

/**
 * The Microsoft Graph sendMail body for a system email. Its own function so a test
 * can check the shape without sending anything.
 *
 * @param string $to  the recipient's email address
 * @param string $subject  the email subject
 * @param string $htmlBody  the email body as HTML
 * @param array $parts  attachments from ssSystemEmailAttachmentParts(); [] for none
 */
function ssSystemEmailGraphMessage(string $to, string $subject, string $htmlBody, array $parts = []): array {
    $message = [
        'subject'      => $subject,
        'body'         => ['contentType' => 'HTML', 'content' => $htmlBody],
        'toRecipients' => [['emailAddress' => ['address' => $to]]],
    ];
    if ($parts) {
        $message['attachments'] = $parts;   // left out when empty: exactly what was sent before 5.3.0
    }
    return ['message' => $message, 'saveToSentItems' => true];
}

/**
 * Send a system email to one recipient. Returns true on success, false if no
 * mailbox is configured or the send fails (caller decides what to do — the
 * verification flow fails closed rather than pretend it sent).
 *
 * 5.3.0: optional attachments - files that travel IN the email, for installs that
 * are not reachable from the internet, where a "download" link cannot be opened.
 * Refused (false, with the reason in the send log) when they fail
 * ssSystemEmailAttachmentParts()'s rules.
 *
 * @param string $to  the recipient's email address
 * @param string $subject  the email subject
 * @param string $htmlBody  the email body as HTML, already escaped by the caller
 * @param string $route  a key of EMAIL_LOG_ROUTES for the send log, e.g. 'portal' or 'training'
 * @param array $attachments  list of ['name' => string, 'mime' => string, 'content' => raw bytes]; [] for none
 */
function ssSendSystemEmail(PDO $conn, string $to, string $subject, string $htmlBody, string $route = 'portal', array $attachments = []): bool {
    require_once __DIR__ . '/email_log.php';
    // $route defaults to what every existing caller meant, so none of them
    // change. Training reminders pass 'training' — they are the one route that
    // sends to many people at once, and mixing them into "Portal / system"
    // alongside password resets makes the send log unable to answer the only
    // question anybody brings to it about them.

    try {
        $parts = ssSystemEmailAttachmentParts($attachments);
    } catch (InvalidArgumentException $e) {
        emailLogFailed($conn, null, $route, $to, $subject, $e->getMessage());
        return false;
    }

    $mailbox = ssGetSendingMailbox($conn);
    if (!$mailbox) {
        // Worth a row of its own: "no mailbox could send this" is a configuration
        // problem, and it is invisible to the person who never got their email.
        emailLogFailed($conn, null, $route, $to, $subject,
            'No active mailbox is able to send (none configured, or none authenticated)');
        return false;
    }
    $provider = MailboxProvider::fromMailbox($mailbox);
    try {
        if ($provider === MailboxProvider::Imap) {
            require_once __DIR__ . '/mailbox_imap.php';
            imapSmtpSend($mailbox, $to, '', $subject, $htmlBody, $parts);
        } elseif ($provider === MailboxProvider::Google) {
            $tokenData = json_decode(preg_replace('/[\x00-\x1F\x7F]/', '', (string)$mailbox['token_data']), true);
            if (!$tokenData || !isset($tokenData['access_token'])) {
                emailLogFailed($conn, $mailbox, $route, $to, $subject, 'Mailbox has no usable stored token');
                return false;
            }
            require_once __DIR__ . '/gmail.php';
            $accessToken = gmailGetValidAccessToken($conn, $mailbox, $tokenData);
            if (!$accessToken) {
                emailLogFailed($conn, $mailbox, $route, $to, $subject, 'Could not obtain an access token for this mailbox');
                return false;
            }
            gmailSendEmail($accessToken, $to, $subject, $htmlBody, $mailbox['target_mailbox'] ?? '', '', $parts);
        } else {
            // microsoft (delegated or app-only) via Graph — templateGraphContext() resolves
            // both the token source and the /me vs /users/<addr> endpoint from auth_mode.
            $graph = templateGraphContext($conn, $mailbox);
            if (!$graph) {
                emailLogFailed($conn, $mailbox, $route, $to, $subject,
                    'Could not obtain an access token for this mailbox '
                    . '(check the mailbox is authenticated, and that its authentication mode matches its stored token)');
                return false;
            }
            templateSendViaGraph($graph['token'], ssSystemEmailGraphMessage($to, $subject, $htmlBody, $parts), $graph['base']);
        }
        emailLogSent($conn, $mailbox, $route, $to, $subject);
        return true;
    } catch (Exception $e) {
        error_log('ssSendSystemEmail failed: ' . $e->getMessage());
        emailLogFailed($conn, $mailbox, $route, $to, $subject, $e->getMessage());
        return false;
    }
}

/**
 * Absolute URL to the self-service email-verification page for a raw token.
 *
 * @param string $rawToken  the unhashed verification token that goes in the link
 */
function ssBuildVerifyUrl(string $rawToken): string {
    $scheme = requestScheme();
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $docRoot = rtrim(str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
    $appRoot = rtrim(str_replace('\\', '/', realpath(__DIR__ . '/..')), '/');
    $appPath = ($docRoot && str_starts_with($appRoot, $docRoot)) ? substr($appRoot, strlen($docRoot)) : '';
    return $scheme . '://' . $host . $appPath . '/self-service/verify-email.php?token=' . urlencode($rawToken);
}

/**
 * Absolute URL to the self-service password-reset page for a raw token.
 *
 * Built exactly as ssBuildVerifyUrl() does rather than from BASE_URL: these two
 * links are sent by the same code to the same people, and a reset that lands on
 * a different host from the confirmation is the sort of thing that gets read as
 * a phishing attempt.
 *
 * @param string $rawToken  the unhashed password-reset token that goes in the link
 */
function ssBuildResetUrl(string $rawToken): string {
    $scheme = requestScheme();
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $docRoot = rtrim(str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
    $appRoot = rtrim(str_replace('\\', '/', realpath(__DIR__ . '/..')), '/');
    $appPath = ($docRoot && str_starts_with($appRoot, $docRoot)) ? substr($appRoot, strlen($docRoot)) : '';
    return $scheme . '://' . $host . $appPath . '/self-service/reset-password.php?token=' . urlencode($rawToken);
}

/**
 * HTML body for the password-reset email.
 *
 * ⚠️ Deliberately says "set" rather than "reset", because for most of the people
 * who will receive it this is the FIRST password they have ever had: a contact
 * created by the service desk has no password at all, and telling them to
 * "reset" one they never set reads as a mistake or a scam (GH #134).
 *
 * @param string $displayName  the recipient's name for the greeting; '' greets them as 'there'
 * @param string $link  the reset link from ssBuildResetUrl()
 * @param int $validHours  how long the link lasts, in hours, as stated in the email
 */
function ssResetEmailBody(string $displayName, string $link, int $validHours = 1): string {
    $name = htmlspecialchars($displayName !== '' ? $displayName : 'there', ENT_QUOTES, 'UTF-8');
    $safeLink = htmlspecialchars($link, ENT_QUOTES, 'UTF-8');
    $hours = $validHours === 1 ? '1 hour' : $validHours . ' hours';
    return '<div style="font-family:Segoe UI,Arial,sans-serif;font-size:15px;color:#2c3e50;line-height:1.6">'
        . '<p>Hi ' . $name . ',</p>'
        . '<p>Someone (hopefully you) asked to set a password for your self-service account. '
        . 'Choose one by clicking the button below &mdash; the link is valid for ' . $hours . ' and can only be used once.</p>'
        . '<p style="margin:24px 0"><a href="' . $safeLink . '" '
        . 'style="background:#2d6a4f;color:#fff;text-decoration:none;padding:11px 22px;border-radius:8px;font-weight:600">Set my password</a></p>'
        . '<p style="font-size:13px;color:#5a6c7d">If you didn\'t request this, you can safely ignore this email &mdash; '
        . 'your account is not changed until the link above is used, and the link stops working once it is.</p>'
        . '<p style="font-size:13px;color:#5a6c7d">Or paste this link into your browser:<br>' . $safeLink . '</p>'
        . '</div>';
}

/**
 * HTML body for the verification email.
 *
 * @param string $displayName  the recipient's name for the greeting; '' greets them as 'there'
 * @param string $link  the verification link from ssBuildVerifyUrl()
 */
function ssVerifyEmailBody(string $displayName, string $link): string {
    $name = htmlspecialchars($displayName !== '' ? $displayName : 'there', ENT_QUOTES, 'UTF-8');
    $safeLink = htmlspecialchars($link, ENT_QUOTES, 'UTF-8');
    return '<div style="font-family:Segoe UI,Arial,sans-serif;font-size:15px;color:#2c3e50;line-height:1.6">'
        . '<p>Hi ' . $name . ',</p>'
        . '<p>Someone (hopefully you) asked to set up a self-service account with this email address. '
        . 'Confirm it by clicking the button below — the link is valid for 24 hours.</p>'
        . '<p style="margin:24px 0"><a href="' . $safeLink . '" '
        . 'style="background:#2d6a4f;color:#fff;text-decoration:none;padding:11px 22px;border-radius:8px;font-weight:600">Confirm my account</a></p>'
        . '<p style="font-size:13px;color:#5a6c7d">If you didn\'t request this, you can safely ignore this email — '
        . 'no account is created and no password is set until the link above is used.</p>'
        . '<p style="font-size:13px;color:#5a6c7d">Or paste this link into your browser:<br>' . $safeLink . '</p>'
        . '</div>';
}
