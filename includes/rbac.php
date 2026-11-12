<?php
/**
 * RBAC Layer 2 — per-module SETTINGS permissions.
 *
 * Layer 1 (includes/functions.php: requireModuleAccess) decides which modules an
 * analyst can ENTER. This file is Layer 2: once in a module, may they also
 * ADMINISTER its settings? See docs/design/rbac.md for the full design.
 *
 * Three rules, all enforced here:
 *   - DENY BY DEFAULT. No grant → no settings access. (Opposite of Layer 1's
 *     "absence = everything", and the right default for an admin surface.)
 *   - is_admin BYPASSES the whole layer. A System administrator implicitly holds
 *     every capability, which is what makes deny-by-default safe on upgrade —
 *     the instance owner keeps everything, and the tightening only bites
 *     non-admins (who mostly shouldn't be in settings anyway).
 *   - ENFORCED SERVER-SIDE, page AND api, failing closed — Layer 1's mistake was
 *     hiding cards without enforcing, so a "restricted" analyst could type the URL.
 *
 * A capability key is '<module>.<action>' and MUST appear in rbacCapabilities()
 * below. The DB never stores a capability the code doesn't know: the registry is
 * the source of truth, the tables only reference it.
 */

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/capabilities.php';

/**
 * The registry (WHAT capabilities exist) lives in includes/capabilities.php. This file
 * is only concerned with WHO holds them, and with enforcing that.
 *
 * Always pass a Cap:: constant to the functions below, never a string literal — see the
 * header of capabilities.php for why a mistyped string here is the dangerous kind of bug.
 */

/**
 * Every capability an analyst effectively holds, as a flat list of keys.
 *
 * is_admin short-circuits to ALL declared capabilities. Otherwise it's the union of the
 * capabilities granted by the roles assigned to the analyst directly and by the roles
 * assigned to any team they belong to — one query, one choke-point, the same
 * individual-plus-team-unioned shape as module and company access.
 *
 * Two filters are then applied, and the order matters:
 *   1. capFromKey() drops anything the code no longer defines (and maps retired keys
 *      through capAliases()), so a stale or hand-inserted DB row can never grant.
 *   2. capExpandUmbrellas() adds everything an umbrella grant implies, so holding
 *      '<module>.manage' gives you the module's individual capabilities too.
 *
 * @return array<int,string>
 */
function getAnalystCapabilities(PDO $conn, int $analystId): array {
    if ($analystId <= 0) return [];
    if (analystIsAdmin($conn, $analystId)) return capAll();

    // Cached for the life of the request, like getAnalystAllowedModules(). A settings page
    // asks "do you hold this?" once per tab, and each ask used to re-run this query — so
    // an 8-tab page cost 8 round-trips to answer a question whose answer cannot change
    // mid-render. Still authoritative: the next request re-reads, so a revoked role bites
    // immediately.
    static $cache = [];
    if (array_key_exists($analystId, $cache)) return $cache[$analystId];

    $sql = "SELECT DISTINCT rc.capability_key
            FROM rbac_role_capabilities rc
            JOIN rbac_roles r ON r.id = rc.role_id AND r.is_active = 1
            WHERE rc.role_id IN (
                SELECT role_id FROM rbac_analyst_roles WHERE analyst_id = ?
                UNION
                SELECT tr.role_id FROM rbac_team_roles tr
                JOIN analyst_teams at ON at.team_id = tr.team_id
                JOIN teams t ON t.id = tr.team_id AND COALESCE(t.is_active, 1) = 1   -- a retired team grants no role
                WHERE at.analyst_id = ?
            )";
    try {
        $stmt = $conn->prepare($sql);
        $stmt->execute([$analystId, $analystId]);
        $granted = $stmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable) {
        return []; // fail closed — a broken query must not grant access (and don't cache it)
    }

    $valid = [];
    foreach ($granted as $key) {
        $resolved = capFromKey((string) $key);
        if ($resolved !== null) $valid[] = $resolved;
    }

    return $cache[$analystId] = capExpandUmbrellas($valid);
}

/**
 * Does this analyst hold a capability? Authoritative (DB-checked). is_admin is
 * always true. Use this for server-side enforcement.
 *
 * TRAP: typed Cap, not string. The held list is strings, and in_array() of an enum
 * against strings is silently false - the type makes passing the wrong thing loud.
 *
 * @param Cap $capability  the capability, e.g. Cap::PROJECTS_MANAGE
 */
function analystHasCapability(PDO $conn, int $analystId, Cap $capability): bool {
    if ($analystId <= 0) return false;
    if (analystIsAdmin($conn, $analystId)) return true;
    return in_array($capability->value, getAnalystCapabilities($conn, $analystId), true);
}

