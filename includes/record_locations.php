<?php
/**
 * A location on people, tickets, contracts and projects (GH #311).
 *
 * One rule for all four, the way includes/cost_centre_assign.php does it for
 * cost centres:
 *   - the location is a row of System -> Locations (asset_locations): the
 *     record's OWN company's, or a shared one (assetLocationScope());
 *   - it is checked again when the record moves company, and dropped if the new
 *     company cannot use it;
 *   - deleting a location that any of them points at is REFUSED
 *     (api/assets/delete_asset_location.php), with a pointer to
 *     System -> Maintenance -> Locations in use, which moves them all at once.
 *     The ON DELETE SET NULL foreign keys are only a backstop.
 *
 * Tickets also take the requester's location when they are created, if
 * Tickets -> Settings -> General says so (on by default) -
 * ticketApplyRequesterLocation(), called by every route that makes a ticket.
 *
 * Design and Ed's decisions: wiki Locations-On-Records-Design.
 *
 * @wiki Locations-Developer-Guide
 */

require_once __DIR__ . '/tenancy.php';
require_once __DIR__ . '/asset_locations.php';

/** The tables that carry a location_id, with the word used for them in counts. */
const RECORD_LOCATION_TABLES = [
    'users'     => 'people',
    'tickets'   => 'tickets',
    'contracts' => 'contracts',
    'projects'  => 'projects',
];

/**
 * Has Database Verification added location_id to this table yet?
 *
 * @param string $table  one of RECORD_LOCATION_TABLES' keys
 */
function recordLocationReady(PDO $conn, string $table): bool
{
    return isset(RECORD_LOCATION_TABLES[$table]) && tenancyColumnExists($conn, $table, 'location_id');
}

/**
 * The location id to store for a record in this company, or null for none.
 *
 * TRAP: a location from another company reads exactly like one that does not
 * exist - the same message - so the answer says nothing about other companies.
 *
 * @param ?int $tenantId  the RECORD's company (null = the Default company)
 * @param mixed $value  what was sent: an id, '' / null / 0 for none
 * @throws InvalidArgumentException  when the location is unknown or not this company's
 */
function recordLocationCheck(PDO $conn, ?int $tenantId, $value): ?int
{
    if ($value === null || $value === '' || $value === false || (is_numeric($value) && (int)$value === 0)) {
        return null;
    }
    if (!is_numeric($value) || (int)$value < 1) {
        throw new InvalidArgumentException('Unknown location.');
    }
    $id = (int)$value;
    $tid = $tenantId ?? getDefaultTenantId($conn);
    [$scope, $args] = assetLocationScope($conn, $tid, 'l');
    $st = $conn->prepare("SELECT 1 FROM asset_locations l WHERE l.id = ? $scope");
    $st->execute(array_merge([$id], $args));
    if (!$st->fetchColumn()) {
        throw new InvalidArgumentException('Unknown location.');
    }
    return $id;
}

/**
 * Is this stored location still usable by a record in this company? For a
 * record that moves company: false means drop it, as cost centres are dropped.
 *
 * @param ?int $tenantId  the record's NEW company
 */
function recordLocationStillValid(PDO $conn, ?int $tenantId, ?int $locationId): bool
{
    if (!$locationId) return true;
    try {
        recordLocationCheck($conn, $tenantId, $locationId);
        return true;
    } catch (InvalidArgumentException $e) {
        return false;
    }
}

/**
 * "UK > London > Floor 2" for a location, or null when it does not exist.
 * One read of the whole tree per request.
 */
function recordLocationPath(PDO $conn, ?int $id): ?string
{
    static $all = null;
    if (!$id) return null;
    if ($all === null) {
        $all = [];
        foreach ($conn->query("SELECT id, name, parent_id FROM asset_locations")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $all[(int)$r['id']] = $r;
        }
    }
    $parts = []; $seen = []; $cur = $id;
    while ($cur && isset($all[$cur]) && !isset($seen[$cur])) {
        $seen[$cur] = true;
        array_unshift($parts, (string)$all[$cur]['name']);
        $cur = (int)$all[$cur]['parent_id'];
    }
    return $parts ? implode(' > ', $parts) : null;
}

/**
 * The REST API's shape for a location: {id, name, path}, or null.
 */
function recordLocationRef(PDO $conn, ?int $id): ?array
{
    if (!$id) return null;
    $path = recordLocationPath($conn, $id);
    if ($path === null) return null;
    $parts = explode(' > ', $path);
    return ['id' => $id, 'name' => end($parts), 'path' => $path];
}

/**
 * recordLocationRef() for a REST serializer that is not handed a connection
 * (they are also called by name from array_map). Connects once, and only when
 * a record actually has a location.
 */
function recordLocationRefFor(?int $id): ?array
{
    static $conn = null;
    if (!$id) return null;
    $conn ??= connectToDatabase();
    return recordLocationRef($conn, $id);
}

