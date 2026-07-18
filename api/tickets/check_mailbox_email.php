<?php
/**
 * API Endpoint: Check emails for a specific mailbox
 *
 * This uses the mailbox settings from the database instead of config constants.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/encryption.php';
require_once '../../includes/tenancy.php';
require_once '../../includes/ticket_reply.php';
require_once '../../includes/mailbox_graph.php';

header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

// The inbox's 'check for new mail' action — everyday work. It had NO module check.
requireModuleAccessJson('tickets');

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

    // Determine provider + auth mode
    $provider = $mailbox['provider'] ?? 'microsoft';
    $authMode = $mailbox['auth_mode'] ?? 'delegated';

    // Point every Graph call at the right mailbox for this request: delegated reads
    // /me (whoever signed in); app-only reads the specific /users/<target_mailbox>.
    mailboxResolveGraphBase($mailbox);

    if ($provider === 'imap') {
        // Basic IMAP: no OAuth / stored token — the connection authenticates with the
        // stored username + password each time. Use a placeholder token so the shared
        // downstream guards (which expect a truthy access token) pass; IMAP never makes
        // a Graph/Gmail call.
        require_once dirname(dirname(__DIR__)) . '/includes/mailbox_imap.php';
        $accessToken = 'imap-session';
    } elseif ($provider === 'microsoft' && $authMode === 'app_only') {
        // App-only (client credentials): no interactive sign-in / stored token needed —
        // the app authenticates itself and reads the exact target mailbox.
        try {
            $accessToken = mailboxAppOnlyToken($conn, $mailbox);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => 'App-only authentication failed: ' . $e->getMessage()]);
            exit;
        }
    } else {
        // Delegated (Microsoft sign-in) or Google: requires a stored token.
        if (empty($mailbox['token_data'])) {
            echo json_encode(['success' => false, 'error' => 'Mailbox is not authenticated. Please authenticate first.']);
            exit;
        }

        // Clean token data by removing any null bytes or control characters
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
            if ($provider === 'google') {
                require_once dirname(dirname(__DIR__)) . '/includes/gmail.php';
                $accessToken = gmailGetValidAccessToken($conn, $mailbox, $tokenData);
            } else {
                $accessToken = getValidAccessToken($conn, $mailbox, $tokenData);
            }
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

        // SAFETY (delegated Microsoft): make sure we're reading the RIGHT inbox. The
        // token belongs to whoever signed in. Back-fill the identity (primary + aliases)
        // for mailboxes that signed in before we recorded it, then block only on a
        // CONFIRMED mismatch. A target that matches any owned alias passes; the genuine
        // "wrong account" / "changed address" cases (issue #26) get caught.
        if ($provider === 'microsoft') {
            $mailbox = mailboxBackfillIdentity($conn, $mailbox, $accessToken);
            if ($mismatchError = mailboxIdentityMismatch($mailbox)) {
                echo json_encode(['success' => false, 'error' => $mismatchError]);
                exit;
            }
        }
    }

    if (!$accessToken) {
        echo json_encode(['success' => false, 'error' => 'Failed to obtain valid access token. Please re-authenticate.']);
        exit;
    }

    // Fetch emails — branch by provider
    if ($provider === 'imap') {
        $emails = imapGetEmails($mailbox);
    } elseif ($provider === 'google') {
        $emails = gmailGetEmails($accessToken, $mailbox);
    } else {
        $emails = getEmails($accessToken, $mailbox);
    }

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

    // Load whitelist for this mailbox
    $whitelist = loadWhitelist($conn, $mailboxId);
    $hasWhitelist = !empty($whitelist['domains']) || !empty($whitelist['emails']);

    // Get action settings
    $rejectedAction = $mailbox['rejected_action'] ?? 'delete';
    $importedAction = $mailbox['imported_action'] ?? 'delete';
    $importedFolder = $mailbox['imported_folder'] ?? null;

    $mailboxSettings = [
        'imported_action' => $importedAction,
        'imported_folder' => $importedFolder,
        'rejected_action' => $rejectedAction,
    ];

    // Save emails to database
    $savedCount = 0;
    $rejectedCount = 0;
    $errors = [];
    $activityEntries = [];

    foreach ($emails as $email) {
        $fromAddress = strtolower(trim($email['from']['emailAddress']['address'] ?? ''));
        $fromName = $email['from']['emailAddress']['name'] ?? '';
        $subject = $email['subject'] ?? '(No Subject)';

        // Check whitelist
        if ($hasWhitelist && !isWhitelisted($fromAddress, $whitelist)) {
            $processingLog = ['steps' => [], 'mailbox_settings' => $mailboxSettings];

            $domain = explode('@', $fromAddress)[1] ?? '';
            $processingLog['steps'][] = [
                'step' => 'whitelist_check',
                'result' => 'rejected',
                'details' => "Sender {$fromAddress} (domain {$domain}) not whitelisted"
            ];

            // Reject: handle according to rejected_action setting
            $postAction = ['step' => 'post_action', 'action' => $rejectedAction];
            try {
                $httpCode = handleEmailAfterProcessing($accessToken, $email['id'], $rejectedAction, null, $provider, $mailbox);
                $postAction['result'] = 'success';
                $postAction['http_code'] = $httpCode;
            } catch (Exception $delEx) {
                $errors[] = 'Failed to handle rejected email from ' . $fromAddress . ': ' . $delEx->getMessage();
                $postAction['result'] = 'error';
                $postAction['error'] = $delEx->getMessage();
            }
            $processingLog['steps'][] = $postAction;

            $rejectedCount++;
            $activityEntries[] = [
                'action' => 'rejected',
                'from_address' => $fromAddress,
                'from_name' => $fromName,
                'subject' => $subject,
                'reason' => 'Not whitelisted',
                'ticket_id' => null,
                'processing_log' => json_encode($processingLog)
            ];
            continue;
        }

        try {
            $processingLog = ['steps' => [], 'mailbox_settings' => $mailboxSettings];

            if ($hasWhitelist) {
                $domain = explode('@', $fromAddress)[1] ?? '';
                $processingLog['steps'][] = [
                    'step' => 'whitelist_check',
                    'result' => 'passed',
                    'details' => "Domain {$domain} whitelisted"
                ];
            }

            $importResult = saveEmailToDatabase($conn, $email, $accessToken, $mailboxId);
            $ticketId = $importResult['ticket_id'] ?? $importResult;
            $isNewTicket = $importResult['is_new_ticket'] ?? false;
            $processingLog['steps'][] = [
                'step' => 'import',
                'result' => 'success',
                'ticket_id' => $ticketId ?: null,
                'is_new_ticket' => $isNewTicket
            ];
            $savedCount++;

            // Handle imported email according to imported_action setting
            $postAction = ['step' => 'post_action', 'action' => $importedAction];
            if ($importedAction === 'move_to_folder' && $importedFolder) {
                $postAction['folder'] = $importedFolder;
            }
            try {
                $httpCode = handleEmailAfterProcessing($accessToken, $email['id'], $importedAction, $importedFolder, $provider, $mailbox);
                $postAction['result'] = 'success';
                $postAction['http_code'] = $httpCode;
            } catch (Exception $delEx) {
                $errors[] = 'Imported but failed to handle email ID ' . $email['id'] . ': ' . $delEx->getMessage();
                $postAction['result'] = 'error';
                $postAction['error'] = $delEx->getMessage();
            }
            $processingLog['steps'][] = $postAction;

            // Send template email for new tickets
            if ($isNewTicket && $ticketId) {
                $templateStep = ['step' => 'template_email', 'event' => 'new_ticket_email'];
                try {
                    require_once dirname(dirname(__DIR__)) . '/includes/template_email.php';
                    sendTemplateEmail($conn, $ticketId, 'new_ticket_email');
                    $templateStep['result'] = 'success';
                } catch (Exception $tplEx) {
                    $templateStep['result'] = 'error';
                    $templateStep['error'] = $tplEx->getMessage();
                }
                $processingLog['steps'][] = $templateStep;
            }

            $activityEntries[] = [
                'action' => 'imported',
                'from_address' => $fromAddress,
                'from_name' => $fromName,
                'subject' => $subject,
                'reason' => null,
                'ticket_id' => $ticketId ?: null,
                'processing_log' => json_encode($processingLog)
            ];
        } catch (Exception $e) {
            $errors[] = 'Failed to save email ID ' . $email['id'] . ': ' . $e->getMessage();
        }
    }

    // Log activity (only if emails were actually processed)
    if (!empty($activityEntries)) {
        logMailboxActivity($conn, $mailboxId, $activityEntries);
    }

    // Update last checked time
    updateLastChecked($conn, $mailboxId);

    $message = "Processed {$savedCount} imported";
    if ($rejectedCount > 0) {
        $message .= ", {$rejectedCount} rejected";
    }
    $message .= " email(s) from " . $mailbox['target_mailbox'];

    echo json_encode([
        'success' => true,
        'message' => $message,
        'details' => [
            'emails_found' => count($emails),
            'emails_saved' => $savedCount,
            'emails_rejected' => $rejectedCount,
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

    $sql = "UPDATE target_mailboxes SET token_data = ? WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$jsonData, $mailboxId]);
}

// App-only token + Graph base path now live in includes/mailbox_graph.php
// (shared with send_email.php and verify_mailbox_folder.php).

/**
 * Update last checked datetime
 */
