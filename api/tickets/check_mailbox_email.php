<?php
/**
 * API Endpoint: Check emails for a specific mailbox
 *
 * This uses the mailbox settings from the database instead of config constants.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/ticket_numbering.php';
require_once '../../includes/encryption.php';
require_once '../../includes/tenancy.php';
require_once '../../includes/ticket_reply.php';
require_once '../../includes/ticket_snooze.php';
require_once '../../includes/mailbox_graph.php';
require_once '../../includes/uploads.php';   // uploadStoreBytes() — see F1

header('Content-Type: application/json');

// Changes data: never on a GET, which a link on another site can send (csrf.php).
csrfRequireWriteMethod();

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

    /* TRAP: every open ticket inbox polls every mailbox every minute, so five
       analysts meant five checks a minute - and two landing together both
       fetched the same unread email. The second found it already imported and
       logged "import: success, ticket_id: null" (GH #183); had both looked
       before either saved, they would have made TWO tickets. So: one check per
       mailbox at a time (the lock is released when this request's connection
       closes), and an automatic poll stands down if any check of this mailbox
       finished in the last 50 seconds. A manual Check is never throttled. */
    $lock = $conn->prepare("SELECT GET_LOCK(CONCAT('freeitsm_mbx_', LEFT(SHA1(DATABASE()), 12), '_', ?), 0)");
    $lock->execute([(int)$mailboxId]);
    if ((int)$lock->fetchColumn() !== 1) {
        mailboxCheckStandDown($mailbox, 'busy', 'Another check of ' . $mailbox['target_mailbox'] . ' is already running');
    }
    if (!empty($data['auto']) && !empty($mailbox['last_checked_datetime'])
        && time() - strtotime($mailbox['last_checked_datetime'] . ' UTC') < 50) {
        mailboxCheckStandDown($mailbox, 'recent', $mailbox['target_mailbox'] . ' was checked less than a minute ago');
    }

    // Determine provider + auth mode
    $provider = MailboxProvider::fromMailbox($mailbox);
    $authMode = $mailbox['auth_mode'] ?? 'delegated';

    // Point every Graph call at the right mailbox for this request: delegated reads
    // /me (whoever signed in); app-only reads the specific /users/<target_mailbox>.
    mailboxResolveGraphBase($mailbox);

    if ($provider === MailboxProvider::Imap) {
        // Basic IMAP: no OAuth / stored token — the connection authenticates with the
        // stored username + password each time. Use a placeholder token so the shared
        // downstream guards (which expect a truthy access token) pass; IMAP never makes
        // a Graph/Gmail call.
        require_once dirname(dirname(__DIR__)) . '/includes/mailbox_imap.php';
        $accessToken = 'imap-session';
    } elseif ($provider === MailboxProvider::Microsoft && $authMode === 'app_only') {
        // App-only (client credentials): no interactive sign-in / stored token needed —
        // the app authenticates itself and reads the exact target mailbox.
        try {
            $accessToken = mailboxAppOnlyToken($conn, $mailbox);
        } catch (Exception $e) {
            failMailboxCheck($conn, $mailboxId, 'App-only authentication failed: ' . $e->getMessage());
        }
    } else {
        // Delegated (Microsoft sign-in) or Google: requires a stored token.
        if (empty($mailbox['token_data'])) {
            failMailboxCheck($conn, $mailboxId, 'Mailbox is not authenticated. Please authenticate first.');
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
            if ($provider === MailboxProvider::Google) {
                require_once dirname(dirname(__DIR__)) . '/includes/gmail.php';
                $accessToken = gmailGetValidAccessToken($conn, $mailbox, $tokenData);
            } else {
                $accessToken = getValidAccessToken($conn, $mailbox, $tokenData);
            }
        } catch (Exception $e) {
            // 🔴 THE ONE THAT WENT UNNOTICED FOR EIGHTEEN HOURS: an expired Azure
            // client secret. The message now carries the provider's own words
            // (see oauthTokenErrorMessage), and RECORDING it is what lets the
            // mailbox row say so on its own rather than waiting to be clicked.
            failMailboxCheck($conn, $mailboxId, $e->getMessage(), ['debug' => [
                'has_access_token'  => isset($tokenData['access_token']),
                'has_refresh_token' => isset($tokenData['refresh_token']),
                'expires_at'        => $tokenData['expires_at'] ?? 'not set',
                'current_time'      => time(),
            ]]);
        }

        // SAFETY (delegated Microsoft): make sure we're reading the RIGHT inbox. The
        // token belongs to whoever signed in. Back-fill the identity (primary + aliases)
        // for mailboxes that signed in before we recorded it, then block only on a
        // CONFIRMED mismatch. A target that matches any owned alias passes; the genuine
        // "wrong account" / "changed address" cases (issue #26) get caught.
        if ($provider === MailboxProvider::Microsoft) {
            $mailbox = mailboxBackfillIdentity($conn, $mailbox, $accessToken);
            if ($mismatchError = mailboxIdentityMismatch($mailbox)) {
                failMailboxCheck($conn, $mailboxId, $mismatchError);
            }
        }
    }

    if (!$accessToken) {
        failMailboxCheck($conn, $mailboxId, 'Failed to obtain valid access token. Please re-authenticate.');
    }

    // Fetch emails — branch by provider
    if ($provider === MailboxProvider::Imap) {
        $emails = imapGetEmails($mailbox);
    } elseif ($provider === MailboxProvider::Google) {
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

    // Attachment size limits and what an oversized email does (GH #170) - the
    // install defaults, or this mailbox's own. See includes/attachment_limits.php.
    $oversizeRules = oversizeRules($conn, $mailbox);

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

        // ── Size check (GH #170) ─────────────────────────────────────────────
        // Attachments are fetched HERE, before the email is imported, because
        // "reject the whole email" has to be decided before a ticket exists. The
        // same fetched list is handed on to saveEmailToDatabase(), so nothing is
        // downloaded twice. An email already imported is left to the de-dup below.
        $prefetched = null;
        $sizePlan = null;
        $sizeAtts = [];
        try {
            [$prefetched, $sizeAtts] = oversizeMeasure($conn, $email, $accessToken);
            if ($sizeAtts) $sizePlan = oversizePlan($sizeAtts, $oversizeRules);
        } catch (Exception $measureEx) {
            $prefetched = null;   // measuring failed: import exactly as before, and say so
            $errors[] = 'Could not measure attachments on email from ' . $fromAddress . ': ' . $measureEx->getMessage();
        }

        if ($sizePlan && $sizePlan['reject']) {
            $processingLog = ['steps' => [], 'mailbox_settings' => $mailboxSettings];
            $processingLog['steps'][] = oversizeLogStep($sizeAtts, $sizePlan, $oversizeRules, 'rejected');
            $postAction = ['step' => 'post_action', 'action' => $rejectedAction];
            try {
                $httpCode = handleEmailAfterProcessing($accessToken, $email['id'], $rejectedAction, null, $provider, $mailbox);
                $postAction['result'] = 'success';
                $postAction['http_code'] = $httpCode;
            } catch (Exception $delEx) {
                $errors[] = 'Failed to handle oversized email from ' . $fromAddress . ': ' . $delEx->getMessage();
                $postAction['result'] = 'error';
                $postAction['error'] = $delEx->getMessage();
            }
            $processingLog['steps'][] = $postAction;
            foreach (oversizeNotify($conn, $mailbox, $oversizeRules, $fromAddress, $fromName, $subject, $sizeAtts, $sizePlan, 'rejected') as $s) {
                $processingLog['steps'][] = $s;
            }
            $rejectedCount++;
            $activityEntries[] = [
                'action' => 'rejected',
                'from_address' => $fromAddress,
                'from_name' => $fromName,
                'subject' => $subject,
                'reason' => mb_substr('Too large: ' . oversizeSummary($sizeAtts, $sizePlan), 0, 255),
                'ticket_id' => null,
                'processing_log' => json_encode($processingLog)
            ];
            continue;
        }

        try {
            $processingLog = ['steps' => [], 'mailbox_settings' => $mailboxSettings];
            if ($sizePlan && $sizePlan['over']) {
                $processingLog['steps'][] = oversizeLogStep($sizeAtts, $sizePlan, $oversizeRules, 'stripped');
            }

            if ($hasWhitelist) {
                $domain = explode('@', $fromAddress)[1] ?? '';
                $processingLog['steps'][] = [
                    'step' => 'whitelist_check',
                    'result' => 'passed',
                    'details' => "Domain {$domain} whitelisted"
                ];
            }

            $importResult = saveEmailToDatabase($conn, $email, $accessToken, $mailboxId, $prefetched,
                                                $sizePlan['strip'] ?? [], (int)$oversizeRules['max_bytes']);
            $ticketId = $importResult['ticket_id'] ?? null;
            $isNewTicket = $importResult['is_new_ticket'] ?? false;
            // Already in the database (GH #183): an earlier check imported it and
            // the email is still in the mailbox - usually because the action after
            // importing failed that time. Nothing new is saved; it is logged as
            // what it is, with the ticket it went to, and the action below runs
            // again so it does not come back on every check.
            $alreadyImported = !empty($importResult['already_imported']);
            $processingLog['steps'][] = $alreadyImported ? [
                'step' => 'import',
                'result' => 'already_imported',
                'details' => 'This email was imported by an earlier check; nothing new was saved',
                'ticket_id' => $ticketId ?: null,
            ] : [
                'step' => 'import',
                'result' => 'success',
                'ticket_id' => $ticketId ?: null,
                'is_new_ticket' => $isNewTicket
            ];
            if (!$alreadyImported) $savedCount++;

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

            // Attachments removed for size: tell whoever the settings say, and
            // record it on the log row so it is findable without opening it.
            $sizeReason = null;
            if ($sizePlan && $sizePlan['over'] && !$alreadyImported) {
                foreach (oversizeNotify($conn, $mailbox, $oversizeRules, $fromAddress, $fromName, $subject, $sizeAtts, $sizePlan, 'stripped', $ticketId ? (int)$ticketId : null) as $s) {
                    $processingLog['steps'][] = $s;
                }
                $sizeReason = mb_substr('Attachments removed (too large): ' . oversizeSummary($sizeAtts, $sizePlan), 0, 255);
            }

            $activityEntries[] = [
                'action' => $alreadyImported ? 'already_imported' : 'imported',
                'from_address' => $fromAddress,
                'from_name' => $fromName,
                'subject' => $subject,
                'reason' => $sizeReason,
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

    // Record how it went. ⚠️ A check that FOUND mail and could not save it has
    // not worked, whatever its status says — that is exactly the shape the
    // client-secret outage took on its way out: "1 found, 0 saved", success:true,
    // and the reason sitting unread in an array. So per-email failures are
    // recorded as the mailbox's last error too.
    recordMailboxCheckOutcome($conn, $mailboxId, $errors ? implode(' | ', $errors) : null);

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
    // The check itself fell over — record it so the mailbox can say so
    // without somebody having to click Check and read the response.
    // ⚠️ Guarded: this catch also covers failures BEFORE the connection or the
    // mailbox id exist, and recording an outage must never become the outage.
    if (isset($conn) && !empty($mailboxId)) {
        recordMailboxCheckOutcome($conn, $mailboxId, $e->getMessage());
    }
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}


/**
 * Get mailbox configuration from database
 *
 * @param mixed $mailboxId  the target_mailboxes id to load
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
 * Refresh the access token using refresh token
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

// App-only token + Graph base path now live in includes/mailbox_graph.php
// (shared with send_email.php and verify_mailbox_folder.php).

/**
 * Record how a mail check went — stamping the time AND what went wrong.
 *
 * 🔴 WHY THIS EXISTS. A mailbox stopped collecting for eighteen hours and said
 * nothing. The Azure client secret had expired; the provider said so plainly;
 * FreeITSM discarded the sentence and left the mailbox looking green and
 * "Authenticated" in the list. The only way to find out was to click Check and
 * read the response.
 *
 * So an outcome is now written down. A clean check clears the error; a failed
 * one keeps the provider's own words, and mailbox health turns that into a
 * non-dismissible error on the mailbox row.
 *
 * ⚠️ PARTIAL FAILURE COUNTS AS FAILURE. The same outage also produced checks
 * that returned success while saving nothing — "1 email found, 0 saved" with
 * the reason buried in an errors array nobody was reading. A check that fetched
 * mail it could not turn into tickets has not worked, whatever its status said.
 *
 * @param mixed $mailboxId  the target_mailboxes id the check was for
 * @param ?string $error null on a clean run; the reason otherwise.
 */
function recordMailboxCheckOutcome($conn, $mailboxId, ?string $error = null) {
    try {
        $stmt = $conn->prepare(
            "UPDATE target_mailboxes
                SET last_checked_datetime = UTC_TIMESTAMP(),
                    last_error            = ?,
                    last_error_datetime   = " . ($error === null ? "NULL" : "UTC_TIMESTAMP()") . "
              WHERE id = ?"
        );
        // 1000 characters is plenty for any provider sentence and stops a stack
        // trace or an HTML error page filling the column.
        $stmt->execute([$error === null ? null : mb_substr($error, 0, 1000), $mailboxId]);
    } catch (Exception $e) {
        // Never let recording a failure BE the failure — an install that has not
        // run db_verify since these columns arrived must still collect mail.
    }
}

/**
 * Report a check that could not run at all: record it, say so, stop.
 *
 * One function rather than a recording line beside each of the eight exits,
 * because the exit somebody forgets to update is exactly the silent one.
 *
 * @param mixed $mailboxId  the target_mailboxes id the check was for
 * @param string $error  the reason, recorded on the mailbox and sent back as the error
 * @param array $extra  more fields to add to the JSON reply
 */
function failMailboxCheck($conn, $mailboxId, string $error, array $extra = []) {
    recordMailboxCheckOutcome($conn, $mailboxId, $error);
    echo json_encode(array_merge(['success' => false, 'error' => $error], $extra));
    exit;
}

/**
 * A check that did not run because it did not need to: another check of this
 * mailbox is running, or (for an automatic poll) one finished moments ago.
 * Not an error and not a check, so the mailbox's last-checked time and last
 * error are left exactly as they were.
 *
 * @param array $mailbox  the target_mailboxes row
 * @param string $why  'busy' or 'recent'
 * @param string $message  what to say
 */
function mailboxCheckStandDown(array $mailbox, string $why, string $message) {
    echo json_encode([
        'success' => true,
        'skipped' => $why,
        'message' => $message,
        'details' => ['emails_saved' => 0, 'mailbox' => $mailbox['target_mailbox']],
    ]);
    exit;
}

/**
 * Update last checked datetime (clean run).
 *
 * @param mixed $mailboxId  the target_mailboxes id the check was for
 */
function updateLastChecked($conn, $mailboxId) {
    recordMailboxCheckOutcome($conn, $mailboxId, null);
}

/**
 * Retrieve emails from Microsoft Graph API
 *
 * @param mixed $accessToken  the mailbox's Microsoft Graph access token, from getValidAccessToken()
 * @param mixed $mailbox  the target_mailboxes row - email_folder and max_emails_per_check are read
 */
function getEmails($accessToken, $mailbox) {
    // The configured folder is a NAME a person typed. Graph wants an id (or one of
    // its handful of well-known aliases) in this path, so anything else came back
    // as "400 ErrorInvalidIdMalformed" — GH #77. INBOX only ever worked because it
    // is on the alias list; reading any other folder by name had never worked.
    $folderId = mailboxResolveFolderId($mailbox['email_folder'] ?? 'inbox',
        function ($url) use ($accessToken) { return mailboxGraphGet($accessToken, $url); });

    $graphUrl = 'https://graph.microsoft.com/v1.0' . mailboxGraphBase()
        . '/mailFolders/' . rawurlencode($folderId) . '/messages';

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
    sslApplyCurl($ch);
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
 *
 * @param mixed $accessToken  the mailbox's Microsoft Graph access token, from getValidAccessToken()
 * @param mixed $messageId  the Graph message id to delete for good
 */
function deleteEmailFromMailbox($accessToken, $messageId) {
    $graphUrl = 'https://graph.microsoft.com/v1.0' . mailboxGraphBase() . '/messages/' . $messageId;

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $graphUrl);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    sslApplyCurl($ch);
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
 * Resolve a folder name to what Graph will accept in a path.
 *
 * Delegates to mailboxResolveFolderId() in includes/mailbox_graph.php. It used to
 * be implemented here AND again in verify_mailbox_folder.php — which is how the
 * two disagreed: Verify resolved "freeitsm" happily while the code that actually
 * READ the mailbox never called either of them (GH #77). One implementation now,
 * so the button and the fetch can no longer answer differently.
 *
 * @param mixed $accessToken  the mailbox's Microsoft Graph access token, from getValidAccessToken()
 * @param mixed $folderName  the folder name as typed in the mailbox settings, or a well-known alias such as 'deleteditems'
 */
function resolveMailFolderId($accessToken, $folderName) {
    return mailboxResolveFolderId($folderName,
        function ($url) use ($accessToken) { return mailboxGraphGet($accessToken, $url); });
}

/**
 * Move an email to a folder via Microsoft Graph API
 *
 * @param mixed $accessToken  the mailbox's Microsoft Graph access token, from getValidAccessToken()
 * @param mixed $messageId  the Graph message id to move
 * @param mixed $folderName  the destination folder name, or a well-known alias such as 'deleteditems'
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
    sslApplyCurl($ch);
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
 *
 * @param mixed $accessToken  the mailbox's Microsoft Graph access token, from getValidAccessToken()
 * @param mixed $messageId  the Graph message id to mark as read
 */
function markEmailAsRead($accessToken, $messageId) {
    $graphUrl = 'https://graph.microsoft.com/v1.0' . mailboxGraphBase() . '/messages/' . $messageId;

    $body = json_encode(['isRead' => true]);

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $graphUrl);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
    curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    sslApplyCurl($ch);
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
 *
 * @param mixed $accessToken  the provider access token (Graph or Gmail); unused for IMAP
 * @param mixed $messageId  the provider's message id - the IMAP UID for a basic mailbox
 * @param mixed $action  'delete', 'move_to_deleted', 'mark_read' or 'move_to_folder'
 * @param mixed $folderName  the folder for 'move_to_folder'; without one the email is deleted instead (Microsoft)
 * @param MailboxProvider $provider  where the mailbox's mail comes from
 * @param mixed $mailbox  the target_mailboxes row; needed only for IMAP
 */
function handleEmailAfterProcessing($accessToken, $messageId, $action, $folderName = null, MailboxProvider $provider = MailboxProvider::Microsoft, $mailbox = null) {
    if ($provider === MailboxProvider::Imap) {
        // $messageId is the IMAP UID for basic mailboxes.
        return imapHandleAfterProcessing($mailbox, (int) $messageId, $action, $folderName);
    }

    if ($provider === MailboxProvider::Google) {
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
/**
 * ⚠️ The third of three copies, now delegating to the single engine in
 * includes/ticket_numbering.php (GH #71). An emailed ticket must be numbered by
 * exactly the same rules as one raised by an analyst or through the portal.
 */
function generateTicketNumber($conn, ?int $tenantId = null, ?int $typeId = null) {
    // ⚠️ THE COMPANY MUST BE PASSED IN — see the call site, where the routing
    // decision now happens BEFORE the number is made rather than after it. The
    // type too, since GH #230 can give an emailed ticket one.
    return TicketNumbering::next($conn, $typeId, $tenantId);
}

/**
 * Extract ticket reference from subject
 *
 * @param mixed $subject  the inbound email's subject, which may carry an [SDREF:...] tag
 */
function extractTicketReference($subject) {
    // ⚠️ FORMAT-AGNOSTIC, and it has to stay that way. This used to look for
    // [A-Z]{3}-\d{3}-\d{5} — the shape of the old random numbers. Now that an
    // install can choose its own format, a shape-matching parser would stop
    // recognising every email it had ever sent the moment the format changed.
    // Capture whatever is in the tag; the DATABASE decides if it is real.
    if (preg_match(TicketNumbering::REF_PATTERN, $subject, $matches)) {
        return $matches[1];
    }
    return null;
}

/**
 * Find existing ticket by number.
 *
 * FOLLOWS MERGES. Every notification FreeITSM has ever sent carries [SDREF:ABC-…] in
 * its subject, and those emails live in customers' inboxes forever. When ABC is later
 * merged into DEF, a reply to any of them still quotes ABC — and without this it would
 * append to a ticket that is closed, flagged as merged, and which nobody is watching.
 * The customer would get silence.
 *
 * This is the single point every inbound reply passes through, which is exactly why
 * the redirect belongs here rather than at each call site.
 *
 * @param mixed $ticketNumber  the ticket number taken from the subject tag; old and renumbered numbers are found too
 */
function findTicketByNumber($conn, $ticketNumber) {
    // 🔑 Looks in ticket_number_history as well as tickets (GH #71). A ticket
    // that has been RENUMBERED keeps every number it has ever had, for exactly
    // the same reason merges are followed below: the emails quoting the old one
    // live in customers' inboxes forever. Without this, renumbering would turn
    // every historical reply into a brand-new ticket, silently.
    $foundId = TicketNumbering::findTicketId($conn, $ticketNumber);
    if ($foundId === null) {
        return null;
    }

    require_once __DIR__ . '/../../includes/ticket_merge.php';
    $liveId = resolveMergedTicket($conn, $foundId);
    if ($liveId !== $foundId) {
        error_log("Inbound reply quoted $ticketNumber, which was merged — routing to ticket $liveId");
    }
    return $liveId;
}

/**
 * Get or create user by email address
 * Returns the user ID
 *
 * @param mixed $email  the sender's address; lower-cased and trimmed before use
 * @param mixed $displayName  the sender's name from the email; empty leaves an existing name alone
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
 *
 * @param mixed $accessToken  the mailbox's Microsoft Graph access token, from getValidAccessToken()
 * @param mixed $emailId  the Graph message id whose attachments are wanted
 */
function fetchEmailAttachments($accessToken, $emailId) {
    $graphUrl = 'https://graph.microsoft.com/v1.0' . mailboxGraphBase() . '/messages/' . $emailId . '/attachments';

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $graphUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    sslApplyCurl($ch);
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
 *
 * @param mixed $dbEmailId  the emails.id the attachment belongs to; also names its folder on disk
 * @param mixed $attachment  one Graph fileAttachment: name, contentType, contentId, isInline, contentBytes (base64)
 * @param ?int $maxBytes  the mailbox's per-attachment limit in bytes; null uses the install's
 */
function saveAttachment($conn, $dbEmailId, $attachment, ?int $maxBytes = null) {
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

    $attachmentsDir = dirname(dirname(__DIR__)) . '/tickets/attachments';
    $subDir   = floor($dbEmailId / 1000);
    $emailDir = $attachmentsDir . '/' . $subDir . '/' . $dbEmailId;

    // ⚠️ This is the least authenticated path in the application — anyone who can
    // send an email reaches it. It used to write the sender's own filename into the
    // web root after only stripping the characters that make a path separator, so
    // shell.php survived intact. uploadStoreBytes() applies the extension and
    // content checks and, crucially, names the file itself.
    $stored = uploadStoreBytes(
        $fileData,
        $filename,
        $emailDir,
        attachmentRejectPolicy($conn),
        attachmentAllowedTypes($conn),
        $maxBytes   // the mailbox's own limit when it has one; null = the install's
    );

    if (!$stored['stored']) {
        // Not written. Reported to the caller so the ingest can note it on the
        // ticket rather than losing the file in silence.
        return [
            'id'         => null,
            'content_id' => $contentId,
            'filename'   => $stored['original_name'],
            'rejected'   => true,
            'reason'     => $stored['reason'],
        ];
    }

    $filePath = $subDir . '/' . $dbEmailId . '/' . $stored['stored_name'];

    $sql = "INSERT INTO email_attachments (
        email_id, exchange_attachment_id, filename, content_type,
        content_id, file_path, file_size, is_inline
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";

    // filename keeps the sender's name for DISPLAY only — the path above is ours.
    // content_type is the type we detected from the bytes, not the one the sender
    // declared on the MIME part; nothing downstream should ever trust theirs.
    $stmt = $conn->prepare($sql);
    $stmt->execute([
        $dbEmailId, $attachmentId, $stored['original_name'], $stored['mime'],
        $contentId, $filePath, $stored['size'], $isInline ? 1 : 0
    ]);

    $dbAttachmentId = $conn->lastInsertId();

    return [
        'id'          => $dbAttachmentId,
        'content_id'  => $contentId,
        'filename'    => $stored['original_name'],
        'rejected'    => false,
        'quarantined' => $stored['quarantined'],
        'reason'      => $stored['reason'],
    ];
}

/**
 * Rewrite CID references in email body
 *
 * @param mixed $bodyContent  the email's HTML body
 * @param mixed $dbEmailId  the emails.id the attachments were saved under
 * @param mixed $attachments  the saved attachment rows; each content_id is pointed at get_attachment.php
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
 *
 * @param mixed $email  one message in Graph's shape (id, subject, from, body, hasAttachments...); IMAP adds internet_message_id
 * @param mixed $accessToken  the provider access token, used to fetch attachments on Microsoft
 * @param mixed $mailboxId  the target_mailboxes id the email came in through
 * @param ?array $prefetched  the attachment list already fetched by oversizeMeasure(); null fetches it here
 * @param array $strip  attachments to leave out, file-attachment index => reason, from oversizePlan()
 * @param ?int $maxBytes  the mailbox's per-attachment limit in bytes; null uses the install's
 */
function saveEmailToDatabase($conn, $email, $accessToken, $mailboxId, ?array $prefetched = null, array $strip = [], ?int $maxBytes = null) {
    // De-dup / stored message id. Providers whose native message id isn't globally
    // unique (IMAP UIDs collide across mailboxes) supply an 'internet_message_id'
    // (the Message-ID header) to key on instead.
    $emailId = $email['internet_message_id'] ?? $email['id'] ?? null;

    // Check if email already exists
    $checkSql = "SELECT id, ticket_id FROM emails WHERE exchange_message_id = ? LIMIT 1";
    $checkStmt = $conn->prepare($checkSql);
    $checkStmt->execute([$emailId]);
    $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        // Not false: the caller logged false as "import: success" with no ticket (GH #183).
        return ['already_imported' => true, 'ticket_id' => $existing['ticket_id'] ? (int)$existing['ticket_id'] : null, 'is_new_ticket' => false];
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

            // …and if it was asleep, wake it. A snooze is nearly always "waiting on
            // them", so their reply is the very event it was waiting for.
            wakeSnoozedTicketOnCustomerReply($conn, (int)$ticketId);

            // For replies to existing tickets, strip the quoted thread
            // Look for our reply marker and store only the new content above it
            $bodyContent = stripInboundThread($bodyContent);
        }
    }

    // Get or create user from sender
    $userId = getOrCreateUser($conn, $fromAddress, $fromName);

    // Create new ticket if needed
    if (!$ticketId) {

        // Multi-tenancy: route the new ticket to a company. Pinned mailbox → its
        // company; shared intake → sender-domain match → that company, else triage
        // (NULL). Always the Default company on a single-company install.
        //
        // 🔴 THIS MUST COME BEFORE THE NUMBER IS MADE. Under per-company ticket
        // numbering the number carries the company's code and draws from that
        // company's own counter — so numbering first, as this used to, gave every
        // emailed ticket the DEFAULT company's number no matter who it was routed
        // to. Email is the busiest intake path on an MSP install, which is exactly
        // where per-company numbering is chosen.
        $ticketTenantId = resolveTicketTenantForEmail($conn, $mailboxId, $fromAddress);

        // Where the ticket came from, as configured on THIS mailbox (#79). Per
        // mailbox rather than one global "Email", because a helpdesk address and a
        // monitoring alert address both arrive by email and are not the same
        // source. NULL when the mailbox has no origin set — the ⚠ on the mailbox
        // row says so rather than letting it stay invisible.
        $originStmt = $conn->prepare("SELECT default_origin_id FROM target_mailboxes WHERE id = ?");
        $originStmt->execute([$mailboxId]);
        $ticketOriginId = $originStmt->fetchColumn();
        $ticketOriginId = $ticketOriginId ? (int) $ticketOriginId : null;

        // GH #230: an emailed ticket's type comes from the type rules / default -
        // before the number, which can carry the type.
        require_once __DIR__ . '/../../includes/ticket_type_rules.php';
        $ticketTypePick = ticketTypeAutoPick($conn, $ticketTenantId, [
            'channel' => 'email', 'mailbox_id' => $mailboxId, 'origin_id' => $ticketOriginId,
            'subject' => $subject, 'requester_email' => $fromAddress,
        ]);
        $ticketTypeId = $ticketTypePick['type_id'] ?? null;

        $ticketNumber = generateTicketNumber($conn, $ticketTenantId, $ticketTypeId);

        // Status/priority resolve to the CONFIGURED default, never to the literal
        // names 'Open'/'Normal' — those are display names an admin may rename or
        // translate, and a missed match silently inserted a NULL status_id (#79).
        // GH #218: an emailed ticket has no department of its own (a mailbox cannot
        // set one), so it starts in the company's default department, if any.
        require_once __DIR__ . '/../../includes/tenant_settings.php';
        [$departmentId, $departmentNote] = ticketDepartmentForNewTicket($conn, $ticketTenantId !== null ? (int) $ticketTenantId : null, null);

        $ticketSql = "INSERT INTO tickets (
            ticket_number, subject, status_id, priority_id,
            created_datetime, updated_datetime, user_id, tenant_id, origin_id, ticket_type_id, department_id
        ) VALUES (
            ?, ?,
            (SELECT id FROM ticket_statuses   WHERE is_active = 1 ORDER BY is_default DESC, display_order, id LIMIT 1),
            (SELECT id FROM ticket_priorities WHERE is_active = 1 ORDER BY is_default DESC, display_order, id LIMIT 1),
            ?, UTC_TIMESTAMP(), ?, ?, ?, ?, ?
        )";

        $ticketStmt = $conn->prepare($ticketSql);
        $ticketStmt->execute([
            $ticketNumber, $subject, $receivedDateTime, $userId, $ticketTenantId, $ticketOriginId, $ticketTypeId, $departmentId
        ]);

        $ticketId = $conn->lastInsertId();
        ticketDepartmentNote($conn, (int) $ticketId, $departmentNote);
        ticketTypeAutoRecord($conn, (int)$ticketId, $ticketTypePick);
        // Announced further down, once the opening message has been written —
        // anything listening (the search indexer especially) wants the ticket
        // and its first message to both exist.
        $ticketWasCreatedHere = true;
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

    // ticket.reply_received (discussion #55) — only for a reply, never for the
    // email that CREATED the ticket. ticket.created already covers that one, and
    // firing both would put two notifications on a single new ticket.
    //
    // Runs under the mailbox poll, which has no analyst session, so the actor is
    // nobody and the assignee is correctly told even though they own the ticket.
    // A ticket that arrived by email now announces itself. It never did: this
    // file writes its own INSERT and never went near TicketsService, so the
    // comment above — "ticket.created already covers that one" — was describing
    // an event that was not being fired for this channel at all. The reply event
    // is suppressed for the opening message on the strength of it, so a new email
    // ticket used to announce NOTHING, to workflows or to the notification bell.
    //
    // Placed here so the opening message row already exists: the search indexer
    // reads the ticket and its messages together.
    if (!empty($ticketWasCreatedHere) && $ticketId) {
        // Confidential mailbox (discussion #62) - BEFORE the ticket is announced.
        // This is the path a grievance emailed to HR takes, and an emailed ticket
        // gets no department, so the mailbox is the only thing that can protect it.
        require_once __DIR__ . '/../../includes/ticket_sensitivity.php';
        ticketSensitivityApplyDefaults($conn, (int)$ticketId, isset($mailboxId) ? (int)$mailboxId : null);

        require_once __DIR__ . '/../../includes/ticket_events.php';
        // created_by is null on purpose — nobody signed in created this, it
        // arrived. The requester's address is resolved from the linked user.
        ticketDispatchCreated($conn, (int)$ticketId, null, $fromAddress);
    }

    if ($isInitial != 1 && $ticketId) {
        try {
            require_once __DIR__ . '/../../workflow/includes/engine.php';
            // ⚠️ requester_email is NOT a column on tickets — it lives on the user.
            // See the same note in api/self-service/reply_ticket.php.
            $tStmt = $conn->prepare(
                "SELECT t.id, t.subject, t.priority_id, t.status_id, t.department_id,
                        t.ticket_type_id AS type_id, t.assigned_analyst_id, t.owner_id,
                        t.origin_id, t.user_id AS created_by, u.email AS requester_email
                   FROM tickets t
                   LEFT JOIN users u ON u.id = t.user_id
                  WHERE t.id = ? LIMIT 1"
            );
            $tStmt->execute([(int)$ticketId]);
            $tRow = $tStmt->fetch(PDO::FETCH_ASSOC);
            if ($tRow) {
                WorkflowEngine::dispatch('ticket.reply_received', [
                    'ticket' => $tRow,
                    'source' => 'email',
                ]);
            }
        } catch (Throwable $wfEx) {
            error_log('Workflow dispatch error on inbound reply: ' . $wfEx->getMessage());
        }
    }

    // Process attachments
    // Check for inline images by looking for cid: references in the body
    $hasCidReferences = preg_match('/cid:/i', $bodyContent);
    $attachmentInfo = [];

    // Attachments come one of two ways:
    //  - Pre-loaded inline by the connector (IMAP / Gmail), already Graph-shaped —
    //    IMAP can't re-fetch a part after disconnecting, so they ride along with the
    //    message. (This also fixes Gmail, whose attachments a Graph-only fetch missed.)
    //  - Fetched on demand from Microsoft Graph via a second call.
    // Already fetched by the size check in the main loop: use that list.
    $preloadedAttachments = $prefetched ?? ($email['attachments_inline'] ?? null);
    if (($hasAttachments || $hasCidReferences) && ($preloadedAttachments !== null || $accessToken)) {
        try {
            $graphAttachments = ($preloadedAttachments !== null)
                ? $preloadedAttachments
                : fetchEmailAttachments($accessToken, $providerMessageId);
            $savedAttachments = [];
            $rejectedAttachments = [];
            $quarantinedAttachments = [];

            $fileIndex = -1;
            foreach ($graphAttachments as $attachment) {
                if (($attachment['@odata.type'] ?? '') === '#microsoft.graph.fileAttachment') {
                    // Removed by the size check (GH #170): not stored, and listed in
                    // the note at the foot of the message, where it would have been.
                    $fileIndex++;
                    if (isset($strip[$fileIndex])) {
                        $rejectedAttachments[] = ['name' => $attachment['name'] ?? 'attachment', 'reason' => $strip[$fileIndex]];
                        continue;
                    }
                    $savedAttachment = saveAttachment($conn, $dbEmailId, $attachment, $maxBytes);
                    if (!$savedAttachment) continue;

                    // A file we would not store is reported, never dropped silently —
                    // the sender is a person who thinks they attached something.
                    if (!empty($savedAttachment['rejected'])) {
                        $rejectedAttachments[] = ['name' => $savedAttachment['filename'], 'reason' => $savedAttachment['reason']];
                        continue;
                    }
                    if (!empty($savedAttachment['quarantined'])) {
                        $quarantinedAttachments[] = ['name' => $savedAttachment['filename'], 'reason' => $savedAttachment['reason']];
                    }

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

            $ingestNotice = attachmentIngestNotice($rejectedAttachments, $quarantinedAttachments);

            if (!empty($savedAttachments) || $ingestNotice !== '') {
                $rewrittenBody = rewriteCidReferences($bodyContent, $dbEmailId, $savedAttachments) . $ingestNotice;

                // Update body content and ensure has_attachments is set to true
                $updateSql = "UPDATE emails SET body_content = ?, has_attachments = ? WHERE id = ?";
                $updateStmt = $conn->prepare($updateSql);
                $updateStmt->execute([$rewrittenBody, !empty($savedAttachments) ? 1 : 0, $dbEmailId]);
            }
            // Re-index now the attachments exist on disk and in the database.
            //
            // ticket.created is dispatched further up, deliberately, so the ticket and
            // its opening message both exist when the indexer runs. Attachments are
            // saved down HERE, dozens of lines later — so at index time there were no
            // attachment rows to find, searchIndexTicketAttachments() matched nothing,
            // and an emailed PDF was never queued for text extraction at all. It was
            // not pending and not failed; it simply had no extraction row, which is
            // the one state no status screen can report on.
            //
            // Indexing a whole ticket is an idempotent upsert, so doing it twice is
            // cheap and ordering-immune: whichever of the two runs happens last sees
            // the complete picture. That is preferable to moving the dispatch, which
            // would delay the notification bell and every workflow on ticket.created
            // behind an attachment download.
            if (!empty($savedAttachments) && $ticketId) {
                try {
                    require_once __DIR__ . '/../../includes/search/indexer.php';
                    if (searchCorpusReady($conn)) {
                        searchIndexTicket($conn, (int)$ticketId);
                    }
                } catch (Throwable $e) {
                    // Never let indexing failure lose an email that arrived fine.
                    error_log('[search] re-index after attachments failed for ticket '
                        . $ticketId . ': ' . $e->getMessage());
                }
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
 *
 * @param mixed $bodyContent  the reply's HTML body, with the quoted thread still in it
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
    if ($stripped === null && preg_match(TicketNumbering::REF_LINE_PATTERN, $bodyContent, $matches, PREG_OFFSET_CAPTURE)) {
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
/**
 * Fetch (or take the pre-loaded) attachments of one email and measure them.
 * Returns [the attachment list to hand on, [['name','size'], ...] for the file
 * attachments in order]. Sizes are the DECODED bytes where the content is here,
 * else the size the provider reports.
 *
 * @param array $email  one message in Graph's shape; attachments_inline is used when the provider supplied them
 * @param mixed $accessToken  the Graph access token, used to fetch attachments when they are not inline
 */
function oversizeMeasure($conn, array $email, $accessToken): array
{
    // Not if it is already in the database: saveEmailToDatabase() skips it anyway,
    // and measuring it would alert and auto-reply about the same email twice.
    $st = $conn->prepare("SELECT 1 FROM emails WHERE exchange_message_id = ? LIMIT 1");
    $st->execute([$email['internet_message_id'] ?? $email['id'] ?? '']);
    if ($st->fetchColumn()) return [null, []];

    $hasAtt = ($email['hasAttachments'] ?? false) || preg_match('/cid:/i', $email['body']['content'] ?? '');
    $list = $email['attachments_inline'] ?? null;
    if ($list === null) {
        if (!$hasAtt || !$accessToken) return [null, []];
        $list = fetchEmailAttachments($accessToken, $email['id'] ?? '');
    }
    $atts = [];
    foreach ($list as $a) {
        if (($a['@odata.type'] ?? '') !== '#microsoft.graph.fileAttachment') continue;
        $b64 = (string)($a['contentBytes'] ?? '');
        $size = $b64 !== '' ? (int)floor(strlen($b64) * 3 / 4) - substr_count(substr($b64, -2), '=') : (int)($a['size'] ?? 0);
        $atts[] = ['name' => (string)($a['name'] ?? 'attachment'), 'size' => max(0, $size)];
    }
    return [$list, $atts];
}

/**
 * The processing-log step describing the size check.
 *
 * @param array $atts  the file attachments as measured: [['name' => string, 'size' => bytes], ...]
 * @param array $plan  the decision from oversizePlan() - total and reasons are read
 * @param array $rules  the mailbox's rules from oversizeRules()
 * @param string $outcome  what happened to the email, e.g. 'rejected' or 'stripped'
 */
function oversizeLogStep(array $atts, array $plan, array $rules, string $outcome): array
{
    return [
        'step'    => 'size_check',
        'result'  => $outcome,
        'limits'  => ['per_attachment' => oversizeMbWords((int)$rules['max_bytes']),
                      'total' => $rules['max_total_bytes'] > 0 ? oversizeMbWords((int)$rules['max_total_bytes']) : 'none',
                      'from' => $rules['source'], 'mode' => $rules['mode'], 'strip_order' => $rules['strip_order']],
        'total'   => oversizeMbWords((int)$plan['total']),
        'details' => array_map(fn($i, $a) => $a['name'] . ' (' . oversizeMbWords((int)$a['size']) . ')' . (isset($plan['reasons'][$i]) ? ' - ' . $plan['reasons'][$i] : ''),
                               array_keys($atts), $atts),
    ];
}

/**
 * "big.zip (40 MB), video.mp4 (30 MB)" - what broke the limits, for the log's reason column.
 *
 * @param array $atts  the file attachments as measured: [['name' => string, 'size' => bytes], ...]
 * @param array $plan  the decision from oversizePlan(); its reasons say which attachments broke a limit
 */
function oversizeSummary(array $atts, array $plan): string
{
    $out = [];
    foreach ($plan['reasons'] as $i => $_) $out[] = $atts[$i]['name'] . ' (' . oversizeMbWords((int)$atts[$i]['size']) . ')';
    return implode(', ', $out);
}

/**
 * Writes one email_import row to system_logs with the mailbox id added to the details. A
 * logging failure goes to the PHP error log and never stops the import.
 *
 * @param mixed $mailboxId  the target_mailboxes id the email came in through
 * @param mixed $details  what to log, as an array; stored as JSON with mailbox_id added
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
 *
 * @param mixed $mailboxId  the target_mailboxes id whose whitelist is wanted
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
 *
 * @param mixed $fromAddress  the sender's address, any case
 * @param mixed $whitelist  the lists from loadWhitelist(): ['domains' => [...], 'emails' => [...]], lower-case
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
 *
 * @param mixed $mailboxId  the target_mailboxes id the activity belongs to
 * @param mixed $entries  one row per email: action, from_address, from_name, subject, reason, ticket_id, processing_log
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
