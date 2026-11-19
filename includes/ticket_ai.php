<?php
/**
 * Reading a whole ticket with an AI: the shared parts.
 *
 * Two features from discussion #104 sit on this file:
 *
 *   • The MAINTAINED SUMMARY (idea 7) — a few lines at the top of the ticket
 *     saying where things stand, rewritten when the conversation moves on.
 *   • READ THIS TICKET FOR ME (idea 12) — an on-demand briefing you ask for and
 *     read once, stored nowhere.
 *
 * They share one thing worth having in a single place: turning a ticket into the
 * text a model actually sees. Getting that wrong is how a summary quotes an
 * internal note into something customer-facing, or costs ten times what it
 * should because nobody bounded the transcript.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * ⚠️ WHAT A SUMMARY IS NOT
 *
 * It is not the ticket. The conversation stays exactly where it was, in full,
 * underneath — the summary is an extra pane you can collapse, never a
 * replacement for reading. That matters more here than anywhere else in
 * FreeITSM, because this is the one feature that could quietly become the only
 * thing anybody reads. So it is always labelled as machine-written, always
 * carries the time it was written and what it read, and always says when the
 * conversation has moved on since.
 *
 * 🔑 AND IT IS NEVER OVERWRITTEN. A refresh writes a new version; every earlier
 * one stays readable. A later reading really can be worse than an earlier one —
 * a model changes underneath you, a thread grows past what fits — and without
 * history that loss is silent and permanent.
 */

require_once __DIR__ . '/ai_settings.php';

/* ───────────────────────────────────────────────────────────────────────────
   SETTINGS

   Both features are OFF until somebody turns them on, which is not the usual
   FreeITSM default. Every other setting in this area changes how something
   already free is displayed; these two spend money with somebody else's API
   key, and a feature that starts billing on upgrade is not a good surprise.
   ─────────────────────────────────────────────────────────────────────────── */
function ticketAiSettings(?PDO $conn = null): array
{
    $defaults = [
        // The maintained summary panel at the top of a ticket (idea 7).
        'summary_enabled'       => 0,
        // Refresh it by itself once the conversation has moved this far ahead of
        // the last version. 0 = never; the button is then the only way.
        //
        // ⚠️ A refresh only ever happens when somebody OPENS the ticket. There is
        // no background job, deliberately: a nightly sweep across an open queue
        // would bill for summaries of tickets nobody looked at, and the bill
        // would arrive before the feature had been any use to anyone.
        'summary_auto_after'    => 0,
        // How far back the summary reads. A cap on cost, and on the model's
        // attention.
        'summary_max_messages'  => 60,
        // Whether internal notes are shown to the model at all.
        'summary_include_notes' => 1,

        // "Read this ticket for me" — the on-demand briefing (idea 12).
        'read_enabled'          => 0,
    ];

    try {
        $conn  = $conn ?: connectToDatabase();
        $keys  = array_map(fn($k) => 'ticket_ai_' . $k, array_keys($defaults));
        $place = implode(',', array_fill(0, count($keys), '?'));
        $stmt  = $conn->prepare("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ($place)");
        $stmt->execute($keys);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $k = substr($row['setting_key'], strlen('ticket_ai_'));
            if (!array_key_exists($k, $defaults)) continue;
            if ($k === 'summary_auto_after') {
                // 0 is meaningful here (never), so this one clamps rather than
                // bottoming out at a minimum the way the others do.
                $defaults[$k] = max(0, min(100, (int)$row['setting_value']));
            } elseif ($k === 'summary_max_messages') {
                $defaults[$k] = max(5, min(200, (int)$row['setting_value']));
            } else {
                $defaults[$k] = (int)(bool)(int)$row['setting_value'];
            }
        }
    } catch (Exception) {
        // Defaults — and the defaults are OFF, so a settings table we cannot
        // read can never start spending somebody's money.
    }

    return $defaults;
}

/**
 * The AI namespace both features use.
 *
 * Deliberately the SAME one the reply cleanup and the merge summary already use,
 * rather than a third provider panel and a third API key to paste in. An
 * administrator configures "the AI that reads tickets" once.
 */
const TICKET_AI_NS = 'tickets_reply_cleanup';

/* ───────────────────────────────────────────────────────────────────────────
   THE TRANSCRIPT

   One builder, used by the summary, the on-demand briefing and the merge
   summary. It had been written twice before this and the copies did not
   disagree yet, which is the only reason nobody had noticed.
   ─────────────────────────────────────────────────────────────────────────── */

/**
 * Turn a ticket into the plain text a model reads.
 *
 * Bodies are stripped to text before they go anywhere near a provider. Markup is
 * noise that costs tokens, and stripping it also means no HTML from a stranger's
 * email is ever echoed back into anything FreeITSM renders.
 *
 * @param array $opts ['max_messages'=>int, 'max_notes'=>int, 'include_notes'=>bool]
 * @return array ['text','messages','notes','last_email_id','truncated']
 */
