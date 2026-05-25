<?php
/**
 * Debug script: Test sending knowledge article email
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';

// Hardcoded test values - UPDATE THESE FOR YOUR ENVIRONMENT
$articleId = 1;
$mailboxId = 1;
$toEmail = 'test@example.com';  // Change to your test email
$message = 'This is a test email from the debug script.';

echo "<pre>";
echo "=== Debug Send Share Email ===\n\n";

try {
    $conn = connectToDatabase();
    echo "✓ Database connected\n";

    // Get article details
    $sql = "SELECT * FROM knowledge_articles WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$articleId]);
    $article = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$article) {
        die("✗ Article ID $articleId not found\n");
    }
    echo "✓ Article found: " . $article['title'] . "\n";

    // Get mailbox details
    $sql = "SELECT * FROM target_mailboxes WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$mailboxId]);
    $mailbox = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$mailbox) {
        die("✗ Mailbox ID $mailboxId not found\n");
    }
    echo "✓ Mailbox found: " . $mailbox['target_mailbox'] . "\n";

    if (empty($mailbox['token_data'])) {
        die("✗ Mailbox has no token_data - not authenticated\n");
    }
    echo "✓ Mailbox has token_data\n";

    // Parse token data
    $cleanedTokenData = preg_replace('/[\x00-\x1F\x7F]/', '', $mailbox['token_data']);
    $tokenData = json_decode($cleanedTokenData, true);

    if ($tokenData === null) {
        echo "✗ Failed to parse token_data JSON: " . json_last_error_msg() . "\n";
        echo "Raw token_data (first 200 chars): " . substr($mailbox['token_data'], 0, 200) . "\n";
        die();
    }
    echo "✓ Token data parsed successfully\n";
    echo "  - Has access_token: " . (isset($tokenData['access_token']) ? 'Yes' : 'No') . "\n";
    echo "  - Has refresh_token: " . (isset($tokenData['refresh_token']) ? 'Yes' : 'No') . "\n";
    echo "  - Has expires_at: " . (isset($tokenData['expires_at']) ? 'Yes (' . date('Y-m-d H:i:s', $tokenData['expires_at']) . ')' : 'No') . "\n";

    // Check token expiry
    if (isset($tokenData['expires_at'])) {
        $expiresIn = $tokenData['expires_at'] - time();
        echo "  - Token expires in: " . $expiresIn . " seconds\n";
        if ($expiresIn < 300) {
            echo "  - Token needs refresh (less than 5 min buffer)\n";
        }
    }

    // Get valid access token
    $accessToken = getValidAccessToken($conn, $mailbox, $tokenData);
    if (!$accessToken) {
        die("✗ Failed to get valid access token\n");
    }
    echo "✓ Got valid access token (first 50 chars): " . substr($accessToken, 0, 50) . "...\n";

    // Build email content
    $senderName = 'Debug Script';
    $subject = "Knowledge Article: " . $article['title'];
    $articleUrl = "https://your-server.com/knowledge/?article=" . $articleId;  // Update with your domain

    $htmlBody = "<div style='font-family: Segoe UI, Tahoma, sans-serif; max-width: 600px;'>";
    $htmlBody .= "<p>Hi,</p>";
    $htmlBody .= "<p>{$senderName} has shared a knowledge article with you:</p>";
    $htmlBody .= "<h2 style='color: #8764b8;'>" . htmlspecialchars($article['title']) . "</h2>";
    $htmlBody .= "<div style='background: #f5f5f5; padding: 15px; border-radius: 6px; margin: 15px 0;'>";
    $htmlBody .= "<strong>Message:</strong><br>" . nl2br(htmlspecialchars($message));
    $htmlBody .= "</div>";
    $htmlBody .= "<p><a href='" . htmlspecialchars($articleUrl) . "' style='display: inline-block; background: #8764b8; color: white; padding: 10px 20px; text-decoration: none; border-radius: 4px;'>View Article</a></p>";
    $htmlBody .= "<hr style='border: none; border-top: 1px solid #ddd; margin: 20px 0;'>";
    $htmlBody .= "<p style='font-size: 12px; color: #888;'>This email was sent from the Knowledge Base system (debug script).</p>";
    $htmlBody .= "</div>";

    echo "✓ Email content built\n";
    echo "  - To: $toEmail\n";
    echo "  - Subject: $subject\n";

    // Build Graph API message
    $graphMessage = [
        'message' => [
            'subject' => $subject,
            'body' => [
                'contentType' => 'HTML',
                'content' => $htmlBody
            ],
            'toRecipients' => [
                [
                    'emailAddress' => [
                        'address' => $toEmail
                    ]
                ]
            ]
        ],
        'saveToSentItems' => true
    ];

    echo "✓ Graph API message built\n";

    // Send via Graph API
    $graphUrl = 'https://graph.microsoft.com/v1.0/users/' . urlencode($mailbox['target_mailbox']) . '/sendMail';
    echo "  - Graph URL: $graphUrl\n";

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $graphUrl);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($graphMessage));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $accessToken,
        'Content-Type: application/json'
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, SSL_VERIFY_PEER);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, SSL_VERIFY_PEER ? 2 : 0);

    echo "Sending email...\n";

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    echo "  - HTTP Code: $httpCode\n";

    if ($curlError) {
        echo "✗ cURL Error: $curlError\n";
    }

    if ($httpCode === 202 || $httpCode === 200) {
        echo "✓ EMAIL SENT SUCCESSFULLY!\n";
    } else {
        echo "✗ Failed to send email\n";
        echo "  - Response: $response\n";
        $error = json_decode($response, true);
        if ($error && isset($error['error'])) {
            echo "  - Error Code: " . ($error['error']['code'] ?? 'unknown') . "\n";
            echo "  - Error Message: " . ($error['error']['message'] ?? 'unknown') . "\n";
        }
    }

} catch (Exception $e) {
    echo "✗ Exception: " . $e->getMessage() . "\n";
    echo "  - File: " . $e->getFile() . "\n";
    echo "  - Line: " . $e->getLine() . "\n";
}

echo "\n=== End Debug ===\n";
echo "</pre>";

/**
 * Get valid access token (refresh if expired)
 */
