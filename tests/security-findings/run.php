<?php
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
          is_string($src) && strpos($src, 'uploadStoreBytes(') !== false);
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
      strpos(code("$APP/setup/index.php"), "\$_SESSION['setup_access'] = true") === false);

$verifySrc = code("$APP/api/system/db_verify.php");
check("db_verify asks the database instead of trusting a session flag",
      strpos($verifySrc, 'installIsUnprovisioned(') !== false
      && strpos($verifySrc, "empty(\$_SESSION['setup_access'])") === false);

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
                  strpos($r1[2], '<strong>freeitsm</strong>') === false);
            check("the anonymous setup page offers no Database Verify button",
                  strpos($r1[2], 'id="dbVerifySection"') === false);
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
                if ($v !== null && $v !== '' && strpos($v, ENCRYPTION_PREFIX) !== 0) {
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
            if (strpos($row['setting_value'], ENCRYPTION_PREFIX) !== 0) $plainSettings[] = $row['setting_key'];
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
                  is_string($plain) && $plain !== '' && strpos($plain, ENCRYPTION_PREFIX) !== 0);
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
      strpos($inj['filename'], "\r") === false && strpos($inj['filename'], "\n") === false
      && strpos($inj['filename'], '"') === false, $inj['filename']);


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
}
check("VENDOR.md exists so a scanner-invisible dependency is at least written down",
      is_file("$APP/VENDOR.md"));
$vendor = (string)@file_get_contents("$APP/VENDOR.md");
check("VENDOR.md records the TinyMCE version actually shipping",
      isset($version) && strpos($vendor, $version) !== false, $version ?? '?');


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
    check("$p rotates the session id", strpos($src, 'sessionPromoteToAuthenticated()') !== false);
}

// The ini settings ship three times, once per server flavour, and must agree.
foreach ([['.user.ini', 'FPM/CGI — nginx and IIS'], ['docker/php.ini', 'the Docker image']] as [$f, $who]) {
    $src = code("$APP/$f");
    check("$f exists and hardens the cookie for $who",
          $src !== '' && strpos($src, 'session.cookie_httponly = 1') !== false
                      && strpos($src, 'session.cookie_samesite = Lax') !== false
                      && strpos($src, 'session.use_strict_mode = 1') !== false);
}
$rootHt = code("$APP/.htaccess");
check(".htaccess carries the same settings for Apache mod_php",
      strpos($rootHt, 'session.cookie_httponly 1') !== false
      && strpos($rootHt, 'session.cookie_samesite Lax') !== false
      && strpos($rootHt, 'session.use_strict_mode 1') !== false);
// Comments stripped, so this looks for a real directive rather than the note in each
// file explaining why the directive is deliberately absent.
check("cookie_secure is NOT forced in static config (it would lock out HTTP installs)",
      strpos($rootHt, 'session.cookie_secure') === false
      && strpos(code("$APP/.user.ini"), 'session.cookie_secure') === false
      && strpos(code("$APP/docker/php.ini"), 'session.cookie_secure') === false);

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
      strpos($secSrc, "s.max_ip_attempts || '5'") === false
      && strpos($secSrc, "s.min_ip_attempts || '2'") === false);
check("the forced password change is enforced on every request, not just at login",
      is_file("$APP/includes/password_gate.php")
      && strpos(code("$APP/includes/functions.php"), 'password_gate.php') !== false);

foreach (['api/myaccount/verify_login_otp.php', 'api/self-service/verify_login_otp.php'] as $p) {
    check("$p counts failed codes and gives up", strpos(code("$APP/$p"), 'mfa_attempts') !== false);
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
      strpos($rec, "analystCanAccessModule(") !== false);
check("get_recording.php gates analysts on the recording's own ticket",
      strpos($rec, 'analystCanAccessTicket(') !== false);

$su = code("$APP/api/tickets/save_user.php");
check("save_user.php checks the company of the account being EDITED, not only the destination",
      strpos($su, 'analystCanAccessUser(') !== false);

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


echo "\n" . str_repeat('─', 60) . "\n";
echo "  {$pass} passed, {$fail} failed" . ($skip ? ", {$skip} skipped" : '') . "\n";
if ($skip && $BASE === null) {
    echo "  Re-run with a base URL for the live checks, e.g.\n";
    echo "      php tests/security-findings/run.php http://localhost/freeitsm-app/\n";
}
exit($fail > 0 ? 1 : 0);
