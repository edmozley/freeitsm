<?php
/**
 * Channel message ingest — the bridge from a normalised inbound message onto the
 * existing ticket "membrane". This is the channel twin of saveEmailToDatabase()
 * in api/tickets/check_mailbox_email.php: find-or-create a ticket, store the
 * message in the shared `emails` table (so the reading-pane thread works for
 * free), route the company, and keep the 24h window state current.
 *
 * Deliberately self-contained (no dependency on the email importer's internals)
 * so it is safe to include from the webhook with just functions.php + tenancy.php
 * + messaging.php loaded.
 */

require_once __DIR__ . '/messaging.php';
require_once __DIR__ . '/../tenancy.php';
require_once __DIR__ . '/../ticket_reply.php';
require_once __DIR__ . '/../ticket_snooze.php';
require_once __DIR__ . '/../uploads.php';   // uploadStoreBytes() — see F1
require_once __DIR__ . '/../csat.php';      // csatPendingRequestForChat(), rating helpers
require_once __DIR__ . '/../i18n.php';      // I18n::tFor() — Telegram bot replies in the customer's own language_code
require_once __DIR__ . '/../ticket_numbering.php';

/**
 * Ingest one normalised inbound message for a (decrypted) channel row.
 * Returns ['status' => 'created'|'appended'|'duplicate', 'ticket_id' => int|null].
 *
 * @param array $channel  the messaging_channels row, decrypted
 * @param array $msg  one normalised message from the provider's parseInbound() (from, to, body, profile_name, provider_msg_id,
 *        media, and optionally csat, contact, sender_id, language_code, is_test)
 */
