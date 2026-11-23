<?php
/**
 * API Endpoint: Verify a mail folder exists in a mailbox
 * POST: { "mailbox_id": N, "folder_name": "Processed" }
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/rbac.php';
require_once '../../includes/encryption.php';
require_once '../../includes/mailbox_graph.php';
require_once '../../includes/mailbox_imap.php';
require_once '../../includes/gmail.php';

// Changes data: never on a GET, which a link on another site can send (csrf.php).
csrfRequireWriteMethod();

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

// Settings-only (Mailboxes tab). It had no module check at all — and it probes a mailbox.
requireModuleAccessJson('tickets');
requireCapabilityJson(Cap::TICKETS_MAILBOXES);

$data = json_decode(file_get_contents('php://input'), true);
$mailboxId = $data['mailbox_id'] ?? null;
$folderName = trim($data['folder_name'] ?? '');

if (!$mailboxId || $folderName === '') {
    echo json_encode(['success' => false, 'error' => 'Mailbox ID and folder name are required']);
    exit;
}

try {
    $conn = connectToDatabase();

    $sql = "SELECT * FROM target_mailboxes WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$mailboxId]);
    $mailbox = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$mailbox) {
        echo json_encode(['success' => false, 'error' => 'Mailbox not found']);
        exit;
    }

    $mailbox = decryptMailboxRow($mailbox);

    $provider = MailboxProvider::fromMailbox($mailbox);
    $authMode = $mailbox['auth_mode'] ?? 'delegated';

    // ⚠️ Everything below the provider branches is Microsoft Graph, and used to be
    // the ONLY path — so Verify was broken for two of the three providers it was
    // offered on. Branch BEFORE the OAuth-token gate, which is where IMAP died.

    // Basic IMAP has no OAuth token and needs none: it connects with the stored
    // username + password. The Graph path answered "Mailbox is not authenticated"
    // for every IMAP mailbox on every install, without making a network call.
    if ($provider === MailboxProvider::Imap) {
        try {
            $folder = imapVerifyFolder($mailbox, $folderName);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            exit;
        }
        echo json_encode(['success' => true, 'folder' => $folder]);
        exit;
    }

    // Google DOES hold a token — a Google one — so it passed the gate and then ran
    // the Microsoft flow with Google credentials: the access token was sent to
    // graph.microsoft.com, and an expired one refreshed against
    // login.microsoftonline.com with azure_* fields a Google mailbox never has.
    if ($provider === MailboxProvider::Google) {
        if (empty($mailbox['token_data'])) {
            echo json_encode(['success' => false, 'error' => 'Mailbox is not authenticated. Please sign in to Google in Settings.']);
            exit;
        }

        $cleanedTokenData = preg_replace('/[\x00-\x1F\x7F]/', '', $mailbox['token_data']);
        $tokenData = json_decode($cleanedTokenData, true);
        if (!$tokenData || !isset($tokenData['access_token'])) {
            echo json_encode(['success' => false, 'error' => 'Invalid token data']);
            exit;
        }

        try {
            // Google's own refresh + persist, not Microsoft's.
            $accessToken = gmailGetValidAccessToken($conn, $mailbox, $tokenData);
            $folder = gmailVerifyFolder($accessToken, $mailbox, $folderName);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            exit;
        }
        echo json_encode(['success' => true, 'folder' => $folder]);
        exit;
    }

    mailboxResolveGraphBase($mailbox); // /me (delegated) or /users/<target> (app-only)

    if ($provider === MailboxProvider::Microsoft && $authMode === 'app_only') {
        // App-only: authenticate the app and verify the folder on the target mailbox.
        try {
            $accessToken = mailboxAppOnlyToken($conn, $mailbox);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => 'App-only authentication failed: ' . $e->getMessage()]);
            exit;
        }
    } else {
    if (empty($mailbox['token_data'])) {
        echo json_encode(['success' => false, 'error' => 'Mailbox is not authenticated']);
        exit;
    }

    $cleanedTokenData = preg_replace('/[\x00-\x1F\x7F]/', '', $mailbox['token_data']);
    $tokenData = json_decode($cleanedTokenData, true);

    if (!$tokenData || !isset($tokenData['access_token'])) {
        echo json_encode(['success' => false, 'error' => 'Invalid token data']);
        exit;
    }

    // SAFETY (delegated Microsoft): verify folders against the configured mailbox only.
    // A target matching the signed-in mailbox's primary or any alias passes.
    if ($provider === MailboxProvider::Microsoft) {
        if ($mismatchError = mailboxIdentityMismatch($mailbox)) {
            echo json_encode(['success' => false, 'error' => $mismatchError]);
            exit;
        }
    }

    // Refresh token if expired
    if (isset($tokenData['expires_at']) && $tokenData['expires_at'] < (time() + 300)) {
        if (!isset($tokenData['refresh_token'])) {
            echo json_encode(['success' => false, 'error' => 'Token expired. Please re-authenticate.']);
            exit;
        }

        $tokenUrl = 'https://login.microsoftonline.com/' . $mailbox['azure_tenant_id'] . '/oauth2/v2.0/token';
        $postData = [
            'client_id' => $mailbox['azure_client_id'],
            'client_secret' => $mailbox['azure_client_secret'],
            'refresh_token' => $tokenData['refresh_token'],
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

        if ($httpCode !== 200) {
            // The provider says WHY in the body — pass it on rather than throwing it away.
            echo json_encode(['success' => false, 'error' => oauthTokenErrorMessage($response, $httpCode)]);
            exit;
        }

        $newToken = json_decode($response, true);
        $tokenData['access_token'] = $newToken['access_token'];
        $tokenData['refresh_token'] = $newToken['refresh_token'] ?? $tokenData['refresh_token'];
        $tokenData['expires_at'] = time() + ($newToken['expires_in'] ?? 3600);

        $saveSql = "UPDATE target_mailboxes SET token_data = ? WHERE id = ?";
        $saveStmt = $conn->prepare($saveSql);
        // Encrypted at rest — see ENCRYPTED_MAILBOX_COLUMNS; decryptMailboxRow() reverses it.
        $saveStmt->execute([encryptValue(json_encode($tokenData)), $mailboxId]);
    }

        $accessToken = $tokenData['access_token'];
    }

    // Resolve through the SAME helper the mail fetch uses. This endpoint used to
    // run its own displayName query, which is how Verify could report a folder
    // "found" that reading the mailbox then rejected outright (GH #77).
    $get = function ($url) use ($accessToken) { return mailboxGraphGet($accessToken, $url); };

    try {
        $folderId = mailboxResolveFolderId($folderName, $get);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit;
    }

    // Read it back by id, so what is reported is the folder that will actually be
    // used — and confirm the id works in a path, which is the thing that failed.
    $res = $get('https://graph.microsoft.com/v1.0' . mailboxGraphBase()
        . '/mailFolders/' . rawurlencode($folderId)
        . '?' . http_build_query(['$select' => 'id,displayName,totalItemCount,unreadItemCount']));

    if (($res['code'] ?? 0) !== 200) {
        echo json_encode([
            'success' => false,
            'error' => 'Folder "' . $folderName . '" resolved but could not be opened (HTTP '
                     . ($res['code'] ?? '?') . ').'
        ]);
        exit;
    }

    $folder = $res['body'] ?? [];
    echo json_encode([
        'success' => true,
        'folder' => [
            'id' => $folder['id'] ?? $folderId,
            'displayName' => $folder['displayName'] ?? $folderName,
            'totalItemCount' => $folder['totalItemCount'] ?? null,
            'unreadItemCount' => $folder['unreadItemCount'] ?? null,
        ]
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
