<?php
/**
 * CSAT Survey Helpers
 *
 * Creates a ticket_csat_responses row with a tokenised URL and sends the
 * survey email via the existing template engine. The token is HMAC-derived
 * so a leaked database row can't be replayed against a different install
 * (the per-install secret lives in system_settings.csat_token_secret).
 *
 * Called from:
 *   - TicketsService (csatAutoOnClose) when a ticket transitions into a closed
 *     status and the global csat_mode is 'auto' - at once, or queued for after
 *     csat_delay_minutes and sent by cron/csat_delayed.php (GH #301)
 *   - api/tickets/request_csat.php when an analyst clicks the manual
 *     "Request feedback" button (regardless of mode, as long as mode != 'off')
 */

require_once __DIR__ . '/template_email.php';
require_once __DIR__ . '/encryption.php';   // csat_token_secret is stored encrypted
require_once __DIR__ . '/session_security.php'; // requestScheme() — proxy-aware (GH #152)
require_once __DIR__ . '/module_availability.php'; // moduleIsEnabled('csat') - GH #217

/**
 * Read a system_settings key with a default fallback.
 *
 * @param string $key  the system_settings.setting_key, e.g. 'csat_mode'; encrypted keys are decrypted
 * @param string $default  returned when the key has no row (the first answer is cached per request)
 */
function csatGetSetting(PDO $conn, string $key, string $default = ''): string {
    $cache = &csatSettingCache();
    if (isset($cache[$key])) return $cache[$key];
    $stmt = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ?");
    $stmt->execute([$key]);
    $val = $stmt->fetchColumn();
    // csat_token_secret is a signing key, so it is encrypted at rest. Decrypt on the
    // way out; decryptValue() passes a plaintext value through untouched, so tokens
    // issued before the change still verify against the same secret.
    if ($val !== false && $val !== null && isEncryptedSettingKey($key)) {
        $val = decryptValue($val);
    }
    $cache[$key] = ($val === false || $val === null) ? $default : (string)$val;
    return $cache[$key];
}

/** The per-request settings cache csatGetSetting() fills (by reference). */
function &csatSettingCache(): array {
    static $cache = [];
    return $cache;
}

/** Forget cached CSAT settings - for a long-running process or a test that changes them. */
function csatSettingsForget(): void {
    $cache = &csatSettingCache();
    $cache = [];
}

/**
 * Generate a token unique to (ticket, response_row). 32-byte random base ensures
 * it's not guessable from ticket id alone; we then HMAC it with the install
 * secret so even a database leak of `token` alone isn't enough — though for the
 * survey URL we just use the random portion since the response row IS the
 * authoritative store. Keeping HMAC available as a verification step in case we
 * ever want to surface the link in places where the DB isn't queried.
 */
function csatGenerateToken(): string {
    return bin2hex(random_bytes(24)); // 48 hex chars — fits in VARCHAR(64)
}

/**
 * Build the full public URL the user clicks. Uses the host the request came
 * in on so multi-domain installs (test.example.com vs example.com) work without
 * config changes.
 *
 * @param string $token  the survey token from ticket_csat_responses.token; URL-encoded here
 */
