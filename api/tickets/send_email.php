<?php
/**
 * Send Email API - Uses Microsoft Graph API to send emails
 */

// Set error handling to prevent HTML output
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Custom error handler to return JSON
set_error_handler(function($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/ticket_numbering.php';
require_once '../../includes/encryption.php';
require_once '../../includes/tenancy.php';
require_once '../../includes/mailbox_graph.php';
require_once '../../includes/email_log.php';

header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireModuleAccessJson('tickets');

try {
    // Get POST data
    $input = json_decode(file_get_contents('php://input'), true);

    if (!$input) {
        throw new Exception('Invalid request data');
    }

    $to = $input['to'] ?? '';
    $cc = $input['cc'] ?? '';
    $subject = $input['subject'] ?? '';
    $body = $input['body'] ?? '';
    $ticketId = $input['ticket_id'] ?? null;
    $type = $input['type'] ?? 'new';
    $attachments = $input['attachments'] ?? [];

    // Validate required fields.
    //
    // The address is VALIDATED, not merely checked for emptiness: a requester
    // may have no mailbox, and a null address arriving from the browser
    // stringifies to the literal "null", which is not empty and would sail
    // through to the mail provider. Nothing on this path validated the address
    // before, so junk of any shape reached Graph/Gmail/SMTP.
    if (empty($to)) {
        throw new Exception('Recipient email address is required');
    }
    // Only the first recipient is checked when several are given: this is a
    // sanity gate against a broken address, not a full RFC 5322 parser.
    $firstTo = trim(explode(',', str_replace(';', ',', $to))[0]);
    if (!filter_var($firstTo, FILTER_VALIDATE_EMAIL)) {
        throw new Exception('That is not a valid email address. If this person has no mailbox, share a note on the ticket instead — they will see it in the portal.');
    }
    if (empty($subject)) {
        throw new Exception('Subject is required');
    }
    if (empty($ticketId)) {
        throw new Exception('Ticket ID is required to determine which mailbox to send from');
    }

    // Get database connection
    $conn = connectToDatabase();

    // Multi-tenancy: don't send from / act on a ticket in a company this analyst
    // can't access.
    if (!analystCanAccessTicket($conn, (int)$_SESSION['analyst_id'], $ticketId)) {
        throw new Exception('Ticket not found');
    }

    // Get the mailbox for this ticket
    $mailbox = getMailboxForTicket($conn, $ticketId);

    if (!$mailbox) {
        throw new Exception('Could not determine mailbox for this ticket. Please ensure the ticket has associated emails.');
    }

    // Determine provider + auth mode, and point Graph at the right mailbox
    // (delegated → /me; app-only → /users/<target_mailbox>).
    $provider = $mailbox['provider'] ?? 'microsoft';
    $authMode = $mailbox['auth_mode'] ?? 'delegated';
    mailboxResolveGraphBase($mailbox);

    if ($provider === 'imap') {
        // Basic IMAP mailboxes send via SMTP with the stored username + password —
        // no OAuth token. A placeholder keeps the shared "got a token?" guard happy.
        require_once dirname(dirname(__DIR__)) . '/includes/mailbox_imap.php';
        $accessToken = 'imap-session';
    } elseif ($provider === 'microsoft' && $authMode === 'app_only') {
        // App-only: the app authenticates itself; we send as /users/<target_mailbox>.
        $accessToken = mailboxAppOnlyToken($conn, $mailbox);
    } else {
        if (empty($mailbox['token_data'])) {
            throw new Exception('Mailbox "' . $mailbox['target_mailbox'] . '" is not authenticated. Please authenticate in Settings.');
        }

        // Parse token data (clean any control characters)
        $cleanedTokenData = preg_replace('/[\x00-\x1F\x7F]/', '', $mailbox['token_data']);
        $tokenData = json_decode($cleanedTokenData, true);
        if ($tokenData === null) {
            throw new Exception('Failed to parse token data for mailbox: ' . json_last_error_msg());
        }

        // SAFETY (delegated Microsoft): don't SEND from the wrong account. A target that
        // matches the signed-in mailbox's primary OR any alias is fine; a genuine
        // mismatch is refused until re-authenticated. Unknown identity is grandfathered.
        if ($provider === 'microsoft') {
            if ($mismatchError = mailboxIdentityMismatch($mailbox)) {
                throw new Exception($mismatchError . ' (Cannot send until this is resolved.)');
            }
        }

        // Get valid access token (will refresh if needed)
        if ($provider === 'google') {
            require_once dirname(dirname(__DIR__)) . '/includes/gmail.php';
            $accessToken = gmailGetValidAccessToken($conn, $mailbox, $tokenData);
        } else {
            $accessToken = getValidAccessToken($conn, $mailbox, $tokenData);
        }
    }

    if (!$accessToken) {
        throw new Exception('Failed to obtain valid access token. Please re-authenticate the mailbox.');
    }

    // For replies/forwards, assemble the full email with thread server-side
    // The client sends only the analyst's new content
    $bodyForSending = $body;
    if (($type === 'reply' || $type === 'forward') && $ticketId) {
        $bodyForSending = buildFullEmailBody($conn, $ticketId, $body, $type);
    }

    try {
        if ($provider === 'imap') {
            // Send via SMTP (username/password). HTML body only — outbound attachments
            // aren't supported on the basic-IMAP path (parity with the Gmail path).
            imapSmtpSend($mailbox, $to, $cc, $subject, $bodyForSending);
        } elseif ($provider === 'google') {
            // Send via Gmail API
            $fromAddress = $mailbox['target_mailbox'] ?? '';
            gmailSendEmail($accessToken, $to, $subject, $bodyForSending, $fromAddress);
        } else {
            // Build the email message for Graph API (send assembled body with thread)
            $message = buildEmailMessage($to, $cc, $subject, $bodyForSending, $attachments);

            // Send the email via Graph API
            $result = sendEmailViaGraph($accessToken, $message);
        }
    } catch (Exception $sendEx) {
        // Rethrown after logging: the analyst still gets told it failed, and the
        // attempt is now on record rather than only in the server's error log.
        emailLogFailed($conn, $mailbox, 'reply', $to, $subject, $sendEx->getMessage(), $ticketId ?: null);
        throw $sendEx;
    }
    emailLogSent($conn, $mailbox, 'reply', $to, $subject, $ticketId ?: null);

    // Save only the analyst's new content to DB (not the assembled thread).
    // $attachments goes too: the same files buildEmailMessage() just sent, so
    // the ticket shows what the requester actually received.
    saveSentEmail($conn, $ticketId, $mailbox, $to, $cc, $subject, $body, $attachments);

    echo json_encode([
        'success' => true,
        'message' => 'Email sent successfully'
    ]);

} catch (ErrorException $e) {
    echo json_encode([
        'success' => false,
        'error' => 'PHP Error: ' . $e->getMessage() . ' in ' . $e->getFile() . ' line ' . $e->getLine()
    ]);
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}

/**
 * Get mailbox for a ticket based on associated emails
 */
function getMailboxForTicket($conn, $ticketId) {
    // Get the mailbox_id from the ticket's emails (use the first/initial email's mailbox)
    $sql = "SELECT tm.*
            FROM emails e
            INNER JOIN target_mailboxes tm ON e.mailbox_id = tm.id
            WHERE e.ticket_id = ?
            ORDER BY e.is_initial DESC, e.received_datetime ASC
            LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$ticketId]);
    $mailbox = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($mailbox) {
        $mailbox = decryptMailboxRow($mailbox);
        $mailbox['is_active'] = (bool)$mailbox['is_active'];
        $mailbox['mark_as_read'] = (bool)$mailbox['mark_as_read'];
    }

    return $mailbox;
}

