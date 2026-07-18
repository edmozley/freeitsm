<?php
/**
 * Web chat widgets — shared helpers.
 *
 * A web chat widget is the self-hosted twin of a WhatsApp number: it drives one
 * `messaging_channels` row (channel_type='webchat', provider='freeitsm') so that
 * once a visitor's message is ingested it flows through the same ticket membrane,
 * inbox and reply pipeline as every other channel. This file holds the helpers the
 * config screen and (later) the public widget endpoints share.
 *
 *   webchatGenerateKey()               → a fresh public widget key
 *   webchatBaseUrl($conn)              → scheme://host + app root for widget URLs
 *   webchatEmbedSnippet($conn, $key)   → the <script> a customer pastes into their site
 *   webchatLoadByChannel($conn, $id)   → a widget row by its channel id (or null)
 *   webchatLoadByKey($conn, $key)      → a widget row by its public key (or null)
 *   webchatParseOrigins($raw)          → a cleaned list of allowed origins
 *   webchatOriginAllowed($list, $orig) → is this request origin permitted?
 *
 * The widget key is PUBLIC — it ships in the customer's page source. It is not a
 * secret: abuse is contained by the origin allowlist + rate limiting, never by
 * keeping the key hidden.
 */

require_once __DIR__ . '/../messaging/messaging.php';
require_once __DIR__ . '/../ticket_reply.php';

/** A fresh, URL-safe public widget key (e.g. wc_1a2b…). */
function webchatGenerateKey(): string
{
    return 'wc_' . bin2hex(random_bytes(16));
}

/**
 * The message shown to a visitor when the widget is closed (out of office hours). Uses
 * the admin's custom offline message if set, else a sensible default — so a closed widget
 * is never silent, even if no message was configured.
 */
function webchatOfflineMessage(array $widget): string
{
    $custom = trim((string) ($widget['offline_message'] ?? ''));
    return $custom !== ''
        ? $custom
        : "Thanks for your message — we're currently closed, but we'll get back to you as soon as we can.";
}

/**
 * Public base URL + app root used to build widget asset / API URLs. Reuses the
 * messaging public base (system setting or derived from the request) and derives
 * the app root from SCRIPT_NAME so it works under any sub-path. Only ever called
 * from a script under /api/webchat/, so that prefix is what we strip.
 */
function webchatBaseUrl(PDO $conn): string
{
    $base = messagingPublicBaseUrl($conn);
    $root = preg_replace('#/api/webchat/.*$#', '', $_SERVER['SCRIPT_NAME'] ?? '');
    return rtrim($base . $root, '/');
}

/** The <script> embed snippet a customer pastes just before </body> on their site. */
function webchatEmbedSnippet(PDO $conn, string $widgetKey): string
{
    $src = webchatBaseUrl($conn) . '/api/webchat/widget.js';
    return "<script>\n"
        . "  (function(d){\n"
        . "    var s=d.createElement('script');\n"
        . "    s.src=" . json_encode($src) . ";\n"
        . "    s.async=true;\n"
        . "    s.setAttribute('data-freeitsm-widget'," . json_encode($widgetKey) . ");\n"
        . "    d.head.appendChild(s);\n"
        . "  })(document);\n"
        . "</script>";
}

