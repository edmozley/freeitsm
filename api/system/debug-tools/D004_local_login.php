<?php
/**
 * Debug Tool D004 — Local login check (analyst or self-service user)
 *
 * Diagnoses why a username/email + password sign-in fails for a local account.
 * Built for the classic "I bulk-imported users with password hashes but they
 * can't log in" case: PHP's password_verify() only understands bcrypt/argon, so
 * an imported MD5/SHA-1/phpass/Django/etc. hash ALWAYS fails — until the
 * password is re-set in FreeITSM (which re-hashes with bcrypt).
 *
 * Pick the account type, enter the username/email, and OPTIONALLY the password.
 *   - No password  -> account-readiness checks (hash format, lockout, SSO pin…).
 *   - With password -> additionally runs password_verify(), and if that fails on
 *                      a non-bcrypt hash, tries to identify the foreign format by
 *                      testing whether the stored hash is e.g. MD5(password).
 *
 * SAFE: POST only (the password never lands in a URL/log), the password is
 * NEVER echoed, and the stored hash is NEVER printed (only its format/cost/
 * length). Read-only — writes nothing.
 *
 * Output: plain text, section-delimited with === HEADERS ===.
 */

@session_start();

$DIAG_ID   = 'D004';
$DIAG_NAME = 'Local login check';

// ---- helpers -----------------------------------------------------------

$sections = [];
function addSection(&$sections, $title, $body) {
    if (is_array($body)) $body = implode("\n", $body);
    $sections[] = "=== {$title} ===\n" . rtrim($body, "\n");
}
function yn($v) { return $v ? 'YES' : 'NO'; }
function okbad($v, $ok = 'OK', $bad = 'MISSING') { return $v ? $ok : $bad; }
function emit_and_exit($sections) {
    if (!headers_sent()) {
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-store');
    }
    echo implode("\n\n", $sections) . "\n";
    exit;
}

/**
 * Classify a stored password hash. Returns:
 *   ['label','php_native'(bool can password_verify check it),'note','raw_algo'(for foreign match test)]
 */
function classifyHash($h) {
    $h = (string)$h;
    if ($h === '') return ['label' => '(empty — no password set)', 'php_native' => false, 'note' => 'No hash stored. This is a passwordless account.', 'raw_algo' => null];
    if (preg_match('/^\$2[aby]\$(\d{2})\$/', $h, $m)) return ['label' => 'bcrypt (' . substr($h, 0, 4) . ', cost ' . $m[1] . ')', 'php_native' => true, 'note' => 'FreeITSM-native — password_verify() can check this.', 'raw_algo' => null];
    if (preg_match('/^\$argon2(id|i|d)\$/', $h, $m)) return ['label' => 'argon2' . $m[1], 'php_native' => true, 'note' => 'Argon2 — password_verify() can check this.', 'raw_algo' => null];
    if (strpos($h, '$1$') === 0)  return ['label' => 'md5crypt ($1$)', 'php_native' => 'maybe', 'note' => 'Unix md5crypt. Verifiable only if the host crypt() supports it — NOT what FreeITSM creates (bcrypt).', 'raw_algo' => null];
    if (strpos($h, '$5$') === 0)  return ['label' => 'sha256crypt ($5$)', 'php_native' => 'maybe', 'note' => 'Unix sha256crypt — platform-dependent; NOT what FreeITSM creates.', 'raw_algo' => null];
    if (strpos($h, '$6$') === 0)  return ['label' => 'sha512crypt ($6$)', 'php_native' => 'maybe', 'note' => 'Unix sha512crypt — platform-dependent; NOT what FreeITSM creates.', 'raw_algo' => null];
    if (preg_match('/^\$P\$|^\$H\$/', $h)) return ['label' => 'phpass portable ($P$/$H$)', 'php_native' => false, 'note' => 'WordPress/phpBB portable hash. password_verify() CANNOT check this — login will always fail.', 'raw_algo' => null];
    if (preg_match('/^pbkdf2_sha256\$|^pbkdf2_sha1\$|^bcrypt_sha256\$|^sha1\$|^md5\$|^argon2\$/', $h)) return ['label' => 'Django (' . substr($h, 0, strpos($h, '$')) . '$…)', 'php_native' => false, 'note' => 'Django/Werkzeug-style hash. password_verify() CANNOT check this — login will always fail.', 'raw_algo' => null];
    if (preg_match('/^\{(SSHA|SHA|SSHA256|SHA256|MD5)\}/i', $h, $m)) return ['label' => 'LDAP ' . $m[1], 'php_native' => false, 'note' => 'LDAP-style hash. password_verify() CANNOT check this — login will always fail.', 'raw_algo' => null];
    if (preg_match('/^[0-9a-f]{32}$/i', $h)) return ['label' => 'raw MD5 (32 hex)', 'php_native' => false, 'note' => 'Unsalted MD5. password_verify() CANNOT check this — login will always fail. FreeITSM needs bcrypt.', 'raw_algo' => 'md5'];
    if (preg_match('/^[0-9a-f]{40}$/i', $h)) return ['label' => 'raw SHA-1 (40 hex)', 'php_native' => false, 'note' => 'Unsalted SHA-1. password_verify() CANNOT check this — login will always fail. FreeITSM needs bcrypt.', 'raw_algo' => 'sha1'];
    if (preg_match('/^[0-9a-f]{64}$/i', $h)) return ['label' => 'raw SHA-256 (64 hex)', 'php_native' => false, 'note' => 'Unsalted SHA-256. password_verify() CANNOT check this — login will always fail. FreeITSM needs bcrypt.', 'raw_algo' => 'sha256'];
    if (preg_match('/^[0-9a-f]{128}$/i', $h)) return ['label' => 'raw SHA-512 (128 hex)', 'php_native' => false, 'note' => 'Unsalted SHA-512. password_verify() CANNOT check this — login will always fail. FreeITSM needs bcrypt.', 'raw_algo' => 'sha512'];
    return ['label' => 'unrecognised format', 'php_native' => false, 'note' => 'Not a recognised hash format. password_verify() will almost certainly fail.', 'raw_algo' => null];
}