function getValidAccessToken($conn, $mailbox, $tokenData) {
    if (!$tokenData || !isset($tokenData['access_token'])) {
        echo "  ✗ No access_token in tokenData\n";
        return null;
    }

    // Check if token is expired (with 5 minute buffer)
    if (isset($tokenData['expires_at']) && $tokenData['expires_at'] < (time() + 300)) {
        echo "  - Token expired or expiring soon, refreshing...\n";

        if (!isset($tokenData['refresh_token'])) {
            echo "  ✗ No refresh_token available\n";
            return null;
        }

        $tokenData = refreshAccessToken($mailbox, $tokenData['refresh_token']);
        if ($tokenData) {
            echo "  ✓ Token refreshed successfully\n";
            saveTokenData($conn, $mailbox['id'], $tokenData);
        } else {
            echo "  ✗ Token refresh failed\n";
            return null;
        }
    }

    return $tokenData['access_token'];
}

/**
 * Refresh the access token
 */
function refreshAccessToken($mailbox, $refreshToken) {
    $tokenUrl = 'https://login.microsoftonline.com/' . $mailbox['azure_tenant_id'] . '/oauth2/v2.0/token';

    echo "  - Refresh URL: $tokenUrl\n";
    echo "  - Client ID: " . $mailbox['azure_client_id'] . "\n";
    echo "  - Scopes: " . $mailbox['oauth_scopes'] . "\n";

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
    $curlError = curl_error($ch);
    $curlErrno = curl_errno($ch);
    curl_close($ch);

    echo "  - Refresh HTTP Code: $httpCode\n";
    if ($curlError) {
        echo "  - cURL Error ($curlErrno): $curlError\n";
    }

    if ($httpCode !== 200) {
        echo "  - Refresh Response: $response\n";
        return null;
    }

    $data = json_decode($response, true);

    if (!isset($data['access_token'])) {
        echo "  - No access_token in refresh response\n";
        return null;
    }

    return [
        'access_token' => $data['access_token'],
        'refresh_token' => $data['refresh_token'] ?? $refreshToken,
        'expires_in' => $data['expires_in'] ?? 3600,
        'token_type' => $data['token_type'] ?? 'Bearer',
        'expires_at' => time() + ($data['expires_in'] ?? 3600),
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
    echo "  ✓ Token data saved to database\n";
}
?>
