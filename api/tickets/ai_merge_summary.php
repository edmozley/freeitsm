<?php
/**
 * API: Tickets — AI merge summary (streaming).
 *
 * POST { ticket_id: int }  — the MERGED ticket, after the merge has happened.
 * Streams Server-Sent Events as the model reads the combined conversation and
 * writes two short sections: what the requesters asked for, and what has already
 * been asked or done by the service desk.
 *
 * WHY THIS EXISTS
 * ---------------
 * Merging four tickets about one outage produces one ticket with four interleaved
 * conversations. The next analyst to open it has to read all of them to learn what
 * is essentially two facts: what people want, and what has been tried. That is the
 * gap this fills — it is a reading aid, not a record.
 *
 * WHICH IS WHY IT IS WRITTEN AS A NOTE, NOT AS A MESSAGE
 * -----------------------------------------------------
 * The summary is a machine's opinion about a conversation. It goes in as an internal
 * note (never shared with the requester), it says on its face that AI wrote it, and
 * an analyst can edit or delete it like any other note. It must never be mistaken
 * for something a person asserted, and it must never be sent to a customer.
 *
 * The caller streams for the live typing effect and then POSTs the finished text to
 * save_merge_summary.php. Deliberately two steps: if the analyst closes the tab
 * halfway through, a half-written summary simply never gets saved.
 *
 * Reuses the reply-cleanup AI settings (ns=tickets_reply_cleanup) rather than adding
 * a fourth provider block to configure — one AI key for the Tickets module.
 */

session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/encryption.php';
require_once '../../includes/rbac.php';
require_once '../../includes/tenancy.php';
require_once '../../includes/rfp_ai.php';
require_once '../../includes/ai_settings.php';

@ini_set('zlib.output_compression', '0');
@ini_set('output_buffering', '0');
@ini_set('implicit_flush', '1');
while (ob_get_level() > 0) ob_end_flush();
ob_implicit_flush(true);

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache, no-transform');
header('X-Accel-Buffering: no');
header('Connection: keep-alive');
set_time_limit(0);

function sse_send(string $event, array $data): void
{
    echo "event: {$event}\n";
    echo 'data: ' . json_encode($data, JSON_UNESCAPED_SLASHES) . "\n\n";
    @flush();
}

if (!isset($_SESSION['analyst_id'])) {
    sse_send('error', ['message' => 'Not authenticated']);
    exit;
}
requireModuleAccessJson('tickets');

$input    = json_decode(file_get_contents('php://input'), true);
$ticketId = (int)($input['ticket_id'] ?? 0);
if ($ticketId <= 0) {
    sse_send('error', ['message' => 'Ticket id required']);
    exit;
}

