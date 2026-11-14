<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * System landing by topic (GH #346): every card names a real group, never the
 * catch-all "other", and every group has its heading in English.
 *
 *   php tests/system-card-groups.php
 *
 * No database, no config.php: it reads the registry (system/includes/areas.php)
 * and lang/en/system.php only. A new System area without a 'group' fails here,
 * rather than turning up under "Other" on the page.
 */
require __DIR__ . '/../system/includes/areas.php';

$pass = 0; $fail = 0;
function check(string $what, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ok    $what\n"; } else { $fail++; echo "  FAIL  $what" . ($detail !== '' ? "  <- $detail" : '') . "\n"; }
}

echo "System cards by topic\n";
$groups = systemAreaGroups();
$en = require __DIR__ . '/../lang/en/system.php';
$string = function (string $key) use ($en): bool {
    $v = $en;
    foreach (array_slice(explode('.', $key), 1) as $p) { if (!is_array($v) || !array_key_exists($p, $v)) return false; $v = $v[$p]; }
    return is_string($v) && $v !== '';
};

$areas = getSystemAreas();
check('POSITIVE CONTROL: there are System areas to check', count($areas) > 10, (string)count($areas));
$bad = [];
foreach ($areas as $a) {
    $g = $a['group'] ?? null;
    if ($g === null || $g === 'other' || !isset($groups[$g])) $bad[] = $a['url'] . ' => ' . var_export($g, true);
}
check('every area names a group from systemAreaGroups(), never "other"', !$bad, implode(', ', $bad));
$missing = array_keys(array_filter($groups, fn($k) => !$string($k)));
check('every group has its heading in lang/en/system.php', !$missing, implode(', ', $missing));
$byUrl = array_column($areas, 'group', 'url');
check('Search is with health and maintenance', ($byUrl['search/'] ?? null) === 'health', (string)($byUrl['search/'] ?? 'missing'));
check('Currencies is with the organisation', ($byUrl['currencies/'] ?? null) === 'organisation', (string)($byUrl['currencies/'] ?? 'missing'));

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
