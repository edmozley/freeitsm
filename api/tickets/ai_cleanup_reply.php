<?php
/**
 * API: Tickets — AI Reply Cleanup (streaming)
 *
 * POST { ticket_id: int, draft_text: string }
 * Streams Server-Sent Events as Claude rewrites the rough draft into a
 * properly formatted reply. The output is plain text with blank-line paragraph
 * breaks; the front-end is responsible for wrapping into <p> tags before
 * inserting into TinyMCE.
 *
 * Reuses the rfp_ai.php streaming helper but supplies its own settings
 * (key + model) from the tickets_reply_cleanup_* keys so this feature has
 * its own line on the Anthropic billing dashboard.
 */

session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/encryption.php';
require_once '../../includes/tenancy.php';
require_once '../../includes/rfp_ai.php';
require_once '../../includes/ai_settings.php';
require_once '../../includes/reply_cleanup_prompt.php';

// Disable buffering so SSE events flush immediately.
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

$input     = json_decode(file_get_contents('php://input'), true);
$ticketId  = (int)($input['ticket_id'] ?? 0);
$draftText = trim((string)($input['draft_text'] ?? ''));

if ($ticketId <= 0) {
    sse_send('error', ['message' => 'Ticket id required']);
    exit;
}
if ($draftText === '') {
    sse_send('error', ['message' => 'Draft text is empty — type something first']);
    exit;
}
if (mb_strlen($draftText) > 5000) {
    sse_send('error', ['message' => 'Draft is too long for cleanup (max 5000 characters)']);
    exit;
}

