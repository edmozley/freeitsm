<?php
/**
 * Debug Tool D005 — Endpoint permission coverage
 *
 * Scans EVERY endpoint under api/ and reports what actually guards it:
 *   - a capability (RBAC Layer 2)      — requireCapabilityJson(Cap::X)
 *   - administrator only               — includes/admin_api_guard.php
 *   - module access (RBAC Layer 1)     — requireModuleAccessJson('<module>')
 *   - an API key                       — the REST API v1 front controller
 *   - logged in, and nothing more      — only a $_SESSION['analyst_id'] check
 *   - NOTHING AT ALL                   — no authentication of any kind
 *
 * WHY THIS EXISTS
 * ---------------
 * A type system catches a MISSPELLED permission. Nothing catches a permission
 * somebody simply FORGOT TO WRITE. That omission is how api/settings/
 * save_system_settings.php ended up letting any logged-in analyst rewrite the
 * vCenter credentials and the brute-force lockout policy (#829), how six Intune
 * endpoints let any analyst trigger a full tenant sync (#830), and how
 * api/rfp-builder/save_ai_settings.php still lets anyone overwrite an AI API key.
 *
 * Every one of those was found BY HAND, BY ACCIDENT, while looking for something
 * else. This tool is the systematic version: it reads all ~630 endpoints in a few
 * seconds and ranks what it finds by how much damage the gap allows.
 *
 * It is a STATIC read of the source. It cannot prove an endpoint is safe — an
 * endpoint may do its own bespoke checking that this tool can't see, which is why
 * findings are ranked as "worth a look", not "definitely broken". But an endpoint
 * that writes to the database with no guard at all is nearly always a real bug.
 *
 * READ-ONLY. Writes nothing, changes nothing, prints no secrets.
 *
 * Output: plain text, section-delimited with === HEADERS === for easy skimming.
 */

@session_start();

$DIAG_ID   = 'D005';
$DIAG_NAME = 'Endpoint permission coverage';

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/functions.php';
require_once __DIR__ . '/../../../includes/capabilities.php';

$sections = [];
function addSection(&$sections, $title, $body) {
    if (is_array($body)) $body = implode("\n", $body);
    $sections[] = "=== {$title} ===\n" . rtrim($body, "\n");
}

$appRoot = realpath(__DIR__ . '/../../..');
$apiRoot = $appRoot . DIRECTORY_SEPARATOR . 'api';

// ---------------------------------------------------------------------------
// Classify one endpoint
// ---------------------------------------------------------------------------

/**
 * Endpoints that are PUBLIC BY DESIGN — they must work before anyone is logged in,
 * or they belong to the self-service portal, which authenticates its own way. Being
 * unguarded is the point, so they are excluded from the findings rather than shouted
 * about. Listed explicitly so the exclusion is a decision, not an accident.
 */
function d005IsPublicByDesign(string $rel): bool {
    $publicPrefixes = [
        'api/auth/',           // login, SSO callbacks, password reset
        'api/self-service/',   // the requester portal — its own session/user auth
        'api/external/',       // inbound webhooks / integrations, token-authed
        'api/v1/',             // the REST API — API-key authed by its front controller
    ];
    foreach ($publicPrefixes as $p) {
        if (strncmp($rel, $p, strlen($p)) === 0) return true;
    }
    return false;
}

/**
 * Endpoints that are CORRECTLY reachable by any logged-in analyst, with the reason.
 *
 * These act on YOU — your own password, your own MFA, your own preferences. There is no
 * module to check, because the resource is the caller. Listing them here (rather than
 * letting them sit in the findings forever) is what keeps the report actionable: a report
 * with twenty permanent false alarms in it is a report nobody reads.
 *
 * Add to this ONLY when the endpoint genuinely acts on the caller's own account. If you
 * are adding something because "it's probably fine", it isn't — guard it instead.
 */
function d005ByDesign(string $rel): ?string {
    $ok = [
        'api/myaccount/change_password.php'     => 'Changes the caller\'s OWN password',
        'api/myaccount/disable_mfa.php'         => 'Disables the caller\'s OWN MFA (re-verifies their password)',
        'api/myaccount/verify_mfa.php'          => 'Verifies the caller\'s OWN MFA enrolment',
        'api/myaccount/verify_login_otp.php'    => 'Verifies the caller\'s OWN login OTP',
        'api/myaccount/toggle_trust_device.php' => 'Trusts the caller\'s OWN device',
        'api/system/set_user_preference.php'    => 'Sets the caller\'s OWN preference (theme, locale, timezone)',
        'api/system/set_active_tenant.php'      => 'Switches the caller\'s OWN active company (already scoped to companies they can access)',
    ];
    return $ok[$rel] ?? null;
}

