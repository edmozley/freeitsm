<?php
/**
 * Debug Tool D009 — are the guarded paths actually guarded?
 *
 * Answers one question end to end: "can somebody with a web browser fetch a file
 * that only the application is supposed to hand out?"
 *
 * FreeITSM keeps uploaded files — ticket attachments, change attachments, war room
 * attachments, RFP uploads, call recordings, attached documents — under the web root, and
 * relies on the WEB SERVER refusing to serve them. Every one of those files is
 * meant to be reached through a PHP endpoint that checks who you are first.
 *
 * On Apache that refusal comes from a .htaccess in each folder. ⚠️ nginx never
 * reads .htaccess, and neither does Apache with `AllowOverride None`. So the
 * protection is not a property of FreeITSM — it is a property of how somebody
 * configured their web server, which FreeITSM cannot see and has no way to assume.
 *
 * THIS IS THE FAILURE MODE THAT ANNOUNCES ITSELF LEAST. A missing rewrite rule
 * gives you a 404 within seconds of installing. A missing deny rule gives you a
 * service desk that works perfectly and publishes every attachment. Nothing looks
 * wrong, no error is logged, and the first sign of trouble is somebody else
 * finding a document they should never have seen. That is the whole reason this
 * tool exists (issue #68).
 *
 * HOW IT TESTS, and why it has to be done this way: it writes a tiny harmless
 * probe file into each guarded folder and then asks its OWN web server for it
 * over HTTP, exactly as a stranger would. Reading .htaccess files off disk would
 * prove nothing — the question is not whether the file exists, it is whether the
 * server is paying any attention to it.
 *
 * ⚠️ A REQUEST THAT FAILS IS NOT A PASS. A connection refused, a TLS error or a
 * timeout means "could not test", never "guarded" — a check that cannot run must
 * say so rather than quietly report all-clear. The tool proves it can see its own
 * site (a positive control) before it believes a single 403 or 404.
 *
 * Output: plain text, section-delimited with === HEADERS === for easy skimming.
 */

@session_start();

$DIAG_ID   = 'D009';
$DIAG_NAME = 'Guarded paths — are uploads really unreachable?';

require_once __DIR__ . '/../../../config.php';
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

if (!headers_sent()) header('Content-Type: text/plain; charset=utf-8');

$sections = [];
function addSection(array &$s, string $title, array $lines): void {
    $s[] = "=== {$title} ===\n" . rtrim(implode("\n", $lines), "\n");
}
function emit_and_exit(array $sections): void {
    echo implode("\n\n", $sections) . "\n";
    exit;
}

$APP_ROOT = dirname(__DIR__, 3);
$problems = [];
$fixes    = [];

// ---- 1. Where are we asking? -----------------------------------------------
$scheme = requestScheme();
$host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
$base   = $scheme . '://' . $host . (defined('BASE_URL') ? BASE_URL : '/');

/**
 * Fetch a URL from this server. Returns [httpCode, transportError].
 * A transport error yields code 0, and every caller must treat that as
 * "unknown", never as "blocked".
 */
function d009Fetch(string $url): array {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 8);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
    // Self-signed certificates are normal on an internal hostname, and this
    // request never leaves the machine. Verifying here would turn a perfectly
    // guarded install into an untestable one.
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
    curl_setopt($ch, CURLOPT_USERAGENT, 'FreeITSM-D009');
    curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_errno($ch) ? curl_error($ch) : '';
    curl_close($ch);
    return [$code, $err];
}

addSection($sections, "WHAT THIS TOOL ASKS", [
    "Uploaded files live in folders under the web root. They are meant to be",
    "reachable ONLY through FreeITSM, which checks permissions first. This tool",
    "puts a harmless probe file in each of those folders and then asks this",
    "server for it over HTTP — exactly as a stranger with the URL would.",
    "",
    "Testing it: " . $base,
    "",
    "On Apache the protection comes from a .htaccess in each folder. nginx does",
    "not read those files at all, so on nginx the protection exists only if the",
    "shipped config (deploy/nginx/freeitsm.conf) was installed.",
]);

