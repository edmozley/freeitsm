<?php
/**
 * Debug Tool D003 — Self-Service SSO check (by email)
 *
 * Enter a requester's email address; this tool reports — end to end — whether
 * self-service sign-in is correctly wired for them:
 *   - schema readiness for the self-service login + SSO tables/columns,
 *   - global SSO config (sso_enabled / local_login_enabled, provider counts),
 *   - single- vs multi-tenant mode,
 *   - how the email resolves to a company (domain / specific-sender / freemail),
 *   - the user account state (exists / passwordless / TOTP / provider pin),
 *   - the predicted login outcome (local / sso / choose) — mirrors resolve_login,
 *   - provider health + a live, secret-free OIDC discovery test,
 *   - the exact redirect URI to register in the IdP.
 *
 * READ-ONLY. Writes nothing. NEVER prints secrets (client secrets, TOTP
 * secrets and password hashes are reported only as present / absent).
 *
 * Output: plain text, section-delimited with === HEADERS === for easy skimming.
 */

@session_start();

$DIAG_ID   = 'D003';
$DIAG_NAME = 'Self-Service SSO check (by email)';

// ---- helpers -----------------------------------------------------------

$sections = [];
function addSection(&$sections, $title, $body) {
    if (is_array($body)) $body = implode("\n", $body);
    $sections[] = "=== {$title} ===\n" . rtrim($body, "\n");
}
function yn($v) { return $v ? 'YES' : 'NO'; }
function okbad($v, $ok = 'OK', $bad = 'MISSING') { return $v ? $ok : $bad; }

/**
 * One discovery-endpoint line, printing the ADDRESS the provider published.
 *
 * Three things are worth saying about each, and only the first was ever said:
 *   - present or missing;
 *   - RELATIVE, which is the failure that masquerades as a bug in this app —
 *     a bare "/oidc/authorize" is resolved by the browser against THIS host, so
 *     the user lands here, gets a 404, and nothing on screen implicates the IdP;
 *   - a host that differs from the issuer's. Perfectly legal (Entra and Okta
 *     both do it), so it is a note and never a failure — but when somebody is
 *     staring at an unexpected domain it is the line that explains it.
 */
function endpointLine(string $key, array $doc, string $issuerUrl, string $what): string {
    $pad   = str_pad($key, 22);
    $value = isset($doc[$key]) ? trim((string)$doc[$key]) : '';
    if ($value === '') return "    {$pad}: MISSING  <-- required; sign-in cannot start without it";

    $parts   = parse_url($value);
    $scheme  = is_array($parts) ? strtolower($parts['scheme'] ?? '') : '';
    $host    = is_array($parts) ? ($parts['host'] ?? '') : '';
    $isAbs   = ($scheme === 'http' || $scheme === 'https') && $host !== '';
    $line    = "    {$pad}: " . maskGuids($value);

    if (!$isAbs) {
        return $line . "\n      ^^ NOT AN ABSOLUTE URL — this is the problem. The provider must publish"
                     . "\n         the full address (https://idp.example.com/...). A relative one sends"
                     . "\n         people to THIS server instead of to the provider. Fix it at the IdP.";
    }
    $issuerHost = parse_url(rtrim($issuerUrl, '/'), PHP_URL_HOST) ?: '';
    if ($issuerHost !== '' && strcasecmp($host, $issuerHost) !== 0) {
        $line .= "\n      note: different host from the issuer (" . maskGuids($issuerHost) . ") — normal for"
               . "\n            some providers, but worth confirming it is the one you expect.";
    }
    return $line . "\n      ({$what})";
}
function maskMiddle($s) {
    $s = (string)$s;
    if ($s === '') return '(empty)';
    if (strlen($s) <= 8) return substr($s, 0, 2) . '…';
    return substr($s, 0, 4) . '…' . substr($s, -4);
}
// Identifiers (client IDs, tenant GUIDs) aren't secrets, but this report is
// made to be shared with support — so partially mask them: enough to eyeball-
// verify, not the full value. Diagnostic signals (match/reachability) are
// computed on the real values and shown as YES/NO, so nothing is lost.
function maskGuids($s) {
    return preg_replace_callback('/[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}/', function ($m) {
        return substr($m[0], 0, 8) . '…' . substr($m[0], -4);
    }, (string)$s);
}
function maskId($s) {
    $s = (string)$s;
    if ($s === '') return '(empty!)';
    // GUIDs and long opaque ids → partial mask; short human-readable client
    // names (e.g. a Keycloak "freeitsm-app") aren't identifying, so show them.
    if (preg_match('/^[0-9a-fA-F-]{20,}$/', $s) || strlen($s) > 24) return maskMiddle($s);
    return $s;
}
function emit_and_exit($sections) {
    if (!headers_sent()) {
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-store');
    }
    echo implode("\n\n", $sections) . "\n";
    exit;
}

