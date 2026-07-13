<?php
/**
 * API Endpoint: AI summary of a messaging-channel conversation (WhatsApp etc.).
 *
 * Summarises the conversation into a few sentences and saves it as an internal
 * note on the ticket, so the thread is digestible at a glance. Reuses the Tickets
 * AI key (ns = tickets_reply_cleanup).
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

// AI summary in the inbox — everyday work, but it spends the AI budget.
requireModuleAccessJson('tickets');

try {
    $input = json_decode(file_get_contents('php://input'), true);
    $ticketId = (int) ($input['ticket_id'] ?? 0);
    if ($ticketId <= 0) {
        throw new Exception('Ticket ID is required');
    }

    $conn = connectToDatabase();
    $analystId = (int) $_SESSION['analyst_id'];
    if (!analystCanAccessTicket($conn, $analystId, $ticketId)) {
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

    $system = "You are an IT service desk analyst. Summarise the following WhatsApp conversation "
        . "in 2-4 short sentences: what the customer needs, what's been done, and any open action. "
        . "Plain text only — no markdown.";

    $resp = aiProviderChat($cfg, [
        'system'     => $system,
        'user'       => $transcript,
        'max_tokens' => 400,
    ]);
    $summary = trim((string) ($resp['content'] ?? ''));
    if ($summary === '') {
        throw new Exception('The AI returned an empty summary.');
    }

    // Save as an internal note so it lives with the ticket.
    $note = "🤖 WhatsApp conversation summary:\n\n" . $summary;
    $conn->prepare("INSERT INTO ticket_notes (ticket_id, analyst_id, note_text, is_internal, created_datetime) VALUES (?, ?, ?, 1, UTC_TIMESTAMP())")
         ->execute([$ticketId, $analystId, $note]);

    echo json_encode(['success' => true, 'summary' => $summary]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
