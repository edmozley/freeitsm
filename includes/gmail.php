<?php
/**
 * Gmail API Helper Functions
 *
 * Provides send/read/refresh/delete operations for Google mailboxes.
 * Mirrors the Microsoft Graph API functions used elsewhere.
 */

require_once __DIR__ . '/encryption.php';
require_once __DIR__ . '/mime_message.php';

/**
 * Refresh a Google access token using the refresh token.
 * Returns the updated token data array.
 *
 * @param array $mailbox    the target_mailboxes row, decrypted - id, azure_client_id and azure_client_secret
 *                          (which hold the Google OAuth client for a Google mailbox)
 * @param array $tokenData  the decoded token_data - array{access_token:string,refresh_token:string,expires_at:int}
 */
function gmailRefreshAccessToken(PDO $conn, array $mailbox, array $tokenData): array {
    if (empty($tokenData['refresh_token'])) {
        throw new Exception('No Google refresh token available. Please re-authenticate.');
    }

    $postData = [
        'client_id' => $mailbox['azure_client_id'],
        'client_secret' => $mailbox['azure_client_secret'],
        'refresh_token' => $tokenData['refresh_token'],
        'grant_type' => 'refresh_token'
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://oauth2.googleapis.com/token');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    sslApplyCurl($ch);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if (curl_errno($ch)) {
        $error = curl_error($ch);
        curl_close($ch);
        throw new Exception('cURL error refreshing Google token: ' . $error);
    }

    curl_close($ch);

    if ($httpCode !== 200) {
        throw new Exception('Failed to refresh Google token. HTTP ' . $httpCode);
    }

    $newToken = json_decode($response, true);

    if (!isset($newToken['access_token'])) {
        throw new Exception('Google refresh did not return an access token');
    }

    $tokenData['access_token'] = $newToken['access_token'];
    // Google doesn't always return a new refresh_token — keep the old one
    if (isset($newToken['refresh_token'])) {
        $tokenData['refresh_token'] = $newToken['refresh_token'];
    }
    $tokenData['expires_at'] = time() + ($newToken['expires_in'] ?? 3600);

    // Persist
    $stmt = $conn->prepare("UPDATE target_mailboxes SET token_data = ? WHERE id = ?");
    // Encrypted at rest — see ENCRYPTED_MAILBOX_COLUMNS; decryptMailboxRow() reverses it.
    $stmt->execute([encryptValue(json_encode($tokenData)), $mailbox['id']]);

    return $tokenData;
}

/**
 * Get a valid Google access token, refreshing if expired.
 *
 * @param array $mailbox    the target_mailboxes row, decrypted - passed on to gmailRefreshAccessToken()
 * @param array $tokenData  the decoded token_data; refreshed when expires_at (Unix seconds) is under 5 minutes away
 */
function gmailGetValidAccessToken(PDO $conn, array $mailbox, array $tokenData): string {
    if (isset($tokenData['expires_at']) && $tokenData['expires_at'] < (time() + 300)) {
        $tokenData = gmailRefreshAccessToken($conn, $mailbox, $tokenData);
    }
    return $tokenData['access_token'];
}

/**
 * Send an email via the Gmail API.
 *
 * $to       - recipient email address
 * $subject  - email subject
 * $htmlBody - HTML body content
 * $from     - sender email address (the mailbox address)
 * $cc       - CC addresses, semicolon/comma separated
 * $parts    - attachments and inline images, Graph-shaped (includes/mime_message.php)
 *
 * GH #158: $cc and $parts are new. Before them an analyst's attachments, pasted
 * images and CC list were all silently dropped on a Google mailbox.
 *
 * @param string $accessToken  a valid Google OAuth access token for the mailbox
 * @param string $to           recipient addresses, semicolon/comma separated; invalid ones are dropped
 * @param string $subject      the email subject
 * @param string $htmlBody     the HTML body
 * @param string $from         the sender address (the mailbox address)
 * @param string $cc           CC addresses, semicolon/comma separated; invalid ones are dropped
 * @param array $parts         attachments and inline images, Graph-shaped (includes/mime_message.php)
 */
