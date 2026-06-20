<?php
/**
 * Multi-tenancy helper — the single choke-point for tenant logic.
 *
 * A FreeITSM install can host multiple client companies ("tenants"). On a
 * single-company install everything lives inside one silent "Default" tenant,
 * so multi-tenancy stays completely invisible until a second tenant is created
 * (see isMultiTenant()).
 *
 * These functions are deliberately defensive: if the `tenants` table does not
 * exist yet (an install that hasn't run Database Verify), they degrade to
 * "single tenant, id 1" rather than throwing — so the file is safe to include
 * anywhere, at any point in the rollout.
 *
 * All functions take the caller's PDO connection (matching the rest of the
 * codebase). Per-request results are cached in statics.
 *
 * NOTE: nothing wires these into queries yet — this is foundation only.
 */

/** Fallback tenant id used when the tenants table doesn't exist yet. */
if (!defined('TENANCY_FALLBACK_TENANT_ID')) {
    define('TENANCY_FALLBACK_TENANT_ID', 1);
}

/**
 * Does the tenants table exist? (Cached.) Lets every other function degrade
 * gracefully on an install that hasn't been migrated yet.
 */
function tenancyTablesReady(PDO $conn): bool {
    static $ready = null;
    if ($ready === null) {
        try {
            $conn->query("SELECT 1 FROM tenants LIMIT 1");
            $ready = true;
        } catch (Exception $e) {
            $ready = false;
        }
    }
    return $ready;
}

/**
 * Number of tenants on this install. Returns 1 if the table isn't ready.
 */
function tenantCount(PDO $conn): int {
    static $count = null;
    if ($count === null) {
        if (!tenancyTablesReady($conn)) {
            $count = 1;
        } else {
            $count = (int) $conn->query("SELECT COUNT(*) FROM tenants")->fetchColumn();
            if ($count < 1) $count = 1;
        }
    }
    return $count;
}

/**
 * Is multi-tenancy "awake"? True only once a second tenant exists. This is the
 * master switch: while false, switchers/scoping/triage all stay dormant and the
 * app behaves exactly as a single-company install.
 */
function isMultiTenant(PDO $conn): bool {
    return tenantCount($conn) > 1;
}

/**
 * The id of the silent Default tenant (the one that owns everything on a
 * single-company install). Falls back to the lowest tenant id, then to
 * TENANCY_FALLBACK_TENANT_ID. (Cached.)
 */
function getDefaultTenantId(PDO $conn): int {
    static $id = null;
    if ($id === null) {
        if (!tenancyTablesReady($conn)) {
            $id = TENANCY_FALLBACK_TENANT_ID;
        } else {
            $val = $conn->query("SELECT id FROM tenants WHERE is_default = 1 ORDER BY id LIMIT 1")->fetchColumn();
            if ($val === false) {
                $val = $conn->query("SELECT id FROM tenants ORDER BY id LIMIT 1")->fetchColumn();
            }
            $id = $val === false ? TENANCY_FALLBACK_TENANT_ID : (int) $val;
        }
    }
    return $id;
}

/**
 * All tenants as rows [id, name, slug, is_default, is_active].
 * @param bool $activeOnly only return tenants with is_active = 1
 */
function getAllTenants(PDO $conn, bool $activeOnly = false): array {
    if (!tenancyTablesReady($conn)) return [];
    $sql = "SELECT id, name, slug, is_default, is_active FROM tenants";
    if ($activeOnly) $sql .= " WHERE is_active = 1";
    $sql .= " ORDER BY is_default DESC, name ASC";
    $rows = $conn->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) {
        $r['id']         = (int) $r['id'];
        $r['is_default'] = (bool) $r['is_default'];
        $r['is_active']  = (bool) $r['is_active'];
    }
    return $rows;
}

/**
 * A single tenant row, or null if not found.
 */
function getTenantById(PDO $conn, int $tenantId): ?array {
    if (!tenancyTablesReady($conn)) return null;
    $stmt = $conn->prepare("SELECT id, name, slug, is_default, is_active FROM tenants WHERE id = ?");
    $stmt->execute([$tenantId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) return null;
    $row['id']         = (int) $row['id'];
    $row['is_default'] = (bool) $row['is_default'];
    $row['is_active']  = (bool) $row['is_active'];
    return $row;
}

/**
 * The tenant ids an analyst may access. An analyst flagged
 * can_access_all_tenants sees every tenant; otherwise only those granted via
 * analyst_tenant_access. (Cached per analyst per request.)
 */
function getAccessibleTenantIds(PDO $conn, int $analystId): array {
    static $cache = [];
    if (array_key_exists($analystId, $cache)) return $cache[$analystId];

    if (!tenancyTablesReady($conn)) {
        return $cache[$analystId] = [TENANCY_FALLBACK_TENANT_ID];
    }

    // All-access flag → every tenant.
    $stmt = $conn->prepare("SELECT can_access_all_tenants FROM analysts WHERE id = ?");
    $stmt->execute([$analystId]);
    $all = (int) $stmt->fetchColumn();
    if ($all === 1) {
        $ids = array_map('intval', $conn->query("SELECT id FROM tenants")->fetchAll(PDO::FETCH_COLUMN));
        return $cache[$analystId] = $ids;
    }

    // Otherwise only explicitly granted tenants.
    $stmt = $conn->prepare("SELECT tenant_id FROM analyst_tenant_access WHERE analyst_id = ?");
    $stmt->execute([$analystId]);
    return $cache[$analystId] = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * May this analyst access this tenant?
 */
function analystCanAccessTenant(PDO $conn, int $analystId, int $tenantId): bool {
    return in_array($tenantId, getAccessibleTenantIds($conn, $analystId), true);
}

/**
 * The analyst's current working company context.
 *
 * - Single-company install → always the Default tenant.
 * - Multi-tenant → the session's active tenant if the analyst may access it,
 *   otherwise the Default tenant (if accessible), otherwise their first
 *   accessible tenant.
 *
 * @param int|null $analystId omit to skip access checks (returns the session
 *                            value or the default tenant).
 */
function getActiveTenantId(PDO $conn, ?int $analystId = null): int {
    if (!isMultiTenant($conn)) {
        return getDefaultTenantId($conn);
    }

    $sessionId = isset($_SESSION['active_tenant_id']) ? (int) $_SESSION['active_tenant_id'] : 0;
    if ($sessionId > 0 && ($analystId === null || analystCanAccessTenant($conn, $analystId, $sessionId))) {
        return $sessionId;
    }

    $defaultId = getDefaultTenantId($conn);
    if ($analystId === null || analystCanAccessTenant($conn, $analystId, $defaultId)) {
        return $defaultId;
    }

    $accessible = getAccessibleTenantIds($conn, $analystId);
    return $accessible[0] ?? $defaultId;
}

/**
 * Set the analyst's active company context. The caller must have a writable
 * session (i.e. session_start() without read_and_close).
 */
function setActiveTenantId(int $tenantId): void {
    $_SESSION['active_tenant_id'] = $tenantId;
}
