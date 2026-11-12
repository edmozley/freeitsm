<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * A value passed as a string inside an inline on*="..." handler must go through jsArg().
 *
 *   php tests/inline-handler-strings.php
 *
 * Why it exists: the browser HTML-decodes an attribute BEFORE it runs it as script. So in
 *     onclick="deleteTeam(4, '${escapeHtml(name)}')"
 * the &#39; that escapeHtml() makes is a plain ' again when the JavaScript is read, and a name
 * like  x');alert(1);//  closes the string and runs. Adding .replace(/'/g, "\\'") after
 * escapeHtml() does nothing - there is no ' left for it to find. Fifty-eight handlers in 27
 * files did one or the other until 5.1.0; the same in PHP with '<?php echo htmlspecialchars(..) ?>'.
 * jsArg() (assets/js/i18n.js) writes a complete JSON string literal, entity-encoded:
 *     onclick="deleteTeam(4, ${jsArg(name)})"
 *
 * Part 1 reads files: no inline handler may wrap an escaped value in quotes. Control: the
 * scanner must flag the old shapes, or "none found" proves nothing.
 * Part 2 runs in headless Chrome: jsArg() delivers hostile values intact and runs nothing;
 * the old shape, given the same value, DOES run it (positive control - the harness can fail).
 */

chdir(__DIR__ . '/..');
$pass = 0; $fail = 0;
function check(string $what, bool $ok, string $detail = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  PASS  $what\n"; }
    else { $fail++; echo "  FAIL  $what" . ($detail !== '' ? "\n        $detail" : '') . "\n"; }
}

/** Escape helpers that end in &#39; - any of them, quoted inside a handler, is the bug. */
const HELPERS = '(?:escapeHtml|escHtml|esc|escapeAttr|escAttr|escapeHtmlAttr|wfEsc|_esc|escapeHTML|htmlEsc)';

/**
 * Lines that put an escaped value inside quotes inside an inline handler.
 *
 * @return int[] 1-based line numbers
 */
function unsafeHandlerLines(string $src): array {
    $out = [];
    foreach (preg_split('/\r\n|\n/', $src) as $i => $line) {
        if (preg_match('#^\s*(\*|//)#', $line)) continue;   // a comment describing the bug, not the bug
        if (!preg_match('/\bon[a-z]+\s*=\s*\\\\?["\']/', $line)) continue;
        if (preg_match("/'\\$\\{\\s*" . HELPERS . "\\(/", $line)                       // '${escapeHtml(x)}'
            || preg_match("/\\\\'\\s*'?\\s*\\+\\s*" . HELPERS . "\\(/", $line)               // \'' + esc(x) + '\'
            || preg_match("/'<\\?(?:php\\s+echo|=)\\s*htmlspecialchars\\(/", $line)) { // '<?php echo htmlspecialchars(
            $out[] = $i + 1;
        }
    }
    return $out;
}

echo "\n-- Part 1: no quoted escaped value inside an inline handler --\n";

$controls = [
    "<button onclick=\"del(1, '\${escapeHtml(n)}')\">",
    "<button onclick=\"del(1, '\${escapeHtml(n).replace(/'/g, \"\\\\'\")}')\">",
    "html += '<a onclick=\"go(\\'' + esc(x) + '\\')\">';",
    "<a onclick=\"pick('<" . "?php echo htmlspecialchars(\$k); ?" . ">')\">",
];
foreach ($controls as $i => $c) check('control ' . ($i + 1) . ': the scanner flags the old shape', unsafeHandlerLines($c) === [1], $c);
check('control: the new shape is not flagged', unsafeHandlerLines("<button onclick=\"del(1, \${jsArg(n)})\">") === []);

$found = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator('.', FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    $p = str_replace('\\', '/', substr($f->getPathname(), 2));
    if (!preg_match('/\.(js|php|html)$/', $p)) continue;
    if (preg_match('#^(vendor|engineering-wiki|lang|tests|node_modules|local|\.git)/#', $p) || str_contains($p, '/vendor/')) continue;
    foreach (unsafeHandlerLines(file_get_contents($f->getPathname())) as $ln) $found[] = "$p:$ln";
}
check('no inline handler wraps an escaped value in quotes (' . count($found) . ' found)', $found === [],
    implode("\n        ", array_slice($found, 0, 30)) . "\n        Use \${jsArg(value)} with no quotes around it - see assets/js/i18n.js.");

echo "\n-- Part 2: in a real browser --\n";

