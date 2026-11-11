<?php
/* 🔴 COMMAND LINE ONLY - see scripts/.htaccess. Over HTTP this would run for anyone, unauthenticated. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * Pack a locale's chunks into bundles one translator can do, so a dozen small
 * screens do not each cost an agent re-reading the brief.
 *
 *   php scripts/i18n_pack.php <locale> [--out DIR]
 *
 * DIR is where i18n_chunk.php wrote its _worklist.json (default .i18n-work).
 * Writes there:
 *   bundles.<loc>.json       the packing
 *   bundle.<loc>.NN.txt      one manifest per bundle: `<english chunk> -> <output file>` per line,
 *                            which is all a translator's prompt needs to point at
 *
 * Budget = the chunker's own per-locale English-byte budget (40,000 scaled by
 * the locale's measured script expansion - see EXPANSION in i18n_chunk.php).
 * Chunks are taken namespace by namespace, so a bundle is mostly one module;
 * it closes when the next chunk would take it over budget. A chunk already over
 * budget gets a bundle to itself.
 */
chdir(dirname(__DIR__));
$out = dirname(__DIR__) . '/.i18n-work';
$loc = '';
for ($i = 1; $i < count($argv); $i++) {
    if ($argv[$i] === '--out') { $out = rtrim($argv[++$i], '/\\'); continue; }
    $loc = $argv[$i];
}
if ($loc === '' || !is_file("$out/_worklist.json")) {
    fwrite(STDERR, "usage: i18n_pack.php <locale> [--out DIR]   (DIR must hold _worklist.json from i18n_chunk.php)\n");
    exit(2);
}
$wl = json_decode(file_get_contents("$out/_worklist.json"), true);
$items = array_values(array_filter($wl['items'], fn($it) => $it['locale'] === $loc));
if (!$items) { fwrite(STDERR, "no chunks for $loc in $out\n"); exit(1); }
// The same table as i18n_chunk.php; keep the two in step.
$exp = ['ml' => 2.42, 'gu' => 2.04, 'kn' => 2.40, 'ta' => 2.40, 'te' => 2.40, 'bn' => 2.40, 'pa' => 2.40,
        'mr' => 2.40, 'hi' => 2.40, 'uk' => 1.64, 'ru' => 1.64, 'ms' => 1.12, 'nb' => 1.04, 'el' => 2.18];
$budget = (int) round(($wl['max_bytes'] ?? 40000) * (1.18 / ($exp[$loc] ?? 1.18)));
usort($items, fn($a, $b) => strcmp($a['namespace'], $b['namespace']) ?: strcmp($a['section'], $b['section']));
$bundles = [];
$cur = ['bytes' => 0, 'keys' => 0, 'chunks' => []];
foreach ($items as $it) {
    if ($cur['chunks'] && $cur['bytes'] + $it['bytes'] > $budget) {
        $bundles[] = $cur;
        $cur = ['bytes' => 0, 'keys' => 0, 'chunks' => []];
    }
    $cur['bytes'] += $it['bytes'];
    $cur['keys']  += $it['keys'];
    $cur['chunks'][] = ['en' => basename($it['en_tsv']), 'out' => basename($it['out_tsv']),
                        'ns' => $it['namespace'], 'keys' => $it['keys'], 'bytes' => $it['bytes']];
}
if ($cur['chunks']) $bundles[] = $cur;
foreach ($bundles as $i => $b) {
    $n  = sprintf('%02d', $i + 1);
    $ns = array_unique(array_column($b['chunks'], 'ns'));
    printf("bundle %s  %6d bytes %4d keys  %2d chunks  %s\n", $n, $b['bytes'], $b['keys'], count($b['chunks']), implode(',', $ns));
    $txt = "# bundle $n for $loc - translate each English chunk into the output file beside it\n";
    foreach ($b['chunks'] as $c) $txt .= "$out/chunks/{$c['en']}\t->\t$out/chunks/{$c['out']}\n";
    file_put_contents("$out/bundle.$loc.$n.txt", $txt);
}
printf("%s: %d bundles, budget %d bytes -> %s\n", $loc, count($bundles), $budget, $out);
file_put_contents("$out/bundles.$loc.json", json_encode(['locale' => $loc, 'budget' => $budget, 'bundles' => $bundles], JSON_PRETTY_PRINT));