/**
 * Get valid access token (refresh if expired)
 */
function getValidAccessToken($conn, $mailbox, $tokenData) {
    if (!$tokenData || !isset($tokenData['access_token'])) {
        throw new Exception('Invalid token data');
    }

    // Check if token is expired (with 5 minute buffer)
    if (isset($tokenData['expires_at']) && $tokenData['expires_at'] < (time() + 300)) {
        // Token expired or expiring soon, refresh it
        if (!isset($tokenData['refresh_token'])) {
            throw new Exception('No refresh token available. Please re-authenticate.');
        }

        $tokenData = refreshAccessToken($mailbox, $tokenData['refresh_token']);
        saveTokenData($conn, $mailbox['id'], $tokenData);
    }

    return $tokenData['access_token'];
}

/**
 * Refresh the access token using mailbox configuration
 */
function refreshAccessToken($mailbox, $refreshToken) {
    $tokenUrl = 'https://login.microsoftonline.com/' . $mailbox['azure_tenant_id'] . '/oauth2/v2.0/token';

    $postData = [
        'client_id' => $mailbox['azure_client_id'],
        'client_secret' => $mailbox['azure_client_secret'],
        'refresh_token' => $refreshToken,
        'grant_type' => 'refresh_token',
        'scope' => $mailbox['oauth_scopes']
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $tokenUrl);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    sslApplyCurl($ch);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if (curl_errno($ch)) {
        throw new Exception('cURL error: ' . curl_error($ch));
    }

    curl_close($ch);

    if ($httpCode !== 200) {
        // The provider says WHY in the body — pass it on rather than throwing it away.
        throw new Exception(oauthTokenErrorMessage($response, $httpCode));
    }

    $tokenData = json_decode($response, true);

    if (!isset($tokenData['access_token'])) {
        throw new Exception('Access token not found in refresh response');
    }

    return [
        'access_token' => $tokenData['access_token'],
        'refresh_token' => $tokenData['refresh_token'] ?? $refreshToken,
        'expires_in' => $tokenData['expires_in'] ?? 3600,
        'token_type' => $tokenData['token_type'] ?? 'Bearer',
        'expires_at' => time() + ($tokenData['expires_in'] ?? 3600),
        'created_at' => time()
    ];
}

