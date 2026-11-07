<?php
/**
 * Send one email FROM a ticket mailbox, through whatever that mailbox uses
 * (Microsoft Graph, Gmail, or IMAP/SMTP), and record it in the send log.
 *
 * Written for GH #170 (the oversized-email alert and auto-reply), and kept
 * separate from includes/template_email.php on purpose: a template email is about
 * a TICKET (merge codes, the SDREF subject, a copy filed on the ticket). This is a
 * plain message about the mailbox itself, which may have no ticket at all - a
 * rejected email never made one.
 *
 * Never throws. Returns null on success, or the reason it could not send.
 */

require_once __DIR__ . '/mailbox_provider.php';   // MailboxProvider
require_once __DIR__ . '/email_log.php';

/**
 * Sends one HTML email from a mailbox through its own provider (IMAP/SMTP, Gmail or
 * Microsoft Graph) and logs it.
 *
 * Returns null on success, or the error message when sending failed.
 *
 * @param array $mailbox  the target_mailboxes row, decrypted; its provider ('imap', 'google', else Microsoft) picks the route
 * @param string $to  one recipient address
 * @param string $subject  the subject line
 * @param string $htmlBody  the HTML body
 * @param string $route  what the send log records it as, e.g. 'oversize_alert' or 'oversize_reply'
 */
function mailboxSendSimple(PDO $conn, array $mailbox, string $to, string $subject, string $htmlBody, string $route, ?int $ticketId = null): ?string
{
    $provider = MailboxProvider::fromMailbox($mailbox);
    try {
        if ($provider === MailboxProvider::Imap) {
            require_once __DIR__ . '/mailbox_imap.php';
            imapSmtpSend($mailbox, $to, '', $subject, $htmlBody);
        } elseif ($provider === MailboxProvider::Google) {
            require_once __DIR__ . '/gmail.php';
            $cleaned = preg_replace('/[\x00-\x1F\x7F]/', '', (string)($mailbox['token_data'] ?? ''));
            $tokenData = $cleaned !== '' ? json_decode($cleaned, true) : null;
            if (!$tokenData || !isset($tokenData['access_token'])) throw new Exception('The mailbox is not signed in.');
            $token = gmailGetValidAccessToken($conn, $mailbox, $tokenData);
            if (!$token) throw new Exception('Could not obtain an access token for this mailbox.');
            gmailSendEmail($token, $to, $subject, $htmlBody, $mailbox['target_mailbox'] ?? '');
        } else {
            require_once __DIR__ . '/template_email.php';
            $graph = templateGraphContext($conn, $mailbox);
            if (!$graph) throw new Exception('Could not obtain an access token for this mailbox.');
            templateSendViaGraph($graph['token'], [
                'message' => [
                    'subject' => $subject,
                    'body' => ['contentType' => 'HTML', 'content' => $htmlBody],
                    'toRecipients' => [['emailAddress' => ['address' => $to]]],
                ],
                'saveToSentItems' => true,
            ], $graph['base']);
        }
        emailLogSent($conn, $mailbox, $route, $to, $subject, $ticketId);
        return null;
    } catch (Throwable $e) {
        emailLogFailed($conn, $mailbox, $route, $to, $subject, $e->getMessage(), $ticketId);
        return $e->getMessage();
    }
}

/**
 * The mailbox the Test button should send with: the saved one, with whatever is
 * typed in the form laid over it (4.11.0).
 *
 * Basic IMAP is tested with the FORM's values, so a setting can be checked before it
 * is saved. Microsoft and Google send with a sign-in token that only a saved,
 * connected mailbox has, so they are tested as saved - and say so.
 *
 * TRAP - the stored password is only reused for the server and username it was saved
 * for. Test sends the password to whatever SMTP server is typed. Reusing the stored
 * one for a CHANGED server would let anybody with mailbox settings point Test at a
 * server of their own and collect the password they are not allowed to see. So a
 * changed server or username needs the password typed. (Save has always let the
 * server be changed; it does not send anything until a real email goes out, and that
 * leaves the change on record.)
 *
 * @param ?array $stored  the saved target_mailboxes row, decrypted; null for a mailbox not saved yet
 * @param array $form  the modal's fields, named as save_mailbox.php takes them
 * @return array{0:?array, 1:?string, 2:bool} [the mailbox to send with, an error to show instead, whether the SAVED settings were used]
 */
function mailboxTestResolve(?array $stored, array $form): array
{
    $provider = strtolower((string)($form['provider'] ?? ($stored['provider'] ?? '')));
    if ($provider !== 'imap') {
        if ($stored === null) {
            return [null, 'Save the mailbox and connect it first - Microsoft 365 and Google mailboxes send with their sign-in, which only a saved mailbox has.', true];
        }
        if (strtolower((string)($stored['provider'] ?? '')) !== $provider) {
            return [null, 'Save the mailbox first - the provider has been changed since it was saved.', true];
        }
        return [$stored, null, true];
    }

    $same = static fn ($a, $b) => strcasecmp(trim((string)$a), trim((string)$b)) === 0;
    $typed = static fn ($p) => !(trim((string)$p) === '' || preg_match('/^\*+/', (string)$p));

    $mb = $stored ?? [];
    foreach (['name', 'target_mailbox', 'imap_server', 'imap_port', 'imap_encryption', 'imap_username',
              'smtp_server', 'smtp_port', 'smtp_encryption'] as $k) {
        if (array_key_exists($k, $form)) {
            $mb[$k] = is_string($form[$k]) ? trim($form[$k]) : $form[$k];
        }
    }
    $mb['provider'] = 'imap';
    if (trim((string)($mb['smtp_server'] ?? '')) === '') {
        return [null, 'Fill in the SMTP server first.', false];
    }
    $serverUnchanged = $stored !== null && $same($stored['smtp_server'] ?? '', $mb['smtp_server'] ?? '');

    // The IMAP login: typed, or the stored one if nothing it goes to has changed.
    if ($typed($form['imap_password'] ?? '')) {
        $mb['imap_password'] = (string)$form['imap_password'];
    } elseif (!($serverUnchanged && $same($stored['imap_username'] ?? '', $mb['imap_username'] ?? '') && ($stored['imap_password'] ?? '') !== '')) {
        $mb['imap_password'] = '';
    }

    $sameAsImap = !array_key_exists('smtp_same_as_imap', $form) ? trim((string)($mb['smtp_username'] ?? '')) === '' : !empty($form['smtp_same_as_imap']);
    if ($sameAsImap) {
        $mb['smtp_username'] = '';
        $mb['smtp_password'] = '';
        if ($mb['imap_password'] === '') {
            return [null, 'Type the password to test - the saved one is only used with the server and username it was saved for.', false];
        }
    } else {
        $mb['smtp_username'] = trim((string)($form['smtp_username'] ?? ($stored['smtp_username'] ?? '')));
        if ($typed($form['smtp_password'] ?? '')) {
            $mb['smtp_password'] = (string)$form['smtp_password'];
        } elseif (!($serverUnchanged && $same($stored['smtp_username'] ?? '', $mb['smtp_username']) && ($stored['smtp_password'] ?? '') !== '')) {
            return [null, 'Type the SMTP password to test - the saved one is only used with the server and username it was saved for.', false];
        }
        if ($mb['smtp_username'] === '') {
            return [null, 'Fill in the SMTP username, or turn on "Use the IMAP login for sending".', false];
        }
    }

    return [$mb, null, false];
}
