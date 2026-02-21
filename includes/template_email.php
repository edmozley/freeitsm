<?php
/**
 * Email Template Engine
 *
 * Sends automated emails triggered by ticket events (new ticket, assigned, closed).
 * Templates are configured in Tickets > Settings > Templates.
 */

require_once __DIR__ . '/encryption.php';

/**
 * Main entry point — send a template email for a ticket event.
 * Returns silently if no active template exists or no mailbox is found.
 * Never throws — errors go to error_log().
 */
function sendTemplateEmail(PDO $conn, int $ticketId, string $eventTrigger): void {
    try {
        $template = getActiveTemplate($conn, $eventTrigger);
        if (!$template) {
            return; // No active template for this event
        }

        $mergeData = buildTicketMergeData($conn, $ticketId);
        if (!$mergeData) {
            error_log("Template email: could not build merge data for ticket $ticketId");
            return;
        }

        // Resolve merge codes in subject and body
        $subject = resolveMergeCodes($template['subject_template'], $mergeData);
        $body = resolveMergeCodes($template['body_template'], $mergeData);

        // Get the mailbox for this ticket
        $mailbox = templateGetMailboxForTicket($conn, $ticketId);
        if (!$mailbox) {
            return; // Manual ticket or no mailbox — skip silently
        }

        if (empty($mailbox['token_data'])) {
            error_log("Template email: mailbox {$mailbox['id']} has no token data");
            return;
        }

        // Parse and validate token
        $cleanedTokenData = preg_replace('/[\x00-\x1F\x7F]/', '', $mailbox['token_data']);
        $tokenData = json_decode($cleanedTokenData, true);
        if (!$tokenData || !isset($tokenData['access_token'])) {
            error_log("Template email: invalid token data for mailbox {$mailbox['id']}");
            return;
        }

        // Get valid access token (refresh if needed)
        $accessToken = templateGetValidAccessToken($conn, $mailbox, $tokenData);
        if (!$accessToken) {
            error_log("Template email: failed to get access token for mailbox {$mailbox['id']}");
            return;
        }

        // Get recipient (the ticket requester)
        $recipientEmail = $mergeData['requester_email'] ?? '';
        if (empty($recipientEmail)) {
            error_log("Template email: no requester email for ticket $ticketId");
            return;
        }

        $ticketNumber = $mergeData['ticket_reference'] ?? '';

        // Build subject with SDREF for threading
        $fullSubject = "[SDREF:$ticketNumber] $subject";

        // Build HTML body with reply marker
        $fullBody = buildTemplateEmailBody($body, $ticketNumber);

        // Build Graph API message
        $message = [
            'message' => [
                'subject' => $fullSubject,
                'body' => [
                    'contentType' => 'HTML',
                    'content' => $fullBody
                ],
                'toRecipients' => [
                    ['emailAddress' => ['address' => $recipientEmail]]
                ]
            ],
            'saveToSentItems' => true
        ];

        // Send via Graph API
        templateSendViaGraph($accessToken, $message);

        // Save to emails table
        templateSaveSentEmail($conn, $ticketId, $mailbox, $recipientEmail, $fullSubject, $body);

    } catch (Exception $e) {
        error_log("Template email error ($eventTrigger, ticket $ticketId): " . $e->getMessage());
    }
}

/**
 * Get the first active template for a given event trigger.
 */
function getActiveTemplate(PDO $conn, string $eventTrigger): ?array {
    $sql = "SELECT * FROM ticket_email_templates
            WHERE event_trigger = ? AND is_active = 1
            ORDER BY display_order ASC, id ASC
            LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$eventTrigger]);
    $template = $stmt->fetch(PDO::FETCH_ASSOC);
    return $template ?: null;
}

/**
 * Build merge data from ticket, analyst, and department tables.
 */
function buildTicketMergeData(PDO $conn, int $ticketId): ?array {
    $sql = "SELECT t.ticket_number, t.subject, t.status, t.priority,
                   t.requester_name, t.requester_email,
                   t.created_datetime, t.closed_datetime,
                   COALESCE(o.full_name, a.full_name) AS analyst_name,
                   COALESCE(o.email, a.email) AS analyst_email,
                   d.name AS department_name
            FROM tickets t
            LEFT JOIN analysts a ON t.assigned_analyst_id = a.id
            LEFT JOIN analysts o ON t.owner_id = o.id
            LEFT JOIN departments d ON t.department_id = d.id
            WHERE t.id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$ticketId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        return null;
    }

    return [
        'ticket_reference' => $row['ticket_number'] ?? '',
        'ticket_subject' => $row['subject'] ?? '',
        'ticket_status' => $row['status'] ?? '',
        'ticket_priority' => $row['priority'] ?? '',
        'requester_name' => $row['requester_name'] ?? '',
        'requester_email' => $row['requester_email'] ?? '',
        'analyst_name' => $row['analyst_name'] ?? '',
        'analyst_email' => $row['analyst_email'] ?? '',
        'department_name' => $row['department_name'] ?? '',
        'created_date' => $row['created_datetime'] ? date('d M Y H:i', strtotime($row['created_datetime'])) : '',
        'closed_date' => $row['closed_datetime'] ? date('d M Y H:i', strtotime($row['closed_datetime'])) : '',
    ];
}