/**
 * Save token data to database
 */
function saveTokenData($conn, $mailboxId, $tokenData) {
    // Encrypted at rest — see ENCRYPTED_MAILBOX_COLUMNS; decryptMailboxRow() reverses it.
    $jsonData = encryptValue(json_encode($tokenData));

    $sql = "UPDATE target_mailboxes SET token_data = ? WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$jsonData, $mailboxId]);
}

/**
 * Build email message structure for Graph API
 */
function buildEmailMessage($to, $cc, $subject, $body, $attachments) {
    // Parse recipients
    $toRecipients = parseRecipients($to);
    $ccRecipients = parseRecipients($cc);

    // Process inline images - convert internal URLs to CID references
    $inlineResult = processInlineImages($body);
    $body = $inlineResult['body'];
    $inlineAttachments = $inlineResult['attachments'];

    $message = [
        'message' => [
            'subject' => $subject,
            'body' => [
                'contentType' => 'HTML',
                'content' => $body
            ],
            'toRecipients' => $toRecipients
        ],
        'saveToSentItems' => true
    ];

    // Add CC recipients if any
    if (!empty($ccRecipients)) {
        $message['message']['ccRecipients'] = $ccRecipients;
    }

    // Start with inline attachments
    $allAttachments = $inlineAttachments;

    // Add user-uploaded attachments if any
    if (!empty($attachments)) {
        foreach ($attachments as $attachment) {
            $allAttachments[] = [
                '@odata.type' => '#microsoft.graph.fileAttachment',
                'name' => $attachment['name'],
                'contentType' => $attachment['type'] ?? 'application/octet-stream',
                'contentBytes' => $attachment['content'] // Already base64 encoded
            ];
        }
    }

    // Add all attachments to message
    if (!empty($allAttachments)) {
        $message['message']['attachments'] = $allAttachments;
    }

    return $message;
}

/**
 * Process inline images in email body
 * Converts internal api/get_attachment.php URLs to proper CID references
 */
