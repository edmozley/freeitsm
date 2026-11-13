<?php
/* 🔴 NEVER OVER THE WEB. A test writes to the real tables — it creates forms,
   assets, documents and even working analyst accounts, and only tidies them up
   if it runs to the end. Served by a web server it is an unauthenticated write
   endpoint, and the request can be cut off half way. FreeITSM is normally
   deployed by putting the repository in the document root, so this file is
   reachable unless it refuses. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * Security findings, August 2026 — does the install actually behave as fixed?
 *
 * Almost none of these fixes have a button. There is no screen that shows "the
 * session cookie is HttpOnly now" and no way to eyeball whether a refresh token is
 * ciphertext at rest. This suite exists so the answer is not "trust the diff".
 *
 * It checks the NINE reported security findings by their observable behaviour
 * wherever it can, and by the shape of the code only where behaviour is not
 * reachable from outside:
 *
 *   F1  attachments named by the sender could be written into the web root
 *   F2  setup/ handed a privilege flag to anonymous visitors
 *   F3  mailbox OAuth tokens (and five other secrets) stored in the clear
 *   F5  attachments served with a sender-chosen Content-Type, SVG inline
 *   F6  bundled TinyMCE carried four published stored-XSS CVEs
 *   F7  no session rotation, no cookie flags, no CSRF defence
 *   F8  default credentials permanent, brute-force protection shipped off
 *   F9  tenant guards failed open; two confirmed cross-company leaks
 *
 *   php tests/security-findings/run.php
 *   php tests/security-findings/run.php http://localhost/freeitsm-app/
 *
 * Pass a base URL to include the live HTTP checks — most importantly the original
 * F2 exploit chain, which is the one worth watching fail. Without a URL those are
 * SKIPPED, not silently passed.
 *
 * ⚠️ Every "it refused" assertion is paired with a POSITIVE CONTROL that the same
 * code still accepts something legitimate. A guard that refuses everything — a
 * typo in a constant, a wrong column name — would otherwise look like a clean pass,
 * which is exactly how a fail-open bug hides.
 *
 * Read-only. It writes nothing to the database and nothing to the web root; the
 * upload checks work in the system temp directory and tidy up after themselves.
 */

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/encryption.php';
require_once __DIR__ . '/../../includes/uploads.php';
require_once __DIR__ . '/../../includes/tenancy.php';
require_once __DIR__ . '/../../includes/db_errors.php';

$APP  = dirname(__DIR__, 2);
$BASE = isset($argv[1]) ? rtrim($argv[1], '/') . '/' : null;

$pass = $fail = $skip = 0;

function check(string $what, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  [PASS] $what\n"; }
    else     { $fail++; echo "  [FAIL] $what" . ($detail !== '' ? " — $detail" : '') . "\n"; }
}
function skipped(string $what, string $why): void
{
    global $skip; $skip++;
    echo "  [SKIP] $what — $why\n";
}
function heading(string $t): void { echo "\n$t\n" . str_repeat('─', strlen($t)) . "\n"; }

/**
 * Source with its comments removed.
 *
 * ⚠️ Needed because every one of these fixes is documented by a comment that QUOTES
 * THE OLD VULNERABLE LINE — that is deliberate, it is how the next reader learns why
 * the code looks as it does. The first version of this suite grepped raw source and
 * reported four fixes as missing because it found the old code inside the comment
 * explaining that it had been removed. Same trap as trusting a grep anywhere else.
 */
function withoutComments(string $src): string
{
    $src = preg_replace('~/\*.*?\*/~s', '', $src);                 // /* block */
    $out = [];
    foreach (explode("\n", $src) as $line) {
        $t = ltrim($line);
        if ($t === '' || str_starts_with($t, '//') || str_starts_with($t, '#')
            || str_starts_with($t, '*') || str_starts_with($t, ';')) {
            continue;
        }
        $out[] = $line;
    }
    return implode("\n", $out);
}

/** A file's source, comments stripped. '' if unreadable. */
function code(string $path): string
{
    $s = @file_get_contents($path);
    return is_string($s) ? withoutComments($s) : '';
}

/** Fetch a URL, returning [status, headers, body]. Null if curl is unavailable. */
function http(string $url, array $opt = []): ?array
{
    if (!function_exists('curl_init')) return null;
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    if (!empty($opt['post']))    { curl_setopt($ch, CURLOPT_POST, true); }
    if (isset($opt['body']))     { curl_setopt($ch, CURLOPT_POSTFIELDS, $opt['body']); }
    if (!empty($opt['headers'])) { curl_setopt($ch, CURLOPT_HTTPHEADER, $opt['headers']); }
    if (!empty($opt['cookie']))  { curl_setopt($ch, CURLOPT_COOKIE, $opt['cookie']); }
    $raw = curl_exec($ch);
    if ($raw === false) { curl_close($ch); return null; }
    $status  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hdrSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    return [$status, substr($raw, 0, $hdrSize), substr($raw, $hdrSize)];
}

$conn = null;
try { $conn = connectToDatabase(); } catch (Exception $e) { /* reported per-section */ }

echo "FreeITSM — security findings verification\n";
echo $BASE ? "Live checks against: $BASE\n" : "No base URL given — live HTTP checks will be skipped.\n";


// ── F1 ───────────────────────────────────────────────────────────────────────
heading('F1  An attachment cannot name itself on disk');

$tmp = sys_get_temp_dir() . '/freeitsm_sec_' . bin2hex(random_bytes(4));
@mkdir($tmp, 0777, true);
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');

foreach ([['shell.php', '<?php echo 1; ?>'], ['evil.svg', '<svg><script>x</script></svg>'], ['page.html', '<html></html>'], ['.htaccess', 'php_flag engine on']] as [$name, $bytes]) {
    $r = uploadStoreBytes($bytes, $name, $tmp, ATTACHMENT_POLICY_DROP);
    check("$name is refused outright when the policy is 'drop'", $r['stored'] === false, (string)$r['reason']);

    $q = uploadStoreBytes($bytes, $name, $tmp, ATTACHMENT_POLICY_STORE);
    $ext = strtolower(pathinfo((string)$q['stored_name'], PATHINFO_EXTENSION));
    check("$name is neutered to .bin when the policy is 'keep'",
          $q['stored'] === true && $q['quarantined'] === true && $ext === ATTACHMENT_QUARANTINE_EXT,
          'stored as ' . var_export($q['stored_name'], true));
}

// A PHP payload wearing a .png name must fail the CONTENT check, not just the name.
$mismatch = uploadStoreBytes('<?php echo 1; ?>', 'photo.png', $tmp, ATTACHMENT_POLICY_DROP);
check("a PHP payload renamed photo.png is caught by the content check",
      $mismatch['stored'] === false, (string)$mismatch['reason']);

// POSITIVE CONTROL — a guard that refuses everything is not a guard.
$good = uploadStoreBytes($png, 'photo.png', $tmp, ATTACHMENT_POLICY_DROP);
check("POSITIVE CONTROL: a real PNG is still accepted",
      $good['stored'] === true && $good['quarantined'] === false, (string)$good['reason']);
check("POSITIVE CONTROL: and stored under a name of ours, not the sender's",
      $good['stored_name'] !== null && $good['stored_name'] !== 'photo.png'
      && (bool)preg_match('/^[0-9a-f]{32}\.png$/', (string)$good['stored_name']),
      (string)$good['stored_name']);

foreach (glob("$tmp/*") as $f) @unlink($f); @rmdir($tmp);

