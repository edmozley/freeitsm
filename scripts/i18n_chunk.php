<?php
/* 🔴 COMMAND LINE ONLY - see scripts/.htaccess. Over HTTP this would run for anyone, unauthenticated. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * Build the translation work list: every gap a locale has, split into chunks an
 * agent can do in one go.
 *
 *   php scripts/i18n_chunk.php hi                    one locale
 *   php scripts/i18n_chunk.php hi bn ta --max 260    several, custom chunk size
 *   php scripts/i18n_chunk.php --indian              the nine Indian locales
 *   php scripts/i18n_chunk.php hi --out C:/tmp/x     somewhere other than the default
 *
 * 🔑 CHUNKS ARE SPLIT BY TOP-LEVEL SECTION, not by counting to N and cutting.
 * A section is a screen, so a unit of work is coherent — the agent sees all the
 * strings for one dialogue at once and can make them read as a set. It also
 * means a failure loses a screen rather than half a module.
 *
 * ⚠️ A section larger than --max is NOT split. Cutting a screen in half to hit
 * an arbitrary number is how you get two halves translated in two registers.
 * The oversized ones are reported so the orchestrator can give them their own
 * agent rather than discovering it at merge time.
 *
 * Writes, under --out (default: the session's own directory):
 *   chunks/<locale>__<namespace>__<section>.en.tsv   the English to translate
 *   _worklist.json                                   what to fan out over
 *
 * Read-only with respect to lang/. Writes nothing but chunk files.
 */

require __DIR__ . '/i18n_lib.php';

$root = dirname(__DIR__);
chdir($root);

$argvRest = array_slice($argv, 1);
$locales = [];
$max = 260;

/**
 * 🔴 BYTES ARE THE REAL LIMIT, NOT KEYS. Measured on the French run of
 * 2026-09-22, in the units i18nRowBytes() counts (key + tab + value + newline):
 *
 *   contracts.rfp   597 keys, 39,847 bytes, short labels   -> COMPLETED, ~2 min
 *   tickets.settings 629 keys, 59,934 bytes, mixed          -> AGENT KILLED
 *   tickets.help    358 keys, 63,787 bytes, help prose      -> AGENT KILLED
 *
 * Both deaths were the 64,000 output-token ceiling, and both agents were killed
 * before writing anything at all. Key count predicted neither: the 597-key
 * chunk survived and the 358-key one did not. Size predicted both.
 *
 * ⚠️ BUT SIZE WAS NOT THE ONLY THING THAT CHANGED. The re-split also added an
 * instruction: write the file once, and do not echo it back. Both deaths were
 * agents producing the same content twice. That confound was then resolved
 * deliberately, on the pt-BR run the same day: seven bundles packed to ~45 KB
 * — larger than one chunk that had died — WITH the write-once rule.
 *
 *   6 of 6 large bundles survived, at 44,007 to 44,996 bytes.
 *
 * 🔑 So the write-once instruction was the fix, not the smaller chunk. That is
 * why it now lives in scripts/i18n_BRIEF.md as its own section rather than in
 * an orchestrator's prompt: THIS LIMIT ASSUMES EVERY AGENT READS AND FOLLOWS
 * IT. If you translate without the brief, halve this number.
 *
 * 40,000 is under the 44,007 lowest proven survivor, with margin for prose
 * being costlier per byte than labels. 60 KB remains untested with the rule and
 * is not worth finding out on a real run: a chunk that dies costs a full re-run
 * and a manual split, while a chunk that is too small costs one more agent
 * re-reading the brief. Those are not comparable.
 */
$maxBytes = 40000;

/**
 * 🔴 THE LIMIT ABOVE IS IN *ENGLISH* BYTES, BUT THE CEILING IS ON *OUTPUT*.
 * A locale whose script costs more bytes per character hits it sooner, and
 * 40,000 was calibrated entirely on Latin-script runs.
 *
 * Measured, output bytes per English byte, over whole completed locales:
 *
 *   nb  x1.04     Norwegian      Latin
 *   ms  x1.12     Malay          Latin
 *   fr  x1.18     French         Latin
 *   uk  x1.64     Ukrainian      Cyrillic, 2 bytes/char
 *   gu  x2.04     Gujarati       Indic, 3 bytes/char
 *   ml  x2.42     Malayalam      Indic, 3 bytes/char + long conjuncts
 *
 * A 45,000-byte English bundle is ~53 KB of French and ~109 KB of Malayalam.
 * That is exactly how a Malayalam agent was killed on the 64,000 output-token
 * ceiling after the same size had been completing comfortably in French,
 * Norwegian, Malay, Portuguese and Ukrainian.
 *
 * 🔑 So the budget is scaled by the locale's measured expansion, relative to
 * the Latin baseline the 40,000 was derived from. Unknown locales get the
 * Latin default, which is the safe direction to be wrong in only for Latin
 * scripts — add a measured figure before running a new script family.
 */