function processInlineImages($body) {
    $inlineAttachments = [];
    $cidCounter = 1;

    // Pattern to match our internal attachment URLs
    // Matches: src="api/get_attachment.php?id=123" or src="api/get_attachment.php?cid=xxx&email_id=123"
    $pattern = '/src=["\']api\/get_attachment\.php\?([^"\']+)["\']/i';

    // Use a wrapper to pass variables by reference into the callback
    $callback = function($matches) use (&$inlineAttachments, &$cidCounter) {
        try {
            $queryString = $matches[1];
            parse_str(html_entity_decode($queryString), $params);

            // Try to find the attachment
            $attachment = null;

            if (isset($params['id'])) {
                $attachment = getAttachmentById($params['id']);
            } elseif (isset($params['cid']) && isset($params['email_id'])) {
                $attachment = getAttachmentByCid($params['cid'], $params['email_id']);
            }

            if (!$attachment) {
                // Couldn't find attachment, leave URL as-is
                error_log('Inline image: attachment not found for params: ' . json_encode($params));
                return $matches[0];
            }

            // Read the file content - use DIRECTORY_SEPARATOR for Windows compatibility
            $basePath = dirname(dirname(__DIR__)) . DIRECTORY_SEPARATOR . 'tickets' . DIRECTORY_SEPARATOR . 'attachments' . DIRECTORY_SEPARATOR;
            $filePath = $basePath . str_replace('/', DIRECTORY_SEPARATOR, $attachment['file_path']);

            if (!file_exists($filePath)) {
                error_log('Inline image: file not found at ' . $filePath);
                return $matches[0];
            }

            $fileContent = file_get_contents($filePath);
            if ($fileContent === false) {
                error_log('Inline image: failed to read file ' . $filePath);
                return $matches[0];
            }

            // Generate a unique CID for this attachment
            $newCid = 'inline_image_' . $cidCounter . '_' . time();
            $cidCounter++;

            // Add to inline attachments array
            $inlineAttachments[] = [
                '@odata.type' => '#microsoft.graph.fileAttachment',
                'name' => $attachment['filename'],
                'contentType' => $attachment['content_type'],
                'contentBytes' => base64_encode($fileContent),
                'contentId' => $newCid,
                'isInline' => true
            ];

            // Return the CID reference
            return 'src="cid:' . $newCid . '"';
        } catch (Exception $e) {
            error_log('Inline image processing error: ' . $e->getMessage());
            return $matches[0];
        }
    };

    $body = preg_replace_callback($pattern, $callback, $body);

    return [
        'body' => $body,
        'attachments' => $inlineAttachments
    ];
}

/**
 * Get attachment by ID from database
 */
function getAttachmentById($id) {
    try {
        $conn = connectToDatabase();
        $sql = "SELECT id, filename, content_type, file_path FROM email_attachments WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log('Failed to get attachment by ID: ' . $e->getMessage());
        return null;
    }
}

/**
 * Get attachment by CID and email_id from database
 */
function getAttachmentByCid($cid, $emailId) {
    try {
        $conn = connectToDatabase();
        // CID might be URL-encoded, so try both
        $decodedCid = urldecode($cid);

        $sql = "SELECT id, filename, content_type, file_path FROM email_attachments
                WHERE (content_id = ? OR content_id = ? OR content_id = ? OR content_id = ?)
                AND email_id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->execute([
            $cid,
            $decodedCid,
            '<' . $cid . '>',
            '<' . $decodedCid . '>',
            $emailId
        ]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log('Failed to get attachment by CID: ' . $e->getMessage());
        return null;
    }
}

/**
 * Parse recipients string into Graph API format
 */
function parseRecipients($recipientString) {
    if (empty($recipientString)) {
        return [];
    }

    $recipients = [];
    // Split by semicolon or comma
    $emails = preg_split('/[;,]/', $recipientString);

    foreach ($emails as $email) {
        $email = trim($email);
        if (!empty($email) && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $recipients[] = [
                'emailAddress' => [
                    'address' => $email
                ]
            ];
        }
    }

    return $recipients;
}

/**
 * Send email via Microsoft Graph API
 */
function sendEmailViaGraph($accessToken, $message) {
    $graphUrl = 'https://graph.microsoft.com/v1.0' . mailboxGraphBase() . '/sendMail';

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $graphUrl);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($message));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    sslApplyCurl($ch);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $accessToken,
        'Content-Type: application/json'
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if (curl_errno($ch)) {
        throw new Exception('cURL error: ' . curl_error($ch));
    }

    curl_close($ch);

    // Graph API returns 202 Accepted for successful sendMail
    if ($httpCode !== 202 && $httpCode !== 200) {
        $errorData = json_decode($response, true);
        $errorMessage = $errorData['error']['message'] ?? 'Unknown error';
        throw new Exception('Failed to send email: ' . $errorMessage . ' (HTTP ' . $httpCode . ')');
    }

    return true;
}

