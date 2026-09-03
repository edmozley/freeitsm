<?php
/**
 * Shared Functions
 * Include this file in any script that needs common functionality
 *
 * Usage: require_once '../includes/functions.php'; (from api folder)
 *        require_once 'includes/functions.php'; (from root folder)
 */

// Session hardening helpers. Pulled in here rather than added to each of the ten
// files that establish or change an identity, because every one of them already
// includes this file — one require instead of ten, and a new sign-in path gets
// sessionPromoteToAuthenticated() available without anyone having to remember.
require_once __DIR__ . '/db.php';    // dbConnectionOptions() — NOT in config.php, see GH #129
require_once __DIR__ . '/ssl.php';   // sslApplyCurl() — same reason: 43 callers, config.php is the operator's
require_once __DIR__ . '/session_security.php';

// Keeps an ACTIVE session from being deleted by PHP's garbage collector. The 717
// endpoints that open the session with read_and_close never reach the close that
// would refresh the session file's timestamp, so the collector reads a busy user
// as idle and signs them out mid-task. Included here for the same reason as the
// others: every one of those endpoints already includes this file. See GH #107.
require_once __DIR__ . '/session_keepalive.php';

// Refuses a state-changing request declaring text/plain — the CORS-simple trick that
// smuggles a JSON body past the preflight that would otherwise stop it. Runs on
// include, for the same reason: 369 endpoints read php://input and none of them
// check. See the file for why the rule is deliberately this narrow.
require_once __DIR__ . '/request_guard.php';

// Enforces "you must change your password" on every request, not just as a redirect
// at the end of login — otherwise typing a URL walks straight past it, which would
// make the seeded-credentials fix cosmetic. See the file.
require_once __DIR__ . '/password_gate.php';

// ⚠️ Stop announcing the exact PHP build on every single response.
//
// Withholding the version from setup/ (S9) is worth very little while every other
// response carries `X-Powered-By: PHP/8.4.0`, which is what PHP's expose_php
// directive does by default. The proper fix is `expose_php = Off` in php.ini, and
// that stays the recommendation — but it is not something a self-hosted operator on
// shared hosting can always reach, so the app removes the header where it can.
//
// Harmless when the header was never set, and it must run before any output, which
// is why it lives here: every page and endpoint includes this file, most of them
// before printing anything.
if (!headers_sent()) {
    header_remove('X-Powered-By');
}

/**
 * Connect to MySQL database using PDO
 *
 * @return PDO Database connection
 * @throws Exception If connection fails
 */
function connectToDatabase() {
    $dsn = "mysql:host=" . DB_SERVER . ";dbname=" . DB_NAME . ";charset=utf8mb4";
    // dbConnectionOptions() pins the session to UTC — see includes/db.php for why
    // (GH #126). Every `new PDO` in the product passes it.
    $conn = new PDO($dsn, DB_USERNAME, DB_PASSWORD, dbConnectionOptions());
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    return $conn;
}

/**
 * Get the list of modules an analyst is allowed to access.
 *
 * @param PDO $conn Database connection
 * @param int $analyst_id Analyst ID
 * @return array|null Null means all access; array of module_key strings if restricted
 */
/**
 * Canonical list of user-facing modules for access control (issue #30), key => name.
 * System is deliberately excluded — it's gated by is_admin, not module access.
 * Keys match the waffle launcher / landing page module keys.
 */
function getModuleRegistry(): array {
    $keys = ['watchtower','tickets','assets','knowledge','changes','problems','calendar',
             'morning-checks','reporting','software','forms','contracts','service-status',
             'wiki','lms','process-mapper','tasks','cmdb','network-mapper','workflow','war-room'];
    $out = [];
    foreach ($keys as $k) {
        $out[$k] = function_exists('t') ? t("common.modules.$k.name") : ucfirst($k);
    }
    return $out;
}

/**
 * Site-wide policy for combining module grants from several sources (issue #30):
 *   'most'  (default) — an analyst may use a module if ANY source grants it (union).
 *   'least'           — only if their own access AND every team allow it (intersection).
 */
function getModulePermissionMode(PDO $conn): string {
    static $mode = null;
    if ($mode !== null) return $mode;
    try {
        $s = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'module_permission_mode'");
        $s->execute();
        $mode = ($s->fetchColumn() === 'least') ? 'least' : 'most';
    } catch (Throwable $e) {
        $mode = 'most';
    }
    return $mode;
}

