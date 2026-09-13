<?php
/**
 * Merge a verified chunk into a locale file. THE ONLY SCRIPT THAT WRITES TO lang/.
 *
 *   php scripts/i18n_merge.php hi tickets chunks/hi__tickets__list.hi.tsv
 *   php scripts/i18n_merge.php hi tickets <tsv> --dry-run
 *   php scripts/i18n_merge.php --worklist .i18n-work        merge everything verified
 *
 * 🔴 IT NEVER OVERWRITES AN EXISTING VALUE. A locale file holding 358 of 2,049
 * keys is PARTIAL, not absent, and those 358 may have been reviewed by somebody
 * who reads the language. Re-running this therefore only ever fills gaps, which
 * also makes the whole pipeline safe to repeat after a failure.
 *
 * 🔴 WHY THIS PARSES AND RE-EMITS RATHER THAN APPENDING. Appending a second
 * `'section' => [...]` to a PHP array REPLACES the first — silently deleting
 * every translation in it — and the file still passes `php -l`. There is no
 * textual append that is safe, so the whole file is rebuilt from a merged tree.
 *
 * ⚠️ Key ORDER follows English, so a translated file reads alongside the source.
 * Keys the locale has that English no longer has are KEPT and reported; pruning
 * them is a separate, deliberate act, not a side effect of filling a gap.
 *
 * Every merge proves itself non-destructive before writing: the pre-existing
 * tree is flattened, and every one of its keys must be byte-identical in the
 * result. If one is not, nothing is written.
 */

require __DIR__ . '/i18n_lib.php';

$root = dirname(__DIR__);
chdir($root);

$args   = array_slice($argv, 1);
$dryRun = in_array('--dry-run', $args, true);
$args   = array_values(array_filter($args, function ($a) { return $a !== '--dry-run'; }));

if (($args[0] ?? '') === '--worklist') {
    exit(i18nMergeWorklist($args[1] ?? '.i18n-work', $dryRun));
}

if (count($args) < 3) {
    fwrite(STDERR, "usage: i18n_merge.php <locale> <namespace> <tsv> [--dry-run]\n");
    fwrite(STDERR, "       i18n_merge.php --worklist <dir> [--dry-run]\n");
    exit(2);
}

[$loc, $ns, $tsv] = $args;
$r = i18nMergeOne($loc, $ns, $tsv, $dryRun);
foreach ($r['messages'] as $m) echo "  $m\n";
printf("%s  %s/%s  +%d key(s)\n", $r['ok'] ? ($dryRun ? 'DRY' : 'MERGED') : 'REFUSED', $loc, $ns, $r['added']);
exit($r['ok'] ? 0 : 1);