function updateLastChecked($conn, $mailboxId) {
    $sql = "UPDATE target_mailboxes SET last_checked_datetime = UTC_TIMESTAMP() WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$mailboxId]);
}

/**
 * Retrieve emails from Microsoft Graph API
 */
function getEmails($accessToken, $mailbox) {
    $graphUrl = 'https://graph.microsoft.com/v1.0' . mailboxGraphBase() . '/mailFolders/' . $mailbox['email_folder'] . '/messages';

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
    $graphUrl = 'https://graph.microsoft.com/v1.0' . mailboxGraphBase() . '/messages/' . $messageId;

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
 * Resolve a folder display name to a Graph API folder ID.
 * Maps well-known display names first, then queries Graph API.
 * Results are cached within the same request.
 */
function resolveMailFolderId($accessToken, $folderName) {
    static $cache = [];
    if (isset($cache[$folderName])) {
        return $cache[$folderName];
    }

    // Well-known folder display names -> Graph API names
    $wellKnown = [
        'Inbox'         => 'inbox',
        'Drafts'        => 'drafts',
        'Sent Items'    => 'sentitems',
        'Deleted Items' => 'deleteditems',
        'Junk Email'    => 'junkemail',
        'Archive'       => 'archive',
        'Outbox'        => 'outbox',
    ];

    foreach ($wellKnown as $displayName => $apiName) {
        if (strcasecmp($folderName, $displayName) === 0) {
            $cache[$folderName] = $apiName;
            return $apiName;
        }
    }

    // Check if already a well-known API name
    if (in_array(strtolower($folderName), array_values($wellKnown))) {
        $cache[$folderName] = strtolower($folderName);
        return strtolower($folderName);
    }

    // Query Graph API by displayName
    $graphUrl = 'https://graph.microsoft.com/v1.0' . mailboxGraphBase() . '/mailFolders?'
        . http_build_query(['$filter' => "displayName eq '" . str_replace("'", "''", $folderName) . "'"]);

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
        curl_close($ch);
        throw new Exception('cURL error resolving folder: ' . curl_error($ch));
    }

    curl_close($ch);

    if ($httpCode !== 200) {
        throw new Exception('Failed to resolve folder "' . $folderName . '". HTTP ' . $httpCode);
    }

    $data = json_decode($response, true);
    $folders = $data['value'] ?? [];

    if (empty($folders)) {
        throw new Exception('Folder "' . $folderName . '" not found in mailbox');
    }

    $folderId = $folders[0]['id'];
    $cache[$folderName] = $folderId;
    return $folderId;
}

