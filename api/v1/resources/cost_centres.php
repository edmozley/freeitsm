<?php
/**
 * FreeITSM REST API v1 — cost centres resource (GH #160).
 *
 * Every write goes through CostCentresService (includes/services/cost_centres.php),
 * the same code System -> Cost Centres and its spreadsheet import use, so a cost
 * centre changed through the API gets exactly the same checks as one changed by
 * a person. The reads + serialiser live here: the API shape is a public contract.
 *
 * 🔑 POST /cost-centres/sync IS THE ONE AN ACCOUNTING SYSTEM CALLS. Send the
 * list, matched on code, as often as you like: new codes are added, changed
 * ones updated, nothing duplicated, and - with deactivate_missing - anything no
 * longer in the list made inactive. All or nothing: one bad row and nothing is
 * written, and every problem comes back in error.details.
 *
 * COMPANY SCOPE: cost centres are always one company's. The list is filtered to
 * the key's companies, every by-id route 404s outside them (never 403, so ids
 * cannot be probed), and writes land in `company_id` (checked against the key)
 * or the key's default company.
 */

require_once dirname(__DIR__, 3) . '/includes/service_context.php';
require_once dirname(__DIR__, 3) . '/includes/services/cost_centres.php';

/** The most rows one sync call may carry. Bigger lists: split them by company. */
const API_COST_CENTRE_SYNC_MAX = 10000;

/**
 * The SELECT for cost centres, with the parent's code and name, the company name and the
 * number of child cost centres.
 */
