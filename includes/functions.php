<?php
/**
 * Shared Functions
 * Include this file in any script that needs common functionality
 *
 * Usage: require_once '../includes/functions.php'; (from api folder)
 *        require_once 'includes/functions.php'; (from root folder)
 */

// PHP 8.1 or newer, or a plain message instead of a parse error - for anything
// that loads this before config.php. includes/php_version_guard.php.
require_once __DIR__ . '/php_version_guard.php';

// Where a mailbox's mail comes from - used across Tickets, Workflow and the portal.
// TRAP: loaded with require, never written into this file: this file must stay
// readable by old PHP so the guard above can speak, and an enum is PHP 8.1.
require_once __DIR__ . '/mailbox_provider.php';

// Which modules are switched off for the whole install (GH #217) - asked by
// analystCanAccessModule() below, so every page and endpoint gate honours it.
require_once __DIR__ . '/module_availability.php';

// Session hardening helpers. Pulled in here rather than added to each of the ten
// files that establish or change an identity, because every one of them already
// includes this file — one require instead of ten, and a new sign-in path gets
// sessionPromoteToAuthenticated() available without anyone having to remember.
require_once __DIR__ . '/base_url.php';   // BASE_URL when config.php does not define it - first, before anything uses it
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

// Signs out a deactivated or deleted analyst on their next request, rather than
// leaving every endpoint that only checks "signed in" open to them. See the file.
require_once __DIR__ . '/analyst_active_gate.php';

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
             'morning-checks','reporting','software','forms','contracts','domains','people','starters','service-status','files',
             'war-room','wiki','lms','process-mapper','checklists','tasks','projects','cmdb','network-mapper','workflow'];
    $out = [];
    foreach ($keys as $k) {
        $out[$k] = function_exists('t') ? t("common.modules.$k.name") : ucfirst($k);
    }
    return $out;
}

/**
 * The modules that offer a Read only level (#180 Part 3). A module joins only once EVERY
 * one of its write endpoints calls requireModuleWriteJson() and its service has the
 * backstop - otherwise Read only would be a promise with a hole in it.
 * tests/module-access-coverage.php holds the line.
 */
const MODULES_WITH_READ_ONLY = ['changes', 'forms', 'tasks'];   // tasks: #181 Part G (the Auditor role)

/**
 * What may this analyst do in this module: 'full', 'read', or null (no access)? The
 * same sources and the same most/least rule as getAnalystAllowedModules(): 'most' takes
 * the highest level any source gives, 'least' the lowest every source gives. An
 * all-modules flag means full on everything, as it always has; a module without a Read
 * only level is always full where it is granted.
 *
 * analystCanAccessModule() is unchanged - read IS access - so every page and read
 * endpoint lets a reviewer in without being touched. Only writes ask this.
 *
 * @param int $analystId  the analyst
 * @param string $moduleKey  a module key from getModuleRegistry()
 */
function analystModuleLevel(PDO $conn, int $analystId, string $moduleKey): ?string {
    static $cache = [];
    $k = $analystId . '|' . $moduleKey;
    if (array_key_exists($k, $cache)) return $cache[$k];
    if (!analystCanAccessModule($conn, $analystId, $moduleKey)) return $cache[$k] = null;
    if (!in_array($moduleKey, MODULES_WITH_READ_ONLY, true)) return $cache[$k] = 'full';

    $rank = ['read' => 1, 'full' => 2];
    $levels = [];   // one per source that grants the module; null where a source does not
    try {
        $a = $conn->prepare("SELECT can_access_all_modules FROM analysts WHERE id = ?");
        $a->execute([$analystId]);
        if ((int)$a->fetchColumn() === 1) {
            $levels[] = 'full';
        } else {
            $r = $conn->prepare("SELECT access_level FROM analyst_modules WHERE analyst_id = ? AND module_key = ?");
            $r->execute([$analystId, $moduleKey]);
            $lv = $r->fetchColumn();
            $levels[] = $lv === false ? null : ($lv === 'read' ? 'read' : 'full');
        }
        $t = $conn->prepare(
            "SELECT t.can_access_all_modules, tm.access_level
               FROM analyst_teams at JOIN teams t ON at.team_id = t.id
               LEFT JOIN team_modules tm ON tm.team_id = t.id AND tm.module_key = ?
              WHERE at.analyst_id = ?"
        );
        $t->execute([$moduleKey, $analystId]);
        foreach ($t->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $levels[] = (int)$row['can_access_all_modules'] === 1 ? 'full'
                : ($row['access_level'] === null ? null : ($row['access_level'] === 'read' ? 'read' : 'full'));
        }
    } catch (Throwable $e) {
        return $cache[$k] = 'full';   // pre-upgrade DB (no access_level yet): today's behaviour
    }

    $granted = array_values(array_filter($levels, fn($l) => $l !== null));
    if (!$granted) return $cache[$k] = 'full';   // access came from somewhere this does not model: unchanged
    if (getModulePermissionMode($conn) === 'least') {
        $lowest = 'full';
        foreach ($granted as $l) if ($rank[$l] < $rank[$lowest]) $lowest = $l;
        return $cache[$k] = $lowest;
    }
    $highest = 'read';
    foreach ($granted as $l) if ($rank[$l] > $rank[$highest]) $highest = $l;
    return $cache[$k] = $highest;
}