/**
 * Move an email to a folder via Microsoft Graph API
 */
function moveEmailToFolder($accessToken, $messageId, $folderName) {
    $destinationId = resolveMailFolderId($accessToken, $folderName);

    $graphUrl = 'https://graph.microsoft.com/v1.0' . mailboxGraphBase() . '/messages/' . $messageId . '/move';

    $body = json_encode(['destinationId' => $destinationId]);

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $graphUrl);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
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
        curl_close($ch);
        throw new Exception('cURL error when moving email: ' . curl_error($ch));
    }

    curl_close($ch);

    if ($httpCode !== 200 && $httpCode !== 201) {
        throw new Exception('Failed to move email to "' . $folderName . '". HTTP ' . $httpCode);
    }

    return $httpCode;
}

/**
 * Mark an email as read via Microsoft Graph API
 */
function markEmailAsRead($accessToken, $messageId) {
    $graphUrl = 'https://graph.microsoft.com/v1.0' . mailboxGraphBase() . '/messages/' . $messageId;

    $body = json_encode(['isRead' => true]);

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $graphUrl);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
    curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
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
        curl_close($ch);
        throw new Exception('cURL error when marking email as read: ' . curl_error($ch));
    }

    curl_close($ch);

    if ($httpCode !== 200) {
        throw new Exception('Failed to mark email as read. HTTP Code: ' . $httpCode);
    }
}

