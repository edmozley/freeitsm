<?php
/**
 * POST /assets/{id}/inventory (#194). 200 when every section applied, was replayed or
 * was stale; 207 when some failed and others did not (each failed one names why, and
 * sending the same report again retries only those); 422 when every section failed.
 */
function apiAssetInventorySubmit(PDO $conn,array $apiKey,array $params,array $body): void {
    // GH #200 part D: a tool still reporting to an asset that was merged into another
    // reaches the kept one (the response's asset_id says which).
    require_once dirname(__DIR__,3) . '/includes/services/asset_merge.php';
    $params[0] = AssetMergeService::current($conn, (int)$params[0]) ?? (int)$params[0];
    apiLoadAsset($conn,$apiKey,$params[0]);
    require_once dirname(__DIR__,3) . '/includes/services/asset_inventory.php';
    try { $result=AssetInventoryService::submit($conn,ActorContext::fromApiKey($apiKey),$params[0],$body); }
    catch (ServiceError $e) { apiFailFromService($e); }
    $failed = count(array_filter($result['sections'], fn($s) => $s['result'] === 'failed'));
    if ($failed && $failed === count($result['sections'])) {
        apiError(422, 'all_sections_failed', 'No section of the report could be applied.', $result['sections']);
    }
    apiRespond($result, $failed ? 207 : 200);
}
function apiAssetInventoryUnlink(PDO $conn,array $apiKey,array $params,array $body): void {
    apiLoadAsset($conn,$apiKey,$params[0]);
    require_once dirname(__DIR__,3) . '/includes/services/asset_inventory.php';
    try { $result=AssetInventoryService::unlink($conn,ActorContext::fromApiKey($apiKey),(int)$params[0],$params[1]); }
    catch (ServiceError $e) { apiFailFromService($e); }
    apiRespond($result);
}
/** GET /assets/{id}/connections (GH #200 part C): what is physically connected, from either end. */
function apiAssetConnectionsList(PDO $conn,array $apiKey,array $params,array $body): void {
    apiLoadAsset($conn,$apiKey,$params[0]);
    require_once dirname(__DIR__,3) . '/includes/services/asset_connections.php';
    try { $result=AssetConnectionsService::listFor($conn,ActorContext::fromApiKey($apiKey),(int)$params[0]); }
    catch (ServiceError $e) { apiFailFromService($e); }
    apiRespond($result);
}
function apiAssetInventoryProvenance(PDO $conn,array $apiKey,array $params,array $body): void {
    apiLoadAsset($conn,$apiKey,$params[0]);
    require_once dirname(__DIR__,3) . '/includes/services/asset_inventory.php';
    try { $result=AssetInventoryService::provenance($conn,ActorContext::fromApiKey($apiKey),$params[0]); }
    catch (ServiceError $e) { apiFailFromService($e); }
    apiRespond($result);
}

/**
 * FreeITSM REST API v1 — assets resource.
 *
 * Mirrors the Assets module's behaviour so an asset touched via the API is
 * indistinguishable from one touched in the UI or by the inventory agent:
 *   - field updates write asset_history rows with the SAME stable field keys
 *     the UI uses (update_asset_field.php), and display NAMES for lookups
 *   - assignment/unassignment mirror assign_asset_user.php /
 *     unassign_asset_user.php (users_assets + custody log + audit)
 *   - a warranty_expiry change re-syncs the calendar's warranty events
 *
 * Notes on scope and identity:
 *   - Assets ARE company-scoped (assets.tenant_id, Phase 3): the key's company
 *     scope restricts every list + by-id read/write (apiKeyTenantFilter on the
 *     list, apiKeyCanAccessTenantRow via apiLoadAsset). An asset outside scope is
 *     a 404; NULL tenant_id normalises to the Default company. Invisible at N=1.
 *   - There is deliberately NO delete endpoint: nothing in the product
 *     deletes assets (they are agent-maintained records).
 *   - hostname is the de-facto identity every ingest path upserts on, and is now
 *     unique PER COMPANY, so a duplicate hostname within a company is a 409.
 *
 * The WRITES (create / field update / assign / unassign) are delegated to
 * AssetsService — the same rules the UI's api/assets/*.php endpoints now call.
 * The reads, serialisers, list filters, and reference lookups below are API-only
 * and stay here.
 */

require_once dirname(__DIR__, 3) . '/includes/service_context.php';
require_once dirname(__DIR__, 3) . '/includes/services/assets.php';
require_once dirname(__DIR__, 3) . '/includes/services/asset_ci_links.php';   // GH #201
require_once dirname(__DIR__, 3) . '/includes/timezone.php';   // naive_today_sql() — warranty_expiry is a bare date (GH #126)

// ---------------------------------------------------------------------------
// Shared helpers
// ---------------------------------------------------------------------------

/**
 * The SELECT for assets, with type, status, location, company and supplier names, how
 * many people it is assigned to and, once Database Verification has added the column,
 * its cost centre.
 */
function apiAssetSelect(): string {
    // The cost centre it is charged to (GH #160) - joined only once Database
    // Verification has added the column, so an older install's reads still work.
    static $cc = null;
    if ($cc === null) {
        require_once dirname(__DIR__, 3) . '/includes/cost_centre_assign.php';
        $cc = costCentreReady(connectToDatabase(), 'assets')
            ? [", ccx.code AS cost_centre_code, ccx.name AS cost_centre_name", " LEFT JOIN cost_centres ccx ON ccx.id = a.cost_centre_id"]
            : [", NULL AS cost_centre_code, NULL AS cost_centre_name", ''];
    }
    return "SELECT a.*{$cc[0]},
                   at.name  AS type_name,
                   ast.name AS status_name,
                   al.name  AS location_name,
                   tn.name  AS company_name,
                   COALESCE(NULLIF(TRIM(s.trading_name), ''), s.legal_name) AS supplier_name,
                   (SELECT COUNT(*) FROM users_assets ua WHERE ua.asset_id = a.id) AS assigned_count
            FROM assets a
            LEFT JOIN asset_types        at  ON at.id  = a.asset_type_id
            LEFT JOIN asset_status_types ast ON ast.id = a.asset_status_id
            LEFT JOIN asset_locations    al  ON al.id  = a.location_id
            LEFT JOIN tenants            tn  ON tn.id  = a.tenant_id
            LEFT JOIN suppliers          s   ON s.id   = a.supplier_id{$cc[1]}";
}

