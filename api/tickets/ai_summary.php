<?php
/**
 * API: Tickets — the maintained AI summary (discussion #104, idea 7).
 *
 *   GET  ?ticket_id=N              the current summary, and whether it is behind
 *   GET  ?ticket_id=N&history=1    every version ever written, newest first
 *   POST { ticket_id, auto?:bool } write a new version
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * 🔑 A REFRESH NEVER OVERWRITES.
 *
 * It writes version n+1 and leaves n alone. A summary is a machine's reading of
 * a conversation and a later reading can be worse than an earlier one — the
 * model changes, the thread outgrows the window, somebody tightens the prompt.
 * If the newest version were the only one, that loss would be silent. The
 * history is one click away in the panel and nothing ever deletes it.
 *
 * ⚠️ `auto` is not a licence to spend. An automatic refresh is refused unless
 * the administrator has switched it on AND the conversation has actually moved
 * the configured number of messages ahead of the last version. The browser asks
 * for it on open; the SERVER decides, because a browser that asks twice must
 * not be able to bill twice.
 *
 * Uses the Tickets AI provider (ns=tickets_reply_cleanup) — one API key for the
 * module rather than a fourth panel to configure.
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

    // Multi-tenancy: a summary of a ticket you cannot open would be a novel way
    // to read one.
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
    if (!$settings['summary_enabled']) {
        echo json_encode(['success' => true, 'disabled' => true]);
        exit;
    }

    /* ── history ──────────────────────────────────────────────────────────── */
    if (!$isPost && !empty($_GET['history'])) {
        $stmt = $conn->prepare(
            "SELECT s.id, s.version, s.summary, s.model, s.provider, s.message_count,
                    s.note_count, s.truncated, s.created_at, a.full_name AS generated_by_name
               FROM ticket_ai_summaries s
          LEFT JOIN analysts a ON a.id = s.generated_by
              WHERE s.ticket_id = ? AND s.kind = 'summary'
           ORDER BY s.version DESC"
        );
        $stmt->execute([$ticketId]);
        echo json_encode(['success' => true, 'versions' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        exit;
    }

    $latest = ticketAiLatestSummary($conn, $ticketId);
    $behind = ticketAiMessagesSince($conn, $ticketId, $latest ? ($latest['last_email_id'] !== null ? (int)$latest['last_email_id'] : null) : null);

    /* ── read ─────────────────────────────────────────────────────────────── */
    if (!$isPost) {
        echo json_encode([
            'success' => true,
            'summary' => $latest ? [
                'version'           => (int)$latest['version'],
                'text'              => (string)$latest['summary'],
                'model'             => (string)($latest['model'] ?? ''),
                'message_count'     => (int)$latest['message_count'],
                'note_count'        => (int)$latest['note_count'],
                'created_at'        => (string)$latest['created_at'],
                'generated_by_name' => $latest['generated_by_name'] ?? null,
                'truncated'         => (int)($latest['truncated'] ?? 0),
            ] : null,
            // How far the conversation has moved since. The panel says this out
            // loud rather than quietly serving a summary that no longer describes
            // the ticket.
            'behind'       => $behind,
            // Whether the caller may usefully ask for an automatic refresh. Advisory
            // only — the POST checks the same thing again for itself.
            'auto_after'   => (int)$settings['summary_auto_after'],
            'auto_due'     => $settings['summary_auto_after'] > 0
                              && ($latest === null || $behind >= $settings['summary_auto_after']),
            'configured'   => (aiSettingsLoad($conn, TICKET_AI_NS)['api_key'] ?? '') !== '',
        ]);
        exit;
    }

    /* ── write a new version ──────────────────────────────────────────────── */
    $auto = !empty($input['auto']);
    if ($auto) {
        /* The server decides, not the browser. Two tabs opening the same ticket
           would otherwise bill twice for the same summary, and a page that
           refreshes on a timer would bill for ever. */
        if ($settings['summary_auto_after'] <= 0) {
            echo json_encode(['success' => false, 'error' => 'auto_disabled']);
            exit;
        }
        if ($latest !== null && $behind < $settings['summary_auto_after']) {
            echo json_encode(['success' => false, 'error' => 'not_due']);
            exit;
        }
    }

    $cfg = aiSettingsLoad($conn, TICKET_AI_NS);
    if (!aiConfigured($cfg)) {
        echo json_encode(['success' => false, 'error' => 'not_configured']);
        exit;
    }

    $transcript = ticketAiTranscript($conn, $ticketId, [
        'max_messages'  => (int)$settings['summary_max_messages'],
        'include_notes' => (bool)$settings['summary_include_notes'],
    ]);

    // Nothing to read is a normal answer, not a failure — and answering it here
    // saves paying for a call that can only say the same thing.
    if (trim($transcript['text']) === '') {
        echo json_encode(['success' => false, 'error' => 'nothing_to_summarise']);
        exit;
    }

    $user = '';
    if ($transcript['truncated']) {
        // Said plainly, so the model does not describe a beginning it never saw.
        $user .= "NOTE: this ticket is longer than what follows. You are seeing the "
               . "most recent part of the conversation only. Do not describe how the "
               . "ticket began unless the text below actually says.\n\n";
    }
    $user .= "TICKET CONVERSATION:\n\n" . $transcript['text'];

    $result = aiProviderChat($cfg, [
        'system'     => ticketAiSummarySystemPrompt(),
        'user'       => $user,
        /* ⚠️ Generous, because a REASONING MODEL spends this budget thinking before
           it writes anything. 700 was plenty for the ~180 words asked for and not
           nearly enough for a model that reasons first — it returned an empty string
           and a full usage record. A model that does not think out loud stops at its
           own natural end, so the higher ceiling costs those nothing. */
        'max_tokens' => 6000,
    ]);
    $text = trim((string)($result['content'] ?? ''));
    if ($text === '') {
        /* Nothing came back, and there are two very different reasons for that.
           A reasoning model that ran out of budget mid-thought is a SETTINGS
           problem with a fix the administrator can act on; anything else is not.
           Reported as itself, because "that did not work" sends somebody looking
           at their API key for a problem that is nowhere near it. */
        $ranOut = in_array($result['finish_reason'] ?? '', ['length', 'max_tokens'], true)
                  || (int)($result['reasoning_tokens'] ?? 0) > 0;
        echo json_encode(['success' => false, 'error' => $ranOut ? 'reasoning_overran' : 'empty_response']);
        exit;
    }

    /* Did it finish? A truncated summary is the worst thing this can produce —
       it reads almost like a complete one, and the section it lost is usually the
       last, which is where "waiting on" lives. Stored as a fact so the panel can
       say so, rather than being dropped (half a summary is still worth reading if
       you know that is what it is). */
    $wasCut = in_array($result['finish_reason'] ?? '', ['length', 'max_tokens'], true) ? 1 : 0;

    $version = $latest ? ((int)$latest['version'] + 1) : 1;
    $stmt = $conn->prepare(
        "INSERT INTO ticket_ai_summaries
            (ticket_id, version, summary, provider, model, message_count, note_count,
             last_email_id, generated_by, tokens_in, tokens_out, truncated, kind, created_at)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,'summary', UTC_TIMESTAMP())"
    );
    $stmt->execute([
        $ticketId, $version, $text,
        $result['provider'] ?? null, $result['model'] ?? null,
        $transcript['messages'], $transcript['notes'], $transcript['last_email_id'],
        // NULL for an automatic refresh: nobody pressed anything, and recording
        // an analyst would make it look as though somebody had.
        $auto ? null : $analystId,
        (int)($result['tokens_in'] ?? 0), (int)($result['tokens_out'] ?? 0), $wasCut,
    ]);

    echo json_encode([
        'success' => true,
        'summary' => [
            'version'           => $version,
            'text'              => $text,
            'model'             => (string)($result['model'] ?? ''),
            'message_count'     => $transcript['messages'],
            'note_count'        => $transcript['notes'],
            'created_at'        => gmdate('Y-m-d H:i:s'),
            'generated_by_name' => $auto ? null : ($_SESSION['full_name'] ?? null),
            'truncated'         => $wasCut,
        ],
        'behind' => 0,
    ]);
} catch (RuntimeException $e) {
    // A provider or network failure, reported as itself rather than as a 500 —
    // "could not reach the AI provider" is a true and actionable answer, and not
    // a bug in FreeITSM.
    echo json_encode(['success' => false, 'error' => 'provider_unreachable', 'detail' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('ai_summary: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Could not build the summary']);
}