/**
 * Strip the quoted thread from the email body
 * Looks for the reply marker and returns only the content above it
 */
function stripThreadFromBody($body) {
    // Look for the marker pattern in the HTML
    // The marker text is: [*** SDREF:XXX-XXX-XXXXX REPLY ABOVE THIS LINE ***]
    // It's wrapped in a div with data-reply-marker="true"
    $markerPattern = TicketNumbering::REF_LINE_PATTERN;

    // Try to find the marker div first (from our own compose)
    $divPattern = '/<div[^>]*data-reply-marker="true"[^>]*>.*?<\/div>/is';
    if (preg_match($divPattern, $body, $matches, PREG_OFFSET_CAPTURE)) {
        $markerPos = $matches[0][1];
        return trim(substr($body, 0, $markerPos));
    }

    // Fallback: look for the raw marker text (e.g. if HTML was modified)
    if (preg_match($markerPattern, $body, $matches, PREG_OFFSET_CAPTURE)) {
        $markerPos = $matches[0][1];
        return trim(substr($body, 0, $markerPos));
    }

    // No marker found — return the full body (legacy emails without marker)
    return $body;
}

/**
 * Build full email body with thread for sending to recipient
 * Analyst's content + reply marker + quoted thread from DB
 */
function buildFullEmailBody($conn, $ticketId, $analystBody, $type) {
    // Get the ticket number for the reply marker
    $ticketStmt = $conn->prepare("SELECT ticket_number FROM tickets WHERE id = ?");
    $ticketStmt->execute([$ticketId]);
    $ticket = $ticketStmt->fetch(PDO::FETCH_ASSOC);
    $ticketNumber = $ticket ? $ticket['ticket_number'] : 'UNKNOWN';

    // Fetch all emails for this ticket
    $sql = "SELECT from_address, from_name, received_datetime, body_content, direction
            FROM emails WHERE ticket_id = ? ORDER BY received_datetime ASC";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$ticketId]);
    $emails = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($emails)) {
        return $analystBody;
    }

    // Build the quoted thread HTML (newest first)
    $threadEmails = array_reverse($emails);
    $threadParts = [];
    foreach ($threadEmails as $e) {
        $bodyContent = $e['body_content'] ?? '';
        // Strip any existing quoted content so we don't nest
        $bodyContent = stripQuotedContent($bodyContent);
        // Clean control characters
        $bodyContent = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $bodyContent);

        // A portal message from a requester with no mailbox has NO address, so
        // the name stands alone — passing null to htmlspecialchars() is also a
        // deprecation on PHP 8.1+. Without the conditional below, every reply on
        // a ticket that contains one such message quotes "Wendy Warehouse <>",
        // including replies addressed to other people entirely.
        $fromName = htmlspecialchars((string)($e['from_name'] ?: ($e['from_address'] ?? '')), ENT_QUOTES, 'UTF-8');
        $fromAddr = htmlspecialchars((string)($e['from_address'] ?? ''), ENT_QUOTES, 'UTF-8');
        $fromLabel = $fromAddr !== '' ? ($fromName . ' &lt;' . $fromAddr . '&gt;') : $fromName;
        $date = date('d M Y H:i', strtotime($e['received_datetime']));

        $threadParts[] = '<div style="margin-bottom: 15px;">'
            . '<p style="margin: 0 0 5px 0; color: #666; font-size: 13px;"><strong>On ' . $date . ', ' . $fromLabel . ' wrote:</strong></p>'
            . '<blockquote style="margin: 0 0 0 10px; padding-left: 10px; border-left: 2px solid #ccc;">'
            . $bodyContent
            . '</blockquote>'
            . '</div>';
    }

    $threadHtml = implode('', $threadParts);

    // Build the reply marker - visible, clean separator with SDREF for programmatic detection
    $markerText = "[*** SDREF:$ticketNumber REPLY ABOVE THIS LINE ***]";
    $prefix = ($type === 'forward') ? '<p><strong>---------- Forwarded message ----------</strong></p>' : '';

    // Assemble: analyst content + visible separator + thread
    return $analystBody
        . '<div style="border-top: 1px solid #ccc; padding: 10px 0; margin: 20px 0; color: #999; font-size: 12px; text-align: center;" data-reply-marker="true">— Please reply above this line —</div>'
        . '<div style="color: #555;">'
        . $prefix
        . $threadHtml
        . '</div>';
}

