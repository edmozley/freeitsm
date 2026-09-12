<?php
/**
 * Shared helpers for the translation pipeline.
 *
 * The pipeline exists because English keeps moving and 24 locales do not follow
 * it. A missing key falls back to English and renders perfectly, so a
 * half-translated module looks finished until somebody who reads that language
 * opens it. `scripts/i18n_audit.php` finds the gap; these scripts fill it.
 *
 * ──────────────────────────────────────────────────────────────────────────
 * THE SPLIT OF RESPONSIBILITY IS THE WHOLE DESIGN
 *
 *   Translating agents READ English and WRITE A TSV. They never touch a file
 *   under lang/. A bad agent therefore cannot damage anything.
 *
 *   The orchestrator verifies mechanically, then merges. Every destructive
 *   step stays under one pair of hands.
 * ──────────────────────────────────────────────────────────────────────────
 *
 * 🔴 WHY A MERGE TOOL EXISTS AT ALL, rather than writing whole files:
 * a locale file that already holds 358 of 2,049 keys is PARTIAL, not absent.
 * Writing it wholesale discards what is there. And appending a second
 * `'section' => [...]` to a PHP array REPLACES the first — silently deleting
 * every translation in it — and still passes `php -l`. So merging is done by
 * parsing, deep-merging in memory, and re-emitting the whole file.
 *
 * ⚠️ TSV, not JSON or CSV: a translated UI string is full of apostrophes and
 * commas and the odd quote. Tab is the one character that does not occur in any
 * of the 12,523 English keys or values (verified), so it is the only separator
 * that needs no quoting rules for an agent to get wrong.
 */

/** Keys are flattened on dots. Verified: no English key contains a dot or a tab. */
const I18N_SEP = '.';

/**
 * Flatten a nested lang array to dotted key => string value.
 *
 * @return array<string,string>
 */
function i18nFlatten(array $a, string $prefix = ''): array
{
    $out = [];
    foreach ($a as $k => $v) {
        $key = $prefix === '' ? (string)$k : $prefix . I18N_SEP . $k;
        if (is_array($v)) {
            foreach (i18nFlatten($v, $key) as $kk => $vv) $out[$kk] = $vv;
        } else {
            $out[$key] = (string)$v;
        }
    }
    return $out;
}

/**
 * Rebuild a nested array from dotted keys.
 *
 * ⚠️ Insertion order is preserved, which is what lets the emitted file keep
 * English's key order — the verifier checks order, so this has to be stable.
 */
function i18nUnflatten(array $flat): array
{
    $out = [];
    foreach ($flat as $dotted => $val) {
        $parts = explode(I18N_SEP, $dotted);
        $ref = &$out;
        foreach ($parts as $i => $p) {
            if ($i === count($parts) - 1) { $ref[$p] = $val; break; }
            if (!isset($ref[$p]) || !is_array($ref[$p])) $ref[$p] = [];
            $ref = &$ref[$p];
        }
        unset($ref);
    }
    return $out;
}

/**
 * Escape a value for one TSV cell.
 *
 * 13 English values contain a real newline. A raw newline would end the record,
 * so it travels as a literal backslash-n and the verifier counts them on both
 * sides — a lost line break changes the rendering of a multi-line hint.
 */
function i18nTsvEscape(string $s): string
{
    return str_replace(["\\", "\r\n", "\n", "\t"], ['\\\\', '\\n', '\\n', ' '], $s);
}

/** Inverse of i18nTsvEscape(). */
function i18nTsvUnescape(string $s): string
{
    // Order matters: decode \\ last so "\\n" does not become a newline.
    $s = str_replace('\\n', "\n", $s);
    return str_replace('\\\\', '\\', $s);
}

/** Read a 2-column TSV into key => value, reporting malformed lines. */
function i18nReadTsv(string $path, array &$problems = []): array
{
    $out = [];
    $fh = fopen($path, 'rb');
    if (!$fh) { $problems[] = "cannot open $path"; return $out; }
    $n = 0;
    while (($line = fgets($fh)) !== false) {
        $n++;
        $line = rtrim($line, "\r\n");
        if ($line === '') continue;
        if (strpos($line, "\t") === false) {
            /* ⚠️ THE FAILURE MODE THAT ACTUALLY HAPPENS. A key whose English is
               an empty string looks like it needs nothing after the tab, so the
               tab gets dropped — and a merge tool that split on tab would bin
               the line without a word. Both real cases found in the German run
               were exactly this. Reported, never guessed at. */
            $problems[] = "line $n has no tab: " . substr($line, 0, 60);
            continue;
        }
        [$k, $v] = explode("\t", $line, 2);
        $k = trim($k);
        if ($k === '') { $problems[] = "line $n has an empty key"; continue; }
        if (isset($out[$k])) $problems[] = "line $n duplicates key $k";
        $out[$k] = i18nTsvUnescape($v);
    }
    fclose($fh);
    return $out;
}

/** Write key => value as TSV. */
function i18nWriteTsv(string $path, array $rows): int
{
    $buf = '';
    foreach ($rows as $k => $v) $buf .= $k . "\t" . i18nTsvEscape((string)$v) . "\n";
    @mkdir(dirname($path), 0777, true);
    file_put_contents($path, $buf);
    return count($rows);
}

/** The placeholder tokens in a string: {name} style and printf style, in order. */
function i18nPlaceholders(string $s): array
{
    $curly = [];
    if (preg_match_all('/\{[a-zA-Z0-9_]+\}/', $s, $m)) $curly = $m[0];
    $printf = [];
    // %s %d %1$s %02d — the conversion, as written, in the order it appears.
    if (preg_match_all('/%(?:\d+\$)?[-+ 0#\']*[0-9]*(?:\.\d+)?[bcdeEfFgGosuxX%]/', $s, $m)) {
        $printf = array_values(array_filter($m[0], function ($t) { return $t !== '%%'; }));
    }
    return ['curly' => $curly, 'printf' => $printf];
}

/** The HTML tag names in a string, in order of appearance. */
function i18nHtmlTags(string $s): array
{
    $tags = [];
    if (preg_match_all('#</?([a-zA-Z][a-zA-Z0-9]*)\b#', $s, $m)) $tags = array_map('strtolower', $m[1]);
    return $tags;
}

/** Emit a lang array as a PHP file, stable and readable. */
function i18nEmitPhpFile(array $tree, string $header): string
{
    $out = "<?php\n" . $header . "\nreturn " . i18nExport($tree, 0) . ";\n";
    return $out;
}

/** var_export with the house style: short arrays, single quotes, 4-space indent. */
function i18nExport($v, int $depth): string
{
    $pad  = str_repeat('    ', $depth + 1);
    $padC = str_repeat('    ', $depth);
    if (!is_array($v)) {
        return "'" . str_replace(['\\', "'"], ['\\\\', "\\'"], (string)$v) . "'";
    }
    if ($v === []) return '[]';
    $lines = [];
    foreach ($v as $k => $vv) {
        $key = is_int($k) ? $k : "'" . str_replace(['\\', "'"], ['\\\\', "\\'"], (string)$k) . "'";
        $lines[] = $pad . $key . ' => ' . i18nExport($vv, $depth + 1) . ',';
    }
    return "[\n" . implode("\n", $lines) . "\n" . $padC . ']';
}

/** Load a lang file, or [] when it does not exist. */
function i18nLoad(string $path): array
{
    if (!is_file($path)) return [];
    $v = require $path;
    return is_array($v) ? $v : [];
}