function ticketAiTranscript(PDO $conn, int $ticketId, array $opts = []): array
{
    $maxMessages  = max(1, min(500, (int)($opts['max_messages'] ?? 120)));
    $maxNotes     = max(0, min(200, (int)($opts['max_notes'] ?? 60)));
    $includeNotes = array_key_exists('include_notes', $opts) ? (bool)$opts['include_notes'] : true;

    /* The most RECENT messages, not the first ones. A ticket that overflows the
       cap overflows it at the old end, and the old end is the part somebody
       picking this up cares least about. Fetched newest-first under the limit
       and then reversed, so the transcript still reads forwards. */
    $stmt = $conn->prepare(
        "SELECT e.id, e.direction, e.from_name, e.from_address, e.received_datetime,
                e.subject, e.body_content, e.body_type
           FROM emails e
          WHERE e.ticket_id = ?
       ORDER BY e.received_datetime DESC, e.id DESC
          LIMIT $maxMessages"
    );
    $stmt->execute([$ticketId]);
    $messages = array_reverse($stmt->fetchAll(PDO::FETCH_ASSOC));

    // Did we leave anything behind? Worth saying out loud in the prompt, so the
    // model does not confidently describe a beginning it never saw.
    $stmt = $conn->prepare("SELECT COUNT(*) FROM emails WHERE ticket_id = ?");
    $stmt->execute([$ticketId]);
    $truncated = (int)$stmt->fetchColumn() > count($messages);

    $notes = [];
    if ($includeNotes && $maxNotes > 0) {
        $stmt = $conn->prepare(
            "SELECT n.note_text, n.created_datetime, a.full_name
               FROM ticket_notes n LEFT JOIN analysts a ON a.id = n.analyst_id
              WHERE n.ticket_id = ?
           ORDER BY n.created_datetime DESC, n.id DESC
              LIMIT $maxNotes"
        );
        $stmt->execute([$ticketId]);
        $notes = array_reverse($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    $text        = '';
    $lastEmailId = null;
    $used        = 0;
    foreach ($messages as $m) {
        $plain = trim(html_entity_decode(strip_tags((string)($m['body_content'] ?? '')), ENT_QUOTES, 'UTF-8'));
        $plain = preg_replace('/\s+/u', ' ', $plain);
        // Recorded even when the body turns out to be empty: this is "how far I
        // have read", and an empty message still moves that mark forward.
        $lastEmailId = (int)$m['id'];
        if ($plain === '') continue;
        if (mb_strlen($plain) > 1500) $plain = mb_substr($plain, 0, 1500) . '…';

        $who   = trim(($m['from_name'] ?? '') ?: ($m['from_address'] ?? 'unknown'));
        $text .= '[' . ($m['direction'] ?? '?') . '] ' . $who
               . ' (' . ($m['received_datetime'] ?? '') . '): ' . $plain . "\n\n";
        $used++;
    }
    foreach ($notes as $n) {
        $plain = preg_replace('/\s+/u', ' ', trim((string)$n['note_text']));
        if ($plain === '') continue;
        if (mb_strlen($plain) > 800) $plain = mb_substr($plain, 0, 800) . '…';
        $text .= '[Internal note] ' . ($n['full_name'] ?? 'Analyst')
               . ' (' . ($n['created_datetime'] ?? '') . '): ' . $plain . "\n\n";
    }

    // A last, blunt ceiling. Everything above bounds the message COUNT; this
    // bounds one pathological message that survived its own truncation.
    if (mb_strlen($text) > 60000) {
        $text      = mb_substr($text, 0, 60000) . "\n…[truncated]";
        $truncated = true;
    }

    return [
        'text'          => $text,
        'messages'      => $used,
        'notes'         => count($notes),
        'last_email_id' => $lastEmailId,
        'truncated'     => $truncated,
    ];
}

/**
 * How many messages have arrived since the summary was written.
 *
 * @param ?int $lastEmailId  the last emails.id the summary read; null counts every message on the ticket
 */
function ticketAiMessagesSince(PDO $conn, int $ticketId, ?int $lastEmailId): int
{
    if ($lastEmailId === null) {
        $stmt = $conn->prepare("SELECT COUNT(*) FROM emails WHERE ticket_id = ?");
        $stmt->execute([$ticketId]);
        return (int)$stmt->fetchColumn();
    }
    /* By id rather than by timestamp: two messages can share a received time to
       the second, and "newer than the last one I read" has to be exact. */
    $stmt = $conn->prepare("SELECT COUNT(*) FROM emails WHERE ticket_id = ? AND id > ?");
    $stmt->execute([$ticketId, $lastEmailId]);
    return (int)$stmt->fetchColumn();
}

/** The two things this file stores, and the only two values `kind` may take. */
const TICKET_AI_KINDS = ['summary', 'read'];

/**
 * The newest stored summary (or briefing) for a ticket, or null.
 *
 * @param string $kind  'summary' or 'read'; anything else is treated as 'summary'
 */
function ticketAiLatestSummary(PDO $conn, int $ticketId, string $kind = 'summary'): ?array
{
    // Never interpolated from a request: an unknown kind falls back rather than
    // reaching the query.
    if (!in_array($kind, TICKET_AI_KINDS, true)) $kind = 'summary';
    $stmt = $conn->prepare(
        "SELECT s.*, a.full_name AS generated_by_name
           FROM ticket_ai_summaries s
      LEFT JOIN analysts a ON a.id = s.generated_by
          WHERE s.ticket_id = ? AND s.kind = ?
       ORDER BY s.version DESC LIMIT 1"
    );
    $stmt->execute([$ticketId, $kind]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * The instructions for the standing summary.
 *
 * Two rules carry most of the weight, and both are about what NOT to do. A
 * summary that invents a fix is worse than no summary, because it reads exactly
 * like one that did not. And a summary that hides its own uncertainty removes
 * the only signal telling somebody to go and read the thread themselves.
 */
/**
 * The rule that makes the ticket AI write in the analyst's language (GH #396) -
 * empty for English, so an English install's prompts are exactly what they were.
 *
 * Whose language: the analyst who asks. A summary is stored per ticket and shared,
 * but each version is one person's request, shown first on their screen; a team
 * that shares a language gets that language throughout.
 *
 * @param ?string $locale  the analyst's interface locale; null = the current request's
 */
function ticketAiLanguageRule(?string $locale = null): string
{
    require_once __DIR__ . '/i18n.php';
    $locale = $locale ?? (string)I18n::getLocale();
    if ($locale === '' || $locale === 'en') return '';
    $lang = I18n::englishLanguageName($locale);
    if ($lang === 'English') return '';
    return "\n- Write everything in {$lang}, the analyst's language, including the headings: translate each heading above into {$lang} and keep them in the same order. "
         . "Names, product and system names, reference numbers and quoted error messages stay exactly as written.";
}

function ticketAiSummarySystemPrompt(?string $locale = null): string
{
    return "You are summarising one ticket on an IT service desk, for an analyst who is about to pick it up.\n\n"
         . "Write a SHORT standing summary with exactly these headings, each on its own line:\n\n"
         . "Where things stand\n"
         . "- one or two sentences: what the current situation actually is.\n\n"
         . "What was asked\n"
         . "- what the requester wants, in their terms. Name them.\n\n"
         . "What has been done\n"
         . "- what the service desk has tried, asked or promised. If nothing yet, say so plainly.\n\n"
         . "Waiting on\n"
         . "- who owes the next move, and what it is. If it is unclear who, say that.\n\n"
         . "RULES:\n"
         . "- Plain text only. No markdown, no asterisks, no bold. Use '- ' for bullets.\n"
         . "- Under 180 words. This replaces the first scroll, not the ticket.\n"
         . "- State only what the conversation says. Never infer a cause, a fix or a resolution nobody wrote down.\n"
         . "- If something important is unclear or missing, say it is unclear. That is a useful answer, not a failure.\n"
         . "- Never invent names, dates, reference numbers, systems or fixes.\n"
         . "- Internal notes are staff-only. You may use them, but write for an analyst and never phrase anything as if it were going to the customer.\n"
         . "- No greeting, no sign-off, no commentary about being an AI."
         . ticketAiLanguageRule($locale);
}

/**
 * "Read this ticket for me" asks a different question from the summary.
 *
 * The summary is a standing description of the state. This is what you want at
 * the moment you open a ticket cold: what happened, and what should I do next.
 * It is allowed to suggest — and required to label a suggestion as one, because
 * the failure mode here is somebody acting on a confident guess.
 */
function ticketAiReadSystemPrompt(?string $locale = null): string
{
    return "An IT service desk analyst has just opened a ticket they have never seen, and asked you to read it for them.\n\n"
         . "Write a briefing with exactly these headings, each on its own line:\n\n"
         . "The short version\n"
         . "- two or three sentences. If somebody reads only this much, it should be enough to hold a conversation about the ticket.\n\n"
         . "How it got here\n"
         . "- the sequence of what actually happened, briefly. Skip the pleasantries and the repetition.\n\n"
         . "What is unresolved\n"
         . "- the open questions, the contradictions, anything asked and never answered.\n\n"
         . "What I would do next\n"
         . "- concrete suggestions. Begin this section with the line 'These are suggestions, not conclusions.'\n\n"
         . "RULES:\n"
         . "- Plain text only. No markdown, no asterisks, no bold. Use '- ' for bullets.\n"
         . "- Under 300 words.\n"
         . "- Separate what the ticket SAYS from what you are guessing, every time. If you are inferring, say you are inferring.\n"
         . "- Never invent names, dates, reference numbers, systems or fixes.\n"
         . "- If the ticket does not contain enough to answer a section, say so under that heading rather than filling it.\n"
         . "- No greeting, no sign-off, no commentary about being an AI."
         . ticketAiLanguageRule($locale);
}
