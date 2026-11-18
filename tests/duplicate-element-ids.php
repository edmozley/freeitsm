<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * No page has two elements with the same id (GH #392).
 *
 *   php tests/duplicate-element-ids.php
 *
 * document.getElementById() returns the FIRST element with an id, silently. When a
 * page repeats one, the script for the second element wires itself to the first,
 * and a button simply does nothing - no error anywhere:
 *   - 5.5.0's Move to company dialog reused pmSave / pmError from the People dialog,
 *     so "Add someone" stopped working (#392);
 *   - 5.1.0's Income dialog reused six pi* ids from the Scope dialog, so its Save
 *     did nothing and the deliverable's Save also sent an income save.
 *
 * Static: every literal id="..." in every page file, counted per file. A repeat is a
 * failure unless it is listed below with the reason only one of the two is ever on
 * the page at once. Runs in CI (Web exposure job).
 */

$root = dirname(__DIR__);

/** Repeats that are safe: path => [id => why only one is ever in the page]. */
const DUPLICATE_ID_ALLOWED = [
    'auth/login.php' => [
        'localLoginForm' => 'one form in the SSO modal, the other when no SSO is active (if / else)',
        'username'       => 'same if / else',
        'password'       => 'same if / else',
    ],
    'reporting/intune/index.php' => [
        'drillCount' => 'the second is in a JS string that replaces the heading holding the first',
    ],
    'reporting/logs/index.php' => [
        'jobInterval' => 'two JS templates for two different dialogs; one is drawn at a time',
    ],
    'self-service/tickets.php' => [
        'tkFilter' => 'one for My tickets, one for Team tickets (if / else on the view)',
    ],
];

$fail = 0; $checked = 0;
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    $rel = ltrim(str_replace('\\', '/', substr($f->getPathname(), strlen($root))), '/');
    if (!str_ends_with($rel, '.php')) continue;
    if (preg_match('#^(vendor|lib|tests|engineering-wiki|local|node_modules|uploads|storage|lang|api|data|docs|scripts|database|cron|setup)/#', $rel)) continue;
    $checked++;
    preg_match_all('/\bid="([A-Za-z][\w-]*)"/', (string)file_get_contents($f->getPathname()), $m);
    foreach (array_count_values($m[1]) as $id => $n) {
        if ($n < 2 || isset(DUPLICATE_ID_ALLOWED[$rel][$id])) continue;
        echo "  FAIL  $rel: id=\"$id\" appears $n times - getElementById() finds only the first, so the other element's script is wired to the wrong one\n";
        $fail++;
    }
}
// A listed exception that is no longer repeated should be taken off the list.
foreach (DUPLICATE_ID_ALLOWED as $rel => $ids) {
    $s = is_file("$root/$rel") ? (string)file_get_contents("$root/$rel") : '';
    foreach ($ids as $id => $why) {
        if (substr_count($s, 'id="' . $id . '"') < 2) { echo "  FAIL  $rel: id=\"$id\" is listed as allowed but no longer repeats - remove it from the list\n"; $fail++; }
    }
}
echo $fail ? "\n$fail problem(s) in $checked page files\n" : "  PASS  no unexpected duplicate ids in $checked page files\n";
exit($fail ? 1 : 0);
