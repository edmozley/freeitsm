<?php
/**
 * Single source of truth for the AI Reply Cleanup system prompt.
 *
 * Used both by the streaming endpoint at runtime (with real values) and by the
 * settings page, so the editor there shows exactly what the AI is sent.
 *
 * EDITABLE, WITH A HISTORY (GH #396). The prompt is a TEMPLATE with two
 * placeholders - {greeting_name} and {tone} - filled in on every cleanup. Tickets
 * -> Settings -> Reply Cleanup can replace the built-in template; every save is a
 * new row in ticket_reply_cleanup_prompts and the NEWEST row is the one in use.
 * Nothing is ever overwritten or deleted, so a prompt somebody broke is one
 * Restore away: Restore and Reset write a new row too (Reset = a row with NULL
 * text, meaning "the built-in default"), and are themselves undoable the same way.
 * Ed, 19 Nov 2026: editable, "but can we allow versioning so if someone messes it
 * up they can revert back to the previous version".
 */

/** The placeholders an edited prompt may use, filled in on every cleanup. */
const REPLY_CLEANUP_PLACEHOLDERS = ['{greeting_name}', '{tone}'];

/** Longest prompt a person may save, in characters (the built-in one is about 5,000). */
const REPLY_CLEANUP_PROMPT_MAX = 20000;

/**
 * Map a tone name (Friendly / Formal / Brief) to the short clause inserted
 * into the system prompt. Defaults to Friendly for unrecognised values.
 *
 * @param string $tone  the tone name from settings: 'Friendly', 'Formal' or 'Brief'; anything else is treated as Friendly
 */
function replyCleanupToneDescription(string $tone): string
{
    return match ($tone) {
        'Formal'  => 'Polite, professional, formal British English. No contractions.',
        'Brief'   => 'Polite, concise, no padding. British English.',
        default   => 'Polite, friendly, professional British English.',
    };
}

/**
 * Build the system prompt. $customInstructions is appended verbatim under a
 * dedicated heading at the end so any user-supplied rules sit AFTER the
 * safety scaffold rather than overriding it.
 *
 * @param string $greetingName  the name after "Dear" - the requester's, or a placeholder on the settings page
 * @param string $toneDescription  the tone clause from replyCleanupToneDescription()
 * @param string $customInstructions  the organisation's extra rules from settings, appended last; '' for none
 * @param ?string $template  an edited template (replyCleanupActiveTemplate()); null = the built-in one
 */
function buildReplyCleanupSystemPrompt(string $greetingName, string $toneDescription, string $customInstructions = '', ?string $template = null): string
{
    $base = str_replace(REPLY_CLEANUP_PLACEHOLDERS, [$greetingName, $toneDescription],
                        ($template !== null && trim($template) !== '') ? $template : replyCleanupDefaultTemplate());

    $custom = trim($customInstructions);
    if ($custom !== '') {
        $base .= "\n\n# ADDITIONAL CUSTOM INSTRUCTIONS (from this organisation's settings)\n";
        $base .= "Apply these on top of the rules above. They MUST NOT override the hard constraints, the safety scaffold, or the output format.\n\n";
        $base .= $custom;
    }

    return $base;
}

/**
 * The template in use: the newest saved version's text, or null for the built-in
 * one (nothing saved, the newest version is a Reset, or the table does not exist
 * yet - an install that has not run Database Verification keeps working).
 */
function replyCleanupActiveTemplate(PDO $conn): ?string
{
    try {
        $t = $conn->query("SELECT prompt_text FROM ticket_reply_cleanup_prompts ORDER BY id DESC LIMIT 1")->fetchColumn();
    } catch (Throwable $e) {
        return null;
    }
    return ($t === false || $t === null || trim((string)$t) === '') ? null : (string)$t;
}

/**
 * Every saved version, newest first, with who saved it. The first is the one in use.
 *
 * @return array<int, array{id:int, is_default:bool, action:string, restored_from:?int, created_datetime:string, created_by_name:?string, chars:int}>
 */
function replyCleanupPromptVersions(PDO $conn, int $limit = 100): array
{
    try {
        $st = $conn->prepare("SELECT p.id, p.prompt_text IS NULL AS is_default, p.action, p.restored_from, p.created_datetime,
                                     a.full_name AS created_by_name, CHAR_LENGTH(COALESCE(p.prompt_text, '')) AS chars
                                FROM ticket_reply_cleanup_prompts p LEFT JOIN analysts a ON a.id = p.created_by
                               ORDER BY p.id DESC LIMIT " . max(1, min(500, $limit)));
        $st->execute();
    } catch (Throwable $e) {
        return [];
    }
    return array_map(fn($r) => [
        'id' => (int)$r['id'], 'is_default' => (bool)$r['is_default'], 'action' => (string)$r['action'],
        'restored_from' => $r['restored_from'] !== null ? (int)$r['restored_from'] : null,
        'created_datetime' => (string)$r['created_datetime'], 'created_by_name' => $r['created_by_name'], 'chars' => (int)$r['chars'],
    ], $st->fetchAll(PDO::FETCH_ASSOC));
}

/**
 * One saved version's text; null = it is the built-in default (a Reset), false = no such version.
 */
function replyCleanupPromptVersionText(PDO $conn, int $id): string|false|null
{
    $st = $conn->prepare("SELECT prompt_text FROM ticket_reply_cleanup_prompts WHERE id = ?");
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_NUM);
    if (!$row) return false;
    return $row[0] === null ? null : (string)$row[0];
}

/**
 * Writes a new version and makes it the one in use. Never updates or deletes a row.
 * Returns the new id, or 0 when nothing changed (the text is what is already in use).
 *
 * @param ?string $text  the template; null = back to the built-in default
 * @param string $action  'edit', 'reset' or 'restore'
 * @param ?int $restoredFrom  for 'restore', the version it copies
 */
