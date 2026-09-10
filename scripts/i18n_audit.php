<?php
/**
 * i18n audit — compare a locale against English and report what is wrong with it.
 *
 *   php scripts/i18n_audit.php de            one locale, full detail
 *   php scripts/i18n_audit.php --all         every locale, one summary line each
 *   php scripts/i18n_audit.php de --keys     also list every missing key
 *
 * WHY THIS EXISTS. There are 24 locales and English keeps moving. A locale does
 * not announce that it has fallen behind: a missing key falls back to English and
 * renders perfectly, so a half-translated module looks finished until somebody
 * who reads that language opens it. The only way to know is to compare.
 *
 * What it checks, in the order the problems actually matter:
 *
 *   1. MISSING FILES     a whole namespace absent — the entire module in English
 *   2. MISSING KEYS      individual strings absent — English words mid-sentence
 *   3. PLACEHOLDERS      ⚠️ the dangerous one. A translation that drops {name} or
 *                        swaps %d and %s is WORSE than a missing translation: it
 *                        renders, so nobody reports it, and it either prints the
 *                        wrong value or a raw token at the user. Checked BOTH
 *                        ways — a placeholder invented by the translator is as
 *                        broken as one they lost.
 *   4. UNTRANSLATED      value byte-identical to English. Often legitimate
 *                        ("Email", "OK", a product name), so this is reported as
 *                        a number to look at, never as an error.
 *   5. EXTRA             keys English no longer has — dead weight, and a sign the
 *                        locale was written against an older version.
 *
 * Read-only. Touches nothing but the lang directory, and only reads.
 */

// CLI ONLY — scripts/ sits inside the web root on a normal install. This one only
// reads, but it enumerates the whole translation surface and a maintainer's tool
// has no business answering a web request at all. In PHP rather than the web
// server, so it holds on nginx and IIS where an .htaccess deny is ignored.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script is command-line only.\n");
}

$root    = dirname(__DIR__);
$langDir = $root . '/lang';

$args    = array_slice($argv, 1);
$flags   = array_values(array_filter($args, fn($a) => str_starts_with($a, '--')));
$locales = array_values(array_filter($args, fn($a) => !str_starts_with($a, '--')));
$showKeys = in_array('--keys', $flags, true);
$all      = in_array('--all', $flags, true);

if (!is_dir($langDir)) {
    fwrite(STDERR, "No lang directory at $langDir\n");
    exit(1);
}

$available = array_values(array_filter(scandir($langDir), function ($d) use ($langDir) {
    return $d !== '.' && $d !== '..' && $d !== 'en' && is_dir($langDir . '/' . $d);
}));
sort($available);

if ($all) {
    $locales = $available;
} elseif (!$locales) {
    fwrite(STDERR, "Usage: php scripts/i18n_audit.php <locale>|--all [--keys]\n");
    fwrite(STDERR, "Locales: " . implode(', ', $available) . "\n");
    exit(1);
}

/** Flatten a nested lang array into dot paths: ['a' => ['b' => 'x']] => ['a.b' => 'x']. */
function flatten(array $a, string $prefix = ''): array
{
    $out = [];
    foreach ($a as $k => $v) {
        $key = $prefix === '' ? (string) $k : $prefix . '.' . $k;
        if (is_array($v)) {
            $out += flatten($v, $key);
        } else {
            $out[$key] = (string) $v;
        }
    }
    return $out;
}

/**
 * Every placeholder in a string, as a sorted multiset.
 *
 * ⚠️ BOTH STYLES ARE IN USE and a locale can be perfect in one and broken in the
 * other. `{name}` is the modern form; the older tickets strings use printf. A
 * swapped %d and %s passes any check that only counts them, so ORDER is kept for
 * printf while {named} tokens are sorted — order is meaningful for one and not
 * the other.
 */