function gmailSendEmail(string $accessToken, string $to, string $subject, string $htmlBody, string $from = '', string $cc = '', array $parts = []): void {
    // Valid addresses only - the same rule as the SMTP path, and it keeps a stray
    // line break in the To box from ever becoming a header of its own.
    $split = function (string $raw): array {
        return array_values(array_filter(array_map('trim', preg_split('/[;,]/', $raw)), function ($a) {
            return $a !== '' && filter_var($a, FILTER_VALIDATE_EMAIL);
        }));
    };
    $toList = $split($to);
    if (empty($toList)) {
        throw new Exception('No valid recipient address to send to.');
    }
    // Gmail sends to whoever the To / Cc headers name.
    $rawMessage = mimeBuildMessage([
        'from'    => $from,
        'to'      => $toList,
        'cc'      => $split($cc),
        'subject' => $subject,
        'html'    => $htmlBody,
        'parts'   => $parts,
    ]);

    // Base64url encode
    $encoded = rtrim(strtr(base64_encode($rawMessage), '+/', '-_'), '=');

    $payload = json_encode(['raw' => $encoded]);

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://gmail.googleapis.com/gmail/v1/users/me/messages/send');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    sslApplyCurl($ch);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $accessToken,
        'Content-Type: application/json'
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if (curl_errno($ch)) {
        $error = curl_error($ch);
        curl_close($ch);
        throw new Exception('Gmail send cURL error: ' . $error);
    }

    curl_close($ch);

    if ($httpCode !== 200) {
        $errorData = json_decode($response, true);
        $errorMsg = $errorData['error']['message'] ?? 'Unknown error';
        throw new Exception("Gmail API send failed: $errorMsg (HTTP $httpCode)");
    }
}

/**
 * An authenticated Gmail API GET, returning ['code' => int, 'body' => array|null].
 * Every caller was writing the same twelve lines of curl.
 *
 * @param string $accessToken  a valid Google OAuth access token, sent as the Bearer token
 * @param string $url          the full Gmail API URL to GET
 */
function gmailApiGet(string $accessToken, string $url): array {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    sslApplyCurl($ch);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $accessToken]);
    $response = curl_exec($ch);
    $code     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err      = curl_errno($ch) ? curl_error($ch) : null;
    curl_close($ch);
    if ($err !== null) {
        throw new Exception('cURL error talking to Gmail: ' . $err);
    }
    return ['code' => (int) $code, 'body' => json_decode($response, true)];
}

/**
 * Every label on the account, as Gmail returns them (id, name, type, ...).
 *
 * @param string $accessToken  a valid Google OAuth access token for the mailbox
 */
function gmailListLabels(string $accessToken): array {
    $res = gmailApiGet($accessToken, 'https://gmail.googleapis.com/gmail/v1/users/me/labels');
    if ($res['code'] !== 200) {
        throw new Exception('Could not list Gmail labels (HTTP ' . $res['code'] . ').');
    }
    return $res['body']['labels'] ?? [];
}

/**
 * Find one label by display name, case-insensitively. Null when there is no match.
 *
 * @param array $labels  the labels from gmailListLabels() - each array{id:string,name:string,...}
 * @param string $name   the display name to look for; surrounding spaces are ignored
 */
function gmailFindLabel(array $labels, string $name): ?array {
    foreach ($labels as $label) {
        if (strcasecmp((string) ($label['name'] ?? ''), trim($name)) === 0) {
            return $label;
        }
    }
    return null;
}

/**
 * Resolve the configured folder to the label id the message list should be scoped to.
 *
 * ⚠️ Gmail has no folders. It has labels, a message carries several at once, and
 * "in the Inbox" is itself just a label — archiving a message removes INBOX and
 * leaves the rest. So the configured folder IS the list scope.
 *
 * This used to read `labelIds=INBOX` with the configured folder bolted on as an
 * extra `label:` search term, which meant a message had to be in the Inbox AND
 * carry the label. Mail filtered straight past the Inbox — the usual reason to
 * label anything — was never collected, silently and permanently.
 *
 * Scoping by label ID also fixes labels whose names contain spaces: Gmail's
 * `label:` search syntax cannot express "Customer Support" without quoting rules
 * that the old string concatenation did not apply.
 *
 * See the Gmail mail collection developer guide on the wiki.
 *
 * @param string $accessToken  a valid Google OAuth access token for the mailbox
 * @param array $mailbox       the target_mailboxes row; email_folder is the label name, blank meaning INBOX
 * @throws Exception naming the label when the account has no such label.
 */