function replyCleanupSavePromptVersion(PDO $conn, ?string $text, int $analystId, string $action, ?int $restoredFrom = null): int
{
    if ($text !== null) {
        $text = str_replace("\r\n", "\n", $text);
        if (trim($text) === '') throw new InvalidArgumentException('The prompt is empty.');
        if (mb_strlen($text) > REPLY_CLEANUP_PROMPT_MAX) throw new InvalidArgumentException('The prompt is too long.');
        // The built-in text saved as an "edit" is the default: store it as one, so a
        // later improvement to the built-in prompt reaches this install too.
        if ($text === replyCleanupDefaultTemplate()) $text = null;
    }
    $current = replyCleanupActiveTemplate($conn);
    if ($current === $text) return 0;
    $conn->prepare("INSERT INTO ticket_reply_cleanup_prompts (prompt_text, action, restored_from, created_by, created_datetime)
                    VALUES (?, ?, ?, ?, UTC_TIMESTAMP())")
         ->execute([$text, $action, $restoredFrom, $analystId > 0 ? $analystId : null]);
    return (int)$conn->lastInsertId();
}

/**
 * The built-in template, with {greeting_name} and {tone} where the requester's
 * name and the tone clause go. A nowdoc: nothing in it is interpolated by PHP.
 */
function replyCleanupDefaultTemplate(): string
{
    return <<<'PROMPT'
You clean up rough draft replies for IT support analysts. The user message will give you the ticket context (subject + original problem) AND the analyst's draft.

Your ONLY job is to:
- Add a "Dear {greeting_name}," greeting at the top
- Turn the analyst's shorthand and sentence fragments into proper full grammatical sentences
- Combine closely related points into a single short sentence where it reads more naturally
- Add paragraph breaks where natural
- Fix spelling and grammar
- Add "Kind regards," at the end (no name — the analyst signature is appended afterwards)
- Apply the requested tone

Tone: {tone}

# CONTEXT ENRICHMENT (only when the draft is VERY SHORT)

If — and ONLY if — the draft is fewer than ~15 words AND has no full sentence (e.g. just "fixed", "done", "work completed", "sorted", "delivered"), you may add ONE short sentence that:
1. Briefly references what was being addressed (using wording close to the original ticket subject)
2. Asks the user to verify in a way that fits the situation

Match the verification verb to the situation — pick the one that fits:
- Technical issues (errors, slowness, broken things, access problems): "please test it and let us know" / "please try again and let us know if it persists"
- Account / login / access requests: "please try logging in and confirm it works"
- Software install or config requests: "please launch it and confirm everything's working"
- Hardware / equipment delivery (mouse, keyboard, laptop, monitor): "please confirm it's set up correctly" / "please let us know if you have any issues setting it up" — NEVER use the words "test" or "re-test" for delivered hardware
- Information requests / questions answered: "please let us know if you need anything further" — NO verification ask needed

NEVER use the literal phrase "please re-test" verbatim — choose phrasing that fits the actual situation.

If the draft is LONGER than ~15 words OR contains complete sentences OR already mentions the issue OR already has its own next-steps/verification instructions ("call if not working", "let me know", "give it a try"), DO NOT add a context sentence — just clean up the grammar and stop.

# HARD CONSTRAINTS

You MUST NOT:
- Invent technical details, dates, ticket numbers, or facts not in the draft or ticket context
- Generalise or rename the problem ("Outlook crash" must not become "your email problem")
- Quote, summarise, or repeat details from the ticket body beyond the one-clause reference
- Add apologies, explanations, recommendations, or extra next steps the analyst didn't write
- Pad short drafts into multiple paragraphs (max one short context sentence + one short verification ask)
- Output any preamble like "Here is the cleaned-up email:"
- Add subject lines, signatures with names, footers, disclaimers, or contact details

# EXAMPLES

POSITIVE example A — draft is already substantial, just fix grammar (no enrichment):
Draft: "DNS issue resolved. Emails going out nicely. Any further problems let us know"
Correct output:
Dear Sarah,

The DNS issue has been resolved and emails are now sending normally. Please let us know if you experience any further problems.

Kind regards,

POSITIVE example B — VERY short draft on a technical-issue ticket (enrich):
Ticket subject: "Outlook keeps crashing on startup"
Draft: "fixed"
Correct output:
Dear Sarah,

The issue with Outlook crashing on startup has been resolved. Please test it and let us know if you experience any further problems.

Kind regards,

POSITIVE example C — VERY short draft on a hardware-request ticket (enrich, but NO "re-test"):
Ticket subject: "New mouse needed for desk B14"
Draft: "delivered"
Correct output:
Dear John,

Your new mouse has been delivered. Please let us know if you have any issues setting it up.

Kind regards,

POSITIVE example D — VERY short draft on an account-access ticket:
Ticket subject: "Cannot log into VPN"
Draft: "sorted"
Correct output:
Dear Mark,

Your VPN access has been restored. Please try logging in and confirm it works.

Kind regards,

NEGATIVE example — never pad / embellish / fabricate:
Ticket subject: "Outlook keeps crashing on startup"
Draft: "fixed"
WRONG output (do NOT do this):
Dear Sarah,

I'm pleased to inform you that I've successfully resolved the issue with Outlook crashing on startup. After investigating, I identified the root cause and applied the necessary fix. Outlook should now launch and run smoothly without any crashes. Please test it thoroughly and let me know if you encounter any further issues. I've also taken steps to prevent this from happening again.

Kind regards,

# OUTPUT FORMAT
Plain text only. Use a single blank line between paragraphs. No HTML, no markdown.
PROMPT;
}
