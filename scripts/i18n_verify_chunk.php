<?php
/**
 * Verify one translated chunk against its English source. THE LOAD-BEARING PART.
 *
 *   php scripts/i18n_verify_chunk.php <en.tsv> <translated.tsv>
 *   php scripts/i18n_verify_chunk.php <en.tsv> <translated.tsv> --quiet
 *   php scripts/i18n_verify_chunk.php --self-test
 *
 * Exit 0 = safe to merge. Exit 1 = do not merge. Exit 2 = bad usage.
 *
 * ──────────────────────────────────────────────────────────────────────────
 * WHY THIS IS THE MOST IMPORTANT FILE IN THE PIPELINE
 *
 * Nobody reads 69,000 translated strings, and nobody on this project reads
 * Malayalam. So every line is checked mechanically against its English source.
 * This is what makes it safe to let dozens of agents loose on a language the
 * maintainer cannot read.
 *
 * ⚠️ It checks STRUCTURE, never MEANING. A chunk that passes is safe to merge;
 * it is not certified as good Hindi. Those are different claims and this script
 * only makes the first one.
 * ──────────────────────────────────────────────────────────────────────────
 *
 * What it checks, in the order the problems actually matter:
 *
 *  1. KEY SET      every English key present, none invented, none duplicated
 *  2. KEY ORDER    same order as English — cheap, and a reordered chunk is a
 *                  sign the agent rewrote rather than translated
 *  3. PLACEHOLDERS identical SETS of {curly} tokens, and identical printf
 *                  tokens IN THE SAME ORDER. 🔴 printf substitutes BY POSITION,
 *                  so swapping %s and %d prints the count where the name goes.
 *                  This is the one that renders wrongly rather than falling
 *                  back, so nobody ever reports it.
 *  4. HTML         same tags in the same order
 *  5. NEWLINES     same count of literal \n
 *  6. EMPTY        a non-empty English value must not come back empty
 *  7. IDENTICAL    >25% byte-identical to English is flagged, not failed —
 *                  "Email", "OK" and product names legitimately do not change
 *
 * 🔑 SELF-TEST IT IN BOTH DIRECTIONS BEFORE TRUSTING IT. A verifier that has
 * never been shown to fail is decoration. `--self-test` builds a deliberately
 * broken chunk, asserts this script rejects it, then fixes it and asserts it
 * passes. Run it after any edit to this file.
 */

require __DIR__ . '/i18n_lib.php';

$args  = array_slice($argv, 1);
$quiet = in_array('--quiet', $args, true);
$args  = array_values(array_filter($args, function ($a) { return $a !== '--quiet'; }));

if (in_array('--self-test', $args, true)) { exit(i18nVerifySelfTest()); }

if (count($args) < 2) {
    fwrite(STDERR, "usage: i18n_verify_chunk.php <en.tsv> <translated.tsv> [--quiet]\n");
    fwrite(STDERR, "       i18n_verify_chunk.php --self-test\n");
    exit(2);
}

[$enPath, $trPath] = $args;
$res = i18nVerifyChunk($enPath, $trPath);

if (!$quiet || !$res['ok']) {
    foreach ($res['errors'] as $e)   echo "  FAIL  $e\n";
    foreach ($res['warnings'] as $w) echo "  warn  $w\n";
    printf("%s  %s  (%d keys)\n", $res['ok'] ? 'PASS' : 'FAIL', basename($trPath), $res['checked']);
}
exit($res['ok'] ? 0 : 1);


/**
 * @return array{ok:bool,errors:string[],warnings:string[],checked:int}
 */
