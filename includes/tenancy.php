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
 * The tenant ids an analyst may access. Company access is ADDITIVE from two
 * sources, unioned:
 *   1. the analyst's own grant (can_access_all_tenants flag / analyst_tenant_access);
 *   2. the teams they belong to (a team flagged can_access_all_tenants grants
 *      every company; otherwise its team_tenant_access rows).
 * So an analyst can reach a company if their own access OR any of their teams
 * grants it. A team grants nothing until explicitly configured, so this only
 * ever widens access once an admin sets it up. (Cached per analyst per request.)
 */
function getAccessibleTenantIds(PDO $conn, int $analystId): array {
    static $cache = [];
    if (array_key_exists($analystId, $cache)) return $cache[$analystId];

    if (!tenancyTablesReady($conn)) {
        return $cache[$analystId] = [TENANCY_FALLBACK_TENANT_ID];
    }

    $allTenantIds = function () use ($conn) {
        return array_map('intval', $conn->query("SELECT id FROM tenants")->fetchAll(PDO::FETCH_COLUMN));
    };

    // Analyst all-access flag → every tenant.
    $stmt = $conn->prepare("SELECT can_access_all_tenants FROM analysts WHERE id = ?");
    $stmt->execute([$analystId]);
    if ((int) $stmt->fetchColumn() === 1) {
        return $cache[$analystId] = $allTenantIds();
    }

    // Member of an all-access team → every tenant. (Guarded: teams may predate
    // the can_access_all_tenants column on an un-verified DB.)
    try {
        $stmt = $conn->prepare(
            "SELECT 1 FROM analyst_teams at
             JOIN teams t ON at.team_id = t.id
             WHERE at.analyst_id = ? AND t.can_access_all_tenants = 1 LIMIT 1"
        );
        $stmt->execute([$analystId]);
        if ($stmt->fetchColumn()) {
            return $cache[$analystId] = $allTenantIds();
        }
    } catch (Exception $e) { /* column not migrated yet — ignore team all-access */ }

    // Otherwise: the analyst's own granted tenants, unioned with the specific
    // companies granted by any team they belong to.
    try {
        $stmt = $conn->prepare(
            "SELECT tenant_id FROM analyst_tenant_access WHERE analyst_id = :aid
             UNION
             SELECT tta.tenant_id FROM team_tenant_access tta
               JOIN analyst_teams at ON at.team_id = tta.team_id
              WHERE at.analyst_id = :aid"
        );
        $stmt->execute([':aid' => $analystId]);
        return $cache[$analystId] = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    } catch (Exception $e) {
        // team_tenant_access not migrated yet — fall back to analyst-only grants.
        $stmt = $conn->prepare("SELECT tenant_id FROM analyst_tenant_access WHERE analyst_id = ?");
        $stmt->execute([$analystId]);
        return $cache[$analystId] = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }
}

/**
 * May this analyst access this tenant?
 */
function analystCanAccessTenant(PDO $conn, int $analystId, int $tenantId): bool {
    return in_array($tenantId, getAccessibleTenantIds($conn, $analystId), true);
}

/**
 * May this analyst access this *ticket* (by its owning company)?
 *
 * The isolation gate for detail-by-id and mutation-by-id endpoints: a ticket
 * belongs to a company, and an analyst may only see/act on a ticket whose
 * company is in their accessible set (all-access analysts see every company).
 * A ticket with tenant_id NULL is treated as Default-owned (an un-routed ticket
 * that surfaces under Default), so access follows Default.
 *
 * Deliberately defensive:
 *   - Single-company install → always true (nothing to isolate).
 *   - Unknown ticket id → false (don't reveal another company's row).
 *   - Part-migrated table (no tenant_id column) → true, so it never blocks an
 *     install mid-rollout (isMultiTenant is false there anyway).
 */
