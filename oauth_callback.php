<?php
/**
 * OAuth 2.0 Callback Handler
 *
 * This script receives the authorization code from Azure AD
 * and exchanges it for an access token and refresh token.
 *
 * Supports mailbox-specific authentication via state parameter.
 */

require_once 'config.php';
require_once 'includes/encryption.php';

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

        // Save tokens to database
        saveTokensToDatabase($conn, $mailboxId, $tokens);

        // Redirect back to settings page with success message
        header('Location: admin_settings.php?oauth=success&mailbox_id=' . $mailboxId);
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
 * Connect to SQL Server database using PDO with ODBC
 */
function connectToDatabase() {
    $drivers = ['ODBC Driver 17 for SQL Server', 'ODBC Driver 18 for SQL Server', 'SQL Server Native Client 11.0', 'SQL Server'];
    foreach ($drivers as $driver) {
        try {
            $dsn = "odbc:Driver={{$driver}};Server=" . DB_SERVER . ";Database=" . DB_NAME;
            $conn = new PDO($dsn, DB_USERNAME, DB_PASSWORD);
            $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            return $conn;
        } catch (PDOException $e) {
            continue;
        }
    }
    throw new Exception('Database connection failed');
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
 * Save tokens to database for specific mailbox
 */
function saveTokensToDatabase($conn, $mailboxId, $tokens) {
    $jsonData = json_encode($tokens);

    // Use direct SQL to avoid ODBC encoding issues with parameterized NVARCHAR
    // Escape single quotes for SQL
    $escapedJson = str_replace("'", "''", $jsonData);

    $sql = "UPDATE target_mailboxes SET token_data = '$escapedJson' WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$mailboxId]);

    if ($stmt->rowCount() === 0) {
        throw new Exception('Failed to save tokens to database');
    }
}
?>