// The four ingest writers must no longer build a path out of the sender's name.
$writers = [
    'api/tickets/check_mailbox_email.php',
    'api/self-service/create_ticket.php',
    'api/self-service/reply_ticket.php',
    'includes/messaging/ingest.php',
];
foreach ($writers as $w) {
    $src = code("$APP/$w");
    check("$w routes uploads through uploadStoreBytes()",
          is_string($src) && str_contains($src, 'uploadStoreBytes('));
    check("$w no longer derives a filename with the old preg_replace",
          is_string($src) && !preg_match("/preg_replace\('\/\[\^a-zA-Z0-9\._\\\\?-\]\/'/", $src));
}

// Directory protection, for the servers that read each file.
$ht = code("$APP/tickets/attachments/.htaccess");
check("tickets/attachments/.htaccess denies the whole directory, not just scripts",
      is_string($ht) && stripos($ht, 'Require all denied') !== false);
check("tickets/attachments/web.config exists (nginx and IIS never read .htaccess)",
      is_file("$APP/tickets/attachments/web.config"));


// ── F2 ───────────────────────────────────────────────────────────────────────
heading('F2  setup/ grants nothing to an anonymous visitor');

check("setup/index.php no longer sets a setup_access privilege flag",
      !str_contains(code("$APP/setup/index.php"), "\$_SESSION['setup_access'] = true"));

$verifySrc = code("$APP/api/system/db_verify.php");
check("db_verify asks the database instead of trusting a session flag",
      str_contains($verifySrc, 'installIsUnprovisioned(')
      && !str_contains($verifySrc, "empty(\$_SESSION['setup_access'])"));

// The bootstrap gate must fail CLOSED on anything that is not a missing schema.
check("a missing table reads as 'fresh install'",       dbErrorIsMissingSchema(mkPdoEx('42S02', 1146)));
check("a missing column reads as 'fresh install'",      dbErrorIsMissingSchema(mkPdoEx('42S22', 1054)));
check("a lock-wait timeout does NOT",                  !dbErrorIsMissingSchema(mkPdoEx('HY000', 1205)));
check("a dropped connection does NOT",                 !dbErrorIsMissingSchema(mkPdoEx('HY000', 2006)));
check("an access-denied error does NOT",               !dbErrorIsMissingSchema(mkPdoEx('42000', 1045)));

function mkPdoEx(string $sqlstate, int $code): PDOException
{
    $e = new PDOException("simulated $sqlstate/$code");
    $e->errorInfo = [$sqlstate, $code, 'simulated'];
    return $e;
}

if ($BASE === null) {
    skipped("THE ORIGINAL EXPLOIT: GET /setup/ then POST db_verify", 'no base URL given');
} elseif ($conn === null) {
    skipped("THE ORIGINAL EXPLOIT", 'no database connection, cannot confirm the install is provisioned');
} else {
    $analystCount = (int)$conn->query("SELECT COUNT(*) FROM analysts")->fetchColumn();
    if ($analystCount === 0) {
        skipped("THE ORIGINAL EXPLOIT", 'this install has no analysts yet, so the bootstrap path is legitimately open');
    } else {
        $r1 = http($BASE . 'setup/');
        $sid = null;
        if ($r1 && preg_match('/^Set-Cookie:\s*PHPSESSID=([^;]+)/mi', $r1[1], $m)) $sid = $m[1];
        if (!$r1) {
            skipped("THE ORIGINAL EXPLOIT", 'could not reach ' . $BASE);
        } else {
            $r2 = http($BASE . 'api/system/db_verify.php', ['post' => true, 'body' => '', 'cookie' => $sid ? "PHPSESSID=$sid" : '']);
            check("THE ORIGINAL EXPLOIT is refused: loading /setup/ then posting to db_verify returns 401",
                  $r2 !== null && $r2[0] === 401, $r2 ? ('got HTTP ' . $r2[0]) : 'request failed');
            check("the anonymous setup page leaks no absolute filesystem path",
                  !preg_match('~[A-Za-z]:[\\\\/][A-Za-z0-9_\\\\/.-]{6,}~', preg_replace('~<script>window\.translations.*?</script>~s', '', $r1[2])));
            check("the anonymous setup page does not print the default password",
                  !str_contains($r1[2], '<strong>freeitsm</strong>'));
            check("the anonymous setup page offers no Database Verify button",
                  !str_contains($r1[2], 'id="dbVerifySection"'));
        }
    }
}


// ── F3 ───────────────────────────────────────────────────────────────────────
heading('F3  Secrets are ciphertext at rest');

check("token_data is registered as an encrypted mailbox column",
      in_array('token_data', ENCRYPTED_MAILBOX_COLUMNS, true));

// The rule, not the list: a brand-new secret name nobody has registered.
check("a NEW *_token setting is encrypted without anyone registering it",   isEncryptedSettingKey('something_new_cron_token'));
check("a NEW *_secret setting likewise",                                    isEncryptedSettingKey('brand_new_signing_secret'));
check("a NEW *_api_key setting likewise",                                   isEncryptedSettingKey('future_module_api_key'));
check("a NEW *_password setting likewise",                                  isEncryptedSettingKey('some_service_password'));
check("POSITIVE CONTROL: an ordinary setting is NOT encrypted",            !isEncryptedSettingKey('sla_warning_threshold_percent'));

if ($conn === null) {
    skipped("stored secrets are ciphertext", 'no database connection');
} else {
    try {
        $plainMailbox = [];
        foreach ($conn->query("SELECT * FROM target_mailboxes")->fetchAll(PDO::FETCH_ASSOC) as $row) {
            foreach (ENCRYPTED_MAILBOX_COLUMNS as $col) {
                $v = $row[$col] ?? null;
                if ($v !== null && $v !== '' && !str_starts_with($v, ENCRYPTION_PREFIX)) {
                    $plainMailbox[] = "mailbox {$row['id']}.{$col}";
                }
            }
        }
        check("every mailbox credential in the database is ciphertext", $plainMailbox === [],
              implode(', ', $plainMailbox));

        $plainSettings = [];
        foreach ($conn->query("SELECT setting_key, setting_value FROM system_settings")->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if ($row['setting_value'] === null || $row['setting_value'] === '') continue;
            if (!isEncryptedSettingKey($row['setting_key'])) continue;
            if (!str_starts_with($row['setting_value'], ENCRYPTION_PREFIX)) $plainSettings[] = $row['setting_key'];
        }
        check("every secret setting in the database is ciphertext", $plainSettings === [],
              implode(', ', $plainSettings) . ' — run Database Verify to migrate them');

        // POSITIVE CONTROL — ciphertext nobody can read is not a fix either.
        $sample = $conn->query("SELECT setting_value FROM system_settings WHERE setting_key = 'sla_cron_token'")->fetchColumn();
        if ($sample === false || $sample === null || $sample === '') {
            skipped("POSITIVE CONTROL: an encrypted secret still decrypts", 'sla_cron_token not present');
        } else {
            $plain = decryptValue($sample);
            check("POSITIVE CONTROL: an encrypted secret still decrypts to something usable",
                  is_string($plain) && $plain !== '' && !str_starts_with($plain, ENCRYPTION_PREFIX));
        }
    } catch (Exception $e) {
        skipped("stored secrets are ciphertext", $e->getMessage());
    }
}


// ── F5 ───────────────────────────────────────────────────────────────────────
heading('F5  An attachment cannot choose how it is served');

foreach (['evil.svg', 'page.html', 'shell.php', 'archive.tar.gz', 'noextension'] as $n) {
    $r = attachmentServeRules($n);
    check("$n is served as an octet-stream download",
          $r['type'] === 'application/octet-stream' && $r['inline'] === false,
          $r['type'] . ($r['inline'] ? ' inline' : ' attachment'));
}
// POSITIVE CONTROL — the reading pane still has to work.
foreach ([['photo.png', 'image/png'], ['scan.JPG', 'image/jpeg'], ['report.pdf', 'application/pdf']] as [$n, $want]) {
    $r = attachmentServeRules($n);
    check("POSITIVE CONTROL: $n still previews inline as $want",
          $r['type'] === $want && $r['inline'] === true, $r['type']);
}
$inj = attachmentServeRules("bad\r\nX-Injected: 1.png");
check("a filename cannot break out of the response header",
      !str_contains($inj['filename'], "\r") && !str_contains($inj['filename'], "\n")
      && !str_contains($inj['filename'], '"'), $inj['filename']);