/**
 * Resolve an analyst's EFFECTIVE module access (issue #30) from every source:
 * the analyst's own grant (all-modules flag, or analyst_modules rows) plus each
 * team they belong to (the team's all-modules flag, or team_modules rows), combined
 * per getModulePermissionMode(). Mirrors getAccessibleTenantIds() for companies.
 *
 * Returns: null = ALL modules (unrestricted) · [] = NO modules · [keys] = a specific set.
 * Cached per request. System is deliberately excluded (gated by is_admin instead).
 */
function getAnalystAllowedModules($conn, $analyst_id) {
    static $cache = [];
    $analyst_id = (int) $analyst_id;
    if (array_key_exists($analyst_id, $cache)) return $cache[$analyst_id];

    // Each source is ['all' => bool, 'set' => [module_key, …]].
    $sources = [];

    // 1. The analyst themselves.
    $analystAll = true;
    try {
        $a = $conn->prepare("SELECT can_access_all_modules FROM analysts WHERE id = ?");
        $a->execute([$analyst_id]);
        $analystAll = ((int) $a->fetchColumn() === 1);
    } catch (Throwable $e) { /* pre-upgrade DB: treat as unrestricted */ }
    if ($analystAll) {
        $sources[] = ['all' => true, 'set' => []];
    } else {
        $r = $conn->prepare("SELECT module_key FROM analyst_modules WHERE analyst_id = ?");
        $r->execute([$analyst_id]);
        $sources[] = ['all' => false, 'set' => $r->fetchAll(PDO::FETCH_COLUMN)];
    }

    // 2. Each active team the analyst is in (guarded — tables/columns may predate an
    //    un-verified DB, in which case the analyst simply has no team sources).
    try {
        $t = $conn->prepare(
            "SELECT t.id, t.can_access_all_modules
               FROM analyst_teams at JOIN teams t ON at.team_id = t.id
              WHERE at.analyst_id = ?");
        $t->execute([$analyst_id]);
        foreach ($t->fetchAll(PDO::FETCH_ASSOC) as $team) {
            if ((int) $team['can_access_all_modules'] === 1) {
                $sources[] = ['all' => true, 'set' => []];
            } else {
                $tm = $conn->prepare("SELECT module_key FROM team_modules WHERE team_id = ?");
                $tm->execute([$team['id']]);
                $sources[] = ['all' => false, 'set' => $tm->fetchAll(PDO::FETCH_COLUMN)];
            }
        }
    } catch (Throwable $e) { /* no team dimension on this DB yet */ }

    // 3. Combine.
    if (getModulePermissionMode($conn) === 'least') {
        // Allowed only where EVERY source grants it. All-access sources don't
        // constrain, so intersect the specific sets; if none are specific, every
        // source is all-access → unrestricted.
        $specific = [];
        foreach ($sources as $s) if (!$s['all']) $specific[] = $s['set'];
        if (empty($specific)) {
            $result = null;
        } else {
            $result = $specific[0];
            for ($i = 1, $n = count($specific); $i < $n; $i++) {
                $result = array_intersect($result, $specific[$i]);
            }
            $result = array_values(array_unique($result)); // may be [] = no modules
        }
    } else {
        // 'most' — any all-access source means unrestricted; else union of the sets.
        $result = [];
        $anyAll = false;
        foreach ($sources as $s) {
            if ($s['all']) { $anyAll = true; break; }
            $result = array_merge($result, $s['set']);
        }
        $result = $anyAll ? null : array_values(array_unique($result)); // may be [] = no modules
    }

    return $cache[$analyst_id] = $result;
}

/**
 * Is this analyst an administrator? Admins are the only accounts allowed into the
 * System module (analyst/team/company management, SSO, security, DB verify, etc.).
 * Authoritative DB check — use this for server-side enforcement (no session lag).
 */
function analystIsAdmin(PDO $conn, int $analystId): bool {
    if ($analystId <= 0) return false;

    // Cached for the life of the request, exactly as getAnalystAllowedModules() does.
    // Still authoritative: the flag cannot meaningfully change midway through rendering
    // one page, and the next request re-reads it — so a just-demoted admin is still
    // stopped. Without this, drawing a settings tab bar re-asked this question once per
    // tab (see includes/rbac.php).
    static $cache = [];
    if (array_key_exists($analystId, $cache)) return $cache[$analystId];

    $stmt = $conn->prepare("SELECT is_admin FROM analysts WHERE id = ?");
    $stmt->execute([$analystId]);
    return $cache[$analystId] = ((int) $stmt->fetchColumn() === 1);
}

/**
 * Cosmetic (UI-layer) admin check for pages: "should this analyst see System?".
 * Reads $_SESSION['is_admin']; if absent (e.g. a session created before this
 * upgrade) it self-heals by looking the flag up once and caching it in the
 * session, so no login-path changes are needed and no admin is ever locked out.
 * Requires a writable session (not read_and_close). For hard enforcement on APIs
 * use requireAdminJson() instead, which re-checks the DB every call.
 */
function sessionIsAdmin(): bool {
    if (!isset($_SESSION['analyst_id'])) return false;
    if (!array_key_exists('is_admin', $_SESSION)) {
        try {
            $conn = connectToDatabase();
            $_SESSION['is_admin'] = analystIsAdmin($conn, (int) $_SESSION['analyst_id']) ? 1 : 0;
        } catch (Throwable $e) {
            return false; // fail closed
        }
    }
    return !empty($_SESSION['is_admin']);
}

/**
 * Hard gate for System JSON APIs: refuse non-admins with a 403. Authoritative
 * (DB-checked) so a just-demoted analyst can't keep acting on a stale session.
 * Call right after connecting, e.g. requireAdminJson($conn);
 */
function requireAdminJson(PDO $conn): void {
    $id = (int) ($_SESSION['analyst_id'] ?? 0);
    if (!$id || !analystIsAdmin($conn, $id)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Administrator access required']);
        exit;
    }
}

/**
 * Module access — the enforcement half of issue #30. Mirrors the admin gate above.
 * True if $analystId may use $moduleKey under the current most/least policy.
 *
 * 'system' is NOT one of these modules — it's governed by is_admin — so passing
 * 'system' here defers to analystIsAdmin(), and any unknown key is denied. The
 * heavy lifting (union/intersection across the analyst + their teams) lives in the
 * single choke-point getAnalystAllowedModules(), which this just consults.
 */
function analystCanAccessModule(PDO $conn, int $analystId, string $moduleKey): bool {
    if ($analystId <= 0) return false;
    if ($moduleKey === 'system') return analystIsAdmin($conn, $analystId);
    $allowed = getAnalystAllowedModules($conn, $analystId); // null = all modules
    if ($allowed === null) return true;
    return in_array($moduleKey, $allowed, true);
}

/**
 * Page gate: bounce an analyst who can't use $moduleKey back to the launcher, so
 * a restricted user can't simply type the module's URL and walk in. Authoritative
 * (DB-checked) — a just-restricted analyst is stopped even on a stale session.
 * Call near the top of a module's page after functions.php is loaded and the
 * "are you logged in?" check. Passes $conn if you already have one, else connects.
 */
function requireModuleAccess(string $moduleKey, ?PDO $conn = null): void {
    if (!isset($_SESSION['analyst_id'])) {
        header('Location: ' . BASE_URL . 'auth/login.php');
        exit;
    }
    try {
        if ($conn === null) $conn = connectToDatabase();
        $ok = analystCanAccessModule($conn, (int) $_SESSION['analyst_id'], $moduleKey);
    } catch (Throwable $e) {
        $ok = false; // fail closed
    }
    if (!$ok) {
        header('Location: ' . BASE_URL . '?denied=' . urlencode($moduleKey));
        exit;
    }
}

/**
 * Hard gate for an endpoint shared by SEVERAL modules: allow if the analyst can use ANY
 * of them, refuse otherwise.
 *
 * Some features are genuinely reachable from more than one place. Asking the knowledge
 * base a question is offered both in Knowledge and from the ticket reading pane, so
 * requiring 'knowledge' alone would break the inbox for an analyst who only works
 * tickets — while requiring nothing at all (which is what these endpoints did) lets
 * anyone with a login spend your AI budget.
 *
 * "Any of these modules" is the honest gate. Use it sparingly: if an endpoint belongs to
 * ONE module, use requireModuleAccessJson().
 *
 * @param array<int,string> $moduleKeys
 */
function requireAnyModuleAccessJson(array $moduleKeys, ?PDO $conn = null): void {
    if (!isset($_SESSION['analyst_id'])) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Not authenticated']);
        exit;
    }
    $ok = false;
    try {
        if ($conn === null) $conn = connectToDatabase();
        $analystId = (int) $_SESSION['analyst_id'];
        foreach ($moduleKeys as $key) {
            if (analystCanAccessModule($conn, $analystId, $key)) { $ok = true; break; }
        }
    } catch (Throwable $e) {
        $ok = false; // fail closed
    }
    if (!$ok) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'You do not have access to this feature']);
        exit;
    }
}