function i18nVerifyChunk(string $enPath, string $trPath): array
{
    $errors = []; $warnings = [];

    if (!is_file($enPath)) return ['ok'=>false,'errors'=>["missing English source $enPath"],'warnings'=>[],'checked'=>0];
    if (!is_file($trPath)) return ['ok'=>false,'errors'=>["missing translation $trPath"],'warnings'=>[],'checked'=>0];

    $enProb = []; $trProb = [];
    $en = i18nReadTsv($enPath, $enProb);
    $tr = i18nReadTsv($trPath, $trProb);

    foreach ($enProb as $p) $errors[] = "English source malformed: $p";
    foreach ($trProb as $p) $errors[] = "translation malformed: $p";

    // 1. key set
    $missing = array_keys(array_diff_key($en, $tr));
    $extra   = array_keys(array_diff_key($tr, $en));
    if ($missing) $errors[] = count($missing) . ' key(s) not translated: ' . implode(', ', array_slice($missing, 0, 6));
    if ($extra)   $errors[] = count($extra) . ' key(s) invented: ' . implode(', ', array_slice($extra, 0, 6));

    // 2. key order (only meaningful when the sets match)
    if (!$missing && !$extra && array_keys($en) !== array_keys($tr)) {
        $errors[] = 'keys are in a different order than English';
    }

    $identical = 0; $checked = 0;
    foreach ($en as $k => $enV) {
        if (!isset($tr[$k])) continue;
        $trV = $tr[$k];
        $checked++;

        // 3. placeholders
        $ep = i18nPlaceholders($enV); $tp = i18nPlaceholders($trV);
        $ec = $ep['curly']; $tc = $tp['curly'];
        sort($ec); sort($tc);
        if ($ec !== $tc) {
            $errors[] = "$k: placeholders differ — English has [" . implode(' ', $ep['curly'])
                      . '] translation has [' . implode(' ', $tp['curly']) . ']';
        }
        if ($ep['printf'] !== $tp['printf']) {
            $errors[] = "$k: printf tokens differ or are reordered — English [" . implode(' ', $ep['printf'])
                      . '] translation [' . implode(' ', $tp['printf']) . '] (they substitute by POSITION)';
        }

        // 4. HTML
        $eh = i18nHtmlTags($enV); $th = i18nHtmlTags($trV);
        if ($eh !== $th) {
            $errors[] = "$k: HTML tags differ — English <" . implode('><', $eh) . '> translation <' . implode('><', $th) . '>';
        }

        // 5. newlines
        $en_nl = substr_count($enV, "\n"); $tr_nl = substr_count($trV, "\n");
        if ($en_nl !== $tr_nl) $errors[] = "$k: $en_nl line break(s) in English, $tr_nl in the translation";

        // 6. empty
        /* ⚠️ Guarded on English being non-empty. A blank column header is
           legitimately blank in every language, and flagging those was a false
           positive that cost real time the first time round. */
        if (trim($enV) !== '' && trim($trV) === '') $errors[] = "$k: English is not empty but the translation is";

        if ($enV === $trV && trim($enV) !== '') $identical++;
    }

    // 7. identical — a signal, never a failure
    if ($checked > 0) {
        $pct = round($identical / $checked * 100);
        if ($pct > 25) {
            $warnings[] = "$pct% of values came back byte-identical to English ($identical of $checked) — read a sample before merging";
        }
    }

    return ['ok' => !$errors, 'errors' => $errors, 'warnings' => $warnings, 'checked' => $checked];
}


/**
 * Prove the verifier can fail, and then pass. Both directions, every check.
 */
