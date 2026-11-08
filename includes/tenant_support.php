<?php
/**
 * "Company B supports company A" (GH #228).
 *
 * Some installs have one company giving IT support to another: company B's
 * analysts look after company A's people. A requester still belongs to ONE
 * company - making a person belong to several would mean a second rule for
 * every portal screen that hangs off their company (Knowledge, the catalogue,
 * categories, mailboxes, Team tickets), and installs would want different
 * answers. Instead, company B says which companies it supports, with two ticks:
 *
 *   quiet_warning  A ticket filed under B from somebody at A is expected, so the
 *                  reading pane does not warn "the requester looks like A".
 *   portal_raise   A's portal users get a "Raise with" choice on New ticket and
 *                  may send a ticket to B. ONLY the ticket goes to B: its
 *                  mailboxes, categories, types and questions are B's; everything
 *                  else on their portal stays A's. Their managers (Team tickets)
 *                  see those tickets too.
 *
 * One row per pair, set on B under System -> Companies. Both ticks off = no row,
 * so an install that never uses this behaves exactly as before.
 *
 * The other way to run this needs no link at all: give B's analysts access to
 * company A, and A's tickets stay in A. The help page explains both.
 *
 * Every function is safe before Database Verification has made the table: they
 * answer "no links".
 */

require_once __DIR__ . '/tenancy.php';

/** Has Database Verification created tenant_support_links yet? */
function tenantSupportReady(PDO $conn): bool
{
    static $ready = null;
    if ($ready === null) {
        try {
            $conn->query('SELECT supporter_tenant_id FROM tenant_support_links LIMIT 0');
            $ready = true;
        } catch (Throwable) {
            $ready = false;
        }
    }
    return $ready;
}

/**
 * The companies one company supports, with its two ticks.
 *
 * @param int $supporterId  company B
 * @return array<int,array{quiet_warning:bool,portal_raise:bool}> keyed by company A's id
 */
function tenantSupportLinksFor(PDO $conn, int $supporterId): array
{
    if (!tenantSupportReady($conn)) return [];
    $st = $conn->prepare("SELECT supported_tenant_id, quiet_warning, portal_raise FROM tenant_support_links WHERE supporter_tenant_id = ?");
    $st->execute([$supporterId]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[(int)$r['supported_tenant_id']] = [
            'quiet_warning' => (bool)$r['quiet_warning'],
            'portal_raise'  => (bool)$r['portal_raise'],
        ];
    }
    return $out;
}

/**
 * Save one pair. Both ticks off removes the row.
 *
 * @throws InvalidArgumentException a company cannot support itself
 */
function tenantSupportSet(PDO $conn, int $supporterId, int $supportedId, bool $quietWarning, bool $portalRaise): void
{
    if ($supporterId === $supportedId) {
        throw new InvalidArgumentException('A company cannot support itself.');
    }
    if (!$quietWarning && !$portalRaise) {
        $conn->prepare("DELETE FROM tenant_support_links WHERE supporter_tenant_id = ? AND supported_tenant_id = ?")
             ->execute([$supporterId, $supportedId]);
        return;
    }
    $conn->prepare(
        "INSERT INTO tenant_support_links (supporter_tenant_id, supported_tenant_id, quiet_warning, portal_raise)
         VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE quiet_warning = VALUES(quiet_warning), portal_raise = VALUES(portal_raise)"
    )->execute([$supporterId, $supportedId, $quietWarning ? 1 : 0, $portalRaise ? 1 : 0]);
}

/**
 * Should the reading pane keep quiet about a requester from $requesterCompanyId
 * on a ticket filed under $filedCompanyId? True only when the filed company
 * supports the requester's company with "Don't warn" ticked.
 */
function tenantSupportQuietWarning(PDO $conn, int $filedCompanyId, int $requesterCompanyId): bool
{
    if (!tenantSupportReady($conn)) return false;
    $st = $conn->prepare("SELECT 1 FROM tenant_support_links WHERE supporter_tenant_id = ? AND supported_tenant_id = ? AND quiet_warning = 1");
    $st->execute([$filedCompanyId, $requesterCompanyId]);
    return $st->fetchColumn() !== false;
}

/**
 * The ACTIVE companies that let $ownCompanyId's portal users raise tickets with
 * them (portal_raise ticked). Ids only.
 *
 * @return int[]
 */
function tenantSupportPortalSupporters(PDO $conn, int $ownCompanyId): array
{
    if (!tenantSupportReady($conn) || !isMultiTenant($conn)) return [];
    $st = $conn->prepare(
        "SELECT l.supporter_tenant_id FROM tenant_support_links l
           JOIN tenants t ON t.id = l.supporter_tenant_id AND t.is_active = 1
          WHERE l.supported_tenant_id = ? AND l.portal_raise = 1"
    );
    $st->execute([$ownCompanyId]);
    return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * What the portal's "Raise with" picker offers: the requester's own company
 * first, then each company supporting it from the portal, by name.
 *
 * Empty when there is nothing to choose - no company of their own, a single-
 * company install, or nobody supporting it - so the picker is simply not drawn.
 *
 * ⚠️ One function for the list AND the check (selfServiceTicketTenant() below):
 * a narrowed dropdown is not a check.
 *
 * @param ?int $ownCompanyId  the requester's users.tenant_id
 * @return list<array{id:int,name:string,own:bool}>
 */
function selfServiceRaiseWithCompanies(PDO $conn, ?int $ownCompanyId): array
{
    if ($ownCompanyId === null) return [];
    $supporters = tenantSupportPortalSupporters($conn, $ownCompanyId);
    if (!$supporters) return [];
    $own = getTenantById($conn, $ownCompanyId);
    $out = [['id' => $ownCompanyId, 'name' => (string)($own['name'] ?? ''), 'own' => true]];
    $others = [];
    foreach ($supporters as $id) {
        $t = getTenantById($conn, $id);
        if ($t) $others[] = ['id' => $id, 'name' => (string)$t['name'], 'own' => false];
    }
    usort($others, fn($a, $b) => strcasecmp($a['name'], $b['name']));
    return array_merge($out, $others);
}