function ingestInboundMessage(PDO $conn, array $channel, array $msg): array
{
    $channelType = $channel['channel_type'] ?? 'whatsapp';
    // ⚠️ The channel type MUST be passed: normalisation differs per channel, and
    // the phone rules would shred a Slack user id into a shared identity.
    $from = normaliseChannelIdentifier((string) ($msg['from'] ?? ''), $channelType);
    if ($from === '') {
        throw new Exception('Inbound message has no usable sender identifier');
    }

    $profileName   = trim((string) ($msg['profile_name'] ?? ''));
    $providerMsgId = trim((string) ($msg['provider_msg_id'] ?? ''));

    // Dedupe: providers can retry webhooks. Skip a message id we already stored.
    if ($providerMsgId !== '') {
        $dup = $conn->prepare("SELECT id FROM emails WHERE exchange_message_id = ? AND channel = ? LIMIT 1");
        $dup->execute([$providerMsgId, $channelType]);
        if ($dup->fetchColumn()) {
            return ['status' => 'duplicate', 'ticket_id' => null];
        }
    }

    // Body is the caption (channel media) or the text. Media is downloaded after the
    // message row exists, because attachments are keyed to the email row's id.
    $body = trim((string) ($msg['body'] ?? ''));
    $mediaItems = is_array($msg['media'] ?? null) ? $msg['media'] : [];
    $hasMedia = !empty($mediaItems);

    // CSAT (PR #166): a 1-5 rating is an answer to a survey, not a new ticket
    // message. Two shapes reach here: a rating-button press (Telegram, Teams,
    // Mattermost), and a bare digit typed in reply to a text request. A digit
    // only counts while csatPendingRequestForChat() says a request in THIS chat
    // is waiting for one - otherwise "3" is an answer to an analyst's question.
    $csatReply = null;
    if (!empty($msg['csat'])) {
        $csatReply = [
            'response_id' => (int) $msg['csat']['response_id'],
            'rating'      => (int) $msg['csat']['rating'],
            'button'      => true,
            'callback_id' => (string) ($msg['csat']['callback_id'] ?? ''),
            'message_id'   => (string) ($msg['csat']['message_id'] ?? ''),
            'message_text' => (string) ($msg['csat']['message_text'] ?? ''),
        ];
    } elseif (!$hasMedia && preg_match('/^[1-5]$/', $body) && moduleIsEnabled($conn, 'csat')) {
        // GH #217: with CSAT switched off a bare digit is an ordinary message.
        $pending = csatPendingRequestForChat($conn, (int) $channel['id'], $from);
        if ($pending !== null) {
            $csatReply = ['response_id' => $pending, 'rating' => (int) $body, 'button' => false];
        }
    }
    if ($csatReply !== null) {
        $replyAddress = messagingReplyAddress((string) ($channel['channel_type'] ?? ''), ['from_address' => $from, 'to_recipients' => (string) ($msg['to'] ?? '')]);
        return messagingRecordCsatReply($conn, $channel, $from, $replyAddress, $csatReply);
    }

    if ($body === '' && !$hasMedia) {
        $body = '[empty message]';
    }

    // Where a reply to this message has to go.
    //
    // ⚠️ Phone channels keep their previous value EXACTLY — the channel's own
    // number. Twilio and Meta do both supply a 'to' in the parsed message and it
    // is normally the same number, but "normally" is not a good enough reason to
    // change what a working WhatsApp install writes to the database. Only Slack,
    // where the sender and the destination are genuinely different things (you
    // answer into a channel and a thread, not to a person), reads it - and since
    // PR #166 Teams and Mattermost, which are threaded the same way.
    $replyAddress = (string) ($channel['phone_number'] ?? '');
    if (in_array($channelType, MESSAGING_THREADED_CHANNELS, true)) {
        $replyAddress = trim((string) ($msg['to'] ?? ''));
    }

    $displayName = $profileName !== '' ? $profileName : $from;
    $contactOnly = false;
    if ($channelType === 'slack') {
        // Ask Slack who this is. Never fatal: a ticket must still be raised if
        // the lookup fails, just with a less useful name on it.
        [$userId, $displayName] = resolveSlackRequester($conn, $channel, $from);
    } elseif ($channelType === 'telegram') {
        // Never blocks a ticket on this: the placeholder requester created
        // below is usable from message one, exactly like any other channel.
        // The phone number, once/if shared, only IMPROVES the match (a real
        // existing profile instead of a placeholder), and what happened is put
        // on the ticket's audit trail — see messagingTelegramResolveIdentity().
        $tg = messagingTelegramResolveIdentity($conn, $channel, $from, $msg, $profileName);
        $userId = $tg['user_id'] ?: null;   // 0 = no usable users table; leave unset like the others
        $contactOnly = $tg['contact_only'];
        if ($tg['display_name'] !== '') {
            $displayName = $tg['display_name'];
        }
    } elseif ($channelType === 'teams' || $channelType === 'mattermost') {
        // Ask the platform who this is, and file the ticket under the person
        // FreeITSM already knows when their email matches - the Slack rule.
        // Suggested by the contributor of PR #166; never blocks the ticket.
        [$userId, $displayName] = resolveDirectoryRequester($conn, $channel, $msg, $from, $displayName);
    } else {
        $userId = getOrCreateChannelUser($conn, $from, $displayName, $channelType);
    }

    // A bare "here's my phone number" tap carries no question to raise (or
    // append to) a ticket about — the identity work above is already done;
    // just stop.
    //
    // ⚠️ Tested against the text AS IT ARRIVED, not $body: an empty $body has
    // already been replaced with '[empty message]' above, so PR #159's
    // `$body === ''` never matched, and every phone tap added an
    // "[empty message]" entry to the ticket and bumped it as a customer reply.
    if ($contactOnly && trim((string) ($msg['body'] ?? '')) === '' && !$hasMedia) {
        return ['status' => 'contact_linked', 'ticket_id' => null];
    }

    // Thread into an open conversation, else open a new ticket.
    //
    // ⚠️ "The same conversation" is not the same question on every channel. On
    // WhatsApp a person has ONE conversation with the service desk, so the sender
    // identifies it. In Slack the same person can have several unrelated threads
    // going at once — so the THREAD is the conversation, and matching on the
    // sender would pile every one of their questions onto a single ticket.
    $ticketId  = findOpenChannelTicket($conn, $from, $channelType, $replyAddress, (int) $channel['id']);
    $isInitial = $ticketId ? 0 : 1;
    $subject   = null;

    if (!$ticketId) {
        // The company is decided BEFORE the number: per-company numbering and
        // {COMPANY} both need it.
        $tenantId     = resolveTicketTenantForChannel($conn, $channel['id'], $from);
        $originId     = getChannelOriginId($conn, $channelType);
        $subject      = buildChannelSubject($channelType, $displayName, $body);
        // GH #230: the type rules / default, before the number (which can carry it).
        require_once __DIR__ . '/../ticket_type_rules.php';
        $typePick     = ticketTypeAutoPick($conn, $tenantId, ['channel' => 'messaging', 'origin_id' => $originId, 'subject' => $subject]);
        $ticketNumber = messagingGenerateTicketNumber($conn, $tenantId, $typePick['type_id'] ?? null);

        $sql = "INSERT INTO tickets (
                    ticket_number, subject, status_id, priority_id,
                    created_datetime, updated_datetime, last_inbound_at,
                    user_id, tenant_id, origin_id, ticket_type_id, department_id
                ) VALUES (
                    ?, ?,
                    (SELECT id FROM ticket_statuses   WHERE is_active = 1 ORDER BY is_default DESC, display_order, id LIMIT 1),
                    (SELECT id FROM ticket_priorities WHERE is_active = 1 ORDER BY is_default DESC, display_order, id LIMIT 1),
                    UTC_TIMESTAMP(), UTC_TIMESTAMP(), UTC_TIMESTAMP(),
                    ?, ?, ?, ?, ?
                )";
        // GH #218: a chat has no department of its own, so the company's default department, if any.
        require_once __DIR__ . '/../tenant_settings.php';
        [$departmentId, $departmentNote] = ticketDepartmentForNewTicket($conn, $tenantId, null);
        $conn->prepare($sql)->execute([$ticketNumber, $subject, $userId, $tenantId, $originId, $typePick['type_id'] ?? null, $departmentId]);
        $ticketId = (int) $conn->lastInsertId();
        ticketDepartmentNote($conn, $ticketId, $departmentNote);
        ticketTypeAutoRecord($conn, $ticketId, $typePick);
        // Confidential defaults (discussion #62). No department or mailbox here
        // yet, so today this does nothing - but every ticket-creating path calls
        // it, so a default added later cannot be missed on this one.
        require_once __DIR__ . '/../ticket_sensitivity.php';
        ticketSensitivityApplyDefaults($conn, $ticketId);
    } else {
        $conn->prepare("UPDATE tickets SET updated_datetime = UTC_TIMESTAMP(), last_inbound_at = UTC_TIMESTAMP() WHERE id = ?")
             ->execute([$ticketId]);
        // Messaging in on a finished ticket is the customer coming back, exactly
        // as an email or portal reply is — same shared rule, same setting.
        reopenTicketForCustomerReply($conn, (int)$ticketId);
        wakeSnoozedTicketOnCustomerReply($conn, (int)$ticketId);
    }

    // Store the message in the shared emails table (channel = 'whatsapp' etc.).
    $sql = "INSERT INTO emails (
                exchange_message_id, subject, from_address, from_name,
                to_recipients, received_datetime, body_content, body_type,
                has_attachments, is_read, processed_datetime, ticket_id,
                is_initial, direction, channel, channel_id
            ) VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP(), ?, 'text', ?, 0, UTC_TIMESTAMP(), ?, ?, 'Inbound', ?, ?)";
    $stmt = $conn->prepare($sql);
    $stmt->execute([
        $providerMsgId !== '' ? $providerMsgId : null,
        $subject,
        $from,
        $displayName,
        $replyAddress,
        $body,
        0, // has_attachments — set below once media is actually downloaded
        $ticketId,
        $isInitial,
        $channelType,
        (int) $channel['id'],
    ]);
    $dbEmailId = (int) $conn->lastInsertId();

    // Download any media and attach it (reuses the email-attachment store, so it shows
    // in the reading pane's attachment bar like any email attachment).
    if ($hasMedia) {
        $saved = 0;
        $mediaRejected = [];
        $mediaQuarantined = [];
        try {
            $provider = messagingProvider($channel);
            foreach ($mediaItems as $mediaItem) {
                try {
                    $dl = $provider->downloadMedia($mediaItem);
                    if (!empty($dl['data'])) {
                        $name = $dl['filename'] ?? 'media';
                        $outcome = saveChannelMediaAttachment(
                            $conn, $dbEmailId,
                            $name,
                            $dl['content_type'] ?? 'application/octet-stream',
                            $dl['data']
                        );
                        if ($outcome['stored']) {
                            $saved++;
                            if ($outcome['reason'] !== null) {
                                $mediaQuarantined[] = ['name' => $name, 'reason' => $outcome['reason']];
                            }
                        } else {
                            $mediaRejected[] = ['name' => $name, 'reason' => $outcome['reason']];
                        }
                    }
                } catch (Exception $e) {
                    error_log('channel media download failed (email ' . $dbEmailId . '): ' . $e->getMessage());
                }
            }
        } catch (Exception $e) {
            error_log('channel media: provider unavailable: ' . $e->getMessage());
        }

        // A file we refused is a different thing from one we could not fetch, and the
        // analyst needs to be able to tell them apart.
        $mediaNotice = attachmentIngestNotice($mediaRejected, $mediaQuarantined);

        if ($saved > 0) {
            $conn->prepare("UPDATE emails SET has_attachments = 1 WHERE id = ?")->execute([$dbEmailId]);
            if ($mediaNotice !== '') {
                $conn->prepare("UPDATE emails SET body_content = CONCAT(body_content, ?) WHERE id = ?")
                     ->execute([$mediaNotice, $dbEmailId]);
            }
        } else {
            // Nothing stored: either we refused it, or we couldn't fetch it at all.
            $note = $mediaNotice !== '' ? $mediaNotice : '[media attachment could not be downloaded]';
            $newBody = ($body === '' || $body === '[empty message]') ? $note : ($body . "\n\n" . $note);
            $conn->prepare("UPDATE emails SET body_content = ? WHERE id = ?")->execute([$newBody, $dbEmailId]);
        }
    }


    // Keep the channel's own last-inbound stamp current (diagnostics / settings).
    try {
        $conn->prepare("UPDATE messaging_channels SET last_inbound_datetime = UTC_TIMESTAMP() WHERE id = ?")
             ->execute([(int) $channel['id']]);
    } catch (Exception) { /* non-fatal */ }

    return ['status' => $isInitial ? 'created' : 'appended', 'ticket_id' => (int) $ticketId];
}