function placeholders(string $s): array
{
    preg_match_all('/\{[a-zA-Z0-9_]+\}/', $s, $named);
    $named = $named[0];
    sort($named);

    // %s %d %1$s %05.2f — sequence matters, so this list is NOT sorted.
    //
    // ⚠️ THE SPACE FLAG IS DELIBERATELY EXCLUDED from the flag set. "% d" is legal
    // printf and is never used in these files, but "% " followed by a letter is
    // extremely common in ordinary prose — "{pct}% uploaded", "90% of the licence
    // count". Including it reported both of those as broken placeholders, which
    // is worse than useless: a checker that cries wolf gets ignored, and then the
    // real mismatch it eventually finds gets ignored too.
    preg_match_all('/%(?:\d+\$)?[-+0#]*[\d.]*[bcdeEfFgGosuxX]|%%/', $s, $printf);

    return ['named' => $named, 'printf' => $printf[0]];
}

/** Strings that are legitimately the same in most languages. */
function looksDeliberatelyIdentical(string $v): bool
{
    $t = trim($v);
    if ($t === '') return true;
    if (mb_strlen($t) <= 2) return true;                       // OK, No, ID
    if (preg_match('/^[\d\s\p{P}\p{S}]+$/u', $t)) return true; // punctuation/numbers only
    // Brand and protocol names nobody translates.
    $brands = ['FreeITSM', 'Email', 'E-Mail', 'URL', 'API', 'SSO', 'LDAP', 'SAML', 'OIDC', 'SLA',
               'CMDB', 'ITSM', 'PDF', 'CSV', 'JSON', 'XML', 'Slack', 'Teams', 'Jira', 'Intune',
               'Microsoft', 'Google', 'WhatsApp', 'QR', 'IP', 'DNS', 'SMTP', 'IMAP', 'OAuth',
               'Webhook', 'Webhooks', 'Token', 'Server', 'Status', 'Total', 'Standard', 'Import',
               'Export', 'Reset', 'Filter', 'Info', 'Details', 'Datum', 'Name', 'Modus'];
    return in_array($t, $brands, true);
}

$enFiles = glob($langDir . '/en/*.php');
$enNs    = array_map(fn($f) => basename($f, '.php'), $enFiles);
sort($enNs);

$en = [];
foreach ($enNs as $ns) {
    $data = include $langDir . '/en/' . $ns . '.php';
    $en[$ns] = is_array($data) ? flatten($data) : [];
}
$enTotal = array_sum(array_map('count', $en));

$summary = [];

