<?php
/**
 * API Endpoint: Send share email for change record
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

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

$toEmail = $input['to_email'] ?? '';
$changeTitle = $input['change_title'] ?? '';
$changeRef = $input['change_ref'] ?? '';
$changeUrl = $input['change_url'] ?? null;
$message = $input['message'] ?? '';
$pdfData = $input['pdf_data'] ?? null;
$pdfFilename = $input['pdf_filename'] ?? null;

if (empty($toEmail)) {
    echo json_encode(['success' => false, 'error' => 'Recipient email is required']);
    exit;
}

if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'error' => 'Invalid email address']);
    exit;
}

try {
    $conn = connectToDatabase();

    // Get email settings (use same settings as knowledge module)
    $sql = "SELECT setting_key, setting_value FROM system_settings WHERE setting_key LIKE 'knowledge_email_%'";
    $stmt = $conn->prepare($sql);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $settings = [];
    foreach ($rows as $row) {
        $key = str_replace('knowledge_email_', '', $row['setting_key']);
        $settings[$key] = $row['setting_value'];
    }

    $emailMethod = $settings['method'] ?? 'disabled';

    if ($emailMethod === 'disabled') {
        echo json_encode(['success' => false, 'error' => 'Email sharing is disabled. Please configure email settings in Knowledge Settings.']);
        exit;
    }

    // Build email content
    $senderName = $_SESSION['analyst_name'] ?? 'Change Management';
    $subject = "Change Request: " . $changeRef . " - " . $changeTitle;

    $htmlBody = "<div style='font-family: Segoe UI, Tahoma, sans-serif; max-width: 600px;'>";
    $htmlBody .= "<p>Hi,</p>";
    $htmlBody .= "<p>{$senderName} has shared a change request with you:</p>";
    $htmlBody .= "<h2 style='color: #00897b;'>" . htmlspecialchars($changeRef) . ": " . htmlspecialchars($changeTitle) . "</h2>";

    if (!empty($message)) {
        $htmlBody .= "<div style='background: #f5f5f5; padding: 15px; border-radius: 6px; margin: 15px 0;'>";
        $htmlBody .= "<strong>Message:</strong><br>" . nl2br(htmlspecialchars($message));
        $htmlBody .= "</div>";
    }

    if ($changeUrl) {
        $htmlBody .= "<p><a href='" . htmlspecialchars($changeUrl) . "' style='display: inline-block; background: #00897b; color: white; padding: 10px 20px; text-decoration: none; border-radius: 4px;'>View Change</a></p>";
    }

    if ($pdfData) {
        $htmlBody .= "<p><em>A PDF copy of the change request is attached to this email.</em></p>";
    }

    $htmlBody .= "<hr style='border: none; border-top: 1px solid #ddd; margin: 20px 0;'>";
    $htmlBody .= "<p style='font-size: 12px; color: #888;'>This email was sent from the Change Management system.</p>";
    $htmlBody .= "</div>";

    // Send email based on method
    if ($emailMethod === 'smtp') {
        $result = sendSmtpEmail($settings, $toEmail, $subject, $htmlBody, $pdfData, $pdfFilename);
    } else if ($emailMethod === 'mailbox') {
        $result = sendMailboxEmail($conn, $settings['mailbox_id'], $toEmail, $subject, $htmlBody, $pdfData, $pdfFilename);
    } else {
        echo json_encode(['success' => false, 'error' => 'Invalid email method configured']);
        exit;
    }

    echo json_encode($result);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

/**
 * Send email via SMTP
 */