/** All locations keyed by id — used to build full "UK › London › Office 1" paths. */
function apiAssetLocations(PDO $conn): array {
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        foreach ($conn->query("SELECT id, name, parent_id FROM asset_locations") as $row) {
            $cache[(int)$row['id']] = ['name' => $row['name'], 'parent_id' => $row['parent_id'] !== null ? (int)$row['parent_id'] : null];
        }
    }
    return $cache;
}

/**
 * The full path of a location, top level first, or null when there is no location.
 *
 * @param ?int $locationId  the asset_locations id; null gives null
 */
function apiAssetLocationPath(PDO $conn, ?int $locationId): ?string {
    if ($locationId === null) {
        return null;
    }
    $locations = apiAssetLocations($conn);
    $parts = [];
    $id = $locationId;
    $guard = 0;
    while ($id !== null && isset($locations[$id]) && $guard++ < 20) {
        array_unshift($parts, $locations[$id]['name']);
        $id = $locations[$id]['parent_id'];
    }
    return $parts ? implode(' › ', $parts) : null;
}

/**
 * Shapes an asset row for the API - identity, company, type, status, location with its
 * path, hardware, operating system and the rest of the record.
 *
 * @param array $r  the asset row as apiAssetSelect() loads it, with its type, status, location, supplier and company names
 */
function apiSerializeAsset(PDO $conn, array $r): array {
    $rel = function ($id, $name) {
        return $id === null ? null : ['id' => (int)$id, 'name' => $name];
    };
    $locationId = $r['location_id'] !== null ? (int)$r['location_id'] : null;
    return [
        'id'       => (int)$r['id'],
        'hostname' => $r['hostname'],
        'company'  => $rel($r['tenant_id'] ?? null, $r['company_name'] ?? null),
        'type'     => $rel($r['asset_type_id'], $r['type_name']),
        'status'   => $rel($r['asset_status_id'], $r['status_name']),
        'location' => $locationId === null ? null : [
            'id'   => $locationId,
            'name' => $r['location_name'],
            'path' => apiAssetLocationPath($conn, $locationId),
        ],
        'hardware' => [
            'manufacturer'     => $r['manufacturer'],
            'model'            => $r['model'],
            'service_tag'      => $r['service_tag'],
            'memory'           => $r['memory'] !== null ? (int)$r['memory'] : null,
            'cpu_name'         => $r['cpu_name'],
            'speed'            => $r['speed'] !== null ? (int)$r['speed'] : null,
            'gpu_name'         => $r['gpu_name'],
            'bios_version'     => $r['bios_version'],
            'tpm_version'      => $r['tpm_version'],
            'bitlocker_status' => $r['bitlocker_status'],
        ],
        'os' => [
            'operating_system' => $r['operating_system'],
            'feature_release'  => $r['feature_release'],
            'build_number'     => $r['build_number'],
        ],
        'network' => [
            'domain'         => $r['domain'],
            'logged_in_user' => $r['logged_in_user'],
        ],
        'lifecycle' => [
            'purchase_date'   => $r['purchase_date'],
            'purchase_cost'   => $r['purchase_cost'] !== null ? (float)$r['purchase_cost'] : null,
            'supplier'        => $rel($r['supplier_id'], $r['supplier_name']),
            'cost_centre'     => !empty($r['cost_centre_id'])
                ? ['id' => (int)$r['cost_centre_id'], 'code' => $r['cost_centre_code'] ?? null, 'name' => $r['cost_centre_name'] ?? null]
                : null,
            'order_number'    => $r['order_number'],
            'warranty_expiry' => $r['warranty_expiry'],
            'lease_start'     => $r['lease_start'] ?? null,    // 3.5.0
            'lease_expiry'    => $r['lease_expiry'] ?? null,
        ],
        'assigned_users_count' => (int)($r['assigned_count'] ?? 0),
        'first_seen'   => apiIsoDate($r['first_seen']),
        'last_seen'    => apiIsoDate($r['last_seen']),
        'last_boot_at' => apiIsoDate($r['last_boot_utc']),
        // Retired assets have left the estate (a status ticked Retired): when, and why.
        'retired_at'     => apiIsoDate($r['retired_datetime'] ?? null),
        'retired_reason' => $r['retired_reason'] ?? null,
        // Custom fields (docs/design/flexible-asset-fields.md §8).
        //
        // ⚠️ ADDITIVE ONLY. Everything above is a published contract, so this
        // arrives as a new object and nothing existing moves or changes shape.
        //
        // 🔑 Keyed by field_key, and a field the asset does not carry is simply
        // ABSENT — never null, never a default. A consumer must be able to tell
        // "not recorded" from "recorded as no", which is the same rule the UI
        // follows. An asset with no custom fields returns {}.
        // ⚠️ Cast HERE, not inside the function: an empty PHP array encodes as
        // `[]`, and `"fields": []` is a different shape from `"fields": {}` to
        // every strongly-typed client. (The cast cannot live in the function —
        // it is declared `: array`, and returning stdClass from it is a fatal.)
        'fields' => (object)apiAssetCustomFields($conn, (int)$r['id'],
                                         $r['asset_type_id'] !== null ? (int)$r['asset_type_id'] : null),
    ];
}

