<?php
/**
 * API Endpoint: Check emails for a specific mailbox
 *
 * This uses the mailbox settings from the database instead of config constants.
 */
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

// Get mailbox ID from request
$data = json_decode(file_get_contents('php://input'), true);
$mailboxId = $data['mailbox_id'] ?? $_GET['mailbox_id'] ?? null;

if (!$mailboxId) {
    echo json_encode(['success' => false, 'error' => 'Mailbox ID is required']);
    exit;
}

try {
    $conn = connectToDatabase();

    // Get mailbox configuration
    $mailbox = getMailboxConfig($conn, $mailboxId);

    if (!$mailbox) {
        echo json_encode(['success' => false, 'error' => 'Mailbox not found']);
        exit;
    }

    if (!$mailbox['is_active']) {
        echo json_encode(['success' => false, 'error' => 'Mailbox is inactive']);
        exit;
    }

    // Check if mailbox is authenticated
    if (empty($mailbox['token_data'])) {
        echo json_encode(['success' => false, 'error' => 'Mailbox is not authenticated. Please authenticate first.']);
        exit;
    }

    // Get valid access token
    // Clean token data by removing any null bytes or control characters that ODBC may have inserted
    $rawTokenData = $mailbox['token_data'];
    $cleanedTokenData = preg_replace('/[\x00-\x1F\x7F]/', '', $rawTokenData);

    $tokenData = json_decode($cleanedTokenData, true);

    // Check if JSON parsing failed
    if ($tokenData === null && json_last_error() !== JSON_ERROR_NONE) {
        echo json_encode([
            'success' => false,
            'error' => 'Failed to parse token data: ' . json_last_error_msg(),
            'debug' => [
                'raw_length' => strlen($rawTokenData),
                'cleaned_length' => strlen($cleanedTokenData),
                'first_50' => substr($cleanedTokenData, 0, 50)
            ]
        ]);
        exit;
    }

    try {
        $accessToken = getValidAccessToken($conn, $mailbox, $tokenData);
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage(),
            'debug' => [
                'has_access_token' => isset($tokenData['access_token']),
                'has_refresh_token' => isset($tokenData['refresh_token']),
                'expires_at' => $tokenData['expires_at'] ?? 'not set',
                'current_time' => time()
            ]
        ]);
        exit;
    }

    if (!$accessToken) {
        echo json_encode(['success' => false, 'error' => 'Failed to obtain valid access token. Please re-authenticate.']);
        exit;
    }

    // Fetch emails from Graph API
    $emails = getEmails($accessToken, $mailbox);

    if (empty($emails)) {
        // Update last checked time
        updateLastChecked($conn, $mailboxId);

        echo json_encode([
            'success' => true,
            'message' => 'No new emails found in ' . $mailbox['email_folder'],
            'details' => [
                'emails_processed' => 0,
                'mailbox' => $mailbox['target_mailbox']
            ]
        ]);
        exit;
    }

    // Save emails to database
    $savedCount = 0;
    $errors = [];

    foreach ($emails as $email) {
        try {
            saveEmailToDatabase($conn, $email, $accessToken, $mailboxId);
            $savedCount++;

            // Delete the email from the mailbox after successful import
            try {
                deleteEmailFromMailbox($accessToken, $email['id']);
            } catch (Exception $delEx) {
                $errors[] = 'Imported but failed to delete email ID ' . $email['id'] . ': ' . $delEx->getMessage();
            }
        } catch (Exception $e) {
            $errors[] = 'Failed to save email ID ' . $email['id'] . ': ' . $e->getMessage();
        }
    }

    // Update last checked time
    updateLastChecked($conn, $mailboxId);

    echo json_encode([
        'success' => true,
        'message' => "Successfully processed {$savedCount} email(s) from " . $mailbox['target_mailbox'],
        'details' => [
            'emails_found' => count($emails),
            'emails_saved' => $savedCount,
            'errors' => $errors,
            'mailbox' => $mailbox['target_mailbox'],
            'folder' => $mailbox['email_folder']
        ]
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}


/**
 * Get mailbox configuration from database
 */
function getMailboxConfig($conn, $mailboxId) {
    $sql = "SELECT * FROM target_mailboxes WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$mailboxId]);
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
 * Refresh the access token using refresh token
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
 * Update last checked datetime
 */
function updateLastChecked($conn, $mailboxId) {
    $sql = "UPDATE target_mailboxes SET last_checked_datetime = GETUTCDATE() WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$mailboxId]);
}

/**
 * Retrieve emails from Microsoft Graph API
 */
function getEmails($accessToken, $mailbox) {
    $graphUrl = 'https://graph.microsoft.com/v1.0/me/mailFolders/' . $mailbox['email_folder'] . '/messages';

    $params = [
        '$top' => $mailbox['max_emails_per_check'],
        '$select' => 'id,subject,from,toRecipients,ccRecipients,receivedDateTime,bodyPreview,body,hasAttachments,importance,isRead',
        '$orderby' => 'receivedDateTime DESC',
        '$filter' => 'isRead eq false'
    ];

    $graphUrl .= '?' . http_build_query($params);

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $graphUrl);
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
        throw new Exception('cURL error when fetching emails: ' . curl_error($ch));
    }

    curl_close($ch);

    if ($httpCode !== 200) {
        throw new Exception('Failed to fetch emails. HTTP Code: ' . $httpCode . '. Response: ' . $response);
    }

    $data = json_decode($response, true);
    return $data['value'] ?? [];
}