// ---- 2. POSITIVE CONTROL ----------------------------------------------------
// Before believing any 403/404, prove this tool can reach its own site and get a
// 200 for something that definitely should be served. Without this, a firewall
// or a wrong hostname would make every guarded path look beautifully protected.
$controls = [
    'assets/images/CompanyLogo.png' => 'the login page logo',
    'favicon.svg'                   => 'the site icon',
];
$controlOk = false;
$controlLines = [];
foreach ($controls as $path => $what) {
    [$code, $err] = d009Fetch($base . $path);
    $verdict = $err !== '' ? "could not connect" : "HTTP $code";
    if ($code === 200) { $controlOk = true; $verdict .= " ← control passed"; }
    $controlLines[] = sprintf("  %-32s %-26s %s", $path, $verdict, $err !== '' ? $err : $what);
}
if (!$controlOk) {
    $controlLines[] = "";
    $controlLines[] = "⚠️  NOTHING BELOW CAN BE TRUSTED.";
    $controlLines[] = "This tool could not fetch a file that definitely should be served, so it";
    $controlLines[] = "cannot tell 'protected' from 'unreachable'. Every guarded path would look";
    $controlLines[] = "protected for the wrong reason.";
    $controlLines[] = "";
    $controlLines[] = "Usually this means the server cannot reach itself by the address in the";
    $controlLines[] = "browser bar — a split-horizon DNS name, a proxy in front, or a firewall";
    $controlLines[] = "that blocks loopback. Check from a browser instead:";
    $controlLines[] = "  " . $base . "tickets/attachments/";
    $controlLines[] = "Anything other than 403 or 404 means the folder is being served.";
}
addSection($sections, "CAN THIS TOOL SEE ITS OWN SITE? (positive control)", $controlLines);

if (!$controlOk) {
    addSection($sections, "VERDICT", [
        "INCONCLUSIVE — the check could not run.",
        "",
        "This is deliberately NOT reported as a pass. A tool that cannot reach the",
        "site would find every path 'guarded', which is the most dangerous wrong",
        "answer it could give.",
    ]);
    emit_and_exit($sections);
}

// ---- 3. The guarded folders -------------------------------------------------
// dir => [probe filename, human description]. A .php probe is used where the
// folder legitimately serves files but must never EXECUTE them.
$guarded = [
    'tickets/attachments'          => ['_d009probe.txt', 'Ticket attachments',        'deny'],
    'change-management/attachments'=> ['_d009probe.txt', 'Change attachments',        'deny'],
    'war-room/attachments'         => ['_d009probe.txt', 'War room attachments',      'deny'],
    'contracts/rfp-builder/uploads'=> ['_d009probe.txt', 'RFP uploads',               'deny'],
    'recordings'                   => ['_d009probe.txt', 'Screen recordings',         'deny'],
    // Documents attached to records (discussion #76). Served only through
    // api/documents/download.php, which checks that the caller can see something
    // the document is attached to. The stored filename is random, but a random
    // name is not a permission.
    'uploads/documents'            => ['_d009probe.txt', 'Attached documents',        'deny'],
    'lms/content'                  => ['_d009probe.php', 'Course content (no-exec)',  'noexec'],
    'system/uploads/branding'      => ['_d009probe.php', 'Branding uploads (no-exec)','noexec'],
];

$written = [];   // absolute paths, removed in the finally below
$rows    = [];
$exposed = [];