// ---- 1. HEADER ---------------------------------------------------------

$email = strtolower(trim((string)($_GET['email'] ?? $_POST['email'] ?? '')));
$now   = gmdate('Y-m-d H:i:s') . ' UTC';
addSection($sections, "REPORT HEADER", [
    "Diagnostic   : {$DIAG_ID} — {$DIAG_NAME}",
    "Generated    : {$now}",
    "Generated by : analyst_id=" . ($_SESSION['analyst_id'] ?? '(not logged in)'),
    "Email tested : " . ($email === '' ? '(none supplied)' : $email),
    "Mode         : READ-ONLY. Safe to share — secrets/hashes are reported present/absent only, and identifiers (client IDs, tenant GUIDs) are partially masked.",
]);

// ---- 2. AUTH GATE ------------------------------------------------------

if (!isset($_SESSION['analyst_id'])) {
    addSection($sections, "AUTH", "FAIL: not logged in. Log into FreeITSM in the same browser, then re-run.");
    emit_and_exit($sections);
}

// ---- 3. INPUT ----------------------------------------------------------

if ($email === '') {
    addSection($sections, "INPUT", "FAIL: no email supplied. Type the requester's email address into the box and click Run.");
    emit_and_exit($sections);
}
$emailValid = (bool)filter_var($email, FILTER_VALIDATE_EMAIL);
$domain = (strpos($email, '@') !== false) ? strtolower(trim(substr(strrchr($email, '@'), 1))) : '';
addSection($sections, "INPUT", [
    "Email           : {$email}",
    "Valid format    : " . yn($emailValid),
    "Domain          : " . ($domain !== '' ? $domain : '(none)'),
]);
if (!$emailValid) {
    addSection($sections, "VERDICT", "Stopped: the email address is not a valid format. Fix it and re-run.");
    emit_and_exit($sections);
}

// ---- 4. DATABASE CONNECTION -------------------------------------------

$rootCfg = realpath(__DIR__ . '/../../../config.php');
$conn = null; $connErr = null;
try {
    if ($rootCfg) @require_once $rootCfg;
    // Debug tools are administrators-only (issue #34). Fail closed.
    require_once __DIR__ . '/../../../includes/functions.php';
    try { $__dbgAdmin = !empty($_SESSION['analyst_id']) && analystIsAdmin(connectToDatabase(), (int)$_SESSION['analyst_id']); } catch (Throwable $e) { $__dbgAdmin = false; }
    if (!$__dbgAdmin) { http_response_code(403); if (!headers_sent()) header('Content-Type: text/plain; charset=utf-8'); echo "Administrator access required.\n"; exit; }
    if (defined('DB_SERVER') && defined('DB_NAME') && defined('DB_USERNAME') && defined('DB_PASSWORD')) {
        $conn = new PDO("mysql:host=" . DB_SERVER . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USERNAME, DB_PASSWORD, dbConnectionOptions());   // UTC session — config.php
        $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    } else {
        $connErr = 'DB constants not defined in config.php';
    }
} catch (Throwable $e) { $connErr = $e->getMessage(); }

if (!$conn) {
    addSection($sections, "DATABASE CONNECTION", "FAILED: " . ($connErr ?? 'unknown error'));
    emit_and_exit($sections);
}
addSection($sections, "DATABASE CONNECTION", [
    "Connect attempt : OK",
    "Server version  : " . $conn->getAttribute(PDO::ATTR_SERVER_VERSION),
    "Database        : " . (defined('DB_NAME') ? DB_NAME : ''),
]);

