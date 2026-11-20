<?php
/**
 * Swap hard-coded font sizes for the theme.css tokens (discussion #363).
 *
 *   php scripts/tokenise_font_sizes.php            dry run: what would change, per file
 *   php scripts/tokenise_font_sizes.php --apply    write the changes
 *   ... --round-up [--apply]                       ALSO move sizes between the steps UP to the next
 *                                                  step (12.5px -> 13, 15px -> 16, 22px -> 24) - VISIBLE
 *
 * Only sizes ON the scale are swapped (11 12 13 14 16 18 20 24px), and the old size
 * stays as the var() fallback - font-size: 13px becomes
 * font-size: var(--font-size-md, 13px) - so nothing on screen changes. Sizes between
 * the steps (12.5px, 15px, ...) are left for a deliberate, screenshot-checked pass:
 * moving them changes what people see.
 *
 * Scope and exemptions (emails, TinyMCE's iframe, the iOS zoom guard) are in
 * tests/support/font_size_scope.php, shared with tests/font-size-guard.php.
 * Line endings are kept as they are.
 *
 * @wiki UI-Style-Guide
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../tests/support/font_size_scope.php';

$root  = realpath(__DIR__ . '/..');
$apply = in_array('--apply', $argv, true);
// Ed, 2026-11-20: sizes between the steps round UP to the next one. Above the top
// step (24px) there is no token - headline and dashboard figures stay as written.
$roundUp = in_array('--round-up', $argv, true);
function fontSizeStepUp(float $px): ?int
{
    foreach (array_keys(FONT_SIZE_TOKENS) as $step) if ($step >= $px) return $step;
    return null;
}

$totalFiles = 0; $totalSwaps = 0; $left = [];
foreach (fontSizeScopeFiles($root) as $rel) {
    $path = "$root/$rel";
    $text = (string)file_get_contents($path);
    if (stripos($text, 'font-size') === false) continue;

    $swaps = 0;
    $parts = preg_split('/(\R)/', $text, -1, PREG_SPLIT_DELIM_CAPTURE);   // keeps each line ending
    for ($i = 0; $i < count($parts); $i += 2) {
        $line = $parts[$i];
        if (stripos($line, 'font-size') === false) continue;
        $parts[$i] = preg_replace_callback('/(font-size\s*:\s*)(\d+(?:\.\d+)?)px/i', function ($m) use ($line, $rel, &$swaps, &$left, $roundUp) {
            $px = $m[2];
            if ($roundUp && !fontSizeLineIsExempt($line, $rel, (int)$px) && ($up = fontSizeStepUp((float)$px)) !== null) $px = (string)$up;
            if (!ctype_digit($px) || !isset(FONT_SIZE_TOKENS[(int)$px]) || fontSizeLineIsExempt($line, $rel, (int)$px)) {
                if (!fontSizeLineIsExempt($line, $rel, (int)$px)) $left[$px . 'px'] = ($left[$px . 'px'] ?? 0) + 1;
                return $m[0];
            }
            $swaps++;
            return $m[1] . 'var(' . FONT_SIZE_TOKENS[(int)$px] . ', ' . $px . 'px)';
        }, $line);
    }
    if (!$swaps) continue;
    $totalFiles++; $totalSwaps += $swaps;
    if ($apply) file_put_contents($path, implode('', $parts));
    else echo str_pad((string)$swaps, 6, ' ', STR_PAD_LEFT) . "  $rel\n";
}
ksort($left, SORT_NATURAL);
echo "\n" . ($apply ? 'Swapped' : 'Would swap') . " $totalSwaps sizes in $totalFiles files.\n";
echo "Off the scale, left as they are: " . implode(', ', array_map(fn($k, $v) => "$k x$v", array_keys($left), $left)) . "\n";
