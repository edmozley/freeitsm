<?php
/**
 * Shared Functions
 * Include this file in any script that needs common functionality
 *
 * Usage: require_once '../includes/functions.php'; (from api folder)
 *        require_once 'includes/functions.php'; (from root folder)
 */

/**
 * Connect to MySQL database using PDO
 *
 * @return PDO Database connection
 * @throws Exception If connection fails
 */
function connectToDatabase() {
    $dsn = "mysql:host=" . DB_SERVER . ";dbname=" . DB_NAME . ";charset=utf8mb4";
    $conn = new PDO($dsn, DB_USERNAME, DB_PASSWORD);
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
             'wiki','lms','process-mapper','tasks','cmdb','network-mapper','workflow'];
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
    $stmt = $conn->prepare("SELECT is_admin FROM analysts WHERE id = ?");
    $stmt->execute([$analystId]);
    return (int) $stmt->fetchColumn() === 1;
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
?>
