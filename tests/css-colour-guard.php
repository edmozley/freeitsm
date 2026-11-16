<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * Hard-coded colours in stylesheets may only go DOWN (discussion #363).
 *
 *   php tests/css-colour-guard.php            check against the baseline
 *   php tests/css-colour-guard.php --update   lower the baseline after a conversion
 *
 * A colour written into a stylesheet ignores dark mode, the palettes and System ->
 * Module colours. The way out is a token - var(--accent, #0078d4) - and a colour
 * there, as the var() fallback, is the approved pattern and is NOT counted. What
 * is counted is a colour value anywhere else: #hex, rgb(), rgba(), hsl(), hsla().
 *
 * Nobody has to convert everything at once. tests/baselines/css-colours.json holds
 * each stylesheet's count as it was; this fails if any file goes UP, and a new
 * stylesheet must start at zero. When a conversion lowers a count, run --update so
 * the lower number becomes the new ceiling (--update refuses while anything has
 * gone up).
 *
 * Not counted: assets/css/theme.css (where the colours ARE defined - it is the
 * palette), and third-party CSS (tinymce, vendor). No database, no web server.
 *
 * @wiki UI-Style-Guide
 */
$root = dirname(__DIR__);
$baselineFile = "$root/tests/baselines/css-colours.json";
$update = in_array('--update', $argv, true);

/** Colour values in a stylesheet that are not inside a var() - i.e. not a fallback. */
function cssHardCodedColours(string $css): int
{
    $css = preg_replace('#/\*.*?\*/#s', '', $css);             // comments
    // Blank out every var( ... ) including nested parentheses - a colour there is a fallback.
    $out = ''; $n = strlen($css);
    for ($i = 0; $i < $n; $i++) {
        if (substr_compare($css, 'var(', $i, 4, true) === 0) {
            $depth = 0;
            for ($j = $i + 3; $j < $n; $j++) {
                if ($css[$j] === '(') $depth++;
                elseif ($css[$j] === ')' && --$depth === 0) break;
            }
            $i = $j; $out .= ' ';
            continue;
        }
        $out .= $css[$i];
    }
    // Only declaration values: text after a ':' up to ';' or '}' inside a block. Selectors
    // (#id) and @rules are never values.
    $count = 0;
    if (preg_match_all('/\{([^{}]*)\}/', $out, $blocks)) {
        foreach ($blocks[1] as $body) {
            foreach (explode(';', $body) as $decl) {
                $p = strpos($decl, ':');
                if ($p === false) continue;
                $prop = strtolower(trim(substr($decl, 0, $p)));
                if (str_starts_with($prop, '--')) continue;           // defining a token is not using a colour
                $value = substr($decl, $p + 1);
                $count += preg_match_all('/#[0-9a-fA-F]{3,8}\b|\b(?:rgba?|hsla?)\s*\(/i', $value);
            }
        }
    }
    return $count;
}

$files = glob("$root/assets/css/*.css") ?: [];
$current = [];
foreach ($files as $f) {
    $name = basename($f);
    if ($name === 'theme.css') continue;
    $current[$name] = cssHardCodedColours((string)file_get_contents($f));
}
ksort($current);
$baseline = is_file($baselineFile) ? (json_decode((string)file_get_contents($baselineFile), true) ?: []) : [];

$pass = 0; $fail = 0; $lowered = [];
function ok(string $what, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; } else { $fail++; echo "  FAIL  {$what}" . ($detail !== '' ? "  <- {$detail}" : '') . "\n"; }
}
echo "\nHard-coded colours in stylesheets\n" . str_repeat('=', 72) . "\n";
ok('control: the counter sees a colour outside var() and not one inside it',
   cssHardCodedColours('.a{color:#fff;background:var(--x, #000);border:1px solid rgba(0,0,0,.1)}#bad{--t:#abc}') === 2);
foreach ($current as $name => $n) {
    if (!array_key_exists($name, $baseline)) {
        ok("$name is new, so it starts with no hard-coded colours (use tokens)", $n === 0, "$n found");
        continue;
    }
    ok("$name has no more hard-coded colours than before ({$baseline[$name]})", $n <= $baseline[$name], "now $n");
    if ($n < $baseline[$name]) $lowered[$name] = [$baseline[$name], $n];
}
$total = array_sum($current);
echo "  $total hard-coded colours across " . count($current) . " stylesheets (baseline " . array_sum($baseline) . ").\n";
foreach ($lowered as $name => [$was, $now]) echo "  ↓ $name: $was -> $now - run with --update to lock that in.\n";

if ($update) {
    // The very first run has no baseline to compare with: it writes one. After that,
    // --update only ever locks in a fall.
    if ($fail && $baseline) { echo "\nNot updating the baseline: something went UP.\n"; exit(1); }
    @mkdir(dirname($baselineFile), 0755, true);
    file_put_contents($baselineFile, json_encode($current, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    echo "\nBaseline written: $baselineFile\n";
}
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