/**
 * The locations a record in this company may have, for a dropdown: its own and
 * the shared ones, each with its full path, in path order.
 *
 * @param ?int $tenantId  the record's company (null = the Default company)
 * @return array<int,array{id:int,path:string,shared:bool}>
 */
function recordLocationOptions(PDO $conn, ?int $tenantId): array
{
    $tid = $tenantId ?? getDefaultTenantId($conn);
    [$scope, $args] = assetLocationScope($conn, $tid, 'l');
    $sharedCol = assetLocationsSharedReady($conn) ? 'l.is_shared' : '0';
    $st = $conn->prepare("SELECT l.id, $sharedCol AS is_shared FROM asset_locations l WHERE 1=1 $scope");
    $st->execute($args);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $path = recordLocationPath($conn, (int)$r['id']);
        if ($path !== null) $out[] = ['id' => (int)$r['id'], 'path' => $path, 'shared' => (int)$r['is_shared'] === 1];
    }
    usort($out, fn($a, $b) => strnatcasecmp($a['path'], $b['path']));
    return $out;
}

/**
 * How many records point at a location, by kind - what blocks deleting it.
 * Binned tickets count: restoring one would bring the location back.
 *
 * @return array<string,int>  people / tickets / contracts / projects (only tables that have the column)
 */
function recordLocationUsage(PDO $conn, int $locationId): array
{
    $out = [];
    foreach (RECORD_LOCATION_TABLES as $table => $kind) {
        if (!recordLocationReady($conn, $table)) continue;
        $st = $conn->prepare("SELECT COUNT(*) FROM `$table` WHERE location_id = ?");
        $st->execute([$locationId]);
        $out[$kind] = (int)$st->fetchColumn();
    }
    return $out;
}

/**
 * Should a new ticket take its requester's location? Tickets -> Settings ->
 * General; never saved means ON (Ed, 15 November 2026).
 */
function ticketLocationFromRequesterOn(PDO $conn): bool
{
    try {
        $st = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'ticket_location_from_requester'");
        $st->execute();
        $v = $st->fetchColumn();
        return $v === false || $v === null || $v === '' || $v === '1';
    } catch (Throwable $e) {
        return true;
    }
}

/**
 * Give a new ticket its requester's location - when the setting is on, the
 * ticket has none yet, and the requester's location is one the ticket's
 * company may use. Never throws: a ticket is never refused over a location.
 *
 * Called once a ticket exists, by every route that makes one (the service,
 * email, portal, chat, catalogue, workflows...). Later moves of the requester
 * do not change old tickets.
 *
 * @param int $ticketId  the ticket just created
 */
function ticketApplyRequesterLocation(PDO $conn, int $ticketId): void
{
    try {
        if ($ticketId < 1 || !recordLocationReady($conn, 'tickets') || !recordLocationReady($conn, 'users')
            || !ticketLocationFromRequesterOn($conn)) {
            return;
        }
        $st = $conn->prepare(
            "SELECT t.tenant_id, t.location_id, u.location_id AS requester_location
               FROM tickets t LEFT JOIN users u ON u.id = t.user_id
              WHERE t.id = ?"
        );
        $st->execute([$ticketId]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r || $r['location_id'] !== null || !$r['requester_location']) return;
        $tenant = $r['tenant_id'] !== null ? (int)$r['tenant_id'] : null;
        $loc = (int)$r['requester_location'];
        if (!recordLocationStillValid($conn, $tenant, $loc)) return;
        $conn->prepare("UPDATE tickets SET location_id = ? WHERE id = ? AND location_id IS NULL")->execute([$loc, $ticketId]);
    } catch (Throwable $e) {
        error_log('ticketApplyRequesterLocation: ' . $e->getMessage());
    }
}

/**
 * Why this analyst may NOT manage (delete, or move everything off) a location,
 * or null when they may. The one rule for api/assets/delete_asset_location.php
 * and System -> Maintenance -> Locations in use:
 *   - a shared location affects every company, so only someone who can reach
 *     every company may;
 *   - otherwise the location's own company must be the one being worked in
 *     (the Default company owns the ones with no company).
 * The caller has already checked the permission to manage locations at all
 * (locationsRequireAccessJson(true)).
 *
 * @param int $locationId  the location (asset_locations.id)
 */
function recordLocationManageError(PDO $conn, int $analystId, int $locationId): ?string
{
    $cur = $conn->prepare("SELECT tenant_id FROM asset_locations WHERE id = ?");
    $cur->execute([$locationId]);
    $row = $cur->fetch(PDO::FETCH_ASSOC);
    if (!$row) return 'Location not found';
    $multi = isMultiTenant($conn);
    if (!$multi) return null;
    $owner = $row['tenant_id'] === null ? null : (int)$row['tenant_id'];
    if (assetLocationIsShared($conn, $locationId)) {
        return analystHasAllTenantAccess($conn, $analystId)
            ? null
            : 'That location is shared by every company. Only someone with access to every company can change what uses it.';
    }
    $activeId = getActiveTenantId($conn, $analystId);
    $isDefaultCtx = $activeId === getDefaultTenantId($conn);
    if ($isDefaultCtx) {
        return $owner !== null ? "That's a company's own location — switch to that company to manage it." : null;
    }
    if ($owner === null) return 'That location belongs to the Default company — switch to it to manage that location.';
    if ($owner !== $activeId) return 'That location belongs to another company.';
    return null;
}

