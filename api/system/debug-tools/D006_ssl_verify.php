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
function addSection(&$sections, $title, $body) {
    if (is_array($body)) $body = implode("\n", $body);
    $sections[] = "=== {$title} ===\n" . rtrim($body, "\n");
}
function yn($v) { return $v ? 'YES' : 'NO'; }
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
    "includes/ssl.php loaded : " . yn(function_exists('sslApplyCurl')),
    "sslResolveCaBundle()    : " . yn(function_exists('sslResolveCaBundle')),
]);

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
if ($curlCaReadable) {
    $why = "using the bundle configured in php.ini (curl.cainfo).";
} elseif ($osslCaReadable) {
    $why = "using the bundle configured in php.ini (openssl.cafile).";
} elseif ($resolved !== '' && $resolved === $bundled) {
    $why = "using the shipped includes/cacert.pem (Windows fallback — php.ini has none).";
} elseif ($resolved === '' && !$isWindows) {
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
        curl_close($ch);
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
addSection($sections, "VERDICT", $verdict);

emit_and_exit($sections);
