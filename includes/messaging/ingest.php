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

/**
 * Ingest one normalised inbound message for a (decrypted) channel row.
 * Returns ['status' => 'created'|'appended'|'duplicate', 'ticket_id' => int|null].
 */
function ingestInboundMessage(PDO $conn, array $channel, array $msg): array
{
    $channelType = $channel['channel_type'] ?? 'whatsapp';
    $from = normaliseChannelIdentifier((string) ($msg['from'] ?? ''));
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

    // Body. Phase 1 records the presence of media (download is Phase 3).
    $body = trim((string) ($msg['body'] ?? ''));
    $hasMedia = !empty($msg['media']);
    if ($hasMedia) {
        $note = '[' . count($msg['media']) . ' media attachment(s) received — inline media support is coming in a later release]';
        $body = $body === '' ? $note : ($body . "\n\n" . $note);
    }
    if ($body === '') {
        $body = '[empty message]';
    }

    $displayName = $profileName !== '' ? $profileName : $from;
    $userId = getOrCreateChannelUser($conn, $from, $displayName, $channelType);

    // Thread into an open conversation for this sender, else open a new ticket.
    $ticketId  = findOpenChannelTicket($conn, $from, $channelType);
    $isInitial = $ticketId ? 0 : 1;
    $subject   = null;

    if (!$ticketId) {
        $ticketNumber = messagingGenerateTicketNumber($conn);
        $tenantId     = resolveTicketTenantForChannel($conn, $channel['id'], $from);
        $originId     = getChannelOriginId($conn, $channelType);
        $subject      = buildChannelSubject($channelType, $displayName, $body);

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
    } else {
        $conn->prepare("UPDATE tickets SET updated_datetime = UTC_TIMESTAMP(), last_inbound_at = UTC_TIMESTAMP() WHERE id = ?")
             ->execute([$ticketId]);
    }

    // Store the message in the shared emails table (channel = 'whatsapp' etc.).
    $sql = "INSERT INTO emails (
                exchange_message_id, subject, from_address, from_name,
                to_recipients, received_datetime, body_content, body_type,
                has_attachments, is_read, processed_datetime, ticket_id,
                is_initial, direction, channel, channel_id
            ) VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP(), ?, 'text', ?, 0, UTC_TIMESTAMP(), ?, ?, 'Inbound', ?, ?)";
    $conn->prepare($sql)->execute([
        $providerMsgId !== '' ? $providerMsgId : null,
        $subject,
        $from,
        $displayName,
        (string) ($channel['phone_number'] ?? ''),
        $body,
        $hasMedia ? 1 : 0,
        $ticketId,
        $isInitial,
        $channelType,
        (int) $channel['id'],
    ]);

    // Keep the channel's own last-inbound stamp current (diagnostics / settings).
    try {
        $conn->prepare("UPDATE messaging_channels SET last_inbound_datetime = UTC_TIMESTAMP() WHERE id = ?")
             ->execute([(int) $channel['id']]);
    } catch (Exception $e) { /* non-fatal */ }

    return ['status' => $isInitial ? 'created' : 'appended', 'ticket_id' => (int) $ticketId];
}

/**
 * Find the most recent OPEN (not closed, not trashed) ticket whose conversation
 * is this sender on this channel — so repeat messages thread into one ticket.
 */
function findOpenChannelTicket(PDO $conn, string $from, string $channelType): ?int
{
    $sql = "SELECT t.id
            FROM tickets t
            JOIN emails e ON e.ticket_id = t.id
            WHERE e.channel = ? AND e.from_address = ?
              AND t.deleted_datetime IS NULL
              AND t.closed_datetime IS NULL
            ORDER BY t.updated_datetime DESC
            LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$channelType, $from]);
    $id = $stmt->fetchColumn();
    return $id ? (int) $id : null;
}

/**
 * Get-or-create a placeholder requester keyed by phone, so repeat senders map to
 * one user. The users table requires an email, so we synthesise a stable
 * non-routable address ('+44…@whatsapp.local'); the real identity is the
 * display name (the WhatsApp profile name) shown on the ticket.
 */
function getOrCreateChannelUser(PDO $conn, string $from, string $displayName, string $channelType): ?int
{
    $pseudoEmail = ltrim($from, '+') . '@' . $channelType . '.local';
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
    } catch (Exception $e) {
        // users table shape differs / unavailable → leave the ticket requester unset.
        return null;
    }
}

/** The origin id for a channel type (e.g. the seeded 'WhatsApp' origin), or null. */
function getChannelOriginId(PDO $conn, string $channelType): ?int
{
    $name = $channelType === 'whatsapp' ? 'WhatsApp' : ucfirst($channelType);
    try {
        $stmt = $conn->prepare("SELECT id FROM ticket_origins WHERE name = ? ORDER BY (tenant_id IS NULL) DESC, id ASC LIMIT 1");
        $stmt->execute([$name]);
        $id = $stmt->fetchColumn();
        return $id ? (int) $id : null;
    } catch (Exception $e) {
        return null;
    }
}

/** A short, human ticket subject from the first line of the first message. */
function buildChannelSubject(string $channelType, string $displayName, string $body): string
{
    $label = $channelType === 'whatsapp' ? 'WhatsApp' : ucfirst($channelType);
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

/** Unique ticket number in the existing XXX-NNN-NNNNN format. */
function messagingGenerateTicketNumber(PDO $conn): string
{
    for ($attempt = 0; $attempt < 10; $attempt++) {
        $letters = chr(rand(65, 90)) . chr(rand(65, 90)) . chr(rand(65, 90));
        $n1 = rand(0, 9) . rand(0, 9) . rand(0, 9);
        $n2 = rand(0, 9) . rand(0, 9) . rand(0, 9) . rand(0, 9) . rand(0, 9);
        $ticketNumber = "$letters-$n1-$n2";
        $check = $conn->prepare("SELECT COUNT(*) FROM tickets WHERE ticket_number = ?");
        $check->execute([$ticketNumber]);
        if (!$check->fetchColumn()) {
            return $ticketNumber;
        }
    }
    throw new Exception('Failed to generate unique ticket number');
}