/**
 * Delete an email from the mailbox via Microsoft Graph API
 */
function deleteEmailFromMailbox($accessToken, $messageId) {
    $graphUrl = 'https://graph.microsoft.com/v1.0/me/messages/' . $messageId;

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $graphUrl);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, SSL_VERIFY_PEER);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, SSL_VERIFY_PEER ? 2 : 0);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $accessToken
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if (curl_errno($ch)) {
        curl_close($ch);
        throw new Exception('cURL error when deleting email: ' . curl_error($ch));
    }

    curl_close($ch);

    // Graph API returns 204 No Content on successful delete
    if ($httpCode !== 204 && $httpCode !== 200) {
        throw new Exception('Failed to delete email. HTTP Code: ' . $httpCode);
    }
}

/**
 * Generate unique ticket number
 */
function generateTicketNumber($conn) {
    $maxAttempts = 10;
    $attempt = 0;

    while ($attempt < $maxAttempts) {
        $letters = chr(rand(65, 90)) . chr(rand(65, 90)) . chr(rand(65, 90));
        $numbers1 = rand(0, 9) . rand(0, 9) . rand(0, 9);
        $numbers2 = rand(0, 9) . rand(0, 9) . rand(0, 9) . rand(0, 9) . rand(0, 9);

        $ticketNumber = $letters . '-' . $numbers1 . '-' . $numbers2;

        $checkSql = "SELECT COUNT(*) FROM tickets WHERE ticket_number = ?";
        $checkStmt = $conn->prepare($checkSql);
        $checkStmt->execute([$ticketNumber]);
        $exists = $checkStmt->fetchColumn();

        if (!$exists) {
            return $ticketNumber;
        }

        $attempt++;
    }

    throw new Exception('Failed to generate unique ticket number');
}

/**
 * Extract ticket reference from subject
 */
function extractTicketReference($subject) {
    if (preg_match('/\[SDREF:([A-Z]{3}-\d{3}-\d{5})\]/i', $subject, $matches)) {
        return $matches[1];
    }
    return null;
}

/**
 * Find existing ticket by number
 */
function findTicketByNumber($conn, $ticketNumber) {
    $sql = "SELECT id FROM tickets WHERE ticket_number = ?";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$ticketNumber]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    return $result ? $result['id'] : null;
}

/**
 * Get or create user by email address
 * Returns the user ID
 */
function getOrCreateUser($conn, $email, $displayName) {
    // Normalize email to lowercase
    $email = strtolower(trim($email));

    // Check if user already exists
    $sql = "SELECT id FROM users WHERE email = ?";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$email]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($result) {
        // Update display name if it changed (and we have a new one)
        if (!empty($displayName)) {
            $updateSql = "UPDATE users SET display_name = ? WHERE id = ? AND (display_name IS NULL OR display_name != ?)";
            $updateStmt = $conn->prepare($updateSql);
            $updateStmt->execute([$displayName, $result['id'], $displayName]);
        }
        return $result['id'];
    }

    // Create new user
    $insertSql = "INSERT INTO users (email, display_name, created_at) VALUES (?, ?, GETUTCDATE());
                  SELECT SCOPE_IDENTITY() AS user_id";
    $insertStmt = $conn->prepare($insertSql);
    $insertStmt->execute([$email, $displayName]);

    $insertStmt->nextRowset();
    $newUser = $insertStmt->fetch(PDO::FETCH_ASSOC);

    return $newUser['user_id'];
}

/**
 * Fetch attachments from Graph API
 */