/**
 * Strip quoted/nested content from email body (for thread assembly)
 * Relies on our own visible marker text, with generic blockquote fallback
 */
function stripQuotedContent($body) {
    // 1. Our visible marker text: "Please reply above this line"
    if (preg_match('/\x{2014}\s*Please reply above this line\s*\x{2014}/u', $body, $matches, PREG_OFFSET_CAPTURE)) {
        $stripped = trim(substr($body, 0, $matches[0][1]));
        if (!empty($stripped)) return $stripped;
    }

    // 2. Our data-reply-marker div (if preserved)
    if (preg_match('/<div[^>]*data-reply-marker="true"[^>]*>/i', $body, $matches, PREG_OFFSET_CAPTURE)) {
        $stripped = trim(substr($body, 0, $matches[0][1]));
        if (!empty($stripped)) return $stripped;
    }

    // 3. Legacy SDREF marker text from older emails
    if (preg_match(TicketNumbering::REF_LINE_PATTERN, $body, $matches, PREG_OFFSET_CAPTURE)) {
        $stripped = trim(substr($body, 0, $matches[0][1]));
        if (!empty($stripped)) return $stripped;
    }

    // 4. Generic fallback: blockquote (only if there's content before it)
    if (preg_match('/<blockquote[^>]*>/i', $body, $matches, PREG_OFFSET_CAPTURE)) {
        $before = trim(substr($body, 0, $matches[0][1]));
        if (!empty($before)) return $before;
    }

    return $body;
}

/**
 * Save sent email to database, including whatever was attached to it.
 *
 * ⚠️ THE ATTACHMENTS HAVE ALREADY GONE. This runs after the send, so nothing
 * here can refuse a file — it is recording what left the building, not deciding
 * whether it may. That is why a file the upload policy dislikes is still noted
 * on the message rather than silently dropped: the ticket has to show what the
 * requester actually received.
 *
 * It still stores through includes/uploads.php like every other path, because
 * these land in tickets/attachments/ inside the web root and a name chosen by
 * somebody else must never become a file the server would execute.
 */
