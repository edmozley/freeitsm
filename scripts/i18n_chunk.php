<?php
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
$out = $root . '/.i18n-work';

for ($i = 0; $i < count($argvRest); $i++) {
    $a = $argvRest[$i];
    if ($a === '--max') { $max = max(20, (int)$argvRest[++$i]); continue; }
    if ($a === '--out') { $out = rtrim($argvRest[++$i], '/\\'); continue; }
    if ($a === '--indian') {
        $locales = array_merge($locales, ['hi','bn','ta','te','mr','pa','gu','kn','ml']);
        continue;
    }
    if (strpos($a, '--') === 0) { fwrite(STDERR, "unknown option $a\n"); exit(2); }
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
$chunkDir = $out . '/chunks';
@mkdir($chunkDir, 0777, true);

foreach ($locales as $loc) {
    if (!is_dir("lang/$loc")) { fwrite(STDERR, "no such locale dir: lang/$loc\n"); exit(2); }

    foreach ($enFiles as $enPath) {
        $ns   = basename($enPath, '.php');
        $en   = i18nFlatten(i18nLoad($enPath));
        $have = i18nFlatten(i18nLoad("lang/$loc/$ns.php"));

        // The gap: keys English has that this locale does not. An existing
        // value is never re-translated, however poor — that is a review job,
        // not a gap-filling job, and conflating the two loses work.
        $gap = array_diff_key($en, $have);
        if (!$gap) continue;

        $whole = !is_file("lang/$loc/$ns.php");

        // Group the gap by its top-level section.
        $sections = [];
        foreach ($gap as $k => $v) {
            $top = strpos($k, I18N_SEP) === false ? '_root' : substr($k, 0, strpos($k, I18N_SEP));
            $sections[$top][$k] = $v;
        }

        // Pack whole sections together up to --max, never splitting one.
        $batches = [];
        $cur = []; $curN = 0; $curNames = [];
        foreach ($sections as $name => $rows) {
            $n = count($rows);
            if ($n > $max) {
                $oversized[] = ['locale'=>$loc, 'ns'=>$ns, 'section'=>$name, 'keys'=>$n];
                if ($cur) { $batches[] = [$curNames, $cur]; $cur = []; $curN = 0; $curNames = []; }
                $batches[] = [[$name], $rows];        // its own agent
                continue;
            }
            if ($curN + $n > $max && $cur) {
                $batches[] = [$curNames, $cur]; $cur = []; $curN = 0; $curNames = [];
            }
            foreach ($rows as $k => $v) $cur[$k] = $v;
            $curN += $n; $curNames[] = $name;
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
    'total_keys'  => $totalKeys,
    'total_chunks'=> count($worklist),
    'oversized'   => $oversized,
    'items'       => $worklist,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

printf("locales      : %s\n", implode(' ', $locales));
printf("chunks       : %d\n", count($worklist));
printf("keys         : %s\n", number_format($totalKeys));
printf("max/chunk    : %d\n", $max);
printf("out          : %s\n", $out);
if ($oversized) {
    printf("\noversized sections (own agent each, NOT split):\n");
    foreach (array_slice($oversized, 0, 12) as $o) {
        printf("  %-3s %-20s %-24s %d keys\n", $o['locale'], $o['ns'], $o['section'], $o['keys']);
    }
    if (count($oversized) > 12) printf("  ... and %d more\n", count($oversized) - 12);
}
printf("\nper-locale totals:\n");
$byLoc = [];
foreach ($worklist as $w) { $byLoc[$w['locale']]['k'] = ($byLoc[$w['locale']]['k'] ?? 0) + $w['keys']; $byLoc[$w['locale']]['c'] = ($byLoc[$w['locale']]['c'] ?? 0) + 1; }
foreach ($byLoc as $l => $t) printf("  %-3s %5s keys in %3d chunks\n", $l, number_format($t['k']), $t['c']);
