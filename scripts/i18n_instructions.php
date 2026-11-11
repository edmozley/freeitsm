<?php
/* 🔴 COMMAND LINE ONLY - see scripts/.htaccess. Over HTTP this would run for anyone, unauthenticated. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * Generate INSTRUCTIONS.<loc>.md for each locale - the per-locale half of a
 * translator's prompt. The hard rules live in scripts/i18n_BRIEF.md, which
 * every translator reads first; this file carries only what is specific to the
 * locale, plus plain-word descriptions of the modules the strings belong to.
 *
 *   php scripts/i18n_instructions.php <locale>... [--out DIR]     (DIR defaults to .i18n-work)
 *
 * The wiki's Adding-a-Language guide, §4: generate these rather than retyping
 * forty lines of rules per agent, because a retyped rule drifts. Edit the
 * $special table when a locale settles a new convention - and measure before
 * asserting one (grep the locale), as the AI/KI counts below were.
 */
chdir(dirname(__DIR__));
$out = dirname(__DIR__) . '/.i18n-work';
$locs = [];
$args = array_slice($argv, 1);
for ($i = 0; $i < count($args); $i++) {
    if ($args[$i] === '--out') { $out = rtrim($args[++$i], '/\\'); continue; }
    $locs[] = $args[$i];
}
if (!$locs) { fwrite(STDERR, "usage: i18n_instructions.php <locale>... [--out DIR]\n"); exit(2); }
@mkdir($out, 0777, true);

$L = [
    'af'    => ['Afrikaans', 'Latin'],
    'bn'    => ['Bengali (বাংলা)', 'Bengali script'],
    'da'    => ['Danish (dansk)', 'Latin'],
    'de'    => ['German (Deutsch)', 'Latin'],
    'es'    => ['Spanish (español)', 'Latin'],
    'fr'    => ['French (français)', 'Latin'],
    'gu'    => ['Gujarati (ગુજરાતી)', 'Gujarati script'],
    'hi'    => ['Hindi (हिन्दी)', 'Devanagari script'],
    'id'    => ['Indonesian (Bahasa Indonesia)', 'Latin'],
    'it'    => ['Italian (italiano)', 'Latin'],
    'kn'    => ['Kannada (ಕನ್ನಡ)', 'Kannada script'],
    'ml'    => ['Malayalam (മലയാളം)', 'Malayalam script'],
    'mr'    => ['Marathi (मराठी)', 'Devanagari script'],
    'ms'    => ['Bahasa Melayu (Malay, Malaysia)', 'Latin'],
    'nb'    => ['Norwegian bokmål', 'Latin'],
    'nl'    => ['Dutch (Nederlands)', 'Latin'],
    'nn'    => ['Norwegian nynorsk', 'Latin'],
    'pa'    => ['Punjabi (ਪੰਜਾਬੀ)', 'Gurmukhi script'],
    'pl'    => ['Polish (polski)', 'Latin'],
    'el'    => ['Greek (Ελληνικά)', 'Greek script'],
    'pt-BR' => ['Brazilian Portuguese (português do Brasil)', 'Latin'],
    'ru'    => ['Russian (русский)', 'Cyrillic script'],
    'ta'    => ['Tamil (தமிழ்)', 'Tamil script'],
    'te'    => ['Telugu (తెలుగు)', 'Telugu script'],
    'uk'    => ['Ukrainian (українська)', 'Cyrillic script'],
];