/** Load a widget row joined to its channel, keyed by channel id. Null if missing. */
function webchatLoadByChannel(PDO $conn, int $channelId): ?array
{
    try {
        $stmt = $conn->prepare(
            "SELECT w.*, c.name, c.tenant_id, c.is_active
             FROM webchat_widgets w
             JOIN messaging_channels c ON c.id = w.channel_id
             WHERE w.channel_id = ? LIMIT 1"
        );
        $stmt->execute([$channelId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return null;
    }
    return $row ?: null;
}

/** Load a widget row joined to its channel, keyed by public widget key. Null if missing. */
function webchatLoadByKey(PDO $conn, string $widgetKey): ?array
{
    try {
        $stmt = $conn->prepare(
            "SELECT w.*, c.name, c.tenant_id, c.is_active
             FROM webchat_widgets w
             JOIN messaging_channels c ON c.id = w.channel_id
             WHERE w.widget_key = ? LIMIT 1"
        );
        $stmt->execute([$widgetKey]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return null;
    }
    return $row ?: null;
}

/**
 * Parse the stored allowed_origins blob into a clean list of origins. Accepts one
 * per line (or comma-separated), trims trailing slashes, drops blanks/comments.
 */
function webchatParseOrigins(?string $raw): array
{
    if ($raw === null || trim($raw) === '') {
        return [];
    }
    $parts = preg_split('/[\r\n,]+/', $raw);
    $out = [];
    foreach ($parts as $p) {
        $p = trim($p);
        if ($p === '' || $p[0] === '#') {
            continue;
        }
        $out[] = rtrim($p, '/');
    }
    return array_values(array_unique($out));
}

/**
 * Is the request origin permitted for this widget? An empty allowlist means "any"
 * (testing only). Matching is exact on scheme+host (+port), trailing slash ignored.
 */
function webchatOriginAllowed(array $allowed, ?string $origin): bool
{
    if (empty($allowed)) {
        return true;
    }
    if ($origin === null || $origin === '') {
        return false;
    }
    $origin = rtrim(trim($origin), '/');
    return in_array($origin, $allowed, true);
}

/**
 * Find-or-create the requester for a web chat conversation, keyed by the real email
 * the visitor gave. Returns the user id, or null if the users table is unavailable.
 */
function webchatGetOrCreateUser(PDO $conn, string $email, string $name): ?int
{
    $email = trim($email);
    if ($email === '') {
        return null;
    }
    try {
        $stmt = $conn->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
        $stmt->execute([$email]);
        $id = $stmt->fetchColumn();
        if ($id) {
            if ($name !== '') {
                $conn->prepare("UPDATE users SET display_name = ? WHERE id = ? AND (display_name IS NULL OR display_name = '')")
                     ->execute([$name, (int) $id]);
            }
            return (int) $id;
        }
        $ins = $conn->prepare("INSERT INTO users (email, display_name, created_at) VALUES (?, ?, UTC_TIMESTAMP())");
        $ins->execute([$email, $name !== '' ? $name : $email]);
        return (int) $conn->lastInsertId();
    } catch (Exception $e) {
        return null;
    }
}

/** The visitor's display name for a conversation (name → email → generic). */
function webchatVisitorName(array $conversation): string
{
    $email = trim((string) ($conversation['visitor_email'] ?? ''));
    $name  = trim((string) ($conversation['visitor_name'] ?? ''));
    return $name !== '' ? $name : ($email !== '' ? $email : 'Website visitor');
}

/**
 * Open a ticket for a web chat conversation and pin the conversation to it (one
 * conversation == one ticket). Returns the new ticket id. Shared by the live-ingest
 * path and the deflect-mode escalation path so ticket creation lives in one place.
 */
function webchatOpenTicket(PDO $conn, array $conversation, array $channel, string $subject): int
{
    require_once __DIR__ . '/../messaging/ingest.php';

    $email  = trim((string) ($conversation['visitor_email'] ?? ''));
    $name   = trim((string) ($conversation['visitor_name'] ?? ''));
    $userId       = webchatGetOrCreateUser($conn, $email, $name);
    $ticketNumber = messagingGenerateTicketNumber($conn);
    $tenantId     = ($channel['tenant_id'] ?? null) !== null ? (int) $channel['tenant_id'] : null;
    $originId     = getChannelOriginId($conn, 'webchat');

    $sql = "INSERT INTO tickets (
                ticket_number, subject, status_id, priority_id,
                created_datetime, updated_datetime, last_inbound_at,
                user_id, tenant_id, origin_id
            ) VALUES (
                ?, ?,
                (SELECT id FROM ticket_statuses   WHERE name = 'Open'   LIMIT 1),
                (SELECT id FROM ticket_priorities WHERE name = 'Normal' LIMIT 1),
                UTC_TIMESTAMP(), UTC_TIMESTAMP(), UTC_TIMESTAMP(),
                ?, ?, ?
            )";
    $conn->prepare($sql)->execute([$ticketNumber, $subject, $userId, $tenantId, $originId]);
    $ticketId = (int) $conn->lastInsertId();

    $conn->prepare("UPDATE webchat_conversations SET ticket_id = ?, last_activity_datetime = UTC_TIMESTAMP() WHERE id = ?")
         ->execute([$ticketId, (int) $conversation['id']]);

    return $ticketId;
}

/**
 * Store one inbound visitor message against a ticket in the shared `emails` table
 * (channel='webchat'), so the reading-pane thread and reply path work unchanged.
 * Returns the new email id.
 */
function webchatInsertInbound(PDO $conn, int $ticketId, array $conversation, array $channel, string $body, bool $isInitial): int
{
    require_once __DIR__ . '/../messaging/ingest.php';

    $body = trim($body);
    if ($body === '') {
        $body = '[empty message]';
    }
    $email       = trim((string) ($conversation['visitor_email'] ?? ''));
    $displayName = webchatVisitorName($conversation);
    // The emails.from_address threads the conversation; use the visitor's email if given,
    // else a stable per-conversation pseudo-identifier derived from the token.
    $from = $email !== '' ? $email : ('web-' . ($conversation['token'] ?? ''));

    $ins = $conn->prepare(
        "INSERT INTO emails (
            exchange_message_id, subject, from_address, from_name, to_recipients,
            received_datetime, body_content, body_type, has_attachments, is_read,
            processed_datetime, ticket_id, is_initial, direction, channel, channel_id
        ) VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP(), ?, 'text', 0, 0, UTC_TIMESTAMP(), ?, ?, 'Inbound', 'webchat', ?)"
    );
    $ins->execute([
        'wc_in_' . bin2hex(random_bytes(12)),
        $isInitial ? buildChannelSubject('webchat', $displayName, $body) : null,
        $from,
        $displayName,
        $channel['name'] ?? 'Web chat',
        $body,
        $ticketId,
        $isInitial ? 1 : 0,
        (int) $channel['id'],
    ]);
    $emailId = (int) $conn->lastInsertId();

    try {
        $conn->prepare("UPDATE messaging_channels SET last_inbound_datetime = UTC_TIMESTAMP() WHERE id = ?")
             ->execute([(int) $channel['id']]);
    } catch (Exception $e) { /* non-fatal */ }

    return $emailId;
}