// Introspection helpers bound to this connection.
$tableExists = function ($t) use ($conn) {
    try { return (bool)$conn->query("SHOW TABLES LIKE " . $conn->quote($t))->fetchColumn(); }
    catch (Throwable $e) { return false; }
};
$colExists = function ($t, $c) use ($conn) {
    try { return (bool)$conn->query("SHOW COLUMNS FROM `{$t}` LIKE " . $conn->quote($c))->fetchColumn(); }
    catch (Throwable $e) { return false; }
};
$scalar = function ($sql, $params = []) use ($conn) {
    try { $s = $conn->prepare($sql); $s->execute($params); return $s->fetchColumn(); }
    catch (Throwable $e) { return false; }
};
$rows = function ($sql, $params = []) use ($conn) {
    try { $s = $conn->prepare($sql); $s->execute($params); return $s->fetchAll(PDO::FETCH_ASSOC); }
    catch (Throwable $e) { return []; }
};

// ---- 5. SCHEMA READINESS ----------------------------------------------

$schema = [
    'users'                => ['id','email','display_name','preferred_name','password_hash','totp_secret','totp_enabled','auth_provider_id'],
    'user_sso_identities'  => ['id','user_id','provider_id','subject','email','linked_datetime','last_login_datetime'],
    // `protocol` matters here even though this tool is about OIDC: oidc_login.php
    // refuses anything that is not 'oidc', so a build missing the column would
    // fail in a way this report could not explain.
    'auth_providers'       => ['id','display_name','protocol','issuer_url','client_id','client_secret','scopes','enabled','auto_create_users','require_verified_email','tenant_id'],
    'system_settings'      => ['setting_key','setting_value'],
];
$schemaLines = [];
$schemaOk = true;
foreach ($schema as $tbl => $cols) {
    if (!$tableExists($tbl)) { $schemaLines[] = sprintf("  [%-20s] TABLE MISSING", $tbl); $schemaOk = false; continue; }
    $missing = [];
    foreach ($cols as $c) { if (!$colExists($tbl, $c)) $missing[] = $c; }
    if ($missing) { $schemaLines[] = sprintf("  [%-20s] OK, missing columns: %s", $tbl, implode(', ', $missing)); $schemaOk = false; }
    else          { $schemaLines[] = sprintf("  [%-20s] OK (all expected columns present)", $tbl); }
}
// Routing tables (only required for multi-tenant routing).
$routingTables = ['tenants','tenant_domains','tenant_sender_addresses'];
$routingLines = [];
foreach ($routingTables as $t) { $routingLines[] = sprintf("  [%-22s] %s", $t, okbad($tableExists($t), 'present', 'absent (single-tenant only)')); }
// Key constraints (best-effort via information_schema).
$fkUsers = $scalar("SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=? AND TABLE_NAME='users' AND CONSTRAINT_NAME='fk_users_auth_provider'", [DB_NAME]);
$uqLink  = $scalar("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=? AND TABLE_NAME='user_sso_identities' AND INDEX_NAME='uq_user_sso_provider_subject'", [DB_NAME]);
addSection($sections, "SCHEMA READINESS (self-service login + SSO)", array_merge(
    $schemaLines,
    ["", "Routing tables (multi-tenant):"],
    $routingLines,
    ["", "Key constraints:",
     "  users.auth_provider_id FK (fk_users_auth_provider) : " . okbad($fkUsers, 'present', 'absent'),
     "  user_sso_identities unique (provider, subject)      : " . okbad($uqLink, 'present', 'absent'),
     "", "Overall core schema: " . ($schemaOk ? 'OK' : 'PROBLEMS FOUND (see above) — run System → Database verification')]
));

// ---- 6. GLOBAL SSO CONFIG ---------------------------------------------

$ssoEnabled   = ($scalar("SELECT setting_value FROM system_settings WHERE setting_key='sso_enabled'") ?: '0') === '1';
$localEnabled = ($scalar("SELECT setting_value FROM system_settings WHERE setting_key='local_login_enabled'") ?: '1') !== '0';
$provTotal    = (int)$scalar("SELECT COUNT(*) FROM auth_providers");
$provEnabled  = (int)$scalar("SELECT COUNT(*) FROM auth_providers WHERE enabled=1");
$provGlobal   = (int)$scalar("SELECT COUNT(*) FROM auth_providers WHERE enabled=1 AND tenant_id IS NULL");
$provTenant   = (int)$scalar("SELECT COUNT(*) FROM auth_providers WHERE enabled=1 AND tenant_id IS NOT NULL");
addSection($sections, "GLOBAL SSO CONFIG", [
    "Enable single sign-on (sso_enabled) : " . yn($ssoEnabled) . ($ssoEnabled ? '' : '  <-- SSO is OFF; everyone gets local login'),
    "Allow local login                   : " . yn($localEnabled),
    "Providers configured                : {$provTotal} ({$provEnabled} enabled)",
    "  - global / MSP-internal (analysts): {$provGlobal}",
    "  - company-owned (portal)          : {$provTenant}",
]);