function fetchEmailAttachments($accessToken, $emailId) {
    $graphUrl = 'https://graph.microsoft.com/v1.0/me/messages/' . $emailId . '/attachments';

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $graphUrl);
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
        throw new Exception('cURL error when fetching attachments: ' . curl_error($ch));
    }

    curl_close($ch);

    if ($httpCode !== 200) {
        return [];
    }

    $data = json_decode($response, true);
    return $data['value'] ?? [];
}

/**
 * Save attachment to filesystem and database
 */
function saveAttachment($conn, $dbEmailId, $attachment) {
    $attachmentId = $attachment['id'] ?? '';
    $filename = $attachment['name'] ?? 'unknown';
    $contentType = $attachment['contentType'] ?? 'application/octet-stream';
    $contentId = $attachment['contentId'] ?? null;
    $isInline = $attachment['isInline'] ?? false;
    $contentBytes = $attachment['contentBytes'] ?? '';

    if (empty($contentBytes)) {
        return null;
    }

    $fileData = base64_decode($contentBytes);
    $fileSize = strlen($fileData);

    $attachmentsDir = dirname(dirname(__DIR__)) . '/tickets/attachments';
    if (!is_dir($attachmentsDir)) {
        mkdir($attachmentsDir, 0755, true);
    }

    $subDir = floor($dbEmailId / 1000);
    $emailDir = $attachmentsDir . '/' . $subDir . '/' . $dbEmailId;
    if (!is_dir($emailDir)) {
        mkdir($emailDir, 0755, true);
    }

    $safeFilename = preg_replace('/[^a-zA-Z0-9._-]/', '_', $filename);
    $filePath = $subDir . '/' . $dbEmailId . '/' . $safeFilename;
    $fullPath = $attachmentsDir . '/' . $filePath;

    $counter = 1;
    $pathInfo = pathinfo($safeFilename);
    while (file_exists($fullPath)) {
        $newFilename = $pathInfo['filename'] . '_' . $counter . '.' . ($pathInfo['extension'] ?? '');
        $filePath = $subDir . '/' . $dbEmailId . '/' . $newFilename;
        $fullPath = $attachmentsDir . '/' . $filePath;
        $counter++;
    }

    if (file_put_contents($fullPath, $fileData) === false) {
        throw new Exception('Failed to save attachment file: ' . $filename);
    }

    $sql = "INSERT INTO email_attachments (
        email_id, exchange_attachment_id, filename, content_type,
        content_id, file_path, file_size, is_inline
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?);
    SELECT SCOPE_IDENTITY() AS attachment_id";

    $stmt = $conn->prepare($sql);
    $stmt->execute([
        $dbEmailId, $attachmentId, $filename, $contentType,
        $contentId, $filePath, $fileSize, $isInline ? 1 : 0
    ]);

    $stmt->nextRowset();
    $result = $stmt->fetch(PDO::FETCH_ASSOC);

    return [
        'id' => $result['attachment_id'],
        'content_id' => $contentId,
        'filename' => $filename
    ];
}

/**
 * Rewrite CID references in email body
 */
function rewriteCidReferences($bodyContent, $dbEmailId, $attachments) {
    foreach ($attachments as $attachment) {
        if (!empty($attachment['content_id'])) {
            $cid = trim($attachment['content_id'], '<>');
            $apiUrl = '/api/tickets/get_attachment.php?cid=' . urlencode($cid) . '&email_id=' . $dbEmailId;

            // Simple string replacements
            $bodyContent = str_ireplace('cid:' . $cid, $apiUrl, $bodyContent);
            $bodyContent = str_ireplace('cid:' . $attachment['content_id'], $apiUrl, $bodyContent);
            $bodyContent = str_ireplace('cid:' . str_replace('@', '%40', $cid), $apiUrl, $bodyContent);
        }
    }

    // Post-process: sanitize any src attributes containing our API URL
    // Remove any non-printable ASCII characters that may have crept in
    $bodyContent = preg_replace_callback(
        '/src=["\']([^"\']*\/api\/tickets\/get_attachment\.php[^"\']*)["\']/',
        function($matches) {
            // Keep only printable ASCII characters (space through tilde, plus common URL chars)
            $cleanUrl = preg_replace('/[^\x20-\x7E]/', '', $matches[1]);
            return 'src="' . $cleanUrl . '"';
        },
        $bodyContent
    );

    return $bodyContent;
}

/**
 * Save email to database
 */