const LATIN_BASELINE = 1.18;
const EXPANSION = [
    'ml' => 2.42, 'gu' => 2.04,
    // The other Indic scripts are the same 3-bytes-per-character shape. Given
    // gu measured 2.04 and ml 2.42, 2.40 is used until each is measured for
    // real — erring towards the larger, because the cost of being wrong is an
    // agent dying with nothing written.
    'kn' => 2.40, 'ta' => 2.40, 'te' => 2.40, 'bn' => 2.40, 'pa' => 2.40,
    'mr' => 2.40, 'hi' => 2.40,
    'uk' => 1.64, 'ru' => 1.64,          // Cyrillic
    // Greek: measured x2.18 over the whole first translation (2026-11-11, 22,945 strings) -
    // 2 bytes a character like Cyrillic, and the words run longer than English.
    'el' => 2.18,
    'ms' => 1.12, 'nb' => 1.04,          // measured Latin
];

function i18nBudget(string $loc, int $maxBytes): int
{
    $x = EXPANSION[$loc] ?? LATIN_BASELINE;
    return (int) round($maxBytes * (LATIN_BASELINE / $x));
}
$out = $root . '/.i18n-work';

for ($i = 0; $i < count($argvRest); $i++) {
    $a = $argvRest[$i];
    if ($a === '--max') { $max = max(20, (int)$argvRest[++$i]); continue; }
    if ($a === '--maxbytes') { $maxBytes = max(2000, (int)$argvRest[++$i]); continue; }
    if ($a === '--out') { $out = rtrim($argvRest[++$i], '/\\'); continue; }
    if ($a === '--indian') {
        $locales = array_merge($locales, ['hi','bn','ta','te','mr','pa','gu','kn','ml']);
        continue;
    }
    if (str_starts_with($a, '--')) { fwrite(STDERR, "unknown option $a\n"); exit(2); }
    $locales[] = $a;
}
$locales = array_values(array_unique($locales));
if (!$locales) {
    fwrite(STDERR, "usage: i18n_chunk.php <locale>... | --indian  [--max N] [--out DIR]\n");
    exit(2);
}

$enFiles = glob('lang/en/*.php');
sort($enFiles);

$worklist = [];
$oversized = [];
$totalKeys = 0;
$blankSkipped = 0;   // blank-English keys withheld from translators; see below
$budgets = [];       // per-locale English-byte budget, scaled by script expansion
$chunkDir = $out . '/chunks';
@mkdir($chunkDir, 0777, true);

foreach ($locales as $loc) {
    if (!is_dir("lang/$loc")) { fwrite(STDERR, "no such locale dir: lang/$loc\n"); exit(2); }

    // English bytes this locale may be given, scaled by how much its script
    // expands on output. See EXPANSION above.
    $budget = i18nBudget($loc, $maxBytes);
    $budgets[$loc] = $budget;

    foreach ($enFiles as $enPath) {
        $ns   = basename($enPath, '.php');
        $en   = i18nFlatten(i18nLoad($enPath));
        $have = i18nFlatten(i18nLoad("lang/$loc/$ns.php"));

        // The gap: keys English has that this locale does not. An existing
        // value is never re-translated, however poor — that is a review job,
        // not a gap-filling job, and conflating the two loses work.
        $gap = array_diff_key($en, $have);

        /* 🔴 BLANK-ENGLISH KEYS ARE NEVER SENT TO A TRANSLATOR.
         *
         * Seven keys in the whole product have an empty English value — a blank
         * column header, a hint deliberately unset. They need no translation:
         * if the English is empty the translation is empty, necessarily and in
         * every language. i18n_merge.php fills them itself.
         *
         * 🔑 Asking for them anyway was the most persistent fault in this
         * pipeline — ELEVEN failures, every one of them on one of these seven
         * keys. `software.inventory.col_actions` alone failed in fr, pt-BR and
         * nb, the last time in an agent whose prompt named that exact key as
         * the thing to watch. The output format is `key<TAB>value`, and a tab
         * followed by nothing is not reliably producible; two agents reported
         * checking that very line and were wrong.
         *
         * Removing the request removes the failure. That is worth more than any
         * amount of warning about it.
         */
        $blank = 0;
        foreach ($gap as $k => $v) {
            if ($v === '') { unset($gap[$k]); $blank++; }
        }
        if ($blank) $blankSkipped += $blank;

        if (!$gap) continue;

        $whole = !is_file("lang/$loc/$ns.php");

        // Group the gap by its top-level section.
        $sections = [];
        foreach ($gap as $k => $v) {
            $top = !str_contains($k, I18N_SEP) ? '_root' : substr($k, 0, strpos($k, I18N_SEP));
            $sections[$top][$k] = $v;
        }

        // Pack whole sections together up to --max keys AND --maxbytes bytes.
        $batches = [];
        $cur = []; $curN = 0; $curB = 0; $curNames = [];
        foreach ($sections as $name => $rows) {
            $n = count($rows);
            $b = i18nRowBytes($rows);

            if ($n > $max || $b > $budget) {
                $oversized[] = ['locale'=>$loc, 'ns'=>$ns, 'section'=>$name, 'keys'=>$n, 'bytes'=>$b];
                if ($cur) { $batches[] = [$curNames, $cur]; $cur = []; $curN = 0; $curB = 0; $curNames = []; }

                // ⚠️ A section over the BYTE limit must be split or its agent dies
                // with nothing written — so split it at SECOND-level key boundaries,
                // which keeps each dialogue or panel whole. A section that is merely
                // over the KEY limit still gets one agent, as it always did.
                if ($b > $budget) {
                    foreach (i18nSplitSection($rows, $budget) as $pi => $part) {
                        $batches[] = [[$name . '_p' . ($pi + 1)], $part];
                    }
                } else {
                    $batches[] = [[$name], $rows];    // its own agent
                }
                continue;
            }

            if (($curN + $n > $max || $curB + $b > $budget) && $cur) {
                $batches[] = [$curNames, $cur]; $cur = []; $curN = 0; $curB = 0; $curNames = [];
            }
            foreach ($rows as $k => $v) $cur[$k] = $v;
            $curN += $n; $curB += $b; $curNames[] = $name;
        }
        if ($cur) $batches[] = [$curNames, $cur];

        foreach ($batches as $idx => [$names, $rows]) {
            $label = count($names) === 1 ? $names[0] : ('mixed' . ($idx + 1));
            $label = preg_replace('/[^A-Za-z0-9_-]/', '_', $label);
            $file  = "{$loc}__{$ns}__{$label}.en.tsv";
            i18nWriteTsv("$chunkDir/$file", $rows);
            $worklist[] = [
                'locale'    => $loc,
                'namespace' => $ns,
                'section'   => $label,
                'sections'  => $names,
                'keys'      => count($rows),
                'bytes'     => i18nRowBytes($rows),
                'whole_file'=> $whole,
                'en_tsv'    => "chunks/$file",
                'out_tsv'   => 'chunks/' . str_replace('.en.tsv', ".$loc.tsv", $file),
            ];
            $totalKeys += count($rows);
        }
    }
}