foreach ($locales as $loc) {
    $dir = $langDir . '/' . $loc;
    if (!is_dir($dir)) { fwrite(STDERR, "No such locale: $loc\n"); continue; }

    $missingFiles = [];
    $missingKeys  = [];
    $extraKeys    = [];
    $phProblems   = [];
    $untranslated = 0;
    $present      = 0;
    $parseErrors  = [];

    foreach ($enNs as $ns) {
        $path = $dir . '/' . $ns . '.php';
        if (!is_file($path)) { $missingFiles[] = $ns; continue; }

        $data = @include $path;
        if (!is_array($data)) { $parseErrors[] = $ns; continue; }
        $loc_ = flatten($data);

        foreach ($en[$ns] as $key => $enVal) {
            $full = $ns . '.' . $key;
            if (!array_key_exists($key, $loc_)) { $missingKeys[] = $full; continue; }
            $present++;

            $lv = $loc_[$key];
            if ($lv === $enVal && !looksDeliberatelyIdentical($enVal)) $untranslated++;

            $a = placeholders($enVal);
            $b = placeholders($lv);
            if ($a['named'] !== $b['named']) {
                $phProblems[] = sprintf('%s  {} en=[%s] %s=[%s]', $full,
                    implode(',', $a['named']), $loc, implode(',', $b['named']));
            } elseif ($a['printf'] !== $b['printf']) {
                $phProblems[] = sprintf('%s  printf en=[%s] %s=[%s]', $full,
                    implode(',', $a['printf']), $loc, implode(',', $b['printf']));
            }
        }
        foreach ($loc_ as $key => $v) {
            if (!array_key_exists($key, $en[$ns])) $extraKeys[] = $ns . '.' . $key;
        }
    }

    $missingFromFiles = 0;
    foreach ($missingFiles as $ns) $missingFromFiles += count($en[$ns]);
    $totalMissing = count($missingKeys) + $missingFromFiles;
    $pct = $enTotal > 0 ? round(100 * ($enTotal - $totalMissing) / $enTotal, 1) : 0;

    $summary[$loc] = [
        'pct' => $pct, 'files' => count($missingFiles), 'keys' => $totalMissing,
        'ph' => count($phProblems), 'extra' => count($extraKeys), 'untranslated' => $untranslated,
    ];

    if ($all) continue;

    echo "=== LOCALE {$loc} ===\n";
    printf("Coverage: %s%% — %d of %d English strings present\n", $pct, $enTotal - $totalMissing, $enTotal);
    echo "\n";

    echo "=== MISSING FILES (the whole module renders in English) ===\n";
    if (!$missingFiles) {
        echo "None — all " . count($enNs) . " namespaces present.\n";
    } else {
        foreach ($missingFiles as $ns) printf("%-22s %5d string(s)\n", $ns . '.php', count($en[$ns]));
        printf("%d file(s), %d string(s).\n", count($missingFiles), $missingFromFiles);
    }
    echo "\n";

    if ($parseErrors) {
        echo "=== FILES THAT DID NOT RETURN AN ARRAY ===\n" . implode("\n", $parseErrors) . "\n\n";
    }

    echo "=== MISSING KEYS in files that DO exist ===\n";
    if (!$missingKeys) {
        echo "None.\n";
    } else {
        $byNs = [];
        foreach ($missingKeys as $k) { $byNs[substr($k, 0, strpos($k, '.'))][] = $k; }
        foreach ($byNs as $ns => $ks) printf("%-22s %5d missing\n", $ns, count($ks));
        printf("%d in total.\n", count($missingKeys));
        if ($showKeys) foreach ($missingKeys as $k) echo "  $k\n";
    }
    echo "\n";

    echo "=== PLACEHOLDER MISMATCHES (these RENDER, so nobody reports them) ===\n";
    echo $phProblems ? implode("\n", $phProblems) . "\n" : "None.\n";
    echo "\n";

    echo "=== EXTRA KEYS (English no longer has these) ===\n";
    if (!$extraKeys) {
        echo "None.\n";
    } else {
        printf("%d — likely written against an older version of English.\n", count($extraKeys));
        foreach (array_slice($extraKeys, 0, 40) as $k) echo "  $k\n";
        if (count($extraKeys) > 40) printf("  … and %d more\n", count($extraKeys) - 40);
    }
    echo "\n";

    echo "=== IDENTICAL TO ENGLISH ===\n";
    printf("%d string(s) are byte-identical to English, after excluding brand names,\n", $untranslated);
    echo "punctuation and very short words. Some of those are legitimate; a large\n";
    echo "number usually means a file was copied and never translated.\n";
}

if ($all) {
    printf("%-6s %8s %7s %8s %7s %7s %s\n", 'LOCALE', 'COVER', 'FILES', 'KEYS', 'PLACE', 'EXTRA', 'SAME-AS-EN');
    printf("%s\n", str_repeat('-', 62));
    uasort($summary, fn($a, $b) => $a['pct'] <=> $b['pct']);
    foreach ($summary as $loc => $s) {
        printf("%-6s %7s%% %7d %8d %7d %7d %d\n",
            $loc, $s['pct'], $s['files'], $s['keys'], $s['ph'], $s['extra'], $s['untranslated']);
    }
    echo "\nFILES = whole namespaces absent. KEYS = total English strings not present.\n";
    echo "PLACE = placeholder mismatches, the ones that render wrongly rather than fall back.\n";
}