// ---- 7. TENANCY MODE ---------------------------------------------------

$companyCount = $tableExists('tenants') ? (int)$scalar("SELECT COUNT(*) FROM tenants") : 1;
$multiTenant  = $companyCount > 1;
addSection($sections, "TENANCY MODE", [
    "Companies        : {$companyCount}",
    "Mode             : " . ($multiTenant ? 'MULTI-TENANT (N>1) — portal login is email-first, routed by company' : 'SINGLE-COMPANY (N=1) — portal shows provider buttons up front'),
]);

// ---- 8. EMAIL -> COMPANY ROUTING --------------------------------------

$freemailBuiltins = ['gmail.com','googlemail.com','outlook.com','hotmail.com','hotmail.co.uk','live.com','live.co.uk','msn.com','yahoo.com','yahoo.co.uk','ymail.com','icloud.com','me.com','mac.com','aol.com','protonmail.com','proton.me','gmx.com','gmx.co.uk','mail.com','zoho.com','yandex.com','fastmail.com','btinternet.com','sky.com','talktalk.net','virginmedia.com','ntlworld.com'];
$customFreemail = $tableExists('freemail_domains') ? array_map(function ($r) { return strtolower($r['domain']); }, $rows("SELECT domain FROM freemail_domains")) : [];
$isFreemail = in_array($domain, $freemailBuiltins, true) || in_array($domain, $customFreemail, true);

$senderTenant = $tableExists('tenant_sender_addresses') ? $scalar("SELECT tenant_id FROM tenant_sender_addresses WHERE email=?", [$email]) : false;
$domainTenant = ($domain !== '' && $tableExists('tenant_domains')) ? $scalar("SELECT tenant_id FROM tenant_domains WHERE domain=?", [$domain]) : false;
$routedTenant = $senderTenant !== false && $senderTenant !== null ? (int)$senderTenant
              : ($domainTenant !== false && $domainTenant !== null ? (int)$domainTenant : null);
$routedTenantName = $routedTenant ? ($scalar("SELECT name FROM tenants WHERE id=?", [$routedTenant]) ?: '(unknown)') : null;

addSection($sections, "EMAIL -> COMPANY ROUTING", [
    "Domain                       : " . ($domain ?: '(none)'),
    "Freemail / personal domain   : " . yn($isFreemail) . ($isFreemail ? '  (never auto-mapped to a company by domain)' : ''),
    "Exact sender-address mapping : " . ($senderTenant ? "tenant #{$senderTenant}" : 'none'),
    "Domain -> company mapping    : " . ($domainTenant ? "tenant #{$domainTenant}" : 'none'),
    "Resolved company            : " . ($routedTenant ? "#{$routedTenant} ({$routedTenantName})" : 'NONE — this email maps to no company → local login'),
]);

// ---- 9. USER ACCOUNT ---------------------------------------------------

