<?php
/**
 * Messaging channels — shared entry point.
 *
 * Require this one file from any endpoint that deals with WhatsApp/chat channels;
 * it pulls in the provider classes and exposes the helpers below:
 *
 *   messagingProvider($channel)        → a MessagingProvider for a (decrypted) row
 *   loadMessagingChannel($conn, $id)   → a channel row with credentials decrypted
 *   normaliseChannelIdentifier($raw)   → a phone number as '+<digits>'
 *   channelWindowOpen($lastInboundAt)  → is the 24h service window still open?
 *
 * The credentials column is an encrypted JSON blob; loadMessagingChannel decrypts
 * it and decodes it into $channel['credentials'] (an array). Providers read their
 * own keys out of that array (shapes documented on each provider class).
 */

require_once __DIR__ . '/MessagingProvider.php';
require_once __DIR__ . '/TwilioProvider.php';
require_once __DIR__ . '/MetaCloudProvider.php';
require_once __DIR__ . '/../encryption.php';

/** The 24h provider service window, in seconds. */
if (!defined('MESSAGING_WINDOW_SECONDS')) {
    define('MESSAGING_WINDOW_SECONDS', 24 * 60 * 60);
}

/**
 * Build the right provider for a channel row (credentials already decrypted into
 * an array by loadMessagingChannel). Throws on an unknown provider.
 */
function messagingProvider(array $channel): MessagingProvider
{
    switch ($channel['provider'] ?? 'twilio') {
        case 'twilio':
            return new TwilioProvider($channel);
        case 'meta':
            return new MetaCloudProvider($channel);
        default:
            throw new Exception('Unknown messaging provider: ' . ($channel['provider'] ?? '?'));
    }
}

/**
 * Load a messaging_channels row with its credentials decrypted + JSON-decoded.
 * Returns null if not found. Safe to call before db_verify has run the table
 * (returns null rather than throwing).
 */
function loadMessagingChannel(PDO $conn, $channelId): ?array
{
    try {
        $stmt = $conn->prepare("SELECT * FROM messaging_channels WHERE id = ?");
        $stmt->execute([(int) $channelId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return null;
    }
    if (!$row) {
        return null;
    }
    $row['credentials'] = messagingDecodeCredentials($row['credentials'] ?? null);
    // verify_token / relay_secret are secrets, stored encrypted at rest. decryptValue
    // returns the value unchanged if it lacks the ENC: prefix, so pre-encryption or
    // empty rows still work (migration-safe).
    foreach (['verify_token', 'relay_secret'] as $secretCol) {
        if (isset($row[$secretCol]) && $row[$secretCol] !== null && $row[$secretCol] !== '') {
            try { $row[$secretCol] = decryptValue($row[$secretCol]); } catch (Exception $e) { /* leave as-is */ }
        }
    }
    $row['is_active'] = (bool) ($row['is_active'] ?? 1);
    return $row;
}

/** Decrypt + JSON-decode a stored credentials blob into an array (never throws). */
function messagingDecodeCredentials($stored): array
{
    if ($stored === null || $stored === '') {
        return [];
    }
    try {
        $plain = decryptValue($stored);
    } catch (Exception $e) {
        return [];
    }
    $decoded = json_decode((string) $plain, true);
    return is_array($decoded) ? $decoded : [];
}

/**
 * Normalise a phone identifier to '+<digits>' for storage and matching. Strips a
 * "whatsapp:" prefix, spaces, dashes and brackets. Returns '' if nothing usable.
 */
function normaliseChannelIdentifier(string $raw): string
{
    $s = trim($raw);
    if (stripos($s, 'whatsapp:') === 0) {
        $s = substr($s, strlen('whatsapp:'));
    }
    $hasPlus = strpos(ltrim($s), '+') === 0;
    $digits = preg_replace('/\D+/', '', $s);
    if ($digits === '') {
        return '';
    }
    return ($hasPlus ? '+' : '+') . $digits;
}

/**
 * Build a plain-text transcript of a channel conversation (oldest first), capped
 * to keep token usage predictable. Used by the AI summary / suggested-reply
 * endpoints. Non-channel (email) rows are excluded.
 */
function messagingBuildTranscript(PDO $conn, int $ticketId): string
{
    $stmt = $conn->prepare(
        "SELECT direction, from_name, from_address, body_content
         FROM emails
         WHERE ticket_id = ? AND channel <> 'email'
         ORDER BY received_datetime ASC, id ASC
         LIMIT 50"
    );
    $stmt->execute([$ticketId]);
    $lines = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $who = ($row['direction'] === 'Outbound')
            ? 'Analyst'
            : ('Customer (' . ($row['from_name'] ?: $row['from_address']) . ')');
        $text = trim(strip_tags((string) $row['body_content']));
        if ($text !== '') {
            $lines[] = "$who: $text";
        }
    }
    return implode("\n", $lines);
}

/**
 * Is the 24h service window still open, given the last inbound timestamp
 * (a 'Y-m-d H:i:s' UTC string, or null)? Outside the window, free-text replies
 * are blocked by the provider and only template messages are allowed.
 */
function channelWindowOpen(?string $lastInboundAt): bool
{
    if (!$lastInboundAt) {
        return false;
    }
    $ts = strtotime($lastInboundAt . ' UTC');
    if ($ts === false) {
        return false;
    }
    return (time() - $ts) < MESSAGING_WINDOW_SECONDS;
}