function i18nVerifySelfTest(): int
{
    $dir = sys_get_temp_dir() . '/i18n_selftest_' . getmypid();
    @mkdir($dir, 0777, true);
    $enP = "$dir/en.tsv";

    // English source exercising every rule at once.
    $en = [
        'a.greeting' => 'Hello {name}, you have {n} tickets',
        'a.counts'   => 'Imported %d of %s',
        'a.markup'   => 'See <strong>the guide</strong> for <em>details</em>',
        'a.lines'    => "First line\nSecond line",
        'a.blank'    => '',
        'a.brand'    => 'IMAP',
    ];
    i18nWriteTsv($enP, $en);

    $cases = [
        'a good translation'             => [['a.greeting'=>'Hola {name}, tienes {n} tickets','a.counts'=>'Importados %d de %s','a.markup'=>'Ver <strong>la guia</strong> para <em>detalles</em>','a.lines'=>"Primera linea\nSegunda linea",'a.blank'=>'','a.brand'=>'IMAP'], true],
        'a dropped {placeholder}'        => [['a.greeting'=>'Hola, tienes {n} tickets','a.counts'=>'Importados %d de %s','a.markup'=>'Ver <strong>la guia</strong> para <em>detalles</em>','a.lines'=>"Primera linea\nSegunda linea",'a.blank'=>'','a.brand'=>'IMAP'], false],
        'SWAPPED %d and %s'              => [['a.greeting'=>'Hola {name}, tienes {n} tickets','a.counts'=>'Importados %s de %d','a.markup'=>'Ver <strong>la guia</strong> para <em>detalles</em>','a.lines'=>"Primera linea\nSegunda linea",'a.blank'=>'','a.brand'=>'IMAP'], false],
        'a lost HTML tag'                => [['a.greeting'=>'Hola {name}, tienes {n} tickets','a.counts'=>'Importados %d de %s','a.markup'=>'Ver la guia para <em>detalles</em>','a.lines'=>"Primera linea\nSegunda linea",'a.blank'=>'','a.brand'=>'IMAP'], false],
        'a lost line break'              => [['a.greeting'=>'Hola {name}, tienes {n} tickets','a.counts'=>'Importados %d de %s','a.markup'=>'Ver <strong>la guia</strong> para <em>detalles</em>','a.lines'=>'Primera linea Segunda linea','a.blank'=>'','a.brand'=>'IMAP'], false],
        'a missing key'                  => [['a.greeting'=>'Hola {name}, tienes {n} tickets','a.counts'=>'Importados %d de %s','a.markup'=>'Ver <strong>la guia</strong> para <em>detalles</em>','a.lines'=>"Primera linea\nSegunda linea",'a.blank'=>''], false],
        'an invented key'                => [['a.greeting'=>'Hola {name}, tienes {n} tickets','a.counts'=>'Importados %d de %s','a.markup'=>'Ver <strong>la guia</strong> para <em>detalles</em>','a.lines'=>"Primera linea\nSegunda linea",'a.blank'=>'','a.brand'=>'IMAP','a.extra'=>'nuevo'], false],
        'reordered keys'                 => [['a.counts'=>'Importados %d de %s','a.greeting'=>'Hola {name}, tienes {n} tickets','a.markup'=>'Ver <strong>la guia</strong> para <em>detalles</em>','a.lines'=>"Primera linea\nSegunda linea",'a.blank'=>'','a.brand'=>'IMAP'], false],
        'a blanked value'                => [['a.greeting'=>'','a.counts'=>'Importados %d de %s','a.markup'=>'Ver <strong>la guia</strong> para <em>detalles</em>','a.lines'=>"Primera linea\nSegunda linea",'a.blank'=>'','a.brand'=>'IMAP'], false],
    ];

    $pass = 0; $fail = 0;
    foreach ($cases as $label => [$rows, $expectOk]) {
        $p = "$dir/tr.tsv";
        i18nWriteTsv($p, $rows);
        $r = i18nVerifyChunk($enP, $p);
        $ok = ($r['ok'] === $expectOk);
        printf("  %-4s %-34s expected %s, got %s%s\n",
            $ok ? 'ok' : 'BAD', $label,
            $expectOk ? 'PASS' : 'FAIL', $r['ok'] ? 'PASS' : 'FAIL',
            (!$ok && $r['errors']) ? '   [' . $r['errors'][0] . ']' : '');
        $ok ? $pass++ : $fail++;
    }

    // The no-tab case needs a raw file, since i18nWriteTsv always writes one.
    file_put_contents("$dir/notab.tsv", "a.greeting\tHola {name}, tienes {n} tickets\na.blank\n");
    $r = i18nVerifyChunk($enP, "$dir/notab.tsv");
    $sawTab = false;
    foreach ($r['errors'] as $e) if (strpos($e, 'no tab') !== false) $sawTab = true;
    printf("  %-4s %-34s %s\n", $sawTab ? 'ok' : 'BAD', 'a key with no tab at all',
        $sawTab ? 'reported, not silently dropped' : 'NOT REPORTED');
    $sawTab ? $pass++ : $fail++;

    array_map('unlink', glob("$dir/*"));
    @rmdir($dir);

    printf("\n%d passed, %d failed\n", $pass, $fail);
    if ($fail) { echo "🔴 THE VERIFIER IS NOT TRUSTWORTHY. Do not run a fan-out until this is green.\n"; return 1; }
    echo "The verifier fails what it should fail and passes what it should pass.\n";
    return 0;
}
