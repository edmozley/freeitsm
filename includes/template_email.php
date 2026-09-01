<?php
/**
 * Email Template Engine
 *
 * Sends automated emails triggered by ticket events (new ticket, assigned, closed).
 * Templates are configured in Tickets > Settings > Templates.
 */

require_once __DIR__ . '/encryption.php';
require_once __DIR__ . '/email_log.php';
require_once __DIR__ . '/public_url.php';   // publicAbsoluteUrl(), for the [ticket_url] merge code
require_once __DIR__ . '/timezone.php';     // fmt_local(), for the [created_date] merge code (GH #126)

/**
 * Main entry point — send a template email for a ticket event.
 * Returns silently if no active template exists or no mailbox is found.
 * Never throws — errors go to error_log().
 *
 * $extraMergeData allows callers (e.g. the CSAT flow) to inject additional
 * placeholders like `csat_link` that aren't derivable from the ticket alone.
 */
function sendTemplateEmail(PDO $conn, int $ticketId, string $eventTrigger, array $extraMergeData = []): void {
    try {
        // ⚠️ Merge data FIRST, because which template applies now depends on who the
        // email is going to (discussion #80). It used to be the other way round.
        $mergeData = buildTicketMergeData($conn, $ticketId);
        if (!$mergeData) {
            error_log("Template email: could not build merge data for ticket $ticketId");
            return;
        }

        $choice   = templateSelectForRecipient($conn, $eventTrigger, $mergeData['requester_email'] ?? '');
        $template = $choice['template'];
        if (!$template) {
            // 'no_active_template' is the state every install has always been able to
            // reach and is not worth a row — nobody configured a template, so nobody
            // is expecting an email. 'no_match' is the new one and is the whole point
            // of logging: templates exist, this sender matched none of them, and the
            // only other evidence would be an email that never arrived.
            if ($choice['reason'] === 'no_match') {
                // Resolve the mailbox even though nothing is being sent, so the row
                // files under the mailbox that took the email in. Logged against no
                // mailbox at all it would land in the "sends with no mailbox" bucket,
                // which is not where anybody asking "why did this mailbox not reply?"
                // will look. One extra query, only on the path that sends nothing.
                emailLogSkipped(
                    $conn, templateGetMailboxForTicket($conn, $ticketId), 'template',
                    (string)($mergeData['requester_email'] ?? ''),
                    'Automatic email: ' . $eventTrigger,
                    'No email template applies to this sender. Every template for this event is limited to particular senders, and none of them covers this one.',
                    $ticketId
                );
            }
            return;
        }

        // Caller-supplied merge codes win — e.g. csat_link, which needs a freshly
        // minted response row before the link can be built
        $mergeData = array_merge($mergeData, $extraMergeData);

        // Resolve merge codes in subject and body
        // ⚠️ Decide plain-vs-HTML from the TEMPLATE, before anything is merged in.
        //
        // buildTemplateEmailBody() makes the same decision by testing the body it
        // is handed — but by then the merge codes have been substituted, so a
        // VALUE containing a tag flipped the verdict for the whole template. A
        // note reading "use the <table> in the office" would have turned an
        // otherwise plain-text template into an HTML one, and everything else in
        // it would silently stop being escaped. Whether the template is HTML is a
        // property of the template the administrator wrote, not of today's data.
        $bodyIsHtml = strip_tags($template['body_template']) !== $template['body_template'];

        // Caller-supplied values are free-form text — a note body, most of all —
        // so they are escaped when they are about to land inside HTML. Values
        // built from the ticket are left as they were: they already render
        // correctly today and this is not the change to alter them in.
        //
        // When the template is PLAIN text nothing is escaped here, because
        // buildTemplateEmailBody() escapes the whole body afterwards; escaping
        // twice would show the customer literal &amp;lt; and &lt;br&gt;.
        if ($bodyIsHtml) {
            foreach ($extraMergeData as $k => $v) {
                $mergeData[$k] = nl2br(htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'));
            }
        }

        $subject = resolveMergeCodes($template['subject_template'], $mergeData);
        $body = resolveMergeCodes($template['body_template'], $mergeData);

        // Get the mailbox for this ticket
        $mailbox = templateGetMailboxForTicket($conn, $ticketId);
        if (!$mailbox) {
            return; // Manual ticket or no mailbox — skip silently
        }

        $provider = $mailbox['provider'] ?? 'microsoft';
        $accessToken = null;
        $graphBase = '/me';
        if ($provider === 'imap') {
            // Basic IMAP sends via SMTP — no OAuth token to validate/refresh.
            require_once __DIR__ . '/mailbox_imap.php';
        } elseif ($provider === 'google') {
            if (empty($mailbox['token_data'])) {
                error_log("Template email: mailbox {$mailbox['id']} has no token data");
                return;
            }
            $cleanedTokenData = preg_replace('/[\x00-\x1F\x7F]/', '', $mailbox['token_data']);
            $tokenData = json_decode($cleanedTokenData, true);
            if (!$tokenData || !isset($tokenData['access_token'])) {
                error_log("Template email: invalid token data for mailbox {$mailbox['id']}");
                return;
            }
            require_once __DIR__ . '/gmail.php';
            $accessToken = gmailGetValidAccessToken($conn, $mailbox, $tokenData);
            if (!$accessToken) {
                error_log("Template email: failed to get access token for mailbox {$mailbox['id']}");
                emailLogFailed($conn, $mailbox, 'template', $mergeData['requester_email'] ?? '',
                    $subject, 'Could not obtain an access token for this mailbox', $ticketId);
                return;
            }
        } else {
            // Microsoft: token source AND endpoint both depend on auth_mode, so don't
            // test for stored token_data here — an app-only mailbox legitimately has
            // none until it first mints one.
            $graph = templateGraphContext($conn, $mailbox);
            if (!$graph) {
                error_log("Template email: failed to get access token for mailbox {$mailbox['id']}");
                emailLogFailed($conn, $mailbox, 'template', $mergeData['requester_email'] ?? '',
                    $subject, 'Could not obtain an access token for this mailbox '
                    . '(check the mailbox is authenticated, and that its authentication mode matches its stored token)',
                    $ticketId);
                return;
            }
            $accessToken = $graph['token'];
            $graphBase   = $graph['base'];
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
        $fullBody = buildTemplateEmailBody($body, $ticketNumber, $bodyIsHtml);

        // Send via appropriate API
        if ($provider === 'imap') {
            imapSmtpSend($mailbox, $recipientEmail, '', $fullSubject, $fullBody);
        } elseif ($provider === 'google') {
            $fromAddress = $mailbox['target_mailbox'] ?? '';
            gmailSendEmail($accessToken, $recipientEmail, $fullSubject, $fullBody, $fromAddress);
        } else {
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
            templateSendViaGraph($accessToken, $message, $graphBase);
        }

        emailLogSent($conn, $mailbox, 'template', $recipientEmail, $fullSubject, $ticketId);

        // Save to emails table
        templateSaveSentEmail($conn, $ticketId, $mailbox, $recipientEmail, $fullSubject, $body);

    } catch (Exception $e) {
        error_log("Template email error ($eventTrigger, ticket $ticketId): " . $e->getMessage());
        // The error log alone is what made this class of failure invisible: an
        // acknowledgement that silently never sends looks identical to one nobody
        // happened to trigger.
        emailLogFailed(
            $conn, $mailbox ?? null, 'template',
            $recipientEmail ?? '', $fullSubject ?? '', $e->getMessage(), $ticketId
        );
    }
}

/**
 * Get the first active template for a given event trigger.
 */
function getActiveTemplate(PDO $conn, string $eventTrigger, ?string $recipientEmail = null): ?array {
    return templateSelectForRecipient($conn, $eventTrigger, $recipientEmail)['template'];
}

/**
 * Choose the template for an event and a recipient, and say WHY it was chosen.
 *
 * ⚠️ SPECIFICITY DECIDES, NOT ORDER. This is the whole design of the sender rules
 * (discussion #80) and the reason they are safe to hand to an administrator:
 *
 *      1. a rule naming this exact address      someone@a.com
 *      2. a rule naming this domain             a.com
 *      3. a template with no rules at all       everyone
 *
 * The alternative — evaluate top to bottom, first match wins — requires the admin
 * to get the rules right AND the ordering right, and gives no sign when the second
 * is wrong. Here, dragging rows cannot change which template is sent. `display_order`
 * survives only as a tie-break between two rules of equal specificity, which is a
 * genuine ambiguity the settings screen flags rather than resolves silently.
 *
 * ⚠️ NO RULES MEANS EVERYONE, not nobody. The permissive case is the empty case, so
 * a template nobody has restricted keeps working and a fresh install always has a
 * catch-all. Inverting that would make "forgot to add a rule" mean silence.
 *
 * Returns ['template' => ?array, 'reason' => string, 'matched' => ?array] — the
 * reason is what the simulator shows and what is written to the send log when
 * nothing matches, so the answer to "why did nobody get a reply?" survives the
 * twelve months after everyone forgets this screen exists.
 */
function templateSelectForRecipient(PDO $conn, string $eventTrigger, ?string $recipientEmail = null): array
{
    $stmt = $conn->prepare(
        "SELECT * FROM ticket_email_templates
          WHERE event_trigger = ? AND is_active = 1
          ORDER BY display_order ASC, id ASC"
    );
    $stmt->execute([$eventTrigger]);
    $templates = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$templates) {
        return ['template' => null, 'reason' => 'no_active_template', 'matched' => null];
    }

    $rules = templateRulesByTemplate($conn, array_column($templates, 'id'));

    $email  = strtolower(trim((string)$recipientEmail));
    $domain = ($at = strrpos($email, '@')) !== false ? substr($email, $at + 1) : '';

    $unrestricted = null;
    $byDomain     = null;
    foreach ($templates as $tpl) {
        $mine = $rules[(int)$tpl['id']] ?? [];

        if (!$mine) {
            // Ordered by display_order already, so the first one found is the winner.
            if ($unrestricted === null) $unrestricted = $tpl;
            continue;
        }
        // Without a recipient there is nothing to match a rule against, so a
        // restricted template can never be the answer.
        if ($email === '') {
            continue;
        }
        foreach ($mine as $rule) {
            if ($rule['match_type'] === 'address' && $rule['match_value'] === $email) {
                return ['template' => $tpl, 'reason' => 'address', 'matched' => $rule];
            }
            if ($rule['match_type'] === 'domain' && $domain !== '' && $rule['match_value'] === $domain
                && $byDomain === null) {
                $byDomain = ['template' => $tpl, 'reason' => 'domain', 'matched' => $rule];
            }
        }
    }

    if ($byDomain)     return $byDomain;
    if ($unrestricted) return ['template' => $unrestricted, 'reason' => 'everyone', 'matched' => null];

    return ['template' => null, 'reason' => 'no_match', 'matched' => null];
}

/** Sender rules for the given templates, keyed by template id, values lowercased. */
function templateRulesByTemplate(PDO $conn, array $templateIds): array
{
    if (!$templateIds) return [];
    try {
        $in   = implode(',', array_fill(0, count($templateIds), '?'));
        $stmt = $conn->prepare("SELECT template_id, match_type, match_value
                                  FROM ticket_email_template_rules
                                 WHERE template_id IN ($in)");
        $stmt->execute(array_map('intval', $templateIds));
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(int)$r['template_id']][] = [
                'match_type'  => $r['match_type'],
                'match_value' => strtolower(trim((string)$r['match_value'])),
            ];
        }
        return $out;
    } catch (Exception $e) {
        // A part-upgraded install without the table has no rules, which means every
        // template applies to everyone — exactly the behaviour before this existed.
        return [];
    }
}

