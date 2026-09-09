<?php
/**
 * Self-service system emails (account verification, etc.).
 *
 * The ticket email path (includes/template_email.php) is bound to a ticket +
 * a template. Account verification has neither, so this sends a plain system
 * email from the first active mailbox, reusing the same provider-branched send
 * logic (Gmail API / Microsoft Graph / IMAP-SMTP).
 */
require_once __DIR__ . '/encryption.php';       // decryptMailboxRow
require_once __DIR__ . '/template_email.php';   // templateGetValidAccessToken, templateSendViaGraph

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
 * Send a system email to one recipient. Returns true on success, false if no
 * mailbox is configured or the send fails (caller decides what to do — the
 * verification flow fails closed rather than pretend it sent).
 */
function ssSendSystemEmail(PDO $conn, string $to, string $subject, string $htmlBody, string $route = 'portal'): bool {
    require_once __DIR__ . '/email_log.php';
    // $route defaults to what every existing caller meant, so none of them
    // change. Training reminders pass 'training' — they are the one route that
    // sends to many people at once, and mixing them into "Portal / system"
    // alongside password resets makes the send log unable to answer the only
    // question anybody brings to it about them.

    $mailbox = ssGetSendingMailbox($conn);
    if (!$mailbox) {
        // Worth a row of its own: "no mailbox could send this" is a configuration
        // problem, and it is invisible to the person who never got their email.
        emailLogFailed($conn, null, $route, $to, $subject,
            'No active mailbox is able to send (none configured, or none authenticated)');
        return false;
    }
    $provider = $mailbox['provider'] ?? 'microsoft';
    try {
        if ($provider === 'imap') {
            require_once __DIR__ . '/mailbox_imap.php';
            imapSmtpSend($mailbox, $to, '', $subject, $htmlBody);
        } elseif ($provider === 'google') {
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
            gmailSendEmail($accessToken, $to, $subject, $htmlBody, $mailbox['target_mailbox'] ?? '');
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
            templateSendViaGraph($graph['token'], [
                'message' => [
                    'subject'      => $subject,
                    'body'         => ['contentType' => 'HTML', 'content' => $htmlBody],
                    'toRecipients' => [['emailAddress' => ['address' => $to]]],
                ],
                'saveToSentItems' => true,
            ], $graph['base']);
        }
        emailLogSent($conn, $mailbox, $route, $to, $subject);
        return true;
    } catch (Exception $e) {
        error_log('ssSendSystemEmail failed: ' . $e->getMessage());
        emailLogFailed($conn, $mailbox, $route, $to, $subject, $e->getMessage());
        return false;
    }
}

/** Absolute URL to the self-service email-verification page for a raw token. */
function ssBuildVerifyUrl(string $rawToken): string {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $docRoot = rtrim(str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
    $appRoot = rtrim(str_replace('\\', '/', realpath(__DIR__ . '/..')), '/');
    $appPath = ($docRoot && strpos($appRoot, $docRoot) === 0) ? substr($appRoot, strlen($docRoot)) : '';
    return $scheme . '://' . $host . $appPath . '/self-service/verify-email.php?token=' . urlencode($rawToken);
}

/**
 * Absolute URL to the self-service password-reset page for a raw token.
 *
 * Built exactly as ssBuildVerifyUrl() does rather than from BASE_URL: these two
 * links are sent by the same code to the same people, and a reset that lands on
 * a different host from the confirmation is the sort of thing that gets read as
 * a phishing attempt.
 */
function ssBuildResetUrl(string $rawToken): string {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $docRoot = rtrim(str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
    $appRoot = rtrim(str_replace('\\', '/', realpath(__DIR__ . '/..')), '/');
    $appPath = ($docRoot && strpos($appRoot, $docRoot) === 0) ? substr($appRoot, strlen($docRoot)) : '';
    return $scheme . '://' . $host . $appPath . '/self-service/reset-password.php?token=' . urlencode($rawToken);
}

/**
 * HTML body for the password-reset email.
 *
 * ⚠️ Deliberately says "set" rather than "reset", because for most of the people
 * who will receive it this is the FIRST password they have ever had: a contact
 * created by the service desk has no password at all, and telling them to
 * "reset" one they never set reads as a mistake or a scam (GH #134).
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

/** HTML body for the verification email. */
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
