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

session_start();
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/encryption.php';

header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

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
    $attachments = $input['attachments'] ?? [];

    // Validate required fields
    if (empty($to)) {
        throw new Exception('Recipient email address is required');
    }
    if (empty($subject)) {
        throw new Exception('Subject is required');
    }
    if (empty($ticketId)) {
        throw new Exception('Ticket ID is required to determine which mailbox to send from');
    }

    // Get database connection
    $conn = connectToDatabase();

    // Get the mailbox for this ticket
    $mailbox = getMailboxForTicket($conn, $ticketId);

    if (!$mailbox) {
        throw new Exception('Could not determine mailbox for this ticket. Please ensure the ticket has associated emails.');
    }

    if (empty($mailbox['token_data'])) {
        throw new Exception('Mailbox "' . $mailbox['target_mailbox'] . '" is not authenticated. Please authenticate in Settings.');
    }

    // Parse token data (clean ODBC control characters)
    $cleanedTokenData = preg_replace('/[\x00-\x1F\x7F]/', '', $mailbox['token_data']);
    $tokenData = json_decode($cleanedTokenData, true);

    if ($tokenData === null) {
        throw new Exception('Failed to parse token data for mailbox: ' . json_last_error_msg());
    }

    // Get valid access token (will refresh if needed)
    $accessToken = getValidAccessToken($conn, $mailbox, $tokenData);

    if (!$accessToken) {
        throw new Exception('Failed to obtain valid access token. Please re-authenticate the mailbox.');
    }

    // Build the email message for Graph API
    $message = buildEmailMessage($to, $cc, $subject, $body, $attachments);

    // Send the email via Graph API
    $result = sendEmailViaGraph($accessToken, $message);

    // Save sent email to database
    saveSentEmail($conn, $ticketId, $mailbox, $to, $cc, $subject, $body);

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
    $sql = "SELECT TOP 1 tm.*
            FROM emails e
            INNER JOIN target_mailboxes tm ON e.mailbox_id = tm.id
            WHERE e.ticket_id = ?
            ORDER BY e.is_initial DESC, e.received_datetime ASC";
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
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, SSL_VERIFY_PEER);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, SSL_VERIFY_PEER ? 2 : 0);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if (curl_errno($ch)) {
        throw new Exception('cURL error: ' . curl_error($ch));
    }

    curl_close($ch);

    if ($httpCode !== 200) {
        throw new Exception('Failed to refresh token. HTTP Code: ' . $httpCode);
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
    $jsonData = json_encode($tokenData);
    // Escape single quotes and use direct SQL to avoid ODBC encoding issues
    $escapedJson = str_replace("'", "''", $jsonData);

    $sql = "UPDATE target_mailboxes SET token_data = '$escapedJson' WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$mailboxId]);
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
    $graphUrl = 'https://graph.microsoft.com/v1.0/me/sendMail';

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $graphUrl);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($message));
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
 * Save sent email to database
 */
function saveSentEmail($conn, $ticketId, $mailbox, $to, $cc, $subject, $body) {
    try {
        $sql = "INSERT INTO emails (
            subject, from_address, from_name, to_recipients, cc_recipients,
            received_datetime, body_content, body_type, ticket_id, is_initial, direction, mailbox_id
        ) VALUES (?, ?, ?, ?, ?, GETDATE(), ?, 'html', ?, 0, 'Outbound', ?)";

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

        // Update ticket's updated_datetime
        $updateSql = "UPDATE tickets SET updated_datetime = GETDATE() WHERE id = ?";
        $updateStmt = $conn->prepare($updateSql);
        $updateStmt->execute([$ticketId]);
    } catch (Exception $e) {
        // Log error but don't fail the send operation
        error_log('Failed to save sent email to database: ' . $e->getMessage());
    }
}
