<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * Every DOM-based HTML escape helper must escape quotes.
 *
 *   php tests/escape-helpers-quotes.php
 *
 * Why it exists: the usual JavaScript helper - put the text in a div's textContent, read back
 * innerHTML - escapes < > and &, but NOT " or '. The result is safe between tags and unsafe
 * inside an attribute: value="${escapeHtml(label)}" with a label containing " ends the
 * attribute and lets the rest of the label add its own. FreeITSM had 107 such helpers (escapeHtml,
 * esc, escHtml and a dozen local variants) and many of them fed attributes. All now finish
 * with .replace(/"/g, '&quot;').replace(/'/g, '&#39;'). A new page that copies the old
 * three-line helper fails here.
 *
 * Reads files only. Control: the scanner must flag the old helper, or "none found" proves nothing.
 */

chdir(__DIR__ . '/..');

/**
 * The unsafe helpers in some source text: `return X.innerHTML;` (nothing after it) where
 * X.textContent or X.innerText was set in the six lines before.
 *
 * @return int[] 1-based line numbers
 */
function unsafeHelpers(string $src): array {
    $lines = preg_split('/\r\n|\n/', $src);
    $out = [];
    foreach ($lines as $i => $line) {
        if (!preg_match_all('/return\s+([A-Za-z_$][\w$]*)\.innerHTML\s*;/', $line, $m, PREG_OFFSET_CAPTURE)) continue;
        foreach ($m[1] as $k => $v) {
            $ctx = substr($line, 0, $m[0][$k][1]);
            for ($j = $i - 1; $j >= 0 && $j >= $i - 6; $j--) $ctx = $lines[$j] . "\n" . $ctx;
            if (preg_match('/\b' . preg_quote($v[0], '/') . '\.(textContent|innerText)\s*=/', $ctx)) $out[] = $i + 1;
        }
    }
    return $out;
}

$pass = 0; $fail = 0;
function ok(string $what, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  PASS  {$what}\n"; }
    else       { $fail++; echo "  FAIL  {$what}" . ($detail ? "\n        {$detail}" : '') . "\n"; }
}

ok('control: the old three-line helper is flagged',
   unsafeHelpers("function escapeHtml(t) {\n  const div = document.createElement('div');\n  div.textContent = t;\n  return div.innerHTML;\n}") === [4]);
ok('control: the fixed helper is not',
   unsafeHelpers("function esc(t) { const d = document.createElement('div'); d.textContent = t; return d.innerHTML.replace(/\"/g, '&quot;').replace(/'/g, '&#39;'); }") === []);

$found = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator('.', FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    $p = str_replace(DIRECTORY_SEPARATOR, '/', $f->getPathname());
    if (!preg_match('/\.(php|js|html)$/', $p) || preg_match('#/(vendor|node_modules|\.git|tests)/#', $p)) continue;
    $src = file_get_contents($p);
    if (!str_contains($src, 'innerHTML')) continue;
    foreach (unsafeHelpers($src) as $line) $found[] = ltrim($p, './') . ':' . $line;
}
ok('no escape helper leaves quotes unescaped', !$found, implode("\n        ", $found));

/*
 * A script that CALLS esc() must define it or import it (#260). widget-editor.js called esc()
 * from 2.5.0 to 4.10.0 but only defined escapeHtml(), so the dashboard cog died with
 * "esc is not defined". Most scripts import it: `esc = P.esc`, `{ T, esc } = window.Dom`.
 */
function callsEscUndefined(string $src): bool {
    if (!preg_match('/(?<![\w$.])esc\s*\(/', $src)) return false;
    return !preg_match('/function\s+esc\s*\(|(?<![\w$.])esc\s*=[^=]|[{,]\s*esc\s*[,}]/', $src);
}
ok('control: esc() called but never defined is flagged',
   callsEscUndefined("(function () {\n  function escapeHtml(s) { return s; }\n  x.innerHTML = esc(label);\n})();"));
ok('control: esc imported from a shared module is not',
   !callsEscUndefined("const T = P.T, esc = P.esc;\nx.innerHTML = esc(label);")
   && !callsEscUndefined("const { T, esc, api } = window.Dom;\nx.innerHTML = esc(label);"));
$undef = [];
foreach (glob('assets/js/*.js') as $p) {
    if (str_ends_with($p, '.min.js')) continue;
    if (callsEscUndefined(file_get_contents($p))) $undef[] = $p;
}
ok('no script calls esc() without defining or importing it', $undef === [], implode(', ', $undef));

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail ? 1 : 0);
