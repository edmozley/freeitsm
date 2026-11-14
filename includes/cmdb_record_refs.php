<?php
/**
 * CMDB properties that point at real FreeITSM records (GH #312).
 *
 * A CMDB property used to reference only another CI (`object_ref`), so an
 * owner was a "Person" CI kept by hand beside the real person. These seven
 * property types point at the record itself - its live name, a link to it:
 *
 *   person    -> users            (company: the CI's)
 *   analyst   -> analysts         (install-wide)
 *   team      -> teams            (install-wide)
 *   location  -> asset_locations  (company: the CI's, or a shared location)
 *   company   -> tenants          (any company the person editing can reach)
 *   supplier  -> suppliers        (install-wide registry)
 *   contract  -> contracts        (company: the CI's)
 *
 * THIS FILE IS THE ONE PLACE that knows those tables. The property type list,
 * the save check, the picker, the display, the REST API, the data-quality
 * audit and the delete summaries all ask it.
 *
 * Storage: `cmdb_object_properties.value_ref_id` - which table it names is the
 * property's type. 🔴 NEVER `value_object_id`: everything that reads that column
 * (impact, summaries, the network mapper, the audit, the FK) assumes it is a CI.
 *
 * A removed record leaves its id behind on purpose (Ed, 2026-11-14): deleting a
 * person a CI points at is allowed, the CI says "(removed)", CMDB -> Data
 * quality lists it, and the delete dialog says beforehand which CIs point at it
 * (cmdbRecordReferences()).
 *
 * @wiki CMDB-Record-References-Developer-Guide
 */

require_once __DIR__ . '/typed_fields.php';
require_once __DIR__ . '/tenancy.php';

/**
 * The record-reference property types.
 *
 * kind     the TypedFields ref kind registered below
 * table    the table the id points at
 * name     SQL for the record's name (alias r)
 * active   SQL that is 1 when the record is in use, or null when the table has no such idea
 * scope    'ci_company' | 'ci_company_or_shared' | 'reachable' | 'none'
 * tenant   the company column for the ci_company scopes (NULL = the Default company)
 * link     entityLink() type for a link to the record, or null for none
 * search   SQL columns the picker searches
 *
 * @return array<string, array>
 */
function cmdbRecordTypes(): array
{
    return [
        'person' => [
            'kind' => 'user', 'table' => 'users',
            'name' => "COALESCE(NULLIF(r.display_name, ''), r.email)",
            'active' => null, 'scope' => 'ci_company', 'tenant' => 'tenant_id',
            'link' => 'person', 'search' => ['r.display_name', 'r.email'],
        ],
        'analyst' => [
            'kind' => 'analyst', 'table' => 'analysts',
            'name' => 'r.full_name', 'active' => 'COALESCE(r.is_active, 1)',
            'scope' => 'none', 'tenant' => null, 'link' => null,
            'search' => ['r.full_name', 'r.username', 'r.email'],
        ],
        'team' => [
            'kind' => 'team', 'table' => 'teams',
            'name' => 'r.name', 'active' => 'COALESCE(r.is_active, 1)',
            'scope' => 'none', 'tenant' => null, 'link' => null, 'search' => ['r.name'],
        ],
        'location' => [
            'kind' => 'location', 'table' => 'asset_locations',
            'name' => 'r.name', 'active' => null,
            'scope' => 'ci_company_or_shared', 'tenant' => 'tenant_id', 'link' => null, 'search' => ['r.name'],
        ],
        'company' => [
            'kind' => 'tenant', 'table' => 'tenants',
            'name' => 'r.name', 'active' => 'r.is_active',
            'scope' => 'reachable', 'tenant' => null, 'link' => 'company', 'search' => ['r.name'],
        ],
        'supplier' => [
            'kind' => 'supplier', 'table' => 'suppliers',
            'name' => "COALESCE(NULLIF(r.trading_name, ''), r.legal_name)", 'active' => 'r.is_active',
            'scope' => 'none', 'tenant' => null, 'link' => 'supplier',
            'search' => ['r.trading_name', 'r.legal_name'],
        ],
        'contract' => [
            'kind' => 'contract', 'table' => 'contracts',
            'name' => 'r.title', 'active' => 'r.is_active',
            'scope' => 'ci_company', 'tenant' => 'tenant_id', 'link' => 'contract', 'search' => ['r.title'],
        ],
    ];
}

/** True when $type is one of the record-reference property types. */
function cmdbIsRecordType(string $type): bool
{
    return isset(cmdbRecordTypes()[$type]);
}

/**
 * SQL that keeps only the records a CI of company $ciTenant may point at, for alias r.
 *
 * @param string $type     a record type
 * @param int    $ciTenant the CI's company (resolved - never null)
 * @param ?array $reach    companies the person editing can reach; null = all
 * @return array{0:string,1:array} [sql starting " AND ...", params]
 */