$user = $rows("SELECT * FROM users WHERE LOWER(email)=? LIMIT 1", [$email]);
$user = $user[0] ?? null;
$pinProviderId = null;
if ($user) {
    $hasPw   = !empty($user['password_hash']);
    $totpOn  = !empty($user['totp_enabled']);
    $hasSec  = !empty($user['totp_secret']);
    $pinProviderId = !empty($user['auth_provider_id']) ? (int)$user['auth_provider_id'] : null;
    $pinName = $pinProviderId ? ($scalar("SELECT display_name FROM auth_providers WHERE id=?", [$pinProviderId]) ?: '(provider not found)') : null;
    $pinOn   = $pinProviderId ? (bool)$scalar("SELECT enabled FROM auth_providers WHERE id=?", [$pinProviderId]) : false;
    $links   = $rows("SELECT s.provider_id, p.display_name, s.subject, s.last_login_datetime FROM user_sso_identities s LEFT JOIN auth_providers p ON p.id=s.provider_id WHERE s.user_id=?", [(int)$user['id']]);
    $lines = [
        "users row exists            : YES (id " . $user['id'] . ")",
        "Display / preferred name    : " . trim(($user['preferred_name'] ?? '') . ' / ' . ($user['display_name'] ?? '')),
        "Local password set          : " . yn($hasPw) . ($hasPw ? '' : '  (passwordless — e.g. created from a ticket, never registered)'),
        "TOTP enabled / secret stored: " . yn($totpOn) . ' / ' . yn($hasSec),
        "SSO pin (auth_provider_id)  : " . ($pinProviderId ? "#{$pinProviderId} ({$pinName}) — enabled: " . yn($pinOn) : 'none (not pinned to a provider)'),
        "Linked SSO identities       : " . count($links),
    ];
    foreach ($links as $l) {
        $lines[] = "    provider #{$l['provider_id']} (" . ($l['display_name'] ?? '?') . "), subject " . maskMiddle($l['subject']) . ", last login " . ($l['last_login_datetime'] ?: 'never');
    }
    addSection($sections, "USER ACCOUNT", $lines);
} else {
    addSection($sections, "USER ACCOUNT", [
        "users row exists            : NO",
        "Meaning                     : no self-service account for this email yet. They can be created on first SSO sign-in if the matched provider has auto-create on, or by registering / raising a ticket.",
    ]);
}

// ---- 10. RESOLUTION PREDICTION (mirrors resolve_login.php, portal=self-service) ----

$predMode = 'local'; $predDetail = []; $predProviders = [];
if (!$ssoEnabled) {
    $predDetail[] = "SSO is disabled globally → LOCAL (email + password).";
} else {
    // (1) Per-user pin.
    if ($user && $pinProviderId && $scalar("SELECT enabled FROM auth_providers WHERE id=?", [$pinProviderId])) {
        $predMode = 'sso';
        $predProviders[] = ['id' => $pinProviderId, 'name' => $scalar("SELECT display_name FROM auth_providers WHERE id=?", [$pinProviderId])];
        $predDetail[] = "Matched by per-user pin (users.auth_provider_id) → straight to provider #{$pinProviderId}.";
    } elseif ($multiTenant && $routedTenant) {
        $tp = $rows("SELECT id, display_name FROM auth_providers WHERE tenant_id=? AND enabled=1 ORDER BY sort_order, display_name", [$routedTenant]);
        if (count($tp) === 1)      { $predMode = 'sso';    $predProviders[] = ['id' => $tp[0]['id'], 'name' => $tp[0]['display_name']]; $predDetail[] = "Company #{$routedTenant} has exactly one enabled IdP → routed straight to it."; }
        elseif (count($tp) > 1)    { $predMode = 'choose'; foreach ($tp as $t) $predProviders[] = ['id' => $t['id'], 'name' => $t['display_name']]; $predDetail[] = "Company #{$routedTenant} has " . count($tp) . " enabled IdPs → user is shown a picker."; }
        else                       { $predDetail[] = "Company #{$routedTenant} has no enabled IdP → LOCAL."; }
    } elseif ($multiTenant) {
        $predDetail[] = "Email maps to no company → LOCAL.";
    } else {
        // Single-company: the portal shows global provider buttons; routing is by the pin only.
        $predDetail[] = "Single-company install: the portal shows provider buttons up front; email-first only routes a user already pinned to a provider. This email is " . ($user && $pinProviderId ? "pinned." : "not pinned → starts at LOCAL / button choice.");
    }
}
$predLine = strtoupper($predMode);
if ($predProviders) {
    $names = array_map(function ($p) { return "#{$p['id']} " . $p['name']; }, $predProviders);
    $predLine .= ' → ' . implode($predMode === 'choose' ? ' OR ' : ' ', $names);
}
addSection($sections, "PREDICTED LOGIN OUTCOME", array_merge(
    ["Predicted mode  : {$predLine}", ""],
    $predDetail,
    ["", "(This mirrors api/auth/resolve_login.php for portal=self-service.)"]
));

// ---- 11. PROVIDER HEALTH + DISCOVERY (secret-free) ---------------------

