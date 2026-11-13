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
require_once '../../includes/timezone.php';   // fmt_local() for the quoted thread's dates

// Changes data: never on a GET, which a link on another site can send (csrf.php).
csrfRequireWriteMethod();

// Most bytes of thread pictures one email carries - see processInlineImages().
// ⚠️ Must stay up here, above the code that sends. Unlike a function, a top-level
// const only exists once PHP has run the line, and this file's functions are
// called before execution ever reaches the bottom of it. 2.10.0 declared it next
// to processInlineImages(), so every reply whose thread held a picture died with
// "Undefined constant" and was never sent (GH #158 follow-up).
const INLINE_THREAD_BUDGET = 2 * 1024 * 1024;

header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireModuleAccessJson('tickets');
Tz::init();   // the sending analyst's zone, for the dates in the quoted thread

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

    // GH #306: the subject is editable in the dialog. Whatever was typed, this ticket's
    // [SDREF] stays (added back if removed, another ticket's taken out), so replies thread.
    require_once '../../includes/email_threading.php';
    $numStmt = $conn->prepare("SELECT ticket_number FROM tickets WHERE id = ?");
    $numStmt->execute([$ticketId]);
    $ticketNumberForSubject = (string)$numStmt->fetchColumn();
    if ($ticketNumberForSubject !== '') {
        $subject = ticketOutboundSubject((string)$subject, $ticketNumberForSubject);
    }

    // Get the mailbox for this ticket
    $mailbox = getMailboxForTicket($conn, $ticketId);

    if (!$mailbox) {
        throw new Exception('Could not determine mailbox for this ticket. Please ensure the ticket has associated emails.');
    }

    // Determine provider + auth mode, and point Graph at the right mailbox
    // (delegated → /me; app-only → /users/<target_mailbox>).
    $provider = MailboxProvider::fromMailbox($mailbox);
    $authMode = $mailbox['auth_mode'] ?? 'delegated';
    mailboxResolveGraphBase($mailbox);

    if ($provider === MailboxProvider::Imap) {
        // Basic IMAP mailboxes send via SMTP with the stored username + password —
        // no OAuth token. A placeholder keeps the shared "got a token?" guard happy.
        require_once dirname(dirname(__DIR__)) . '/includes/mailbox_imap.php';
        $accessToken = 'imap-session';
    } elseif ($provider === MailboxProvider::Microsoft && $authMode === 'app_only') {
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
        if ($provider === MailboxProvider::Microsoft) {
            if ($mismatchError = mailboxIdentityMismatch($mailbox)) {
                throw new Exception($mismatchError . ' (Cannot send until this is resolved.)');
            }
        }

        // Get valid access token (will refresh if needed)
        if ($provider === MailboxProvider::Google) {
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
        if ($provider === MailboxProvider::Imap || $provider === MailboxProvider::Google) {
            // SMTP and Gmail both take a finished MIME message, built by
            // includes/mime_message.php from the same file list Graph gets.
            // GH #158: both used to send the HTML alone - the attachments, the
            // pasted images and (on Gmail) the CC list never left.
            $inline = processInlineImages($bodyForSending, (int)$ticketId);
            $parts  = array_merge($inline['attachments'], uploadedFileParts($attachments));
            if ($provider === MailboxProvider::Imap) {
                imapSmtpSend($mailbox, $to, $cc, $subject, $inline['body'], $parts);
            } else {
                gmailSendEmail($accessToken, $to, $subject, $inline['body'], $mailbox['target_mailbox'] ?? '', $cc, $parts);
            }
        } else {
            // Build the email message for Graph API (send assembled body with thread)
            $message = buildEmailMessage($to, $cc, $subject, $bodyForSending, $attachments, (int)$ticketId);

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
 *
 * @param mixed $ticketId  the ticket whose first email decides which mailbox replies go from
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
 *
 * @param mixed $mailbox  the target_mailboxes row, decrypted (id and the azure_* columns are used for a refresh)
 * @param mixed $tokenData  the mailbox's decoded token_data: access_token, refresh_token, expires_at (Unix time)
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
 *
 * @param mixed $mailbox  the target_mailboxes row, decrypted - azure_tenant_id, azure_client_id/secret, oauth_scopes
 * @param mixed $refreshToken  the OAuth refresh token to trade for a new access token
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
 *
 * @param mixed $mailboxId  the target_mailboxes id to update
 * @param mixed $tokenData  the token array to store (encrypted) in token_data, as built by refreshAccessToken()
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
 *
 * @param mixed $to  the To addresses as typed, separated by ; or ,
 * @param mixed $cc  the Cc addresses as typed, separated by ; or ,; empty for none
 * @param mixed $subject  the email subject
 * @param mixed $body  the HTML body to send, thread included for a reply or forward
 * @param mixed $attachments  the files the analyst attached, from the request: [{name, type, content (base64)}, ...]
 * @param mixed $ticketId  the ticket being emailed from; only its own stored pictures are embedded
 */
function buildEmailMessage($to, $cc, $subject, $body, $attachments, $ticketId) {
    // Parse recipients
    $toRecipients = parseRecipients($to);
    $ccRecipients = parseRecipients($cc);

    // Process inline images - convert internal URLs to CID references
    $inlineResult = processInlineImages($body, $ticketId);
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

    // Inline images first, then the files the analyst attached
    $allAttachments = array_merge($inlineAttachments, uploadedFileParts($attachments));

    // Add all attachments to message
    if (!empty($allAttachments)) {
        $message['message']['attachments'] = $allAttachments;
    }

    return $message;
}

/**
 * The files the analyst attached, in the Graph fileAttachment shape that all
 * three providers' builders take (Graph directly; SMTP and Gmail through
 * includes/mime_message.php).
 *
 * @param mixed $attachments  the files the analyst attached, from the request: [{name, type, content (base64)}, ...]
 */
function uploadedFileParts($attachments) {
    $parts = [];
    foreach ((array)$attachments as $attachment) {
        if (!is_array($attachment) || !isset($attachment['name'], $attachment['content'])) {
            continue;
        }
        $parts[] = [
            '@odata.type' => '#microsoft.graph.fileAttachment',
            'name' => $attachment['name'],
            'contentType' => $attachment['type'] ?? 'application/octet-stream',
            'contentBytes' => $attachment['content'] // Already base64 encoded
        ];
    }
    return $parts;
}

/**
 * Process inline images in email body
 * Converts image links the customer's mail client could never load into
 * images carried inside the email itself (src="cid:...").
 *
 * Two kinds of image are converted:
 *
 * 1. Links to a file stored on this ticket. An inbound email's pictures are
 *    saved with a link back into FreeITSM, and that link travels in the quoted
 *    thread of every reply and forward. check_mailbox_email.php writes it as
 *    "/api/tickets/get_attachment.php?cid=...&email_id=N" (older rows have
 *    "../api/tickets/..."). GH #158: this used to match only
 *    "api/get_attachment.php", a form nothing stores - it matched 0 rows, so
 *    thread pictures went out as broken links on EVERY provider.
 *    Only files belonging to THIS ticket are read, so a link typed into the
 *    editor's source view cannot mail out another ticket's (or company's) file.
 *    ⚠️ Capped at INLINE_THREAD_BUDGET bytes per email. Every reply re-sends the
 *    whole thread's pictures, and Graph refuses a sendMail request over 4 MB -
 *    uncapped, a picture-heavy thread would turn a send that used to work (with
 *    broken pictures) into one that fails. Past the cap the link stays as it was.
 *
 * 2. data: images - what the editor produces when a screenshot is pasted.
 *    Gmail and Outlook refuse to show a data: image in a received email.
 *
 * @param mixed $body  the HTML body to send
 * @param mixed $ticketId  the ticket being emailed from; links to another ticket's files are left alone
 */
function processInlineImages($body, $ticketId) {
    $inlineAttachments = [];
    $cidCounter = 1;
    $byAttachmentId = []; // the same picture quoted twice goes in once
    $threadBytes = 0;
    $stamp = time();

    // Any RELATIVE link to get_attachment.php (a full http(s) URL is someone
    // else's server and is left alone).
    $pattern = '/src=(["\'])(?![a-z][a-z0-9+.\-]*:)[^"\']*?api\/(?:tickets\/)?get_attachment\.php\?([^"\']+)\1/i';

    // Use a wrapper to pass variables by reference into the callback
    $callback = function($matches) use (&$inlineAttachments, &$cidCounter, &$byAttachmentId, &$threadBytes, $stamp, $ticketId) {
        try {
            $queryString = $matches[2];
            parse_str(html_entity_decode($queryString), $params);

            // Try to find the attachment
            $attachment = null;

            if (isset($params['id'])) {
                $attachment = getAttachmentById($params['id'], $ticketId);
            } elseif (isset($params['cid']) && isset($params['email_id'])) {
                $attachment = getAttachmentByCid($params['cid'], $params['email_id'], $ticketId);
            }

            if (!$attachment) {
                // Couldn't find attachment, leave URL as-is
                error_log('Inline image: attachment not found for params: ' . json_encode($params));
                return $matches[0];
            }

            if (isset($byAttachmentId[$attachment['id']])) {
                return 'src="cid:' . $byAttachmentId[$attachment['id']] . '"';
            }

            // Read the file content - use DIRECTORY_SEPARATOR for Windows compatibility
            $basePath = dirname(dirname(__DIR__)) . DIRECTORY_SEPARATOR . 'tickets' . DIRECTORY_SEPARATOR . 'attachments' . DIRECTORY_SEPARATOR;
            $filePath = $basePath . str_replace('/', DIRECTORY_SEPARATOR, $attachment['file_path']);

            if (!file_exists($filePath)) {
                error_log('Inline image: file not found at ' . $filePath);
                return $matches[0];
            }

            $size = (int)filesize($filePath);
            if ($threadBytes + $size > INLINE_THREAD_BUDGET) {
                return $matches[0];
            }
            $threadBytes += $size;

            $fileContent = file_get_contents($filePath);
            if ($fileContent === false) {
                error_log('Inline image: failed to read file ' . $filePath);
                return $matches[0];
            }

            // Generate a unique CID for this attachment
            $newCid = 'inline_image_' . $cidCounter . '_' . $stamp;
            $cidCounter++;
            $byAttachmentId[$attachment['id']] = $newCid;

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
        } catch (Throwable $e) {
            // Throwable, not Exception: a fault while embedding one picture must
            // leave that link as it was, never stop the whole email going out.
            error_log('Inline image processing error: ' . $e->getMessage());
            return $matches[0];
        }
    };

    $body = preg_replace_callback($pattern, $callback, $body);

    // Pasted screenshots: src="data:image/png;base64,...."
    $body = preg_replace_callback(
        '/src=(["\'])data:(image\/[a-z0-9.+\-]+);base64,([A-Za-z0-9+\/=\s]+)\1/i',
        function ($m) use (&$inlineAttachments, &$cidCounter, $stamp) {
            $bytes = preg_replace('/\s+/', '', $m[3]);
            if (base64_decode($bytes, true) === false) {
                return $m[0];
            }
            $type = strtolower($m[2]);
            $ext = ['image/jpeg' => 'jpg', 'image/svg+xml' => 'svg'][$type] ?? preg_replace('/[^a-z0-9]/', '', substr($type, 6));
            $newCid = 'inline_image_' . $cidCounter . '_' . $stamp;
            $inlineAttachments[] = [
                '@odata.type' => '#microsoft.graph.fileAttachment',
                'name' => 'image' . $cidCounter . '.' . $ext,
                'contentType' => $type,
                'contentBytes' => $bytes,
                'contentId' => $newCid,
                'isInline' => true
            ];
            $cidCounter++;
            return 'src="cid:' . $newCid . '"';
        },
        $body
    );

    return [
        'body' => $body,
        'attachments' => $inlineAttachments
    ];
}

/**
 * Get attachment by ID from database - only if it belongs to this ticket
 *
 * @param mixed $id  the email_attachments id from the image link
 * @param mixed $ticketId  the ticket the attachment must belong to; anything else gives no row
 */
function getAttachmentById($id, $ticketId) {
    try {
        $conn = connectToDatabase();
        $sql = "SELECT a.id, a.filename, a.content_type, a.file_path
                  FROM email_attachments a
                  JOIN emails e ON e.id = a.email_id
                 WHERE a.id = ? AND e.ticket_id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->execute([$id, $ticketId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log('Failed to get attachment by ID: ' . $e->getMessage());
        return null;
    }
}

/**
 * Get attachment by CID and email_id from database - only if it belongs to this ticket
 *
 * @param mixed $cid  the content id from the image link, possibly URL-encoded
 * @param mixed $emailId  the emails.id from the image link
 * @param mixed $ticketId  the ticket the attachment must belong to; anything else gives no row
 */
function getAttachmentByCid($cid, $emailId, $ticketId) {
    try {
        $conn = connectToDatabase();
        // CID might be URL-encoded, so try both
        $decodedCid = urldecode($cid);

        $sql = "SELECT a.id, a.filename, a.content_type, a.file_path
                  FROM email_attachments a
                  JOIN emails e ON e.id = a.email_id
                 WHERE (a.content_id = ? OR a.content_id = ? OR a.content_id = ? OR a.content_id = ?)
                   AND a.email_id = ? AND e.ticket_id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->execute([
            $cid,
            $decodedCid,
            '<' . $cid . '>',
            '<' . $decodedCid . '>',
            $emailId,
            $ticketId
        ]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log('Failed to get attachment by CID: ' . $e->getMessage());
        return null;
    }
}

/**
 * Parse recipients string into Graph API format
 *
 * @param mixed $recipientString  addresses separated by ; or ,; invalid ones are dropped
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
 *
 * @param mixed $accessToken  the mailbox's Microsoft Graph access token, from getValidAccessToken()
 * @param mixed $message  the sendMail request as built by buildEmailMessage()
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
 *
 * @param mixed $body  an HTML body that may contain the reply marker and the quoted thread below it
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
 *
 * @param mixed $ticketId  the ticket whose emails make up the quoted thread
 * @param mixed $analystBody  the analyst's new HTML text, which goes above the marker
 * @param mixed $type  'reply' or 'forward' - a forward gets a "Forwarded message" heading
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
        // In the SENDING analyst's zone (their preference, else the install's).
        // This was date('d M Y H:i', strtotime($utc)) - parsed and formatted in
        // the same zone, so it printed the stored UTC digits as if they were local
        // time: "05:20" in Ho Chi Minh for a 12:20 email (GH #161). The same fault
        // GH #126 fixed in email templates; this copy was missed then.
        $date = fmt_local($e['received_datetime'], 'd M Y H:i');

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
 *
 * @param mixed $body  one stored email's HTML body, which may carry its own quoted thread
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
 *
 * @param mixed $ticketId  the ticket the email was sent from
 * @param mixed $mailbox  the target_mailboxes row it was sent from - id, target_mailbox and mailbox_name are stored
 * @param mixed $to  the To addresses as typed
 * @param mixed $cc  the Cc addresses as typed
 * @param mixed $subject  the email subject
 * @param mixed $body  the analyst's HTML text, without the quoted thread
 * @param mixed $attachments  the files the analyst attached, from the request: [{name, type, content (base64)}, ...]
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
 *
 * @param mixed $emailId  the emails.id of the sent message; also names its folder on disk
 * @param mixed $attachments  the files the analyst attached, from the request: [{name, type, content (base64)}, ...]
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