try {
    foreach ($guarded as $dir => [$name, $label, $kind]) {
        $abs = $APP_ROOT . '/' . $dir;
        if (!is_dir($abs)) {
            $rows[] = sprintf("  %-30s %-12s %s", $dir, "n/a", "folder not present yet — nothing to protect");
            continue;
        }
        if (!is_writable($abs)) {
            $rows[] = sprintf("  %-30s %-12s %s", $dir, "SKIPPED", "folder is not writable, cannot place a probe");
            continue;
        }

        // Harmless content, unpredictable name. If the folder turns out to be
        // unguarded this file is briefly fetchable, so it must say nothing.
        $file = $abs . '/' . substr($name, 0, -4) . '_' . bin2hex(random_bytes(4)) . substr($name, -4);
        if (@file_put_contents($file, "FreeITSM D009 probe. Harmless. Delete me.\n") === false) {
            $rows[] = sprintf("  %-30s %-12s %s", $dir, "SKIPPED", "could not write a probe file");
            continue;
        }
        $written[] = $file;

        $url = $base . $dir . '/' . basename($file);
        [$code, $err] = d009Fetch($url);

        if ($err !== '') {
            $rows[] = sprintf("  %-30s %-12s %s", $dir, "UNKNOWN", "request failed: $err");
            $problems[] = "Could not test $dir — the request failed ($err).";
            $fixes[] = "Re-run once the server can reach itself, or check $dir by hand in a browser.";
        } elseif ($code === 200) {
            $rows[] = sprintf("  %-30s %-12s %s", $dir, "🔴 EXPOSED", "HTTP 200 — the file was served");
            $exposed[] = $dir;
        } elseif ($code === 403 || $code === 404) {
            $rows[] = sprintf("  %-30s %-12s %s", $dir, "✅ guarded", "HTTP $code — refused, as it should be");
        } else {
            $rows[] = sprintf("  %-30s %-12s %s", $dir, "⚠️  odd", "HTTP $code — not 200, but not a clean refusal either");
        }
    }
} finally {
    foreach ($written as $f) @unlink($f);
}

// Nothing may be left behind. Say so either way — silence about cleanup is how
// a probe file ends up living in an uploads folder forever.
$leftover = 0;
foreach ($written as $f) if (is_file($f)) $leftover++;
$rows[] = "";
$rows[] = "  Probe files left behind: " . $leftover . ($leftover === 0 ? "  (all cleaned up)" : "  ⚠️ NOT CLEANED UP");
if ($leftover > 0) {
    $problems[] = "$leftover probe file(s) could not be deleted.";
    $fixes[] = "Delete any file named _d009probe_* from the folders above by hand.";
}
addSection($sections, "UPLOAD FOLDERS (probe written, fetched, deleted)", $rows);

// ---- 4. Paths that need no probe --------------------------------------------
$fixed = [
    'tickets/csat/survey.php' => ['The CSAT survey, which is reachable only through /csat', true],
    '.git/config'             => ['Your repository config — exposes history, and often credentials', true],
];
$fixedRows = [];
foreach ($fixed as $path => [$why, $mustBlock]) {
    [$code, $err] = d009Fetch($base . $path);
    if ($err !== '') {
        $fixedRows[] = sprintf("  %-30s %-12s %s", $path, "UNKNOWN", "request failed");
    } elseif ($code === 200) {
        $fixedRows[] = sprintf("  %-30s %-12s %s", $path, "🔴 EXPOSED", $why);
        $exposed[] = $path;
    } else {
        $fixedRows[] = sprintf("  %-30s %-12s %s", $path, "✅ guarded", "HTTP $code");
    }
}
$fixedRows[] = "";
$fixedRows[] = "  .git is only present if this install was deployed with `git clone`. If it";
$fixedRows[] = "  is exposed, that is worth fixing whatever web server you run — Apache does";
$fixedRows[] = "  not block it either.";
addSection($sections, "OTHER PATHS THAT SHOULD NOT BE SERVED", $fixedRows);

// ---- 5. Which server, and what that implies ---------------------------------
$sig = $_SERVER['SERVER_SOFTWARE'] ?? '(unknown)';
$isNginx = stripos($sig, 'nginx') !== false;
$svr = ["  Server software: " . $sig, ""];
if ($isNginx) {
    $svr[] = "  This is nginx, which NEVER reads .htaccess. Every protection above comes";
    $svr[] = "  from your nginx config and nothing else. FreeITSM ships one:";
    $svr[] = "    deploy/nginx/freeitsm.conf";
    $svr[] = "  See the wiki page 'Running on nginx'.";
} else {
    $svr[] = "  This looks like Apache (or something reporting itself as Apache). The";
    $svr[] = "  protections above come from .htaccess files, which only apply when the";
    $svr[] = "  site is configured with AllowOverride All (or at least AllowOverride";
    $svr[] = "  Limit FileInfo). With AllowOverride None they are silently ignored, and";
    $svr[] = "  the results above are what actually matters — not the presence of the files.";
}
addSection($sections, "WEB SERVER", $svr);