/**
 * Hard gate for a module's JSON write APIs: refuse a denied analyst with a 403.
 * Authoritative (DB-checked). The API twin of requireModuleAccess(). Call right
 * after connecting, e.g. requireModuleAccessJson('assets', $conn);
 * Shared READ endpoints that other modules or the login page depend on must NOT
 * call this — guarding those would break normal cross-module flows.
 */
function requireModuleAccessJson(string $moduleKey, ?PDO $conn = null): void {
    if (!isset($_SESSION['analyst_id'])) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Not authenticated']);
        exit;
    }
    try {
        if ($conn === null) $conn = connectToDatabase();
        $ok = analystCanAccessModule($conn, (int) $_SESSION['analyst_id'], $moduleKey);
    } catch (Throwable $e) {
        $ok = false; // fail closed
    }
    if (!$ok) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'You do not have access to this module']);
        exit;
    }
}

/**
 * Fire a settings/CRUD workflow event ({entity}.{created|updated|deleted}) from a
 * UI settings endpoint, without each file having to require the workflow engine.
 * Lazily loads the engine and is fully self-safe — swallows any Throwable
 * (including a missing engine on a minimal install) so it can NEVER affect the
 * save it follows. Funnels into the exact same WorkflowEngine::dispatch() path as
 * every other event, so a webhook fires identically however it was emitted.
 */