/** Does this endpoint change anything? Writes are what make a missing guard dangerous. */
function d005IsWriteShaped(string $src): bool {
    // SQL that mutates…
    if (preg_match('/\b(INSERT\s+INTO|UPDATE\s+[`\w]+\s+SET|DELETE\s+FROM|REPLACE\s+INTO|TRUNCATE|ALTER\s+TABLE|DROP\s+TABLE)\b/i', $src)) return true;
    // …or it accepts a request body / POST at all, or writes files.
    if (preg_match('/php:\/\/input|\$_POST|\$_FILES|move_uploaded_file|file_put_contents|unlink\s*\(/i', $src)) return true;
    return false;
}

/**
 * What guards it? Returns [level, detail]. Strongest match wins.
 *
 * The order and the breadth here matter more than they look. A scanner that shouts
 * about endpoints which are in fact perfectly well guarded — by a mechanism it simply
 * didn't recognise — gets ignored within a week, and then it protects nothing. So every
 * legitimate authentication style in the codebase is taught to it explicitly, and the
 * cases below are the ones that DID produce false alarms on the first run:
 *   - MFA endpoints authenticate through getMfaAuthContext(), not a bare $_SESSION read;
 *   - the browser extension's endpoint takes an Authorization API key;
 *   - the inbound messaging webhook is authenticated by the provider's own signature.
 */
function d005Classify(string $src, string $rel): array {
    if (strncmp($rel, 'api/v1/', 7) === 0) {
        return ['apikey', 'REST API v1 — API key + per-route permission (front controller)'];
    }
    if (preg_match('/requireCapabilityJson\s*\(\s*Cap::([A-Z0-9_]+)/', $src, $m)) {
        return ['capability', 'Cap::' . $m[1]];
    }
    // A bare-string capability guard still works, but it's the dangerous form — call it out.
    if (preg_match('/requireCapabilityJson\s*\(\s*[\'"]([a-z0-9_.]+)[\'"]/', $src, $m)) {
        return ['capability_string', "'" . $m[1] . "'  ⚠ passed as a STRING, not a Cap:: constant"];
    }
    if (strpos($src, 'admin_api_guard.php') !== false || preg_match('/requireAdminJson\s*\(/', $src)) {
        return ['admin', 'Administrators only'];
    }
    if (preg_match('/requireModuleAccessJson\s*\(\s*[\'"]([a-z0-9-]+)[\'"]/', $src, $m)) {
        return ['module', "Module access: '" . $m[1] . "'"];
    }
    // Per-KEY authorisation: the generic settings writer can't use a single guard (one
    // file, five callers, five audiences), so it authorises each posted key against its
    // owning module/capability. See includes/settings_keys.php.
    if (preg_match('/analystCanWriteSettingKey|settingKeyOwner/', $src)) {
        return ['perkey', 'Per setting key (settings_keys.php)'];
    }
    // Module access asserted through the underlying resolver rather than the JSON guard
    // — used where a JSON 403 would corrupt the response (e.g. SSE streams).
    // Note the lazy .*? rather than [^)]* — the call typically contains connectToDatabase(),
    // whose own ')' would otherwise stop the match dead and report the endpoint as unguarded.
    if (preg_match('/analystCanAccessModule\s*\(.*?[\'"]([a-z0-9-]+)[\'"]\s*\)/', $src, $m)) {
        return ['module', "Module access: '" . $m[1] . "' (checked inline — SSE/stream)"];
    }
    // An API key presented in the Authorization header and checked against api_keys.
    if (preg_match('/getallheaders|HTTP_AUTHORIZATION/i', $src) && preg_match('/api_keys|\$authKey/i', $src)) {
        return ['apikey', 'API key (Authorization header)'];
    }
    // A provider webhook proving itself with a signature or a shared relay secret.
    if (preg_match('/hash_equals|hash_hmac|X-Twilio-Signature|X-Hub-Signature|relay_secret/i', $src)) {
        return ['signature', 'Signature / shared secret (inbound provider webhook)'];
    }
    // A per-analyst capability token in the URL. Used where the client CANNOT carry a
    // session cookie — the iCal feed a phone's calendar app subscribes to. The token is
    // the credential, and rotating it revokes the URL.
    if (preg_match('/\$_GET\s*\[\s*[\'"]token[\'"]\s*\]/', $src) && preg_match('/_token|capability token/i', $src)) {
        return ['token', 'Per-analyst capability token in the URL (no session possible)'];
    }
    // A logged-in human — an analyst, a self-service user, or via an auth-context helper.
    if (preg_match('/\$_SESSION\s*\[\s*[\'"](analyst_id|ss_user_id|user_id)[\'"]\s*\]|getMfaAuthContext|requireLogin/', $src)) {
        return ['session', 'Logged in, and nothing more'];
    }
    return ['none', 'NO AUTHENTICATION OF ANY KIND'];
}