/**
 * Find the most recent OPEN (not closed, not trashed) ticket for this
 * conversation, so repeat messages thread into one ticket.
 *
 * ⚠️ What counts as "this conversation" is per-channel:
 *
 *   phone channels — the SENDER. A person has one running conversation with the
 *                    service desk, so their number identifies it.
 *   slack          — the THREAD. One person can have five unrelated threads open
 *                    at once, and matching on the sender would collapse all five
 *                    onto one ticket. $replyAddress is "C08HELP:1719500000.0001",
 *                    which is exactly the thread.
 *
 * @param string $from  the normalised sender id (phone number, chat id or platform user id)
 * @param string $channelType  the channel type, e.g. 'whatsapp', 'slack', 'telegram', 'teams', 'mattermost'
 * @param string $replyAddress  the Slack thread address "<channel>:<threadTs>"; only read for Slack, '' otherwise
 * @param ?int $channelId  the messaging_channels id; Telegram, Teams and Mattermost only match within it
 */
function findOpenChannelTicket(PDO $conn, string $from, string $channelType, string $replyAddress = '', ?int $channelId = null): ?int
{
    $byThread = ($channelType === 'slack' && $replyAddress !== '');
    // 🔴 The conversation is (channel, sender), never the sender alone - for EVERY
    // channel type. A Telegram chat id is the person's user id, the same on every
    // bot; a Mattermost user id is the same in every configuration on one server; and
    // a phone number is the same whichever company's WhatsApp number it messages.
    // Matched on the sender alone, a message to company B's channel threaded into the
    // person's open ticket from company A's - another company's ticket.
    //
    // Telegram, Mattermost and Teams were scoped one at a time; WhatsApp was left
    // matching across every channel until it was reported privately, October 2026.
    // Scoping all of them is what stops the next channel type repeating it.
    // emails.channel_id has been written since the first messaging channel shipped,
    // so open conversations keep threading.
    $byBot = ($channelId !== null);

    $sql = "SELECT t.id
            FROM tickets t
            JOIN emails e ON e.ticket_id = t.id
            WHERE e.channel = ? AND " . ($byThread ? "e.to_recipients = ?" : "e.from_address = ?") . "
              " . ($byBot ? "AND e.channel_id = ?" : "") . "
              AND t.deleted_datetime IS NULL
              AND t.closed_datetime IS NULL
            ORDER BY t.updated_datetime DESC
            LIMIT 1";
    $stmt = $conn->prepare($sql);
    $params = [$channelType, $byThread ? $replyAddress : $from];
    if ($byBot) {
        $params[] = $channelId;
    }
    $stmt->execute($params);
    $id = $stmt->fetchColumn();
    return $id ? (int) $id : null;
}

/**
 * Work out who a Slack message is from. Returns [userId|null, displayName].
 *
 * ⚠️ Halo's Slack integration requires the person's Slack email to equal their
 * Halo email, which quietly fails for guests, contractors and anyone whose Slack
 * account is a personal address. We do the lookup but never *depend* on it:
 *
 *   1. ask Slack for the profile (needs users:read + users:read.email)
 *   2. if it gives an email we already know, the ticket belongs to that person
 *   3. otherwise fall back to a channel pseudo-user — and NAME the case, so the
 *      ticket says "Sam Okafor (Slack)" or "Slack user @U08ABCDEF" rather than
 *      something anonymous that an analyst cannot act on
 *
 * Every failure path still returns a usable requester. Losing the identity must
 * never lose the ticket.
 *
 * @param array $channel  the Slack messaging_channels row, decrypted
 * @param string $slackUserId  the sender's Slack user id
 */
function resolveSlackRequester(PDO $conn, array $channel, string $slackUserId): array
{
    $name = '';
    $email = '';
    try {
        $provider = messagingProvider($channel);
        if ($provider instanceof SlackProvider) {
            $info  = $provider->lookupUser($slackUserId);
            $name  = trim((string) ($info['name'] ?? ''));
            $email = trim((string) ($info['email'] ?? ''));
        }
    } catch (Exception $e) {
        error_log('Slack requester lookup failed for ' . $slackUserId . ': ' . $e->getMessage());
    }

    $known = messagingKnownPersonByEmail($conn, $email, $name);
    if ($known !== null) {
        return $known;
    }

    // Name the case rather than leaving it blank.
    $fallbackName = 'Slack user @' . $slackUserId;
    $displayName  = $name !== '' ? ($name . ' (Slack)') : $fallbackName;
    $userId = getOrCreateChannelUser($conn, $slackUserId, $displayName, 'slack');

    // ⚠️ Let the name HEAL. getOrCreateChannelUser only sets a display name when
    // it creates the row, so somebody first seen while the lookup was failing
    // would keep "Slack user @U0A1B2C3" for ever — even after the cause is fixed.
    //
    // That is not a rare edge: Slack grants an app its scopes at install time, so
    // an app created from a manifest cannot read profiles until someone clicks
    // "Reinstall to Workspace". Tickets raised before that all name nobody, and
    // there is no obvious way for an admin to connect the two facts.
    //
    // Only ever replaces our OWN fallback string. A name an analyst has edited by
    // hand does not match that pattern, so it is never overwritten.
    if ($userId && $name !== '') {
        try {
            $upd = $conn->prepare(
                "UPDATE users SET display_name = ? WHERE id = ? AND display_name = ?"
            );
            $upd->execute([$displayName, $userId, $fallbackName]);
        } catch (Exception) { /* cosmetic — never worth failing the ticket */ }
    }

    return [$userId, $displayName];
}