function gmailResolveListLabelId(string $accessToken, array $mailbox): string {
    $folder = trim((string) ($mailbox['email_folder'] ?? '')) ?: 'INBOX';
    if (strcasecmp($folder, 'INBOX') === 0) {
        return 'INBOX';
    }

    $label = gmailFindLabel(gmailListLabels($accessToken), $folder);
    if ($label === null) {
        // Loudly, rather than quietly returning nothing forever: a label that
        // matches nothing used to read as "no new mail", which is indistinguishable
        // from a quiet inbox and so was never investigated.
        throw new Exception('Gmail label "' . $folder . '" was not found in this account.');
    }
    return (string) $label['id'];
}

/**
 * Fetch unread emails from Gmail.
 * Returns an array of messages normalised to the same shape as Graph API results.
 *
 * @param string $accessToken  a valid Google OAuth access token for the mailbox
 * @param array $mailbox       the target_mailboxes row - email_folder (the label) and max_emails_per_check (default 10)
 */
function gmailGetEmails(string $accessToken, array $mailbox): array {
    $maxResults = $mailbox['max_emails_per_check'] ?? 10;

    // List unread message IDs. The configured folder becomes the list SCOPE,
    // not an extra filter layered on top of the Inbox — see gmailResolveListLabelId().
    $labelId = gmailResolveListLabelId($accessToken, $mailbox);

    $listUrl = 'https://gmail.googleapis.com/gmail/v1/users/me/messages?'
        . http_build_query(['q' => 'is:unread', 'maxResults' => $maxResults, 'labelIds' => $labelId]);

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $listUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    sslApplyCurl($ch);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $accessToken]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        throw new Exception('Gmail list messages failed: HTTP ' . $httpCode . ' ' . $response);
    }

    $data = json_decode($response, true);
    $messageIds = $data['messages'] ?? [];

    if (empty($messageIds)) {
        return [];
    }

    // Fetch full message for each ID
    $emails = [];
    foreach ($messageIds as $msg) {
        $detail = gmailGetMessage($accessToken, $msg['id']);
        if ($detail) {
            $emails[] = $detail;
        }
    }

    return $emails;
}

/**
 * Fetch a single Gmail message and normalise to Graph-like structure.
 *
 * @param string $accessToken  a valid Google OAuth access token for the mailbox
 * @param string $messageId    the Gmail message id, from messages.list
 */