/**
 * The level of each specific grant one analyst or team holds: module_key => 'full'|'read'.
 * Every screen that rewrites grants (delete, then insert the new list) reads this first
 * and writes the levels back, so saving a module list never quietly turns a Read only
 * grant into Full.
 *
 * @param string $kind  'analyst' or 'team'
 * @param int $id  the analyst or team id
 */
function moduleGrantLevels(PDO $conn, string $kind, int $id): array {
    $table = $kind === 'team' ? 'team_modules' : 'analyst_modules';
    $col   = $kind === 'team' ? 'team_id' : 'analyst_id';
    try {
        $s = $conn->prepare("SELECT module_key, access_level FROM {$table} WHERE {$col} = ?");
        $s->execute([$id]);
        return $s->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
    } catch (Throwable $e) {
        return [];   // before Database Verify added access_level: every grant is full
    }
}

/**
 * May this analyst change things in this module, not only read them? (#180 Part 3)
 *
 * @param int $analystId  the analyst
 * @param string $moduleKey  a module key
 */
function analystCanWriteModule(PDO $conn, int $analystId, string $moduleKey): bool {
    return analystModuleLevel($conn, $analystId, $moduleKey) === 'full';
}

/**
 * JSON gate for a module WRITE: 403 for an analyst with Read only access. Beside
 * requireModuleAccessJson(), never instead of it.
 *
 * TRAP: voting on a change and deciding as its approver are NOT writes - they are decided
 * by identity (a CAB row, the approver_id), so a read-only approver can still approve.
 * Those endpoints must not call this.
 *
 * @param string $moduleKey  a module key
 */