/**
 * The person FreeITSM already holds with this email: [userId, displayName], or
 * null. A real person's chat tickets then sit with their emailed ones, their
 * history and their company. Shared by Slack, Teams and Mattermost.
 *
 * TRAP: only ever pass an address the PLATFORM vouches for - Slack's profile,
 *   Microsoft 365's directory, Mattermost's verified flag. The email is the
 *   whole of the identity here: an address someone merely typed would file
 *   their chats under whoever owns it. users.email is unique, so a match is
 *   one person, never a guess between two.
 *
 * @param string $email  an address the platform vouches for; '' or invalid gives null
 * @param string $name  the platform's display name, used if the user row has none
 */
function messagingKnownPersonByEmail(PDO $conn, string $email, string $name): ?array
{
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return null;
    }
    try {
        $stmt = $conn->prepare("SELECT id, display_name FROM users WHERE email = ? LIMIT 1");
        $stmt->execute([$email]);
        if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $known = trim((string) ($row['display_name'] ?? ''));
            return [(int) $row['id'], $known !== '' ? $known : ($name !== '' ? $name : $email)];
        }
    } catch (Exception) { /* the caller falls back to a contact of its own */ }
    return null;
}

/**
 * Who a Teams or Mattermost message is from. Returns [userId|null, displayName].
 *
 * Added after PR #166 was merged, on the contributor's suggestion: until then
 * every Teams and Mattermost person became a contact of their own
 * ("…@teams.local"), even when FreeITSM already held them by email, so their
 * chat tickets sat apart from everything else of theirs. Now, as on Slack:
 *
 *   1. ask the platform for the person's name and email (lookupUser())
 *   2. an email FreeITSM already holds -> the ticket is that person's
 *   3. otherwise the channel's own contact, as before - keyed on the platform
 *      id, which never changes - with the real name filled in
 *
 * Only WHO raised the ticket changes. Which ticket a message joins is still
 * decided by the platform id (findOpenChannelTicket()), because an id never
 * changes and an email can. Every failure path still returns a requester.
 *
 * @param array $channel  the Teams or Mattermost messaging_channels row, decrypted
 * @param array $msg  the normalised message (Teams reads 'to' and 'sender_id')
 * @param string $from  the normalised sender id - the key for the channel's own contact
 * @param string $displayName  the name to use when the platform gives none
 */
function resolveDirectoryRequester(PDO $conn, array $channel, array $msg, string $from, string $displayName): array
{
    $channelType = (string) ($channel['channel_type'] ?? '');
    $info = ['name' => '', 'email' => ''];
    try {
        $provider = messagingProvider($channel);
        if ($provider instanceof TeamsProvider) {
            $info = $provider->lookupUser((string) ($msg['to'] ?? ''), (string) ($msg['sender_id'] ?? ''));
        } elseif ($provider instanceof MattermostProvider) {
            $info = $provider->lookupUser($from);
        }
    } catch (Throwable $e) {
        error_log(ucfirst($channelType) . ' requester lookup failed for ' . $from . ': ' . $e->getMessage());
    }
    $name  = trim((string) ($info['name'] ?? ''));
    $email = trim((string) ($info['email'] ?? ''));

    $known = messagingKnownPersonByEmail($conn, $email, $name);
    if ($known !== null) {
        return $known;
    }

    $label  = $name !== '' ? $name : $displayName;
    $userId = getOrCreateChannelUser($conn, $from, $label, $channelType);
    // Let the name heal, as on Slack: a contact first created with only the
    // raw platform id as its name takes the real one. A name an analyst has
    // edited is not the raw id, so it is never overwritten.
    if ($userId && $name !== '') {
        try {
            $conn->prepare("UPDATE users SET display_name = ? WHERE id = ? AND display_name = ?")
                 ->execute([$name, $userId, $from]);
        } catch (Exception) { /* cosmetic */ }
    }
    return [$userId, $label];
}

/**
 * ─── Telegram identity: who is this chat? ────────────────────────────────────
 *
 * Contributed in PR #159 (turbay-a) and rebuilt at merge time. The full story,
 * with the bugs the first version had, is on the wiki:
 * Telegram-Channel-Developer-Guide.
 *
 * Telegram identifies a chat by an opaque number that says nothing about who the
 * person is (WhatsApp, by contrast, identifies them by phone number). So:
 *
 *   1. The first message from a chat gets a PLACEHOLDER requester, exactly like
 *      every other channel. The ticket is never held up.
 *   2. The bot asks, once, for the person's phone number through Telegram's own
 *      "Share phone number" button. Telegram vouches for that number (only the
 *      sender's OWN contact card is accepted - see TelegramProvider::parseInbound).
 *   3. If the number belongs to exactly one person in the right company, the
 *      chat is linked to them and the placeholder's tickets move to them.
 *
 * 🔴 THE RULES THAT MAKE THIS SAFE (each one is a bug the first version had):
 *
 *   - Only the chat's OWN PLACEHOLDER is ever merged away. Once a chat is linked
 *     to a real person, a later phone share changes NOBODY: no tickets move, no
 *     account is deleted, no phone field is overwritten. The first version
 *     treated "whoever the chat is linked to" as the placeholder, so a second
 *     share moved a real person's every ticket - email ones included - to
 *     someone else and then deleted them.
 *   - Only tickets that came in through THIS channel move. A placeholder is
 *     keyed on the chat id, which is the same on every bot, so another company's
 *     bot can share it.
 *   - Matching is scoped to the company the channel files tickets into, and only
 *     an UNAMBIGUOUS number matches. Office phone fields are often a shared
 *     switchboard, and "first match wins" handed one person's tickets to another.
 *   - The link is per CHANNEL (bot), not per chat id: company A's bot linking
 *     someone must not decide who they are on company B's bot.
 *   - What happened goes in the ticket's audit trail, never into the customer's
 *     own message text (or, as it could, an analyst's reply).
 *   - The chat's reply never says whether an account was found.
 */

/** Has Database Verification created messaging_identity_links yet? Cached per request. */
function messagingIdentityLinksReady(PDO $conn): bool
{
    static $ready = null;
    if ($ready === null) {
        try {
            $ready = (bool) $conn->query("SHOW COLUMNS FROM messaging_identity_links LIKE 'channel_id'")->fetch();
        } catch (PDOException) {
            $ready = false;
        }
    }
    return $ready;
}