function gmailGetMessage(string $accessToken, string $messageId): ?array {
    $url = 'https://gmail.googleapis.com/gmail/v1/users/me/messages/' . $messageId . '?format=full';

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    sslApplyCurl($ch);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $accessToken]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) return null;

    $msg = json_decode($response, true);
    $headers = [];
    foreach ($msg['payload']['headers'] ?? [] as $h) {
        $headers[strtolower($h['name'])] = $h['value'];
    }

    // Extract body
    $body = gmailExtractBody($msg['payload'] ?? []);

    // Parse from header: "Name <email>" or just "email"
    $fromRaw = $headers['from'] ?? '';
    $fromName = '';
    $fromAddress = $fromRaw;
    if (preg_match('/^(.*?)\s*<(.+?)>$/', $fromRaw, $m)) {
        $fromName = trim($m[1], '" ');
        $fromAddress = $m[2];
    }

    // Parse to/cc
    $toRecipients = gmailParseAddressList($headers['to'] ?? '');
    $ccRecipients = gmailParseAddressList($headers['cc'] ?? '');

    // Parse date
    $dateStr = $headers['date'] ?? '';
    // gmdate, not date: the value is labelled Z (UTC), so it must BE UTC. date()
    // wrote the server's local clock under that label, and the importer trusts
    // the label - so every Gmail email was stored ahead by the server's offset
    // (an hour in a UK summer, nothing on a UTC server, which is why it hid).
    $receivedDateTime = $dateStr && strtotime($dateStr) !== false
        ? gmdate('Y-m-d\TH:i:s\Z', strtotime($dateStr))
        : gmdate('Y-m-d\TH:i:s\Z');

    // Pull attachments now (in Graph shape) and pass them along inline — the importer
    // stores whatever a message carries in 'attachments_inline' rather than making a
    // second, provider-specific fetch. (Previously Gmail attachments were missed
    // because the importer only knew how to re-fetch from Microsoft Graph.)
    $inlineAttachments = [];
    if (!empty($msg['payload'])) {
        try {
            $inlineAttachments = gmailGetAttachmentsGraphShape($accessToken, $messageId, $msg['payload']);
        } catch (Exception $e) {
            error_log('Gmail attachment fetch failed for ' . $messageId . ': ' . $e->getMessage());
        }
    }

    // Return in Graph-like structure so the import code doesn't need major changes
    return [
        'id' => $messageId,
        'subject' => $headers['subject'] ?? '(No Subject)',
        'from' => [
            'emailAddress' => [
                'name' => $fromName,
                'address' => $fromAddress
            ]
        ],
        'toRecipients' => $toRecipients,
        'ccRecipients' => $ccRecipients,
        'receivedDateTime' => $receivedDateTime,
        'bodyPreview' => substr(strip_tags($body), 0, 255),
        'body' => [
            'contentType' => 'html',
            'content' => $body
        ],
        'hasAttachments' => !empty($inlineAttachments),
        'attachments_inline' => $inlineAttachments,
        'importance' => 'normal',
        'isRead' => false,
        // GH #120: Gmail's own conversation id - a reply to a requester's first
        // email shares it, so it can join that email's ticket without [SDREF].
        'thread_key' => (string)($msg['threadId'] ?? '') !== '' ? (string)$msg['threadId'] : null,
    ];
}

/**
 * Fetch Gmail attachments for a message and return them in the Graph fileAttachment
 * shape the importer (saveAttachment) expects. Walks nested multiparts.
 *
 * @param string $accessToken  a valid Google OAuth access token for the mailbox
 * @param string $messageId    the Gmail message id the attachments belong to
 * @param array $payload       the message's payload from messages.get?format=full (mimeType, headers, parts...)
 */
function gmailGetAttachmentsGraphShape(string $accessToken, string $messageId, array $payload): array {
    $out = [];
    $walk = function ($parts) use (&$walk, &$out, $accessToken, $messageId) {
        foreach ($parts as $part) {
            if (!empty($part['parts'])) {
                $walk($part['parts']);
            }
            if (empty($part['filename'])) {
                continue;
            }
            $attId = $part['body']['attachmentId'] ?? null;
            if (!$attId) {
                continue;
            }

            $url = 'https://gmail.googleapis.com/gmail/v1/users/me/messages/' . $messageId . '/attachments/' . $attId;
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            sslApplyCurl($ch);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $accessToken]);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($httpCode !== 200) {
                continue;
            }

            $attData = json_decode($response, true);
            if (!isset($attData['data'])) {
                continue;
            }
            $bytes = base64_decode(strtr($attData['data'], '-_', '+/'));

            // Content-ID (for inline images referenced by cid:)
            $contentId = null;
            $isInline = false;
            foreach ($part['headers'] ?? [] as $h) {
                $hn = strtolower($h['name'] ?? '');
                if ($hn === 'content-id') {
                    $contentId = trim($h['value'] ?? '', '<>');
                } elseif ($hn === 'content-disposition' && stripos($h['value'] ?? '', 'inline') !== false) {
                    $isInline = true;
                }
            }

            $out[] = [
                '@odata.type' => '#microsoft.graph.fileAttachment',
                'id' => $attId,
                'name' => $part['filename'],
                'contentType' => $part['mimeType'] ?? 'application/octet-stream',
                'contentBytes' => base64_encode($bytes),
                'isInline' => $isInline,
                'contentId' => $contentId,
                'size' => strlen($bytes),
            ];
        }
    };
    $walk($payload['parts'] ?? []);
    return $out;
}

/**
 * Extract body (prefer HTML, fall back to plain text).
 *
 * @param array $payload  a Gmail message payload or one of its multipart parts (mimeType, body.data, parts)
 */