// ---------------------------------------------------------------------------
// Walk the API tree
// ---------------------------------------------------------------------------

$rows = [];
if (is_dir($apiRoot)) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($apiRoot, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') continue;

        $abs = $file->getPathname();
        $rel = str_replace('\\', '/', substr($abs, strlen($appRoot) + 1));

        // The debug tools themselves are reached through the admin-gated debug runner.
        if (strpos($rel, 'api/system/debug-tools/') === 0) continue;

        // By convention a leading underscore means "shared include, not an endpoint"
        // (_ai_helpers.php, _field_catalogue.php …). They define functions and do nothing
        // when requested directly, but they CONTAIN the SQL of the endpoints that use
        // them — so scanning them as endpoints reports writes that can't actually be
        // triggered. Skip them; the callers are scanned on their own merits.
        if (strncmp($file->getFilename(), '_', 1) === 0) continue;

        // Front-controller internals of the REST API (its bootstrap/lib/resources are
        // not independently routable — index.php authenticates every route).
        if (preg_match('#^api/v1/(lib|resources|dev)/#', $rel)) continue;

        $src = (string) file_get_contents($abs);
        [$level, $detail] = d005Classify($src, $rel);

        $rows[] = [
            'rel'    => $rel,
            'module' => explode('/', $rel)[1] ?? '?',
            'level'  => $level,
            'detail' => $detail,
            'write'  => d005IsWriteShaped($src),
            'public' => d005IsPublicByDesign($rel),
        ];
    }
}
usort($rows, fn($a, $b) => strcmp($a['rel'], $b['rel']));

// ---------------------------------------------------------------------------
// Findings, ranked by how much damage the gap allows
// ---------------------------------------------------------------------------

$critical = [];   // writes with NO auth at all
$high     = [];   // writes behind "logged in" only
$info     = [];   // reads behind "logged in" only
$stringly = [];   // capability passed as a string rather than a Cap:: constant

$byDesign = [];
foreach ($rows as $r) {
    if ($r['public']) continue;
    if ($reason = d005ByDesign($r['rel'])) { $byDesign[] = $r + ['reason' => $reason]; continue; }
    if ($r['level'] === 'capability_string') $stringly[] = $r;
    if ($r['level'] === 'none' && $r['write'])    { $critical[] = $r; continue; }
    if ($r['level'] === 'none' && !$r['write'])   { $high[]     = $r; continue; }   // a read with no auth at all is still bad
    if ($r['level'] === 'session' && $r['write']) { $high[]     = $r; continue; }
    if ($r['level'] === 'session' && !$r['write']) $info[] = $r;
}

// ---------------------------------------------------------------------------
// Report
// ---------------------------------------------------------------------------

$counts = [];
foreach ($rows as $r) $counts[$r['level']] = ($counts[$r['level']] ?? 0) + 1;
$label = [
    'capability'        => 'Guarded by a capability (Layer 2)',
    'capability_string' => 'Capability, but passed as a STRING (fix: use Cap::)',
    'admin'             => 'Administrators only',
    'module'            => 'Module access (Layer 1)',
    'apikey'            => 'REST API v1 (API key)',
    'perkey'            => 'Per setting key (shared settings endpoint)',
    'signature'         => 'Signature / shared secret (provider webhook)',
    'token'             => 'Capability token in the URL (iCal feed)',
    'session'           => 'Logged in, and nothing more',
    'none'              => 'No authentication of any kind',
];

$sum = [];
$sum[] = sprintf('Endpoints scanned : %d', count($rows));
$sum[] = '';
foreach (['capability','capability_string','admin','module','apikey','perkey','signature','token','session','none'] as $lv) {
    if (!isset($counts[$lv])) continue;
    $sum[] = sprintf('  %-46s %4d', $label[$lv], $counts[$lv]);
}
$sum[] = '';
$sum[] = sprintf('Public by design (login / self-service / REST / webhooks), excluded from findings: %d',
    count(array_filter($rows, fn($r) => $r['public'])));
addSection($sections, 'SUMMARY', $sum);

$verdict = [];
if ($critical) {
    $verdict[] = 'CRITICAL — these endpoints CHANGE DATA and have NO authentication at all.';
    $verdict[] = 'Anyone who can reach the URL can call them. Fix these first.';
} elseif ($high) {
    $verdict[] = 'No endpoint is completely unauthenticated. But see HIGH below:';
    $verdict[] = 'endpoints that change data behind nothing but "are you logged in?".';
} else {
    $verdict[] = 'Nothing found. Every data-changing endpoint carries a real permission check.';
}
addSection($sections, 'VERDICT', $verdict);

