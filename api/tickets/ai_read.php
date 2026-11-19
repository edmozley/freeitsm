<?php
/**
 * API: Tickets — "read this ticket for me" (discussion #104, idea 12).
 *
 *   GET  ?ticket_id=N   the briefing already written, and whether it is behind
 *   POST { ticket_id }  write a new one
 *
 * A different question from the maintained summary. The summary is a standing
 * description of where a ticket stands; this is what you want at the moment you
 * open one cold — what happened, and what should I do next.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * 🔑 IT IS KEPT, AND IT IS VERSIONED.
 *
 * The first version of this stored nothing at all, on the reasoning that a
 * stored suggestion becomes a fact the next person reads without knowing a
 * machine wrote it. Ed's answer, which is the better one: the actual experience
 * of the unstored version is waiting a minute for a briefing you have already
 * read. The "it becomes a fact" worry is answered exactly the way the summary
 * answers it — it carries the time it was written, what it read, an AI label,
 * and a line saying how many messages have arrived since.
 *
 * So it shares the summary's table with `kind = 'read'` and inherits its rules:
 * a re-read writes version n+1, nothing is ever overwritten, and every earlier
 * version stays readable.
 *
 * ⚠️ Reopening it costs NOTHING. The GET answers from the table and never
 * touches the provider; only a re-read spends anything.
 *
 * Uses the Tickets AI provider (ns=tickets_reply_cleanup).
 */

session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/encryption.php';
require_once '../../includes/rbac.php';
require_once '../../includes/tenancy.php';
require_once '../../includes/ai_settings.php';
require_once '../../includes/ticket_ai.php';
require_once '../../includes/i18n.php';
I18n::initFromSession();   // GH #396: the AI writes in the analyst's language

header('Content-Type: application/json');

/* ⚠️ A reasoning model on a long ticket takes a MINUTE — measured at 62s on a
   real one. PHP's default limit would kill the request after the provider had
   already been paid and before anything was written down, which is the worst
   of both. The provider's own timeout is the real bound; this just stops PHP
   getting there first. */
@set_time_limit(300);

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireModuleAccessJson('tickets');

$isPost = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
$input  = $isPost ? json_decode(file_get_contents('php://input'), true) : [];
if (!is_array($input)) $input = [];

$ticketId = (int)($isPost ? ($input['ticket_id'] ?? 0) : ($_GET['ticket_id'] ?? 0));
if ($ticketId <= 0) {
    echo json_encode(['success' => false, 'error' => 'Ticket id required']);
    exit;
}