function apiCostCentreSelect(?PDO $conn = null): string {
    // GH #160 Part B: the owner and deputy, once Database Verification has added them.
    $people = $conn !== null && CostCentresService::peopleReady($conn);
    return "SELECT c.*, p.code AS parent_code, p.name AS parent_name, t.name AS company_name,
                   (SELECT COUNT(*) FROM cost_centres k WHERE k.parent_id = c.id) AS child_count"
         . ($people ? ",
                   ou.display_name AS owner_name, ou.email AS owner_email, ou.is_active AS owner_active,
                   og.name AS owner_group_name, du.display_name AS deputy_name, du.email AS deputy_email, du.is_active AS deputy_active" : "") . "
              FROM cost_centres c
         LEFT JOIN cost_centres p ON p.id = c.parent_id
         LEFT JOIN tenants t ON t.id = c.tenant_id"
         . ($people ? "
         LEFT JOIN users ou ON ou.id = c.owner_user_id
         LEFT JOIN knowledge_user_groups og ON og.id = c.owner_group_id
         LEFT JOIN users du ON du.id = c.deputy_user_id" : "");
}

/**
 * Shapes a cost centre row for the API - code, name, description, parent, active flag,
 * company, child count and dates.
 *
 * @param array $r  the cost_centres row as apiCostCentreSelect() loads it, with parent code/name, company name and child_count
 */
function apiSerializeCostCentre(array $r): array {
    return [
        'id'          => (int)$r['id'],
        'code'        => $r['code'],
        'name'        => $r['name'],
        'description' => $r['description'],
        'parent'      => $r['parent_id'] === null ? null : ['id' => (int)$r['parent_id'], 'code' => $r['parent_code'], 'name' => $r['parent_name']],
        'is_active'   => (bool)(int)$r['is_active'],
        'company'     => ['id' => (int)$r['tenant_id'], 'name' => $r['company_name']],
        'child_count' => (int)$r['child_count'],
        // GH #160 Part B. `active` false: the person has since been deactivated - kept, flagged.
        'owner'       => empty($r['owner_user_id']) ? null : ['id' => (int)$r['owner_user_id'], 'name' => $r['owner_name'] ?? null, 'email' => $r['owner_email'] ?? null, 'active' => (bool)(int)($r['owner_active'] ?? 1)],
        'owner_group' => empty($r['owner_group_id']) ? null : ['id' => (int)$r['owner_group_id'], 'name' => $r['owner_group_name'] ?? null],
        'deputy'      => empty($r['deputy_user_id']) ? null : ['id' => (int)$r['deputy_user_id'], 'name' => $r['deputy_name'] ?? null, 'email' => $r['deputy_email'] ?? null, 'active' => (bool)(int)($r['deputy_active'] ?? 1)],
        'created_at'  => apiIsoDate($r['created_datetime']),
        'updated_at'  => apiIsoDate($r['updated_datetime']),
    ];
}

/**
 * The by-id gate: existence + company scope, both failing as 404.
 *
 * @param int $id  the cost_centres id
 */
function apiLoadCostCentre(PDO $conn, array $apiKey, int $id): array {
    $st = $conn->prepare(apiCostCentreSelect($conn) . " WHERE c.id = ?");
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row || !apiKeyCanAccessTenantRow($conn, $apiKey, 'cost_centres', $id)) {
        apiError(404, 'not_found', 'Cost centre not found.');
    }
    return $row;
}

/**
 * company_id from the body/query, checked against the key - or the key's default company.
 *
 * @param mixed $raw  the caller's company_id (untrusted); null or '' means the key's default company
 */
function apiCostCentreCompany(PDO $conn, array $apiKey, $raw): int {
    if ($raw === null || $raw === '') return apiKeyDefaultTenantId($conn, $apiKey);
    $tenantId = (int)$raw;
    if (!getTenantById($conn, $tenantId)) apiError(422, 'invalid_field', "Unknown company id: {$tenantId}");
    if (!apiKeyCanAccessTenant($conn, $apiKey, $tenantId)) apiError(403, 'forbidden', 'This key cannot access that company.');
    return $tenantId;
}

/**
 * Answers 503 and stops when the cost centre tables are not there yet (Database
 * Verification has not been run).
 */
function apiCostCentresReady(PDO $conn): void {
    if (!CostCentresService::ready($conn)) {
        apiError(503, 'not_ready', 'Cost centres are not set up on this install yet: an administrator needs to run System > Database Verification.');
    }
}

// ---------------------------------------------------------------------------
// GET /cost-centres
// ---------------------------------------------------------------------------
function apiCostCentresList(PDO $conn, array $apiKey, array $params, array $body): void {
    apiCostCentresReady($conn);
    $where = ['1=1'];
    $args  = [];
    if (isset($_GET['company_id']) && $_GET['company_id'] !== '') {
        $where[] = 'c.tenant_id = ?';
        $args[] = apiCostCentreCompany($conn, $apiKey, $_GET['company_id']);
    }
    if (isset($_GET['code']) && trim($_GET['code']) !== '') {
        $where[] = 'c.code = ?';                 // _ci collation: ignores case, like everywhere else
        $args[] = trim((string)$_GET['code']);
    }
    if (isset($_GET['q']) && trim($_GET['q']) !== '') {
        $like = '%' . trim($_GET['q']) . '%';
        $where[] = '(c.code LIKE ? OR c.name LIKE ? OR c.description LIKE ?)';
        array_push($args, $like, $like, $like);
    }
    if (isset($_GET['active'])) {
        if (!in_array($_GET['active'], ['true', 'false'], true)) apiError(400, 'invalid_parameter', "'active' must be true or false.");
        $where[] = 'c.is_active = ?';
        $args[] = $_GET['active'] === 'true' ? 1 : 0;
    }
    if (isset($_GET['parent_id']) && $_GET['parent_id'] !== '') {
        if ((int)$_GET['parent_id'] === 0) {
            $where[] = 'c.parent_id IS NULL';
        } else {
            $where[] = 'c.parent_id = ?';
            $args[] = (int)$_GET['parent_id'];
        }
    }

    $sortable = ['code' => 'c.code', 'name' => 'c.name', 'updated_at' => 'c.updated_datetime', 'id' => 'c.id'];
    $sortParam = trim($_GET['sort'] ?? 'code');
    $desc = strncmp($sortParam, '-', 1) === 0;
    $sortKey = ltrim($sortParam, '-');
    if (!isset($sortable[$sortKey])) {
        apiError(400, 'invalid_parameter', "Unknown sort field '{$sortKey}'. Sortable: " . implode(', ', array_keys($sortable)));
    }
    $orderSql = $sortable[$sortKey] . ($desc ? ' DESC' : ' ASC') . ', c.id';

    [$scopeSql, $scopeArgs] = apiKeyTenantFilter($conn, $apiKey, 'c');
    [$page, $perPage, $offset] = apiPagination();
    $whereSql = implode(' AND ', $where) . $scopeSql;
    $args = array_merge($args, $scopeArgs);

    $count = $conn->prepare("SELECT COUNT(*) FROM cost_centres c WHERE $whereSql");
    $count->execute($args);
    $total = (int)$count->fetchColumn();

    $st = $conn->prepare(apiCostCentreSelect($conn) . " WHERE $whereSql ORDER BY $orderSql LIMIT $perPage OFFSET $offset");
    $st->execute($args);
    apiRespond(array_map('apiSerializeCostCentre', $st->fetchAll(PDO::FETCH_ASSOC)), 200, [
        'page' => $page, 'per_page' => $perPage, 'total' => $total, 'total_pages' => (int)ceil($total / max(1, $perPage)),
    ]);
}

// ---------------------------------------------------------------------------
// GET /cost-centres/{id}
// ---------------------------------------------------------------------------
function apiCostCentresGet(PDO $conn, array $apiKey, array $params, array $body): void {
    apiCostCentresReady($conn);
    apiRespond(apiSerializeCostCentre(apiLoadCostCentre($conn, $apiKey, (int)$params[0])));
}

// ---------------------------------------------------------------------------
// POST /cost-centres
// ---------------------------------------------------------------------------
function apiCostCentresCreate(PDO $conn, array $apiKey, array $params, array $body): void {
    apiCostCentresReady($conn);
    $tenantId = apiCostCentreCompany($conn, $apiKey, $body['company_id'] ?? null);
    $in = array_intersect_key($body, array_flip(['code', 'name', 'description', 'parent_id', 'parent_code', 'is_active',
        'owner_user_id', 'owner_email', 'owner_group_id', 'owner_group', 'deputy_user_id', 'deputy_email']));   // GH #160 Part B
    try {
        $id = CostCentresService::create($conn, ActorContext::fromApiKey($apiKey), $tenantId, $in);
        apiRespond(apiSerializeCostCentre(apiLoadCostCentre($conn, $apiKey, $id)), 201);
    } catch (ServiceError $e) {
        apiFailFromService($e);
    }
}

// ---------------------------------------------------------------------------
// PATCH /cost-centres/{id}
// ---------------------------------------------------------------------------
function apiCostCentresUpdate(PDO $conn, array $apiKey, array $params, array $body): void {
    apiCostCentresReady($conn);
    $id = (int)$params[0];
    apiLoadCostCentre($conn, $apiKey, $id);          // 404 first, before any body check
    if (array_key_exists('company_id', $body)) {
        apiError(422, 'invalid_field', "A cost centre cannot move between companies. Create it in the other company instead.");
    }
    $in = array_intersect_key($body, array_flip(['code', 'name', 'description', 'parent_id', 'parent_code', 'is_active',
        'owner_user_id', 'owner_email', 'owner_group_id', 'owner_group', 'deputy_user_id', 'deputy_email']));   // GH #160 Part B
    try {
        CostCentresService::update($conn, ActorContext::fromApiKey($apiKey), $id, $in);
        apiRespond(apiSerializeCostCentre(apiLoadCostCentre($conn, $apiKey, $id)));
    } catch (ServiceError $e) {
        apiFailFromService($e);
    }
}

// ---------------------------------------------------------------------------
// DELETE /cost-centres/{id}
// ---------------------------------------------------------------------------
function apiCostCentresDelete(PDO $conn, array $apiKey, array $params, array $body): void {
    apiCostCentresReady($conn);
    $id = (int)$params[0];
    apiLoadCostCentre($conn, $apiKey, $id);
    try {
        CostCentresService::delete($conn, ActorContext::fromApiKey($apiKey), $id);
        apiRespond(['id' => $id, 'deleted' => true]);
    } catch (ServiceError $e) {
        apiFailFromService($e);
    }
}

// ---------------------------------------------------------------------------
// POST /cost-centres/sync — bring a company's list into line, matched on code
// ---------------------------------------------------------------------------
function apiCostCentresSync(PDO $conn, array $apiKey, array $params, array $body): void {
    apiCostCentresReady($conn);
    // The route asks for update; a sync can also add, so it needs create too.
    apiRequirePermission($apiKey, 'cost_centres', 'create');
    $tenantId = apiCostCentreCompany($conn, $apiKey, $body['company_id'] ?? null);
    $list = $body['cost_centres'] ?? null;
    if (!is_array($list) || array_values($list) !== $list) {
        apiError(422, 'invalid_field', "'cost_centres' must be an array of objects, each with at least a code.");
    }
    if (count($list) > API_COST_CENTRE_SYNC_MAX) {
        apiError(422, 'invalid_field', 'At most ' . API_COST_CENTRE_SYNC_MAX . ' cost centres per call.');
    }
    $rows = [];
    foreach ($list as $i => $item) {
        if (!is_array($item)) apiError(422, 'invalid_field', "cost_centres[{$i}] is not an object.");
        // Only the keys sent are touched; 'line' is the item's position, 1-based.
        $rows[] = ['line' => $i + 1] + array_intersect_key($item, array_flip(['code', 'name', 'description', 'parent_code', 'is_active', 'owner_email', 'owner_group', 'deputy_email']));
    }
    try {
        $report = CostCentresService::sync($conn, ActorContext::fromApiKey($apiKey), $tenantId, $rows, [
            'dry_run'            => !empty($body['dry_run']),
            'deactivate_missing' => !empty($body['deactivate_missing']),
        ]);
    } catch (ServiceError $e) {
        apiFailFromService($e);
    }
    $out = [
        'company_id' => $tenantId,
        'applied'    => $report['applied'],
        'dry_run'    => $report['dry_run'],
        'counts'     => $report['counts'],
        // 'line' is the 1-based position in cost_centres[]; null for one made
        // inactive because it was missing from the list.
        'changes'    => array_values(array_filter($report['rows'], fn($r) => $r['action'] !== 'unchanged')),
    ];
    if ($report['errors']) {
        apiError(422, 'invalid_rows', count($report['errors']) . ' cost centre(s) have problems, so nothing was changed.', [
            'errors' => $report['errors'], 'counts' => $report['counts'],
        ]);
    }
    apiRespond($out);
}