/**
 * The placeholder's synthetic address - MUST match getOrCreateChannelUser().
 *
 * @param string $chatId  the Telegram chat id
 */
function messagingTelegramPlaceholderEmail(string $chatId): string
{
    return ltrim($chatId, '+') . '@telegram.local';
}

/**
 * Is this user id this chat's own placeholder (and not a real person)?
 *
 * @param int $userId  the users.id to check
 * @param string $chatId  the Telegram chat id
 */
function messagingTelegramIsPlaceholder(PDO $conn, int $userId, string $chatId): bool
{
    $st = $conn->prepare("SELECT email FROM users WHERE id = ?");
    $st->execute([$userId]);
    $email = $st->fetchColumn();
    return $email !== false && strcasecmp((string) $email, messagingTelegramPlaceholderEmail($chatId)) === 0;
}

/**
 * Resolve (never blocks) which `users` row this chat belongs to on this channel.
 *
 * Returns ['user_id'=>int, 'display_name'=>string (only when it should override
 * the caller's default), 'contact_only'=>bool (this message was a phone-number
 * share the caller should not turn into ticket text), 'is_new_placeholder'=>bool].
 *
 * @param array $channel  the Telegram messaging_channels row, decrypted
 * @param string $chatId  the Telegram chat id (the normalised sender)
 * @param array $msg  the normalised message (language_code, contact, is_test)
 * @param string $profileName  the sender's Telegram name; '' uses the chat id
 */
function messagingTelegramResolveIdentity(PDO $conn, array $channel, string $chatId, array $msg, string $profileName): array
{
    $channelId = (int) $channel['id'];
    $userId    = 0;

    // 🔴 Before Database Verification has created messaging_identity_links (an
    // upgraded install always spends a while in that state), every query below
    // fails - and the message, and its ticket, would be lost. Degrade to what
    // every other channel does: a placeholder requester, no phone matching.
    if (!messagingIdentityLinksReady($conn)) {
        $userId = getOrCreateChannelUser($conn, $chatId, $profileName !== '' ? $profileName : $chatId, 'telegram');
        return ['user_id' => (int) $userId, 'display_name' => '', 'contact_only' => false, 'is_new_placeholder' => false];
    }
    // Telegram's language_code is the person's own app setting - the one signal
    // this early for which language the bot's own text should be in.
    $locale = messagingNormaliseLocale((string) ($msg['language_code'] ?? ''));

    $find = $conn->prepare("SELECT user_id, locale FROM messaging_identity_links WHERE channel_id = ? AND external_id = ?");
    $find->execute([$channelId, $chatId]);
    $row = $find->fetch(PDO::FETCH_ASSOC);

    $isNewPlaceholder = !$row;
    if ($isNewPlaceholder) {
        $userId = getOrCreateChannelUser($conn, $chatId, $profileName !== '' ? $profileName : $chatId, 'telegram');
        if (!$userId) {
            // users table unusable - the ticket is still raised, without a requester.
            return ['user_id' => 0, 'display_name' => '', 'contact_only' => false, 'is_new_placeholder' => false];
        }
        try {
            $conn->prepare("INSERT INTO messaging_identity_links (channel_id, channel_type, external_id, user_id, phone, locale)
                            VALUES (?, 'telegram', ?, ?, NULL, ?)")
                 ->execute([$channelId, $chatId, $userId, $locale]);
        } catch (Exception $e) {
            // Two messages arriving together both found no link; the other one
            // won the unique key. Use the row it wrote, and don't ask twice.
            $find->execute([$channelId, $chatId]);
            $row = $find->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $isNewPlaceholder = false;
                $userId = (int) $row['user_id'];
            } else {
                error_log('Telegram identity: could not save link for ' . $chatId . ': ' . $e->getMessage());
            }
        }
        // Ask once, best-effort; a failed send never breaks ingest. Skipped for
        // the settings self-test, which uses a fake chat id and promises never to
        // make a real outbound call (test_channel.php).
        if ($isNewPlaceholder && empty($msg['is_test'])) {
            try {
                $provider = messagingProvider($channel);
                if ($provider instanceof TelegramProvider) {
                    $provider->requestContact(
                        $chatId,
                        I18n::tFor($locale, 'tickets.telegram_bot.request_contact_prompt'),
                        I18n::tFor($locale, 'tickets.telegram_bot.request_contact_button')
                    );
                }
            } catch (Exception $e) {
                error_log('Telegram contact request failed for chat ' . $chatId . ': ' . $e->getMessage());
            }
        }
    }
    if ($row) {
        $userId = (int) $row['user_id'];
        // People change their app language, so a message that REPORTS one updates
        // the stored value; one that doesn't (some clients omit it) keeps it.
        $reported = trim((string) ($msg['language_code'] ?? '')) !== '';
        if (!$reported && !empty($row['locale'])) {
            $locale = $row['locale'];
        } elseif (($row['locale'] ?? null) !== $locale) {
            try {
                $conn->prepare("UPDATE messaging_identity_links SET locale = ? WHERE channel_id = ? AND external_id = ?")
                     ->execute([$locale, $channelId, $chatId]);
            } catch (Exception) { /* cosmetic - never worth failing ingest over */ }
        }
    }

    $contact = is_array($msg['contact'] ?? null) ? $msg['contact'] : null;
    $phone = trim((string) ($contact['phone'] ?? ''));
    if ($phone === '') {
        return ['user_id' => (int) $userId, 'display_name' => '', 'contact_only' => false, 'is_new_placeholder' => $isNewPlaceholder];
    }

    [$resolvedId, $resolvedName] = messagingTelegramLinkPhone($conn, $channel, $chatId, (int) $userId, $phone, $locale, !empty($msg['is_test']));
    return ['user_id' => $resolvedId, 'display_name' => $resolvedName, 'contact_only' => true, 'is_new_placeholder' => false];
}

/**
 * A phone number has just arrived for this chat. Decide what it means, safely.
 *
 * @param array $channel  the Telegram messaging_channels row, decrypted
 * @param string $chatId  the Telegram chat id
 * @param int $linkedUserId  the users.id the chat is linked to now - its placeholder, or a real person
 * @param string $phone  the number Telegram vouched for, '+digits'
 * @param string $locale  the language for the bot's reply
 * @param bool $isTest  true for the settings self-test - nothing is sent to Telegram
 * @return array{0:int,1:string} [the user the chat now resolves to, display name override]
 */
