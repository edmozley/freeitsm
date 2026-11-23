<?php
/**
 * Debug Tool D006 — SSL / HTTPS certificate verification
 *
 * Answers one question end to end: "can this server actually make a
 * certificate-verified HTTPS request, and if not, why?"
 *
 * FreeITSM makes a lot of outbound HTTPS calls (mailboxes, AI providers, SSO,
 * Intune/vCenter, webhooks, email). Since #919 verification is ON by default
 * (SSL_VERIFY_PEER) and every handle goes through sslApplyCurl(), which attaches
 * a CA bundle. This tool shows the whole chain — the global switch, the php.ini
 * CA config, the shipped includes/cacert.pem, which bundle actually wins, and a
 * batch of LIVE verified requests to the real services the app talks to — so a
 * "certificate problem" is diagnosed in one place instead of guessed at.
 *
 * ⚠️ It also asks the question a live request cannot: config.php is the
 * OPERATOR'S file and never upgrades, so a hand-assembled or pre-release copy can
 * lack the SSL block the app expects. Every certificate test here can pass while a
 * mailbox sign-in still dies on "Call to undefined function sslApplyCurl()".
 * This tool used to print that helper's function_exists() as if it meant something,
 * having itself loaded functions.php first - so it could only ever say YES.
 *
 * READ-ONLY. It makes unauthenticated HEAD requests to public endpoints and
 * writes nothing. Prints no secrets (no API keys, no request bodies).
 *
 * Output: plain text, section-delimited with === HEADERS === for easy skimming.
 */

@session_start();

$DIAG_ID   = 'D006';
$DIAG_NAME = 'SSL / HTTPS certificate verification';

require_once __DIR__ . '/../../../config.php';   // defines SSL_VERIFY_PEER, SSL_CA_BUNDLE, loads includes/ssl.php
require_once __DIR__ . '/../../../includes/functions.php';

// Debug tools are administrators-only (issue #34). Fail closed.
try {
    $__dbgAdmin = !empty($_SESSION['analyst_id']) && analystIsAdmin(connectToDatabase(), (int)$_SESSION['analyst_id']);
} catch (Throwable $e) {
    $__dbgAdmin = false;
}
if (!$__dbgAdmin) {
    http_response_code(403);
    if (!headers_sent()) header('Content-Type: text/plain; charset=utf-8');
    echo "Administrator access required.\n";
    exit;
}

$sections = [];
/**
 * Adds one titled section ("=== TITLE ===" and its lines) to the report; $body is a
 * string or an array of lines.
 *
 * @param mixed $sections  the report so far, a list of section strings - appended to by reference
 * @param mixed $title  the section heading, printed between === marks
 * @param mixed $body  the section text - a string, or an array of lines
 */
function addSection(&$sections, $title, $body) {
    if (is_array($body)) $body = implode("\n", $body);
    $sections[] = "=== {$title} ===\n" . rtrim($body, "\n");
}
/**
 * Formats a boolean as YES or NO for the report.
 *
 * @param mixed $v  any value, judged as true or false
 */
function yn($v) { return $v ? 'YES' : 'NO'; }
/**
 * Prints every section, separated by blank lines, and stops - the end of the report, and
 * the early exit when a check cannot go on.
 *
 * @param mixed $sections  the report, a list of section strings as built by addSection()
 */
function emit_and_exit($sections) {
    if (!headers_sent()) header('Content-Type: text/plain; charset=utf-8');
    echo implode("\n\n", $sections) . "\n";
    exit;
}

$appRoot = realpath(__DIR__ . '/../../..');

// ---- 1. ENVIRONMENT ----------------------------------------------------
$cv = function_exists('curl_version') ? curl_version() : [];
addSection($sections, "ENVIRONMENT", [
    "PHP version     : " . PHP_VERSION,
    "OS              : " . PHP_OS . " (" . php_uname('s') . ")",
    "SAPI            : " . PHP_SAPI . "   (this is the WEB server's PHP; the background worker runs under the CLI php.ini, which may differ)",
    "curl extension  : " . yn(function_exists('curl_init')),
    "libcurl         : " . ($cv['version'] ?? '(unknown)'),
    "TLS backend     : " . ($cv['ssl_version'] ?? '(unknown)'),
]);