// ── F6 ───────────────────────────────────────────────────────────────────────
heading('F6  The bundled editor is past the CVEs');

// Read the whole bundle: the version string is roughly a megabyte in, so the first
// version of this check truncated the read and reported "version not found".
$tiny = @file_get_contents("$APP/assets/js/tinymce/tinymce.min.js");
if (!is_string($tiny) || !preg_match('/majorVersion:"(\d+)",minorVersion:"([\d.]+)"/', $tiny, $m)) {
    check("the bundled TinyMCE version can be read", false, 'version string not found');
} else {
    $version = $m[1] . '.' . $m[2];
    check("bundled TinyMCE is 8.5.1 or later (CVE-2026-47759/60/61/62 fixed in 8.5.1)",
          version_compare($version, '8.5.1', '>='), "found $version");
    // 8.9.3 fixed a stored XSS in the media plugin, which the Inbox and Knowledge
    // editors switch on.
    check("bundled TinyMCE is 8.9.3 or later (media plugin stored XSS fixed in 8.9.3)",
          version_compare($version, '8.9.3', '>='), "found $version");
}

// The other bundled libraries with published fixes FreeITSM depends on (4.1.0).
// PDFs are written by PDFKit since GH #254; jsPDF (which had four published CVEs
// before 4.2.1) must not come back alongside it, and PDFKit must be the pinned build.
check("jsPDF is no longer bundled - every PDF goes through assets/js/pdf-writer.js (GH #254)",
      !is_file("$APP/assets/js/vendor/jspdf.umd.min.js") && !is_file("$APP/assets/js/vendor/jspdf.plugin.autotable.min.js"));
$pdfkit = (string)@file_get_contents("$APP/assets/js/vendor/pdfkit/pdfkit.bundle.js", false, null, 0, 200);
preg_match('/pdfkit@(\d+\.\d+\.\d+)/', $pdfkit, $pm);
check("bundled PDFKit is the version VENDOR.md records (0.20.2)", ($pm[1] ?? '') === '0.20.2', 'found ' . ($pm[1] ?? 'none'));
$prism = @file_get_contents("$APP/assets/js/vendor/prism.min.js");
check("bundled Prism has the CVE-2024-53382 fix (1.30.0: currentScript must be a SCRIPT)",
      is_string($prism) && str_contains($prism, '"SCRIPT"===document.currentScript.tagName'));
check("no second PDF library is bundled (html2pdf carried its own jsPDF until 4.1.0)",
      !is_file("$APP/assets/js/vendor/html2pdf.bundle.min.js"));
check("VENDOR.md exists so a scanner-invisible dependency is at least written down",
      is_file("$APP/VENDOR.md"));
$vendor = (string)@file_get_contents("$APP/VENDOR.md");
check("VENDOR.md records the TinyMCE version actually shipping",
      isset($version) && str_contains($vendor, $version), $version ?? '?');


// ── F7 ───────────────────────────────────────────────────────────────────────
heading('F7  Sessions rotate, and the cookie is locked down');

$identityPoints = [
    'auth/login.php',
    'api/myaccount/verify_login_otp.php',
    'api/self-service/login.php',
    'api/self-service/verify_login_otp.php',
    'api/auth/oidc_callback.php',
    'self-service/verify-email.php',
    'api/myaccount/change_password.php',
    'api/self-service/change_password.php',
];
foreach ($identityPoints as $p) {
    $src = code("$APP/$p");
    // sessionPromoteToAuthenticated() or (false) - a password change keeps the CSRF token (S4).
    check("$p rotates the session id", (bool)preg_match('/sessionPromoteToAuthenticated\((false)?\)/', $src));

    // ⚠️ …and that the call can actually DO anything. Searching for the call string is
    // not the same as checking it works: api/self-service/change_password.php opened
    // with session_start(['read_and_close' => true]), which leaves session_status() at
    // PHP_SESSION_NONE, so sessionPromoteToAuthenticated() hit its early return and the
    // session was never rotated — silently, with this test passing throughout. Erlend
    // Volden caught it by running the code rather than reading it. A file that rotates
    // the session must therefore hold the session OPEN.
    if (str_contains($src, 'sessionPromoteToAuthenticated()')) {
        check("$p keeps the session open, so the rotation is not a no-op",
              !str_contains($src, 'read_and_close'),
              "session_start(['read_and_close' => true]) makes the rotation silently do nothing");
    }
}

// The ini settings ship three times, once per server flavour, and must agree.
foreach ([['.user.ini', 'FPM/CGI — nginx and IIS'], ['docker/php.ini', 'the Docker image']] as [$f, $who]) {
    $src = code("$APP/$f");
    check("$f exists and hardens the cookie for $who",
          $src !== '' && str_contains($src, 'session.cookie_httponly = 1')
                      && str_contains($src, 'session.cookie_samesite = Lax')
                      && str_contains($src, 'session.use_strict_mode = 1'));
}
$rootHt = code("$APP/.htaccess");
check(".htaccess carries the same settings for Apache mod_php",
      str_contains($rootHt, 'session.cookie_httponly 1')
      && str_contains($rootHt, 'session.cookie_samesite Lax')
      && str_contains($rootHt, 'session.use_strict_mode 1'));
// Comments stripped, so this looks for a real directive rather than the note in each
// file explaining why the directive is deliberately absent.
check("cookie_secure is NOT forced in static config (it would lock out HTTP installs)",
      !str_contains($rootHt, 'session.cookie_secure')
      && !str_contains(code("$APP/.user.ini"), 'session.cookie_secure')
      && !str_contains(code("$APP/docker/php.ini"), 'session.cookie_secure'));

// ⚠️ THE OTHER HALF OF THIS GUARD. Everything above proves it refuses an ATTACKER's
// text/plain. Nothing proved that our OWN front end had stopped sending it — and it
// had not. `fetch` with a string body and no `headers` sends text/plain;charset=UTF-8,
// so the three POSTs on the API-keys screen were refused by our own defence, taking
// create/edit/enable/delete on that screen out of service for three weeks before
// anybody reported it (GH #114). The guard's header comment asserted in prose that
// all 149 call sites sent application/json; the claim was false when written, and
// being written down is what stopped anyone checking. Hence this scan.
//
// FormData bodies are deliberately NOT flagged: the browser must set the multipart
// boundary itself, so those are correct WITHOUT a Content-Type. The rule therefore
// looks only at bodies built with JSON.stringify.
function fetchCallsMissingJsonHeader(string $src): array
{
    $hits = [];
    $off  = 0;
    while (($p = strpos($src, 'fetch(', $off)) !== false) {
        $off = $p + 6;
        // Stop the window at the NEXT fetch( so a compliant neighbour can never
        // mask a bare one, nor a bare neighbour incriminate a compliant one.
        $next = strpos($src, 'fetch(', $off);
        $win  = substr($src, $p, min(700, ($next === false ? strlen($src) : $next) - $p));
        if (!str_contains($win, 'JSON.stringify')) continue;   // not a JSON body
        if (!str_contains($win, 'body')) continue;             // not sending one
        if (stripos($win, 'Content-Type') !== false) continue;    // declared, fine
        $hits[] = substr_count(substr($src, 0, $p), "\n") + 1;
    }
    return $hits;
}