// ---- 6. VERDICT -------------------------------------------------------------
if (empty($exposed) && empty($problems)) {
    addSection($sections, "VERDICT", [
        "ALL GOOD — every folder that should be unreachable refused the request.",
        "",
        "Uploaded files can only be reached through FreeITSM, which checks who is",
        "asking. This was tested by actually fetching them, not by looking for",
        "configuration files, so it reflects what your web server really does.",
        "",
        "Worth re-running after any web server change, and after moving to a",
        "different server or host.",
    ]);
} else {
    $v = [];
    if (!empty($exposed)) {
        $v[] = "🔴 FILES ARE BEING SERVED THAT SHOULD NOT BE — " . count($exposed) . ":";
        $v[] = "";
        foreach ($exposed as $i => $p) $v[] = sprintf("  %d. %s", $i + 1, $p);
        $v[] = "";
        $v[] = "WHAT THIS MEANS: anyone who has, guesses or is sent one of these URLs can";
        $v[] = "fetch the file without signing in. None of FreeITSM's permission checks are";
        $v[] = "involved, because the web server answers before FreeITSM is ever asked.";
        $v[] = "";
        // The remedy differs by WHAT is exposed. An upload folder is a server
        // configuration problem; an exposed .git is a deployment problem, and
        // telling somebody to change AllowOverride would not fix it — worse, they
        // would re-run the tool, still see it, and stop trusting the tool.
        $uploadsExposed = array_values(array_filter($exposed, fn($p) => strpos($p, '.git') === false));
        $gitExposed     = array_values(array_filter($exposed, fn($p) => strpos($p, '.git') !== false));

        $v[] = "WHAT TO DO:";
        $n = 0;
        if (!empty($uploadsExposed)) {
            $n++;
            if ($isNginx) {
                $v[] = "  $n. Install the shipped nginx config: deploy/nginx/freeitsm.conf";
                $v[] = "     Wiki: Running on nginx. Then re-run this tool.";
            } else {
                $v[] = "  $n. Set AllowOverride All on this site in your Apache configuration,";
                $v[] = "     so the .htaccess files in those folders are read. Reload Apache";
                $v[] = "     and re-run this tool.";
            }
            $n++;
            $v[] = "  $n. Treat the files already in " . implode(', ', $uploadsExposed);
            $v[] = "     as having been publicly reachable for as long as this has been so.";
        }
        if (!empty($gitExposed)) {
            $n++;
            $v[] = "  $n. The .git folder is a DEPLOYMENT issue, not a permissions one —";
            $v[] = "     changing AllowOverride will not help. This install was deployed by";
            $v[] = "     cloning the repository into the web root, so its history (and often";
            $v[] = "     the credentials in .git/config) is downloadable. Either:";
            $v[] = "       - block /.git in your web server config (the shipped nginx config";
            $v[] = "         already does; Apache needs a rule adding), or";
            $v[] = "       - deploy from an export instead of a clone, so there is no .git at all.";
            $v[] = "     Rotate any credential that appears in .git/config, since it has been";
            $v[] = "     readable by anyone who asked.";
        }
    }
    if (!empty($problems)) {
        if (!empty($v)) $v[] = "";
        $v[] = "ALSO — " . count($problems) . " thing(s) could not be checked:";
        $v[] = "";
        foreach ($problems as $i => $p) $v[] = sprintf("  %d. %s", $i + 1, $p);
        foreach (array_values(array_unique($fixes)) as $f) $v[] = "     " . $f;
    }
    addSection($sections, "VERDICT", $v);
}

emit_and_exit($sections);