function cmdbRecordScopeSql(PDO $conn, string $type, int $ciTenant, ?array $reach): array
{
    $t = cmdbRecordTypes()[$type];
    if (!isMultiTenant($conn)) {
        return ['', []];
    }
    $def = (int)getDefaultTenantId($conn);
    switch ($t['scope']) {
        case 'ci_company':
            // NULL means "the Default company's" for these tables, never "shared".
            return [" AND COALESCE(r.`{$t['tenant']}`, ?) = ?", [$def, $ciTenant]];
        case 'ci_company_or_shared':
            require_once __DIR__ . '/asset_locations.php';
            if (assetLocationsSharedReady($conn)) {
                return [" AND (r.is_shared = 1 OR COALESCE(r.`{$t['tenant']}`, ?) = ?)", [$def, $ciTenant]];
            }
            return [" AND COALESCE(r.`{$t['tenant']}`, ?) = ?", [$def, $ciTenant]];
        case 'reachable':
            if ($reach === null) {
                return ['', []];
            }
            if (!$reach) {
                return [' AND 1 = 0', []];
            }
            $reach = array_map('intval', $reach);
            return [' AND r.id IN (' . implode(',', array_fill(0, count($reach), '?')) . ')', $reach];
        default:
            return ['', []];
    }
}

/**
 * The picker: records of $type a CI of company $ciTenant may point at, matching $q.
 * Inactive records are not offered for a new value.
 *
 * @return array<int, array{id:int,name:string}>
 */
function cmdbRecordSearch(PDO $conn, string $type, string $q, int $ciTenant, ?array $reach, int $limit = 20): array
{
    if (!cmdbIsRecordType($type)) {
        return [];
    }
    $t = cmdbRecordTypes()[$type];
    [$scope, $args] = cmdbRecordScopeSql($conn, $type, $ciTenant, $reach);
    $where = '1=1' . $scope;
    if ($t['active'] !== null) {
        $where .= " AND {$t['active']} = 1";
    }
    $q = trim($q);
    if ($q !== '') {
        $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $q) . '%';
        $ors = [];
        foreach ($t['search'] as $col) { $ors[] = "$col LIKE ?"; $args[] = $like; }
        $where .= ' AND (' . implode(' OR ', $ors) . ')';
    }
    $limit = max(1, min(50, $limit));
    $stmt = $conn->prepare("SELECT r.id, {$t['name']} AS name FROM `{$t['table']}` r WHERE $where ORDER BY name LIMIT $limit");
    $stmt->execute($args);
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $name = (string)$r['name'];
        if ($type === 'location') {
            $name = cmdbLocationPath($conn, (int)$r['id']) ?? $name;
        }
        $out[] = ['id' => (int)$r['id'], 'name' => $name];
    }
    return $out;
}

/**
 * May a CI of company $ciTenant point at record $id of $type? Used by the save,
 * so a crafted request cannot bind a CI to another company's person or contract.
 * An inactive record is allowed only when it is the value already stored
 * (re-saving a CI must not fail because its supplier was retired).
 */
function cmdbRecordAllowed(PDO $conn, string $type, int $id, int $ciTenant, ?array $reach, bool $alreadyStored = false): bool
{
    if (!cmdbIsRecordType($type) || $id <= 0) {
        return false;
    }
    $t = cmdbRecordTypes()[$type];
    [$scope, $args] = cmdbRecordScopeSql($conn, $type, $ciTenant, $reach);
    $sql = "SELECT 1 FROM `{$t['table']}` r WHERE r.id = ?" . $scope;
    if ($t['active'] !== null && !$alreadyStored) {
        $sql .= " AND {$t['active']} = 1";
    }
    $stmt = $conn->prepare($sql . ' LIMIT 1');
    $stmt->execute(array_merge([$id], $args));
    return (bool)$stmt->fetchColumn();
}

/** "UK > London > Floor 2" for a location, or null when it does not exist. */
function cmdbLocationPath(PDO $conn, int $id): ?string
{
    static $all = null;
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
 * What to show for stored values: [id => ['name' => ?string, 'state' => ..., 'url' => ?string]].
 *
 * state: 'ok' | 'inactive' | 'removed' (no such row any more) |
 *        'hidden' (a company the viewer cannot reach - the name is not shown).
 *
 * @param ?array $reach  companies the VIEWER can reach; null = all
 */
function cmdbRecordResolve(PDO $conn, string $type, array $ids, ?array $reach): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (!$ids || !cmdbIsRecordType($type)) {
        return [];
    }
    $t = cmdbRecordTypes()[$type];
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $active = $t['active'] ?? '1';
    $tenantSel = $t['tenant'] ? ", r.`{$t['tenant']}` AS tenant" : ($type === 'company' ? ', r.id AS tenant' : ', NULL AS tenant');
    $stmt = $conn->prepare("SELECT r.id, {$t['name']} AS name, {$active} AS active{$tenantSel} FROM `{$t['table']}` r WHERE r.id IN ($ph)");
    $stmt->execute($ids);
    $rows = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $rows[(int)$r['id']] = $r;
    }
    if ($t['link'] !== null) {
        require_once __DIR__ . '/entity_links.php';
    }
    $def = isMultiTenant($conn) ? (int)getDefaultTenantId($conn) : 0;
    $out = [];
    foreach ($ids as $id) {
        if (!isset($rows[$id])) {
            $out[$id] = ['name' => null, 'state' => 'removed', 'url' => null];
            continue;
        }
        $r = $rows[$id];
        // A record in a company the viewer cannot reach: say so, never its name.
        // Only the 'company' type can hold one (the others are tied to the CI's
        // own company, which the viewer reached to open the CI), but a moved CI
        // or a shared location must not leak either, so check every scoped type.
        if ($reach !== null && $def && ($t['tenant'] || $type === 'company')) {
            $tid = $r['tenant'] === null ? $def : (int)$r['tenant'];
            $shared = ($type === 'location') && cmdbLocationIsShared($conn, $id);
            if (!$shared && !in_array($tid, array_map('intval', $reach), true)) {
                $out[$id] = ['name' => null, 'state' => 'hidden', 'url' => null];
                continue;
            }
        }
        $name = (string)$r['name'];
        if ($type === 'location') {
            $name = cmdbLocationPath($conn, $id) ?? $name;
        }
        $out[$id] = [
            'name'  => $name,
            'state' => ((int)$r['active'] === 1) ? 'ok' : 'inactive',
            'url'   => $t['link'] !== null ? entityLink($t['link'], $id) : null,
        ];
    }
    return $out;
}

