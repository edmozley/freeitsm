<?php
/**
 * API Endpoint: AI-suggested reply for a messaging-channel ticket (WhatsApp etc.).
 *
 * Reads the conversation and drafts a short, friendly reply for the analyst to
 * review/edit before sending. Does NOT send anything. Reuses the Tickets AI key
 * (ns = tickets_reply_cleanup) so it shares that feature's provider + billing line.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/tenancy.php';
require_once '../../includes/ai_settings.php';
require_once '../../includes/messaging/messaging.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

try {
    $input = json_decode(file_get_contents('php://input'), true);
    $ticketId = (int) ($input['ticket_id'] ?? 0);
    if ($ticketId <= 0) {
        throw new Exception('Ticket ID is required');
    }

    $conn = connectToDatabase();
    if (!analystCanAccessTicket($conn, (int) $_SESSION['analyst_id'], $ticketId)) {
        throw new Exception('Ticket not found');
    }

    $cfg = aiSettingsLoad($conn, 'tickets_reply_cleanup');
    if (($cfg['api_key'] ?? '') === '') {
        throw new Exception('AI is not configured. Set up the provider and key in Tickets → Settings → Reply Cleanup.');
    }

    $transcript = messagingBuildTranscript($conn, $ticketId);
    if ($transcript === '') {
        throw new Exception('No conversation to summarise yet.');
    }

    $system = "You are an IT service desk analyst replying to a customer over WhatsApp. "
        . "Write a concise, friendly, professional reply to the customer's most recent message. "
        . "Use 1-3 short sentences suitable for a chat app. Plain text only — no markdown, no signature, no subject line.";

    $resp = aiProviderChat($cfg, [
        'system'     => $system,
        'user'       => "Conversation so far (oldest first):\n\n" . $transcript . "\n\nDraft the analyst's next reply:",
        'max_tokens' => 400,
    ]);

    echo json_encode(['success' => true, 'reply' => trim((string) ($resp['content'] ?? ''))]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