// ---- 2. GLOBAL SETTING -------------------------------------------------
$verifyDefined = defined('SSL_VERIFY_PEER');
$verifyOn      = $verifyDefined && SSL_VERIFY_PEER;
addSection($sections, "GLOBAL SETTING (config.php)", [
    "SSL_VERIFY_PEER defined : " . yn($verifyDefined),
    "SSL_VERIFY_PEER value   : " . ($verifyDefined ? ($verifyOn ? 'true (verification ON)' : 'false (verification OFF — INSECURE)') : '(not defined!)'),
    "SSL_CA_BUNDLE defined   : " . yn(defined('SSL_CA_BUNDLE'))
        . (defined('SSL_CA_BUNDLE') ? '' : "   <-- see the next section, your config.php looks incomplete"),
]);

// ---- 2b. WHERE sslApplyCurl() COMES FROM -------------------------------
// ⚠️ "Is the function loaded?" is the WRONG QUESTION, and this tool used to ask
// exactly it on this spot. D006 requires includes/functions.php, which requires
// includes/ssl.php, so function_exists('sslApplyCurl') is ALWAYS true in here.
// It therefore reported a healthy install to a user who had a live
// "Call to undefined function sslApplyCurl()" in his Microsoft OAuth callback.
//
// 🔑 The question that tells the two apart is structural: config.php is the
// OPERATOR'S file and never upgrades, so does each entry point that calls the
// helper LOAD it itself rather than relying on that file? See GH #129 and
// tests/config-not-load-bearing.php.
$sslHome = 'includes/ssl.php';
$cfgPath = $appRoot . DIRECTORY_SEPARATOR . 'config.php';
$cfgSrc  = is_readable($cfgPath) ? (string)file_get_contents($cfgPath) : '';
$reqRe   = '#(?:require|include)(?:_once)?\s*\(?\s*__DIR__\s*\.\s*[\'"][^\'"]*includes/ssl\.php[\'"]#';
$cfgLoadsSsl = $cfgSrc !== '' && preg_match($reqRe, $cfgSrc) === 1;

// The entry points that deliberately do NOT load includes/functions.php, so they
// must require ssl.php for themselves. Everything else arrives via functions.php.
$sslEntryPoints = ['auth/oauth_callback.php', 'auth/google_oauth_callback.php'];
$sslEntryProblems = [];
$entryLines = [];
foreach ($sslEntryPoints as $ep) {
    $p = $appRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $ep);
    if (!is_readable($p)) {
        $entryLines[] = sprintf("  %-32s (not present on this install)", $ep);
        continue;
    }
    $loads = preg_match($reqRe, (string)file_get_contents($p)) === 1;
    if (!$loads) $sslEntryProblems[] = $ep;
    $entryLines[] = sprintf("  %-32s %s", $ep,
        $loads ? 'loads includes/ssl.php itself  [OK]'
               : 'does NOT load it  [PROBLEM - see below]');
}

$whereLines = array_merge([
    "config.php requires includes/ssl.php : " . yn($cfgLoadsSsl),
    "",
], $entryLines, [
    "",
]);
if (!$cfgLoadsSsl) {
    $whereLines[] = "🔴 YOUR config.php IS MISSING THE SSL BLOCK. config.php is your file, not";
    $whereLines[] = "   ours - it is never overwritten by an upgrade - so an older or";
    $whereLines[] = "   hand-assembled copy can lack lines the app now expects. The shipped";
    $whereLines[] = "   template has these, just after the SSL_VERIFY_PEER line:";
    $whereLines[] = "";
    $whereLines[] = "       require_once(__DIR__ . '/includes/ssl.php');";
    $whereLines[] = "       if (!defined('SSL_CA_BUNDLE')) {";
    $whereLines[] = "           define('SSL_CA_BUNDLE', sslResolveCaBundle());";
    $whereLines[] = "       }";
    $whereLines[] = "";
    $whereLines[] = "   Compare your config.php against the one in the release you are running";
    $whereLines[] = "   and add anything missing. Other blocks may be absent too.";
} elseif (!$sslEntryProblems) {
    $whereLines[] = "Nothing here depends on your config.php carrying the right lines, which is";
    $whereLines[] = "the point: every entry point that needs the helper loads it for itself.";
}
if ($sslEntryProblems) {
    $whereLines[] = "";
    $whereLines[] = "🔴 " . implode(' and ', $sslEntryProblems) . " will fail with";
    $whereLines[] = "   \"Call to undefined function sslApplyCurl()\" on any install whose";
    $whereLines[] = "   config.php does not happen to load it. Upgrade, or add";
    $whereLines[] = "   require_once __DIR__ . '/../includes/ssl.php'; to each.";
}
addSection($sections, "WHERE sslApplyCurl() COMES FROM", $whereLines);