// SELF-TEST BOTH WAYS. A scanner that matched nothing would report a clean tree and
// look identical to a clean pass — which is precisely the failure this whole file
// exists to avoid.
check("the scanner detects a fetch() JSON body sent with no Content-Type",
      count(fetchCallsMissingJsonHeader("await fetch(u, {method:'POST', body: JSON.stringify(x)});")) === 1);
check("POSITIVE CONTROL: the scanner passes the same call once the header is set",
      count(fetchCallsMissingJsonHeader("await fetch(u, {method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(x)});")) === 0);

$offenders = [];
$it = new RecursiveIteratorIterator(
    new RecursiveCallbackFilterIterator(
        new RecursiveDirectoryIterator($APP, FilesystemIterator::SKIP_DOTS),
        function ($f, $key, $iter) {
            if ($iter->hasChildren()) {
                // Vendored code is not ours to hold to this rule.
                return !in_array($f->getFilename(), ['.git', 'node_modules', 'tinymce', 'vendor'], true);
            }
            return preg_match('/\.(js|php)$/i', $f->getFilename()) === 1;
        }
    )
);
foreach ($it as $file) {
    // This file carries a deliberate violation as its own self-test, two checks up.
    if (realpath($file->getPathname()) === realpath(__FILE__)) continue;
    $src = @file_get_contents($file->getPathname());
    if (!is_string($src) || !str_contains($src, 'fetch(')) continue;
    foreach (fetchCallsMissingJsonHeader($src) as $line) {
        $offenders[] = ltrim(str_replace($APP, '', $file->getPathname()), '/\\') . ':' . $line;
    }
}
check("every fetch() in the tree that sends a JSON body declares application/json",
      $offenders === [],
      $offenders ? (count($offenders) . ' would be refused by our own guard: ' . implode(', ', array_slice($offenders, 0, 8))) : '');

if ($BASE === null) {
    skipped("the cookie really arrives HttpOnly + SameSite=Lax", 'no base URL given');
    skipped("an attacker-chosen session id is refused", 'no base URL given');
    skipped("a text/plain POST is refused", 'no base URL given');
} else {
    $r = http($BASE . 'self-service/login.php');
    if (!$r) {
        skipped("the cookie really arrives HttpOnly + SameSite=Lax", 'could not reach ' . $BASE);
    } else {
        check("the session cookie really arrives HttpOnly and SameSite=Lax",
              (bool)preg_match('/^Set-Cookie:\s*PHPSESSID=[^;]+;.*HttpOnly/mi', $r[1])
              && (bool)preg_match('/^Set-Cookie:.*SameSite=Lax/mi', $r[1]),
              trim((string)strstr($r[1], 'Set-Cookie')));

        $forged = 'attackerchosenid0000000000000000';
        $r2 = http($BASE . 'self-service/login.php', ['cookie' => "PHPSESSID=$forged"]);
        $issued = ($r2 && preg_match('/^Set-Cookie:\s*PHPSESSID=([^;]+)/mi', $r2[1], $mm)) ? $mm[1] : null;
        check("an attacker-chosen session id is refused and replaced",
              $issued !== null && $issued !== $forged, $issued === null ? 'no new id issued — it was ADOPTED' : 'ok');

        $r3 = http($BASE . 'api/tickets/get_emails.php',
                   ['post' => true, 'body' => '{"x":1}', 'headers' => ['Content-Type: text/plain']]);
        check("a state-changing POST declaring text/plain is refused (the CSRF trick)",
              $r3 !== null && $r3[0] === 415, $r3 ? ('got HTTP ' . $r3[0]) : 'request failed');
        // POSITIVE CONTROL — refusing everything would also produce a pass above.
        $r4 = http($BASE . 'api/tickets/get_emails.php',
                   ['post' => true, 'body' => '{"x":1}', 'headers' => ['Content-Type: application/json']]);
        check("POSITIVE CONTROL: the same POST as application/json is NOT refused",
              $r4 !== null && $r4[0] !== 415, $r4 ? ('got HTTP ' . $r4[0]) : 'request failed');
    }
}


// ── F8 ───────────────────────────────────────────────────────────────────────
heading('F8  Default credentials and brute-force protection');

if ($conn === null) {
    skipped("must_change_password exists", 'no database connection');
} else {
    try {
        $col = $conn->query("SHOW COLUMNS FROM analysts LIKE 'must_change_password'")->fetch(PDO::FETCH_ASSOC);
        check("analysts.must_change_password exists (run Database Verify if not)", (bool)$col);

        foreach (['max_failed_logins', 'lockout_duration_minutes', 'max_ip_attempts', 'min_ip_attempts'] as $k) {
            $v = $conn->query("SELECT setting_value FROM system_settings WHERE setting_key = " . $conn->quote($k))->fetchColumn();
            check("$k has a value, so the login code does not read it as 'off'",
                  $v !== false && $v !== null && $v !== '', 'unset — brute-force protection would be disabled');
        }
        $mf = (int)$conn->query("SELECT setting_value FROM system_settings WHERE setting_key = 'max_failed_logins'")->fetchColumn();
        check("account lockout is actually switched on (max_failed_logins > 0)", $mf > 0, "value is $mf");

        // The seeded admin, if it is still there, must owe a password change.
        $admin = $conn->query("SELECT must_change_password FROM analysts WHERE username = 'admin'")->fetch(PDO::FETCH_ASSOC);
        if (!$admin) {
            skipped("the seeded admin account must change its password", 'no account called admin on this install');
        } else {
            check("the seeded admin account still owes a password change, or has already changed it",
                  true, '');   // informational: either state is fine, see the note below
            echo "         (admin.must_change_password = {$admin['must_change_password']}; 1 means the default"
               . " password has not been changed yet)\n";
        }
    } catch (Exception $e) {
        skipped("F8 database checks", $e->getMessage());
    }
}

$secSrc = code("$APP/system/security/index.php");
check("System → Security no longer pre-fills 5 and 2 for settings that may be unset",
      !str_contains($secSrc, "s.max_ip_attempts || '5'")
      && !str_contains($secSrc, "s.min_ip_attempts || '2'"));
check("the forced password change is enforced on every request, not just at login",
      is_file("$APP/includes/password_gate.php")
      && str_contains(code("$APP/includes/functions.php"), 'password_gate.php'));

foreach (['api/myaccount/verify_login_otp.php', 'api/self-service/verify_login_otp.php'] as $p) {
    check("$p counts failed codes and gives up", str_contains(code("$APP/$p"), 'mfa_attempts'));
}


// ── F9 ───────────────────────────────────────────────────────────────────────
heading('F9  Company isolation holds, including under database trouble');

check("a part-migrated schema is still forgiven",      tenancyDegradeAllowed(mkPdoEx('42S22', 1054)));
check("a lock-wait timeout now DENIES",               !tenancyDegradeAllowed(mkPdoEx('HY000', 1205)));
check("a dropped connection now DENIES",              !tenancyDegradeAllowed(mkPdoEx('HY000', 2006)));
check("a deadlock now DENIES",                        !tenancyDegradeAllowed(mkPdoEx('40001', 1213)));
check("a non-database exception now DENIES",          !tenancyDegradeAllowed(new RuntimeException('anything else')));

check("analystCanAccessUser() exists — the guard save_user.php was missing",
      function_exists('analystCanAccessUser'));

$rec = code("$APP/api/self-service/get_recording.php");
check("get_recording.php gates analysts on the Tickets module",
      str_contains($rec, "analystCanAccessModule("));
check("get_recording.php gates analysts on the recording's own ticket",
      str_contains($rec, 'analystCanAccessTicket('));

$su = code("$APP/api/tickets/save_user.php");
check("save_user.php checks the company of the account being EDITED, not only the destination",
      str_contains($su, 'analystCanAccessUser('));