// Which providers are relevant to this email? The pin + the routed company's
// providers (multi-tenant) or the global providers (single-company).
$relevantIds = [];
if ($pinProviderId) $relevantIds[] = $pinProviderId;
if ($multiTenant && $routedTenant) {
    foreach ($rows("SELECT id FROM auth_providers WHERE tenant_id=? AND enabled=1", [$routedTenant]) as $r) $relevantIds[] = (int)$r['id'];
} elseif (!$multiTenant) {
    foreach ($rows("SELECT id FROM auth_providers WHERE tenant_id IS NULL AND enabled=1") as $r) $relevantIds[] = (int)$r['id'];
}
$relevantIds = array_values(array_unique($relevantIds));

$sslVerify = defined('SSL_VERIFY_PEER') ? SSL_VERIFY_PEER : true;
$discover = function ($issuer) use ($sslVerify) {
    $url = rtrim($issuer, '/') . '/.well-known/openid-configuration';
    if (!function_exists('curl_init')) return ['ok' => false, 'error' => 'curl not available', 'url' => $url];
    $ch = curl_init();
    curl_setopt_array($ch, [CURLOPT_URL => $url, CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 8, CURLOPT_HTTPHEADER => ['Accept: application/json']]);
    sslApplyCurl($ch);
    $body = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch); curl_close($ch);
    if ($body === false || $err) return ['ok' => false, 'error' => ($err ?: 'no response'), 'url' => $url];
    if ($code !== 200) return ['ok' => false, 'error' => "HTTP {$code}", 'url' => $url];
    $doc = json_decode($body, true);
    if (!is_array($doc)) return ['ok' => false, 'error' => 'invalid JSON from discovery endpoint', 'url' => $url];
    return ['ok' => true, 'doc' => $doc, 'url' => $url];
};

if (!$relevantIds) {
    addSection($sections, "PROVIDER HEALTH + DISCOVERY", "No SSO provider is relevant to this email (it would use local login), so there is nothing to test here.");
} else {
    $provBlocks = [];
    foreach ($relevantIds as $pid) {
        $p = $rows("SELECT * FROM auth_providers WHERE id=?", [$pid]);
        $p = $p[0] ?? null;
        if (!$p) continue;
        $owner = empty($p['tenant_id']) ? 'global / internal' : ('company #' . $p['tenant_id'] . ' (' . ($scalar("SELECT name FROM tenants WHERE id=?", [$p['tenant_id']]) ?: '?') . ')');
        $block = [
            "Provider #{$p['id']} — {$p['display_name']}",
            "  Enabled                 : " . yn($p['enabled']),
            "  Protocol                : " . ($p['protocol'] ?? 'oidc')
                . ((($p['protocol'] ?? 'oidc') !== 'oidc') ? "  <-- not OIDC; the SSO login flow refuses this and offers a password instead" : ''),
            "  Owner                   : {$owner}",
            "  Issuer URL              : " . maskGuids($p['issuer_url']),
            "  Client ID               : " . maskId($p['client_id']),
            "  Client secret           : " . (!empty($p['client_secret']) ? 'CONFIGURED (not shown)' : 'NOT SET'),
            "  Scopes                  : " . ($p['scopes'] ?: '(default)'),
            "  Auto-create users       : " . yn($p['auto_create_users']),
            "  Require verified email  : " . yn($p['require_verified_email']),
        ];
        // Live, secret-free discovery test.
        $d = $discover($p['issuer_url']);
        if (!$d['ok']) {
            $block[] = "  Discovery               : FAIL — " . $d['error'];
            $block[] = "    tried: " . maskGuids($d['url']);
        } else {
            $doc = $d['doc'];
            $issMatch = isset($doc['issuer']) && rtrim((string)$doc['issuer'], '/') === rtrim((string)$p['issuer_url'], '/');
            $block[] = "  Discovery               : OK (" . maskGuids($d['url']) . ")";
            // Deliberately NOT phrased as a blocker. Token validation compares
            // the ID token's `iss` against the DISCOVERY DOCUMENT's issuer, not
            // against the value typed in here (includes/oidc.php), so this
            // difference does not by itself stop anyone signing in. It is a
            // configuration smell worth showing — most often the www / apex
            // form of the same domain — and claiming it breaks login would send
            // an administrator hunting the wrong thing.
            $block[] = "    issuer match          : " . yn($issMatch)
                . ($issMatch ? '' : "  <-- the provider calls itself \"" . maskGuids((string)($doc['issuer'] ?? '?'))
                                  . "\". Sign-in still works (the ID token is checked against the provider's own"
                                  . "\n                            value, not this one), but the two are usually meant to match.");
            // Print the VALUES, not just present/absent. "authorization_endpoint:
            // present" tells you nothing about the one fact that decides where the
            // browser actually goes, and a wrong address here looks exactly like a
            // bug in this application (issue #117).
            $block[] = endpointLine('authorization_endpoint', $doc, $p['issuer_url'], 'this is the address the browser is sent to');
            $block[] = endpointLine('token_endpoint',         $doc, $p['issuer_url'], 'this server calls it directly, back-channel');
            $block[] = endpointLine('jwks_uri',               $doc, $p['issuer_url'], 'signing keys for the ID token');
            $block[] = "    end_session_endpoint  : " . okbad(!empty($doc['end_session_endpoint']), 'present', 'absent (single logout will be skipped)');
        }
        $provBlocks[] = implode("\n", $block);
    }
    addSection($sections, "PROVIDER HEALTH + DISCOVERY", implode("\n\n", $provBlocks));
}