function messagingTelegramLinkPhone(PDO $conn, array $channel, string $chatId, int $linkedUserId, string $phone, string $locale = 'en', bool $isTest = false): array
{
    $channelId    = (int) $channel['id'];
    $openTicketId = findOpenChannelTicket($conn, $chatId, 'telegram', '', $channelId);

    // Remember the number on the link whatever happens next - it is what the
    // person told us, and the audit entries below quote it.
    $conn->prepare("UPDATE messaging_identity_links SET phone = ? WHERE channel_id = ? AND external_id = ?")
         ->execute([$phone, $channelId, $chatId]);

    // ── Already linked to a real person: change nobody ──────────────────────
    if (!messagingTelegramIsPlaceholder($conn, $linkedUserId, $chatId)) {
        if ($openTicketId) {
            messagingTelegramAudit($conn, $openTicketId, 'Telegram phone shared', null,
                $phone . ' - this chat is already linked to ' . messagingUserLabel($conn, $linkedUserId) . '; nothing was changed.');
        }
        if (!$isTest) {
            messagingTelegramAck($channel, $chatId, I18n::tFor($locale, 'tickets.telegram_bot.ack_new'));
        }
        return [$linkedUserId, ''];
    }

    // ── Still the placeholder: look for exactly one person with this number ──
    $placeholderId = $linkedUserId;
    $tenantId   = resolveTicketTenantForChannel($conn, $channelId, $chatId);
    $candidates = messagingFindUsersByPhone($conn, $phone, $tenantId);

    if (count($candidates) === 1) {
        $matchedId = $candidates[0];
        $moved = [];
        $conn->beginTransaction();
        try {
            $conn->prepare("UPDATE messaging_identity_links SET user_id = ? WHERE channel_id = ? AND external_id = ?")
                 ->execute([$matchedId, $channelId, $chatId]);

            // Only the tickets this chat raised THROUGH THIS CHANNEL. The
            // placeholder is keyed on the chat id, which another company's bot
            // shares, so "every ticket the placeholder has" could include tickets
            // filed in a different company.
            $sel = $conn->prepare(
                "SELECT DISTINCT t.id FROM tickets t
                   JOIN emails e ON e.ticket_id = t.id
                  WHERE t.user_id = ? AND e.channel = 'telegram' AND e.channel_id = ? AND e.direction = 'Inbound'"
            );
            $sel->execute([$placeholderId, $channelId]);
            $moved = array_map('intval', $sel->fetchAll(PDO::FETCH_COLUMN));
            if ($moved) {
                $in = implode(',', array_fill(0, count($moved), '?'));
                $conn->prepare("UPDATE tickets SET user_id = ? WHERE user_id = ? AND id IN ($in)")
                     ->execute(array_merge([$matchedId, $placeholderId], $moved));
            }

            // Retire the placeholder only when nothing at all still points at it:
            // no ticket, and no link from another bot. And only if it is STILL
            // this chat's placeholder - re-checked inside the transaction.
            $left = $conn->prepare("SELECT (SELECT COUNT(*) FROM tickets WHERE user_id = ?) + (SELECT COUNT(*) FROM messaging_identity_links WHERE user_id = ?)");
            $left->execute([$placeholderId, $placeholderId]);
            if ((int) $left->fetchColumn() === 0 && messagingTelegramIsPlaceholder($conn, $placeholderId, $chatId)) {
                $conn->prepare("DELETE FROM users WHERE id = ? AND email = ?")
                     ->execute([$placeholderId, messagingTelegramPlaceholderEmail($chatId)]);
            }
            $conn->commit();
        } catch (Exception $e) {
            $conn->rollBack();
            error_log('Telegram identity: merge failed for chat ' . $chatId . ': ' . $e->getMessage());
            return [$placeholderId, ''];
        }

        $label = messagingUserLabel($conn, $matchedId);
        foreach ($moved as $tid) {
            messagingTelegramAudit($conn, $tid, 'Requester', 'Telegram chat ' . $chatId,
                $label . ' - matched by the phone number they shared (' . $phone . ')');
        }
        if (!$isTest) {
            messagingTelegramAck($channel, $chatId, I18n::tFor($locale, 'tickets.telegram_bot.ack_new'));
        }
        $nameStmt = $conn->prepare("SELECT display_name FROM users WHERE id = ?");
        $nameStmt->execute([$matchedId]);
        return [$matchedId, trim((string) ($nameStmt->fetchColumn() ?: ''))];
    }

    // ── No match, or more than one: keep the placeholder ────────────────────
    // Only the placeholder - our own record - is written to, and only an empty
    // mobile (a Telegram number is a mobile; the office `phone` is left alone).
    $conn->prepare("UPDATE users SET mobile = ? WHERE id = ? AND email = ? AND (mobile IS NULL OR mobile = '')")
         ->execute([$phone, $placeholderId, messagingTelegramPlaceholderEmail($chatId)]);
    if ($openTicketId) {
        messagingTelegramAudit($conn, $openTicketId, 'Telegram phone shared', null, count($candidates) > 1
            ? $phone . ' - ' . count($candidates) . ' people in this company have this number, so it was not matched to any of them.'
            : $phone . ' - no one in this company has this number; kept as a new contact.');
    }
    if (!$isTest) {
        messagingTelegramAck($channel, $chatId, I18n::tFor($locale, 'tickets.telegram_bot.ack_new'));
    }
    return [$placeholderId, ''];
}

/**
 * Record what happened on the ticket's audit trail (Audit tab), as automation:
 * analyst_id NULL is the established "nobody did this, the system did" marker
 * (see the ticket_audit comment in freeitsm.sql). Best-effort.
 *
 * @param string $field  the audit field name shown, e.g. 'Requester'
 * @param ?string $old  the old value; null for none (cut to 500 characters)
 * @param string $new  the new value or what happened (cut to 500 characters)
 */