function i18nMergeOne(string $loc, string $ns, string $tsvPath, bool $dryRun): array
{
    $msgs = [];
    $enPath  = "lang/en/$ns.php";
    $locPath = "lang/$loc/$ns.php";

    if (!is_file($enPath))  return ['ok'=>false,'added'=>0,'messages'=>["no English source $enPath"]];
    if (!is_dir("lang/$loc")) return ['ok'=>false,'added'=>0,'messages'=>["no locale dir lang/$loc"]];
    if (!is_file($tsvPath)) return ['ok'=>false,'added'=>0,'messages'=>["no such TSV $tsvPath"]];

    $enFlat   = i18nFlatten(i18nLoad($enPath));
    $haveFlat = i18nFlatten(i18nLoad($locPath));

    $problems = [];
    $newFlat  = i18nReadTsv($tsvPath, $problems);
    foreach ($problems as $p) $msgs[] = "TSV problem: $p";
    if ($problems) return ['ok'=>false,'added'=>0,'messages'=>$msgs];

    // Only keys English actually has, and only ones not already translated.
    $added = 0; $skippedExisting = 0; $unknown = 0;
    $result = [];
    foreach ($enFlat as $k => $_) {
        if (array_key_exists($k, $haveFlat)) { $result[$k] = $haveFlat[$k]; continue; }
        if (array_key_exists($k, $newFlat))  { $result[$k] = $newFlat[$k]; $added++; }
        // else: left out entirely, so it falls back to English at runtime.
    }
    foreach ($newFlat as $k => $_) {
        if (!array_key_exists($k, $enFlat))   { $unknown++; continue; }
        if (array_key_exists($k, $haveFlat))  { $skippedExisting++; }
    }

    // Keys this locale has that English no longer does — kept, not pruned here.
    $extras = array_diff_key($haveFlat, $enFlat);
    foreach ($extras as $k => $v) $result[$k] = $v;

    if ($unknown)         $msgs[] = "$unknown key(s) in the TSV are not in English — ignored";
    if ($skippedExisting) $msgs[] = "$skippedExisting key(s) already translated — left alone";
    if ($extras)          $msgs[] = count($extras) . " key(s) English no longer has — kept (prune separately)";

    /* ── the non-destructive proof, before anything is written ───────────── */
    foreach ($haveFlat as $k => $v) {
        if (!array_key_exists($k, $result)) {
            return ['ok'=>false,'added'=>0,'messages'=>array_merge($msgs, ["🔴 REFUSING: existing key $k would be lost"])];
        }
        if ($result[$k] !== $v) {
            return ['ok'=>false,'added'=>0,'messages'=>array_merge($msgs, ["🔴 REFUSING: existing key $k would change value"])];
        }
    }

    if ($added === 0) { $msgs[] = 'nothing to add'; return ['ok'=>true,'added'=>0,'messages'=>$msgs]; }

    $tree   = i18nUnflatten($result);
    $header = i18nMergeHeader($loc, $ns);
    $php    = i18nEmitPhpFile($tree, $header);

    // It must parse, and it must load back to exactly the tree we intended.
    $tmp = tempnam(sys_get_temp_dir(), 'i18n') . '.php';
    file_put_contents($tmp, $php);
    exec(sprintf('%s -l %s 2>&1', escapeshellarg(PHP_BINARY), escapeshellarg($tmp)), $lintOut, $lintCode);
    if ($lintCode !== 0) {
        @unlink($tmp);
        return ['ok'=>false,'added'=>0,'messages'=>array_merge($msgs, ['🔴 REFUSING: emitted PHP does not parse: ' . implode(' ', $lintOut)])];
    }
    $roundTrip = i18nFlatten(i18nLoad($tmp));
    @unlink($tmp);
    /* ⚠️ Compare CONTENT, not order. `$roundTrip !== $result` also compares key
       order, and the two legitimately differ: keys English no longer has are
       appended to the end of the working list, but re-emitting nests them back
       into their own section, which may sit earlier in the file. That is
       correct — and comparing with !== rejected the two locales that happen to
       carry extra keys, with 0 keys lost and 0 values changed. */
    $lost   = array_diff_key($result, $roundTrip);
    $gained = array_diff_key($roundTrip, $result);
    $changed = [];
    foreach ($result as $k => $v) {
        if (array_key_exists($k, $roundTrip) && $roundTrip[$k] !== $v) $changed[] = $k;
    }
    if ($lost || $gained || $changed) {
        $why = [];
        if ($lost)    $why[] = count($lost) . ' key(s) lost: ' . implode(', ', array_slice(array_keys($lost), 0, 4));
        if ($gained)  $why[] = count($gained) . ' key(s) appeared: ' . implode(', ', array_slice(array_keys($gained), 0, 4));
        if ($changed) $why[] = count($changed) . ' value(s) changed: ' . implode(', ', array_slice($changed, 0, 4));
        return ['ok'=>false,'added'=>0,'messages'=>array_merge($msgs,
            ['🔴 REFUSING: emitted file does not read back with the same content — ' . implode('; ', $why)])];
    }

    if ($dryRun) { $msgs[] = 'dry run — nothing written'; return ['ok'=>true,'added'=>$added,'messages'=>$msgs]; }

    file_put_contents($locPath, $php);
    return ['ok'=>true,'added'=>$added,'messages'=>$msgs];
}

function i18nMergeHeader(string $loc, string $ns): string
{
    return "/**\n"
         . " * FreeITSM — $ns strings ($loc).\n"
         . " *\n"
         . " * Keys mirror lang/en/$ns.php exactly. A key absent here falls back to\n"
         . " * English at runtime, so this file may be incomplete without breaking\n"
         . " * anything. Check coverage with: php scripts/i18n_audit.php $loc\n"
         . " *\n"
         . " * ⚠️ Placeholders like {name} and %d are substituted at runtime — printf\n"
         . " * tokens substitute BY POSITION, so their order must match English.\n"
         . " */\n";
}

function i18nMergeWorklist(string $dir, bool $dryRun): int
{
    $wlPath = rtrim($dir, '/\\') . '/_worklist.json';
    if (!is_file($wlPath)) { fwrite(STDERR, "no worklist at $wlPath\n"); return 2; }
    $wl = json_decode(file_get_contents($wlPath), true);
    if (!is_array($wl) || empty($wl['items'])) { fwrite(STDERR, "worklist is empty or unreadable\n"); return 2; }

    $merged = 0; $added = 0; $skipped = 0; $refused = [];
    foreach ($wl['items'] as $it) {
        $out = rtrim($dir, '/\\') . '/' . $it['out_tsv'];
        $en  = rtrim($dir, '/\\') . '/' . $it['en_tsv'];
        if (!is_file($out)) { $skipped++; continue; }               // agent has not delivered

        // Never merge what the verifier has not passed.
        exec(sprintf('%s %s %s %s --quiet',
            escapeshellarg(PHP_BINARY),
            escapeshellarg(__DIR__ . '/i18n_verify_chunk.php'),
            escapeshellarg($en), escapeshellarg($out)), $vo, $vc);
        if ($vc !== 0) { $refused[] = $it['out_tsv'] . ' (failed verification)'; continue; }

        $r = i18nMergeOne($it['locale'], $it['namespace'], $out, $dryRun);
        if (!$r['ok']) { $refused[] = $it['out_tsv'] . ' — ' . implode('; ', $r['messages']); continue; }
        $merged++; $added += $r['added'];
    }

    printf("%s: %d chunk(s) merged, %s key(s) added, %d awaiting an agent, %d refused\n",
        $dryRun ? 'DRY RUN' : 'MERGE', $merged, number_format($added), $skipped, count($refused));
    foreach (array_slice($refused, 0, 15) as $r) echo "  refused: $r\n";
    return $refused ? 1 : 0;
}
