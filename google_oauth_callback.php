<?php
/**
 * Google OAuth 2.0 Callback Handler
 *
 * Receives the authorization code from Google and exchanges it
 * for access + refresh tokens. Mirrors oauth_callback.php for Microsoft.
 */

require_once 'config.php';
require_once 'includes/encryption.php';

if (!isset($_GET['code'])) {
    if (isset($_GET['error'])) {
        die('Google OAuth Error: ' . htmlspecialchars($_GET['error']));
    }
    die('No authorization code received.');
}

$authCode = $_GET['code'];
$state = $_GET['state'] ?? '';

// Parse state: format "google_mailbox_ID_randomhex"
$mailboxId = null;
if (preg_match('/^google_mailbox_(\d+)_/', $state, $matches)) {
    $mailboxId = (int)$matches[1];
}

if (!$mailboxId) {
    die('Missing mailbox ID in state parameter.');
}

try {
    $dsn = "mysql:host=" . DB_SERVER . ";dbname=" . DB_NAME . ";charset=utf8mb4";
    $conn = new PDO($dsn, DB_USERNAME, DB_PASSWORD);
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
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, SSL_VERIFY_PEER);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, SSL_VERIFY_PEER ? 2 : 0);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if (curl_errno($ch)) {
        throw new Exception('cURL error: ' . curl_error($ch));
    }

    curl_close($ch);

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
    $stmt->execute([json_encode($tokens), $mailboxId]);

    header('Location: tickets/settings/index.php?oauth=success&mailbox_id=' . $mailboxId);
    exit;

} catch (Exception $e) {
    die('Error getting Google tokens: ' . htmlspecialchars($e->getMessage()));
}
