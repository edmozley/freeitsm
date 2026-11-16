<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * Each module's accent from System -> Module colours (GH #302, discussion #363).
 * No database: moduleAccentCss() is pure.
 *
 *   php tests/module-accent.php
 */
$root = dirname(__DIR__);
require_once "$root/includes/module-colors.php";
require_once "$root/includes/module_accent.php";

$pass = 0; $fail = 0;
function ok(string $what, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  PASS  {$what}\n"; }
    else       { $fail++; echo "  FAIL  {$what}" . ($detail !== '' ? "  <- {$detail}" : '') . "\n"; }
}
/** The value a declaration sets in the given block (0 = light, 1 = dark). */
function declOf(string $css, string $prop, int $block = 0): ?string {
    $blocks = preg_split('/\}\s*/', $css, -1, PREG_SPLIT_NO_EMPTY);
    if (!isset($blocks[$block]) || !preg_match('/(?:^|[;{])' . preg_quote($prop, '/') . ':([^;}]+)/', $blocks[$block], $m)) return null;
    return $m[1];
}

echo "\nModule accent\n" . str_repeat('=', 72) . "\n";
$d = $defaultModuleColors;

ok('Tickets at its default changes nothing', moduleAccentCss('tickets', $d['tickets'], $d['tickets']) === '');
$cal = moduleAccentCss('calendar', $d['calendar'], $d['calendar']);
ok('a module with its own set, at its default, points --accent at that set (tuned shades kept)',
   str_contains($cal, '--accent:var(--cal-accent,') && !str_contains($cal, 'data-theme-mode'), $cal);

$assets = moduleAccentCss('assets', $d['assets'], $d['assets']);
ok('a module with no set of its own takes its own colour, not the Tickets blue', declOf($assets, '--accent') === $d['assets'][0], (string)declOf($assets, '--accent'));
ok('...with a dark-mode block', str_contains($assets, ':root[data-theme-mode="dark"]'));

$teal = ['#0f766e', '#115e59'];
$calT = moduleAccentCss('calendar', $teal, $d['calendar']);
ok('a changed colour drives --accent', declOf($calT, '--accent') === '#0f766e', (string)declOf($calT, '--accent'));
ok('...and the module\'s own set follows it', str_contains($calT, '--cal-accent:var(--accent)'));
ok('...and the chosen hover is kept when the colour reads as it is', declOf($calT, '--accent-hover') === '#115e59');

$yellow = moduleAccentCss('assets', ['#facc15', '#eab308'], $d['assets']);
$yl = (string)declOf($yellow, '--accent');
ok('a colour too pale to read on white is deepened to 4.5:1', moduleAccentContrast($yl, '#ffffff') >= 4.5, $yl);
ok('...and is still recognisably the chosen colour (not black)', $yl !== '#000000' && moduleAccentContrast($yl, '#ffffff') < 7, $yl);
$yd = (string)declOf($yellow, '--accent', 1);
ok('in dark mode the accent reads on the dark surface', moduleAccentContrast($yd, MODULE_ACCENT_DARK_SURFACE) >= 4.5, $yd);

$navy = moduleAccentCss('files', ['#1e3a5f', '#16304f'], $d['files']);
$nd = (string)declOf($navy, '--accent', 1);
ok('a very dark colour is lifted in dark mode until it reads', moduleAccentContrast($nd, MODULE_ACCENT_DARK_SURFACE) >= 4.5, $nd);
ok('...and used exactly as chosen in light mode', declOf($navy, '--accent') === '#1e3a5f');

foreach ([['#facc15', '#eab308'], ['#0f766e', '#115e59'], ['#1e3a5f', '#16304f'], ['#ffffff', '#ffffff']] as $c) {
    $css = moduleAccentCss('people', $c, $d['people']);
    foreach ([0, 1] as $b) {
        $acc = (string)declOf($css, '--accent', $b); $on = (string)declOf($css, '--on-accent', $b);
        $on = $on === '#fff' ? '#ffffff' : $on;
        ok("text on a filled $c[0] button reads (" . ($b ? 'dark' : 'light') . ')', moduleAccentContrast($acc, $on) >= 4.5, "$acc / $on");
    }
}

foreach (['red', '#fff', 'url(x)', '#12345g', '#0f766e;}body{display:none'] as $bad) {
    ok('a colour that is not #rrggbb prints nothing: ' . $bad, moduleAccentCss('assets', [$bad, '#000000'], $d['assets']) === '');
}
ok('an unknown module with a valid colour still gets an accent (no own set)', declOf(moduleAccentCss('zz', ['#0f766e', '#115e59'], []), '--accent') === '#0f766e');

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