// ---- 12. REDIRECT URI --------------------------------------------------

$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$base   = defined('BASE_URL') ? BASE_URL : '/';
$redirectUri = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $base . 'api/auth/oidc_callback.php';
addSection($sections, "REDIRECT URI", [
    "Expected redirect URI (register this EXACT value in each IdP app):",
    "  {$redirectUri}",
    "",
    "Notes:",
    "  - It is the same for analyst and portal sign-in (one callback).",
    "  - It is derived from this request's host + BASE_URL, so make sure you ran this",
    "    from the same hostname your users use. A trailing-slash or http/https",
    "    difference will cause a 'redirect_uri mismatch' at the provider.",
]);

// ---- 13. VERDICT -------------------------------------------------------

$blockers = [];
if (!$schemaOk)                                   $blockers[] = "Schema gaps — run System → Database verification.";
if (!$ssoEnabled)                                 $blockers[] = "SSO is disabled globally (sso_enabled) — no one gets SSO until it's on.";
if ($predMode === 'sso' || $predMode === 'choose') {
    foreach ($predProviders as $pp) {
        $iss = $scalar("SELECT issuer_url FROM auth_providers WHERE id=?", [$pp['id']]);
        $d = $iss ? $discover($iss) : ['ok' => false];
        if (!$d['ok']) $blockers[] = "Provider #{$pp['id']} ({$pp['name']}) discovery is unreachable — sign-in will fail.";
        if ($iss && !empty($d['ok'])) {
            $secOk = (bool)$scalar("SELECT (client_secret IS NOT NULL AND client_secret<>'') FROM auth_providers WHERE id=?", [$pp['id']]);
            if (!$secOk) $blockers[] = "Provider #{$pp['id']} ({$pp['name']}) has no client secret set.";
            // A relative endpoint is a blocker that points AWAY from us, so say so
            // in the verdict rather than leaving it to be spotted in the detail.
            foreach (['authorization_endpoint', 'token_endpoint', 'jwks_uri'] as $key) {
                $val   = trim((string)($d['doc'][$key] ?? ''));
                $bits  = $val !== '' ? parse_url($val) : null;
                $absOk = is_array($bits)
                    && in_array(strtolower($bits['scheme'] ?? ''), ['http', 'https'], true)
                    && !empty($bits['host']);
                if ($val !== '' && !$absOk) {
                    $blockers[] = "Provider #{$pp['id']} ({$pp['name']}) publishes {$key} as \"{$val}\", which is not an "
                                . "absolute URL. Sign-in will fail, and it will look like a fault in FreeITSM because the "
                                . "browser resolves that address against this server. Fix it at the identity provider.";
                }
            }
        }
    }
}
if ($predMode === 'local' && $ssoEnabled && $user && !$user['password_hash'] && !$pinProviderId) {
    $blockers[] = "This user has no password AND isn't routed to any SSO provider — they currently can't sign in. Map their domain to a company with an IdP, pin them to a provider, or have them register a password.";
}

$summary = "For {$email}: predicted login is {$predLine}.";
addSection($sections, "VERDICT", array_merge(
    [$summary, ""],
    ($blockers ? array_merge(["Blockers / things to fix:"], array_map(function ($b) { return "  - " . $b; }, $blockers))
               : ["No blockers detected for this email's predicted path."])
));

emit_and_exit($sections);