try {
    $conn = connectToDatabase();

    // Multi-tenancy: don't process a draft for a ticket in a company this analyst
    // can't access.
    if (!analystCanAccessTicket($conn, (int)$_SESSION['analyst_id'], $ticketId)) {
        sse_send('error', ['message' => 'Ticket not found']);
        exit;
    }

    // Provider / model / key / verify_ssl come from the shared AI block
    // (ns=tickets_reply_cleanup) so this feature keeps its own key + billing
    // line. Tone + custom instructions are reply-cleanup specific.
    $aiCfg = aiSettingsLoad($conn, 'tickets_reply_cleanup');
    if (($aiCfg['api_key'] ?? '') === '') {
        sse_send('error', ['message' => 'Reply Cleanup AI not configured. Set up the provider and key in Tickets → Settings → Reply Cleanup.']);
        exit;
    }

    $tone = '';
    $customInstructions = '';
    $stmt = $conn->prepare(
        "SELECT setting_key, setting_value FROM system_settings
          WHERE setting_key IN ('tickets_reply_cleanup_tone', 'tickets_reply_cleanup_custom_instructions')"
    );
    $stmt->execute();
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if ($row['setting_key'] === 'tickets_reply_cleanup_tone') {
            $tone = $row['setting_value'] ?? '';
        } elseif ($row['setting_key'] === 'tickets_reply_cleanup_custom_instructions') {
            $customInstructions = $row['setting_value'] ?? '';
        }
    }
    if ($tone === '') $tone = 'Friendly';

    // Resolve requester first name + ticket subject in one query.
    // users.preferred_name wins if the user has set one, else fall back to
    // users.display_name. Take the first whitespace-delimited token as the
    // greeting name ("Sarah Johnson" → "Sarah").
    $ticketStmt = $conn->prepare(
        "SELECT t.subject,
                COALESCE(NULLIF(TRIM(u.preferred_name), ''), u.display_name) AS name
           FROM tickets t
      LEFT JOIN users u ON u.id = t.user_id
          WHERE t.id = ?"
    );
    $ticketStmt->execute([$ticketId]);
    $ticketRow = $ticketStmt->fetch(PDO::FETCH_ASSOC) ?: ['subject' => '', 'name' => ''];
    $ticketSubject = trim((string)($ticketRow['subject'] ?? ''));
    $name = trim((string)($ticketRow['name'] ?? ''));
    $firstName = '';
    if ($name !== '') {
        $parts = preg_split('/\s+/', $name);
        $firstName = $parts[0] ?? '';
    }
    $greetingName = $firstName !== '' ? $firstName : 'there';

    // Fetch the first inbound email body — the "original problem reported".
    // This gives Claude enough context to (a) reference the issue in a short
    // verification ask when the draft is terse, and (b) phrase that ask
    // appropriately (e.g. "test" for issues, "confirm receipt" for hardware
    // requests). Capped to keep tokens predictable.
    $emailStmt = $conn->prepare(
        "SELECT body_content
           FROM emails
          WHERE ticket_id = ? AND direction = 'Inbound'
       ORDER BY received_datetime ASC, id ASC
          LIMIT 1"
    );
    $emailStmt->execute([$ticketId]);
    $rawBody = (string)($emailStmt->fetchColumn() ?: '');

    $originalProblem = '';
    if ($rawBody !== '') {
        $stripped = strip_tags($rawBody);
        $stripped = html_entity_decode($stripped, ENT_QUOTES, 'UTF-8');
        $stripped = preg_replace('/\s+/', ' ', $stripped) ?? '';
        $stripped = trim($stripped);
        if (mb_strlen($stripped) > 2000) {
            $stripped = mb_substr($stripped, 0, 2000) . '…';
        }
        $originalProblem = $stripped;
    }

    $toneDescription = replyCleanupToneDescription($tone);
    $system = buildReplyCleanupSystemPrompt($greetingName, $toneDescription, $customInstructions);

    $userMessage = "TICKET CONTEXT:\n";
    $userMessage .= "Subject: " . ($ticketSubject !== '' ? $ticketSubject : '(none)') . "\n";
    $userMessage .= "Original problem reported by the user:\n";
    $userMessage .= ($originalProblem !== '' ? $originalProblem : '(no inbound email body on file)') . "\n\n";
    $userMessage .= "ANALYST'S DRAFT REPLY (clean this up):\n";
    $userMessage .= $draftText;

    $onEvent = function (string $eventType, array $data) {
        if ($eventType === 'text') {
            sse_send('text', ['delta' => $data['delta'] ?? '']);
        } elseif ($eventType === 'usage') {
            sse_send('usage', $data);
        }
    };
    $opts = [
        'system'      => $system,
        'user'        => $userMessage,
        'max_tokens'  => 1024,
        'temperature' => 0.3,
    ];

    if ($aiCfg['provider'] === 'anthropic') {
        // Anthropic keeps live token-by-token streaming (unchanged engine).
        $resp = rfpAiCallAnthropicStreaming($conn, $opts, $onEvent, [
            'provider'   => 'anthropic',
            'api_key'    => $aiCfg['api_key'],
            'model'      => $aiCfg['model'],
            'verify_ssl' => $aiCfg['verify_ssl'] ? '1' : '0',
        ]);
    } else {
        // OpenRouter / OpenAI: one-shot via the shared client, emitted as a
        // single SSE chunk so the front-end SSE consumer is unaffected.
        require_once '../../includes/ai_provider.php';
        $one  = aiProviderChat($aiCfg, $opts);
        $onEvent('text', ['delta' => $one['content']]);
        $onEvent('usage', ['tokens_in' => $one['tokens_in'], 'tokens_out' => $one['tokens_out']]);
        $resp = $one + ['cache_read' => null, 'cache_write' => null];
    }

    sse_send('done', [
        'duration_ms' => $resp['duration_ms'] ?? null,
        'tokens_in'   => $resp['tokens_in']   ?? null,
        'tokens_out'  => $resp['tokens_out']  ?? null,
        'cache_read'  => $resp['cache_read']  ?? null,
        'cache_write' => $resp['cache_write'] ?? null,
    ]);

} catch (Throwable $e) {
    sse_send('error', ['message' => $e->getMessage()]);
}
