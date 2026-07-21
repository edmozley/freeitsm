<?php
/**
 * OAuth 2.0 Callback Handler
 *
 * This script receives the authorization code from Azure AD
 * and exchanges it for an access token and refresh token.
 *
 * Supports mailbox-specific authentication via state parameter.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/encryption.php';
require_once __DIR__ . '/../includes/mailbox_graph.php';

// Check if we received an authorization code
if (!isset($_GET['code'])) {
    if (isset($_GET['error'])) {
        die('OAuth Error: ' . htmlspecialchars($_GET['error']) . '<br>' .
            'Description: ' . htmlspecialchars($_GET['error_description'] ?? 'No description'));
    }
    die('No authorization code received.');
}

$authCode = $_GET['code'];
$state = $_GET['state'] ?? '';

// Parse state to get mailbox ID (format: mailbox_ID_randomhex)
$mailboxId = null;
if (preg_match('/^mailbox_(\d+)_/', $state, $matches)) {
    $mailboxId = (int)$matches[1];
}

try {
    // Connect to database
    $conn = connectToDatabase();

    if ($mailboxId) {
        // Mailbox-specific authentication
        $mailbox = getMailboxConfig($conn, $mailboxId);

        if (!$mailbox) {
            die('Mailbox not found.');
        }

        // Exchange authorization code for tokens using mailbox config
        $tokens = getTokensFromAuthCodeForMailbox($authCode, $mailbox);

        // Capture WHO actually signed in, with EVERY address that account owns (primary
        // SMTP, UPN and aliases). In delegated mode the app reads this account's inbox —
        // so we record it and compare against the configured target_mailbox to catch
        // "you authenticated the wrong account" (issue #26), while still accepting a
        // target that is merely an alias of the signed-in mailbox.
        $record = mailboxIdentityRecord($tokens['access_token']);

        // Save tokens + the authenticated identity (primary for display, full set for matching)
        saveTokensToDatabase($conn, $mailboxId, $tokens, $record);

        // Flag an immediate mismatch warning if the target isn't one of the account's addresses.
        $target = strtolower(trim($mailbox['target_mailbox'] ?? ''));
        $addresses = $record['addresses'] ?: ($record['primary'] ? [$record['primary']] : []);
        $mismatch = ($addresses && $target && !in_array($target, $addresses, true)) ? 1 : 0;

        // Redirect back to settings page with success message
        header('Location: tickets/settings/index.php?oauth=success&mailbox_id=' . $mailboxId
            . ($mismatch ? '&auth_mismatch=1' : ''));
        exit;
    } else {
        // Legacy: File-based authentication (for backwards compatibility)
        // This shouldn't be used anymore but keeping for safety
        die('Missing mailbox ID in state parameter. Please use mailbox-specific authentication.');
    }

} catch (Exception $e) {
    die('Error getting tokens: ' . htmlspecialchars($e->getMessage()));
}

/**
 * Connect to MySQL database using PDO
 */
function connectToDatabase() {
    $dsn = "mysql:host=" . DB_SERVER . ";dbname=" . DB_NAME . ";charset=utf8mb4";
    $conn = new PDO($dsn, DB_USERNAME, DB_PASSWORD);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    return $conn;
}

/**
 * Get mailbox configuration from database
 */
function getMailboxConfig($conn, $mailboxId) {
    $sql = "SELECT * FROM target_mailboxes WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$mailboxId]);
    $mailbox = $stmt->fetch(PDO::FETCH_ASSOC);
    return decryptMailboxRow($mailbox);
}

/**
 * Exchange authorization code for access token and refresh token (mailbox-specific)
 */
function getTokensFromAuthCodeForMailbox($authCode, $mailbox) {
    $tokenUrl = 'https://login.microsoftonline.com/' . $mailbox['azure_tenant_id'] . '/oauth2/v2.0/token';

    $postData = [
        'client_id' => $mailbox['azure_client_id'],
        'client_secret' => $mailbox['azure_client_secret'],
        'code' => $authCode,
        'redirect_uri' => $mailbox['oauth_redirect_uri'],
        'grant_type' => 'authorization_code',
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
        throw new Exception('Failed to get tokens. HTTP Code: ' . $httpCode . '. Response: ' . $response);
    }

    $tokenData = json_decode($response, true);

    if (!isset($tokenData['access_token'])) {
        throw new Exception('Access token not found in response: ' . $response);
    }

    return [
        'access_token' => $tokenData['access_token'],
        'refresh_token' => $tokenData['refresh_token'] ?? null,
        'expires_in' => $tokenData['expires_in'] ?? 3600,
        'token_type' => $tokenData['token_type'] ?? 'Bearer',
        'expires_at' => time() + ($tokenData['expires_in'] ?? 3600),
        'created_at' => time()
    ];
}

/**
 * Who does the freshly-issued token belong to (the account the user signed in as)?
 * Returns the lowercased email, or null if it can't be determined. Reads the token's
 * own JWT claims first (offline, no permission needed) and only falls back to Graph
 * /me — the mailbox scopes don't include User.Read, so /me usually 403s.
 */
function getGraphSignedInEmail($accessToken) {
    $identity = mailboxDelegatedIdentity($accessToken);
    return $identity !== '' ? $identity : null;
}

/**
 * Save tokens + the authenticated identity to database for a specific mailbox.
 * $record is the ['primary' => ..., 'addresses' => [...]] from mailboxIdentityRecord():
 * 'primary' goes in authenticated_as (display); 'addresses' (primary + aliases) in
 * authenticated_addresses (the set we match the target against). A bare string is also
 * accepted for backward compatibility.
 */
function saveTokensToDatabase($conn, $mailboxId, $tokens, $record = null) {
    $jsonData = json_encode($tokens);

    if (is_array($record)) {
        $primary   = $record['primary'] ?: null;
        $addresses = !empty($record['addresses']) ? json_encode($record['addresses']) : null;
    } else {
        $primary   = $record ?: null;                       // legacy: a plain identity string
        $addresses = $primary ? json_encode([$primary]) : null;
    }

    $sql = "UPDATE target_mailboxes SET token_data = ?, authenticated_as = ?, authenticated_addresses = ? WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$jsonData, $primary, $addresses, $mailboxId]);

    if ($stmt->rowCount() === 0) {
        throw new Exception('Failed to save tokens to database');
    }
}
?>
