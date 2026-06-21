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

/**
 * Build a SQL predicate that scopes ticket rows to the analyst's active company.
 *
 * Returns ['', []] when multi-tenancy is dormant (single company) — i.e. NO
 * filtering at N=1, so single-company installs are unaffected.
 *
 * The Default company also "owns" any ticket whose tenant_id is NULL (e.g. an
 * email/self-service/workflow ticket not yet routed to a company) — so nothing
 * is ever hidden from view; an un-routed ticket simply shows under Default.
 *
 * @param string $alias the tickets table alias used in the query (e.g. 't')
 * @return array [sqlFragment, params] — append the fragment to a WHERE/ON clause
 *               and merge the params into the statement's bound values.
 */
function ticketTenantFilter(PDO $conn, int $analystId, string $alias = 't'): array {
    if (!isMultiTenant($conn)) {
        return ['', []];
    }
    $active  = getActiveTenantId($conn, $analystId);
    $default = getDefaultTenantId($conn);
    $col = $alias === '' ? 'tenant_id' : "$alias.tenant_id";
    if ($active === $default) {
        return [" AND ($col = ? OR $col IS NULL)", [$active]];
    }
    return [" AND $col = ?", [$active]];
}

/**
 * Decide which company (tenant) a NEW inbound-email ticket belongs to.
 *
 * This implements the design's inbound routing for the "no existing ticket"
 * case only — a reply to an existing ticket inherits that ticket's tenant
 * upstream (the email is attached to the found ticket) and never reaches here:
 *
 *   1. Single-company install (!isMultiTenant) → always the Default company.
 *   2. Pinned mailbox (target_mailboxes.tenant_id set) → that company; the
 *      sender is ignored. (A pinned mailbox is also the outbound reply identity.)
 *   3. Shared-intake mailbox (tenant_id NULL) → match the sender's domain
 *      against the companies' registered domains (tenant_domains):
 *        - match  → that company.
 *        - no match (including freemail, which is never registered) → NULL,
 *          meaning "triage": the ticket is left un-companied and, because
 *          ticketTenantFilter() treats NULL as Default-owned, surfaces under
 *          the Default company until an analyst files it. Nothing is ever lost.
 *
 * Deliberately defensive (try/catch around the mailbox/domain lookups) so it is
 * safe to call on a part-migrated install.
 *
 * @param int|string|null $mailboxId  the importing mailbox's id
 * @param string          $fromAddress the sender's email address
 * @return int|null a tenant id, or NULL meaning "send to triage".
 */
function resolveTicketTenantForEmail(PDO $conn, $mailboxId, string $fromAddress): ?int {
    // Single-company install: everything belongs to the silent Default company.
    if (!isMultiTenant($conn)) {
        return getDefaultTenantId($conn);
    }

    // (2) Pinned mailbox decides the company outright — sender ignored.
    if ($mailboxId !== null && $mailboxId !== '') {
        try {
            $stmt = $conn->prepare("SELECT tenant_id FROM target_mailboxes WHERE id = ?");
            $stmt->execute([(int) $mailboxId]);
            $val = $stmt->fetchColumn();
            if ($val !== false && $val !== null) {
                return (int) $val;
            }
        } catch (Exception $e) {
            // tenant_id column missing on a part-migrated install → treat as shared.
        }
    }

    // (3) Shared intake → route by the sender's domain.
    $domain = '';
    if (strpos($fromAddress, '@') !== false) {
        $domain = strtolower(trim(substr(strrchr($fromAddress, '@'), 1)));
    }
    if ($domain !== '') {
        try {
            $stmt = $conn->prepare("SELECT tenant_id FROM tenant_domains WHERE domain = ? LIMIT 1");
            $stmt->execute([$domain]);
            $val = $stmt->fetchColumn();
            if ($val !== false && $val !== null) {
                return (int) $val;
            }
        } catch (Exception $e) {
            // tenant_domains missing → fall through to triage.
        }
    }

    // No match → triage (NULL).
    return null;
}

/**
 * The built-in list of public free-email / consumer domains. These are ALWAYS
 * treated as freemail; an admin can ADD to the list (the freemail_domains
 * table, see getCustomFreemailDomains) but never remove a built-in — so
 * gmail.com and friends can't be accidentally un-protected.
 */
function freemailBuiltinDomains(): array {
    return [
        'gmail.com', 'googlemail.com', 'outlook.com', 'hotmail.com', 'hotmail.co.uk',
        'live.com', 'live.co.uk', 'msn.com', 'yahoo.com', 'yahoo.co.uk', 'ymail.com',
        'icloud.com', 'me.com', 'mac.com', 'aol.com', 'protonmail.com', 'proton.me',
        'gmx.com', 'gmx.co.uk', 'mail.com', 'zoho.com', 'yandex.com', 'fastmail.com',
        'btinternet.com', 'sky.com', 'talktalk.net', 'virginmedia.com', 'ntlworld.com',
    ];
}

/**
 * Admin-added custom freemail domains (the freemail_domains table), lower-cased.
 * Cached per request; degrades to [] if the table doesn't exist yet — so the
 * file is safe on a part-migrated install.
 */
function getCustomFreemailDomains(PDO $conn): array {
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        try {
            foreach ($conn->query("SELECT domain FROM freemail_domains ORDER BY domain") as $row) {
                $d = strtolower(trim($row['domain']));
                if ($d !== '') $cache[] = $d;
            }
        } catch (Exception $e) {
            $cache = [];
        }
    }
    return $cache;
}

/**
 * Is this a public free-email / consumer domain (gmail.com, outlook.com, …)?
 *
 * Such domains must NEVER be registered to a company for shared-intake routing
 * (two different clients can both send from gmail.com, so a mapping would
 * mis-route one of them). They still reach support fine — they simply land in
 * triage and are filed by hand. Used to guard domain registration and to flag
 * triage rows.
 *
 * Matches the built-in list (freemailBuiltinDomains) plus any admin-added
 * custom domains (getCustomFreemailDomains).
 */
function isFreemailDomain(PDO $conn, string $domain): bool {
    $d = strtolower(trim($domain));
    if ($d === '') return false;
    if (in_array($d, freemailBuiltinDomains(), true)) return true;
    return in_array($d, getCustomFreemailDomains($conn), true);
}

/**
 * Normalise a user-entered email domain for storage/matching: lower-cased,
 * trimmed, with any leading "@", scheme or "www." stripped. Returns '' if what
 * remains isn't a plausible domain.
 */
function normaliseEmailDomain(string $raw): string {
    $d = strtolower(trim($raw));
    $d = preg_replace('#^https?://#', '', $d);
    $d = ltrim($d, '@');
    $d = preg_replace('#^www\.#', '', $d);
    $d = explode('/', $d)[0];           // drop any path
    $d = explode('@', $d);              // if they pasted a full address, keep the domain
    $d = end($d);
    // A plausible domain: labels of letters/digits/hyphens, at least one dot, valid TLD.
    if (!preg_match('/^(?=.{1,253}$)([a-z0-9](-?[a-z0-9])*\.)+[a-z]{2,}$/', $d)) {
        return '';
    }
    return $d;
}