function saveEmailToDatabase($conn, $email, $accessToken, $mailboxId) {
    // Check if email already exists
    $checkSql = "SELECT id FROM emails WHERE exchange_message_id = ?";
    $checkStmt = $conn->prepare($checkSql);
    $checkStmt->execute([$email['id']]);
    $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        return false;
    }

    // Extract email data
    $emailId = $email['id'] ?? null;
    $subject = $email['subject'] ?? '(No Subject)';
    $fromAddress = $email['from']['emailAddress']['address'] ?? '';
    $fromName = $email['from']['emailAddress']['name'] ?? '';
    $receivedDateTime = $email['receivedDateTime'] ?? null;
    $bodyPreview = $email['bodyPreview'] ?? '';
    $bodyContent = $email['body']['content'] ?? '';
    $bodyType = $email['body']['contentType'] ?? 'text';
    $hasAttachments = $email['hasAttachments'] ?? false;
    $importance = $email['importance'] ?? 'normal';
    $isRead = $email['isRead'] ?? false;

    // Extract recipients
    $toRecipients = [];
    if (isset($email['toRecipients'])) {
        foreach ($email['toRecipients'] as $recipient) {
            $toRecipients[] = $recipient['emailAddress']['address'];
        }
    }
    $toRecipientsStr = implode('; ', $toRecipients);

    $ccRecipients = [];
    if (isset($email['ccRecipients'])) {
        foreach ($email['ccRecipients'] as $recipient) {
            $ccRecipients[] = $recipient['emailAddress']['address'];
        }
    }
    $ccRecipientsStr = implode('; ', $ccRecipients);

    if ($receivedDateTime) {
        // Graph API returns UTC — use gmdate() to preserve UTC
        $receivedDateTime = gmdate('Y-m-d H:i:s', strtotime($receivedDateTime));
    }

    // Check for ticket reference
    $ticketRef = extractTicketReference($subject);
    $ticketId = null;
    $isInitial = 1;

    if ($ticketRef) {
        $ticketId = findTicketByNumber($conn, $ticketRef);
        if ($ticketId) {
            $isInitial = 0;
            $updateTicketSql = "UPDATE tickets SET updated_datetime = GETUTCDATE() WHERE id = ?";
            $updateTicketStmt = $conn->prepare($updateTicketSql);
            $updateTicketStmt->execute([$ticketId]);

            // For replies to existing tickets, strip the quoted thread
            // Look for our reply marker and store only the new content above it
            $bodyContent = stripInboundThread($bodyContent);
        }
    }

    // Get or create user from sender
    $userId = getOrCreateUser($conn, $fromAddress, $fromName);

    // Create new ticket if needed
    if (!$ticketId) {
        $ticketNumber = generateTicketNumber($conn);

        $ticketSql = "INSERT INTO tickets (
            ticket_number, subject, status, priority, requester_email,
            requester_name, created_datetime, updated_datetime, user_id
        ) VALUES (?, ?, 'Open', 'Normal', ?, ?, ?, GETUTCDATE(), ?);
        SELECT SCOPE_IDENTITY() AS ticket_id";

        $ticketStmt = $conn->prepare($ticketSql);
        $ticketStmt->execute([
            $ticketNumber, $subject, $fromAddress, $fromName, $receivedDateTime, $userId
        ]);

        $ticketStmt->nextRowset();
        $result = $ticketStmt->fetch(PDO::FETCH_ASSOC);
        $ticketId = $result['ticket_id'];
    }

    // Insert email
    $sql = "INSERT INTO emails (
        exchange_message_id, subject, from_address, from_name, to_recipients,
        cc_recipients, received_datetime, body_preview, body_content, body_type,
        has_attachments, importance, is_read, processed_datetime, ticket_id,
        is_initial, direction, mailbox_id
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, GETUTCDATE(), ?, ?, 'Inbound', ?);
    SELECT SCOPE_IDENTITY() AS email_id";

    $params = [
        $emailId, $subject, $fromAddress, $fromName, $toRecipientsStr,
        $ccRecipientsStr, $receivedDateTime, $bodyPreview, $bodyContent, $bodyType,
        $hasAttachments ? 1 : 0, $importance, $isRead ? 1 : 0, $ticketId,
        $isInitial, $mailboxId
    ];

    $stmt = $conn->prepare($sql);
    $stmt->execute($params);

    $stmt->nextRowset();
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $dbEmailId = $result['email_id'];

    // Process attachments
    // Check for inline images by looking for cid: references in the body
    $hasCidReferences = preg_match('/cid:/i', $bodyContent);
    $attachmentInfo = [];

    // Fetch attachments if hasAttachments is true OR if body contains cid: references
    if (($hasAttachments || $hasCidReferences) && $accessToken) {
        try {
            $graphAttachments = fetchEmailAttachments($accessToken, $emailId);
            $savedAttachments = [];

            foreach ($graphAttachments as $attachment) {
                if (($attachment['@odata.type'] ?? '') === '#microsoft.graph.fileAttachment') {
                    $savedAttachment = saveAttachment($conn, $dbEmailId, $attachment);
                    if ($savedAttachment) {
                        $savedAttachments[] = $savedAttachment;
                        // Collect attachment info for logging (only non-inline for the log)
                        $isInline = $attachment['isInline'] ?? false;
                        if (!$isInline) {
                            $attachmentInfo[] = [
                                'name' => $attachment['name'] ?? 'unknown',
                                'type' => $attachment['contentType'] ?? 'unknown',
                                'size' => $attachment['size'] ?? 0
                            ];
                        }
                    }
                }
            }

            if (!empty($savedAttachments)) {
                $rewrittenBody = rewriteCidReferences($bodyContent, $dbEmailId, $savedAttachments);

                // Update body content and ensure has_attachments is set to true
                $updateSql = "UPDATE emails SET body_content = ?, has_attachments = 1 WHERE id = ?";
                $updateStmt = $conn->prepare($updateSql);
                $updateStmt->execute([$rewrittenBody, $dbEmailId]);
            }
        } catch (Exception $e) {
            error_log('Failed to process attachments for email ' . $emailId . ': ' . $e->getMessage());
        }
    }

    // Log the email import
    logEmailImport($conn, $mailboxId, [
        'from' => $fromAddress,
        'from_name' => $fromName,
        'subject' => $subject,
        'received_datetime' => $receivedDateTime,
        'ticket_id' => $ticketId,
        'is_new_ticket' => $isInitial == 1,
        'attachments' => $attachmentInfo
    ]);

    return true;
}