/**
 * Custom field values for one asset, as { field_key: value }.
 *
 * ⚠️ Per-asset, because the serializer is per-asset — so a LIST endpoint pays
 * one query per row. Acceptable at the page sizes this API returns, and the
 * batched reader (AssetFieldsService::readForAssets) is there for when it is
 * not. Flagged rather than hidden: if list latency ever matters, this is the
 * line to change.
 *
 * @param ?int $assetTypeId  the asset's type, which decides the custom fields it carries; null for no type
 */
function apiAssetCustomFields(PDO $conn, int $assetId, ?int $assetTypeId): array {
    static $ready = null;
    if ($ready === null) {
        require_once dirname(__DIR__, 3) . '/includes/services/asset_fields.php';
        $ready = AssetFieldsService::schemaReady($conn);
    }
    if (!$ready) {
        return [];
    }
    $defs = AssetFieldsService::fieldsForAsset($conn, $assetId, $assetTypeId);
    if (!$defs) {
        return [];
    }
    return AssetFieldsService::valuesForAsset($conn, $assetId, $defs);
}

/**
 * Load one asset (with joins); 404 if unknown OR outside the key's companies
 * (multi-tenancy — the key's company scope gates every by-id read/write; a NULL
 * tenant_id normalises to the Default company; no-op at N=1 / all-access key).
 */
