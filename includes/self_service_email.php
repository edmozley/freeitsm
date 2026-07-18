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
    $rows = $conn->query("SELECT * FROM target_mailboxes WHERE is_active = 1 ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $row) {
        $m = decryptMailboxRow($row);
        $provider = $m['provider'] ?? 'microsoft';
        if ($provider === 'imap') {
            if (!empty($m['smtp_server']) && !empty($m['imap_username'])) return $m;
        } elseif (!empty($m['token_data'])) {
            return $m;
        }
    }
    return null;
}

/**
 * Send a system email to one recipient. Returns true on success, false if no
 * mailbox is configured or the send fails (caller decides what to do — the
 * verification flow fails closed rather than pretend it sent).
 */
function ssSendSystemEmail(PDO $conn, string $to, string $subject, string $htmlBody): bool {
    $mailbox = ssGetSendingMailbox($conn);
    if (!$mailbox) return false;
    $provider = $mailbox['provider'] ?? 'microsoft';
    try {
        if ($provider === 'imap') {
            require_once __DIR__ . '/mailbox_imap.php';
            imapSmtpSend($mailbox, $to, '', $subject, $htmlBody);
            return true;
        }
        $tokenData = json_decode(preg_replace('/[\x00-\x1F\x7F]/', '', (string)$mailbox['token_data']), true);
        if (!$tokenData || !isset($tokenData['access_token'])) return false;

        if ($provider === 'google') {
            require_once __DIR__ . '/gmail.php';
            $accessToken = gmailGetValidAccessToken($conn, $mailbox, $tokenData);
            if (!$accessToken) return false;
            gmailSendEmail($accessToken, $to, $subject, $htmlBody, $mailbox['target_mailbox'] ?? '');
            return true;
        }

        // microsoft (delegated or app-only) via Graph
        $accessToken = templateGetValidAccessToken($conn, $mailbox, $tokenData);
        if (!$accessToken) return false;
        templateSendViaGraph($accessToken, [
            'message' => [
                'subject'      => $subject,
                'body'         => ['contentType' => 'HTML', 'content' => $htmlBody],
                'toRecipients' => [['emailAddress' => ['address' => $to]]],
            ],
            'saveToSentItems' => true,
        ]);
        return true;
    } catch (Exception $e) {
        error_log('ssSendSystemEmail failed: ' . $e->getMessage());
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