/**
 * Has the module switched this everyday-work permission on? Until it has, module access
 * is enough, exactly as before - the permission exists, can be granted in Roles, and
 * bites only once the administrator has built the role and turned the restriction on.
 *
 * @param Cap $capability  an everyday-work capability (a manifest 'action')
 */
function capActionRestricted(PDO $conn, Cap $capability): bool {
    if (!$capability->isEveryday()) {
        throw new LogicException("'{$capability->value}' is not an everyday-work capability");
    }
    $r = capActionRestriction($capability->value);
    if ($r === null) return true;
    try {
        $s = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ?");
        $s->execute([$r['setting']]);
        return (string)$s->fetchColumn() === (string)$r['value'];
    } catch (Throwable) {
        return false;   // unreadable settings: today's behaviour
    }
}

/**
 * May this analyst do an everyday-work action (#180 Part 3)? True for everyone while its
 * module has not restricted it; afterwards, the capability (administrators hold every
 * capability, as everywhere - designing a form is a power, and admins hold every power).
 *
 * TRAP: this is for POWERS. Separation of duties (approving your own change) is not a
 * power and must not go through here, or is_admin would bypass it.
 *
 * @param int $analystId  the analyst; 0 (a system or portal actor) has no capabilities
 * @param Cap $capability  an everyday-work capability
 */
function analystMayAct(PDO $conn, int $analystId, Cap $capability): bool {
    if (!capActionRestricted($conn, $capability)) return true;
    return analystHasCapability($conn, $analystId, $capability);
}

/**
 * End the request with a JSON refusal. The guards below all refuse this way.
 *
 * `never` (4.0): it does not return, and PHP now holds it to that - until 4.0 each
 * guard repeated these lines and declared `void`, which a reader could take to mean
 * the request carries on.
 *
 * @param int $status  the HTTP status, 401 or 403
 * @param string $error  the message for the caller
 * @param string|null $code  a machine-readable code, when the caller has one
 */
function rbacRefuseJson(int $status, string $error, ?string $code = null): never {
    http_response_code($status);
    header('Content-Type: application/json');
    $body = ['success' => false, 'error' => $error];
    if ($code !== null) $body['code'] = $code;
    echo json_encode($body);
    exit;
}

/**
 * End the request by sending the browser somewhere else - the page twin of rbacRefuseJson().
 *
 * @param string $location  where to send the browser
 */
function rbacRefusePage(string $location): never {
    header('Location: ' . $location);
    exit;
}

/**
 * JSON gate for an everyday-work action: 403 unless analystMayAct().
 *
 * @param Cap $capability  an everyday-work capability
 */
function requireActionJson(Cap $capability, ?PDO $conn = null): void {
    if (!isset($_SESSION['analyst_id'])) {
        rbacRefuseJson(401, 'Not authenticated');
    }
    try {
        if ($conn === null) $conn = connectToDatabase();
        $ok = analystMayAct($conn, (int) $_SESSION['analyst_id'], $capability);
    } catch (Throwable) {
        $ok = false;
    }
    if (!$ok) {
        rbacRefuseJson(403, 'You do not have permission to do this', 'forbidden');
    }
}

/**
 * Page gate: bounce an analyst who lacks $capability back to the launcher, so a
 * settings URL can't simply be typed. Authoritative (DB-checked), fails closed.
 * Call after functions.php is loaded and the login check. Pair with (or place
 * after) requireModuleAccess() — Layer 1 gets you into the module, this decides
 * whether you may configure it.
 *
 * @param Cap $capability  the capability the page needs
 */
function requireCapability(Cap $capability, ?PDO $conn = null): void {
    if (!isset($_SESSION['analyst_id'])) {
        rbacRefusePage(BASE_URL . 'auth/login.php');
    }
    try {
        if ($conn === null) $conn = connectToDatabase();
        $ok = analystHasCapability($conn, (int) $_SESSION['analyst_id'], $capability);
    } catch (Throwable) {
        $ok = false;
    }
    if (!$ok) {
        rbacRefusePage(BASE_URL . '?denied=' . urlencode($capability->value));
    }
}

/**
 * Hard gate for a settings JSON write API: refuse a lacking analyst with 403.
 * The API twin of requireCapability(). Call right after connecting.
 *
 * @param Cap $capability  the capability the endpoint needs
 */
function requireCapabilityJson(Cap $capability, ?PDO $conn = null): void {
    if (!isset($_SESSION['analyst_id'])) {
        rbacRefuseJson(401, 'Not authenticated');
    }
    try {
        if ($conn === null) $conn = connectToDatabase();
        $ok = analystHasCapability($conn, (int) $_SESSION['analyst_id'], $capability);
    } catch (Throwable) {
        $ok = false;
    }
    if (!$ok) {
        rbacRefuseJson(403, 'You do not have permission to manage these settings');
    }
}