/**
 * Replace [merge_code] placeholders with actual values.
 */
function resolveMergeCodes(string $template, array $mergeData): string {
    foreach ($mergeData as $code => $value) {
        $template = str_replace("[$code]", $value, $template);
    }
    return $template;
}

/**
 * Build the full HTML body with reply marker for threading.
 */
function buildTemplateEmailBody(string $bodyContent, string $ticketNumber): string {
    // Convert newlines to <br> if the body appears to be plain text (no HTML tags)
    if (strip_tags($bodyContent) === $bodyContent) {
        $bodyContent = nl2br(htmlspecialchars($bodyContent, ENT_QUOTES, 'UTF-8'));
    }

    return '<div style="font-family: Arial, sans-serif; color: #333; line-height: 1.6;">'
        . $bodyContent
        . '</div>'
        . '<div style="border-top: 1px solid #ccc; padding: 10px 0; margin: 20px 0; color: #999; font-size: 12px; text-align: center;" data-reply-marker="true">'
        . '&mdash; Please reply above this line &mdash;'
        . '</div>'
        . '<div style="display: none;">[*** SDREF:' . $ticketNumber . ' REPLY ABOVE THIS LINE ***]</div>';
}

// ---------------------------------------------------------------
// Graph API helpers (self-contained to avoid conflicts with
// send_email.php which defines the same functions)
// ---------------------------------------------------------------

/**
 * Get the mailbox associated with a ticket's emails.
 */
function templateGetMailboxForTicket(PDO $conn, int $ticketId): ?array {
    $sql = "SELECT tm.*
            FROM emails e
            INNER JOIN target_mailboxes tm ON e.mailbox_id = tm.id
            WHERE e.ticket_id = ?
            ORDER BY e.is_initial DESC, e.received_datetime ASC
            LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$ticketId]);
    $mailbox = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($mailbox) {
        $mailbox = decryptMailboxRow($mailbox);
    }

    return $mailbox ?: null;
}

/**
 * Get a valid access token, refreshing if expired.
 */
function templateGetValidAccessToken(PDO $conn, array $mailbox, array $tokenData): ?string {
    if (isset($tokenData['expires_at']) && $tokenData['expires_at'] < (time() + 300)) {
        if (!isset($tokenData['refresh_token'])) {
            return null;
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
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, SSL_VERIFY_PEER);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, SSL_VERIFY_PEER ? 2 : 0);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            return null;
        }

        $newToken = json_decode($response, true);
        if (!isset($newToken['access_token'])) {
            return null;
        }

        $tokenData['access_token'] = $newToken['access_token'];
        $tokenData['refresh_token'] = $newToken['refresh_token'] ?? $tokenData['refresh_token'];
        $tokenData['expires_at'] = time() + ($newToken['expires_in'] ?? 3600);

        // Save refreshed token
        $saveSql = "UPDATE target_mailboxes SET token_data = ? WHERE id = ?";
        $saveStmt = $conn->prepare($saveSql);
        $saveStmt->execute([json_encode($tokenData), $mailbox['id']]);
    }

    return $tokenData['access_token'];
}

/**
 * Send an email message via Microsoft Graph API.
 */
function templateSendViaGraph(string $accessToken, array $message): void {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://graph.microsoft.com/v1.0/me/sendMail');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($message));
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
        $error = curl_error($ch);
        curl_close($ch);
        throw new Exception('cURL error: ' . $error);
    }

    curl_close($ch);

    if ($httpCode !== 202 && $httpCode !== 200) {
        $errorData = json_decode($response, true);
        $errorMessage = $errorData['error']['message'] ?? 'Unknown error';
        throw new Exception("Graph API send failed: $errorMessage (HTTP $httpCode)");
    }
}

/**
 * Save the sent template email to the emails table.
 */
function templateSaveSentEmail(PDO $conn, int $ticketId, array $mailbox, string $to, string $subject, string $body): void {
    try {
        $sql = "INSERT INTO emails (
            subject, from_address, from_name, to_recipients,
            received_datetime, body_content, body_type, ticket_id, is_initial, direction, mailbox_id
        ) VALUES (?, ?, ?, ?, UTC_TIMESTAMP(), ?, 'html', ?, 0, 'Outbound', ?)";

        $stmt = $conn->prepare($sql);
        $stmt->execute([
            $subject,
            $mailbox['target_mailbox'] ?? '',
            $mailbox['name'] ?? 'Service Desk',
            $to,
            $body,
            $ticketId,
            $mailbox['id']
        ]);

        // Update ticket's updated_datetime
        $updateSql = "UPDATE tickets SET updated_datetime = UTC_TIMESTAMP() WHERE id = ?";
        $updateStmt = $conn->prepare($updateSql);
        $updateStmt->execute([$ticketId]);
    } catch (Exception $e) {
        error_log('Template email: failed to save sent email: ' . $e->getMessage());
    }
}