/**
 * Handle email after processing based on action setting
 * Actions: 'delete' (permanent), 'move_to_deleted' (Deleted Items), 'mark_read' (leave in inbox), 'move_to_folder' (custom folder)
 */
function handleEmailAfterProcessing($accessToken, $messageId, $action, $folderName = null, $provider = 'microsoft', $mailbox = null) {
    if ($provider === 'imap') {
        // $messageId is the IMAP UID for basic mailboxes.
        return imapHandleAfterProcessing($mailbox, (int) $messageId, $action, $folderName);
    }

    if ($provider === 'google') {
        switch ($action) {
            case 'mark_read':
                gmailMarkAsRead($accessToken, $messageId);
                return 200;
            case 'move_to_deleted':
            case 'delete':
            default:
                gmailTrashMessage($accessToken, $messageId);
                return 200;
            case 'move_to_folder':
                // Gmail doesn't support arbitrary folders the same way — mark as read instead
                gmailMarkAsRead($accessToken, $messageId);
                return 200;
        }
    }

    // Microsoft (default)
    switch ($action) {
        case 'move_to_deleted':
            return moveEmailToFolder($accessToken, $messageId, 'deleteditems');
        case 'mark_read':
            markEmailAsRead($accessToken, $messageId);
            return 200;
        case 'move_to_folder':
            if ($folderName) {
                return moveEmailToFolder($accessToken, $messageId, $folderName);
            }
            deleteEmailFromMailbox($accessToken, $messageId);
            return 204;
        case 'delete':
        default:
            deleteEmailFromMailbox($accessToken, $messageId);
            return 204;
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
    $insertSql = "INSERT INTO users (email, display_name, created_at) VALUES (?, ?, UTC_TIMESTAMP())";
    $insertStmt = $conn->prepare($insertSql);
    $insertStmt->execute([$email, $displayName]);

    return $conn->lastInsertId();
}

/**
 * Fetch attachments from Graph API
 */
function fetchEmailAttachments($accessToken, $emailId) {
    $graphUrl = 'https://graph.microsoft.com/v1.0' . mailboxGraphBase() . '/messages/' . $emailId . '/attachments';

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
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";

    $stmt = $conn->prepare($sql);
    $stmt->execute([
        $dbEmailId, $attachmentId, $filename, $contentType,
        $contentId, $filePath, $fileSize, $isInline ? 1 : 0
    ]);

    $dbAttachmentId = $conn->lastInsertId();

    return [
        'id' => $dbAttachmentId,
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
    // De-dup / stored message id. Providers whose native message id isn't globally
    // unique (IMAP UIDs collide across mailboxes) supply an 'internet_message_id'
    // (the Message-ID header) to key on instead.
    $emailId = $email['internet_message_id'] ?? $email['id'] ?? null;

    // Check if email already exists
    $checkSql = "SELECT id FROM emails WHERE exchange_message_id = ?";
    $checkStmt = $conn->prepare($checkSql);
    $checkStmt->execute([$emailId]);
    $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        return false;
    }

    // The provider-native id (Graph/Gmail message id, or IMAP UID) — used only for
    // the second attachment fetch on Microsoft.
    $providerMessageId = $email['id'] ?? null;
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
            $updateTicketSql = "UPDATE tickets SET updated_datetime = UTC_TIMESTAMP() WHERE id = ?";
            $updateTicketStmt = $conn->prepare($updateTicketSql);
            $updateTicketStmt->execute([$ticketId]);

            // The requester has come back. If the ticket was finished, reopen it
            // (Tickets → Settings → General) — the notification email they were
            // replying to says "just reply to this email and it will be reopened",
            // and until now nothing honoured that. Shared with the self-service
            // portal so both doors behave identically; non-fatal by design, so a
            // reopen problem can never cost us the inbound message.
            reopenTicketForCustomerReply($conn, (int)$ticketId);

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

        // Multi-tenancy: route the new ticket to a company. Pinned mailbox → its
        // company; shared intake → sender-domain match → that company, else triage
        // (NULL). Always the Default company on a single-company install.
        $ticketTenantId = resolveTicketTenantForEmail($conn, $mailboxId, $fromAddress);

        $ticketSql = "INSERT INTO tickets (
            ticket_number, subject, status_id, priority_id,
            created_datetime, updated_datetime, user_id, tenant_id
        ) VALUES (
            ?, ?,
            (SELECT id FROM ticket_statuses   WHERE name = 'Open'   LIMIT 1),
            (SELECT id FROM ticket_priorities WHERE name = 'Normal' LIMIT 1),
            ?, UTC_TIMESTAMP(), ?, ?
        )";

        $ticketStmt = $conn->prepare($ticketSql);
        $ticketStmt->execute([
            $ticketNumber, $subject, $receivedDateTime, $userId, $ticketTenantId
        ]);

        $ticketId = $conn->lastInsertId();
    }

    // Insert email
    $sql = "INSERT INTO emails (
        exchange_message_id, subject, from_address, from_name, to_recipients,
        cc_recipients, received_datetime, body_preview, body_content, body_type,
        has_attachments, importance, is_read, processed_datetime, ticket_id,
        is_initial, direction, mailbox_id
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), ?, ?, 'Inbound', ?)";

    $params = [
        $emailId, $subject, $fromAddress, $fromName, $toRecipientsStr,
        $ccRecipientsStr, $receivedDateTime, $bodyPreview, $bodyContent, $bodyType,
        $hasAttachments ? 1 : 0, $importance, $isRead ? 1 : 0, $ticketId,
        $isInitial, $mailboxId
    ];

    $stmt = $conn->prepare($sql);
    $stmt->execute($params);

    $dbEmailId = $conn->lastInsertId();

    // Process attachments
    // Check for inline images by looking for cid: references in the body
    $hasCidReferences = preg_match('/cid:/i', $bodyContent);
    $attachmentInfo = [];

    // Attachments come one of two ways:
    //  - Pre-loaded inline by the connector (IMAP / Gmail), already Graph-shaped —
    //    IMAP can't re-fetch a part after disconnecting, so they ride along with the
    //    message. (This also fixes Gmail, whose attachments a Graph-only fetch missed.)
    //  - Fetched on demand from Microsoft Graph via a second call.
    $preloadedAttachments = $email['attachments_inline'] ?? null;
    if (($hasAttachments || $hasCidReferences) && ($preloadedAttachments !== null || $accessToken)) {
        try {
            $graphAttachments = ($preloadedAttachments !== null)
                ? $preloadedAttachments
                : fetchEmailAttachments($accessToken, $providerMessageId);
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

    return ['ticket_id' => $ticketId, 'is_new_ticket' => ($isInitial == 1)];
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
                   VALUES ('email_import', NULL, ?, UTC_TIMESTAMP())";
        $logStmt = $conn->prepare($logSql);
        $logStmt->execute([json_encode($details)]);
    } catch (Exception $e) {
        // Silently fail - don't break email import if logging fails
        error_log('Failed to log email import: ' . $e->getMessage());
    }
}