function sendSmtpEmail($settings, $toEmail, $subject, $htmlBody, $pdfData = null, $pdfFilename = null) {
    $host = $settings['smtp_host'] ?? '';
    $fromEmail = $settings['smtp_from_email'] ?? $settings['smtp_username'] ?? '';
    $fromName = $settings['smtp_from_name'] ?? 'Change Management';

    if (empty($host)) {
        return ['success' => false, 'error' => 'SMTP server not configured'];
    }

    $boundary = md5(time());

    $headers = "From: {$fromName} <{$fromEmail}>\r\n";
    $headers .= "Reply-To: {$fromEmail}\r\n";
    $headers .= "MIME-Version: 1.0\r\n";

    if ($pdfData) {
        $headers .= "Content-Type: multipart/mixed; boundary=\"{$boundary}\"\r\n";

        $body = "--{$boundary}\r\n";
        $body .= "Content-Type: text/html; charset=UTF-8\r\n";
        $body .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
        $body .= $htmlBody . "\r\n\r\n";

        $body .= "--{$boundary}\r\n";
        $body .= "Content-Type: application/pdf; name=\"{$pdfFilename}\"\r\n";
        $body .= "Content-Transfer-Encoding: base64\r\n";
        $body .= "Content-Disposition: attachment; filename=\"{$pdfFilename}\"\r\n\r\n";
        $body .= chunk_split($pdfData) . "\r\n";

        $body .= "--{$boundary}--";
    } else {
        $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
        $body = $htmlBody;
    }

    $result = @mail($toEmail, $subject, $body, $headers);

    if ($result) {
        return ['success' => true];
    } else {
        return ['success' => false, 'error' => 'Failed to send email. SMTP configuration may need to use PHPMailer for authentication.'];
    }
}

/**
 * Send email via Microsoft 365 mailbox using Graph API
 */
function sendMailboxEmail($conn, $mailboxId, $toEmail, $subject, $htmlBody, $pdfData = null, $pdfFilename = null) {
    if (empty($mailboxId)) {
        return ['success' => false, 'error' => 'No mailbox selected'];
    }

    // Get mailbox details
    $sql = "SELECT * FROM target_mailboxes WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$mailboxId]);
    $mailbox = decryptMailboxRow($stmt->fetch(PDO::FETCH_ASSOC));

    if (!$mailbox) {
        return ['success' => false, 'error' => 'Mailbox not found'];
    }

    if (empty($mailbox['token_data'])) {
        return ['success' => false, 'error' => 'Mailbox is not authenticated. Please authenticate in Settings.'];
    }

    // Parse token data (clean ODBC control characters)
    $cleanedTokenData = preg_replace('/[\x00-\x1F\x7F]/', '', $mailbox['token_data']);
    $tokenData = json_decode($cleanedTokenData, true);

    if ($tokenData === null) {
        return ['success' => false, 'error' => 'Failed to parse token data for mailbox'];
    }

    // Get valid access token (will refresh if needed)
    $accessToken = getValidAccessToken($conn, $mailbox, $tokenData);
    if (!$accessToken) {
        return ['success' => false, 'error' => 'Failed to get access token for mailbox'];
    }

    // Build email message for Graph API
    $message = [
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

    // Add attachment if PDF data provided
    if ($pdfData && $pdfFilename) {
        $message['message']['attachments'] = [
            [
                '@odata.type' => '#microsoft.graph.fileAttachment',
                'name' => $pdfFilename,
                'contentType' => 'application/pdf',
                'contentBytes' => $pdfData
            ]
        ];
    }

    // Send via Graph API
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://graph.microsoft.com/v1.0/users/' . urlencode($mailbox['target_mailbox']) . '/sendMail');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($message));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $accessToken,
        'Content-Type: application/json'
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, SSL_VERIFY_PEER);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, SSL_VERIFY_PEER ? 2 : 0);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 202 || $httpCode === 200) {
        return ['success' => true];
    } else {
        $error = json_decode($response, true);
        return ['success' => false, 'error' => $error['error']['message'] ?? 'Failed to send email via Microsoft 365'];
    }
}

/**
 * Get valid access token (refresh if expired)
 */
function getValidAccessToken($conn, $mailbox, $tokenData) {
    if (!$tokenData || !isset($tokenData['access_token'])) {
        return null;
    }

    // Check if token is expired (with 5 minute buffer)
    if (isset($tokenData['expires_at']) && $tokenData['expires_at'] < (time() + 300)) {
        // Token expired or expiring soon, refresh it
        if (!isset($tokenData['refresh_token'])) {
            return null;
        }

        $tokenData = refreshAccessToken($mailbox, $tokenData['refresh_token']);
        if ($tokenData) {
            saveTokenData($conn, $mailbox['id'], $tokenData);
        } else {
            return null;
        }
    }

    return $tokenData['access_token'];
}

/**
 * Refresh the access token using mailbox configuration
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
    curl_close($ch);

    if ($httpCode !== 200) {
        return null;
    }

    $data = json_decode($response, true);

    if (!isset($data['access_token'])) {
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
    $escapedJson = str_replace("'", "''", $jsonData);

    $sql = "UPDATE target_mailboxes SET token_data = '$escapedJson' WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$mailboxId]);
}
?>