function analystCanAccessTicket(PDO $conn, int $analystId, $ticketId): bool {
    if (!isMultiTenant($conn)) {
        return true;
    }
    $ticketId = (int) $ticketId;
    if ($ticketId <= 0) {
        return false;
    }
    try {
        $stmt = $conn->prepare("SELECT tenant_id FROM tickets WHERE id = ?");
        $stmt->execute([$ticketId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return false;
        }
        $tid = ($row['tenant_id'] === null) ? getDefaultTenantId($conn) : (int) $row['tenant_id'];
        return analystCanAccessTenant($conn, $analystId, $tid);
    } catch (Exception $e) {
        return true; // tenant_id column missing on a part-migrated install.
    }
}

/**
 * May this analyst access this *problem* (by its owning company)? The Problem
 * Management twin of analystCanAccessTicket() — same rules (single-company → always
 * true; NULL tenant treated as Default-owned; unknown id → false; part-migrated
 * table → true).
 */
function analystCanAccessProblem(PDO $conn, int $analystId, $problemId): bool {
    if (!isMultiTenant($conn)) {
        return true;
    }
    $problemId = (int) $problemId;
    if ($problemId <= 0) {
        return false;
    }
    try {
        $stmt = $conn->prepare("SELECT tenant_id FROM problems WHERE id = ?");
        $stmt->execute([$problemId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return false;
        }
        $tid = ($row['tenant_id'] === null) ? getDefaultTenantId($conn) : (int) $row['tenant_id'];
        return analystCanAccessTenant($conn, $analystId, $tid);
    } catch (Exception $e) {
        return true; // tenant_id column missing on a part-migrated install.
    }
}

/**
 * May this analyst access this *change* (by its owning company)? The Change
 * Management twin of analystCanAccessProblem() — same rules (single-company →
 * always true; NULL tenant treated as Default-owned; unknown id → false;
 * part-migrated table → true).
 */
function analystCanAccessChange(PDO $conn, int $analystId, $changeId): bool {
    if (!isMultiTenant($conn)) {
        return true;
    }
    $changeId = (int) $changeId;
    if ($changeId <= 0) {
        return false;
    }
    try {
        $stmt = $conn->prepare("SELECT tenant_id FROM changes WHERE id = ?");
        $stmt->execute([$changeId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return false;
        }
        $tid = ($row['tenant_id'] === null) ? getDefaultTenantId($conn) : (int) $row['tenant_id'];
        return analystCanAccessTenant($conn, $analystId, $tid);
    } catch (Exception $e) {
        return true; // tenant_id column missing on a part-migrated install.
    }
}

/**
 * May this analyst access this *asset* (by its owning company)? The Asset
 * Management twin of analystCanAccessChange() — same rules (single-company →
 * always true; NULL tenant treated as Default-owned; unknown id → false;
 * part-migrated table → true).
 */
function analystCanAccessAsset(PDO $conn, int $analystId, $assetId): bool {
    if (!isMultiTenant($conn)) {
        return true;
    }
    $assetId = (int) $assetId;
    if ($assetId <= 0) {
        return false;
    }
    try {
        $stmt = $conn->prepare("SELECT tenant_id FROM assets WHERE id = ?");
        $stmt->execute([$assetId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return false;
        }
        $tid = ($row['tenant_id'] === null) ? getDefaultTenantId($conn) : (int) $row['tenant_id'];
        return analystCanAccessTenant($conn, $analystId, $tid);
    } catch (Exception $e) {
        return true; // tenant_id column missing on a part-migrated install.
    }
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
 * Generic active-company list filter for any tenant-scoped table (Change
 * Management onward). Same semantics as ticketTenantFilter but the table alias
 * and column are parameterised so each Phase-3 module can reuse it: scopes to
 * the analyst's ACTIVE company, and the Default company also owns NULL-tenant
 * rows (so nothing is hidden at N=1 or from Default). Returns ['', []] at N=1.
 *
 * @return array [sqlFragment, params] — append to a WHERE/ON clause, merge params.
 */
function activeTenantFilter(PDO $conn, int $analystId, string $alias = 't', string $col = 'tenant_id'): array {
    if (!isMultiTenant($conn)) {
        return ['', []];
    }
    $active  = getActiveTenantId($conn, $analystId);
    $default = getDefaultTenantId($conn);
    $qualified = $alias === '' ? $col : "$alias.$col";
    if ($active === $default) {
        return [" AND ($qualified = ? OR $qualified IS NULL)", [$active]];
    }
    return [" AND $qualified = ?", [$active]];
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
 *   3. Shared-intake mailbox (tenant_id NULL) → most-specific-match wins:
 *        (a) the exact sender address is on a company's "specific senders" list
 *            (tenant_sender_addresses) → that company. This is how a personal /
 *            freemail address (jane@gmail.com) reaches the right company even
 *            though its domain can never be mapped (two clients share gmail.com).
 *        (b) otherwise match the sender's domain against the companies'
 *            registered domains (tenant_domains) → that company.
 *        - no match (including freemail not on any list) → NULL, meaning
 *          "triage": the ticket is left un-companied and, because
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

    // (3a) Shared intake → exact sender-address match (most specific wins).
    // A personal/freemail address (jane@gmail.com) can be filed to a company
    // even though its domain is never mappable — this is that override.
    $addr = strtolower(trim($fromAddress));
    if ($addr !== '') {
        try {
            $stmt = $conn->prepare("SELECT tenant_id FROM tenant_sender_addresses WHERE email = ? LIMIT 1");
            $stmt->execute([$addr]);
            $val = $stmt->fetchColumn();
            if ($val !== false && $val !== null) {
                return (int) $val;
            }
        } catch (Exception $e) {
            // tenant_sender_addresses missing → fall through to domain routing.
        }
    }

    // (3b) Shared intake → route by the sender's domain.
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
 * Decide which company a NEW inbound messaging-channel ticket (WhatsApp etc.)
 * belongs to. The channel twin of resolveTicketTenantForEmail():
 *
 *   1. Single-company install → the Default company.
 *   2. Pinned channel (messaging_channels.tenant_id set) → that company; the
 *      sender phone is ignored. (A pinned channel is also the reply identity.)
 *   3. Shared channel (tenant_id NULL) → the exact sender phone is mapped to a
 *      company (tenant_channel_senders) → that company. Phone numbers have no
 *      domain, so there is no domain-match step — an unmapped sender goes to
 *      triage (NULL), exactly like an unmatched email, and surfaces under Default.
 *
 * Defensive (try/catch) so it is safe on a part-migrated install.
 *
 * @param int|string|null $channelId    the receiving channel's id
 * @param string          $fromIdentifier the sender's phone, normalised ('+digits')
 * @return int|null a tenant id, or NULL meaning "triage".
 */
function resolveTicketTenantForChannel(PDO $conn, $channelId, string $fromIdentifier): ?int {
    if (!isMultiTenant($conn)) {
        return getDefaultTenantId($conn);
    }

    // (2) Pinned channel decides the company outright — sender ignored.
    if ($channelId !== null && $channelId !== '') {
        try {
            $stmt = $conn->prepare("SELECT tenant_id FROM messaging_channels WHERE id = ?");
            $stmt->execute([(int) $channelId]);
            $val = $stmt->fetchColumn();
            if ($val !== false && $val !== null) {
                return (int) $val;
            }
        } catch (Exception $e) {
            // tenant_id column missing on a part-migrated install → treat as shared.
        }
    }

    // (3) Shared channel → exact sender-phone match, else triage.
    $id = trim($fromIdentifier);
    if ($id !== '') {
        try {
            $stmt = $conn->prepare("SELECT tenant_id FROM tenant_channel_senders WHERE identifier = ? LIMIT 1");
            $stmt->execute([$id]);
            $val = $stmt->fetchColumn();
            if ($val !== false && $val !== null) {
                return (int) $val;
            }
        } catch (Exception $e) {
            // table missing → fall through to triage.
        }
    }

    // No match → triage (NULL).
    return null;
}

/**
 * Resolve an email address to the tenant that owns it, for LOGIN routing
 * (which IdP should this person be sent to). Mirrors the email-routing rule
 * (exact sender address → registered domain), but without the mailbox step.
 *
 * Returns the tenant id, or null if the address maps to no company (unknown
 * domain, or a freemail address) → caller falls back to local login.
 */
function resolveTenantIdForAddress(PDO $conn, string $email): ?int {
    $addr = strtolower(trim($email));
    if ($addr === '' || strpos($addr, '@') === false) return null;

    // (1) Exact sender-address override (lets a freemail address map to a company).
    try {
        $stmt = $conn->prepare("SELECT tenant_id FROM tenant_sender_addresses WHERE email = ? LIMIT 1");
        $stmt->execute([$addr]);
        $val = $stmt->fetchColumn();
        if ($val !== false && $val !== null) return (int) $val;
    } catch (Exception $e) { /* table missing → fall through */ }

    // (2) Registered domain → tenant.
    $domain = strtolower(trim(substr(strrchr($addr, '@'), 1)));
    if ($domain !== '') {
        try {
            $stmt = $conn->prepare("SELECT tenant_id FROM tenant_domains WHERE domain = ? LIMIT 1");
            $stmt->execute([$domain]);
            $val = $stmt->fetchColumn();
            if ($val !== false && $val !== null) return (int) $val;
        } catch (Exception $e) { /* table missing → fall through */ }
    }

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

/**
 * Normalise a user-entered email address for storage/matching: lower-cased,
 * trimmed, with any "mailto:" prefix or surrounding angle brackets stripped.
 * Returns '' if what remains isn't a valid email address.
 *
 * Used for the per-company "specific senders" list (tenant_sender_addresses),
 * which matches on the exact sender address — unlike domains, freemail
 * addresses are allowed here (that's the whole point: route an individual
 * personal address even when its domain can't be mapped).
 */
function normaliseEmailAddress(string $raw): string {
    $a = strtolower(trim($raw));
    $a = preg_replace('#^mailto:#', '', $a);
    $a = trim($a, '<> ');
    if ($a === '' || filter_var($a, FILTER_VALIDATE_EMAIL) === false) {
        return '';
    }
    return $a;
}

/**
 * Resolve a per-company config list under the "add + hide" override model
 * (design §7) — the single primitive every overridable list-setting routes
 * through (ticket types, ticket origins, departments, …).
 *
 * Returns the rows of $table that should be visible to $tenantId:
 *   - global defaults (`tenant_id IS NULL`) that this company has NOT hidden
 *     (via tenant_config_hidden), PLUS
 *   - the company's own added rows (`tenant_id = $tenantId`).
 *
 * Deliberately defensive:
 *   - **Single-company install** (or a part-migrated table that lacks the
 *     `tenant_id` column / the hidden table) → degrades to "all rows", i.e.
 *     EXACTLY today's behaviour. So this is safe to call anywhere during rollout.
 *   - Identifiers ($table, $entityType) are developer-supplied literals; they're
 *     still regex-guarded so this can never become an injection vector.
 *
 * @param string $table       the config table (e.g. 'ticket_types')
 * @param string $entityType  key used in tenant_config_hidden (e.g. 'ticket_type')
 * @param int    $tenantId    the company to resolve for (usually the active tenant)
 * @param string $cols        columns to select (default '*')
 * @param string $activeWhere extra always-applied filter, no leading AND
 *                            (e.g. 'is_active = 1'); must be unambiguous (no alias)
 * @param string $orderBy     ORDER BY body, no keyword (default 'display_order, name')
 * @return array rows as associative arrays
 */
function getTenantConfigRows(PDO $conn, string $table, string $entityType, int $tenantId, string $cols = '*', string $activeWhere = '', string $orderBy = 'display_order, name'): array {
    if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $table) || !preg_match('/^[a-zA-Z_]+$/', $entityType)) {
        return [];
    }
    $tail = ($activeWhere !== '' ? " WHERE $activeWhere" : '') . ($orderBy !== '' ? " ORDER BY $orderBy" : '');

    // Single-company (or tables not ready): behave exactly as today — all rows.
    if (!isMultiTenant($conn)) {
        try {
            return $conn->query("SELECT $cols FROM $table$tail")->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            return [];
        }
    }

    // Multi-company: global-not-hidden + this company's own.
    $sql = "SELECT $cols FROM $table
            WHERE ( ( tenant_id IS NULL
                      AND id NOT IN (SELECT entity_id FROM tenant_config_hidden
                                     WHERE tenant_id = ? AND entity_type = ?) )
                    OR tenant_id = ? )";
    if ($activeWhere !== '') $sql .= " AND ($activeWhere)";
    if ($orderBy !== '')     $sql .= " ORDER BY $orderBy";

    try {
        $stmt = $conn->prepare($sql);
        $stmt->execute([$tenantId, $entityType, $tenantId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        // Part-migrated (no tenant_id column / no hidden table) → fall back to all.
        try {
            return $conn->query("SELECT $cols FROM $table$tail")->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e2) {
            return [];
        }
    }
}
