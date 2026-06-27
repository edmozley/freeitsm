<?php
/**
 * API Endpoint: send a pre-approved template message on a channel ticket.
 *
 * This is how an analyst re-opens a conversation after the 24-hour window has
 * closed, so (unlike send_message.php) it deliberately does NOT require the window
 * to be open. The customer's reply to the template reopens the free-text window.
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

try {
    $input = json_decode(file_get_contents('php://input'), true);
    $ticketId   = (int) ($input['ticket_id'] ?? 0);
    $templateId = (int) ($input['template_id'] ?? 0);
    $vars       = is_array($input['vars'] ?? null) ? array_values($input['vars']) : [];

    if ($ticketId <= 0)   throw new Exception('Ticket ID is required');
    if ($templateId <= 0) throw new Exception('Template is required');

    $conn = connectToDatabase();
    if (!analystCanAccessTicket($conn, (int) $_SESSION['analyst_id'], $ticketId)) {
        throw new Exception('Ticket not found');
    }

    // Recipient + channel from the most recent inbound channel message.
    $stmt = $conn->prepare(
        "SELECT from_address, channel_id
         FROM emails
         WHERE ticket_id = ? AND channel <> 'email' AND direction = 'Inbound'
         ORDER BY received_datetime DESC, id DESC LIMIT 1"
    );
    $stmt->execute([$ticketId]);
    $conv = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$conv) {
        throw new Exception('This ticket has no inbound channel message to reply to.');
    }

    $channel = loadMessagingChannel($conn, (int) $conv['channel_id']);
    if (!$channel || empty($channel['is_active'])) {
        throw new Exception('The channel this ticket arrived on is unavailable.');
    }

    $tStmt = $conn->prepare("SELECT * FROM messaging_templates WHERE id = ? AND is_active = 1");
    $tStmt->execute([$templateId]);
    $template = $tStmt->fetch(PDO::FETCH_ASSOC);
    if (!$template) {
        throw new Exception('Template not found or inactive.');
    }
    if (($template['provider'] ?? '') !== ($channel['provider'] ?? '')) {
        throw new Exception('That template is for a different provider than this channel.');
    }

    // Validate variable count.
    $needed = messagingTemplateVarCount((string) $template['body']);
    if (count($vars) < $needed) {
        throw new Exception("This template needs $needed value(s); " . count($vars) . ' supplied.');
    }

    $provider = messagingProvider($channel);
    $providerMsgId = $provider->sendTemplate($conv['from_address'], $template, $vars);

    // Store what was actually sent (placeholders filled) in the thread.
    $rendered = messagingRenderTemplate((string) $template['body'], $vars);
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
        $conv['from_address'],
        '[Template: ' . $template['name'] . "]\n" . $rendered,
        $ticketId,
        $channel['channel_type'] ?? 'whatsapp',
        (int) $channel['id'],
    ]);

    $conn->prepare("UPDATE tickets SET updated_datetime = UTC_TIMESTAMP() WHERE id = ?")->execute([$ticketId]);

    echo json_encode(['success' => true, 'message' => 'Template sent']);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