try {
    $conn = connectToDatabase();

    if (!analystCanAccessTicket($conn, (int)$_SESSION['analyst_id'], $ticketId)) {
        sse_send('error', ['message' => 'Ticket not found']);
        exit;
    }

    $aiCfg = aiSettingsLoad($conn, 'tickets_reply_cleanup');
    if (($aiCfg['api_key'] ?? '') === '') {
        // Not an error worth shouting about: merging works without AI, and the
        // caller treats this as "no summary" rather than "the merge failed".
        sse_send('unconfigured', ['message' => 'No AI provider configured for Tickets.']);
        exit;
    }

    // Gather the merged ticket's conversation. Bounded hard: a merged ticket can
    // carry hundreds of messages and the point is a summary, not a re-transcription.
    $stmt = $conn->prepare(
        "SELECT e.direction, e.from_name, e.from_address, e.received_datetime,
                e.subject, e.body_content, e.body_type
           FROM emails e
          WHERE e.ticket_id = ?
       ORDER BY e.received_datetime, e.id
          LIMIT 120"
    );
    $stmt->execute([$ticketId]);
    $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $conn->prepare(
        "SELECT n.note_text, n.created_datetime, a.full_name
           FROM ticket_notes n LEFT JOIN analysts a ON a.id = n.analyst_id
          WHERE n.ticket_id = ?
       ORDER BY n.created_datetime, n.id
          LIMIT 60"
    );
    $stmt->execute([$ticketId]);
    $notes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$messages && !$notes) {
        sse_send('error', ['message' => 'Nothing to summarise on this ticket']);
        exit;
    }

    // Which tickets were folded in — useful context for the model and for the
    // heading it writes.
    $stmt = $conn->prepare("SELECT source_ticket_number FROM ticket_merges WHERE target_ticket_id = ? ORDER BY id");
    $stmt->execute([$ticketId]);
    $sourceRefs = array_filter($stmt->fetchAll(PDO::FETCH_COLUMN));

    // Bodies are stripped to text before they go anywhere near the model: markup is
    // noise that costs tokens, and stripping it also means no HTML from a stranger's
    // email is echoed back into anything.
    $transcript = '';
    foreach ($messages as $m) {
        $body = (string)($m['body_content'] ?? '');
        $text = trim(html_entity_decode(strip_tags($body), ENT_QUOTES, 'UTF-8'));
        $text = preg_replace('/\s+/u', ' ', $text);
        if ($text === '') continue;
        if (mb_strlen($text) > 1500) $text = mb_substr($text, 0, 1500) . '…';

        $who = trim(($m['from_name'] ?? '') ?: ($m['from_address'] ?? 'unknown'));
        $transcript .= '[' . ($m['direction'] ?? '?') . '] ' . $who
                     . ' (' . ($m['received_datetime'] ?? '') . "): " . $text . "\n\n";
    }
    foreach ($notes as $n) {
        $text = preg_replace('/\s+/u', ' ', trim((string)$n['note_text']));
        if ($text === '') continue;
        if (mb_strlen($text) > 800) $text = mb_substr($text, 0, 800) . '…';
        $transcript .= '[Internal note] ' . ($n['full_name'] ?? 'Analyst')
                     . ' (' . ($n['created_datetime'] ?? '') . "): " . $text . "\n\n";
    }
    if (mb_strlen($transcript) > 60000) {
        $transcript = mb_substr($transcript, 0, 60000) . "\n…[truncated]";
    }

    $system = "You are helping an IT service desk analyst pick up a ticket that was created by merging "
            . "several separate tickets about what appears to be the same issue.\n\n"
            . "Read the combined conversation and write a SHORT briefing with exactly these two sections, "
            . "using these exact headings:\n\n"
            . "What has been asked\n"
            . "- who reported what, in plain language. Group people together when they reported the same thing. "
            . "Name them. Keep it to a few bullets.\n\n"
            . "What has been done\n"
            . "- what the service desk has already asked, tried, or promised, and anything still outstanding. "
            . "If nothing has been done yet, say so plainly.\n\n"
            . "RULES:\n"
            . "- Plain text only. No markdown, no asterisks, no bold. Use '- ' for bullets.\n"
            . "- Be concise: this is a briefing, not a transcript. Aim for under 200 words.\n"
            . "- Only state what the conversation actually says. If something is unclear or missing, "
            . "say it is unclear rather than guessing.\n"
            . "- Do not invent names, dates, reference numbers or fixes.\n"
            . "- Do not write a greeting, a sign-off, or any commentary about being an AI.";

    $userMessage = '';
    if ($sourceRefs) {
        $userMessage .= 'This ticket was created by merging: ' . implode(', ', $sourceRefs) . ".\n\n";
    }
    $userMessage .= "COMBINED CONVERSATION:\n\n" . $transcript;

    $onEvent = function (string $eventType, array $data) {
        if ($eventType === 'text')       sse_send('text', ['delta' => $data['delta'] ?? '']);
        elseif ($eventType === 'usage')  sse_send('usage', $data);
    };

    $opts = [
        'system'      => $system,
        'user'        => $userMessage,
        'max_tokens'  => 900,
        // Low: this is a factual précis of a real conversation. Invention here would
        // be worse than useless — an analyst would act on it.
        'temperature' => 0.2,
    ];

    if ($aiCfg['provider'] === 'anthropic') {
        $resp = rfpAiCallAnthropicStreaming($conn, $opts, $onEvent, [
            'provider'   => 'anthropic',
            'api_key'    => $aiCfg['api_key'],
            'model'      => $aiCfg['model'],
            'verify_ssl' => $aiCfg['verify_ssl'] ? '1' : '0',
        ]);
    } else {
        require_once '../../includes/ai_provider.php';
        $one = aiProviderChat($aiCfg, $opts);
        $onEvent('text', ['delta' => $one['content']]);
        $onEvent('usage', ['tokens_in' => $one['tokens_in'], 'tokens_out' => $one['tokens_out']]);
        $resp = $one + ['cache_read' => null, 'cache_write' => null];
    }

    sse_send('done', [
        'duration_ms' => $resp['duration_ms'] ?? null,
        'tokens_in'   => $resp['tokens_in']   ?? null,
        'tokens_out'  => $resp['tokens_out']  ?? null,
    ]);

} catch (Throwable $e) {
    sse_send('error', ['message' => $e->getMessage()]);
}
