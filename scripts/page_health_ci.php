<?php
/**
 * Page health in CI - does every page's JavaScript parse? (GH #222)
 *
 *   php scripts/page_health_ci.php            check; exit 1 on any problem
 *   php scripts/page_health_ci.php --list     just print what would be checked
 *
 * The in-app check (System -> Debug Tools -> D019) opens every page as a signed-in
 * administrator and lets the browser read each script. CI has no database to sign
 * in to, so this reads the SOURCE instead, from the same page list
 * (includes/page_health.php):
 *
 *   - every inline <script> in every page, and in the shared templates and
 *     includes that write scripts into pages (the waffle menu, headers);
 *   - every JavaScript file the app ships (assets/js and the modules' own .js),
 *     except vendor libraries and minified files.
 *
 * PHP inside a script cannot be run here, so each <?php ... ?> block is replaced
 * by a stand-in of the SAME NUMBER OF LINES - so the line numbers in the report
 * are the lines of the real file:
 *   - a block that prints something (<?= or echo/print) becomes `null`, a value;
 *   - any other block (if/else/foreach) becomes a comment, so both branches stay
 *     and neither leaves a stray value inside an object or an argument list.
 *
 * Parsing needs a JavaScript engine: Node, which GitHub's runners have. Without
 * Node this says so and exits 0 - on a machine without it, run D019 instead.
 *
 * @wiki Page-Health-Developer-Guide
 */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$root = dirname(__DIR__);
require $root . '/includes/page_health.php';

// TRAP: a source file with a byte that is not valid UTF-8 made json_encode() return
// false - an EMPTY payload, so nothing was parsed and the run "passed". Substitute.
const PH_JSON = JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES;

/**
 * The file as the browser would get it, as far as can be known without running it:
 * the raw HTML kept, each PHP block replaced by a stand-in of the same height -
 * `null` if it prints anything (<?=, echo, print), a comment if it does not.
 *
 * TRAP: done with PHP's own tokenizer, not a regex. A regex cannot tell a
 * <script> in the HTML from one in a PHP docblock ("the tag that publishes...")
 * or in a PHP string, and it cannot see that `<?php require ...; echo x; ?>`
 * prints - both gave false alarms. Scripts PHP builds as strings (Tz::scriptTag)
 * are not checked here; D019 sees them, in the page the browser actually gets.
 */
function htmlView(string $src): string {
    $out = '';
    $block = null;   // [height, prints]
    foreach (token_get_all($src) as $tok) {
        [$id, $text] = is_array($tok) ? [$tok[0], $tok[1]] : [null, $tok];
        if ($block === null) {
            if ($id === T_INLINE_HTML) { $out .= $text; continue; }
            if ($id === T_OPEN_TAG || $id === T_OPEN_TAG_WITH_ECHO) {
                $block = [substr_count($text, "\n"), $id === T_OPEN_TAG_WITH_ECHO];
                continue;
            }
            $out .= $text;   // not expected outside a block, kept so lines stay true
            continue;
        }
        $block[0] += substr_count($text, "\n");
        if ($id === T_ECHO || $id === T_PRINT) $block[1] = true;
        if ($id === T_CLOSE_TAG) {
            $out .= ($block[1] ? 'null' : '/*php*/') . str_repeat("\n", $block[0]);
            $block = null;
        }
    }
    if ($block !== null) $out .= str_repeat("\n", $block[0]);   // a file that ends inside PHP
    return $out;
}

/** Every classic inline script in a file's HTML view: [code, first line]. */
function inlineScripts(string $src): array {
    $src = htmlView($src);
    $out = [];
    // HTML comments are matched and skipped (a comment can show an example tag);
    // a script that starts first is matched whole, so "<!--" inside code stays code.
    preg_match_all('/<!--[\s\S]*?-->|<script\b([^>]*)>([\s\S]*?)<\/script\s*>/i', $src, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
    foreach ($m as $s) {
        if (!isset($s[1]) || $s[1][1] < 0) continue;                       // a comment
        $attrs = $s[1][0];
        if (preg_match('/\bsrc\s*=/i', $attrs)) continue;
        if (preg_match('/\btype\s*=\s*["\']?([^"\'\s>]+)/i', $attrs, $t) && !preg_match('#^(text|application)/(javascript|ecmascript)$#i', $t[1])) continue;
        $code = $s[2][0];
        if (trim($code) === '') continue;
        $first = substr_count(substr($src, 0, $s[2][1]), "\n") + 1;
        $out[] = [$code, $first];
    }
    return $out;
}

// ── What to check ──────────────────────────────────────────────────────────
$chunks = [];   // [name, code, first line]
$phpFiles = array_map(fn($p) => $p['path'], pageHealthPages($root));
// The shared pieces that write scripts INTO pages: templates and includes.
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
$jsFiles = [];
foreach ($it as $f) {
    $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($root) + 1));
    if (preg_match('#(^|/)(vendor|node_modules|tests|\.git|engineering-wiki|local|docs|browser-extension)/#', $rel)) continue;
    if (substr($rel, -3) === '.js' && substr($rel, -7) !== '.min.js' && !preg_match('#(^|/)api/#', $rel)) { $jsFiles[] = $rel; continue; }
    if (substr($rel, -4) === '.php' && preg_match('#(^|/)includes/#', $rel) && !in_array($rel, $phpFiles, true)) {
        if (stripos((string) file_get_contents($f->getPathname()), '<script') !== false) $phpFiles[] = $rel;
    }
}
sort($phpFiles); sort($jsFiles);