/**
 * The records that point at a location, a page of each kind, for the
 * Locations in use tool: kind => [['id', 'label', 'url'], ...]. Paths are
 * relative to the app root.
 *
 * @param int $limit  the most of each kind to list (the counts come from recordLocationUsage())
 */
function recordLocationRecords(PDO $conn, int $locationId, int $limit = 25): array
{
    $limit = max(1, min($limit, 200));
    $q = [
        'people'    => ["SELECT id, COALESCE(NULLIF(display_name, ''), NULLIF(email, ''), username) AS label FROM users WHERE location_id = ? ORDER BY label LIMIT $limit", 'people/person.php?id='],
        'tickets'   => ["SELECT id, CONCAT(COALESCE(ticket_number, CONCAT('#', id)), ' ', COALESCE(subject, '')) AS label FROM tickets WHERE location_id = ? ORDER BY id DESC LIMIT $limit", 'tickets/?ticket_id='],
        'contracts' => ["SELECT id, CONCAT(COALESCE(contract_number, ''), ' ', COALESCE(title, '')) AS label FROM contracts WHERE location_id = ? ORDER BY id DESC LIMIT $limit", 'contracts/view.php?id='],
        'projects'  => ["SELECT id, name AS label FROM projects WHERE location_id = ? ORDER BY name LIMIT $limit", 'projects/view.php?id='],
    ];
    $out = [];
    foreach (RECORD_LOCATION_TABLES as $table => $kind) {
        if (!recordLocationReady($conn, $table)) continue;
        [$sql, $url] = $q[$kind];
        $st = $conn->prepare($sql);
        $st->execute([$locationId]);
        $out[$kind] = array_map(fn($r) => ['id' => (int)$r['id'], 'label' => trim((string)$r['label']), 'url' => $url . (int)$r['id']],
                                $st->fetchAll(PDO::FETCH_ASSOC));
    }
    return $out;
}

/**
 * Move everything that points at one location to another, or clear it
 * ($toId null). Each record is checked against its OWN company: a record the
 * new location is not open to is left as it was and counted as skipped.
 * Tickets and projects record the change in their history; people and
 * contracts keep none.
 *
 * @param int $actorId  the analyst doing it (for the history)
 * @param ?int $toId  the location to move to, or null to clear
 * @return array{moved: array<string,int>, skipped: array<string,int>}
 */
function recordLocationMove(PDO $conn, int $actorId, int $fromId, ?int $toId): array
{
    $moved = []; $skipped = [];
    $fromPath = recordLocationPath($conn, $fromId);
    $toPath = $toId ? recordLocationPath($conn, $toId) : null;
    $conn->beginTransaction();
    try {
        foreach (RECORD_LOCATION_TABLES as $table => $kind) {
            $moved[$kind] = 0; $skipped[$kind] = 0;
            if (!recordLocationReady($conn, $table)) continue;
            $st = $conn->prepare("SELECT id, tenant_id FROM `$table` WHERE location_id = ?");
            $st->execute([$fromId]);
            $upd = $conn->prepare("UPDATE `$table` SET location_id = ? WHERE id = ? AND location_id = ?");
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $tenant = $r['tenant_id'] !== null ? (int)$r['tenant_id'] : null;
                if ($toId && !recordLocationStillValid($conn, $tenant, $toId)) { $skipped[$kind]++; continue; }
                $upd->execute([$toId, (int)$r['id'], $fromId]);
                $moved[$kind]++;
                if ($table === 'tickets') {
                    $conn->prepare("INSERT INTO ticket_audit (ticket_id, analyst_id, field_name, old_value, new_value, created_datetime)
                                    VALUES (?, ?, 'Location', ?, ?, UTC_TIMESTAMP())")
                         ->execute([(int)$r['id'], $actorId > 0 ? $actorId : null, $fromPath, $toPath]);
                } elseif ($table === 'projects') {
                    try {
                        $conn->prepare("INSERT INTO project_audit (project_id, analyst_id, field_name, old_value, new_value, created_datetime)
                                        VALUES (?, ?, 'location_id', ?, ?, UTC_TIMESTAMP())")
                             ->execute([(int)$r['id'], $actorId > 0 ? $actorId : null, $fromPath, $toPath]);
                    } catch (Throwable $e) { /* history is best effort; the move stands */ }
                }
            }
        }
        $conn->commit();
    } catch (Throwable $e) {
        if ($conn->inTransaction()) $conn->rollBack();
        throw $e;
    }
    return ['moved' => $moved, 'skipped' => $skipped];
}