// Measured 2026-10-18 on the locale files themselves (grep counts in the notes).
$special = [
    'de'    => "- The existing files address the reader formally as **Sie** and say **KI** for AI (205 uses of KI against 17 of AI - the 17 are mostly product names and code). Keep both.",
    'es'    => "- The existing files address the reader formally as **usted** and say **IA**. Keep both. Use *incidencia* for an incident ticket and *activo* for an asset, as the glossary shows.",
    'fr'    => "- The existing files address the reader as **vous** and say **IA**. Keep both.",
    'pt-BR' => "- Brazilian Portuguese, not European: *você*, *arquivo*, *usuário*, *tela*. Say **IA** for AI, as the existing files do.",
    'nb'    => "- ⚠️ This is **bokmål**. The nynorsk twin is a separate locale - never let nynorsk forms in. Use the LEFT column, never the RIGHT: ikke / ikkje · hva / kva · fra / frå · noen / nokon · hvem / kven · være / vere · hvis / viss · jeg / eg.\n- The existing files are split between **AI** and **KI** (133 against 86). Use whatever the glossary gives for the English term in hand; for a new module with no precedent, use **AI**, which `common.php` uses.",
    'nn'    => "- ⚠️ This is **nynorsk**. The bokmål twin is a separate locale - never let bokmål forms in. Use the LEFT column, never the RIGHT: ikkje / ikke · kva / hva · frå / fra · nokon / noen · kven / hvem · vere / være · viss / hvis · eg / jeg.\n- The existing files are split between **KI** and **AI** (142 against 77). Use whatever the glossary gives for the English term in hand; for a new module with no precedent, use **KI**, the majority here.",
    'ms'    => "- ⚠️ This is **Bahasa Melayu (Malaysia)**, not Indonesian. Use the LEFT column, never the RIGHT: boleh / bisa · kualiti / kualitas · aktiviti / aktivitas · perisian / perangkat lunak · muat turun / unduh · muat naik / unggah · fail / berkas · tetapan / pengaturan · e-mel / surel. *kereta* is a car in Malay. The finished locale measured **boleh 436, bisa 0** - keep it that way.",
    'id'    => "- ⚠️ This is **Bahasa Indonesia**, not Malay. Use the LEFT column, never the RIGHT: bisa / boleh · kualitas / kualiti · aktivitas / aktiviti · perangkat lunak / perisian · unduh / muat turun · unggah / muat naik · berkas / fail · pengaturan / tetapan · surel / e-mel. *kereta* is a train in Indonesian.",
    'uk'    => "- Standard literary **Ukrainian**, never a Russianism where Ukrainian has its own word. Use the LEFT column, never the RIGHT: отримати / получити · скасувати / відмінити · за замовчуванням / по замовчуванню · налаштування / настройки · помилка / ошибка · користувач / пользователь.",
    'el'    => "- Modern standard **Greek** (monotonic, with the **tonos** on every word that takes one - never drop accents, never use polytonic). Address the reader with the polite plural, as Greek business software does: *Επιλέξτε*, *Αποθηκεύστε*, *σας*.\n- Greek punctuation: the question mark is **;** (never ?), quotation marks are **« »**, the final sigma is **ς**. Capitals drop the tonos only when a whole word is in capitals - in sentence case keep it (*Ρυθμίσεις*, not *ΡΥΘΜΙΣΕΙΣ*).\n- Never mix Latin look-alike letters into Greek words (Latin A/B/E/H/K/M/N/O/P/T/X/Y inside a Greek word breaks search).\n- **Watchtower**, **War Room**, **CMDB**, **SLA**, **CSAT**, **AI**, **API**, **REST**, product names and anything in backticks stay in Latin script.\n- 🔴 There is no existing Greek file to match: **the glossary is the whole consistency mechanism**. Use its renderings every time, including in help prose.",
    'pl'    => "- Polish has no single noun form that agrees with every count, and there is no plural machinery - put the number where the sentence tolerates any count, as the existing files do.",
    'hi'    => "- Read the brief's Indian-languages rule twice: Indian IT works in English. **Watchtower** and **War Room** stay in Latin script (30 existing uses). The existing files keep field names that the help pages quote in English **in English**, so a reader following the help can find the button - keep that.",
    'gu'    => "- Read the brief's Indian-languages rule twice. **Watchtower** stays in Latin script (33 existing uses); so do product and module names generally.",
    'ml'    => "- Read the brief's Indian-languages rule twice. Where the glossary gives a rendering, use it. For **Watchtower** and other module names with no glossary entry, keep the Latin-script form, as the other Indian locales do. Do not coin Sanskritised compounds for everyday IT nouns - a transliterated English noun reads better to the analyst using this.",
    'da'    => "- Danish business-software register: *du*, short, direct. Say **AI** as the existing files do (220 uses).",
];

$modules = <<<'MD'
## What the new strings are about

Four modules have never been translated into any language, so there is no file to match - the glossary and the voice sample are your anchors, and plain words win over jargon:

- **Projects** - a project portfolio: each project has stages, a plan of tasks within stages, a timeline, milestones, a burn-up chart, effort and capacity, a RAID log (risks, assumptions, issues, dependencies), gates and gate checks, benefits, an intake form, stakeholders and a RACI table, a budget, reports, templates, an AI assistant, and a tutorial. The English deliberately puts the plain phrase first and the formal project-management term second - keep that shape.
  ⚠️ **Phase, stage and sprint are three DIFFERENT kinds of timebox in this module** (a project is run in phases, in stages, or in sprints), and `{timebox}` is filled with whichever the project uses. Use three distinct words, and use each one everywhere: the German wave rendered *stage* three ways across four translators (Etappe / Phase / Stage) and had to be unified afterwards. Decide your word for *stage* before you start, never reuse your word for *phase*, and keep *gate*, *baseline*, *burn-up*, RAID, RACI and MoSCoW as the jargon your language's project managers actually use.
- **Domains** - a register of internet domain names: registrar, expiry and renewal, the transfer lock, DNS and certificate checks that produce **findings** with advice, a grade, suppliers, cost centres, bulk paste and CSV import, history, and links to tickets. `check.*` strings are the words for stored findings and are shown for every domain ever checked - translate them carefully, keep every `{name}` placeholder, and keep anything in backticks as it is.
- **Files** - a file store that looks like a small desktop: windows, a start menu, Documents and other folders, upload, a viewer, search, sharing, permissions. The strings are mostly short window chrome.
- **People** - a directory: people, the companies they belong to, suppliers and their contacts.

Additions to existing modules you will also meet: **report packs** in Reporting (a designer of report blocks, an engine, PDF output); **currencies** and cost centres in System; **approvals and evidence** in Change management; **leases, import, custom fields and labels** in Assets; **competency tests** in the LMS; **custom fields, mailboxes, a reading pane and an action row** in Tickets; **repeating tasks and subtasks** in Tasks. For these there IS an existing file in your locale - its established words are in the glossary; follow them.
MD;

$rules = <<<'MD'
## How to work

- You are **filling the last gaps in a locale with an established voice**, not starting fresh. The glossary is what this locale already says: **a term in the glossary is settled - use it**, even if you would have chosen differently. The voice sample shows the register. Do not go looking through `lang/` for more - everything you need is in the files you were given, and reading more is how an earlier translator spent twenty minutes and wrote nothing.
- **Read each chunk file for its key count.** Do not trust a number in a prompt.
- **Write each output file exactly once, with the Write tool, as soon as that chunk is done** - never a shell heredoc, never a rewrite. If one line needs correcting afterwards, edit that line.
- A translation that keeps the English word order only because the code glues a value onto the front or back of the string is a **bug report** - translate as best you can and say so in your reply.
- Before finishing each file: count its lines, and count its lines that contain a tab. The two numbers must be equal, and equal to the line count of the English chunk.
- **Your final reply is short:** one line per file written - `filename  N lines` - then at most three short notes on judgement calls. Never quote translated lines back.
MD;

foreach ($locs as $loc) {
    if (!isset($L[$loc])) { fwrite(STDERR, "unknown locale $loc - add it to \$L\n"); continue; }
    [$name, $script] = $L[$loc];
    $md = "# Translating FreeITSM into $name - instructions for this locale\n\n"
        . "Read `scripts/i18n_BRIEF.md` first - it holds the hard rules that get a chunk rejected. This file is what is specific to **$loc** ($script).\n\n"
        . "**Files for this locale**, next to this one:\n"
        . "- `GLOSSARY.$loc.tsv` - English term, the locale's settled translation, how many times it is already used. Up to 700 rows; the first column is what to look up.\n"
        . "- `VOICE.$loc.txt` - up to 24 of the locale's existing sentences beside their English, so you can hear the register.\n\n"
        . "## This locale\n\n" . ($special[$loc] ?? "- Match the register and the terms of the voice sample and the glossary.") . "\n\n"
        . $modules . "\n\n" . $rules;
    file_put_contents("$out/INSTRUCTIONS.$loc.md", $md);
    echo "wrote $out/INSTRUCTIONS.$loc.md\n";
}