// Biggest first: a 1,700-key module wants a concurrency slot early, not last.
usort($worklist, function ($a, $b) { return $b['keys'] <=> $a['keys']; });

file_put_contents($out . '/_worklist.json', json_encode([
    'generated'   => gmdate('c'),
    'locales'     => $locales,
    'max_chunk'   => $max,
    'max_bytes'   => $maxBytes,
    'total_keys'  => $totalKeys,
    'total_chunks'=> count($worklist),
    'oversized'   => $oversized,
    'items'       => $worklist,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

printf("locales      : %s\n", implode(' ', $locales));
printf("chunks       : %d\n", count($worklist));
printf("keys         : %s\n", number_format($totalKeys));
printf("max/chunk    : %d keys, %s bytes of English (Latin baseline)\n", $max, number_format($maxBytes));
foreach ($budgets as $l => $b) {
    $x = EXPANSION[$l] ?? LATIN_BASELINE;
    if (abs($b - $maxBytes) > 1) {
        printf("  %-6s budget %s bytes — script expands x%.2f, so ~%s bytes of output\n",
            $l, number_format($b), $x, number_format((int) round($b * $x)));
    }
}
printf("out          : %s\n", $out);
if ($blankSkipped) {
    printf("blank-English: %d key(s) withheld from translators — i18n_merge.php fills them\n", $blankSkipped);
}
if ($oversized) {
    printf("\noversized sections:\n");
    foreach (array_slice($oversized, 0, 12) as $o) {
        printf("  %-3s %-20s %-24s %5d keys %7s bytes  %s\n",
            $o['locale'], $o['ns'], $o['section'], $o['keys'], number_format($o['bytes']),
            // ⚠️ Against that LOCALE's budget, not the global limit — they differ
            // once a script expands on output, and comparing to the wrong one
            // labels a section "not split" while the packer is splitting it.
            $o['bytes'] > ($budgets[$o['locale']] ?? $maxBytes)
                ? 'SPLIT at second-level boundaries' : 'own agent, not split');
    }
    if (count($oversized) > 12) printf("  ... and %d more\n", count($oversized) - 12);
}

// The number that actually predicts whether an agent survives. A chunk over the
// byte limit here is one that should have been split and was not — which means a
// single second-level group is itself too big and wants a human.
$worst = 0;
foreach ($worklist as $w) $worst = max($worst, $w['bytes']);
printf("\nlargest chunk: %s bytes (limit %s)%s\n",
    number_format($worst), number_format($maxBytes),
    $worst > $maxBytes ? '   ⚠️ OVER - an agent may die on it' : '   ok');
printf("\nper-locale totals:\n");
$byLoc = [];
foreach ($worklist as $w) { $byLoc[$w['locale']]['k'] = ($byLoc[$w['locale']]['k'] ?? 0) + $w['keys']; $byLoc[$w['locale']]['c'] = ($byLoc[$w['locale']]['c'] ?? 0) + 1; }
foreach ($byLoc as $l => $t) printf("  %-3s %5s keys in %3d chunks\n", $l, number_format($t['k']), $t['c']);
