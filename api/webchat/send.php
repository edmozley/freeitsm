<?php
/**
 * PUBLIC endpoint: a visitor sends a message.
 *
 * Two shapes, chosen by the widget's config:
 *
 *  • Plain widget (ai_enabled = 0) — the message is ingested straight onto the ticket
 *    membrane (creating the ticket on the first message), exactly like an email or
 *    WhatsApp message. When outside business hours it still becomes a ticket, and the
 *    offline message is returned so the visitor knows it'll be answered later.
 *
 *  • AI widget (ai_enabled = 1) — webchat_messages is the visitor-facing transcript.
 *    The message is recorded there, and (in assist mode, or once escalated, or when
 *    closed) also ingested onto a ticket so an analyst has the full picture. While open
 *    and not yet handed to a human, the KB-grounded AI drafts a reply.
 *
 * Needs the conversation token.
 */
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/webchat/public.php';

webchatHandlePreflight();

$conn = connectToDatabase();
$data = json_decode(file_get_contents('php://input'), true) ?: [];

$key     = trim((string) ($data['key'] ?? ''));
$token   = trim((string) ($data['token'] ?? ''));
$message = trim((string) ($data['message'] ?? ''));

$widget = webchatPublicResolve($conn, $key);

if ($message === '') {
    webchatFail('Message is empty');
}
$message = mb_substr($message, 0, 5000);

$conv = webchatLoadConversation($conn, $token, (int) $widget['channel_id']);
if (!$conv) {
    webchatFail('Chat session not found — please refresh and start again', 404);
}

webchatRateLimitSend($conn, (int) ($conv['ticket_id'] ?? 0));

// The messaging_channels row (id / name / tenant_id) is what the ingest helper needs.
$channel = loadMessagingChannel($conn, (int) $widget['channel_id']);
if (!$channel) {
    webchatFail('This chat widget is no longer available', 410);
}

$aiEnabled = !empty($widget['ai_enabled']);
$mode      = (($widget['ai_mode'] ?? 'assist') === 'deflect') ? 'deflect' : 'assist';
$calId     = ($widget['business_calendar_id'] ?? null) !== null ? (int) $widget['business_calendar_id'] : null;
$isOpen    = webchatIsOpenNow($conn, $calId);
$hasTicket = !empty($conv['ticket_id']);
$offline   = webchatOfflineMessage($widget); // custom message, or a sensible default

$resp = ['success' => true];

if (!$aiEnabled) {
    // ---- Plain widget: every message is a ticket message. --------------------
    $emailId = null;
    $resp['ticket_id'] = webchatIngestMessage($conn, $conv, $channel, $message, $emailId);
    $resp['msg_id']    = $emailId; // emails.id — the plain-widget poll cursor
    if (!$isOpen && $offline !== '') {
        $resp['notice'] = $offline;
    }
    echo json_encode($resp);
    exit;
}

// ---- AI widget: webchat_messages is the transcript the visitor sees. ---------
require_once '../../includes/webchat/ai.php';

// Conversation history BEFORE recording this turn (so the AI prompt isn't handed the
// latest message twice — webchatAiReply appends it itself).
$history = [];
foreach (webchatGetMessages($conn, (int) $conv['id']) as $m) {
    $history[] = [
        'sender' => $m['sender'] === 'visitor' ? 'visitor' : 'agent',
        'body'   => (string) $m['body'],
    ];
}

// The webchat_messages id of the visitor's message is the AI-widget poll cursor — the
// widget advances past it so its optimistic echo isn't re-fetched as a duplicate.
$resp['msg_id'] = webchatAddMessage($conn, (int) $conv['id'], 'visitor', $message);

// Does this message also land on a ticket now? assist always; deflect only once the
// visitor has escalated (hasTicket) or when we're closed (capture to answer later).
if ($mode === 'assist' || !$isOpen || $hasTicket) {
    $ticketId = webchatIngestMessage($conn, $conv, $channel, $message);
    $conv['ticket_id'] = $ticketId;
    $resp['ticket_id'] = $ticketId;
}

// The AI answers only while open and while the conversation is still bot-handled — never
// after a hand-off to a human (deflect + already ticketed), and never out of hours.
$aiShouldAnswer = $isOpen && ($mode === 'assist' || !$hasTicket);

if ($aiShouldAnswer) {
    // Scope the knowledge search to the widget's company — the same tenant_id that
    // already routes the ticket (see webchatIngestMessage). Otherwise this client's
    // visitor can be answered out of another client's knowledge base.
    $aiTenantId = ($channel['tenant_id'] ?? null) !== null ? (int) $channel['tenant_id'] : null;
    $r = webchatAiReply($conn, $message, $history, $aiTenantId);
    if ($r['ok'] && $r['answer'] !== '') {
        if (!empty($conv['ticket_id'])) {
            // Assist mode: post the bot's reply onto the ticket too, so the analyst sees
            // what the visitor was told. Linked by source_email_id so poll's mirror step
            // doesn't echo it back into the transcript a second time.
            $botName = ($widget['name'] ?? '') !== '' ? $widget['name'] . ' (AI)' : 'Assistant (AI)';
            $srcId   = webchatInsertOutbound($conn, (int) $conv['ticket_id'], $channel, $r['answer'], $botName);
            webchatAddMessage($conn, (int) $conv['id'], 'ai', $r['answer'], $srcId);
        } else {
            webchatAddMessage($conn, (int) $conv['id'], 'ai', $r['answer']);
        }
    }
} elseif (!$isOpen && $offline !== '') {
    // Closed: no live answer — acknowledge with the offline message.
    $resp['notice'] = $offline;
}

echo json_encode($resp);
