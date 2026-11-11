<?php
/**
 * The help assistant's written answers - "Ask" (wiki Help-Assistant-Design, step 5).
 *
 * OPTIONAL. With no chat AI set up (or switched off), the assistant replies as it
 * did in steps 3-4: a canned line and the help sections. With one, the AI reads
 * the sections the search found and answers in a sentence or two, in the asker's
 * language, still pointing at the sections it used.
 *
 * The rules the AI is given (helpAskSystemPrompt()) are the point of this file:
 *   - only the numbered help sections - never what it knows of other products
 *   - never invent a menu, button, field or setting; quote names as written
 *   - the asker's language, with screen names kept exactly as the sections have them
 *   - brief
 *   - search again (search_help) before giving up, in English for a language
 *     with no help files of its own
 *   - "NOT_COVERED" when the help does not cover it - which also puts the question
 *     on the unanswered list (step 6)
 *   - a section the asker lacks the permission for: say WHO can do it, not how
 *
 * Any failure - no key, a timeout, a refusal - falls back to the search reply.
 */

require_once __DIR__ . '/ai_settings.php';
require_once __DIR__ . '/ai_provider.php';

/** The AI settings namespace - its own provider, model and encrypted key, like every AI feature. */
const HELP_ASK_NS = 'help_ai';

/** Characters of one section the AI is given - enough for a section, not a whole long page. */
const HELP_ASK_SECTION_CHARS = 3000;

/** How many of the search's sections go in with the question. */
const HELP_ASK_SECTIONS = 4;

/** What the AI replies when the help does not cover the question. */
const HELP_ASK_NOT_COVERED = 'NOT_COVERED';