// ---- 1. HEADER ---------------------------------------------------------

$type = strtolower(trim((string)($_POST['account_type'] ?? $_GET['account_type'] ?? '')));
if ($type !== 'analyst' && $type !== 'user') $type = '';
$identifier = trim((string)($_POST['identifier'] ?? $_GET['identifier'] ?? ''));
$password   = (string)($_POST['password'] ?? '');   // POST only; never echoed.
$hasPwInput = ($password !== '');

$now = gmdate('Y-m-d H:i:s') . ' UTC';
addSection($sections, "REPORT HEADER", [
    "Diagnostic   : {$DIAG_ID} — {$DIAG_NAME}",
    "Generated    : {$now}",
    "Generated by : analyst_id=" . ($_SESSION['analyst_id'] ?? '(not logged in)'),
    "Account type : " . ($type ?: '(not selected)'),
    "Identifier   : " . ($identifier === '' ? '(none supplied)' : $identifier),
    "Password     : " . ($hasPwInput ? 'supplied (verified below — never shown or logged)' : 'not supplied (account-readiness checks only)'),
    "Mode         : READ-ONLY and safe to share — the password is never echoed and the stored hash is never printed (only its format).",
]);

// ---- 2. AUTH GATE ------------------------------------------------------

if (!isset($_SESSION['analyst_id'])) {
    addSection($sections, "AUTH", "FAIL: not logged in. Log into FreeITSM in the same browser, then re-run.");
    emit_and_exit($sections);
}
if ($type === '') {
    addSection($sections, "INPUT", "FAIL: choose an account type (Analyst or Self-service user).");
    emit_and_exit($sections);
}
if ($identifier === '') {
    addSection($sections, "INPUT", "FAIL: enter the username (analyst) or email (self-service user).");
    emit_and_exit($sections);
}

// ---- 3. DATABASE CONNECTION -------------------------------------------

