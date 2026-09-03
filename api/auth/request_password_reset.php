<?php
/**
 * Request Password Reset
 *
 * Accepts a username or email, generates a secure token, and sends a reset link
 * via the first configured mailbox (Microsoft Graph API).
 *
 * POST JSON: { "identifier": "username_or_email" }
 */
session_start(['read_and_close' => true]);
header('Content-Type: application/json');

require_once '../../config.php';
require_once __DIR__ . '/../../includes/db.php';   // dbConnectionOptions() — NOT in config.php, see GH #129
require_once '../../includes/encryption.php';
require_once '../../includes/mailbox_graph.php';    // mailboxCanSend
require_once '../../includes/template_email.php';   // templateGraphContext, templateSendViaGraph

try {
    $input = json_decode(file_get_contents('php://input'), true);
    $identifier = trim($input['identifier'] ?? '');

    if (empty($identifier)) {
        echo json_encode(['success' => false, 'error' => 'Please enter your username or email address.']);
        exit;
    }

    $dsn = "mysql:host=" . DB_SERVER . ";dbname=" . DB_NAME . ";charset=utf8mb4";
    $conn = new PDO($dsn, DB_USERNAME, DB_PASSWORD, dbConnectionOptions());   // UTC session — includes/db.php
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Look up analyst by username or email
    $stmt = $conn->prepare("SELECT id, username, email, full_name FROM analysts WHERE (username = ? OR email = ?) AND is_active = 1");
    $stmt->execute([$identifier, $identifier]);
    $analyst = $stmt->fetch(PDO::FETCH_ASSOC);

    // Always show the same message whether the account exists or not (prevent enumeration)
    $genericMessage = 'If an account with that username or email exists, a password reset link has been sent.';

    if (!$analyst || empty($analyst['email'])) {
        echo json_encode(['success' => true, 'message' => $genericMessage]);
        exit;
    }

    // First active, send-capable mailbox. Deliberately NOT filtered on token_data in
    // SQL: that is the delegated test, and it hides an app-only mailbox that has not
    // minted its first token yet — which reported "no mailbox is configured" when one
    // was configured perfectly well. mailboxCanSend() knows the difference.
    $mbStmt = $conn->prepare("SELECT * FROM target_mailboxes WHERE is_active = 1 ORDER BY id ASC");
    $mbStmt->execute();
    $mailbox = null;
    foreach ($mbStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $candidate = decryptMailboxRow($row);
        if (mailboxCanSend($candidate)) { $mailbox = $candidate; break; }
    }

    if (!$mailbox) {
        // No mailbox configured — can't send email
        echo json_encode(['success' => false, 'error' => 'Password reset is not available. No email mailbox is configured. Please contact your administrator.']);
        exit;
    }

    // Invalidate any existing unused tokens for this analyst
    $conn->prepare("UPDATE password_reset_tokens SET used = 1 WHERE analyst_id = ? AND used = 0")->execute([$analyst['id']]);

    // Generate secure token (64 hex chars = 32 bytes of randomness)
    $token = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $token);

    // Token expires in 1 hour
    $stmt = $conn->prepare("INSERT INTO password_reset_tokens (analyst_id, token_hash, expires_datetime, created_datetime) VALUES (?, ?, DATE_ADD(UTC_TIMESTAMP(), INTERVAL 1 HOUR), UTC_TIMESTAMP())");
    $stmt->execute([$analyst['id'], $tokenHash]);

    // Build reset URL
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'];
    $basePath = rtrim(dirname(dirname(dirname($_SERVER['SCRIPT_NAME']))), '/\\');
    $resetUrl = $protocol . '://' . $host . $basePath . '/reset-password.php?token=' . $token;

    // Build email
    $htmlBody = '<div style="font-family: Arial, sans-serif; color: #333; line-height: 1.6; max-width: 600px;">'
        . '<h2 style="color: #667eea;">Password Reset Request</h2>'
        . '<p>Hi ' . htmlspecialchars($analyst['full_name']) . ',</p>'
        . '<p>We received a request to reset your password. Click the link below to set a new password:</p>'
        . '<p style="margin: 24px 0;"><a href="' . htmlspecialchars($resetUrl) . '" style="display: inline-block; padding: 12px 24px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; text-decoration: none; border-radius: 5px; font-weight: 600;">Reset Password</a></p>'
        . '<p style="font-size: 13px; color: #666;">Or copy and paste this URL into your browser:<br>'
        . '<a href="' . htmlspecialchars($resetUrl) . '" style="color: #667eea;">' . htmlspecialchars($resetUrl) . '</a></p>'
        . '<p style="font-size: 13px; color: #999;">This link expires in 1 hour. If you did not request a password reset, you can safely ignore this email.</p>'
        . '</div>';

    $provider  = $mailbox['provider'] ?? 'microsoft';
    $graphBase = '/me';

    if ($provider === 'google') {
        $cleanedTokenData = preg_replace('/[\x00-\x1F\x7F]/', '', (string)$mailbox['token_data']);
        $tokenData = json_decode($cleanedTokenData, true);
        if (!$tokenData || !isset($tokenData['access_token'])) {
            echo json_encode(['success' => false, 'error' => 'Password reset is not available. Email mailbox is not properly configured. Please contact your administrator.']);
            exit;
        }
        require_once dirname(dirname(__DIR__)) . '/includes/gmail.php';
        $accessToken = gmailGetValidAccessToken($conn, $mailbox, $tokenData);
    } else {
        // Microsoft: auth_mode decides both the token source and the send endpoint.
        $graph = templateGraphContext($conn, $mailbox);
        $accessToken = $graph['token'] ?? null;
        $graphBase   = $graph['base']  ?? '/me';
    }
    if (!$accessToken) {
        echo json_encode(['success' => false, 'error' => 'Password reset is not available. Email authentication has expired. Please contact your administrator.']);
        exit;
    }

    // Send email via appropriate provider
    if ($provider === 'google') {
        try {
            $fromAddress = $mailbox['target_mailbox'] ?? '';
            gmailSendEmail($accessToken, $analyst['email'], 'Password Reset', $htmlBody, $fromAddress);
        } catch (Exception $sendEx) {
            error_log('Password reset email failed (Gmail): ' . $sendEx->getMessage());
            emailLogFailed($conn, $mailbox, 'password_reset', $analyst['email'], 'Password Reset', $sendEx->getMessage());
            echo json_encode(['success' => false, 'error' => 'Failed to send reset email. Please try again or contact your administrator.']);
            exit;
        }
        emailLogSent($conn, $mailbox, 'password_reset', $analyst['email'], 'Password Reset');
    } else {
        $message = [
            'message' => [
                'subject' => 'Password Reset',
                'body' => [
                    'contentType' => 'HTML',
                    'content' => $htmlBody
                ],
                'toRecipients' => [
                    ['emailAddress' => ['address' => $analyst['email']]]
                ]
            ],
            'saveToSentItems' => false
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'https://graph.microsoft.com/v1.0' . $graphBase . '/sendMail');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($message));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        sslApplyCurl($ch);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $accessToken,
            'Content-Type: application/json'
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 202 && $httpCode !== 200) {
            error_log('Password reset email failed: HTTP ' . $httpCode . ' - ' . $response);
            $graphErr = json_decode((string)$response, true)['error']['message'] ?? ('HTTP ' . $httpCode);
            emailLogFailed($conn, $mailbox, 'password_reset', $analyst['email'], 'Password Reset', $graphErr);
            echo json_encode(['success' => false, 'error' => 'Failed to send reset email. Please try again or contact your administrator.']);
            exit;
        }
        emailLogSent($conn, $mailbox, 'password_reset', $analyst['email'], 'Password Reset');
    }

    echo json_encode(['success' => true, 'message' => $genericMessage]);

} catch (Exception $e) {
    error_log('Password reset request error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'An error occurred. Please try again.']);
}