/**
 * Build merge data from ticket, analyst, and department tables.
 */
function buildTicketMergeData(PDO $conn, int $ticketId): ?array {
    $sql = "SELECT t.ticket_number, t.subject, ts.name AS status, tp.name AS priority,
                   COALESCE(u.display_name, u.email) AS requester_name,
                   u.email AS requester_email,
                   t.created_datetime, t.closed_datetime,
                   COALESCE(o.full_name, a.full_name) AS analyst_name,
                   COALESCE(o.email, a.email) AS analyst_email,
                   d.name AS department_name
            FROM tickets t
            LEFT JOIN ticket_statuses ts ON ts.id = t.status_id
            LEFT JOIN ticket_priorities tp ON tp.id = t.priority_id
            LEFT JOIN users u ON t.user_id = u.id
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
        // A clickable way back to the ticket, asked for in discussion #80. It points
        // at the SELF-SERVICE view rather than the analyst one, because everything
        // built from this data is addressed to the requester — an analyst link would
        // land them on a login page they have no account for.
        //
        // publicAbsoluteUrl() is what makes it usable: these emails are usually sent
        // by the mail collector from cron, where there is no request to read a host
        // from, so a link built any other way would be a bare path.
        'ticket_url' => publicAbsoluteUrl($conn, 'self-service/tickets.php?id=' . $ticketId),
        'ticket_subject' => $row['subject'] ?? '',
        'ticket_status' => $row['status'] ?? '',
        'ticket_priority' => $row['priority'] ?? '',
        'requester_name' => $row['requester_name'] ?? '',
        // First word of the requester's name — for friendlier greetings ("Dear Ed").
        'requester_first_name' => trim(explode(' ', trim($row['requester_name'] ?? ''))[0]),
        'requester_email' => $row['requester_email'] ?? '',
        'analyst_name' => $row['analyst_name'] ?? '',
        'analyst_email' => $row['analyst_email'] ?? '',
        'department_name' => $row['department_name'] ?? '',
        // 🔴 These were `date('d M Y H:i', strtotime($utc))` (GH #126). Both halves
        // run in PHP's default zone — which config.php pins to Europe/London — so
        // the value round-tripped through the same zone it went in by and came out
        // as the UNCONVERTED digits of the stored UTC value. The email therefore
        // showed a time converted for nobody: two hours BEHIND a reader in Vienna,
        // which is the mirror image of the note bug in the same report.
        //
        // fmt_local() converts a stored UTC instant into the display zone, which is
        // the analyst's own preference where there is a session and the install
        // default where there is not. ⚠️ Most of this file's mail is sent by the
        // collector from cron, so in practice that is usually the install default —
        // which is the right answer available, but it is NOT the recipient's zone.
        // A requester's timezone is not something FreeITSM stores.
        'created_date' => fmt_local($row['created_datetime'] ?? null, 'd M Y H:i'),
        'closed_date' => fmt_local($row['closed_datetime'] ?? null, 'd M Y H:i'),
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
/**
 * @param ?bool $isHtml Whether the TEMPLATE was HTML, decided before merge codes
 *                      were substituted. Pass it whenever you know: sniffing the
 *                      merged body means a data value containing a tag decides
 *                      the question for the whole email. Null keeps the old
 *                      sniff for callers that have no template to inspect.
 */
function buildTemplateEmailBody(string $bodyContent, string $ticketNumber, ?bool $isHtml = null): string {
    // Convert newlines to <br> if the body is plain text (no HTML tags)
    if ($isHtml === null) {
        $isHtml = strip_tags($bodyContent) !== $bodyContent;
    }
    if (!$isHtml) {
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
        sslApplyCurl($ch);

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
        // Encrypted at rest — see ENCRYPTED_MAILBOX_COLUMNS; decryptMailboxRow() reverses it.
        $saveStmt->execute([encryptValue(json_encode($tokenData)), $mailbox['id']]);
    }

    return $tokenData['access_token'];
}

/**
 * Everything a Microsoft send needs for one mailbox: a valid access token and the
 * Graph base path to send from. Returns null if no usable token could be obtained.
 *
 * The two auth modes differ in BOTH halves, which is what made issue #67 subtle:
 *
 *   delegated : a user signed in. token_data carries a refresh_token, and calls go
 *               to /me — Graph resolves "me" from the user inside the token.
 *   app_only  : client credentials. There is no user, so /me is meaningless and Graph
 *               rejects it with "/me request is only valid with delegated
 *               authentication flow" (HTTP 400). Calls go to /users/<target>, and
 *               there is no refresh_token either — the token is re-minted from the
 *               client secret.
 *
 * Correcting only the path leaves a slower bug behind: templateGetValidAccessToken()
 * returns null for an app-only mailbox the moment its cached token expires, because it
 * looks for a refresh_token that client credentials never issue. That failure is masked
 * in normal use because the mail poller re-mints the cached token as a side effect of
 * reading — so sends work until polling stops or an hour passes, then fail with a
 * misleading "failed to get access token".
 */
function templateGraphContext(PDO $conn, array $mailbox): ?array {
    require_once __DIR__ . '/mailbox_graph.php';

    if (mailboxIsAppOnly($mailbox)) {
        try {
            // No refresh token exists for client credentials — mint (or reuse the
            // cached) token. Throws on a bad secret / consent problem; that must not
            // escape into callers that only expect a null.
            $token = mailboxAppOnlyToken($conn, $mailbox);
        } catch (Exception $e) {
            error_log('Graph app-only token failed for mailbox '
                . ($mailbox['id'] ?? '?') . ': ' . $e->getMessage());
            return null;
        }
    } else {
        $cleaned   = preg_replace('/[\x00-\x1F\x7F]/', '', (string)($mailbox['token_data'] ?? ''));
        $tokenData = $cleaned !== '' ? json_decode($cleaned, true) : null;
        if (!$tokenData || !isset($tokenData['access_token'])) {
            return null;
        }
        // A token minted for app-only is NOT usable here. Switching a mailbox from
        // app-only back to delegated used to leave the old token in place, and it is
        // refused at /me with the very error this function exists to prevent — while
        // looking like the original bug had come back. Refuse it and make the mailbox
        // report itself unauthenticated, which prompts a real sign-in.
        if (!empty($tokenData['app_only'])) {
            error_log('Graph: mailbox ' . ($mailbox['id'] ?? '?')
                . ' is delegated but holds an app-only token — re-authentication needed');
            return null;
        }
        $token = templateGetValidAccessToken($conn, $mailbox, $tokenData);
    }

    return $token ? ['token' => $token, 'base' => mailboxResolveGraphBase($mailbox)] : null;
}

/**
 * Send an email message via Microsoft Graph API.
 *
 * $graphBase is '/me' (delegated) or '/users/<addr>' (app-only) — get it from
 * templateGraphContext(). It is deliberately REQUIRED rather than defaulting to '/me':
 * a default would reproduce exactly the silent wrong-endpoint bug this argument exists
 * to fix, and a missed caller should fail loudly at development time instead of quietly
 * sending from the wrong mailbox.
 */
function templateSendViaGraph(string $accessToken, array $message, string $graphBase): void {
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
