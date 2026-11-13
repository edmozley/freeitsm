<?php
/**
 * API Endpoint: analyst sends a file (image, document, …) out over a
 * messaging channel from the ticket reading pane.
 *
 * Contributed in PR #159 (turbay-a); reworked at merge time - see the wiki page
 * Telegram-Channel-Developer-Guide, "Sending attachments".
 *
 * The file twin of send_message.php — same recipient/window/channel
 * resolution, but multipart/form-data (ticket_id + file + optional caption)
 * instead of a JSON body, because a browser cannot put a file into JSON.
 *
 * The DB row + final attachment file are created BEFORE the provider is
 * asked to send — unlike send_message.php, which sends first and records
 * after. Twilio needs a URL to fetch the bytes FROM (it never takes an
 * upload), and that URL has to point at something that already exists at its
 * final path; Meta and Telegram upload the bytes directly and would be fine
 * with the old order, but one shared order for every provider is less to get
 * wrong than a branch per provider. A failed send - or a file that fails
 * validation - rolls all of it back, so nothing lingers in the thread looking
 * like it went out.
 *
 * TelegramProvider, MetaCloudProvider and TwilioProvider implement
 * MessagingProvider::sendMedia(); SlackProvider and FreeitsmProvider (web chat)
 * don't yet — their base-class default throws "not supported for this channel
 * yet", which this endpoint surfaces as an ordinary error, not a 500.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/tenancy.php';
require_once '../../includes/messaging/messaging.php';
require_once '../../includes/uploads.php';

// Changes data: never on a GET, which a link on another site can send (csrf.php).
csrfRequireWriteMethod();

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

// Same door as send_message.php — everyday reply work.
requireModuleAccessJson('tickets');

$emailId   = 0;
$emailDir  = null;
$storedKey = null;

try {
    $ticketId = (int) ($_POST['ticket_id'] ?? 0);
    $caption  = trim((string) ($_POST['caption'] ?? ''));

    if ($ticketId <= 0) {
        throw new Exception('Ticket ID is required');
    }
    if (empty($_FILES['file'])) {
        throw new Exception('No file was uploaded');
    }

    $conn = connectToDatabase();

    // Multi-tenancy gate — never act on a ticket in a company this analyst can't access.
    if (!analystCanAccessTicket($conn, (int) $_SESSION['analyst_id'], $ticketId)) {
        throw new Exception('Ticket not found');
    }

    // The conversation: most recent inbound channel message gives us the
    // recipient and the channel row to reply from. Mirrors send_message.php.
    $stmt = $conn->prepare(
        "SELECT from_address, to_recipients, channel, channel_id
         FROM emails
         WHERE ticket_id = ? AND channel <> 'email' AND direction = 'Inbound'
         ORDER BY received_datetime DESC, id DESC
         LIMIT 1"
    );
    $stmt->execute([$ticketId]);
    $conv = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$conv) {
        throw new Exception('This ticket has no inbound channel message to reply to.');
    }

    $channelType = $conv['channel'];
    $recipient = $conv['from_address'];
    if ($channelType === 'slack') {
        $recipient = trim((string) ($conv['to_recipients'] ?? ''));
        if ($recipient === '') {
            throw new Exception('This ticket has no Slack thread to reply to.');
        }
    }
    $channel = loadMessagingChannel($conn, (int) $conv['channel_id']);
    if (!$channel) {
        throw new Exception('The channel this ticket arrived on no longer exists.');
    }
    if (empty($channel['is_active'])) {
        throw new Exception('The channel this ticket arrived on is inactive.');
    }

    // Same reply-window rule as a text reply, from the same one place.
    if (channelHasServiceWindow($channelType)) {
        $win = $conn->prepare("SELECT last_inbound_at FROM tickets WHERE id = ?");
        $win->execute([$ticketId]);
        if (!channelWindowOpen($win->fetchColumn() ?: null)) {
            throw new Exception('The 24-hour reply window has closed. A pre-approved template message is required to reopen the conversation.');
        }
    }

    // Record the message first (see the file header for why), so the file can
    // be stored straight into its final folder, which is keyed on this row's id.
    $ins = $conn->prepare(
        "INSERT INTO emails (
            exchange_message_id, subject, from_address, from_name, to_recipients,
            received_datetime, body_content, body_type, has_attachments, ticket_id,
            is_initial, direction, channel, channel_id
        ) VALUES (NULL, NULL, ?, ?, ?, UTC_TIMESTAMP(), ?, 'text', 1, ?, 0, 'Outbound', ?, ?)"
    );
    $ins->execute([
        (string) ($channel['phone_number'] ?? ''),
        $channel['name'] ?? 'Service Desk',
        $recipient,
        $caption, // shown as the message body in the thread, same as a normal reply's text
        $ticketId,
        $channelType,
        (int) $channel['id'],
    ]);
    $emailId = (int) $conn->lastInsertId();

    // Validate AND store in one step, straight into the same folder inbound
    // channel media uses (tickets/attachments/{floor(id/1000)}/{emailId}/…), so
    // the reading pane's attachment bar shows it with no special case.
    // uploadStoreFile() applies the two gates every ticket attachment gets (an
    // extension we chose, content that matches it), names the file itself, and
    // prepares the folder (including its no-execute guard). It is about to go to
    // a third party, so it gets no more trust than an inbound file would.
    //
    // ⚠️ PR #159 stored into sys_get_temp_dir() and renamed afterwards. That made
    // uploadStoreFile() drop its .htaccess / web.config guard into the OS temp
    // folder, and created the final folder by hand with mkdir(), skipping the guard.
    // Into the tickets store (a server folder or S3, System -> Storage), same layout.
    require_once dirname(__DIR__, 2) . '/includes/storage/stores.php';
    $stored    = storeSaveUpload($conn, 'tickets', storeTicketDir($emailId), $_FILES['file'], attachmentAllowedTypes($conn));
    $storedKey = $stored['key'];

    $filePath = $storedKey;
    $conn->prepare(
        "INSERT INTO email_attachments (email_id, exchange_attachment_id, filename, content_type, content_id, file_path, file_size, is_inline)
         VALUES (?, NULL, ?, ?, NULL, ?, ?, 0)"
    )->execute([$emailId, $stored['original_name'], $stored['mime'], $filePath, $stored['size']]);
    $attachmentId = (int) $conn->lastInsertId();

    // Now actually send it: the local path for a provider that uploads bytes
    // (Meta, Telegram), a short-lived signed URL to the same file for one that
    // fetches it (Twilio), and the ORIGINAL name for the recipient to see.
    // A provider that uploads bytes needs a file on this server: the stored one, or a
    // temporary copy when the store is in S3 (removed once sent).
    $provider = messagingProvider($channel);
    $local = storeLocalPath($conn, 'tickets', $storedKey);
    try {
        $providerMsgId = $provider->sendMedia(
            $recipient,
            $local['path'],
            $stored['mime'],
            $caption,
            messagingOutboundMediaUrl($conn, $attachmentId),
            $stored['original_name']
        );
    } finally {
        storeReleaseLocal($local);
    }

    if ($providerMsgId !== '') {
        $conn->prepare("UPDATE emails SET exchange_message_id = ? WHERE id = ?")->execute([$providerMsgId, $emailId]);
    }
    $conn->prepare("UPDATE tickets SET updated_datetime = UTC_TIMESTAMP() WHERE id = ?")->execute([$ticketId]);

    $emailId = 0; // sent - nothing to roll back
    echo json_encode(['success' => true, 'message' => 'Attachment sent']);

} catch (Exception $e) {
    // Anything that failed after the message row existed - the file refused,
    // the provider refused - takes the row, its attachment row and the file
    // back out. A failed attachment must never sit in the thread looking sent.
    if ($emailId > 0 && isset($conn)) {
        try {
            $conn->prepare("DELETE FROM email_attachments WHERE email_id = ?")->execute([$emailId]);
            $conn->prepare("DELETE FROM emails WHERE id = ?")->execute([$emailId]);
        } catch (Exception $ignored) { /* best effort */ }
        if ($storedKey !== null) {
            storeDelete($conn, 'tickets', $storedKey);
        }
    }
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