$chrome = null;
foreach (['C:\Program Files\Google\Chrome\Application\chrome.exe', 'C:\Program Files (x86)\Google\Chrome\Application\chrome.exe',
          '/usr/bin/google-chrome', '/usr/bin/chromium', '/usr/bin/chromium-browser'] as $c) {
    if (is_file($c)) { $chrome = $c; break; }
}
if ($chrome === null) {
    echo "  SKIP  Chrome not found - the browser half was NOT exercised\n";
} else {
    $tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'freeitsm_jsarg_' . getmypid();
    @mkdir($tmp . '/profile', 0777, true);
    $values = [
        "x');window.PWNED=1;//",
        "x\");window.PWNED=1;//",
        "O'Brien & \"Sons\" <b>bold</b>",
        "back\\slash \\' and \\\"",
        "Zoë 😀 &amp; &#39;",
        "</script><script>window.PWNED=1</script>",
    ];
    // The 3.14.0 escapeHtml, as every page has it.
    $esc = "function escapeHtml(t){var d=document.createElement('div');d.textContent=t==null?'':String(t);"
         . "return d.innerHTML.replace(/\"/g,'&quot;').replace(/'/g,'&#39;');}";
    $html = "<!doctype html><html><head><meta charset=\"utf-8\"></head><body><div id=\"host\"></div><pre id=\"out\"></pre><script>\n"
          . file_get_contents('assets/js/i18n.js') . "\n" . $esc . "\n"
          . "window.got = []; function take(v){ window.got.push(v); }\n"
          . "var vals = " . json_encode($values, JSON_UNESCAPED_UNICODE) . ";\n"
          . "var res = {safe: [], safePwned: false, old: [], oldPwned: false, oldReplacePwned: false};\n"
          . "var host = document.getElementById('host');\n"
          // jsArg, as the fixed handlers use it
          . "vals.forEach(function (v) { window.PWNED = 0; window.got = [];\n"
          . "  host.innerHTML = '<button onclick=\"take(' + jsArg(v) + ')\">b</button>'; host.firstChild.click();\n"
          . "  res.safe.push(window.got.length === 1 && window.got[0] === v); if (window.PWNED) res.safePwned = true; });\n"
          // the old shape, positive control
          . "window.PWNED = 0; host.innerHTML = '<button onclick=\"take(\\'' + escapeHtml(vals[0]) + '\\')\">b</button>'; host.firstChild.click(); res.oldPwned = !!window.PWNED;\n"
          . "window.PWNED = 0; host.innerHTML = '<button onclick=\"take(\\'' + escapeHtml(vals[0]).replace(/'/g, \"\\\\'\") + '\\')\">b</button>'; host.firstChild.click(); res.oldReplacePwned = !!window.PWNED;\n"
          . "res.nullIsEmpty = jsArg(null) === '&quot;&quot;' && jsArg(undefined) === '&quot;&quot;';\n"
          . "document.getElementById('out').textContent = 'RESULTS:' + JSON.stringify(res);\n"
          . "</script></body></html>";
    file_put_contents($tmp . '/harness.html', $html);
    $cmd = '"' . $chrome . '" --headless --disable-gpu --no-sandbox --no-first-run --user-data-dir="' . $tmp . '/profile" --dump-dom '
         . '--virtual-time-budget=2000 "file:///' . str_replace('\\', '/', $tmp) . '/harness.html" 2>&1';
    $dom = (string) shell_exec($cmd);
    if (!preg_match('/RESULTS:(\{.*?\})\s*</s', html_entity_decode($dom), $m)) {
        check('the harness produced a result at all', false, 'no RESULTS marker - the script probably failed to parse');
    } else {
        $r = json_decode($m[1], true);
        foreach ($values as $i => $v) check('jsArg delivers the value intact: ' . $v, !empty($r['safe'][$i]));
        check('jsArg ran none of the injected code', empty($r['safePwned']));
        check('jsArg(null) and jsArg(undefined) are an empty string', !empty($r['nullIsEmpty']));
        check("positive control: '\${escapeHtml(x)}' in a handler DOES run injected code", !empty($r['oldPwned']));
        check("positive control: adding .replace(/'/g, \"\\\\'\") does not stop it", !empty($r['oldReplacePwned']));
    }
    @unlink($tmp . '/harness.html');
    // Chrome may still hold the profile for a moment; leave it if it will not go.
    @exec('rmdir /s /q "' . $tmp . '" 2>nul');
}

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
