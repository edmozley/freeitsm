<?php
/**
 * Google OAuth 2.0 Callback Handler
 *
 * Receives the authorization code from Google and exchanges it
 * for access + refresh tokens. Mirrors oauth_callback.php for Microsoft.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/base_url.php';  // BASE_URL - this file does not load functions.php, see GH #129
require_once __DIR__ . '/../includes/db.php';   // dbConnectionOptions() — NOT in config.php, see GH #129
require_once __DIR__ . '/../includes/ssl.php';  // sslApplyCurl() — this file does not load functions.php, see GH #129
require_once __DIR__ . '/../includes/encryption.php';

if (!isset($_GET['code'])) {
    if (isset($_GET['error'])) {
        die('Google OAuth Error: ' . htmlspecialchars($_GET['error']));
    }
    die('No authorization code received.');
}

$authCode = $_GET['code'];
$state = $_GET['state'] ?? '';

// 🔴 The state must be one api/tickets/mailbox_oauth_start.php issued to the
// analyst signed in here, unused and in date - see auth/oauth_callback.php, which
// had the same hole. The mailbox id comes from what was issued, never the URL.
require_once __DIR__ . '/../includes/mailbox_oauth_state.php';
session_start();
$mailboxId = preg_match('/^google_mailbox_\d+_/', (string)$state) ? mailboxOAuthConsumeState((string)$state) : null;
session_write_close();

if (!$mailboxId) {
    die('Security check failed: this sign-in was not started from Tickets &rarr; Settings &rarr; Mailboxes in this browser, or it took too long. Please start it again from there.');
}

try {
    $dsn = "mysql:host=" . DB_SERVER . ";dbname=" . DB_NAME . ";charset=utf8mb4";
    $conn = new PDO($dsn, DB_USERNAME, DB_PASSWORD, dbConnectionOptions());   // UTC session — includes/db.php
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Get mailbox config
    $stmt = $conn->prepare("SELECT * FROM target_mailboxes WHERE id = ?");
    $stmt->execute([$mailboxId]);
    $mailbox = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$mailbox) {
        die('Mailbox not found.');
    }

    $mailbox = decryptMailboxRow($mailbox);

    // Exchange code for tokens
    // Google uses azure_client_id / azure_client_secret columns (repurposed for Google credentials)
    $postData = [
        'code' => $authCode,
        'client_id' => $mailbox['azure_client_id'],
        'client_secret' => $mailbox['azure_client_secret'],
        'redirect_uri' => $mailbox['oauth_redirect_uri'],
        'grant_type' => 'authorization_code'
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
        throw new Exception('cURL error: ' . curl_error($ch));
    }


    if ($httpCode !== 200) {
        throw new Exception('Failed to get tokens. HTTP ' . $httpCode . '. Response: ' . $response);
    }

    $tokenData = json_decode($response, true);

    if (!isset($tokenData['access_token'])) {
        throw new Exception('Access token not found in response: ' . $response);
    }

    $tokens = [
        'access_token' => $tokenData['access_token'],
        'refresh_token' => $tokenData['refresh_token'] ?? null,
        'expires_in' => $tokenData['expires_in'] ?? 3600,
        'token_type' => $tokenData['token_type'] ?? 'Bearer',
        'expires_at' => time() + ($tokenData['expires_in'] ?? 3600),
        'created_at' => time()
    ];

    // Save tokens
    $stmt = $conn->prepare("UPDATE target_mailboxes SET token_data = ? WHERE id = ?");
    // Encrypted at rest — see ENCRYPTED_MAILBOX_COLUMNS; decryptMailboxRow() reverses it.
    // This is the Gmail refresh token: durable mailbox access on its own.
    $stmt->execute([encryptValue(json_encode($tokens)), $mailboxId]);

    header('Location: ' . (defined('BASE_URL') ? BASE_URL : '/') . 'tickets/settings/index.php?oauth=success&mailbox_id=' . $mailboxId);
    exit;

} catch (Exception $e) {
    die('Error getting Google tokens: ' . htmlspecialchars($e->getMessage()));
}