// ---- 3. PHP.INI CA CONFIGURATION --------------------------------------
$curlCa = (string)ini_get('curl.cainfo');
$osslCa = (string)ini_get('openssl.cafile');
$curlCaReadable = $curlCa !== '' && is_readable($curlCa);
$osslCaReadable = $osslCa !== '' && is_readable($osslCa);
addSection($sections, "PHP.INI CA CONFIGURATION", [
    "curl.cainfo     : " . ($curlCa !== '' ? $curlCa . '  [' . ($curlCaReadable ? 'readable' : 'NOT READABLE — file missing!') . ']' : '(not set)'),
    "openssl.cafile  : " . ($osslCa !== '' ? $osslCa . '  [' . ($osslCaReadable ? 'readable' : 'NOT READABLE — file missing!') . ']' : '(not set)'),
    "",
    "Note: these are optional. FreeITSM ships its own bundle and does not need",
    "them set. A path that IS set but points at a missing file is a real problem,",
    "though — it overrides the fallback and breaks verification.",
]);

// ---- 4. SHIPPED CA BUNDLE ---------------------------------------------
$bundled = $appRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'cacert.pem';
$bundledExists = is_file($bundled);
$bundledReadable = $bundledExists && is_readable($bundled);
$certCount = 0; $bundledSize = 0;
if ($bundledReadable) {
    $bundledSize = filesize($bundled);
    $certCount = substr_count((string)file_get_contents($bundled), 'BEGIN CERTIFICATE');
}
addSection($sections, "SHIPPED CA BUNDLE (includes/cacert.pem)", [
    "Path            : " . $bundled,
    "Exists          : " . yn($bundledExists),
    "Readable        : " . yn($bundledReadable),
    "Size            : " . ($bundledExists ? number_format($bundledSize) . " bytes" : "-") . ($bundledExists && $bundledSize < 50000 ? "   (SUSPICIOUS — a real bundle is ~200 KB; this may be a saved HTML error page)" : ""),
    "Certificates    : " . ($bundledReadable ? $certCount : "-"),
    "",
    ($bundledExists
        ? "This is the fallback FreeITSM uses on Windows when php.ini has no bundle."
        : "MISSING. If php.ini has no bundle either, verification will fail. Fix: download"),
    ($bundledExists ? "" : "https://curl.se/ca/cacert.pem and save it as includes/cacert.pem (no restart needed)."),
]);

// ---- 5. RESOLVED BUNDLE (what sslApplyCurl will actually attach) -------
$resolved = function_exists('sslResolveCaBundle') ? sslResolveCaBundle() : (defined('SSL_CA_BUNDLE') ? SSL_CA_BUNDLE : '');
$isWindows = stripos(PHP_OS, 'WIN') === 0;

// ⚠️ Compare the FILES, never the path strings. sslResolveCaBundle() returns
// __DIR__ . '/cacert.pem' - backslashes from __DIR__ and then one forward slash -
// while section 4 builds the same path with DIRECTORY_SEPARATOR throughout. Those
// are two spellings of one file, and a === between them is false on Windows. That
// sent this decision to its "nothing could be resolved, verification will likely
// fail" branch on precisely the installs the shipped bundle exists for: Windows
// with no php.ini bundle, which is a stock WAMP. The bundle was found and attached
// the whole time; only the sentence describing it was wrong.
$sameFile = static function (string $a, string $b): bool {
    if ($a === '' || $b === '') return false;
    $ra = realpath($a);
    $rb = realpath($b);
    if ($ra === false || $rb === false) return false;
    if (stripos(PHP_OS, 'WIN') === 0) {
        $ra = strtolower(str_replace('\\', '/', $ra));
        $rb = strtolower(str_replace('\\', '/', $rb));
    }
    return $ra === $rb;
};

if ($curlCaReadable) {
    $why = "using the bundle configured in php.ini (curl.cainfo).";
} elseif ($osslCaReadable) {
    $why = "using the bundle configured in php.ini (openssl.cafile).";
} elseif ($sameFile($resolved, $bundled)) {
    $why = "using the shipped includes/cacert.pem (Windows fallback — php.ini has none).";
} elseif ($resolved !== '' && is_readable($resolved)) {
    $why = "using the bundle named above, which is readable. It is neither a php.ini setting nor the shipped includes/cacert.pem, so it came from SSL_CA_BUNDLE in config.php.";
} elseif ($resolved !== '') {
    $why = "a bundle is named above but is NOT READABLE — verification will fail. Fix that path, or remove it to fall back to the shipped includes/cacert.pem.";
} elseif (!$isWindows) {
    $why = "no explicit bundle — on Linux, libcurl falls back to the OS trust store (/etc/ssl/certs), which is correct.";
} else {
    $why = "no CA bundle could be resolved — verification will likely fail.";
}
addSection($sections, "RESOLVED CA BUNDLE", [
    "SSL_CA_BUNDLE   : " . (defined('SSL_CA_BUNDLE') ? (SSL_CA_BUNDLE !== '' ? SSL_CA_BUNDLE : '(empty — rely on the OS/compiled default)') : '(not defined)'),
    "Decision        : " . $why,
]);