if ($critical) {
    $b = ['These WRITE to the system and have NO auth check whatsoever.', ''];
    foreach ($critical as $r) $b[] = sprintf('  [%-18s] %s', $r['module'], $r['rel']);
    addSection($sections, 'CRITICAL — writes with no authentication (' . count($critical) . ')', $b);
}

if ($high) {
    $b = [
        'Reachable by ANY logged-in analyst — including one whose module access is a',
        'single unrelated module. If the endpoint configures something, that is a hole:',
        'it is exactly the shape of #829 (any analyst could rewrite the vCenter',
        'credentials and switch off brute-force lockout).',
        '',
        'Not every one of these is a bug — some genuinely only need "is a human logged in"',
        '(notifications, shared lookups, the account menu). Judge each by what it CHANGES.',
        '',
    ];
    $byModule = [];
    foreach ($high as $r) $byModule[$r['module']][] = $r;
    ksort($byModule);
    foreach ($byModule as $mod => $list) {
        $b[] = sprintf('  %s  (%d)', $mod, count($list));
        foreach ($list as $r) {
            $flag = $r['level'] === 'none' ? '  ← NO AUTH AT ALL' : '';
            $b[] = sprintf('      %s%s', substr($r['rel'], strlen('api/')), $flag);
        }
        $b[] = '';
    }
    addSection($sections, 'HIGH — data-changing endpoints behind "logged in" only (' . count($high) . ')', $b);
}

if ($stringly) {
    $b = [
        'These pass the capability as a bare string. It works — but a typo in a string',
        'fails CLOSED and SILENTLY (a permanent 403 that looks like a policy decision),',
        'and the is_admin bypass means it is invisible to administrators. Use a Cap::',
        'constant, where a typo is an immediate fatal error at the call site.',
        '',
    ];
    foreach ($stringly as $r) $b[] = sprintf('  %-50s %s', substr($r['rel'], strlen('api/')), $r['detail']);
    addSection($sections, 'FIX — capability passed as a string (' . count($stringly) . ')', $b);
}

// A capability nobody enforces is a lie in the Roles UI: an administrator ticks it,
// and it grants precisely nothing.
$enforced = [];
foreach ($rows as $r) {
    if ($r['level'] === 'capability' || $r['level'] === 'capability_string') {
        if (preg_match('/Cap::([A-Z0-9_]+)/', $r['detail'], $m)) {
            $const = 'Cap::' . $m[1];
            if (defined($const)) $enforced[constant($const)] = true;
        } elseif (preg_match("/'([a-z0-9_.]+)'/", $r['detail'], $m)) {
            $enforced[$m[1]] = true;
        }
    }
}
// Umbrellas are satisfied by expansion rather than enforced directly — that's by design.
$unenforced = [];
foreach (capAll() as $cap) {
    if (capIsUmbrella($cap)) continue;
    if (!isset($enforced[$cap])) $unenforced[] = $cap;
}
if ($unenforced) {
    $b = [
        'Declared in includes/capabilities.php and offered on System → Roles, but no API',
        'endpoint requires them. Either the capability only gates a PAGE (fine — say so),',
        'or its endpoints were missed (not fine: the tick-box grants nothing).',
        '',
    ];
    foreach ($unenforced as $c) $b[] = '  ' . $c;
    addSection($sections, 'CHECK — capabilities no endpoint enforces (' . count($unenforced) . ')', $b);
}

if ($byDesign) {
    $b = ['Reachable by any logged-in analyst ON PURPOSE — they act on the CALLER\'S OWN', 'account, so there is no module to check. Declared in d005ByDesign().', ''];
    foreach ($byDesign as $r) $b[] = sprintf('  %-44s %s', substr($r['rel'], strlen('api/')), $r['reason']);
    addSection($sections, 'BY DESIGN — excluded from the findings above (' . count($byDesign) . ')', $b);
}

// Registry/manifest health.
$self = capSelfCheck();
addSection($sections, 'REGISTRY SELF-CHECK',
    $self ? array_map(fn($p) => '  ✗ ' . $p, $self)
          : ['  OK — the Cap:: constants, the registry and the module list all agree.']);

// Full inventory, so the report is auditable rather than a black box.
$inv = [];
$byModule = [];
foreach ($rows as $r) $byModule[$r['module']][] = $r;
ksort($byModule);
foreach ($byModule as $mod => $list) {
    $inv[] = sprintf('%s  (%d endpoints)', $mod, count($list));
    foreach ($list as $r) {
        $inv[] = sprintf('    %-42s %-8s %s',
            substr($r['rel'], strlen('api/' . $mod . '/')),
            $r['write'] ? 'WRITE' : 'read',
            $r['detail']);
    }
    $inv[] = '';
}
addSection($sections, 'FULL INVENTORY', $inv);

echo implode("\n\n", $sections) . "\n";