function gmailExtractBody(array $payload): string {
    // Direct body
    if (!empty($payload['body']['data'])) {
        $decoded = base64_decode(strtr($payload['body']['data'], '-_', '+/'));
        if (($payload['mimeType'] ?? '') === 'text/html') return $decoded;
        if (($payload['mimeType'] ?? '') === 'text/plain') return nl2br(htmlspecialchars($decoded));
    }

    // Multi-part: look for text/html first, then text/plain
    $html = '';
    $plain = '';
    foreach ($payload['parts'] ?? [] as $part) {
        $mime = $part['mimeType'] ?? '';
        if ($mime === 'text/html' && !empty($part['body']['data'])) {
            $html = base64_decode(strtr($part['body']['data'], '-_', '+/'));
        } elseif ($mime === 'text/plain' && !empty($part['body']['data'])) {
            $plain = base64_decode(strtr($part['body']['data'], '-_', '+/'));
        } elseif (str_starts_with($mime, 'multipart/')) {
            // Recurse into nested multipart
            $nested = gmailExtractBody($part);
            if ($nested) return $nested;
        }
    }

    if ($html) return $html;
    if ($plain) return nl2br(htmlspecialchars($plain));
    return '';
}

/**
 * Parse a comma-separated address list into Graph-like format.
 *
 * @param string $raw  a To or Cc header value, e.g. '"Smith, J" <j@example.com>, k@example.com'
 */
function gmailParseAddressList(string $raw): array {
    if (empty($raw)) return [];
    $result = [];
    $parts = preg_split('/,(?=(?:[^"]*"[^"]*")*[^"]*$)/', $raw);
    foreach ($parts as $part) {
        $part = trim($part);
        if (preg_match('/^(.*?)\s*<(.+?)>$/', $part, $m)) {
            $result[] = ['emailAddress' => ['name' => trim($m[1], '" '), 'address' => $m[2]]];
        } elseif (filter_var($part, FILTER_VALIDATE_EMAIL)) {
            $result[] = ['emailAddress' => ['name' => '', 'address' => $part]];
        }
    }
    return $result;
}

/**
 * Delete a Gmail message (move to trash).
 *
 * @param string $accessToken  a valid Google OAuth access token for the mailbox
 * @param string $messageId    the Gmail message id
 */
function gmailTrashMessage(string $accessToken, string $messageId): void {
    $url = 'https://gmail.googleapis.com/gmail/v1/users/me/messages/' . $messageId . '/trash';

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, '');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    sslApplyCurl($ch);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $accessToken,
        'Content-Type: application/json'
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        throw new Exception('Gmail trash failed: HTTP ' . $httpCode);
    }
}

/**
 * Mark a Gmail message as read (remove UNREAD label).
 *
 * @param string $accessToken  a valid Google OAuth access token for the mailbox
 * @param string $messageId    the Gmail message id
 */
function gmailMarkAsRead(string $accessToken, string $messageId): void {
    $url = 'https://gmail.googleapis.com/gmail/v1/users/me/messages/' . $messageId . '/modify';

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['removeLabelIds' => ['UNREAD']]));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    sslApplyCurl($ch);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $accessToken,
        'Content-Type: application/json'
    ]);

    curl_exec($ch);
    curl_close($ch);
}

/**
 * Get Gmail attachments for a message.
 * Returns array of [filename, mimeType, data (base64-decoded)].
 *
 * @param string $accessToken  a valid Google OAuth access token for the mailbox
 * @param string $messageId    the Gmail message id the attachments belong to
 * @param array $payload       the message's payload from messages.get; only its top-level parts are read
 */
function gmailGetAttachments(string $accessToken, string $messageId, array $payload): array {
    $attachments = [];
    foreach ($payload['parts'] ?? [] as $part) {
        if (empty($part['filename'])) continue;
        $attId = $part['body']['attachmentId'] ?? null;
        if (!$attId) continue;

        $url = 'https://gmail.googleapis.com/gmail/v1/users/me/messages/' . $messageId . '/attachments/' . $attId;

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        sslApplyCurl($ch);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $accessToken]);

        $response = curl_exec($ch);
        curl_close($ch);

        $attData = json_decode($response, true);
        if (isset($attData['data'])) {
            $attachments[] = [
                'filename' => $part['filename'],
                'mimeType' => $part['mimeType'] ?? 'application/octet-stream',
                'data' => base64_decode(strtr($attData['data'], '-_', '+/'))
            ];
        }
    }
    return $attachments;
}