// ---- 6. LIVE VERIFICATION TESTS ---------------------------------------
// Hit the real services the app talks to, exactly the way it does (sslApplyCurl).
// A pass means the TLS handshake + certificate verification succeeded; the HTTP
// status is irrelevant (a 401/404 still proves the cert was trusted).
$targets = [
    ['Microsoft Graph (mailboxes)', 'https://graph.microsoft.com/v1.0/'],
    ['Anthropic (AI)',              'https://api.anthropic.com/'],
    ['OpenAI (AI)',                 'https://api.openai.com/'],
    ['Google OAuth (SSO/Gmail)',    'https://oauth2.googleapis.com/'],
    ['Slack (webhooks)',            'https://hooks.slack.com/'],
    ['curl.se (CA bundle home)',    'https://curl.se/'],
];
$results = [];
$certFailures = 0; $networkFailures = 0; $passes = 0;
if (function_exists('curl_init') && function_exists('sslApplyCurl')) {
    foreach ($targets as [$label, $url]) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_NOBODY, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_TIMEOUT, 8);
        sslApplyCurl($ch);   // the exact path every app call uses
        $ok  = curl_exec($ch) !== false;
        $err = curl_error($ch);
        if ($ok) {
            $passes++;
            $results[] = sprintf("  %-28s OK  (certificate verified)", $label);
        } elseif (stripos($err, 'certificate') !== false || stripos($err, 'issuer') !== false || stripos($err, 'CA') !== false) {
            $certFailures++;
            $results[] = sprintf("  %-28s FAIL (certificate)  %s", $label, $err);
        } else {
            $networkFailures++;
            $results[] = sprintf("  %-28s SKIP (network/DNS)  %s", $label, $err);
        }
    }
} else {
    $results[] = "  curl or sslApplyCurl unavailable — cannot run live tests.";
}
addSection($sections, "LIVE VERIFICATION TESTS", array_merge(
    ["Requesting each service the way the app does (sslApplyCurl, verify on):", ""],
    $results
));

// ---- 7. VERDICT --------------------------------------------------------
$verdict = [];
if (!$verifyOn) {
    $verdict[] = "⚠ Verification is OFF (SSL_VERIFY_PEER is false). Outbound HTTPS is not";
    $verdict[] = "  checking who it talks to — set SSL_VERIFY_PEER to true in config.php.";
} elseif ($certFailures > 0) {
    $verdict[] = "✗ Verification is ON but FAILING with a certificate error. Outbound HTTPS";
    $verdict[] = "  (mail, AI, webhooks, sign-in) will not work.";
    $verdict[] = "";
    $verdict[] = "  Simplest fix: put a cacert.pem in the app's includes/ folder —";
    $verdict[] = "  download https://curl.se/ca/cacert.pem and save it as includes/cacert.pem.";
    $verdict[] = "  No php.ini change, no restart. (If a php.ini path above says NOT READABLE,";
    $verdict[] = "  fix or remove that setting — it overrides the fallback.)";
} elseif ($passes > 0 && $certFailures === 0) {
    $verdict[] = "✓ Working. Certificate verification is on and succeeded against " . $passes . " of";
    $verdict[] = "  " . count($targets) . " services. Any SKIPs above are network/DNS (no outbound";
    $verdict[] = "  route to that host), not a certificate problem.";
} else {
    $verdict[] = "? Could not confirm. Every test hit a network/DNS error, so the certificate";
    $verdict[] = "  path could not be exercised. Check this server has outbound internet access.";
}
// A clean run of the live tests above says nothing about the OAuth callbacks:
// they are separate entry points, and the request that breaks them is one no
// diagnostic here makes. Say so rather than printing an unqualified tick.
if (!empty($sslEntryProblems) || !$cfgLoadsSsl) {
    $verdict[] = "";
    $verdict[] = "⚠ But see WHERE sslApplyCurl() COMES FROM above: something is missing that";
    $verdict[] = "  the tests on this page cannot exercise. Mailbox sign-in can still fail";
    $verdict[] = "  with \"Call to undefined function sslApplyCurl()\" while everything here";
    $verdict[] = "  passes.";
}
addSection($sections, "VERDICT", $verdict);

emit_and_exit($sections);