function wf_emit(string $entity, string $action, int $id, ?string $name = null): void
{
    try {
        require_once __DIR__ . '/../workflow/includes/engine.php';
        WorkflowEngine::emitCrud($entity, $action, $id, $name);
    } catch (Throwable $e) {
        error_log('wf_emit(' . $entity . '.' . $action . ') error: ' . $e->getMessage());
    }
}

/**
 * Turn a refused OAuth token response into something an administrator can act on.
 *
 * 🔴 WHY THIS EXISTS. Mail collection stopped on a live install and the only
 * thing FreeITSM said was "Failed to refresh token. HTTP Code: 401". The
 * provider had, in the very response body being thrown away, said exactly what
 * was wrong: "AADSTS7000222: The provided client secret keys for app '…' are
 * expired." One is a mystery costing an afternoon; the other is a five-minute
 * job in the Azure portal.
 *
 * An expired client secret is not an unusual event — Azure secrets last at most
 * 24 months and are routinely created with a 6- or 12-month life — so this is a
 * thing every install will eventually hit, and it must not present as "something
 * went wrong".
 *
 * ⚠️ The provider's text is passed through as-is rather than matched against a
 * list of known codes. A message we don't recognise is exactly the case where
 * the provider's own words are worth most, and a lookup table would replace it
 * with "Unknown error".
 *
 * @param string|false $response the raw body from the token endpoint
 * @param int          $httpCode the status it came back with
 */
function oauthTokenErrorMessage($response, int $httpCode): string
{
    $body = is_string($response) ? json_decode($response, true) : null;

    if (is_array($body) && !empty($body['error_description'])) {
        // Microsoft appends a Trace ID, Correlation ID and Timestamp to every
        // description. Useful when raising a support case with them, noise when
        // reading it on a settings page — so the sentence is kept and the
        // identifiers dropped.
        $desc = preg_replace('/\s*(Trace ID|Correlation ID|Timestamp):.*$/s', '', trim($body['error_description']));
        return 'The mail provider refused the login (HTTP ' . $httpCode . '): ' . $desc;
    }
    if (is_array($body) && !empty($body['error'])) {
        return 'The mail provider refused the login (HTTP ' . $httpCode . '): ' . $body['error'];
    }
    // No usable body at all — say so plainly rather than implying we know more.
    return 'The mail provider refused the login (HTTP ' . $httpCode . ') and gave no reason. '
         . 'Check the client secret and the app registration have not expired.';
}
?>