$rootCfg = realpath(__DIR__ . '/../../../config.php');
$conn = null; $connErr = null;
try {
    if ($rootCfg) @require_once $rootCfg;
    // Debug tools are administrators-only (issue #34). Fail closed.
    require_once __DIR__ . '/../../../includes/functions.php';
    try { $__dbgAdmin = !empty($_SESSION['analyst_id']) && analystIsAdmin(connectToDatabase(), (int)$_SESSION['analyst_id']); } catch (Throwable $e) { $__dbgAdmin = false; }
    if (!$__dbgAdmin) { http_response_code(403); if (!headers_sent()) header('Content-Type: text/plain; charset=utf-8'); echo "Administrator access required.\n"; exit; }
    if (defined('DB_SERVER') && defined('DB_NAME') && defined('DB_USERNAME') && defined('DB_PASSWORD')) {
        $conn = new PDO("mysql:host=" . DB_SERVER . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USERNAME, DB_PASSWORD);
        $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    } else { $connErr = 'DB constants not defined in config.php'; }
} catch (Throwable $e) { $connErr = $e->getMessage(); }
if (!$conn) {
    addSection($sections, "DATABASE CONNECTION", "FAILED: " . ($connErr ?? 'unknown error'));
    emit_and_exit($sections);
}
addSection($sections, "DATABASE CONNECTION", "Connect attempt : OK\nServer version  : " . $conn->getAttribute(PDO::ATTR_SERVER_VERSION) . "\nDatabase        : " . (defined('DB_NAME') ? DB_NAME : ''));