foreach ($phpFiles as $rel) {
    $src = (string) file_get_contents($root . '/' . $rel);
    foreach (inlineScripts($src) as $i => [$code, $first]) {
        $chunks[] = ['name' => $rel, 'code' => $code, 'first' => $first, 'what' => 'inline script ' . ($i + 1)];
    }
}
foreach ($jsFiles as $rel) {
    $chunks[] = ['name' => $rel, 'code' => (string) file_get_contents($root . '/' . $rel), 'first' => 1, 'what' => 'file'];
}

// --dump FILE: write what would be parsed as JSON, for parsing elsewhere (a browser,
// on a machine with no Node).
$dumpAt = array_search('--dump', $argv, true);
if ($dumpAt !== false && isset($argv[$dumpAt + 1])) {
    file_put_contents($argv[$dumpAt + 1], json_encode($chunks, PH_JSON));
    echo count($chunks), " scripts written to ", $argv[$dumpAt + 1], "\n";
    exit(0);
}

if (in_array('--list', $argv, true)) {
    echo count($phpFiles), " PHP files, ", count($jsFiles), " JS files, ", count($chunks), " scripts\n";
    foreach ($chunks as $c) echo $c['name'], ' (', $c['what'], ', line ', $c['first'], ")\n";
    exit(0);
}

// ── Parse them all in one Node process ───────────────────────────────────────
$node = trim((string) shell_exec(PHP_OS_FAMILY === 'Windows' ? 'where node 2>NUL' : 'command -v node 2>/dev/null'));
if ($node === '') {
    echo "Page health: Node is not installed, so the scripts cannot be parsed here.\n";
    echo "Run System -> Debug Tools -> D019 instead (it uses your browser). CI has Node.\n";
    exit(0);
}

// vm.Script parses without running. lineOffset makes the error line the line of
// the real file; the first line of err.stack is "file:LINE".
$js = <<<'JS'
const vm = require('vm');
let input = '';
process.stdin.on('data', d => input += d);
process.stdin.on('end', () => {
  const out = [];
  for (const c of JSON.parse(input)) {
    try { new vm.Script(c.code, { filename: c.name, lineOffset: c.first - 1 }); }
    catch (e) {
      const m = /:(\d+)\s*$/m.exec(String(e.stack).split('\n')[0]);
      out.push({ name: c.name, what: c.what, line: m ? +m[1] : c.first, message: e.message });
    }
  }
  process.stdout.write(JSON.stringify(out));
});
JS;
$tmp = tempnam(sys_get_temp_dir(), 'ph') . '.js';
file_put_contents($tmp, $js);
$proc = proc_open([$node, $tmp], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
fwrite($pipes[0], json_encode($chunks, PH_JSON));
fclose($pipes[0]);
$stdout = stream_get_contents($pipes[1]);
$stderr = stream_get_contents($pipes[2]);
proc_close($proc);
@unlink($tmp);
$problems = json_decode($stdout, true);
if (!is_array($problems)) { echo "Page health: Node failed: $stderr\n"; exit(2); }

echo "Page health: ", count($chunks), " scripts in ", count($phpFiles), " PHP files and ", count($jsFiles), " JS files.\n";
if (!$problems) { echo "All parse.\n"; exit(0); }

echo count($problems), " script(s) do not parse:\n\n";
foreach ($problems as $p) {
    $lines = explode("\n", (string) file_get_contents($root . '/' . $p['name']));
    echo "  {$p['name']}:{$p['line']} ({$p['what']}): {$p['message']}\n";
    for ($i = max(1, $p['line'] - 2); $i <= min(count($lines), $p['line']); $i++) {
        echo ($i === $p['line'] ? '  > ' : '    '), str_pad((string) $i, 5, ' ', STR_PAD_LEFT), ' | ', rtrim(substr($lines[$i - 1], 0, 200)), "\n";
    }
    echo "\n";
}
echo "A script that does not parse never runs - the page looks loaded and nothing on it works (GH #222).\n";
exit(1);