/**
 * Load whitelist entries for a mailbox
 */
function loadWhitelist($conn, $mailboxId) {
    $stmt = $conn->prepare("SELECT entry_type, entry_value FROM mailbox_email_whitelist WHERE mailbox_id = ?");
    $stmt->execute([$mailboxId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $domains = [];
    $emails = [];

    foreach ($rows as $row) {
        $value = strtolower(trim($row['entry_value']));
        if ($row['entry_type'] === 'domain') {
            $domains[] = $value;
        } else {
            $emails[] = $value;
        }
    }

    return ['domains' => $domains, 'emails' => $emails];
}

/**
 * Check if a sender address is whitelisted
 */
function isWhitelisted($fromAddress, $whitelist) {
    $fromAddress = strtolower(trim($fromAddress));

    // Check exact email match
    if (in_array($fromAddress, $whitelist['emails'])) {
        return true;
    }

    // Check domain match
    $parts = explode('@', $fromAddress);
    if (count($parts) === 2) {
        $domain = $parts[1];
        if (in_array($domain, $whitelist['domains'])) {
            return true;
        }
    }

    return false;
}

/**
 * Log mailbox activity (imports and rejections)
 */
function logMailboxActivity($conn, $mailboxId, $entries) {
    try {
        $stmt = $conn->prepare("INSERT INTO mailbox_activity_log
            (mailbox_id, action, from_address, from_name, subject, reason, ticket_id, processing_log, created_datetime)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())");

        foreach ($entries as $entry) {
            $stmt->execute([
                $mailboxId,
                $entry['action'],
                $entry['from_address'],
                $entry['from_name'],
                $entry['subject'],
                $entry['reason'],
                $entry['ticket_id'],
                $entry['processing_log'] ?? null
            ]);
        }
    } catch (Exception $e) {
        error_log('Failed to log mailbox activity: ' . $e->getMessage());
    }
}
?>
