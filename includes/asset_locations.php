<?php
/**
 * Asset locations: which ones a company can see, including SHARED ones (2.6.0).
 *
 * Locations were purely per-company on purpose: a client's sites are its own.
 * A customer asked for locations shared across companies, such as a data centre
 * or head office that several clients' kit sits in. So a location can now be
 * marked shared, and every company can pick it, alongside its own private ones.
 *
 * THE RULES, and why:
 *
 *  - A shared location is stored with tenant_id NULL and is_shared = 1. NULL
 *    because it belongs to no one company: a company-owned shared location would
 *    vanish from every client the day its owner was deleted (the tenant FK
 *    cascades). The REST API and the importer already treat NULL-tenant
 *    locations as available to everyone, so this matches them.
 *
 *  - A shared location's PARENT must be shared too (or it has none). Otherwise
 *    another company would see a child whose parent it cannot see, and the tree
 *    would draw an orphan.
 *
 *  - Only an analyst who can reach EVERY company may create, change or delete a
 *    shared location. Editing one changes what every client sees, and deleting
 *    one clears it from every client's assets.
 *
 * 🔑 Every guard checks the COLUMN first: `is_shared` does not exist until
 * Database Verification has run, and the asset screen must keep working on an
 * install that has pulled the update but not run it yet.
 */

require_once __DIR__ . '/tenancy.php';

/**
 * Who may read or change the locations list (GH #311).
 *
 * Locations live in two places since #311: System -> Locations, for administrators,
 * and Assets -> Settings -> Locations, for the asset managers who have always kept
 * them. Both use these same endpoints, so the gate has to let either in:
 *
 *  - an ADMINISTRATOR always - including when the Assets module is switched off,
 *    because the list now serves forms (and later people and tickets) as well;
 *  - anyone else needs Assets, and to change the list, the assets.locations
 *    permission (exactly the rule before #311).
 *
 * Exits with the usual JSON refusal; returns only when access is allowed.
 *
 * @param bool $manage  true for create / change / delete, false for reading the list
 */
function locationsRequireAccessJson(bool $manage): void
{
    if (!isset($_SESSION['analyst_id'])) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Not authenticated']);
        exit;
    }
    try {
        if (analystIsAdmin(connectToDatabase(), (int)$_SESSION['analyst_id'])) return;
    } catch (Throwable $e) {
        // fall through to the ordinary checks, which fail closed
    }
    requireModuleAccessJson('assets');
    if ($manage) {
        require_once __DIR__ . '/rbac.php';
        requireCapabilityJson(Cap::ASSETS_LOCATIONS);   // settings write - see docs/design/rbac.md
    }
}

/** Has Database Verification added asset_locations.is_shared yet? */
function assetLocationsSharedReady(PDO $conn): bool {
    return tenancyColumnExists($conn, 'asset_locations', 'is_shared');
}

/**
 * The locations one company may see: its own plus every shared one.
 *
 * @param string $alias the asset_locations alias ('' for none)
 * @return array [sqlFragment, params] to append to a WHERE
 */
function assetLocationScope(PDO $conn, int $tenantId, string $alias = 'l'): array {
    $qualified = $alias === '' ? 'tenant_id' : "$alias.tenant_id";
    [$sql, $args] = tenantScopeSqlFor($conn, $tenantId, $qualified);
    if ($sql === '' || !assetLocationsSharedReady($conn)) {
        return [$sql, $args];
    }
    $shared = $alias === '' ? 'is_shared' : "$alias.is_shared";
    // Strip the leading " AND " so the company clause and the shared clause can
    // be OR-ed inside one bracket. Precedence is the whole guard here.
    return [" AND ((" . substr($sql, 5) . ") OR $shared = 1)", $args];
}

/**
 * Is this location shared? False for a missing row or an un-verified install.
 *
 * @param int $locationId  the asset_locations id to check
 */
function assetLocationIsShared(PDO $conn, int $locationId): bool {
    if (!assetLocationsSharedReady($conn)) {
        return false;
    }
    $s = $conn->prepare("SELECT is_shared FROM asset_locations WHERE id = ?");
    $s->execute([$locationId]);
    return (int)$s->fetchColumn() === 1;
}