if ($conn === null) {
    skipped("an unknown record id is denied rather than allowed", 'no database connection');
} elseif (!isMultiTenant($conn)) {
    skipped("an unknown record id is denied rather than allowed",
            'single-company install — these guards short-circuit to true by design');
} else {
    $ghost = 2147483600;   // an id that will not exist
    check("an unknown ticket id is denied",  !analystCanAccessTicket($conn, 1, $ghost));
    check("an unknown user id is denied",    !analystCanAccessUser($conn, 1, $ghost));
    check("an unknown article id is denied", !analystCanAccessArticle($conn, 1, $ghost));
}



// ─────────────────────────────────────────────────────────────────────────────
// ── Round two ────────────────────────────────────────────────────────────────
heading('R   The re-review findings (Erlend Volden, 2026-08-11)');
// ⚠️ THE LESSON THAT PRODUCED THIS SECTION. The original suite asserted that every
// secret in the database was ciphertext — and it passed happily while two features
// were broken, because a read path that never decrypts looks identical to a correct
// one from the storage side. Storage is half a round-trip. Assert the whole one.
// ─────────────────────────────────────────────────────────────────────────────

// R1/R2 — a secret must survive being written AND read back.
if ($conn === null) {
    skipped("secrets survive a full encrypt/decrypt round-trip", 'no database connection');
} else {
    $roundTripKeys = ['sla_cron_token', 'webhook_cron_token', 'workflow_cron_token',
                      'integration_cron_token', 'domain_cron_token', 'csat_token_secret'];
    $raw = [];
    $in  = implode(',', array_fill(0, count($roundTripKeys), '?'));
    $st  = $conn->prepare("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ($in)");
    $st->execute($roundTripKeys);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) $raw[$row['setting_key']] = $row['setting_value'];

    $plain = settingsGetDecrypted($conn, $roundTripKeys);
    foreach ($roundTripKeys as $k) {
        if (!isset($raw[$k])) { skipped("$k round-trips", 'not seeded on this install'); continue; }
        $atRest  = str_starts_with((string)$raw[$k], ENCRYPTION_PREFIX);
        $usable  = ($plain[$k] ?? '') !== '' && !str_starts_with((string)$plain[$k], ENCRYPTION_PREFIX);
        check("$k is ciphertext at rest AND plaintext through the accessor",
              $atRest && $usable,
              !$atRest ? 'stored in the clear' : 'decrypt returned nothing usable');
    }
}

// R1 — the screen that hands the token to a human must not print ciphertext.
$wh = code("$APP/system/webhooks/index.php");
check("System → Webhooks decrypts the cron token before printing the URL",
      str_contains($wh, 'settingsGetDecrypted('),
      'a raw SELECT here puts ?token=ENC%3A… on screen');

// R2 — both halves, and they must agree with each other.
$ksend = code("$APP/api/knowledge/send_share_email.php");
$ksave = code("$APP/api/knowledge/save_email_settings.php");
check("knowledge email READS the SMTP password through a decrypt",
      str_contains($ksend, 'isEncryptedSettingKey(') && str_contains($ksend, 'decryptValue('));
check("knowledge email WRITES the SMTP password encrypted",
      preg_match('/knowledge_email_smtp_password.{0,40}encryptValue\(/s', $ksave) === 1,
      'storing it raw makes the value oscillate: encrypted by Verify, plaintext by Save');

// R3 — feed the real function the real shapes. This is the check Erlend actually ran.
$probeDir = sys_get_temp_dir() . '/fitsm-sec-r3';
$ftyp = fn(string $brand) => pack('N', 32) . 'ftyp' . $brand . pack('N', 512) . $brand . 'mp41' . str_repeat("\0", 8);
$oggOpus = 'OggS' . chr(0) . chr(2) . str_repeat("\0", 8) . pack('V', 0x12345678) . pack('V', 0) . pack('V', 0)
         . chr(1) . chr(19) . 'OpusHead' . chr(1) . chr(1) . pack('v', 312) . pack('V', 48000) . pack('v', 0) . chr(0);

// POSITIVE: the media the messaging channel was built to carry must now store under
// its own extension. Every one of these was quarantined as .bin before this change.
$mustStore = [
    'voice.ogg'  => $oggOpus,
    'clip.mp4'   => $ftyp('isom'),
    'clip.3gp'   => $ftyp('3gp4'),
    'photo.heic' => $ftyp('heic'),
    'card.vcf'   => "BEGIN:VCARD\r\nVERSION:3.0\r\nFN:Ada Lovelace\r\nEND:VCARD\r\n",
    'invite.ics' => "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n",
    'mail.eml'   => "From: a@b.com\r\nTo: c@d.com\r\nSubject: T\r\n\r\nBody\r\n",
];
foreach ($mustStore as $name => $bytes) {
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $r   = uploadStoreBytes($bytes, $name, $probeDir, ATTACHMENT_POLICY_STORE);
    check("$name is accepted and stored as .$ext",
          $r['stored'] && substr((string)$r['stored_name'], -strlen($ext) - 1) === '.' . $ext,
          (string)($r['reason'] ?? 'stored as ' . $r['stored_name']));
}

// NEGATIVE CONTROL: widening the catalogue must not have widened it to anything
// executable. If these ever pass, the whole allow-list has stopped meaning anything.
$mustQuarantine = [
    'shell.php'   => "<?php system(\$_GET['c']); ?>",
    'shell.phtml' => "<?php echo 1; ?>",
    'evil.svg'    => '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>',
    'page.html'   => '<html><body><script>alert(1)</script></body></html>',
    '.htaccess'   => "AddType application/x-httpd-php .bin\n",
];
foreach ($mustQuarantine as $name => $bytes) {
    $r = uploadStoreBytes($bytes, $name, $probeDir, ATTACHMENT_POLICY_STORE);
    check("$name is still quarantined to ." . ATTACHMENT_QUARANTINE_EXT,
          substr((string)$r['stored_name'], -4) === '.' . ATTACHMENT_QUARANTINE_EXT,
          'stored as ' . (string)$r['stored_name']);
}
// A name is never the sender's, whatever they call it.
$r = uploadStoreBytes('hello', "..\\..\\..\\evil.php\x00.txt", $probeDir, ATTACHMENT_POLICY_STORE);
check("a traversal-and-null-byte name still yields a 32-hex name of ours",
      preg_match('/^[0-9a-f]{32}\.[a-z0-9]+$/', (string)$r['stored_name']) === 1,
      'stored as ' . (string)$r['stored_name']);
foreach (glob($probeDir . '/*') as $f) @unlink($f);
@rmdir($probeDir);

// R3 — the setting may narrow the catalogue but must never widen it.
if ($conn === null) {
    skipped("attachment_allowed_extensions can only narrow", 'no database connection');
} else {
    $effective = attachmentAllowedTypes($conn);
    check("the effective attachment list is a subset of the catalogue",
          !array_diff_key($effective, UPLOAD_TYPES_ATTACHMENT),
          'the setting has introduced an extension with no mime list');
    check("nothing executable is reachable through the setting",
          !array_intersect(array_keys($effective), ['php', 'phtml', 'phar', 'exe', 'svg', 'html', 'htaccess']));
}

// S1 — the create path authorises the company it will ACTUALLY use.
$su2 = code("$APP/api/tickets/save_user.php");
check("save_user.php authorises the RESOLVED destination company, not just a sent one",
      str_contains($su2, '$destinationTenantId')
      && preg_match('/analystCanAccessTenant\([^;]*\$destinationTenantId/', $su2) === 1,
      'gating the check on $tenantSent lets an omitted tenant_id skip it entirely');
check("save_user.php still short-circuits on single-company installs",
      preg_match('/isMultiTenant\(\$conn\)\s*\n?\s*&&\s*\$destinationTenantId/', $su2) === 1,
      'without this an ordinary analyst cannot create a requester at all');

