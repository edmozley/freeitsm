<?php
/**
 * API Endpoint: analyst reply on a messaging channel (WhatsApp etc.).
 *
 * The channel twin of api/tickets/send_email.php. Sends the analyst's text out via
 * the ticket's channel provider, then stores the outbound message in the shared
 * `emails` table so it appears in the reading-pane thread next to the inbound ones.
 *
 * Refuses to send outside the provider 24h service window (free-text is blocked
 * there — only template messages are allowed, which is Phase 3).
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/tenancy.php';
require_once '../../includes/messaging/messaging.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

// Replying to a WhatsApp message from the inbox — everyday work.
requireModuleAccessJson('tickets');

try {
    $input = json_decode(file_get_contents('php://input'), true);
    $ticketId = (int) ($input['ticket_id'] ?? 0);
    $body = trim((string) ($input['body'] ?? ''));

    if ($ticketId <= 0) {
        throw new Exception('Ticket ID is required');
    }
    if ($body === '') {
        throw new Exception('Message text is required');
    }

    $conn = connectToDatabase();

    // Multi-tenancy gate — never act on a ticket in a company this analyst can't access.
    if (!analystCanAccessTicket($conn, (int) $_SESSION['analyst_id'], $ticketId)) {
        throw new Exception('Ticket not found');
    }

    // The conversation: most recent inbound channel message gives us the recipient
    // phone (from_address) and the channel row to reply from (channel_id).
    $stmt = $conn->prepare(
        "SELECT from_address, channel, channel_id
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

    $recipient   = $conv['from_address'];
    $channelType = $conv['channel'];
    $channel = loadMessagingChannel($conn, (int) $conv['channel_id']);
    if (!$channel) {
        throw new Exception('The channel this ticket arrived on no longer exists.');
    }
    if (empty($channel['is_active'])) {
        throw new Exception('The channel this ticket arrived on is inactive.');
    }

    // 24h service window — free-text replies are only allowed inside it.
    $win = $conn->prepare("SELECT last_inbound_at FROM tickets WHERE id = ?");
    $win->execute([$ticketId]);
    if (!channelWindowOpen($win->fetchColumn() ?: null)) {
        throw new Exception('The 24-hour reply window has closed. A pre-approved template message is required to reopen the conversation (coming in a later release).');
    }

    // Send via the provider.
    $provider = messagingProvider($channel);
    $providerMsgId = $provider->sendMessage($recipient, $body);

    // Store the outbound message in the shared thread.
    $ins = $conn->prepare(
        "INSERT INTO emails (
            exchange_message_id, subject, from_address, from_name, to_recipients,
            received_datetime, body_content, body_type, ticket_id, is_initial,
            direction, channel, channel_id
        ) VALUES (?, NULL, ?, ?, ?, UTC_TIMESTAMP(), ?, 'text', ?, 0, 'Outbound', ?, ?)"
    );
    $ins->execute([
        $providerMsgId !== '' ? $providerMsgId : null,
        (string) ($channel['phone_number'] ?? ''),
        $channel['name'] ?? 'Service Desk',
        $recipient,
        $body,
        $ticketId,
        $channelType,
        (int) $channel['id'],
    ]);

    $conn->prepare("UPDATE tickets SET updated_datetime = UTC_TIMESTAMP() WHERE id = ?")->execute([$ticketId]);

    echo json_encode(['success' => true, 'message' => 'Message sent']);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
