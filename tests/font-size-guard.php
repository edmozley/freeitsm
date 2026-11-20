<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * Hard-coded font sizes may only go DOWN (discussion #363, the font-size tokens).
 *
 *   php tests/font-size-guard.php            check against the baseline
 *   php tests/font-size-guard.php --update   lower the baseline after a conversion
 *
 * The sizes are tokens in assets/css/theme.css (--font-size-xs .. --font-size-4xl,
 * 11-24px). The approved pattern is font-size: var(--font-size-md, 13px) - the size
 * as the var() fallback - and that is NOT counted. A literal "font-size: 13px" is.
 *
 * Every size on the scale was swapped in one pass (scripts/tokenise_font_sizes.php);
 * what is left is the sizes BETWEEN the steps (12.5px, 15px, ...), which move only in
 * a deliberate, screenshot-checked pass. tests/baselines/font-sizes.json holds each
 * file's count; this fails if any file goes UP, and a new file must start at zero.
 *
 * Scope (what a browser renders - never email bodies, TinyMCE's iframe or the iOS
 * zoom guard) is tests/support/font_size_scope.php, shared with the converter.
 * No database, no web server.
 *
 * @wiki UI-Style-Guide
 */
require __DIR__ . '/support/font_size_scope.php';
$root = dirname(__DIR__);
$baselineFile = "$root/tests/baselines/font-sizes.json";
$update = in_array('--update', $argv, true);

$current = [];
foreach (fontSizeScopeFiles($root) as $rel) {
    $n = fontSizeLiteralCount((string)file_get_contents("$root/$rel"), $rel);
    if ($n) $current[$rel] = $n;
}
ksort($current);
$baseline = is_file($baselineFile) ? (json_decode((string)file_get_contents($baselineFile), true) ?: []) : [];

$pass = 0; $fail = 0; $lowered = [];
function ok(string $what, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; } else { $fail++; echo "  FAIL  {$what}" . ($detail !== '' ? "  <- {$detail}" : '') . "\n"; }
}
echo "\nHard-coded font sizes\n" . str_repeat('=', 72) . "\n";
ok('control: a literal size counts, a token with a fallback does not',
   fontSizeLiteralCount(".a{font-size:13px}\n.b{font-size: var(--font-size-md, 13px)}\n.c{font-size:12.5px}", 'x.css') === 2);
ok('control: TinyMCE content_style and the iOS zoom guard are exempt',
   fontSizeLiteralCount("content_style: 'body { font-size: 14px; }'\n/* iOS zoom */ input { font-size: 16px; }", 'x.js') === 0);
ok('control: an email file is out of scope', !in_array('includes/sla_notifications.php', fontSizeScopeFiles($root), true));
ok('control: a page is in scope', in_array('tickets/settings/index.php', fontSizeScopeFiles($root), true));

foreach ($current as $rel => $n) {
    $was = $baseline[$rel] ?? 0;
    ok($was ? "$rel has no more hard-coded font sizes than before ($was)" : "$rel has no hard-coded font sizes (use the --font-size-* tokens)",
       $n <= $was, "now $n");
    if ($n < $was) $lowered[$rel] = [$was, $n];
}
foreach ($baseline as $rel => $was) if (!isset($current[$rel]) && is_file("$root/$rel")) $lowered[$rel] = [$was, 0];
$total = array_sum($current);
echo "  $total hard-coded font sizes across " . count($current) . " files (baseline " . array_sum($baseline) . ").\n";
foreach ($lowered as $rel => [$was, $now]) echo "  ↓ $rel: $was -> $now - run with --update to lock that in.\n";

if ($update) {
    if ($fail && $baseline) { echo "\nNot updating the baseline: something went UP.\n"; exit(1); }
    @mkdir(dirname($baselineFile), 0755, true);
    file_put_contents($baselineFile, json_encode($current, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    echo "\nBaseline written: $baselineFile\n";
}
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
