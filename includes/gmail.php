<?php
/**
 * Gmail API Helper Functions
 *
 * Provides send/read/refresh/delete operations for Google mailboxes.
 * Mirrors the Microsoft Graph API functions used elsewhere.
 */

require_once __DIR__ . '/encryption.php';

/**
 * Refresh a Google access token using the refresh token.
 * Returns the updated token data array.
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
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, SSL_VERIFY_PEER);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, SSL_VERIFY_PEER ? 2 : 0);

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
    $stmt->execute([json_encode($tokenData), $mailbox['id']]);

    return $tokenData;
}

/**
 * Get a valid Google access token, refreshing if expired.
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
 */
function gmailSendEmail(string $accessToken, string $to, string $subject, string $htmlBody, string $from = ''): void {
    // Build RFC 2822 message
    $boundary = md5(uniqid(time()));
    $headers = "MIME-Version: 1.0\r\n";
    if ($from) {
        $headers .= "From: $from\r\n";
    }
    $headers .= "To: $to\r\n";
    $headers .= "Subject: $subject\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    $headers .= "\r\n";
    $rawMessage = $headers . $htmlBody;

    // Base64url encode
    $encoded = rtrim(strtr(base64_encode($rawMessage), '+/', '-_'), '=');

    $payload = json_encode(['raw' => $encoded]);

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://gmail.googleapis.com/gmail/v1/users/me/messages/send');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, SSL_VERIFY_PEER);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, SSL_VERIFY_PEER ? 2 : 0);
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
 * Fetch unread emails from Gmail.
 * Returns an array of messages normalised to the same shape as Graph API results.
 */
function gmailGetEmails(string $accessToken, array $mailbox): array {
    $maxResults = $mailbox['max_emails_per_check'] ?? 10;

    // List unread message IDs
    $q = 'is:unread';
    $labelId = 'INBOX';
    $folder = strtoupper($mailbox['email_folder'] ?? 'INBOX');
    if ($folder !== 'INBOX') {
        $q .= ' label:' . strtolower($mailbox['email_folder']);
    }

    $listUrl = 'https://gmail.googleapis.com/gmail/v1/users/me/messages?'
        . http_build_query(['q' => $q, 'maxResults' => $maxResults, 'labelIds' => $labelId]);

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $listUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, SSL_VERIFY_PEER);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, SSL_VERIFY_PEER ? 2 : 0);
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
 */
function gmailGetMessage(string $accessToken, string $messageId): ?array {
    $url = 'https://gmail.googleapis.com/gmail/v1/users/me/messages/' . $messageId . '?format=full';

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, SSL_VERIFY_PEER);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, SSL_VERIFY_PEER ? 2 : 0);
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
    $receivedDateTime = $dateStr ? date('Y-m-d\TH:i:s\Z', strtotime($dateStr)) : date('Y-m-d\TH:i:s\Z');

    $hasAttachments = false;
    if (isset($msg['payload']['parts'])) {
        foreach ($msg['payload']['parts'] as $part) {
            if (!empty($part['filename'])) {
                $hasAttachments = true;
                break;
            }
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
        'hasAttachments' => $hasAttachments,
        'importance' => 'normal',
        'isRead' => false
    ];
}

/**
 * Extract body (prefer HTML, fall back to plain text).
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
 */
function gmailTrashMessage(string $accessToken, string $messageId): void {
    $url = 'https://gmail.googleapis.com/gmail/v1/users/me/messages/' . $messageId . '/trash';

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, '');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, SSL_VERIFY_PEER);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, SSL_VERIFY_PEER ? 2 : 0);
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
 */
function gmailMarkAsRead(string $accessToken, string $messageId): void {
    $url = 'https://gmail.googleapis.com/gmail/v1/users/me/messages/' . $messageId . '/modify';

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['removeLabelIds' => ['UNREAD']]));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, SSL_VERIFY_PEER);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, SSL_VERIFY_PEER ? 2 : 0);
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
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, SSL_VERIFY_PEER);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, SSL_VERIFY_PEER ? 2 : 0);
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