/** Whether written answers are switched on (System -> Help assistant). */
function helpAskEnabled(PDO $conn): bool
{
    try {
        $st = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'help_ask_enabled'");
        $st->execute();
        return $st->fetchColumn() === '1';
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * The chat AI configuration when written answers can be given now, or null.
 *
 * @return ?array  aiSettingsLoad() result
 */
function helpAskReady(PDO $conn): ?array
{
    if (!helpAskEnabled($conn)) return null;
    try {
        $cfg = aiSettingsLoad($conn, HELP_ASK_NS);
        return aiConfigured($cfg) ? $cfg : null;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Whether the analyst holds the permission a section is marked with (data-cap).
 * A module's umbrella ("tickets.manage") counts as held when they have ANY of that
 * module's settings permissions - the section covers all of them.
 */
function helpAskCapHeld(PDO $conn, int $analystId, ?string $cap): bool
{
    if ($cap === null || $cap === '') return true;
    require_once __DIR__ . '/rbac.php';
    $held = getAnalystCapabilities($conn, $analystId);
    if (in_array($cap, $held, true)) return true;
    if (str_ends_with($cap, '.manage')) {
        $prefix = substr($cap, 0, -strlen('manage'));
        foreach ($held as $h) if (str_starts_with($h, $prefix)) return true;
    }
    return false;
}

/**
 * The text of one section for the AI: all of it when short, else the passage the
 * search matched and what follows it, up to HELP_ASK_SECTION_CHARS.
 */
function helpAskSectionText(array $s): string
{
    $body = (string)($s['body'] ?? '');
    if (mb_strlen($body) <= HELP_ASK_SECTION_CHARS) return $body;
    $passage = (string)($s['passage'] ?? '');
    $at = $passage !== '' ? mb_strpos($body, mb_substr($passage, 0, 80)) : false;
    return mb_substr($body, $at === false ? 0 : $at, HELP_ASK_SECTION_CHARS);
}

/** The language name the AI is asked to answer in. */
function helpAskLanguageName(string $locale): string
{
    $names = ['en' => 'English', 'de' => 'German', 'es' => 'Spanish', 'fr' => 'French', 'it' => 'Italian', 'nl' => 'Dutch', 'pt-BR' => 'Brazilian Portuguese',
              'pl' => 'Polish', 'da' => 'Danish', 'nb' => 'Norwegian Bokmål', 'nn' => 'Norwegian Nynorsk', 'af' => 'Afrikaans', 'ru' => 'Russian', 'uk' => 'Ukrainian',
              'id' => 'Indonesian', 'ms' => 'Malay', 'hi' => 'Hindi', 'bn' => 'Bengali', 'ta' => 'Tamil', 'te' => 'Telugu', 'mr' => 'Marathi', 'kn' => 'Kannada',
              'ml' => 'Malayalam', 'gu' => 'Gujarati', 'pa' => 'Punjabi'];
    return $names[$locale] ?? 'English';
}

/** The rules. Kept in one place so the test can read them. */
function helpAskSystemPrompt(string $locale, ?string $module): string
{
    $lang = helpAskLanguageName($locale);
    return <<<TXT
You are the help assistant inside FreeITSM, an IT service management system. You answer questions about how to use FreeITSM, for the analysts and administrators who use it.

RULES - follow every one:
1. Answer ONLY from the numbered FreeITSM help sections you are given, and any you fetch with the search_help tool. Never use what you know about other products or IT tools in general. FreeITSM's own help is the only truth.
2. Never invent a menu, button, field, setting or page. When you name one, write it exactly as it appears in the sections - those are the words on the user's screen.
3. Answer in {$lang}, the user's language - or in the language of the question, if it is clearly written in another. Even then, keep menu, button and setting names exactly as the sections write them.
4. Be brief: one to four sentences, or a short numbered list when there are steps. Start with the answer itself - never write "Based on the help sections", "I found", "According to the help" or similar, and no greeting or sign-off.
5. First check the sections are really about what is asked - for example, analysts (the people who work tickets) are not users or requesters (the people who raise them). If they are not clearly about it, or the question is not in English, call search_help with English keywords before answering - up to twice. If the help still does not cover it, reply with exactly NOT_COVERED and nothing else. Do not guess.
6. A section marked [needs permission the user does not have] describes something this user cannot do. Do not explain how; say that an administrator, or someone with access to that module's settings, can do it.
7. Finish with a line SOURCES: followed by the numbers of the sections you used, for example SOURCES: 1, 3
8. Signpost: when the answer means going somewhere, show the way there as a path with arrows, starting from the module's name as each section gives it - in English that looks like Tickets → Settings → Departments, or System → Database Verify. Put the path in bold. Build every step from the words the sections themselves use, in their language - never from this example. The help page's own name (such as "Tickets help" or "LMS guide") is NOT a menu, so never put it in a path. If the sections do not say where something is, do not make a path up.
TXT
        . ($module ? "\n\nThe user is in the {$module} module; when a question could mean several modules, prefer that one." : '');
}

/** One numbered section as the AI sees it. */
function helpAskFormatSection(int $n, array $s, bool $lacksPermission): string
{
    $where = trim(($s['page_title'] ?? '') . ' > ' . ($s['title'] ?? ''), ' >');
    $mod = helpAskModuleName($s['module'] ?? null);
    return "[$n] " . $where . ($mod !== '' ? " (module: $mod)" : '') . ($lacksPermission ? ' [needs permission the user does not have]' : '') . "\n" . helpAskSectionText($s);
}

/**
 * A module's name as its menu shows it, in the user's language - so the AI can
 * start a path from it (rule 8). The help itself writes paths from inside the
 * module ("Settings → Renewals" in the Software help); this is the missing first step.
 */
function helpAskModuleName(?string $module): string
{
    if ($module === null || $module === '') return '';
    $key = 'common.modules.' . $module . '.name';
    $name = function_exists('t') ? t($key) : '';
    if ($name === '' || $name === $key) {
        static $en = null;
        if ($en === null) {
            $c = @include __DIR__ . '/../lang/en/common.php';
            $en = is_array($c) ? ($c['modules'] ?? []) : [];
        }
        $name = $en[$module]['name'] ?? '';
    }
    return (string)$name;
}

/**
 * Ask the AI. Returns its answer and the sections it used, or null when it could
 * not (no key, an error) - the caller then replies as search does.
 *
 * @param list<array> $first  the search's best sections (helpMerge() rows, with body and passage)
 * @param list<array> $all  every section the analyst may be answered from (helpSectionsFor())
 * @param array<string,string> $pageTitles  page => its title
 * @return ?array{not_covered:bool,text:string,sources:list<array>,meta:array}
 */
function helpAskAnswer(PDO $conn, array $cfg, int $analystId, string $question, string $locale, ?string $module, array $first, array $all, array $pageTitles): ?array
{
    $numbered = [];   // n => section row
    $seen = [];
    $add = function (array $s) use (&$numbered, &$seen, $pageTitles): ?int {
        if (isset($seen[$s['section_key']])) return null;
        $n = count($numbered) + 1;
        $numbered[$n] = $s + ['page_title' => $pageTitles[$s['page']] ?? ''];
        $seen[$s['section_key']] = $n;
        return $n;
    };
    foreach (array_slice($first, 0, HELP_ASK_SECTIONS) as $s) $add($s);
    $block = function (array $ns) use (&$numbered, $conn, $analystId): string {
        $out = [];
        foreach ($ns as $n) $out[] = helpAskFormatSection($n, $numbered[$n], !helpAskCapHeld($conn, $analystId, $numbered[$n]['cap'] ?? null));
        return implode("\n\n", $out);
    };
    $searches = [];
    $tool = [
        'name' => 'search_help',
        'description' => 'Search FreeITSM\'s help for other sections. Give a few keywords - English works best. Returns new numbered sections to answer from.',
        'schema' => ['type' => 'object', 'properties' => ['words' => ['type' => 'string', 'description' => 'keywords to search for']], 'required' => ['words']],
    ];
    $run = function (string $name, array $args) use (&$searches, $all, $add, $block, $module): string {
        if ($name !== 'search_help') return 'Unknown tool.';
        $words = mb_substr(trim((string)($args['words'] ?? '')), 0, 200);
        $searches[] = $words;
        $new = [];
        foreach (helpRank($all, $words, $module, 4) as $s) { $n = $add($s); if ($n !== null) $new[] = $n; }
        return $new ? $block($new) : 'No other sections matched those words.';
    };

    $t = microtime(true);
    try {
        $r = aiProviderChatTools($cfg, [
            'system'      => helpAskSystemPrompt($locale, $module),
            // The closing instruction repeats the format: models follow the end of the
            // question more closely than the system rules ("I'm checking the sections..."
            // and "Based on the help sections" kept appearing without it).
            'user'        => "Question: $question\n\nHelp sections:\n\n" . ($numbered ? $block(array_keys($numbered)) : '(none matched the question\'s words - use search_help)')
                           . "\n\nReply with the answer only - no description of what you are doing or checking - then the SOURCES line. Or reply NOT_COVERED.",
            'max_rounds'  => 3,
            'max_tokens'  => 700,
            'temperature' => 0.2,
            'title'       => 'FreeITSM help assistant',
        ], [$tool], $run);
    } catch (Throwable $e) {
        error_log('help assistant: no written answer, replying with search results: ' . $e->getMessage());
        return null;
    }
    $content = trim((string)($r['content'] ?? ''));
    // TRAP: a model that spends every tool round searching ends with no text at all.
    // One last call without tools, with everything it gathered, makes it answer - or
    // say NOT_COVERED - instead of the asker getting the search reply for nothing.
    if ($content === '' && $numbered) {
        try {
            $f = aiProviderChat($cfg, [
                'system'      => helpAskSystemPrompt($locale, $module),
                'user'        => "Question: $question\n\nHelp sections:\n\n" . $block(array_keys($numbered)) . "\n\nAnswer now from these sections, or reply NOT_COVERED.",
                'max_tokens'  => 700,
                'temperature' => 0.2,
            ]);
            $content = trim((string)($f['content'] ?? ''));
            $r['tokens_in'] = (int)($r['tokens_in'] ?? 0) + (int)($f['tokens_in'] ?? 0);
            $r['tokens_out'] = (int)($r['tokens_out'] ?? 0) + (int)($f['tokens_out'] ?? 0);
        } catch (Throwable $e) {
            error_log('help assistant: no written answer after searching: ' . $e->getMessage());
        }
    }
    if ($content === '') return null;
    $meta = ['provider' => $r['provider'] ?? '', 'model' => $r['model'] ?? '', 'ms' => (int)round((microtime(true) - $t) * 1000),
             'tokens_in' => (int)($r['tokens_in'] ?? 0), 'tokens_out' => (int)($r['tokens_out'] ?? 0), 'searches' => $searches,
             'sections' => array_map(fn($n) => ['n' => $n, 'section_key' => $numbered[$n]['section_key'], 'title' => $numbered[$n]['title']], array_keys($numbered))];
    return helpAskParse($content, $numbered) + ['meta' => $meta];
}

/**
 * Read the AI's reply: NOT_COVERED, or the answer with its SOURCES line taken off
 * and turned into the sections it named. Pure, so it is tested without an AI.
 *
 * @param array<int,array> $numbered  n => section, as the AI was given them
 * @return array{not_covered:bool,text:string,sources:list<array>}
 */
function helpAskParse(string $content, array $numbered): array
{
    $content = trim($content);
    // TRAP: a model sometimes WRITES a tool call as text (<function_calls><invoke ...>)
    // instead of making it, after a line of narration ("I need to search for..."). The
    // answer is what follows the last such block - keep only that.
    if (preg_match('~</function_calls>|</invoke>~i', $content)) {
        $parts = preg_split('~</function_calls>|</invoke>~i', $content);
        $content = trim(preg_replace('~<function_calls>.*|<invoke\b.*~is', '', (string)end($parts)));
    }
    // And the opening the rules forbid, if it slipped through: "Based on the help sections, ...".
    $content = preg_replace_callback('/^(?:based on|according to)\s+(?:the\s+)?(?:help\s+)?(?:sections?|information)(?:\s+(?:provided|above|given))?\s*[,:]\s*(\w)/iu',
        fn($m) => mb_strtoupper($m[1]), $content);
    // NOT_COVERED alone - or, as models do, followed by an explanation of why.
    if (preg_match('/^\W*' . HELP_ASK_NOT_COVERED . '\b/', $content)) return ['not_covered' => true, 'text' => '', 'sources' => []];
    // TRAP: a model can narrate its search first and put NOT_COVERED on a line of
    // its own at the END ("The search results still do not ... NOT_COVERED"). Only
    // the start was checked, so the narration was shown as the answer. A line that
    // is just NOT_COVERED anywhere means not covered - no real answer contains one.
    if (preg_match('/^\W*' . HELP_ASK_NOT_COVERED . '\W*$/m', $content)) return ['not_covered' => true, 'text' => '', 'sources' => []];
    $sources = [];
    if (preg_match('/^\s*SOURCES?\s*:\s*(.*)$/mi', $content, $m)) {
        preg_match_all('/\d+/', $m[1], $nums);
        foreach (array_unique(array_map('intval', $nums[0])) as $n) if (isset($numbered[$n])) $sources[] = $numbered[$n];
        $content = trim(preg_replace('/^\s*SOURCES?\s*:.*$/mi', '', $content));
    }
    // A stray "NOT_COVERED" left with some words is still not an answer.
    if (stripos($content, HELP_ASK_NOT_COVERED) !== false && mb_strlen($content) < 40) return ['not_covered' => true, 'text' => '', 'sources' => []];
    // Inline [1] markers are for us, not the reader.
    $content = trim(preg_replace('/\s*\[\d+(?:\s*,\s*\d+)*\]/', '', $content));
    return ['not_covered' => false, 'text' => $content, 'sources' => $sources];
}