/**
 * Verify a label exists in a Google mailbox and report what the reader will see.
 *
 * ⚠️ WHY THIS EXISTS. verify_mailbox_folder.php was written for Microsoft Graph.
 * A Google mailbox DOES hold an OAuth token, so it sailed past the token gate and
 * then ran the whole Microsoft flow with Google credentials: the access token went
 * to graph.microsoft.com, and an expired one was refreshed against
 * login.microsoftonline.com using azure_client_id / azure_client_secret that a
 * Google mailbox does not have. The user got a Microsoft error about a mailbox
 * with nothing to do with Microsoft.
 *
 * ⚠️ Counts are taken through the reader's own scope — the label id resolved by
 * gmailResolveListLabelId() — and never through a differently-built query. If the
 * two ever diverge, Verify starts reporting a number the collection cannot see,
 * which is the GH #77 failure shape wearing a Gmail hat.
 *
 * @param string $accessToken  a valid Google OAuth access token for the mailbox
 * @param array $mailbox       the target_mailboxes row; only max_emails_per_check is read (for the backlog note)
 * @param string $folderName   the label name to check, as typed in the mailbox settings
 * @return array{displayName:string, totalItemCount:int, unreadItemCount:int, note:string|null}
 * @throws Exception naming the label, or explaining the API failure.
 */
function gmailVerifyFolder(string $accessToken, array $mailbox, string $folderName): array {
    $folderName = trim($folderName);
    if ($folderName === '') {
        throw new Exception('No mail folder configured.');
    }

    $get = fn(string $url) => gmailApiGet($accessToken, $url);

    // Resolved through the SAME helpers the reader uses, so Verify cannot accept
    // a label the collection would then reject.
    $labels = gmailListLabels($accessToken);
    $match  = gmailFindLabel($labels, $folderName);

    if ($match === null) {
        $names = [];
        foreach ($labels as $label) {
            if (!empty($label['name'])) {
                $names[] = $label['name'];
            }
        }
        $names = array_slice($names, 0, 12);
        throw new Exception(
            'Label "' . $folderName . '" was not found in this Gmail account.'
            . ($names ? ' Labels on the account: ' . implode(', ', $names) . '.' : '')
        );
    }

    // Counts come from labels.get, which reports messagesTotal / messagesUnread for
    // the label — the exact scope the reader now lists by.
    //
    // ⚠️ NOT from messages.list resultSizeEstimate. Google documents that field as
    // an estimate and it is not remotely trustworthy per-label: on the account this
    // was built against it returned 201 for INBOX, SENT and TRASH alike, when the
    // true counts were 2, 27 and 1. A verify button reporting invented numbers is
    // worse than one reporting none.
    $isInbox = strcasecmp($folderName, 'INBOX') === 0;

    $detail = $get('https://gmail.googleapis.com/gmail/v1/users/me/labels/' . rawurlencode((string) $match['id']));
    if ($detail['code'] !== 200) {
        throw new Exception('Could not read the Gmail label "' . $match['name'] . '" (HTTP ' . $detail['code'] . ').');
    }
    $total  = (int) ($detail['body']['messagesTotal'] ?? 0);
    $unread = (int) ($detail['body']['messagesUnread'] ?? 0);

    // Collection takes UNREAD mail wherever the label is, archived included. On a
    // label with a long unread history that is a backlog, and it will arrive as
    // tickets a checkful at a time. Better to see the number here than to watch
    // it happen. Only worth saying when it exceeds one check.
    $note = null;
    $perCheck = (int) ($mailbox['max_emails_per_check'] ?? 10);
    if (!$isInbox && $perCheck > 0 && $unread > $perCheck) {
        $note = $unread . ' unread message(s) carry this label, including any that have been '
              . 'archived out of the Inbox. All of them are eligible to become tickets, '
              . $perCheck . ' per check. Mark the older ones as read first if you only want new mail.';
    }

    return [
        'displayName'     => $match['name'],
        'totalItemCount'  => $total,
        'unreadItemCount' => $unread,
        'note'            => $note,
    ];
}