/**
 * Store an automated/outbound web chat message against a ticket in `emails`
 * (direction='Outbound', channel='webchat'), so the analyst sees it in the thread and
 * the widget's reply-poll picks it up. Used to post the AI's answers alongside the
 * ticket in assist mode. Returns the new email id.
 */
function webchatInsertOutbound(PDO $conn, int $ticketId, array $channel, string $body, string $fromName): int
{
    $ins = $conn->prepare(
        "INSERT INTO emails (
            exchange_message_id, subject, from_address, from_name, to_recipients,
            received_datetime, body_content, body_type, has_attachments, is_read,
            processed_datetime, ticket_id, is_initial, direction, channel, channel_id
        ) VALUES (?, NULL, ?, ?, NULL, UTC_TIMESTAMP(), ?, 'text', 0, 1, UTC_TIMESTAMP(), ?, 0, 'Outbound', 'webchat', ?)"
    );
    $ins->execute([
        'wc_out_' . bin2hex(random_bytes(12)),
        $channel['name'] ?? 'Web chat',
        $fromName,
        $body,
        $ticketId,
        (int) $channel['id'],
    ]);
    return (int) $conn->lastInsertId();
}

/**
 * Ingest one inbound visitor message for a web chat conversation. On the first
 * message it opens a ticket and pins the conversation to it; afterwards it appends.
 * Returns the ticket id. (Thin orchestration over webchatOpenTicket + webchatInsertInbound.)
 */