/** Is location $id shared by every company? (False before Database Verification.) */
function cmdbLocationIsShared(PDO $conn, int $id): bool
{
    require_once __DIR__ . '/asset_locations.php';
    return assetLocationsSharedReady($conn) && assetLocationIsShared($conn, $id);
}

/**
 * The CIs whose properties point at record $id of $type - for the summary in a
 * delete dialog ("3 CIs point at this person ... they will show (removed)").
 *
 * Names are returned only for CIs the person asking can reach; the rest are
 * counted, never named (`hidden`).
 *
 * @param ?array $reach  companies the person asking can reach; null = all
 * @return array{count:int, hidden:int, items:array<int, array{id:int,name:string,property:string}>}
 */
function cmdbRecordReferences(PDO $conn, string $type, int $id, ?array $reach, int $limit = 10): array
{
    $none = ['count' => 0, 'hidden' => 0, 'items' => []];
    if (!cmdbIsRecordType($type) || $id <= 0) {
        return $none;
    }
    try {
        $stmt = $conn->prepare(
            "SELECT o.id, o.name, o.tenant_id, p.label
               FROM cmdb_object_properties v
               JOIN cmdb_class_properties p ON p.id = v.property_id AND p.property_type = ?
               JOIN cmdb_objects o ON o.id = v.object_id
              WHERE v.value_ref_id = ?
              ORDER BY o.name");
        $stmt->execute([$type, $id]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return $none;   // before Database Verification: no value_ref_id, so nothing can point here
    }
    $def = (int)getDefaultTenantId($conn);
    $out = $none;
    foreach ($rows as $r) {
        $out['count']++;
        $tid = $r['tenant_id'] === null ? $def : (int)$r['tenant_id'];
        if ($reach !== null && !in_array($tid, array_map('intval', $reach), true)) {
            $out['hidden']++;
            continue;
        }
        if (count($out['items']) < $limit) {
            $out['items'][] = ['id' => (int)$r['id'], 'name' => (string)$r['name'], 'property' => (string)$r['label']];
        }
    }
    return $out;
}

// --------------------------------------------------------------------------
// TypedFields ref kinds: "is this a real row of the right kind?" and nothing
// more. Scope is CmdbService's job (cmdbRecordAllowed). 'user' is registered
// by typed_fields.php already.
// --------------------------------------------------------------------------
foreach (cmdbRecordTypes() as $type => $t) {
    if (TypedFields::refKind($t['kind']) !== null) {
        continue;
    }
    $table = $t['table'];
    $name  = $t['name'];
    TypedFields::registerRefKind($t['kind'], [
        'label'        => $type,
        'target_label' => '',
        'exists'       => function (PDO $conn, int $id) use ($table) {
            $stmt = $conn->prepare("SELECT 1 FROM `{$table}` WHERE id = ?");
            $stmt->execute([$id]);
            return $stmt->fetchColumn() === false ? false : 0;
        },
        'labels'       => function (PDO $conn, array $ids) use ($table, $name) {
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $conn->prepare("SELECT r.id, {$name} FROM `{$table}` r WHERE r.id IN ({$ph})");
            $stmt->execute($ids);
            return $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        },
    ]);
}

/**
 * Has Database Verification added cmdb_object_properties.value_ref_id yet?
 * Cached per request. Before it has, record properties cannot be saved and the
 * CMDB reads and saves every other property exactly as before.
 */
function cmdbRecordRefsReady(?PDO $conn = null): bool
{
    static $ready = null;
    if ($ready === null) {
        try {
            $conn = $conn ?? connectToDatabase();
            $st = $conn->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cmdb_object_properties'
                                   AND COLUMN_NAME = 'value_ref_id'");
            $ready = (int)$st->fetchColumn() > 0;
        } catch (Throwable $e) {
            $ready = false;
        }
    }
    return $ready;
}