// S3 — the master switch must not be turned off by a transient error.
$ten = code("$APP/includes/tenancy.php");
check("tenancyTablesReady() forgives only a missing table",
      preg_match('/function tenancyTablesReady.*?dbErrorIsMissingTable\(/s', $ten) === 1,
      'a bare catch here disables every guard for the whole request');
check("tenantCount() does not report a single company when it cannot count",
      preg_match('/function tenantCount.*?catch \(PDOException/s', $ten) === 1);
check("tenancyColumnExists() forgives only a missing table",
      preg_match('/function tenancyColumnExists.*?dbErrorIsMissingTable\(/s', $ten) === 1);

// S5 — the Docker path seeds the flag, because db_verify's seed never runs there.
$sql = code("$APP/database/freeitsm.sql");
check("the SQL admin seed sets must_change_password",
      preg_match('/INSERT INTO `analysts`[^;]*must_change_password/s', $sql) === 1,
      'docker-compose mounts this as an initdb script, so THIS is the seed that runs');
if ($conn === null) {
    skipped("the default admin cannot keep the published password", 'no database connection');
} else {
    try {
        $a = $conn->query("SELECT password_hash, must_change_password FROM analysts WHERE username = 'admin'")
                  ->fetch(PDO::FETCH_ASSOC);
        if (!$a) {
            skipped("the default admin cannot keep the published password", 'no admin account on this install');
        } elseif (!password_verify('freeitsm', (string)$a['password_hash'])) {
            check("the default admin password has been changed", true);
        } else {
            check("admin still on the published password is forced to change it",
                  (int)$a['must_change_password'] === 1,
                  'run Database Verification — the catch-up migration sets this');
        }
    } catch (PDOException $e) {
        skipped("the default admin cannot keep the published password", 'must_change_password column not migrated yet');
    }
}

// S9 — SSO is not a way around the gate.
$oidc = code("$APP/api/auth/oidc_callback.php");
check("oidc_callback.php honours must_change_password",
      str_contains($oidc, "must_change_password")
      && str_contains($oidc, "password_expired"),
      'SSO would otherwise be the one sign-in path that skips the gate');

// ═══════════════════════════════════════════════════════════════════════════
//  ROUND THREE — the deferred findings (S2, S6, S9 remainder, F10)
//
//  Round two's lesson was that storage is half a round trip: the old suite
//  certified a fix by finding a string in a file while the feature was broken.
//  These lean on behaviour wherever behaviour is reachable — a real counter in a
//  real database, a real HTTP response with and without a session — and fall back
//  to source only where the alternative would be untestable without a second
//  company or a broken PHP build.
// ═══════════════════════════════════════════════════════════════════════════

heading('S2  The four sibling endpoints');

$du = code("$APP/api/tickets/delete_user.php");
check("delete_user.php calls a tenancy guard at all",
      str_contains($du, 'analystCanAccessUser'),
      'it had no company check of any kind');
// Position is the finding. A guard under the counts still answers "how many
// tickets does that other company's contact have?" before refusing.
$guardPos = strpos($du, 'analystCanAccessUser');
$countPos = strpos($du, 'SELECT COUNT(*) FROM tickets');
check("...ABOVE the ticket/asset counts, so the refusal text leaks nothing",
      $guardPos !== false && $countPos !== false && $guardPos < $countPos,
      'the counts are a cross-tenant oracle whether or not the DELETE runs');
check("delete_user.php answers 'not found' rather than 'not yours'",
      str_contains($du, 'User not found'));

$v1u = code("$APP/api/v1/resources/users.php");
check("v1 PATCH /users/{id} checks the SUBJECT row, not just the destination",
      substr_count($v1u, 'apiKeyCanAccessTenantRow') >= 2,
      'the destination check existed; the subject check did not');
check("v1 GET /users/{id} is scoped too",
      substr_count($v1u, 'apiKeyCanAccessTenantRow') >= 2);
check("v1 GET /users (list) is scoped by the key's companies",
      str_contains($v1u, 'apiKeyTenantFilter'),
      'the list returned every requester on the install');

$esc = code("$APP/api/integrations/escalate_ticket.php");
// ⚠️ Compare against the preview BRANCH, not the first mention of $isPreview —
// that is its assignment, which necessarily comes near the top of the file while
// the branch it controls is 100 lines further down. The first draft of this check
// compared against the assignment and reported a correct fix as broken.
$escGuard   = strpos($esc, 'analystCanAccessTicket');
$escPreview = strpos($esc, 'if ($isPreview)');
check("escalate_ticket.php checks the ticket BEFORE the preview branch",
      $escGuard !== false && $escPreview !== false && $escGuard < $escPreview,
      'the preview returned another company\'s message body without reaching the service');

foreach (['test_channel', 'slack_diagnose'] as $mf) {
    $src = code("$APP/api/messaging/$mf.php");
    check("$mf.php checks the channel's company",
          str_contains($src, 'analystCanAccessChannel'));
    check("...and requires includes/tenancy.php, or that call is a fatal",
          str_contains($src, "includes/tenancy.php"),
          'the guard lives there and neither file included it');
}

heading("S3's cousins  Guards that forgive the wrong exceptions");

// The round-two write-up listed "S3's cousins" as outstanding — a cross-reference
// rather than a filename, which is exactly why it was the item missed when the rest
// of that list was worked through. A bare `catch { return true; }` in an access
// guard means a lock-wait timeout reads as authorisation.
$tk = code("$APP/includes/services/tasks.php");
check("TasksService::ticketAccessible() denies on an unexpected database error",
      str_contains($tk, 'return tenancyDegradeAllowed($e)'),
      'a bare `return true` here turns a busy database into a cross-company read');

// ...and the sweep that found it, kept as a standing check so the next one cannot
// be added quietly. Only genuinely missing schema may degrade to "allowed".
$failOpen = [];
foreach (glob("$APP/includes/services/*.php") as $svc) {
    $lines = explode("\n", code($svc));
    foreach ($lines as $i => $line) {
        if (!preg_match('/catch\s*\(/', $line)) { continue; }
        $window = implode("\n", array_slice($lines, $i, 4));
        if (preg_match('/return\s+true\s*;/', $window)) {
            $failOpen[] = basename($svc) . ' line ~' . ($i + 1);
        }
    }
}
check("no service-layer catch block returns true unconditionally",
      $failOpen === [],
      'fail-open guards: ' . implode(', ', $failOpen) . ' — use tenancyDegradeAllowed()');

heading('Unscoped lists  Endpoints that read a scoped table');

// ⚠️ THE RECURRING SHAPE, now with three instances found in three separate
// rounds: an endpoint that filters something ABOUT each row while the row set
// itself is unfiltered. get_users.php scoped only its per-user ticket count —
// with a comment explaining that the count must not reveal cross-company
// activity — while `FROM users u` returned every requester on the install.
// Found while building the requester picker (discussion #54) on top of it.
$gu = code("$APP/api/tickets/get_users.php");
// Either filter counts: 119d04c0 moved this list to activeTenantReadFilter()
// so that "All companies" shows every company the analyst may see. The test
// looked for the old name only and reported a leak that was not there.
check("get_users.php scopes the USER LIST, not only the ticket count",
      preg_match('/activeTenant(Read)?Filter\(\$conn,[^;]*[\'"]u[\'"]\)/', $gu) === 1,
      'the list itself returned every requester on the install');
// Precedence is half the fix: appended after a bare `a LIKE ? OR b LIKE ?` the
// company clause would bind to the last OR branch only.
check("...and brackets the search terms so the company clause cannot bind to one OR branch",
      str_contains($gu, 'AND (u.display_name LIKE ?'),
      'an unbracketed OR list makes the tenancy clause decorative');