function saveSentEmail($conn, $ticketId, $mailbox, $to, $cc, $subject, $body, $attachments = []) {
    try {
        $sql = "INSERT INTO emails (
            subject, from_address, from_name, to_recipients, cc_recipients,
            received_datetime, body_content, body_type, ticket_id, is_initial, direction, mailbox_id
        ) VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP(), ?, 'html', ?, 0, 'Outbound', ?)";

        $stmt = $conn->prepare($sql);
        $stmt->execute([
            $subject,
            $mailbox['target_mailbox'],
            $mailbox['mailbox_name'] ?? 'Service Desk',
            $to,
            $cc,
            $body,
            $ticketId,
            $mailbox['id']
        ]);

        $emailId = (int)$conn->lastInsertId();

        if ($emailId > 0 && !empty($attachments)) {
            saveSentAttachments($conn, $emailId, $attachments);
        }

        // Update ticket's updated_datetime
        $updateSql = "UPDATE tickets SET updated_datetime = UTC_TIMESTAMP() WHERE id = ?";
        $updateStmt = $conn->prepare($updateSql);
        $updateStmt->execute([$ticketId]);

        return $emailId;
    } catch (Exception $e) {
        // Log error but don't fail the send operation
        error_log('Failed to save sent email to database: ' . $e->getMessage());
        return 0;
    }
}

/**
 * Put the files an analyst attached to a reply on the ticket.
 *
 * Same convention as inbound mail (check_mailbox_email.php) and the portal
 * (self-service/reply_ticket.php): tickets/attachments/{floor(id/1000)}/{id}/,
 * so get_attachment.php serves them with no changes and the reading pane shows
 * them against the message they went with.
 *
 * Without this the file reached the requester and left NO trace on the ticket —
 * so the next analyst to open it saw a reply referring to a document that,
 * as far as FreeITSM was concerned, had never existed. Reported by Ed.
 */
function saveSentAttachments($conn, $emailId, $attachments) {
    require_once '../../includes/uploads.php';

    $attachDir = realpath(__DIR__ . '/../../tickets/attachments');
    if ($attachDir === false) {
        error_log('Sent attachments: tickets/attachments is missing');
        return;
    }

    $subdir   = floor($emailId / 1000);
    $emailDir = $attachDir . '/' . $subdir . '/' . $emailId;

    if (!is_dir($emailDir) && !mkdir($emailDir, 0755, true) && !is_dir($emailDir)) {
        error_log('Sent attachments: could not create ' . $emailDir);
        return;
    }

    $attachStmt = $conn->prepare(
        "INSERT INTO email_attachments (email_id, filename, content_type, file_path, file_size, is_inline, created_datetime)
         VALUES (?, ?, ?, ?, ?, 0, UTC_TIMESTAMP())"
    );

    $policy    = attachmentRejectPolicy($conn);
    $allowed   = attachmentAllowedTypes($conn);
    $storedAny = false;
    $notStored = [];

    foreach ($attachments as $att) {
        // The client sends these already base64-encoded, the same shape
        // buildEmailMessage() posted to the mail provider a moment ago.
        $bytes = base64_decode((string)($att['content'] ?? ''), true);
        if ($bytes === false || $bytes === '') {
            $notStored[] = (string)($att['name'] ?? 'file');
            continue;
        }

        $stored = uploadStoreBytes($bytes, (string)($att['name'] ?? 'file'), $emailDir, $policy, $allowed);

        if (!$stored['stored']) {
            $notStored[] = $stored['original_name'];
            continue;
        }

        $storedAny = true;
        $relPath   = $subdir . '/' . $emailId . '/' . $stored['stored_name'];
        $attachStmt->execute([
            $emailId,
            $stored['original_name'],
            $stored['mime'],
            $relPath,
            $stored['size'],
        ]);
    }

    if ($storedAny) {
        $conn->prepare("UPDATE emails SET has_attachments = 1 WHERE id = ?")->execute([$emailId]);
    }

    // A file that went out but could not be kept is the one case worth writing
    // on the message itself. Saying nothing would leave the ticket quietly
    // disagreeing with what the requester has in their inbox.
    if ($notStored) {
        $note = '<p><em>Sent with ' . count($notStored) . ' attachment(s) that could not be stored on the ticket: '
              . htmlspecialchars(implode(', ', $notStored), ENT_QUOTES, 'UTF-8') . '</em></p>';
        $conn->prepare("UPDATE emails SET body_content = CONCAT(body_content, ?) WHERE id = ?")
             ->execute([$note, $emailId]);
    }
}