function messagingTelegramAudit(PDO $conn, int $ticketId, string $field, ?string $old, string $new): void
{
    try {
        $conn->prepare("INSERT INTO ticket_audit (ticket_id, analyst_id, field_name, old_value, new_value, created_datetime)
                        VALUES (?, NULL, ?, ?, ?, UTC_TIMESTAMP())")
             ->execute([$ticketId, $field, $old !== null ? mb_substr($old, 0, 500) : null, mb_substr($new, 0, 500)]);
    } catch (Exception $e) {
        error_log('Telegram identity: audit entry failed for ticket ' . $ticketId . ': ' . $e->getMessage());
    }
}

/**
 * "Alice Real <alice@example.com>" - for audit entries.
 *
 * @param int $userId  the users.id to describe
 */
function messagingUserLabel(PDO $conn, int $userId): string
{
    $st = $conn->prepare("SELECT display_name, email FROM users WHERE id = ?");
    $st->execute([$userId]);
    $u = $st->fetch(PDO::FETCH_ASSOC);
    if (!$u) return 'user #' . $userId;
    $name = trim((string) $u['display_name']);
    return $name !== '' ? $name . ($u['email'] ? ' <' . $u['email'] . '>' : '') : (string) ($u['email'] ?: 'user #' . $userId);
}

/**
 * Record a CSAT rating that came in from a customer's chat, then thank them.
 * A rating only counts if its request was sent to THIS customer on THIS channel
 * and has not been answered yet, so a replayed or forged button press changes
 * nothing. Never creates or touches a ticket.
 *
 * @param array $channel  the messaging_channels row, decrypted
 * @param string $chatId  the normalised sender id the rating came from
 * @param string $replyAddress  where the thanks goes - see messagingReplyAddress()
 * @param array $reply  response_id, rating, button (true for a button press), and for Telegram callback_id, message_id, message_text
 */
function messagingRecordCsatReply(PDO $conn, array $channel, string $chatId, string $replyAddress, array $reply): array
{
    require_once __DIR__ . '/../csat.php';
    require_once __DIR__ . '/../i18n.php';

    // GH #217: CSAT switched off - a button press is swallowed: nothing recorded,
    // no thanks, no edit to the question; Telegram's spinner is still stopped below.
    $csatOn = moduleIsEnabled($conn, 'csat');
    $recorded = $csatOn
        && csatResponseBelongsToChat($conn, $reply['response_id'], (int) $channel['id'], $chatId)
        && csatRecordRating($conn, $reply['response_id'], $reply['rating']);

    $provider = null;
    try {
        $provider = messagingProvider($channel);
    } catch (Exception $e) {
        error_log('CSAT reply: no provider for channel ' . $channel['id'] . ': ' . $e->getMessage());
    }

    if ($provider instanceof TelegramProvider && $reply['button']) {
        // Always stop the button's spinner, even when nothing was recorded.
        $thanks = $recorded
            ? I18n::tFor(csatChannelLocale($conn, $channel, $chatId), 'tickets.csat_channel.thanks')
            : '';
        $provider->answerCallbackQuery($reply['callback_id'], $thanks);

        // Replace the buttons with the rating that STANDS - the one just
        // recorded, or, on a second press of an old question, the first one.
        // Only for this chat's own survey; an unanswered (or not ours) one
        // keeps its buttons.
        $stood = $csatOn && csatResponseBelongsToChat($conn, $reply['response_id'], (int) $channel['id'], $chatId)
            ? csatStoredRating($conn, $reply['response_id'])
            : null;
        if ($stood !== null && ($reply['message_id'] ?? '') !== '') {
            $provider->closeRatingRequest(
                $chatId,
                $reply['message_id'],
                (string) ($reply['message_text'] ?? ''),
                I18n::tFor(csatChannelLocale($conn, $channel, $chatId), 'tickets.csat_channel.rated', ['rating' => $stood])
            );
        }
    } elseif ($recorded && $provider) {
        try {
            // The reply address, not the sender: on Slack, Teams and Mattermost
            // the thanks belongs in the conversation (messagingReplyAddress()).
            $provider->sendMessage($replyAddress, I18n::tFor(csatChannelLocale($conn, $channel, $chatId), 'tickets.csat_channel.thanks'));
        } catch (Exception $e) {
            error_log('CSAT thanks failed for chat ' . $chatId . ': ' . $e->getMessage());
        }
    }

    return ['status' => $recorded ? 'csat_recorded' : 'csat_ignored', 'ticket_id' => null];
}

/**
 * Best-effort reply to the chat. A failed send must never break ingest.
 *
 * Every outcome sends the SAME neutral text: telling a stranger "found your
 * account" would let anyone holding a phone confirm who is in this install.
 *
 * @param array $channel  the Telegram messaging_channels row, decrypted; other channel types send nothing
 * @param string $chatId  the Telegram chat id
 * @param string $text  the message to send
 */
function messagingTelegramAck(array $channel, string $chatId, string $text): void
{
    try {
        $provider = messagingProvider($channel);
        if ($provider instanceof TelegramProvider) {
            $provider->sendMessage($chatId, $text);
        }
    } catch (Exception $e) {
        error_log('Telegram identity: confirmation send failed for chat ' . $chatId . ': ' . $e->getMessage());
    }
}

/**
 * Every active person whose phone or mobile is this number, compared digits-only
 * so "+44 7700 900123" and "447700900123" agree. Returns user ids, deduplicated.
 *
 * Scoped to the company the channel files tickets into ($tenantId): on a
 * multi-company install a person with no company belongs to the Default company,
 * as everywhere else (analystCanAccessUser()). A multi-company channel that
 * cannot say which company (triage, $tenantId null) matches NOBODY - guessing
 * across companies is exactly what must not happen. Single-company installs
 * search everyone.
 *
 * Placeholders (any *@telegram.local) are never candidates: merging one chat into
 * another chat's placeholder is not "finding the person".
 *
 * ⚠️ Digits must be EQUAL. "07700 900123" (national) and "447700900123"
 * (international) do not match, because turning one into the other needs the
 * country, which we don't know. A missed match costs an analyst a click; a wrong
 * one hands someone else's tickets over. Known limitation, documented on the wiki.
 *
 * A full scan rather than an indexed lookup: phone numbers are free text and
 * rarely normalised at entry, so SQL equality would miss real matches.
 *
 * @param string $phone  the number to look for, any format; under 7 digits matches nobody
 */
function messagingFindUsersByPhone(PDO $conn, string $phone, ?int $tenantId): array
{
    $needle = preg_replace('/\D+/', '', $phone);
    if ($needle === '' || strlen($needle) < 7) {
        return []; // too short to identify anyone
    }
    $sql = "SELECT id, phone, mobile FROM users
             WHERE ((phone IS NOT NULL AND phone <> '') OR (mobile IS NOT NULL AND mobile <> ''))
               AND (is_active = 1 OR is_active IS NULL)
               AND (email IS NULL OR email NOT LIKE '%@telegram.local')";
    $params = [];
    if (isMultiTenant($conn)) {
        if ($tenantId === null) {
            return [];
        }
        $sql .= " AND COALESCE(tenant_id, ?) = ?";
        $params = [getDefaultTenantId($conn), $tenantId];
    }
    $st = $conn->prepare($sql);
    $st->execute($params);
    $found = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
        foreach (['mobile', 'phone'] as $col) {
            $digits = preg_replace('/\D+/', '', (string) ($row[$col] ?? ''));
            if ($digits !== '' && $digits === $needle) {
                $found[(int) $row['id']] = true;
            }
        }
    }
    return array_keys($found);
}