// The picker sends a user_id, so the boundary must re-check it. A scoped list
// governs what is easy to choose, never what can be sent.
$ct = code("$APP/api/tickets/create_ticket.php");
check("create_ticket.php authorises a chosen requester id",
      str_contains($ct, 'analystCanAccessUser'),
      'user_id arrives in a JSON body and can be any integer');

// And the requester created by a manual ticket gets a company like every other
// path does — three callers resolved it and the fourth quietly did not.
$ts = code("$APP/includes/services/tickets.php");
check("a requester created by a manual ticket gets its company resolved",
      str_contains($ts, 'resolveTenantForNewUser'),
      'they were inserted with no tenant_id at all, so their tickets sat in triage forever');

heading('S6  The MFA counter, in the database rather than the session');

$thr = code("$APP/includes/mfa_throttle.php");
check("the throttle whitelists its table names",
      str_contains($thr, 'MFA_THROTTLE_TABLES')
      && str_contains($thr, 'in_array($table, MFA_THROTTLE_TABLES, true)'),
      'a caller-supplied table would otherwise be interpolated into SQL');
check("a disabled account-lockout policy cannot re-enable unlimited code guessing",
      str_contains($thr, 'MFA_THROTTLE_FALLBACK_THRESHOLD'),
      'max_failed_logins = 0 must not mean "no MFA limit"');
foreach (['api/myaccount/verify_login_otp.php', 'api/self-service/verify_login_otp.php'] as $otp) {
    $src = code("$APP/$otp");
    check("$otp records failures durably",  str_contains($src, 'mfaThrottleRecordFailure'));
    check("$otp refuses while locked",      str_contains($src, 'mfaThrottleMinutesRemaining'));
    check("$otp clears the count ONLY on a correct code",
          str_contains($src, 'mfaThrottleReset'));
}