/**
 * Strip the quoted thread from an inbound reply
 * Relies on our own visible marker text which survives all email clients,
 * with a generic blockquote fallback
 */
function stripInboundThread($bodyContent) {
    $stripped = null;

    // 1. Our visible marker text: "Please reply above this line"
    if (preg_match('/\x{2014}\s*Please reply above this line\s*\x{2014}/u', $bodyContent, $matches, PREG_OFFSET_CAPTURE)) {
        $s = trim(substr($bodyContent, 0, $matches[0][1]));
        if (!empty($s)) $stripped = $s;
    }

    // 2. Our data-reply-marker div (if the email client preserved it)
    if ($stripped === null && preg_match('/<div[^>]*data-reply-marker="true"[^>]*>/i', $bodyContent, $matches, PREG_OFFSET_CAPTURE)) {
        $s = trim(substr($bodyContent, 0, $matches[0][1]));
        if (!empty($s)) $stripped = $s;
    }

    // 3. Legacy SDREF marker text from older emails
    if ($stripped === null && preg_match('/\[\*{3}\s*SDREF:[A-Z]{3}-\d{3}-\d{5}\s*REPLY ABOVE THIS LINE\s*\*{3}\]/i', $bodyContent, $matches, PREG_OFFSET_CAPTURE)) {
        $s = trim(substr($bodyContent, 0, $matches[0][1]));
        if (!empty($s)) $stripped = $s;
    }

    // 4. Generic fallback: blockquote (only if there's content before it)
    if ($stripped === null && preg_match('/<blockquote[^>]*>/i', $bodyContent, $matches, PREG_OFFSET_CAPTURE)) {
        $s = trim(substr($bodyContent, 0, $matches[0][1]));
        if (!empty($s)) $stripped = $s;
    }

    if ($stripped === null) $stripped = $bodyContent;

    // Remove trailing "On [date], [name] wrote:" attribution lines added by email clients
    $stripped = preg_replace('/(<br\s*\/?>|\s|<\/?div[^>]*>)*\bOn\s+.{10,120}\s+wrote:\s*(<\/?div[^>]*>|<br\s*\/?>|\s)*$/is', '', $stripped);

    return trim($stripped);
}

/**
 * Log email import to system_logs
 */
function logEmailImport($conn, $mailboxId, $details) {
    try {
        $details['mailbox_id'] = $mailboxId;
        $logSql = "INSERT INTO system_logs (log_type, analyst_id, details, created_datetime)
                   VALUES ('email_import', NULL, ?, GETUTCDATE())";
        $logStmt = $conn->prepare($logSql);
        $logStmt->execute([json_encode($details)]);
    } catch (Exception $e) {
        // Silently fail - don't break email import if logging fails
        error_log('Failed to log email import: ' . $e->getMessage());
    }
}
?>