function webchatIngestMessage(PDO $conn, array $conversation, array $channel, string $body, ?int &$emailId = null): int
{
    require_once __DIR__ . '/../messaging/ingest.php';

    $body = trim($body);
    if ($body === '') {
        $body = '[empty message]';
    }

    $ticketId  = !empty($conversation['ticket_id']) ? (int) $conversation['ticket_id'] : 0;
    $isInitial = $ticketId ? false : true;

    if ($isInitial) {
        $subject  = buildChannelSubject('webchat', webchatVisitorName($conversation), $body);
        $ticketId = webchatOpenTicket($conn, $conversation, $channel, $subject);
    } else {
        $conn->prepare("UPDATE tickets SET updated_datetime = UTC_TIMESTAMP(), last_inbound_at = UTC_TIMESTAMP() WHERE id = ?")
             ->execute([$ticketId]);
        // A visitor picking the chat back up on a finished ticket is the customer
        // coming back — same shared rule as email and the portal.
        reopenTicketForCustomerReply($conn, (int)$ticketId);
        $conn->prepare("UPDATE webchat_conversations SET last_activity_datetime = UTC_TIMESTAMP() WHERE id = ?")
             ->execute([(int) $conversation['id']]);
    }

    // $emailId (out) is the stored inbound emails.id — a plain widget polls the emails
    // table, so the caller returns it as the cursor to skip its own optimistic echo.
    $emailId = webchatInsertInbound($conn, $ticketId, $conversation, $channel, $body, $isInitial);

    return $ticketId;
}

// ---------------------------------------------------------------------------
// Part 3 runtime — office hours, the pre-ticket transcript, and escalation.
// ---------------------------------------------------------------------------

/**
 * Is the widget "open" right now? A widget with no business-hours calendar (NULL) is
 * always open. Otherwise the calendar's weekday hours + holidays are evaluated in the
 * calendar's own timezone. A misconfigured calendar (missing, bad tz, no hours rows)
 * fails open — availability should never hard-block a visitor from making contact.
 */
function webchatIsOpenNow(PDO $conn, ?int $calendarId): bool
{
    if (!$calendarId) {
        return true;
    }
    require_once __DIR__ . '/../sla.php';
    try {
        $cal = sla_load_calendar($conn, (int) $calendarId);
    } catch (Exception $e) {
        return true;
    }
    if (!$cal || empty($cal['hours'])) {
        return true;
    }
    try {
        $tz = new DateTimeZone($cal['timezone'] ?: 'UTC');
    } catch (Exception $e) {
        $tz = new DateTimeZone('UTC');
    }
    $now     = new DateTime('now', $tz);
    $dateStr = $now->format('Y-m-d');
    foreach ($cal['holidays'] as $h) {
        if ((string) ($h['holiday_date'] ?? '') === $dateStr) {
            return false;
        }
    }
    $weekday = (int) $now->format('N'); // 1=Mon … 7=Sun
    $time    = $now->format('H:i:s');
    foreach ($cal['hours'] as $h) {
        if ((int) $h['weekday'] === $weekday
            && $time >= (string) $h['start_time'] && $time <= (string) $h['end_time']) {
            return true;
        }
    }
    return false;
}

/**
 * Append a message to a conversation's pre-ticket transcript (webchat_messages).
 * sender is 'visitor'|'ai'|'agent'|'system'. $sourceEmailId links a mirrored analyst
 * reply back to its `emails` row so it's only mirrored once. Returns the new id.
 */
function webchatAddMessage(PDO $conn, int $conversationId, string $sender, string $body, ?int $sourceEmailId = null): int
{
    $conn->prepare(
        "INSERT INTO webchat_messages (conversation_id, sender, body, source_email_id, created_datetime)
         VALUES (?, ?, ?, ?, UTC_TIMESTAMP())"
    )->execute([$conversationId, $sender, $body, $sourceEmailId]);
    // Capture the id before the UPDATE below — a following statement can clear lastInsertId().
    $id = (int) $conn->lastInsertId();
    $conn->prepare("UPDATE webchat_conversations SET last_activity_datetime = UTC_TIMESTAMP() WHERE id = ?")
         ->execute([$conversationId]);
    return $id;
}