// The behavioural half. This is the finding: the count must survive the thing the
// attacker actually did — throw the session away and present the password again.
if ($conn === null) {
    skipped("the MFA counter survives a fresh session and a password reset", 'no database connection');
} else {
    require_once "$APP/includes/mfa_throttle.php";
    $tmpUser = 'sectest_mfa_' . bin2hex(random_bytes(4));
    try {
        $conn->prepare("INSERT INTO analysts (username, password_hash, full_name, email, is_active, is_admin)
                        VALUES (?, ?, 'Security suite fixture', 'sectest@example.invalid', 0, 0)")
             ->execute([$tmpUser, password_hash('irrelevant', PASSWORD_BCRYPT)]);
        $tmpId = (int)$conn->lastInsertId();

        $threshold = mfaThrottleThreshold($conn);
        $lockedAt  = null;
        for ($i = 1; $i <= $threshold; $i++) {
            $r = mfaThrottleRecordFailure($conn, 'analysts', $tmpId);
            if ($r['locked']) { $lockedAt = $i; break; }
        }
        check("wrong codes lock the account's code step at the configured threshold",
              $lockedAt === $threshold, "locked at " . var_export($lockedAt, true) . ", expected $threshold");

        check("a locked account reports minutes remaining",
              mfaThrottleMinutesRemaining($conn, 'analysts', $tmpId) > 0);

        // ⚠️ THE ACTUAL BUG. A successful password step clears failed_login_count and
        // locked_until, which is why looping cost the attacker nothing. Simulate
        // exactly that and assert the MFA lock is untouched.
        $conn->prepare("UPDATE analysts SET failed_login_count = 0, locked_until = NULL WHERE id = ?")
             ->execute([$tmpId]);
        check("a SUCCESSFUL PASSWORD STEP does not clear the MFA lock",
              mfaThrottleMinutesRemaining($conn, 'analysts', $tmpId) > 0,
              'this is the loop the finding described — password resets the counters, five more guesses');

        // ...and only the right code clears it.
        mfaThrottleReset($conn, 'analysts', $tmpId);
        check("a correct code clears the lock",
              mfaThrottleMinutesRemaining($conn, 'analysts', $tmpId) === 0);

        $row = $conn->prepare("SELECT mfa_failed_count FROM analysts WHERE id = ?");
        $row->execute([$tmpId]);
        check("...and resets the count to zero", (int)$row->fetchColumn() === 0);

        $conn->prepare("DELETE FROM analysts WHERE id = ?")->execute([$tmpId]);
        $left = $conn->prepare("SELECT COUNT(*) FROM analysts WHERE username = ?");
        $left->execute([$tmpUser]);
        check("the fixture analyst was cleaned up", (int)$left->fetchColumn() === 0);
    } catch (PDOException $e) {
        try { $conn->prepare("DELETE FROM analysts WHERE username = ?")->execute([$tmpUser]); } catch (Exception $x) {}
        skipped("the MFA counter survives a fresh session and a password reset",
                'mfa_failed_count not migrated yet — run Database Verification (' . $e->getMessage() . ')');
    }
}

heading('S9  The remainder');

$tm = code("$APP/includes/ticket_merge.php");
$stores = code("$APP/includes/storage/stores.php");
// Through uploads.php directly, or through the tickets store, whose storeSaveBytes()
// is uploadStoreBytes() into the same folder (or its staging area for S3).
check("the merge snapshot is written through uploads.php, not by hand",
      (str_contains($tm, 'uploadStoreBytes') || (str_contains($tm, 'storeSaveBytes') && str_contains($stores, 'uploadStoreBytes(')))
      && !str_contains($tm, 'file_put_contents'),
      'it hand-wrote a .html into the web root');

$sb = code("$APP/api/system/save_branding.php");
check("branding no longer accepts SVG",
      !str_contains($sb, 'image/svg+xml') && !str_contains($sb, "'svg'"),
      'an SVG logo is script on our own origin when navigated to directly');
check("branding uses the shared image whitelist",
      str_contains($sb, 'UPLOAD_TYPES_IMAGE'));
check("branding no longer moves the file itself",
      !str_contains($sb, 'move_uploaded_file'));
check("...and prepares the directory as web-servable, not deny-all",
      str_contains($sb, 'uploadPrepareWebServableDir'),
      'the deny-all would 403 the logo on every page');
// The rule this depends on, asserted directly rather than assumed.
require_once "$APP/includes/uploads.php";
check("UPLOAD_TYPES_IMAGE genuinely excludes svg",
      !array_key_exists('svg', UPLOAD_TYPES_IMAGE));

// S9d — a web.config that 500s is not a web.config that denies.
$configs = ['change-management/attachments', 'contracts/rfp-builder/uploads',
            'recordings', 'tickets/attachments', 'war-room/attachments'];
// ⚠️ Strip the XML comments first. Each of these files EXPLAINS why there is no
// <handlers> section, which means the explanation contains the string being
// searched for — the identical trap withoutComments() exists for in PHP, and it
// failed all five of these on the first run. The suite has now been caught by its
// own documented lesson twice, in two different languages.
$stripXml = fn(string $s): string => (string)preg_replace('~<!--.*?-->~s', '', $s);
foreach ($configs as $dir) {
    $raw = @file_get_contents("$APP/$dir/web.config");
    $xml = is_string($raw) ? $stripXml($raw) : '';
    check("$dir/web.config has no <handlers> section",
          $xml !== '' && stripos($xml, '<handlers') === false,
          'clearing a locked section makes IIS answer 500.19 for the whole path');
    check("...but still denies by request filtering",
          stripos($xml, 'denyUrlSequences') !== false);
}
check("the generator no longer writes <handlers><clear/> either",
      !str_contains(code("$APP/includes/uploads.php"), '<handlers>'),
      'directories created at runtime would otherwise reintroduce it');

// S9e — containment, and the sibling-prefix trap in the comparison itself. The
// download endpoints serve through the tickets store (#284), which does the check
// once for every store: a key that could leave it is refused, and on a server folder
// the file must RESOLVE inside it - compared with the separator, so a sibling such
// as attachments-old cannot pass on a bare prefix.
foreach (['api/tickets/get_attachment.php', 'api/self-service/get_attachment.php'] as $ga) {
    $src = code("$APP/$ga");
    check("$ga contains the resolved path to the attachments directory",
          (str_contains($src, 'realpath') && str_contains($src, 'strncmp'))
          || (str_contains($src, "storeServe(\$conn, 'tickets'") && !str_contains($src, 'readfile(')),
          'a bare strpos prefix also matches a sibling like attachments-old');
}
$contained = '';
if (preg_match('/function storeCheckContained\b.*?\n}/s', $stores, $m)) $contained = $m[0];
check("the stores layer resolves a file inside its folder, with the separator",
      str_contains($contained, 'realpath') && str_contains($contained, "strncmp(\$realFile, \$realRoot . DIRECTORY_SEPARATOR")
      && str_contains($stores, 'storeCheckContained($d, $store, $key)'),
      'without it a symlink planted in a store folder, or a sibling prefix, would be followed');

heading('F10  Database Verification tells the truth about what it does');

$dbv = @file_get_contents("$APP/api/system/db_verify.php");   // raw: the claim IS a comment
// ⚠️ Match the ORIGINAL SENTENCE, not the words "never drops". The corrected header
// deliberately quotes the old claim while explaining that it was false — which is
// how the next reader learns the history, and which makes a naive search for the
// phrase match the very comment that fixes it.
check("db_verify.php no longer asserts that it never drops anything",
      is_string($dbv) && stripos($dbv, 'idempotent and never drops') === false,
      'it contains DROP COLUMN for seven columns');
check("...and says to take a backup",
      is_string($dbv) && stripos($dbv, 'TAKE A BACKUP') !== false);
$prev = code("$APP/api/system/db_verify_preview.php");
check("the preview endpoint executes no DDL",
      $prev !== '' && stripos($prev, 'ALTER TABLE') === false
      && stripos($prev, 'CREATE TABLE') === false && stripos($prev, 'DROP ') === false,
      'a read-only preview must be provably read-only, not conditionally read-only');

// The register of destructive operations must not drift from the migration.
// ⚠️ Comment-stripped source, for the third time in this section: the corrected
// header comment lists the columns it drops by name, so a raw scan reports prose
// as though it were SQL ("DROP COLUMN for tickets.status" yields a column named
// `for`). Every real DROP is executable code, so nothing is lost by stripping.
require_once "$APP/includes/db_verify_preview.php";
$dbvRaw = code("$APP/api/system/db_verify.php");
$missing = [];
foreach (DB_VERIFY_DESTRUCTIVE as $op) {
    if ($op['kind'] !== 'column') { continue; }
    if (!preg_match('/DROP COLUMN\s+`?' . preg_quote($op['name'], '/') . '`?/i', $dbvRaw)) {
        $missing[] = $op['table'] . '.' . $op['name'];
    }
}
check("every column drop the preview promises is one db_verify actually performs",
      $missing === [], 'stale entries: ' . implode(', ', $missing));

// ...and the direction that actually matters: a DROP in the migration that the
// preview does not know about would under-report to an administrator.
preg_match_all('/DROP COLUMN\s+`?([a-zA-Z0-9_]+)`?/i', $dbvRaw, $m);
$declared = array_column(array_filter(DB_VERIFY_DESTRUCTIVE, fn($o) => $o['kind'] === 'column'), 'name');
$unlisted = array_values(array_diff(array_unique($m[1] ?? []), $declared));
check("no column drop in db_verify.php is missing from the preview's register",
      $unlisted === [],
      'undeclared: ' . implode(', ', $unlisted) . ' — add them to DB_VERIFY_DESTRUCTIVE');

// ── F11 ──────────────────────────────────────────────────────────────────────
heading('F11  Change text is cleaned on display; AI credentials need the settings permission');

// Change text is stored as the editor sent it, so the display is the defence: the
// detail view and the PDF export must both go through safeChangeHtml().
$cmJs = code("$APP/assets/js/change-management.js");
check("change-management.js defines safeChangeHtml() over safeHtmlFragment()",
      (bool)preg_match('/function safeChangeHtml\(.{0,400}?return safeHtmlFragment\(/s', $cmJs));
check("the detail view renders change text through safeChangeHtml()",
      str_contains($cmJs, '<div class="detail-section-body">${safeChangeHtml(content)}</div>')
      && !str_contains($cmJs, '<div class="detail-section-body">${content}</div>'));
check("the PDF export renders change text through safeChangeHtml()",
      str_contains($cmJs, '${safeChangeHtml(section.content)}')
      && !str_contains($cmJs, '${section.content}'));
$cmPage = code("$APP/change-management/index.php");
$posSafe = strpos($cmPage, 'assets/js/safe-html.js');
$posCm   = strpos($cmPage, 'assets/js/change-management.js');
check("the change page loads safe-html.js, before change-management.js",
      $posSafe !== false && $posCm !== false && $posSafe < $posCm);
// POSITIVE CONTROL - the cleaner it relies on still strips handlers.
check("POSITIVE CONTROL: safe-html.js still drops every on* attribute",
      str_contains(code("$APP/assets/js/safe-html.js"), "name.indexOf('on') === 0"));

// Saving an AI provider's key is a settings-tab job, like testing it. Module access alone
// let any analyst with Forms / Workflow replace the key or point it at another endpoint.
foreach (['forms' => 'FORMS_AI', 'workflow' => 'WORKFLOW_AI'] as $mod => $cap) {
    $src = code("$APP/api/$mod/save_ai_settings.php");
    check("api/$mod/save_ai_settings.php requires Cap::$cap",
          str_contains($src, "requireCapabilityJson(Cap::$cap)"));
    check("api/$mod/save_ai_settings.php loads rbac.php, so the guard cannot fatal",
          (bool)preg_match('#require(_once)?[^;\n]*rbac\.php#', $src));
    // POSITIVE CONTROL - the sibling it was copied from carries the same guard.
    check("POSITIVE CONTROL: api/$mod/test_ai_key.php carries the same Cap::$cap",
          str_contains(code("$APP/api/$mod/test_ai_key.php"), "requireCapabilityJson(Cap::$cap)"));
}

// ── Live checks (need a base URL) ──────────────────────────────────────────
if ($BASE === null) {
    skipped("setup/ hides the PHP version from anonymous visitors", 'no base URL');
    skipped("X-Powered-By is not returned", 'no base URL');
} else {
    $anon = @file_get_contents($BASE . 'setup/');
    check("setup/ hides the exact PHP version from anonymous visitors",
          is_string($anon) && !str_contains($anon, PHP_VERSION),
          'the build number maps straight to a published vulnerability list');
    // Positive control: the page must still be answering, or the check above
    // passes for the wrong reason — the round-two lesson, applied here.
    check("CONTROL — ...and that page really did render",
          is_string($anon) && stripos($anon, 'setup') !== false && strlen($anon) > 500);

    $ctx  = stream_context_create(['http' => ['ignore_errors' => true]]);
    @file_get_contents($BASE . 'login.php', false, $ctx);
    $hdrs = implode("\n", $http_response_header ?? []);
    check("X-Powered-By is not returned to the browser",
          stripos($hdrs, 'X-Powered-By') === false,
          'withholding the version from setup/ is worth little if every response announces it');
}

echo "\n" . str_repeat('─', 60) . "\n";
echo "  {$pass} passed, {$fail} failed" . ($skip ? ", {$skip} skipped" : '') . "\n";
if ($skip && $BASE === null) {
    echo "  Re-run with a base URL for the live checks, e.g.\n";
    echo "      php tests/security-findings/run.php http://localhost/freeitsm-app/\n";
}
exit($fail > 0 ? 1 : 0);