function apiLoadAsset(PDO $conn, array $apiKey, int $assetId): array {
    $stmt = $conn->prepare(apiAssetSelect() . " WHERE a.id = ?");
    $stmt->execute([$assetId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row || !apiKeyCanAccessTenantRow($conn, $apiKey, 'assets', $assetId)) {
        apiError(404, 'not_found', 'Asset not found.');
    }
    return $row;
}

/**
 * Validate a DATE field (YYYY-MM-DD); 422 naming the field on garbage. Null/''
 * clears. Kept here (not in AssetsService) because contracts.php + tasks.php
 * share it as a generic API date-parameter validator.
 *
 * @param mixed $value  the caller-supplied date (untrusted); null or '' clears the field
 * @param string $field  the field name, quoted in the 422 message
 */
function apiParseDateOnly($value, string $field): ?string {
    if ($value === null || $value === '') {
        return null;
    }
    $d = DateTimeImmutable::createFromFormat('Y-m-d', (string)$value);
    if (!$d || $d->format('Y-m-d') !== (string)$value) {
        apiError(422, 'invalid_field', "'{$field}' must be a date in YYYY-MM-DD format.");
    }
    return (string)$value;
}

// The asset field map, per-field validation, and audit-display resolution now
// live in AssetsService (includes/services/assets.php) — the single home shared
// with the UI's api/assets/update_asset_field.php.

// ---------------------------------------------------------------------------
// GET /assets
// ---------------------------------------------------------------------------
function apiAssetsList(PDO $conn, array $apiKey, array $params, array $body): void {
    $where = ['1=1'];
    $args  = [];

    foreach ([
        'asset_type_id'   => 'a.asset_type_id',
        'asset_status_id' => 'a.asset_status_id',
        'location_id'     => 'a.location_id',
        'supplier_id'     => 'a.supplier_id',
        'cost_centre_id'  => 'a.cost_centre_id',
    ] as $param => $col) {
        if (isset($_GET[$param]) && $_GET[$param] !== '') {
            $where[] = "$col = ?";
            $args[]  = (int)$_GET[$param];
        }
    }
    if (isset($_GET['hostname']) && $_GET['hostname'] !== '') {
        $where[] = 'a.hostname = ?';
        $args[]  = trim($_GET['hostname']);
    }
    if (isset($_GET['service_tag']) && $_GET['service_tag'] !== '') {
        $where[] = 'a.service_tag = ?';
        $args[]  = trim($_GET['service_tag']);
    }
    if (isset($_GET['q']) && trim($_GET['q']) !== '') {
        $where[] = '(a.hostname LIKE ? OR a.service_tag LIKE ? OR a.model LIKE ? OR a.manufacturer LIKE ?)';
        $like = '%' . trim($_GET['q']) . '%';
        array_push($args, $like, $like, $like, $like);
    }
    if (isset($_GET['assigned_user_id']) && $_GET['assigned_user_id'] !== '') {
        $where[] = 'EXISTS (SELECT 1 FROM users_assets ua WHERE ua.asset_id = a.id AND ua.user_id = ?)';
        $args[]  = (int)$_GET['assigned_user_id'];
    }
    if (($_GET['unassigned'] ?? '') === 'true') {
        $where[] = 'NOT EXISTS (SELECT 1 FROM users_assets ua WHERE ua.asset_id = a.id)';
    }
    // Lifecycle filters — the same shapes Watchtower/dashboard use.
    if (isset($_GET['warranty_within_days']) && $_GET['warranty_within_days'] !== '') {
        $where[] = 'a.warranty_expiry IS NOT NULL AND a.warranty_expiry <= DATE_ADD(' . naive_today_sql() . ', INTERVAL ? DAY)';
        $args[]  = max(0, (int)$_GET['warranty_within_days']);
    }
    if (($_GET['warranty_expired'] ?? '') === 'true') {
        $where[] = 'a.warranty_expiry IS NOT NULL AND a.warranty_expiry < ' . naive_today_sql();
    }
    // Retired assets: left out unless asked for, as on the screens.
    //   ?retired=include  every asset    ?retired=only  just the retired ones
    $retired = (string)($_GET['retired'] ?? 'exclude');
    if (!in_array($retired, ['exclude', 'include', 'only'], true)) {
        apiError(400, 'invalid_parameter', 'retired must be one of: exclude, include, only');
    }
    if (assetRetirementReady($conn)) {
        if ($retired === 'exclude') $where[] = 'a.retired_datetime IS NULL';
        if ($retired === 'only')    $where[] = 'a.retired_datetime IS NOT NULL';
    } elseif ($retired === 'only') {
        $where[] = '1 = 0';
    }
    if (isset($_GET['not_seen_days']) && $_GET['not_seen_days'] !== '') {
        $where[] = '(a.last_seen IS NULL OR a.last_seen < DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? DAY))';
        $args[]  = max(0, (int)$_GET['not_seen_days']);
    }

    $sortable = [
        'id' => 'a.id', 'hostname' => 'a.hostname', 'last_seen' => 'a.last_seen',
        'first_seen' => 'a.first_seen', 'warranty_expiry' => 'a.warranty_expiry',
        'purchase_date' => 'a.purchase_date', 'model' => 'a.model',
    ];
    $sortParam = trim($_GET['sort'] ?? 'hostname');
    $desc = strncmp($sortParam, '-', 1) === 0;
    $sortKey = ltrim($sortParam, '-');
    if (!isset($sortable[$sortKey])) {
        apiError(400, 'invalid_parameter', "Unknown sort field '{$sortKey}'. Sortable: " . implode(', ', array_keys($sortable)));
    }
    $orderSql = $sortable[$sortKey] . ($desc ? ' DESC' : ' ASC');

    // Multi-tenancy: restrict to the key's companies (no-op at N=1 / all-access
    // key). An explicit ?company_id= narrows within that scope.
    [$scopeSql, $scopeArgs] = apiKeyTenantFilter($conn, $apiKey, 'a');
    if (isset($_GET['company_id']) && $_GET['company_id'] !== '') {
        $cid = (int)$_GET['company_id'];
        if (!apiKeyCanAccessTenant($conn, $apiKey, $cid)) {
            apiError(403, 'forbidden', 'This key cannot access that company.');
        }
        $where[] = ($cid === getDefaultTenantId($conn))
            ? '(a.tenant_id = ? OR a.tenant_id IS NULL)' : 'a.tenant_id = ?';
        $args[] = $cid;
    }

    [$page, $perPage, $offset] = apiPagination();
    $whereSql = implode(' AND ', $where) . $scopeSql;
    $args = array_merge($args, $scopeArgs);

    $countStmt = $conn->prepare("SELECT COUNT(*) FROM assets a WHERE $whereSql");
    $countStmt->execute($args);
    $total = (int)$countStmt->fetchColumn();

    $stmt = $conn->prepare(apiAssetSelect() . " WHERE $whereSql ORDER BY $orderSql LIMIT $perPage OFFSET $offset");
    $stmt->execute($args);
    $assets = array_map(function ($r) use ($conn) {
        return apiSerializeAsset($conn, $r);
    }, $stmt->fetchAll(PDO::FETCH_ASSOC));

    apiRespond($assets, 200, [
        'page'        => $page,
        'per_page'    => $perPage,
        'total'       => $total,
        'total_pages' => (int)ceil($total / $perPage),
    ]);
}

// ---------------------------------------------------------------------------
// GET /assets/{id}
// ---------------------------------------------------------------------------
function apiAssetsGet(PDO $conn, array $apiKey, array $params, array $body): void {
    $row = apiLoadAsset($conn, $apiKey, $params[0]);
    $asset = apiSerializeAsset($conn, $row);

    // Current holders inline — the one child collection you nearly always want.
    $uStmt = $conn->prepare(
        // A holder is a requester OR an analyst. analyst_id and holder_type are
        // ADDED rather than replacing anything, so an existing API consumer
        // reading user_id and display_name keeps working unchanged.
        "SELECT ua.user_id, ua.analyst_id,
                CASE WHEN ua.analyst_id IS NOT NULL THEN 'analyst' ELSE 'user' END AS holder_type,
                COALESCE(u.display_name, ha.full_name) AS display_name,
                COALESCE(u.email, ha.email)            AS email,
                ua.assigned_datetime, ua.expected_return_date, ua.notes
         FROM users_assets ua
         LEFT JOIN users u     ON u.id  = ua.user_id
         LEFT JOIN analysts ha ON ha.id = ua.analyst_id
         WHERE ua.asset_id = ? ORDER BY ua.assigned_datetime ASC"
    );
    $uStmt->execute([$params[0]]);
    $asset['assigned_users'] = array_map(function ($u) {
        return [
            'user_id'              => (int)$u['user_id'],
            'name'                 => $u['display_name'],
            'email'                => $u['email'],
            'assigned_at'          => apiIsoDate($u['assigned_datetime']),
            'expected_return_date' => $u['expected_return_date'],
            'notes'                => $u['notes'],
        ];
    }, $uStmt->fetchAll(PDO::FETCH_ASSOC));

    // GH #201: the configuration item this asset is linked to - {id, name, class},
    // {restricted: true} without cmdb_objects.read, or null. Read-only and additive.
    $asset['linked_ci'] = AssetCiLinkService::restBlock($conn, apiAssetCiAccess($apiKey), 'asset', (int)$params[0]);

    apiRespond($asset);
}

// ---------------------------------------------------------------------------
// POST /assets
// ---------------------------------------------------------------------------
function apiAssetsCreate(PDO $conn, array $apiKey, array $params, array $body): void {
    // Multi-tenancy: the company the new asset belongs to — an explicit
    // company_id (which must be in the key's scope) or the key's default company.
    if (isset($body['company_id']) && $body['company_id'] !== '' && $body['company_id'] !== null) {
        $tenantId = (int)$body['company_id'];
        if (!getTenantById($conn, $tenantId)) {
            apiError(422, 'invalid_field', "Unknown company id: {$tenantId}");
        }
        if (!apiKeyCanAccessTenant($conn, $apiKey, $tenantId)) {
            apiError(403, 'forbidden', 'This key cannot create assets for that company.');
        }
    } else {
        $tenantId = apiKeyDefaultTenantId($conn, $apiKey);
    }

    try {
        $assetId = AssetsService::createAsset($conn, ActorContext::fromApiKey($apiKey), $body,
            'Created via API (key: ' . $apiKey['name'] . ')', $tenantId);
    } catch (ServiceError $e) { apiFailFromService($e); }
    $out = apiSerializeAsset($conn, apiLoadAsset($conn, $apiKey, $assetId));
    // #194 decision 1: name the keys that were not used, rather than accept them silently.
    if ($ignored = AssetsService::ignoredKeys($body, true)) $out['ignored'] = $ignored;
    apiRespond($out, 201);
}

// ---------------------------------------------------------------------------
// PATCH /assets/{id}
// ---------------------------------------------------------------------------
function apiAssetsUpdate(PDO $conn, array $apiKey, array $params, array $body): void {
    try {
        AssetsService::updateFields($conn, ActorContext::fromApiKey($apiKey), (int)$params[0], $body);
    } catch (ServiceError $e) { apiFailFromService($e); }
    $out = apiSerializeAsset($conn, apiLoadAsset($conn, $apiKey, $params[0]));
    if ($ignored = AssetsService::ignoredKeys($body, false)) $out['ignored'] = $ignored;
    apiRespond($out);
}

// ---------------------------------------------------------------------------
// Assignments — POST/GET /assets/{id}/assignments, DELETE .../{user_id}
// ---------------------------------------------------------------------------
function apiAssetAssignmentsList(PDO $conn, array $apiKey, array $params, array $body): void {
    apiLoadAsset($conn, $apiKey, $params[0]);
    $stmt = $conn->prepare(
        "SELECT ua.user_id, u.display_name, u.email, ua.assigned_datetime, ua.expected_return_date, ua.notes,
                a.full_name AS assigned_by
         FROM users_assets ua
         LEFT JOIN users u ON u.id = ua.user_id
         LEFT JOIN analysts a ON a.id = ua.assigned_by_analyst_id
         WHERE ua.asset_id = ? ORDER BY ua.assigned_datetime ASC"
    );
    $stmt->execute([$params[0]]);
    apiRespond(array_map(function ($r) {
        return [
            'user_id'              => (int)$r['user_id'],
            'name'                 => $r['display_name'],
            'email'                => $r['email'],
            'assigned_at'          => apiIsoDate($r['assigned_datetime']),
            'expected_return_date' => $r['expected_return_date'],
            'notes'                => $r['notes'],
            'assigned_by'          => $r['assigned_by'],
        ];
    }, $stmt->fetchAll(PDO::FETCH_ASSOC)));
}

/** POST /assets/{id}/assignments - assigns a person to the asset through AssetsService. */
function apiAssetAssignmentsCreate(PDO $conn, array $apiKey, array $params, array $body): void {
    try {
        $res = AssetsService::assignUser($conn, ActorContext::fromApiKey($apiKey), (int)$params[0], $body);
    } catch (ServiceError $e) { apiFailFromService($e); }
    apiRespond($res, 201);
}

/** DELETE /assets/{id}/assignments/{userId} - removes a person from the asset through AssetsService. */
function apiAssetAssignmentsDelete(PDO $conn, array $apiKey, array $params, array $body): void {
    try {
        AssetsService::unassignUser($conn, ActorContext::fromApiKey($apiKey), (int)$params[0], (int)$params[1]);
    } catch (ServiceError $e) { apiFailFromService($e); }
    apiRespond(['asset_id' => (int)$params[0], 'user_id' => (int)$params[1], 'unassigned' => true]);
}

// ---------------------------------------------------------------------------
// GET /assets/{id}/history  +  /custody
// ---------------------------------------------------------------------------
function apiAssetHistoryList(PDO $conn, array $apiKey, array $params, array $body): void {
    apiLoadAsset($conn, $apiKey, $params[0]);
    $stmt = $conn->prepare(
        "SELECT h.id, h.field_name, h.old_value, h.new_value, h.created_datetime,
                h.analyst_id, a.full_name AS analyst_name
         FROM asset_history h LEFT JOIN analysts a ON a.id = h.analyst_id
         WHERE h.asset_id = ? ORDER BY h.created_datetime DESC, h.id DESC"
    );
    $stmt->execute([$params[0]]);
    // GH #201: a configuration item link row holds 'ci:<id>' - 'restricted' for a key without cmdb_objects.read.
    require_once dirname(__DIR__, 3) . '/includes/services/asset_ci_links.php';
    $cmdbRead = AssetCiLinkService::accessForApiKey($apiKey)['cmdb_read'];
    apiRespond(array_map(function ($e) use ($conn, $cmdbRead) {
        $link = $e['field_name'] === 'cmdb_link';
        return [
            'id'         => (int)$e['id'],
            'field'      => $e['field_name'],
            'old_value'  => $link ? AssetCiLinkService::historyValue($conn, $e['old_value'], $cmdbRead, false) : $e['old_value'],
            'new_value'  => $link ? AssetCiLinkService::historyValue($conn, $e['new_value'], $cmdbRead, false) : $e['new_value'],
            'analyst'    => $e['analyst_id'] === null ? null : ['id' => (int)$e['analyst_id'], 'name' => $e['analyst_name']],
            'created_at' => apiIsoDate($e['created_datetime']),
        ];
    }, $stmt->fetchAll(PDO::FETCH_ASSOC)));
}

/** GET /assets/{id}/custody - the asset's check-out and check-in history, newest first. */
function apiAssetCustodyList(PDO $conn, array $apiKey, array $params, array $body): void {
    apiLoadAsset($conn, $apiKey, $params[0]);
    $stmt = $conn->prepare(
        "SELECT c.id, c.user_id, c.user_name, c.action, c.expected_return_date, c.notes,
                c.action_datetime, a.full_name AS analyst_name
         FROM asset_checkout_log c LEFT JOIN analysts a ON a.id = c.analyst_id
         WHERE c.asset_id = ? ORDER BY c.action_datetime DESC, c.id DESC"
    );
    $stmt->execute([$params[0]]);
    apiRespond(array_map(function ($e) {
        return [
            'id'                   => (int)$e['id'],
            'action'               => $e['action'],
            'user_id'              => $e['user_id'] !== null ? (int)$e['user_id'] : null,
            'user_name'            => $e['user_name'],
            'expected_return_date' => $e['expected_return_date'],
            'notes'                => $e['notes'],
            'by'                   => $e['analyst_name'],
            'at'                   => apiIsoDate($e['action_datetime']),
        ];
    }, $stmt->fetchAll(PDO::FETCH_ASSOC)));
}

// ---------------------------------------------------------------------------
// Inventory reads — disks, network adapters, devices, software
// ---------------------------------------------------------------------------
function apiAssetDisksList(PDO $conn, array $apiKey, array $params, array $body): void {
    apiLoadAsset($conn, $apiKey, $params[0]);
    $stmt = $conn->prepare(
        "SELECT id, drive, label, file_system, size_bytes, free_bytes, used_percent, source, external_id, observed_at, imported_at
         FROM asset_disks WHERE asset_id = ? ORDER BY drive"
    );
    $stmt->execute([$params[0]]);
    apiRespond(array_map(function ($d) {
        return [
            'id'           => (int)$d['id'],
            'drive'        => $d['drive'],
            'label'        => $d['label'],
            'file_system'  => $d['file_system'],
            'size_bytes'   => $d['size_bytes'] !== null ? (int)$d['size_bytes'] : null,
            'free_bytes'   => $d['free_bytes'] !== null ? (int)$d['free_bytes'] : null,
            'used_percent' => $d['used_percent'] !== null ? (float)$d['used_percent'] : null,
            'source'=>$d['source'], 'external_id'=>$d['external_id'],
            'observed_at'=>apiIsoDate($d['observed_at']), 'imported_at'=>apiIsoDate($d['imported_at']),
        ];
    }, $stmt->fetchAll(PDO::FETCH_ASSOC)));
}

/**
 * The physical drives, as opposed to the lettered volumes above (#97).
 *
 * Its own endpoint rather than extra fields on /disks: one drive carries
 * several volumes and one volume can span several drives, so there is no
 * honest way to fold them into the same row. Exposed at all because the
 * serial is the point of the data — a disposal audit or a warranty claim is
 * exactly the sort of thing somebody scripts, and a serial the screen shows
 * but the API cannot reach would be a strange place to stop.
 *
 * The table only exists after Database Verification has run, so an install
 * mid-upgrade gets an empty list rather than a 500.
 */
function apiAssetPhysicalDisksList(PDO $conn, array $apiKey, array $params, array $body): void {
    apiLoadAsset($conn, $apiKey, $params[0]);
    try {
        $stmt = $conn->prepare(
            "SELECT id, model, serial, size_bytes, media_type, interface_type
             FROM asset_physical_disks WHERE asset_id = ? ORDER BY size_bytes DESC, model"
        );
        $stmt->execute([$params[0]]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $rows = [];
    }
    apiRespond(array_map(function ($d) {
        return [
            'id'             => (int)$d['id'],
            'model'          => $d['model'],
            'serial'         => $d['serial'],
            'size_bytes'     => $d['size_bytes'] !== null ? (int)$d['size_bytes'] : null,
            'media_type'     => $d['media_type'],
            'interface_type' => $d['interface_type'],
        ];
    }, $rows));
}

/**
 * GET /assets/{id}/network-adapters - the network adapters the inventory agent reported
 * for the asset.
 */
function apiAssetNetworkAdaptersList(PDO $conn, array $apiKey, array $params, array $body): void {
    apiLoadAsset($conn, $apiKey, $params[0]);
    $stmt = $conn->prepare(
        "SELECT id, name, mac_address, ip_address, subnet_mask, gateway, dhcp_enabled, source, external_id, observed_at, imported_at
         FROM asset_network_adapters WHERE asset_id = ? ORDER BY name"
    );
    $stmt->execute([$params[0]]);
    apiRespond(array_map(function ($n) {
        return [
            'id'           => (int)$n['id'],
            'name'         => $n['name'],
            'mac_address'  => $n['mac_address'],
            'ip_address'   => $n['ip_address'],
            'subnet_mask'  => $n['subnet_mask'],
            'gateway'      => $n['gateway'],
            'dhcp_enabled' => $n['dhcp_enabled'] === null ? null : (bool)$n['dhcp_enabled'],
            'source'=>$n['source'], 'external_id'=>$n['external_id'],
            'observed_at'=>apiIsoDate($n['observed_at']), 'imported_at'=>apiIsoDate($n['imported_at']),
        ];
    }, $stmt->fetchAll(PDO::FETCH_ASSOC)));
}

/**
 * GET /assets/{id}/devices - the hardware devices and drivers the inventory agent
 * reported for the asset.
 */
function apiAssetDevicesList(PDO $conn, array $apiKey, array $params, array $body): void {
    apiLoadAsset($conn, $apiKey, $params[0]);
    $stmt = $conn->prepare(
        "SELECT id, device_class, device_name, status, manufacturer, driver_version, driver_date
         FROM asset_devices WHERE asset_id = ? ORDER BY device_class, device_name"
    );
    $stmt->execute([$params[0]]);
    apiRespond(array_map(function ($d) {
        return [
            'id'             => (int)$d['id'],
            'device_class'   => $d['device_class'],
            'device_name'    => $d['device_name'],
            'status'         => $d['status'],
            'manufacturer'   => $d['manufacturer'],
            'driver_version' => $d['driver_version'],
            'driver_date'    => $d['driver_date'],
        ];
    }, $stmt->fetchAll(PDO::FETCH_ASSOC)));
}

/**
 * GET /assets/{id}/software - the software installed on the asset, by name. System
 * components are left out unless ?include_components=true.
 */
function apiAssetSoftwareList(PDO $conn, array $apiKey, array $params, array $body): void {
    apiLoadAsset($conn, $apiKey, $params[0]);
    // software_inventory_detail keys the asset as host_id.
    $sql = "SELECT d.id, a.display_name, a.publisher, d.display_version, d.install_date,
                   d.system_component, d.last_seen, d.source, d.observed_at, d.imported_at
            FROM software_inventory_detail d
            INNER JOIN software_inventory_apps a ON a.id = d.app_id
            WHERE d.host_id = ?";
    $args = [$params[0]];
    if (($_GET['include_components'] ?? '') !== 'true') {
        $sql .= " AND (d.system_component IS NULL OR d.system_component = 0)";
    }
    $sql .= " ORDER BY a.display_name";
    $stmt = $conn->prepare($sql);
    $stmt->execute($args);
    apiRespond(array_map(function ($s) {
        return [
            'id'               => (int)$s['id'],
            'name'             => $s['display_name'],
            'publisher'        => $s['publisher'],
            'version'          => $s['display_version'],
            'install_date'     => $s['install_date'],
            'system_component' => (bool)$s['system_component'],
            'last_seen'        => apiIsoDate($s['last_seen']),
            'source'=>$s['source'], 'observed_at'=>apiIsoDate($s['observed_at']), 'imported_at'=>apiIsoDate($s['imported_at']),
        ];
    }, $stmt->fetchAll(PDO::FETCH_ASSOC)));
}

// ---------------------------------------------------------------------------
// Reference lookups
// ---------------------------------------------------------------------------
// The reference lists resolve for the key's default company (globals + that
// company's own; every row at N=1). Suppliers stay a shared registry.
function apiAssetTypesList(PDO $conn, array $apiKey, array $params, array $body): void {
    $rows = getTenantConfigRows($conn, 'asset_types', 'asset_type', apiKeyDefaultTenantId($conn, $apiKey),
        'id, name, description, is_active, display_order', '', 'display_order, name');
    apiRespond(array_map(function ($r) {
        return ['id' => (int)$r['id'], 'name' => $r['name'], 'description' => $r['description'], 'is_active' => (bool)$r['is_active']];
    }, $rows));
}

/** GET /asset-statuses - the asset statuses for the key's default company. */
function apiAssetStatusesList(PDO $conn, array $apiKey, array $params, array $body): void {
    $rows = getTenantConfigRows($conn, 'asset_status_types', 'asset_status_type', apiKeyDefaultTenantId($conn, $apiKey),
        'id, name, description, is_active, display_order', '', 'display_order, name');
    apiRespond(array_map(function ($r) {
        return ['id' => (int)$r['id'], 'name' => $r['name'], 'description' => $r['description'], 'is_active' => (bool)$r['is_active']];
    }, $rows));
}

/**
 * GET /asset-locations - the asset locations for the key's default company, top level
 * first, each with its full path.
 */
function apiAssetLocationsList(PDO $conn, array $apiKey, array $params, array $body): void {
    $rows = getTenantConfigRows($conn, 'asset_locations', 'asset_location', apiKeyDefaultTenantId($conn, $apiKey),
        'id, name, parent_id, display_order', '', '(parent_id IS NULL) DESC, parent_id, display_order, name');
    apiRespond(array_map(function ($r) use ($conn) {
        $id = (int)$r['id'];
        return [
            'id'        => $id,
            'name'      => $r['name'],
            'parent_id' => $r['parent_id'] !== null ? (int)$r['parent_id'] : null,
            'path'      => apiAssetLocationPath($conn, $id),
        ];
    }, $rows));
}

/**
 * GET /suppliers - every supplier's id, display name (trading name, else legal name) and
 * whether it supplies assets.
 */
function apiSuppliersList(PDO $conn, array $apiKey, array $params, array $body): void {
    $rows = $conn->query(
        "SELECT id, COALESCE(NULLIF(TRIM(trading_name), ''), legal_name) AS name, supplies_assets
         FROM suppliers ORDER BY name"
    )->fetchAll(PDO::FETCH_ASSOC);
    apiRespond(array_map(function ($r) {
        return ['id' => (int)$r['id'], 'name' => $r['name'], 'supplies_assets' => (bool)$r['supplies_assets']];
    }, $rows));
}

// ---------------------------------------------------------------------------
// The asset <-> configuration item link (GH #201) - every rule is in
// AssetCiLinkService, the same one the asset and CI pages use.
// ---------------------------------------------------------------------------

/** The key's access for the link: read either end only with that side's read permission. */
function apiAssetCiAccess(array $apiKey, bool $forWrite = false): array {
    require_once dirname(__DIR__, 3) . '/includes/services/asset_ci_links.php';
    return AssetCiLinkService::accessForApiKey($apiKey, $forWrite);
}

/** One period of the link, for REST: ISO dates, and the other end as the key may see it. */
function apiAssetCiLinkShape(array $view): array {
    $out = $view;
    foreach (['linked_at', 'unlinked_at'] as $k) {
        if (array_key_exists($k, $out)) $out[$k] = apiIsoDate($out[$k]);
    }
    if (isset($out['history'])) $out['history'] = array_map('apiAssetCiLinkShape', $out['history']);
    return $out;
}

/** GET /assets/{id}/ci-link - the live link (or null) and every earlier period. */
function apiAssetCiLinkGet(PDO $conn, array $apiKey, array $params, array $body): void {
    apiLoadAsset($conn, $apiKey, $params[0]);
    try {
        $v = AssetCiLinkService::forAsset($conn, ActorContext::fromApiKey($apiKey), apiAssetCiAccess($apiKey), (int)$params[0]);
    } catch (ServiceError $e) { apiFailFromService($e); }
    apiRespond(['current' => $v['current'] ? apiAssetCiLinkShape($v['current']) : null,
        'history' => array_map('apiAssetCiLinkShape', $v['history'])]);
}

/**
 * POST /assets/{id}/ci-link {cmdb_object_id, replace?, replaces_link_id? | replaces_link_ids?}
 * Links; the same pair again is 200 with unchanged:true. A conflict is 409 naming the other
 * record (or restricted) with the live link ids in details.conflicts; replace:true must name
 * exactly those links, or it is 409 link_changed.
 */
function apiAssetCiLinkCreate(PDO $conn, array $apiKey, array $params, array $body): void {
    apiLoadAsset($conn, $apiKey, $params[0]);
    $ciId = (int)($body['cmdb_object_id'] ?? 0);
    if ($ciId <= 0) apiError(422, 'missing_field', "'cmdb_object_id' is required.");
    $replaces = [];
    if (isset($body['replaces_link_ids']) && is_array($body['replaces_link_ids'])) $replaces = $body['replaces_link_ids'];
    elseif (isset($body['replaces_link_id'])) $replaces = [$body['replaces_link_id']];
    try {
        $r = AssetCiLinkService::link($conn, ActorContext::fromApiKey($apiKey), apiAssetCiAccess($apiKey, true),
            (int)$params[0], $ciId, !empty($body['replace']), $replaces, 'api:' . ($apiKey['name'] ?? 'key'));
    } catch (ServiceError $e) {
        if ($e->kind === 'conflict' && in_array($e->errorCode, ['ci_already_linked', 'asset_already_linked'], true)) {
            apiError(409, $e->errorCode, $e->getMessage(), ['conflicts' => AssetCiLinkService::conflictIds($conn, (int)$params[0], $ciId)]);
        }
        apiFailFromService($e);
    }
    apiRespond($r);
}

/**
 * POST /assets/{id}/ci-link/create-ci {name?, properties?, ignore_matches?} - Part C: creates a
 * CI of the class the asset's type is mapped to and links it, in one transaction. Also needs
 * cmdb_objects.create. 422 no_class_mapped; 409 possible_matches (with the list) unless
 * ignore_matches; an asset already linked returns its CI with created false (retry-safe).
 */
function apiAssetCiLinkCreateCi(PDO $conn, array $apiKey, array $params, array $body): void {
    apiRequirePermission($apiKey, 'cmdb_objects', 'create');
    apiLoadAsset($conn, $apiKey, $params[0]);
    $ctx = ActorContext::fromApiKey($apiKey);
    try {
        $r = AssetCiLinkService::createLinkedCi($conn, $ctx, apiAssetCiAccess($apiKey, true), (int)$params[0],
            ['name' => $body['name'] ?? '', 'properties' => is_array($body['properties'] ?? null) ? $body['properties'] : []],
            !empty($body['ignore_matches']), 'api:' . ($apiKey['name'] ?? 'key'));
    } catch (ServiceError $e) {
        if ($e->errorCode === 'possible_matches') {
            $p = AssetCiLinkService::createPreview($conn, $ctx, apiAssetCiAccess($apiKey), (int)$params[0]);
            apiError(409, 'possible_matches', $e->getMessage(), ['matches' => $p['matches']]);
        }
        apiFailFromService($e);
    }
    apiRespond($r, $r['created'] ? 201 : 200);
}

/** DELETE /assets/{id}/ci-link[?link_id=N] - ends the live link; 404 when there is none. */
function apiAssetCiLinkDelete(PDO $conn, array $apiKey, array $params, array $body): void {
    apiLoadAsset($conn, $apiKey, $params[0]);
    $expected = isset($_GET['link_id']) ? (int)$_GET['link_id'] : (isset($body['link_id']) ? (int)$body['link_id'] : null);
    try {
        $r = AssetCiLinkService::unlink($conn, ActorContext::fromApiKey($apiKey), apiAssetCiAccess($apiKey, true),
            (int)$params[0], null, $expected, 'api:' . ($apiKey['name'] ?? 'key'));
    } catch (ServiceError $e) { apiFailFromService($e); }
    apiRespond($r);
}

/** GET /cmdb/objects/{id}/asset-link - the same link, seen from the CI. */
function apiCmdbObjectAssetLinkGet(PDO $conn, array $apiKey, array $params, array $body): void {
    if (!apiKeyCanAccessTenantRow($conn, $apiKey, 'cmdb_objects', (int)$params[0])) apiError(404, 'not_found', 'Object not found.');
    try {
        $v = AssetCiLinkService::forCi($conn, ActorContext::fromApiKey($apiKey), apiAssetCiAccess($apiKey), (int)$params[0]);
    } catch (ServiceError $e) { apiFailFromService($e); }
    apiRespond(['current' => $v['current'] ? apiAssetCiLinkShape($v['current']) : null,
        'history' => array_map('apiAssetCiLinkShape', $v['history'])]);
}