$colExists = function ($t, $c) use ($conn) {
    try { return (bool)$conn->query("SHOW COLUMNS FROM `{$t}` LIKE " . $conn->quote($c))->fetchColumn(); }
    catch (Throwable $e) { return false; }
};
$tableExists = function ($t) use ($conn) {
    try { return (bool)$conn->query("SHOW TABLES LIKE " . $conn->quote($t))->fetchColumn(); }
    catch (Throwable $e) { return false; }
};
$scalar = function ($sql, $params = []) use ($conn) {
    try { $s = $conn->prepare($sql); $s->execute($params); return $s->fetchColumn(); }
    catch (Throwable $e) { return false; }
};
$setting = function ($key, $default = null) use ($conn) {
    try { $s = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key=?"); $s->execute([$key]); $v = $s->fetchColumn(); return $v === false ? $default : $v; }
    catch (Throwable $e) { return $default; }
};

// ---- 4. SCHEMA READINESS ----------------------------------------------

$table = $type === 'analyst' ? 'analysts' : 'users';
$expectCols = $type === 'analyst'
    ? ['id','username','password_hash','full_name','email','is_active','totp_enabled','totp_secret','locked_until','failed_login_count','password_changed_datetime','trust_device_enabled','auth_provider_id']
    : ['id','email','display_name','password_hash','totp_enabled','totp_secret','auth_provider_id'];
$missing = [];
$tblOk = $tableExists($table);
if ($tblOk) { foreach ($expectCols as $c) { if (!$colExists($table, $c)) $missing[] = $c; } }
addSection($sections, "SCHEMA READINESS ({$table})", [
    "Table `{$table}`        : " . okbad($tblOk, 'present', 'MISSING'),
    "Expected columns      : " . ($tblOk ? ($missing ? 'missing: ' . implode(', ', $missing) : 'all present') : 'n/a'),
    ($type === 'analyst' ? "ip_login_bans table   : " . okbad($tableExists('ip_login_bans'), 'present', 'absent (IP lockouts off)') : "(self-service users have no lockout/IP-ban machinery)"),
]);

// ---- 5. GLOBAL LOGIN CONFIG -------------------------------------------

$localEnabled = ($setting('local_login_enabled', '1')) !== '0';
$ssoEnabled   = ($setting('sso_enabled', '0')) === '1';
$cfgLines = [
    "Allow local login (local_login_enabled) : " . yn($localEnabled) . ($localEnabled ? '' : '  <-- LOCAL LOGIN IS OFF (SSO-only); the password form is hidden (?local=1 still reaches it)'),
    "Single sign-on enabled                  : " . yn($ssoEnabled),
];
if ($type === 'analyst') {
    $cfgLines[] = "Max failed logins before lockout        : " . ($setting('max_failed_logins', '0') ?: '0 (disabled)');
    $cfgLines[] = "Lockout duration (minutes)              : " . ($setting('lockout_duration_minutes', '30'));
    $cfgLines[] = "Password expiry (days)                  : " . ($setting('password_expiry_days', '0') ?: '0 (never)');
    $cfgLines[] = "Max IP attempts before IP ban           : " . ($setting('max_ip_attempts', '0') ?: '0 (disabled)');
}
addSection($sections, "GLOBAL LOGIN CONFIG", $cfgLines);

// ---- 6. ACCOUNT LOOKUP -------------------------------------------------

if ($type === 'analyst') {
    $row = $scalarRow = null;
    try { $s = $conn->prepare("SELECT * FROM analysts WHERE username = ? LIMIT 1"); $s->execute([$identifier]); $row = $s->fetch(PDO::FETCH_ASSOC) ?: null; } catch (Throwable $e) {}
} else {
    try { $s = $conn->prepare("SELECT * FROM users WHERE LOWER(email) = ? LIMIT 1"); $s->execute([strtolower($identifier)]); $row = $s->fetch(PDO::FETCH_ASSOC) ?: null; } catch (Throwable $e) { $row = null; }
}
if (!$row) {
    addSection($sections, "ACCOUNT LOOKUP", "NOT FOUND: no {$type} matches " . ($type === 'analyst' ? "username '{$identifier}'" : "email '{$identifier}'") . ".\n  - Analyst usernames are matched exactly (case-sensitive); self-service emails are matched case-insensitively.\n  - If you imported this account, check the value actually landed in the expected column and table.");
    addSection($sections, "VERDICT", "Login fails because the account does not exist as searched. Fix the identifier (or the import), then re-run.");
    emit_and_exit($sections);
}
addSection($sections, "ACCOUNT LOOKUP", [
    "Found            : YES (id " . $row['id'] . ")",
    $type === 'analyst' ? "Username         : " . $row['username'] : "Email            : " . $row['email'],
    "Name             : " . ($row['full_name'] ?? $row['display_name'] ?? ''),
]);

// ---- 7. PASSWORD HASH ANALYSIS ----------------------------------------

$hash = (string)($row['password_hash'] ?? '');
$hashTrim = trim($hash);
$whitespace = ($hash !== $hashTrim);
$cls = classifyHash($hashTrim);
$nativeStr = $cls['php_native'] === true ? 'YES' : ($cls['php_native'] === 'maybe' ? 'PLATFORM-DEPENDENT' : 'NO');
$hashLines = [
    "Hash stored          : " . ($hash === '' ? 'NO (passwordless)' : 'YES'),
    "Detected format      : " . $cls['label'],
    "Length               : " . strlen($hash) . ($whitespace ? "  <-- WARNING: leading/trailing whitespace in the stored value (CSV import artefact?)" : ''),
    "password_verify-able : " . $nativeStr,
    "Note                 : " . $cls['note'],
];
if ($cls['php_native'] === true && function_exists('password_needs_rehash')) {
    $hashLines[] = "Needs rehash         : " . yn(password_needs_rehash($hashTrim, PASSWORD_BCRYPT));
}
addSection($sections, "PASSWORD HASH ANALYSIS", $hashLines);

// ---- 8. PASSWORD VERIFICATION (only if supplied) ----------------------

$verifyResult = null;        // true/false/null(not run)
$foreignMatch = null;        // 'md5' etc. if the stored hash == rawAlgo(password)
if ($hasPwInput) {
    if ($hash === '') {
        addSection($sections, "PASSWORD VERIFICATION", "Skipped: the account has no stored hash, so there is nothing to verify against.");
    } else {
        $verifyResult = password_verify($password, $hashTrim);
        $vLines = ["password_verify() result : " . ($verifyResult ? 'MATCH ✓' : 'NO MATCH ✗')];
        if (!$verifyResult) {
            // If the hash looks like a raw digest, test whether it's <algo>(password).
            // This is the smoking gun for "imported the wrong hash type".
            $cands = $cls['raw_algo'] ? [$cls['raw_algo']] : ['md5', 'sha1', 'sha256', 'sha512'];
            foreach ($cands as $algo) {
                $calc = hash($algo, $password);
                if (hash_equals(strtolower($hashTrim), strtolower($calc))) { $foreignMatch = $algo; break; }
            }
            if ($foreignMatch) {
                $vLines[] = "";
                $vLines[] = "*** ROOT CAUSE FOUND ***";
                $vLines[] = "The stored hash EQUALS " . strtoupper($foreignMatch) . "(the supplied password).";
                $vLines[] = "So the import stored a raw " . strtoupper($foreignMatch) . " hash, but FreeITSM signs in with";
                $vLines[] = "bcrypt via password_verify(), which cannot read a raw " . strtoupper($foreignMatch) . " digest — hence every";
                $vLines[] = "login fails until the password is re-set (which re-hashes it with bcrypt).";
                $vLines[] = "FIX: on import, hash with password_hash(\$plain, PASSWORD_BCRYPT) — not " . strtoupper($foreignMatch) . " —";
                $vLines[] = "or have these users use 'forgot password' / set a new password so FreeITSM re-hashes.";
            } else {
                $vLines[] = "";
                if ($cls['php_native'] === true) {
                    $vLines[] = "The hash is a valid bcrypt/argon hash but the supplied password doesn't match it —";
                    $vLines[] = "so either the password is wrong, or the hash belongs to a different password.";
                } else {
                    $vLines[] = "The stored hash isn't bcrypt/argon AND isn't a plain MD5/SHA digest of this password,";
                    $vLines[] = "so password_verify() can't read it. It was almost certainly produced by another system";
                    $vLines[] = "(" . $cls['label'] . "). These users must reset their password so FreeITSM re-hashes with bcrypt.";
                }
            }
        }
        addSection($sections, "PASSWORD VERIFICATION", $vLines);
    }
}

// ---- 9. ACCOUNT STATE & OTHER BLOCKERS --------------------------------

$blockers = [];
$stateLines = [];

if (!$localEnabled) $blockers[] = "Local login is disabled globally (SSO-only). The password form is hidden; use ?local=1 or turn 'Allow local login' on.";

$pin = !empty($row['auth_provider_id']) ? (int)$row['auth_provider_id'] : null;
if ($pin) {
    $pname = $scalar("SELECT display_name FROM auth_providers WHERE id=?", [$pin]);
    $stateLines[] = "SSO pin (auth_provider_id) : #{$pin} (" . ($pname ?: '?') . ")";
    $blockers[] = "This account is assigned to an SSO provider. SSO accounts sign in via the identity provider, and their local password is typically a random unusable value — local password login will fail by design.";
} else {
    $stateLines[] = "SSO pin (auth_provider_id) : none (pure local account)";
}

if ($hash === '') $blockers[] = "No password is set (passwordless). They must register / set a password before local login works.";
elseif ($cls['php_native'] === false) $blockers[] = "The stored hash (" . $cls['label'] . ") is not a format password_verify() can read — local login will always fail until re-hashed with bcrypt.";

$totpOn = !empty($row['totp_enabled']);
$stateLines[] = "TOTP / MFA enabled        : " . yn($totpOn) . ($totpOn ? '  (a correct password still then needs the 6-digit code)' : '');

if ($type === 'analyst') {
    $active = (int)($row['is_active'] ?? 1) === 1;
    $stateLines[] = "Active (is_active)        : " . yn($active);
    if (!$active) $blockers[] = "Account is inactive (is_active = 0) — login is refused.";

    if (!empty($row['locked_until'])) {
        $lockedFuture = false;
        try { $lockedFuture = (new DateTime($row['locked_until'])) > new DateTime('now', new DateTimeZone('UTC')); } catch (Throwable $e) {}
        $stateLines[] = "Locked until              : " . $row['locked_until'] . ($lockedFuture ? '  <-- LOCKED NOW' : '  (in the past — not locked)');
        if ($lockedFuture) $blockers[] = "Account is currently locked (too many failed attempts). It clears at the time shown, or reset failed_login_count / locked_until.";
    } else {
        $stateLines[] = "Locked until              : not locked";
    }
    $stateLines[] = "Failed login count        : " . ($row['failed_login_count'] ?? 0);

    $expDays = (int)$setting('password_expiry_days', '0');
    if ($expDays > 0 && !empty($row['password_changed_datetime'])) {
        try {
            $changed = new DateTime($row['password_changed_datetime']);
            $ageDays = (new DateTime('now', new DateTimeZone('UTC')))->diff($changed)->days;
            $expired = $ageDays >= $expDays;
            $stateLines[] = "Password age              : {$ageDays} day(s) (expiry at {$expDays}) " . ($expired ? '<-- EXPIRED → forced change' : '');
            if ($expired) $blockers[] = "Password has expired — the analyst is sent to a forced password change (not a hard failure, but not a normal login).";
        } catch (Throwable $e) {}
    }

    if ($tableExists('ip_login_bans')) {
        $activeBans = (int)$scalar("SELECT COUNT(*) FROM ip_login_bans WHERE banned_until > UTC_TIMESTAMP()");
        $stateLines[] = "Active IP bans (any IP)   : {$activeBans}" . ($activeBans ? "  (lockouts are per-IP; if the user's IP is banned, login is blocked before the password is even checked)" : '');
    }
}

addSection($sections, "ACCOUNT STATE", $stateLines);

// ---- 9b. ENCRYPTION KEY (only matters for the MFA/TOTP step) -----------
// Password hashes are NOT encrypted, so the key never affects password login.
// But totp_secret IS encrypted (decrypted at the 6-digit-code step), so a
// missing/wrong key fails login for MFA users AFTER a correct password.
$encLines = [];
$encReq = realpath(__DIR__ . '/../../../includes/encryption.php');
$encLoaded = false;
if ($encReq) { try { require_once $encReq; $encLoaded = function_exists('decryptValue') && function_exists('getEncryptionKey'); } catch (Throwable $e) {} }
if (!$encLoaded) {
    $encLines[] = "Encryption helper not loadable — skipped.";
} else {
    $keyPath = defined('ENCRYPTION_KEY_PATH') ? ENCRYPTION_KEY_PATH : '(unknown)';
    $keyFileOk = @file_exists($keyPath);
    $keyValid = false; $keyErr = '';
    if ($keyFileOk) { try { getEncryptionKey(); $keyValid = true; } catch (Throwable $e) { $keyErr = $e->getMessage(); } }
    $encLines[] = "Key file path        : " . $keyPath;
    $encLines[] = "Key file present     : " . yn($keyFileOk);
    $encLines[] = "Key valid (64 hex)   : " . yn($keyValid) . ($keyErr ? "  ({$keyErr})" : '');

    $totpSecret = (string)($row['totp_secret'] ?? '');
    if (!empty($row['totp_enabled']) && $totpSecret !== '') {
        if (strpos($totpSecret, 'ENC:') === 0) {
            $decOk = false; $decErr = '';
            try { $plain = decryptValue($totpSecret); $decOk = ($plain !== false && $plain !== null && $plain !== ''); }
            catch (Throwable $e) { $decErr = $e->getMessage(); }
            $encLines[] = "This user's TOTP secret: encrypted (ENC:) — decrypts with current key: " . yn($decOk) . ($decErr ? "  ({$decErr})" : '');
            if (!$decOk) $blockers[] = "MFA is on but this user's TOTP secret can't be decrypted with the current encryption key — a correct password will still FAIL at the 6-digit code step. Restore the correct key file (System → Encryption), then re-run. (Password login itself doesn't use the key.)";
        } else {
            $encLines[] = "This user's TOTP secret: stored in the clear (pre-encryption) — the key isn't needed for it.";
        }
    } else {
        $encLines[] = "Relevant to this account: NO — no encrypted MFA secret to decrypt, so the key doesn't affect this login.";
    }
}
addSection($sections, "ENCRYPTION KEY (MFA step only)", $encLines);

// ---- 10. VERDICT -------------------------------------------------------

$verdict = [];
if ($hasPwInput && $verifyResult === true && !$blockers) {
    $verdict[] = "The supplied password VERIFIES against the stored hash and no blockers were found → this login should succeed" . ($totpOn ? " (after the TOTP step)." : ".");
} elseif ($foreignMatch) {
    $verdict[] = "ROOT CAUSE: the stored hash is a raw " . strtoupper($foreignMatch) . " of the password (wrong hash type from import). FreeITSM needs bcrypt — re-hash on import, or have users reset their password. See the PASSWORD VERIFICATION section.";
} else {
    if ($blockers) {
        $verdict[] = "Login is blocked. Most likely cause(s):";
        foreach ($blockers as $b) $verdict[] = "  - " . $b;
    } elseif ($hasPwInput && $verifyResult === false) {
        $verdict[] = "The account and hash look fine, but the supplied password does not match. Likely a genuinely wrong password (or the hash is for a different password).";
    } else {
        $verdict[] = "No hard blocker detected from account state. " . ($hasPwInput ? "" : "Re-run WITH the password to confirm it actually verifies against the stored hash.");
    }
}
addSection($sections, "VERDICT", $verdict);

emit_and_exit($sections);