try {
    $conn      = connectToDatabase();
    $analystId = (int)$_SESSION['analyst_id'];

    if (!analystCanAccessTicket($conn, $analystId, $ticketId)) {
        echo json_encode(['success' => false, 'error' => 'Ticket not found']);
        exit;
    }
    // A confidential ticket is not sent to an AI provider unless Tickets -> Settings
    // says so (discussion #62) - checked here, on the server, whatever the page does.
    require_once __DIR__ . '/../../includes/ticket_sensitivity.php';
    if (!ticketAiAllowed($conn, $ticketId)) {
        echo json_encode(['success' => false, 'confidential' => true, 'error' => TICKET_AI_CONFIDENTIAL_ERROR]);
        exit;
    }

    $settings = ticketAiSettings($conn);
    if (!$settings['read_enabled']) {
        echo json_encode(['success' => false, 'error' => 'disabled']);
        exit;
    }

    $latest = ticketAiLatestSummary($conn, $ticketId, 'read');
    $behind = ticketAiMessagesSince(
        $conn, $ticketId,
        $latest && $latest['last_email_id'] !== null ? (int)$latest['last_email_id'] : null
    );

    /* ── what has already been written ─────────────────────────────────────
       Answered from the table, never from the provider — which is the whole
       point of keeping it: reopening a briefing you have read is instant and
       free, and only a re-read spends anything. */
    if (!$isPost) {
        $history = [];
        if ($latest) {
            $stmt = $conn->prepare(
                "SELECT s.version, s.summary, s.model, s.message_count, s.note_count,
                        s.truncated, s.created_at, a.full_name AS generated_by_name
                   FROM ticket_ai_summaries s
              LEFT JOIN analysts a ON a.id = s.generated_by
                  WHERE s.ticket_id = ? AND s.kind = 'read'
               ORDER BY s.version DESC"
            );
            $stmt->execute([$ticketId]);
            $history = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        echo json_encode([
            'success'  => true,
            'briefing' => $latest ? [
                'version'           => (int)$latest['version'],
                'text'              => (string)$latest['summary'],
                'model'             => (string)($latest['model'] ?? ''),
                'message_count'     => (int)$latest['message_count'],
                'note_count'        => (int)$latest['note_count'],
                'created_at'        => (string)$latest['created_at'],
                'generated_by_name' => $latest['generated_by_name'] ?? null,
                'truncated'         => (int)($latest['truncated'] ?? 0),
                // How much of the ticket this briefing has never seen.
                'behind'            => $behind,
            ] : null,
            'versions'   => $history,
            'configured' => (aiSettingsLoad($conn, TICKET_AI_NS)['api_key'] ?? '') !== '',
        ]);
        exit;
    }

    /* ── write a new one ───────────────────────────────────────────────────── */
    $cfg = aiSettingsLoad($conn, TICKET_AI_NS);
    if (!aiConfigured($cfg)) {
        echo json_encode(['success' => false, 'error' => 'not_configured']);
        exit;
    }

    /* Reads further back than the standing summary does, because this is the one
       you ask for when you do not know the ticket at all — and "how it got here"
       is a question about the beginning. */
    $transcript = ticketAiTranscript($conn, $ticketId, [
        'max_messages'  => 120,
        'include_notes' => (bool)$settings['summary_include_notes'],
    ]);
    if (trim($transcript['text']) === '') {
        echo json_encode(['success' => false, 'error' => 'nothing_to_read']);
        exit;
    }

    $user = '';
    if ($transcript['truncated']) {
        $user .= "NOTE: this ticket is longer than what follows. You are seeing the most "
               . "recent part only. Say so under 'How it got here' rather than describing "
               . "a beginning you cannot see.\n\n";
    }
    $user .= "TICKET CONVERSATION:\n\n" . $transcript['text'];

    $result = aiProviderChat($cfg, [
        'system'     => ticketAiReadSystemPrompt(),
        'user'       => $user,
        /* ⚠️ Generous, because a REASONING MODEL spends this budget thinking before
           it writes anything. 700 was plenty for the ~180 words asked for and not
           nearly enough for a model that reasons first — it returned an empty string
           and a full usage record. A model that does not think out loud stops at its
           own natural end, so the higher ceiling costs those nothing. */
        'max_tokens' => 8000,
    ]);
    $text = trim((string)($result['content'] ?? ''));
    if ($text === '') {
        // See ai_summary.php: a reasoning model that spent the whole budget
        // thinking is a nameable settings problem, not a generic failure.
        $ranOut = in_array($result['finish_reason'] ?? '', ['length', 'max_tokens'], true)
                  || (int)($result['reasoning_tokens'] ?? 0) > 0;
        echo json_encode(['success' => false, 'error' => $ranOut ? 'reasoning_overran' : 'empty_response']);
        exit;
    }

    // Cut off before it finished — recorded so the panel can say so, rather than
    // presenting half an answer as the whole of one.
    $wasCut = in_array($result['finish_reason'] ?? '', ['length', 'max_tokens'], true) ? 1 : 0;

    $version = $latest ? ((int)$latest['version'] + 1) : 1;
    $stmt = $conn->prepare(
        "INSERT INTO ticket_ai_summaries
            (ticket_id, kind, version, summary, provider, model, message_count, note_count,
             last_email_id, generated_by, tokens_in, tokens_out, truncated, created_at)
         VALUES (?,'read',?,?,?,?,?,?,?,?,?,?,?, UTC_TIMESTAMP())"
    );
    $stmt->execute([
        $ticketId, $version, $text,
        $result['provider'] ?? null, $result['model'] ?? null,
        $transcript['messages'], $transcript['notes'], $transcript['last_email_id'],
        $analystId,
        (int)($result['tokens_in'] ?? 0), (int)($result['tokens_out'] ?? 0), $wasCut,
    ]);

    echo json_encode([
        'success'  => true,
        'briefing' => [
            'version'           => $version,
            'text'              => $text,
            'model'             => (string)($result['model'] ?? ''),
            'message_count'     => $transcript['messages'],
            'note_count'        => $transcript['notes'],
            'created_at'        => gmdate('Y-m-d H:i:s'),
            'generated_by_name' => $_SESSION['full_name'] ?? null,
            'truncated'         => $wasCut,
            // Written from everything there is, so nothing is behind it.
            'behind'            => 0,
        ],
    ]);
} catch (RuntimeException $e) {
    echo json_encode(['success' => false, 'error' => 'provider_unreachable', 'detail' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('ai_read: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Could not read the ticket']);
}