/**
 * Get-or-create a placeholder requester keyed by phone, so repeat senders map to
 * one user. The users table requires an email, so we synthesise a stable
 * non-routable address ('+44…@whatsapp.local'); the real identity is the
 * display name (the WhatsApp profile name) shown on the ticket.
 *
 * @param string $from  the normalised sender id; becomes the local part of the placeholder email
 * @param string $displayName  the name for a new user; '' uses $from. An existing user's name is not changed
 * @param string $channelType  the channel type; becomes the placeholder email's domain ('<type>.local')
 */
function getOrCreateChannelUser(PDO $conn, string $from, string $displayName, string $channelType): ?int
{
    // Teams conversation ids contain : @ and . — keep the address a plain local part.
    $local = $channelType === 'teams'
        ? preg_replace('/[^A-Za-z0-9_-]/', '_', $from)
        : ltrim($from, '+');
    $pseudoEmail = $local . '@' . $channelType . '.local';
    try {
        $stmt = $conn->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
        $stmt->execute([$pseudoEmail]);
        $id = $stmt->fetchColumn();
        if ($id) {
            return (int) $id;
        }
        $ins = $conn->prepare("INSERT INTO users (email, display_name, created_at) VALUES (?, ?, UTC_TIMESTAMP())");
        $ins->execute([$pseudoEmail, $displayName !== '' ? $displayName : $from]);
        return (int) $conn->lastInsertId();
    } catch (Exception) {
        // users table shape differs / unavailable → leave the ticket requester unset.
        return null;
    }
}

/**
 * The origin id for a channel type (e.g. the seeded 'WhatsApp' origin), or null.
 *
 * @param string $channelType  the channel type, e.g. 'whatsapp' (looked up as the 'WhatsApp' origin)
 */
function getChannelOriginId(PDO $conn, string $channelType): ?int
{
    $name = $channelType === 'whatsapp' ? 'WhatsApp' : ($channelType === 'webchat' ? 'Web chat' : ucfirst($channelType));
    try {
        $stmt = $conn->prepare("SELECT id FROM ticket_origins WHERE name = ? ORDER BY (tenant_id IS NULL) DESC, id ASC LIMIT 1");
        $stmt->execute([$name]);
        $id = $stmt->fetchColumn();
        return $id ? (int) $id : null;
    } catch (Exception) {
        return null;
    }
}

/**
 * A short, human ticket subject from the first line of the first message.
 *
 * @param string $channelType  the channel type, used as the subject's label
 * @param string $displayName  the sender's name, used when the message has no text
 * @param string $body  the first message's text; its first line, cut to 80 characters, becomes the subject
 */
function buildChannelSubject(string $channelType, string $displayName, string $body): string
{
    $label = $channelType === 'whatsapp' ? 'WhatsApp' : ($channelType === 'webchat' ? 'Web chat' : ucfirst($channelType));
    $firstLine = trim(strtok($body, "\n"));
    if ($firstLine === '' || $firstLine === '[empty message]') {
        return "$label message from $displayName";
    }
    if (function_exists('mb_strimwidth')) {
        $snippet = mb_strimwidth($firstLine, 0, 80, '…');
    } else {
        $snippet = strlen($firstLine) > 80 ? substr($firstLine, 0, 79) . '…' : $firstLine;
    }
    return "$label: $snippet";
}

/**
 * The number for a ticket opened by a chat (WhatsApp, Telegram, Slack, Teams,
 * Mattermost, web chat).
 *
 * TRAP: never make a ticket number here, or anywhere else, by hand. This used
 *   to roll its own XXX-NNN-NNNNN, so on an install set to TICKET-{######}
 *   every chat ticket still came out as JNL-471-47705 (GH #71 moved three
 *   generators onto TicketNumbering and missed this one, plus catalogue
 *   approvals, merge and split). Pass the ticket's company: per-company
 *   numbering and {COMPANY} need it.
 */
function messagingGenerateTicketNumber(PDO $conn, ?int $tenantId = null, ?int $typeId = null): string
{
    return TicketNumbering::next($conn, $typeId, $tenantId);
}

/**
 * Save downloaded channel media as a ticket attachment, using the same storage
 * convention as email attachments (tickets/attachments/{floor(id/1000)}/{emailId}/…),
 * so get_ticket_attachments.php and the reading-pane attachment bar pick it up.
 */
/**
 * Stores one downloaded media file as an attachment on the channel message's email row.
 *
 * @param int $emailId  the emails row the message was stored as
 * @param string $filename  the file name the sender gave - untrusted, for display only
 * @param string $contentType  the content type the provider claimed (not stored - the bytes decide)
 * @param string $data  the file's bytes
 * @return array{stored:bool, reason:?string} — reason is set when the file was rejected or quarantined.
 */
function saveChannelMediaAttachment(PDO $conn, int $emailId, string $filename, string $contentType, string $data): array
{
    $attachmentsDir = dirname(dirname(__DIR__)) . '/tickets/attachments';
    $subDir   = floor($emailId / 1000);
    $emailDir = $attachmentsDir . '/' . $subDir . '/' . $emailId;

    // ⚠️ Media arrives from WhatsApp / web chat / Slack with a filename the SENDER
    // controls, and this wrote it into the web root after only replacing the
    // characters that make a path separator — so shell.php came through whole. The
    // report listed three ingest paths for this; this is a fourth with the same
    // shape. uploadStoreBytes() checks the extension AND the bytes, and picks the
    // name on disk itself.
    $stored = uploadStoreBytes($data, $filename, $emailDir, attachmentRejectPolicy($conn), attachmentAllowedTypes($conn));

    if (!$stored['stored']) {
        return ['stored' => false, 'reason' => $stored['reason']];   // caller notes it on the ticket
    }

    $filePath = $subDir . '/' . $emailId . '/' . $stored['stored_name'];

    // filename is the sender's, for display only. content_type is what we detected
    // from the bytes, not what the provider claimed.
    $sql = "INSERT INTO email_attachments (email_id, exchange_attachment_id, filename, content_type, content_id, file_path, file_size, is_inline)
            VALUES (?, NULL, ?, ?, NULL, ?, ?, 0)";
    $conn->prepare($sql)->execute([$emailId, $stored['original_name'], $stored['mime'], $filePath, $stored['size']]);

    return ['stored' => true, 'reason' => $stored['quarantined'] ? $stored['reason'] : null];
}