function csatBuildUrl(string $token, ?PDO $conn = null): string {
    // GH #301: with a connection, the CONFIGURED public address (System -> Settings),
    // then the request's host - publicAbsoluteUrl()'s order. A delayed survey is sent
    // by a background job with no request at all, where the code below would build
    // http://localhost/... and mail it to a customer.
    if ($conn !== null) {
        require_once __DIR__ . '/public_url.php';
        return publicAbsoluteUrl($conn, 'csat?token=' . urlencode($token));
    }
    $scheme = requestScheme();
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    // Find the install root — config.php sits at the repo root, so we walk
    // up from this file's directory to derive it relative to DOCUMENT_ROOT.
    $docRoot = rtrim(str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
    $appRoot = rtrim(str_replace('\\', '/', realpath(__DIR__ . '/..')), '/');
    $appPath = $docRoot && str_starts_with($appRoot, $docRoot)
        ? substr($appRoot, strlen($docRoot))
        : '';
    // Canonical survey URL since the root-folder tidy (the old /csat.php
    // form still 301s here, so links in already-sent emails keep working).
    return $scheme . '://' . $host . $appPath . '/csat?token=' . urlencode($token);
}

/**
 * Result codes returned by sendCsatSurvey() so the caller can give an honest
 * UI message rather than silently lying. The DB row + email send are only
 * performed when the result will be 'sent' — every other case is a precondition
 * failure that should NOT leave an orphan row behind.
 */
const CSAT_RESULT_SENT          = 'sent';
const CSAT_RESULT_OFF           = 'off';            // csat_mode = off
const CSAT_RESULT_ALREADY_SENT  = 'already_sent';   // one-per-ticket guard tripped
const CSAT_RESULT_NO_TEMPLATE   = 'no_template';    // no active csat_request template
const CSAT_RESULT_NO_MAILBOX    = 'no_mailbox';     // ticket has no mailbox to send from

// ===========================================================================
//  Asking in the customer's own chat (PR #166, built on turbay-a's original)
// ===========================================================================
//
// A customer who wrote in on WhatsApp, Telegram, Slack, Teams or Mattermost is
// asked for their rating in that chat - buttons where the channel has them, a
// "reply 1 to 5" message where it does not - instead of by email.
//
// TRAP: an upgrade must not change who is asked, or how.
//   `csat_in_channel` is OFF after an upgrade (db_verify seeds '0'; a new
//   install's freeitsm.sql seeds '1'). An install that has emailed every survey
//   for months must not start messaging its chat customers the day it upgrades.
//
// TRAP: match a chat reply only to a request sent IN THAT CHAT.
//   channel_id + channel_from record where a request went. Never find "the
//   pending survey" through the emails table alone - that also finds emailed
//   surveys and other chats' requests.
//
// TRAP: no orphan rows. The row exists only to give the buttons an id; if the
//   send fails it is deleted and the email survey is tried instead. With
//   one-survey-per-ticket on, a stray row would mean the customer is never asked.
//
// TRAP: WhatsApp outside its 24-hour window cannot take a free-form message -
//   csatTicketChannel() checks channelWindowOpen() and falls back to email.

/** How long a chat rating request is listened for. A digit a week later is a message, not a rating. */
const CSAT_CHANNEL_REPLY_WINDOW_DAYS = 7;

/**
 * The chat a ticket's customer is talking on, ready to ask in - or null, which
 * means "use the email survey": the setting is off, the ticket's latest contact
 * was not a chat, the channel has gone, or (WhatsApp) the 24-hour window has
 * closed and a free-form message cannot be sent.
 *
 * @return array{channel:array, from:string, address:string}|null
 */
function csatTicketChannel(PDO $conn, int $ticketId): ?array {
    if (csatGetSetting($conn, 'csat_in_channel', '0') !== '1') {
        return null;
    }
    require_once __DIR__ . '/messaging/messaging.php';
    $stmt = $conn->prepare(
        "SELECT channel, channel_id, from_address, to_recipients, received_datetime
         FROM emails
         WHERE ticket_id = ? AND channel <> 'email' AND channel_id IS NOT NULL AND direction = 'Inbound'
         ORDER BY received_datetime DESC, id DESC LIMIT 1"
    );
    $stmt->execute([$ticketId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }
    $channel = loadMessagingChannel($conn, (int)$row['channel_id']);
    if (!$channel || empty($channel['is_active'])) {
        return null;
    }
    $type = (string)($channel['channel_type'] ?? $row['channel']);
    if (channelHasServiceWindow($type) && !channelWindowOpen($row['received_datetime'])) {
        return null;   // WhatsApp outside 24h: only a pre-approved template may be sent
    }
    $address = messagingReplyAddress($type, $row);
    if ($address === '' || (string)$row['from_address'] === '') {
        return null;
    }
    return ['channel' => $channel, 'from' => (string)$row['from_address'], 'address' => $address];
}

/**
 * The language to write a customer-facing rating message in. Telegram has the
 * customer's own language_code (stored on the identity link); other channels do
 * not report one, so they get English.
 *
 * @param array $channel  the messaging_channels row as loadMessagingChannel() returns it
 * @param string $from  the customer's id on that channel (the inbound message's from_address)
 */
function csatChannelLocale(PDO $conn, array $channel, string $from): string {
    if (($channel['channel_type'] ?? '') !== 'telegram') {
        return 'en';
    }
    try {
        $stmt = $conn->prepare("SELECT locale FROM messaging_identity_links WHERE channel_type = 'telegram' AND external_id = ?");
        $stmt->execute([$from]);
        $locale = (string)($stmt->fetchColumn() ?: '');
    } catch (Throwable) {
        $locale = '';
    }
    return $locale !== '' ? $locale : 'en';
}

/**
 * Ask a chat customer for a 1-5 rating in their chat. Returns the response id,
 * or throws - having removed its own row - so the caller can fall back to email.
 *
 * @param array $target  where to ask, as csatTicketChannel() returns it: channel row, from, address
 */
function csatSendInChannel(PDO $conn, int $ticketId, array $target, ?int $analystId): int {
    require_once __DIR__ . '/messaging/messaging.php';
    require_once __DIR__ . '/i18n.php';

    $channel = $target['channel'];
    $conn->prepare(
        "INSERT INTO ticket_csat_responses (ticket_id, token, sent_datetime, analyst_id, created_at, channel_id, channel_from)
         VALUES (?, ?, UTC_TIMESTAMP(), ?, UTC_TIMESTAMP(), ?, ?)"
    )->execute([$ticketId, csatGenerateToken(), $analystId, (int)$channel['id'], $target['from']]);
    $responseId = (int)$conn->lastInsertId();

    try {
        $text = I18n::tFor(csatChannelLocale($conn, $channel, $target['from']), 'tickets.csat_channel.prompt');
        messagingProvider($channel)->sendRatingRequest($target['address'], $text, $responseId);
    } catch (Throwable $e) {
        // Nothing orphaned: the request never reached them, so neither does the row.
        $conn->prepare("DELETE FROM ticket_csat_responses WHERE id = ?")->execute([$responseId]);
        throw $e;
    }
    return $responseId;
}

/**
 * The rating request a customer's bare digit answers, or null - in which case
 * the digit is an ordinary message. All of these must hold:
 *   - the request was sent IN THIS CHAT (channel_id + channel_from);
 *   - it is unanswered and at most CSAT_CHANNEL_REPLY_WINDOW_DAYS old;
 *   - the customer has written nothing else in this chat since it was sent.
 *
 * TRAP: a bare digit is usually NOT a rating. "Which floor?" "3" is an answer
 *   to the analyst, and a "2" next month starts a new conversation - loosen any
 *   of the three conditions and real messages vanish into a survey, with no
 *   error anywhere. tests/messaging-teams-mattermost.php checks each one.
 *
 * @param int $channelId  messaging_channels.id the digit arrived on
 * @param string $from  the sender's id on that channel (from_address)
 */
function csatPendingRequestForChat(PDO $conn, int $channelId, string $from): ?int {
    try {
        $stmt = $conn->prepare(
            "SELECT r.id FROM ticket_csat_responses r
             WHERE r.channel_id = ? AND r.channel_from = ?
               AND r.rating IS NULL AND r.sent_datetime IS NOT NULL
               AND r.sent_datetime >= UTC_TIMESTAMP() - INTERVAL " . (int)CSAT_CHANNEL_REPLY_WINDOW_DAYS . " DAY
               AND NOT EXISTS (
                   SELECT 1 FROM emails e
                   WHERE e.channel_id = r.channel_id AND e.from_address = r.channel_from
                     AND e.direction = 'Inbound' AND e.received_datetime > r.sent_datetime
               )
             ORDER BY r.id DESC LIMIT 1"
        );
        $stmt->execute([$channelId, $from]);
        $id = $stmt->fetchColumn();
    } catch (Throwable) {
        return null;   // before Database Verification: no chat surveys exist yet
    }
    return $id ? (int)$id : null;
}

/**
 * Was this request sent to this customer on this channel? Guards a button press against a forged or replayed id.
 *
 * @param int $responseId  ticket_csat_responses.id carried by the button (untrusted)
 * @param int $channelId  messaging_channels.id the press arrived on
 * @param string $from  the presser's id on that channel
 */
function csatResponseBelongsToChat(PDO $conn, int $responseId, int $channelId, string $from): bool {
    try {
        $stmt = $conn->prepare("SELECT 1 FROM ticket_csat_responses WHERE id = ? AND channel_id = ? AND channel_from = ?");
        $stmt->execute([$responseId, $channelId, $from]);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable) {
        return false;
    }
}

/**
 * The rating a survey holds, or null while it is unanswered.
 *
 * @param int $responseId  ticket_csat_responses.id
 */
function csatStoredRating(PDO $conn, int $responseId): ?int {
    try {
        $stmt = $conn->prepare("SELECT rating FROM ticket_csat_responses WHERE id = ?");
        $stmt->execute([$responseId]);
        $r = $stmt->fetchColumn();
        return ($r === false || $r === null) ? null : (int)$r;
    } catch (Throwable) {
        return null;
    }
}

/**
 * Record a 1-5 rating once. Returns false if it was already answered or out of range.
 *
 * @param int $responseId  ticket_csat_responses.id to answer
 * @param int $rating  the customer's score, 1 to 5
 */
function csatRecordRating(PDO $conn, int $responseId, int $rating): bool {
    if ($rating < 1 || $rating > 5) {
        return false;
    }
    $upd = $conn->prepare(
        "UPDATE ticket_csat_responses SET rating = ?, responded_datetime = UTC_TIMESTAMP()
         WHERE id = ? AND rating IS NULL"
    );
    $upd->execute([$rating, $responseId]);
    return $upd->rowCount() > 0;
}

/**
 * Create a CSAT response row and send the survey email. Returns an array:
 *   ['result' => <CSAT_RESULT_*>, 'response_id' => int|null]
 *
 * Pass $force = true to bypass the one-per-ticket guard when the analyst
 * deliberately re-requests feedback.
 *
 * Pre-flight checks (mode, template existence, mailbox availability) all run
 * BEFORE the row is inserted, so failed sends don't leave orphan tokens behind.
 *
 * @param bool $force  true to send even when one-survey-per-ticket is on and one already exists
 */
function sendCsatSurvey(PDO $conn, int $ticketId, ?int $analystId, bool $force = false): array {
    // GH #217: CSAT switched off for the install - no survey by any route.
    if (!moduleIsEnabled($conn, 'csat')) {
        return ['result' => CSAT_RESULT_OFF, 'response_id' => null];
    }
    $mode = csatGetSetting($conn, 'csat_mode', 'off');
    if ($mode === 'off') {
        return ['result' => CSAT_RESULT_OFF, 'response_id' => null];
    }

    // Skip if a response row already exists and one-per-ticket is on (unless forced)
    if (!$force && csatGetSetting($conn, 'csat_one_per_ticket', '1') === '1') {
        $existing = $conn->prepare("SELECT id FROM ticket_csat_responses WHERE ticket_id = ? LIMIT 1");
        $existing->execute([$ticketId]);
        if ($existing->fetchColumn()) {
            return ['result' => CSAT_RESULT_ALREADY_SENT, 'response_id' => null];
        }
    }

    // A chat customer is asked in their chat when the install has chosen that
    // (csat_in_channel) and the chat can take a message now. If the send fails,
    // csatSendInChannel() has removed its row and we fall through to email, so
    // a customer who also has an email address is still asked (PR #166).
    $target = csatTicketChannel($conn, $ticketId);
    if ($target) {
        try {
            return ['result' => CSAT_RESULT_SENT, 'response_id' => csatSendInChannel($conn, $ticketId, $target, $analystId)];
        } catch (Throwable $e) {
            error_log('CSAT in-chat request failed for ticket ' . $ticketId . ', trying email: ' . $e->getMessage());
        }
    }

    // Pre-flight: an active csat_request template must exist. Without it, the
    // template engine silently no-ops and we'd save a useless row with a useless token.
    $tplCheck = $conn->prepare("SELECT id FROM ticket_email_templates WHERE event_trigger = 'csat_request' AND is_active = 1 LIMIT 1");
    $tplCheck->execute();
    if (!$tplCheck->fetchColumn()) {
        return ['result' => CSAT_RESULT_NO_TEMPLATE, 'response_id' => null];
    }

    // Pre-flight: a mailbox must be reachable from this ticket. Manual / portal
    // tickets that came in without an email won't have one — survey would no-op.
    require_once __DIR__ . '/template_email.php';
    $mailbox = templateGetMailboxForTicket($conn, $ticketId);
    if (!$mailbox) {
        return ['result' => CSAT_RESULT_NO_MAILBOX, 'response_id' => null];
    }

    // All checks passed — now we can durably create the row and dispatch the email
    $token = csatGenerateToken();
    $insert = $conn->prepare(
        "INSERT INTO ticket_csat_responses (ticket_id, token, sent_datetime, analyst_id, created_at)
         VALUES (?, ?, UTC_TIMESTAMP(), ?, UTC_TIMESTAMP())"
    );
    $insert->execute([$ticketId, $token, $analystId]);
    $responseId = (int)$conn->lastInsertId();
    // The site address this survey's link uses, so a reminder sent later from a
    // background job links to the same place (GH #301). Its own statement: the
    // column arrives with Database Verify, and a survey must still go without it.
    try {
        require_once __DIR__ . '/public_url.php';
        $base = publicBaseUrl($conn);
        if (preg_match('#^https?://#i', $base)) {
            $conn->prepare("UPDATE ticket_csat_responses SET link_base = ? WHERE id = ?")->execute([mb_substr($base, 0, 255), $responseId]);
        }
    } catch (Throwable) { /* before Database Verify */ }

    sendTemplateEmail($conn, $ticketId, 'csat_request', [
        'csat_link' => csatBuildUrl($token, $conn),
    ]);

    return ['result' => CSAT_RESULT_SENT, 'response_id' => $responseId];
}

// ===========================================================================
//  "Delay before sending" (GH #301)
// ===========================================================================
//
// Tickets -> Settings -> CSAT has had a "Delay before sending (minutes)" since
// CSAT began, saved and shown back - and read by nothing: the survey went the
// moment the ticket closed. Now a close with a delay QUEUES the survey in
// csat_queue (one row per ticket), and the csat_delayed background job sends it
// once due - but only if the ticket is still closed then. Reopened in the
// meantime, the survey is dropped; closed again, it is queued afresh with a new
// delay (the row is per ticket, so the second close moves its due time).
//
// TRAP: an upgrade must not stop surveys. With no delay set (0, the default), or
//   before Database Verification has made csat_queue, the survey goes at once,
//   exactly as before.
// TRAP: the job claims a row by DELETING it (rowCount 1) before sending, so two
//   overlapping runs cannot send one survey twice.

/**
 * A ticket has just closed with CSAT on "auto": send its survey now, or queue it
 * for after the delay. Never throws on the queue - a queue that cannot be written
 * sends at once rather than losing the survey.
 *
 * @param ?int $analystId  who closed it - stored on the survey as for an immediate send
 */
function csatAutoOnClose(PDO $conn, int $ticketId, ?int $analystId): void {
    if (csatGetSetting($conn, 'csat_mode', 'off') !== 'auto') return;
    $delay = max(0, (int)csatGetSetting($conn, 'csat_delay_minutes', '0'));
    if ($delay > 0) {
        try {
            // The site address as it is NOW, while there may be a request to read it
            // from - the job that sends this later has none (see csatSendDue()).
            require_once __DIR__ . '/public_url.php';
            $linkBase = publicBaseUrl($conn);
            $linkBase = preg_match('#^https?://#i', $linkBase) ? mb_substr($linkBase, 0, 255) : null;
            $conn->prepare(
                "INSERT INTO csat_queue (ticket_id, due_datetime, analyst_id, link_base, created_datetime)
                 VALUES (?, UTC_TIMESTAMP() + INTERVAL ? MINUTE, ?, ?, UTC_TIMESTAMP())
                 ON DUPLICATE KEY UPDATE due_datetime = VALUES(due_datetime), analyst_id = VALUES(analyst_id),
                                         link_base = VALUES(link_base), created_datetime = VALUES(created_datetime)"
            )->execute([$ticketId, $delay, $analystId, $linkBase]);
            return;
        } catch (Throwable $e) {
            error_log('CSAT: could not queue the delayed survey for ticket ' . $ticketId . ' - sending now: ' . $e->getMessage());
        }
    }
    sendCsatSurvey($conn, $ticketId, $analystId);
}

/** Longest wait an administrator can set before the reminder: two weeks. */
const CSAT_REMINDER_MAX_HOURS = 336;
/**
 * A survey whose reminder fell due more than this long ago gets none. It is what
 * stops switching reminders on from emailing every unanswered survey ever sent -
 * and a job that was down for days from catching up with stale ones.
 */
const CSAT_REMINDER_CATCHUP_HOURS = 48;

/**
 * Send one reminder for each emailed survey that has gone unanswered for
 * Tickets -> Settings -> CSAT's "Remind after (hours)" (GH #301, Abdullah31H's
 * suggestion). Called by cron/csat_delayed.php after the delayed surveys.
 *
 * - Off unless "Send one reminder" is ticked (csat_reminder = 1).
 * - At most ONE per survey: reminder_sent_datetime is set before sending, by an
 *   UPDATE that only one run can win - even a failed send counts as the one.
 * - Only surveys that fell due in the last CSAT_REMINDER_CATCHUP_HOURS.
 * - Emailed surveys only (channel_id NULL); a chat request has its own window.
 * - Not if the ticket has since been reopened, trashed, or CSAT switched off.
 * - The same link (token), so either email works and one rating is stored.
 * - The "CSAT reminder" email template when there is an active one, otherwise
 *   the survey template again - so it works before anyone writes one.
 *
 * @param ?callable $send  fn(int $ticketId, string $event, string $link) that sends the email; null = sendTemplateEmail().
 *                         Only tests pass one, so they can check who would be emailed without mailing anybody.
 * @return array{sent:int, skipped:int, failed:int}
 */
function csatSendReminders(PDO $conn, int $limit = 200, ?callable $send = null): array {
    $out = ['sent' => 0, 'skipped' => 0, 'failed' => 0];
    csatSettingsForget();
    if (csatGetSetting($conn, 'csat_reminder', '0') !== '1' || csatGetSetting($conn, 'csat_mode', 'off') === 'off'
        || !moduleIsEnabled($conn, 'csat')) {
        return $out;
    }
    $hours = min(CSAT_REMINDER_MAX_HOURS, max(1, (int)csatGetSetting($conn, 'csat_reminder_hours', '48')));
    $due = $conn->prepare(
        "SELECT r.id, r.ticket_id, r.token, r.link_base
           FROM ticket_csat_responses r
          WHERE r.rating IS NULL AND r.reminder_sent_datetime IS NULL AND r.channel_id IS NULL
            AND r.sent_datetime <= UTC_TIMESTAMP() - INTERVAL $hours HOUR
            AND r.sent_datetime >  UTC_TIMESTAMP() - INTERVAL " . ($hours + CSAT_REMINDER_CATCHUP_HOURS) . " HOUR
          ORDER BY r.sent_datetime ASC LIMIT " . max(1, $limit));
    $due->execute();
    $claim = $conn->prepare("UPDATE ticket_csat_responses SET reminder_sent_datetime = UTC_TIMESTAMP() WHERE id = ? AND reminder_sent_datetime IS NULL AND rating IS NULL");
    $state = $conn->prepare("SELECT t.deleted_datetime, COALESCE(s.is_closed, 0) AS is_closed
                               FROM tickets t LEFT JOIN ticket_statuses s ON s.id = t.status_id WHERE t.id = ?");
    $tpl = $conn->prepare("SELECT 1 FROM ticket_email_templates WHERE event_trigger = ? AND is_active = 1 LIMIT 1");
    $tpl->execute(['csat_reminder']);
    $event = $tpl->fetchColumn() ? 'csat_reminder' : 'csat_request';
    if ($event === 'csat_request') {
        // No reminder template and no survey template either: nothing could be sent,
        // so claim nothing - marking reminders "sent" would lose them for good.
        $tpl->execute(['csat_request']);
        if (!$tpl->fetchColumn()) return $out;
    }
    $send ??= fn(int $ticketId, string $ev, string $link) => sendTemplateEmail($conn, $ticketId, $ev, ['csat_link' => $link]);
    $configured = publicBaseUrlSetting($conn) !== '';
    foreach ($due->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $claim->execute([(int)$r['id']]);
        if ($claim->rowCount() !== 1) continue;   // answered or reminded meanwhile, or another run took it
        $state->execute([(int)$r['ticket_id']]);
        $t = $state->fetch(PDO::FETCH_ASSOC);
        if (!$t || $t['deleted_datetime'] !== null || (int)$t['is_closed'] !== 1) { $out['skipped']++; continue; }
        try {
            csatUseLinkBase($conn, $configured ? null : ($r['link_base'] ?? null));
            $send((int)$r['ticket_id'], $event, csatBuildUrl((string)$r['token'], $conn));
            publicBaseUrlForget();
            $out['sent']++;
        } catch (Throwable $e) {
            publicBaseUrlForget();
            $out['failed']++;
            error_log('CSAT: reminder for survey ' . $r['id'] . ' failed: ' . $e->getMessage());
        }
    }
    return $out;
}

/**
 * Make the next survey link start with $base - the site address a queued survey's
 * ticket was closed from - unless an address is configured, which always wins.
 * Null just forgets any earlier one. Undone with publicBaseUrlForget().
 *
 * @param ?string $base  e.g. https://desk.example.com/freeitsm, or null
 */
function csatUseLinkBase(PDO $conn, ?string $base): void {
    require_once __DIR__ . '/public_url.php';
    publicBaseUrlForget();
    if ($base === null || $base === '' || publicBaseUrlSetting($conn) !== '') return;
    $root = &publicBaseUrlCache();
    $root = rtrim($base, '/');
}

/**
 * Send the surveys whose delay has passed. Called by cron/csat_delayed.php.
 * Each row is claimed by deleting it, then sent only if CSAT is still on "auto"
 * and the ticket is still closed and not in the trash.
 *
 * @param int $limit  at most this many surveys in one run
 * @return array{sent:int, reopened:int, skipped:int, failed:int, details:list<array{ticket_id:int,outcome:string}>}
 */
function csatSendDue(PDO $conn, int $limit = 200): array {
    $out = ['sent' => 0, 'reopened' => 0, 'skipped' => 0, 'failed' => 0, 'details' => []];
    require_once __DIR__ . '/public_url.php';
    $configured = publicBaseUrlSetting($conn) !== '';
    $due = $conn->prepare("SELECT ticket_id, due_datetime, analyst_id, link_base FROM csat_queue
                            WHERE due_datetime <= UTC_TIMESTAMP() ORDER BY due_datetime ASC LIMIT " . max(1, $limit));
    $due->execute();
    $claim = $conn->prepare("DELETE FROM csat_queue WHERE ticket_id = ? AND due_datetime = ?");
    $state = $conn->prepare("SELECT t.deleted_datetime, COALESCE(s.is_closed, 0) AS is_closed
                               FROM tickets t LEFT JOIN ticket_statuses s ON s.id = t.status_id WHERE t.id = ?");
    foreach ($due->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $ticketId = (int)$row['ticket_id'];
        $claim->execute([$ticketId, $row['due_datetime']]);
        if ($claim->rowCount() !== 1) continue;   // another run took it, or a new close moved it
        $state->execute([$ticketId]);
        $t = $state->fetch(PDO::FETCH_ASSOC);
        if (!$t || $t['deleted_datetime'] !== null || (int)$t['is_closed'] !== 1) {
            $out['reopened']++;
            $out['details'][] = ['ticket_id' => $ticketId, 'outcome' => $t ? 'reopened' : 'gone'];
            continue;
        }
        if (!moduleIsEnabled($conn, 'csat') || csatGetSetting($conn, 'csat_mode', 'off') !== 'auto') {
            $out['skipped']++;
            $out['details'][] = ['ticket_id' => $ticketId, 'outcome' => 'csat_off'];
            continue;
        }
        try {
            // TRAP: no request here, so with no public address configured the link
            // would be a bare path. Use the address the ticket was closed from, just
            // for this send. A configured address always wins.
            csatUseLinkBase($conn, $configured ? null : ($row['link_base'] ?? null));
            $r = sendCsatSurvey($conn, $ticketId, $row['analyst_id'] !== null ? (int)$row['analyst_id'] : null);
            publicBaseUrlForget();
            $r['result'] === CSAT_RESULT_SENT ? $out['sent']++ : $out['skipped']++;
            $out['details'][] = ['ticket_id' => $ticketId, 'outcome' => $r['result']];
        } catch (Throwable $e) {
            $out['failed']++;
            $out['details'][] = ['ticket_id' => $ticketId, 'outcome' => 'failed: ' . $e->getMessage()];
            error_log('CSAT: delayed survey for ticket ' . $ticketId . ' failed: ' . $e->getMessage());
        }
    }
    return $out;
}