/** Read a conversation's transcript messages after $afterId (ascending). */
function webchatGetMessages(PDO $conn, int $conversationId, int $afterId = 0): array
{
    $st = $conn->prepare(
        "SELECT id, sender, body, created_datetime
         FROM webchat_messages
         WHERE conversation_id = ? AND id > ?
         ORDER BY id ASC LIMIT 200"
    );
    $st->execute([$conversationId, $afterId]);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * The full chat transcript as plain text — "Visitor: …" / "Assistant: …" / "Agent: …".
 * Used as the opening message when a deflect conversation is escalated to a ticket, and
 * as the .txt attachment on an email escalation.
 */
function webchatTranscriptText(PDO $conn, int $conversationId): string
{
    $labels = ['visitor' => 'Visitor', 'ai' => 'Assistant', 'agent' => 'Agent', 'system' => 'System'];
    $out = '';
    foreach (webchatGetMessages($conn, $conversationId) as $m) {
        $who  = $labels[$m['sender']] ?? ucfirst((string) $m['sender']);
        $out .= $who . ': ' . trim((string) $m['body']) . "\n";
    }
    return trim($out);
}

/**
 * Mirror any new analyst replies (Outbound webchat emails on the conversation's ticket)
 * into the transcript, so an AI-enabled widget — which reads webchat_messages, not the
 * emails table — shows them to the visitor. Deduped by source_email_id, so AI answers
 * already recorded with their source email (and previously mirrored replies) are skipped.
 */
function webchatMirrorAgentReplies(PDO $conn, array $conversation): void
{
    $ticketId = (int) ($conversation['ticket_id'] ?? 0);
    if ($ticketId <= 0) {
        return;
    }
    $convId = (int) $conversation['id'];

    $have = $conn->prepare("SELECT source_email_id FROM webchat_messages WHERE conversation_id = ? AND source_email_id IS NOT NULL");
    $have->execute([$convId]);
    $seen = array_flip(array_map('intval', $have->fetchAll(PDO::FETCH_COLUMN)));

    $q = $conn->prepare(
        "SELECT id, body_content FROM emails
         WHERE ticket_id = ? AND channel = 'webchat' AND direction = 'Outbound'
         ORDER BY id ASC"
    );
    $q->execute([$ticketId]);
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $eid = (int) $row['id'];
        if (isset($seen[$eid])) {
            continue;
        }
        webchatAddMessage($conn, $convId, 'agent', (string) $row['body_content'], $eid);
    }
}

/**
 * Promote a deflect-mode conversation to a real ticket. No-op returning the existing id
 * if already ticketed. For $mode 'agent' the opening message is the full transcript; for
 * 'email' the opening body is $aiSummary (or a fallback) and the full transcript is
 * attached as a .txt. Returns the ticket id.
 */
function webchatPromoteToTicket(PDO $conn, array $conversation, array $channel, string $mode, ?string $aiSummary = null): int
{
    if (!empty($conversation['ticket_id'])) {
        return (int) $conversation['ticket_id'];
    }
    require_once __DIR__ . '/../messaging/ingest.php';

    $transcript  = webchatTranscriptText($conn, (int) $conversation['id']);
    $displayName = webchatVisitorName($conversation);

    if ($mode === 'email' && ($aiSummary ?? '') !== '') {
        $openingBody = $aiSummary;
        $subjectSeed = $aiSummary;
    } else {
        $openingBody = $transcript !== '' ? $transcript : '[No messages]';
        $subjectSeed = $transcript;
    }

    $subject  = buildChannelSubject('webchat', $displayName, $subjectSeed);
    $ticketId = webchatOpenTicket($conn, $conversation, $channel, $subject);
    $emailId  = webchatInsertInbound($conn, $ticketId, $conversation, $channel, $openingBody, true);

    if ($mode === 'email') {
        require_once __DIR__ . '/../messaging/ingest.php';
        try {
            saveChannelMediaAttachment($conn, $emailId, 'chat-transcript.txt', 'text/plain', $transcript . "\n");
            $conn->prepare("UPDATE emails SET has_attachments = 1 WHERE id = ?")->execute([$emailId]);
        } catch (Exception $e) { /* transcript attach is best-effort */ }
    }

    return $ticketId;
}
