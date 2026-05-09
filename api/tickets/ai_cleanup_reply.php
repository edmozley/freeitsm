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

session_start();
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/encryption.php';
require_once '../../includes/rfp_ai.php';

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

    // Load the per-feature AI settings (separate from RFP AI / Knowledge AI
    // so usage shows up as its own workspace on the Anthropic console).
    $stmt = $conn->prepare(
        "SELECT setting_key, setting_value FROM system_settings
          WHERE setting_key IN ('tickets_reply_cleanup_api_key',
                                'tickets_reply_cleanup_model',
                                'tickets_reply_cleanup_tone')"
    );
    $stmt->execute();

    $apiKey = '';
    $model  = '';
    $tone   = '';
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $val = $row['setting_value'];
        if ($row['setting_key'] === 'tickets_reply_cleanup_api_key') {
            $apiKey = $val !== '' ? decryptValue($val) : '';
        } elseif ($row['setting_key'] === 'tickets_reply_cleanup_model') {
            $model = $val ?? '';
        } elseif ($row['setting_key'] === 'tickets_reply_cleanup_tone') {
            $tone = $val ?? '';
        }
    }

    if ($apiKey === '') {
        sse_send('error', ['message' => 'Reply Cleanup AI not configured. Set up the key in Tickets → Settings → Reply Cleanup.']);
        exit;
    }
    if ($model === '') $model = 'claude-haiku-4-5-20251001';
    if ($tone  === '') $tone  = 'Friendly';

    // Resolve requester first name from the ticket.
    // users.preferred_name wins if the user has set one, else fall back to
    // users.display_name. Take the first whitespace-delimited token as the
    // greeting name ("Sarah Johnson" → "Sarah").
    $reqStmt = $conn->prepare(
        "SELECT COALESCE(NULLIF(TRIM(u.preferred_name), ''), u.display_name) AS name
           FROM tickets t
      LEFT JOIN users u ON u.id = t.user_id
          WHERE t.id = ?"
    );
    $reqStmt->execute([$ticketId]);
    $name = trim((string)($reqStmt->fetchColumn() ?: ''));
    $firstName = '';
    if ($name !== '') {
        $parts = preg_split('/\s+/', $name);
        $firstName = $parts[0] ?? '';
    }
    $greetingName = $firstName !== '' ? $firstName : 'there';

    // Tone description — kept short so the cached system prompt stays small.
    $toneDescription = match ($tone) {
        'Formal'  => 'Polite, professional, formal British English. No contractions.',
        'Brief'   => 'Polite, concise, no padding. British English.',
        default   => 'Polite, friendly, professional British English.',
    };

    $system = <<<PROMPT
You clean up rough draft replies for IT support analysts.

Your ONLY job is to:
- Add a "Dear {$greetingName}," greeting at the top
- Add paragraph breaks where natural
- Fix spelling and grammar
- Add "Kind regards," at the end (no name — the analyst signature is appended afterwards)
- Apply the requested tone

Tone: {$toneDescription}

You MUST NOT:
- Invent technical details, dates, ticket numbers, or facts not in the draft
- Add apologies, explanations, or next steps the analyst did not write
- Embellish, expand, or pad the content beyond what was provided
- Output any preamble like "Here is the cleaned-up email:"
- Add subject lines, signatures with names, footers, disclaimers, or contact details

The output should preserve the analyst's original meaning and length as closely as possible — only fix grammar, structure, greeting and sign-off.

Negative example:
Draft: "fixed it"
CORRECT output:
Dear Sarah,

It has been fixed.

Kind regards,

WRONG output (do NOT do this):
Dear Sarah,

I've resolved the issue and verified everything is working as expected. Please let me know if you need anything else.

Kind regards,

# OUTPUT FORMAT
Plain text only. Use a single blank line between paragraphs. No HTML, no markdown.
PROMPT;

    $resp = rfpAiCallAnthropicStreaming(
        $conn,
        [
            'system'      => $system,
            'user'        => $draftText,
            'max_tokens'  => 1024,
            'temperature' => 0.3,
        ],
        function (string $eventType, array $data) {
            if ($eventType === 'text') {
                sse_send('text', ['delta' => $data['delta'] ?? '']);
            } elseif ($eventType === 'usage') {
                sse_send('usage', $data);
            }
        },
        [
            'provider'   => 'anthropic',
            'api_key'    => $apiKey,
            'model'      => $model,
            'verify_ssl' => SSL_VERIFY_PEER,
        ]
    );

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