function requireModuleWriteJson(string $moduleKey, ?PDO $conn = null): void {
    $ok = false;
    try {
        if ($conn === null) $conn = connectToDatabase();
        $ok = isset($_SESSION['analyst_id']) && analystCanWriteModule($conn, (int)$_SESSION['analyst_id'], $moduleKey);
    } catch (Throwable $e) {
        $ok = false;
    }
    if (!$ok) {
        http_response_code(403);
        header('Content-Type: application/json');
        // Switched off for the install (GH #217) - not read-only, so not that message.
        if (isset($conn) && !moduleIsEnabled($conn, $moduleKey)) {
            echo json_encode(['success' => false, 'error' => moduleDisabledMessage($moduleKey), 'module_disabled' => $moduleKey]);
            exit;
        }
        echo json_encode(['success' => false, 'error' => function_exists('t') ? t('common.module_read_only') : 'You have read-only access to this module.', 'code' => 'read_only']);
        exit;
    }
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
 *
 * @param mixed $analyst_id  the analyst whose access to resolve (cast to int); deactivated or unknown gives []
 */
function getAnalystAllowedModules($conn, $analyst_id) {
    static $cache = [];
    $analyst_id = (int) $analyst_id;
    if (array_key_exists($analyst_id, $cache)) return $cache[$analyst_id];

    // Each source is ['all' => bool, 'set' => [module_key, …]].
    $sources = [];

    // 1. The analyst themselves.
    // 🔴 A DEACTIVATED analyst has no modules at all (3.5.0). Deactivating stops new
    // sign-ins, but a session already open used to carry on working until it
    // expired - so taking someone's access away at the source (System -> Analysts,
    // or PATCH /api/v1/analysts/{id}) did nothing to the person still at their desk.
    // Every module page and endpoint asks this question, so this is where it ends.
    $analystAll = true;
    try {
        $a = $conn->prepare("SELECT can_access_all_modules, COALESCE(is_active, 1) AS is_active FROM analysts WHERE id = ?");
        $a->execute([$analyst_id]);
        $me = $a->fetch(PDO::FETCH_ASSOC);
        if (!$me || (int) $me['is_active'] !== 1) {
            return $cache[$analyst_id] = [];
        }
        $analystAll = ((int) $me['can_access_all_modules'] === 1);
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
 * A LIKE pattern for "contains this text", for a search box.
 *
 * TRAP: an apostrophe or quote typed in a search must match ANY kind of one.
 * Email subjects are full of typographic quotes - Gmail and Outlook send
 * "We’re" with U+2019 - while a keyboard types the straight ', so a plain
 * '%' . $term . '%' never finds them (the ticket table, 2026-10-27). Each quote
 * becomes `_` (any one character). The user's own % and _ are escaped first, so
 * they mean themselves rather than wildcards.
 *
 * @param string $term  what was typed, trimmed
 */
function likeContainsPattern(string $term): string {
    $escaped = addcslashes($term, '\\%_');
    $quotes  = ["'", "\u{2019}", "\u{2018}", "\u{02BC}", '`', "\u{00B4}", '"', "\u{201C}", "\u{201D}"];
    return '%' . str_replace($quotes, '_', $escaped) . '%';
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

    // A deactivated admin is not an admin - see getAnalystAllowedModules() (3.5.0).
    $stmt = $conn->prepare("SELECT is_admin FROM analysts WHERE id = ? AND COALESCE(is_active, 1) = 1");
    $stmt->execute([$analystId]);
    return $cache[$analystId] = ((int) $stmt->fetchColumn() === 1);
}

/**
 * Is this requester still active? "Mark as left" (Assets -> Users, Tickets ->
 * Users) and PATCH /api/v1/users/{id} clear users.is_active. A requester who has
 * left cannot sign in to the portal, by any route, and is signed out of an open
 * portal session on their next page (self-service/includes/auth.php). 3.5.0.
 * NULL counts as active, as everywhere else.
 *
 * @param int $userId  the requester's users.id; 0 or less, or an unknown id, gives false
 */
function requesterIsActive(PDO $conn, int $userId): bool {
    if ($userId <= 0) return false;
    $stmt = $conn->prepare("SELECT COALESCE(is_active, 1) FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    return (int) $stmt->fetchColumn() === 1;
}

/**
 * The modules the signed-in analyst may use NOW - from the database (cached for
 * the request by getAnalystAllowedModules()), never the copy taken at sign-in.
 * Null = every module; [] = none, which is also the answer when nobody is signed
 * in or the lookup fails (fail closed).
 *
 * 🔴 $_SESSION['allowed_modules'] is written once, at sign-in. The document
 * endpoints and global search gated on it, so an analyst whose access to, say,
 * Contracts was removed kept downloading, attaching and finding its documents,
 * and seeing its records in search, until they signed out. The module pages
 * themselves re-read the grants. Reported privately, October 2026.
 * TRAP: never gate anything on $_SESSION['allowed_modules']; call this.
 */
function analystAllowedModulesNow(): ?array {
    $id = (int) ($_SESSION['analyst_id'] ?? 0);
    if ($id <= 0) return [];
    try {
        $conn = connectToDatabase();
        return modulesWithoutSwitchedOff($conn, getAnalystAllowedModules($conn, $id));
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * An allowed-modules answer (null = all, or a list) with the modules switched off
 * for the install taken out (GH #217). getAnalystAllowedModules() itself is left
 * as the GRANTS - System -> Modules shows those, and an all-modules analyst is
 * still "all modules" there - so anything deciding what an analyst may OPEN from
 * that list goes through here.
 *
 * @param array|null $allowed  getAnalystAllowedModules()'s answer
 * @return array|null  null only when nothing is switched off
 */
function modulesWithoutSwitchedOff(PDO $conn, ?array $allowed): ?array {
    $off = disabledModules($conn);
    if (!$off) return $allowed;
    $base = $allowed ?? array_keys(getModuleRegistry());
    return array_values(array_diff($base, $off));
}

/**
 * Is the signed-in analyst an administrator? Asks the database every request
 * (analystIsAdmin() caches within one), so demoting or deactivating somebody takes
 * effect on their very next click.
 *
 * 🔴 It used to read a copy of the flag cached in the session at sign-in, and was
 * documented as "cosmetic, for pages" - but endpoints had come to rely on it as a
 * gate: the Slack channel settings, test_channel.php, approving catalogue requests
 * as an administrator, editing manager lines. A demoted admin whose session was
 * still open kept all of those. Reported privately, October 2026.
 *
 * $_SESSION['is_admin'] is still written when the session is writable, for older
 * code that reads it directly - but nothing should decide anything from it.
 */
function sessionIsAdmin(): bool {
    if (!isset($_SESSION['analyst_id'])) return false;
    try {
        $isAdmin = analystIsAdmin(connectToDatabase(), (int) $_SESSION['analyst_id']);
    } catch (Throwable $e) {
        return false; // fail closed
    }
    if (session_status() === PHP_SESSION_ACTIVE) $_SESSION['is_admin'] = $isAdmin ? 1 : 0;
    return $isAdmin;
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
 *
 * @param string $moduleKey  the module key, e.g. 'tickets', 'assets', 'files'; 'system' means administrators only
 */
function analystCanAccessModule(PDO $conn, int $analystId, string $moduleKey): bool {
    if ($analystId <= 0) return false;
    if ($moduleKey === 'system') return analystIsAdmin($conn, $analystId);
    // A module switched off for the install is off for everyone, administrators
    // and all-modules analysts included (GH #217). Asked here, the one question
    // every page, endpoint and cross-module button already asks.
    if (!moduleIsEnabled($conn, $moduleKey)) return false;
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
 *
 * @param string $moduleKey  the module the page belongs to, e.g. 'tickets'; also sent back as ?denied= on refusal
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
 * TRAP: pass $owner when the endpoint's DATA belongs to one module. "Any of these"
 * otherwise outlives that module being switched off for the install (GH #217):
 * Knowledge's articles answered anyone with Tickets after Knowledge was switched
 * off. A shared lookup (cost centres, analysts, settings) has no owner and passes none.
 *
 * @param array<int,string> $moduleKeys  the module keys that may use the endpoint, e.g. ['knowledge', 'tickets']
 * @param string|null $owner  the module whose data this is, refused while it is switched off; null for a shared lookup
 */
function requireAnyModuleAccessJson(array $moduleKeys, ?PDO $conn = null, ?string $owner = null): void {
    if (!isset($_SESSION['analyst_id'])) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Not authenticated']);
        exit;
    }
    if ($owner !== null) {
        try {
            if ($conn === null) $conn = connectToDatabase();
            requireModuleOnJson($conn, $owner);
        } catch (Throwable $e) { /* the access check below fails closed on its own */ }
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
 *
 * @param string $moduleKey  the module the endpoint belongs to, e.g. 'assets'; 'system' means administrators only
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
        // Switched off for the install (GH #217) is not a permissions problem, and
        // saying "no access" would send an administrator hunting through grants.
        $off = isset($conn) && !moduleIsEnabled($conn, $moduleKey);
        echo json_encode($off
            ? ['success' => false, 'error' => moduleDisabledMessage($moduleKey), 'module_disabled' => $moduleKey]
            : ['success' => false, 'error' => 'You do not have access to this module']);
        exit;
    }
}

/**
 * Is the signed-in analyst a catalogue approver WITHOUT the Forms module (#180 Part 3)?
 * Then the launcher and the landing page offer them Form approvals - their one way into
 * Forms. Without this front door the approver would hold the permission and have no way
 * to find it. The page itself checks again (catalogueApproverCanEnter()).
 */
function sessionIsFormApproverOnly(): bool
{
    static $memo = null;
    if ($memo !== null) return $memo;
    $memo = false;
    $aid = (int)($_SESSION['analyst_id'] ?? 0);
    $allowed = analystAllowedModulesNow();
    if ($aid <= 0 || $allowed === null || in_array('forms', $allowed, true)) return $memo;   // null = every module
    if (!portalModuleOn('forms')) return $memo;   // no approver door into a switched-off Forms (GH #217)
    try {
        $st = connectToDatabase()->prepare(
            "SELECT 1 FROM forms WHERE approver_id = ? AND requires_approval = 1
             UNION SELECT 1 FROM form_submissions WHERE approver_id = ? LIMIT 1"
        );
        $st->execute([$aid, $aid]);
        $memo = (bool)$st->fetchColumn();
        // GH #220: a member of a team that approves gets the same door. The one
        // definition lives in catalogue_approvals.php (catalogueApproverCanEnter()).
        if (!$memo) {
            require_once __DIR__ . '/catalogue_approvals.php';
            $conn = connectToDatabase();
            $memo = catalogueTeamApprovalAvailable($conn) && catalogueAnalystApprovesAsTeam($conn, $aid);
        }
    } catch (Throwable $e) { /* no door rather than a broken page */ }
    return $memo;
}

/**
 * Fire a settings/CRUD workflow event ({entity}.{created|updated|deleted}) from a
 * UI settings endpoint, without each file having to require the workflow engine.
 * Lazily loads the engine and is fully self-safe — swallows any Throwable
 * (including a missing engine on a minimal install) so it can NEVER affect the
 * save it follows. Funnels into the exact same WorkflowEngine::dispatch() path as
 * every other event, so a webhook fires identically however it was emitted.
 *
 * @param string $entity  the entity key, e.g. 'asset' or 'asset_location' - the first half of the event name
 * @param string $action  'created', 'updated' or 'deleted' - the second half of the event name
 * @param int $id         the id of the record that changed
 * @param ?string $name   its display name for the payload, if known
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
