<?php
/**
 * FreeITSM REST API v1 — chart of accounts resource (GH #282, slice 1).
 *
 * Every write goes through AccountsService (includes/services/accounts.php), the same
 * code System -> Chart of Accounts and its spreadsheet import use. Built as a copy of
 * the cost centres resource on purpose: the two lists behave identically, with the
 * account's `kind` (expense | fixed_asset | payroll | income) added.
 *
 * 🔑 POST /accounts/sync IS THE ONE A FINANCE SYSTEM CALLS. Send the chart, matched on
 * code, as often as you like: new codes are added, changed ones updated, nothing
 * duplicated, and - with deactivate_missing - anything no longer in the list made
 * inactive. All or nothing: one bad row and nothing is written, and every problem
 * comes back in error.details. A new account needs a name and a kind, or a parent to
 * take the kind from.
 *
 * COMPANY SCOPE: accounts are always one company's. The list is filtered to the key's
 * companies, every by-id route 404s outside them (never 403, so ids cannot be
 * probed), and writes land in `company_id` (checked against the key) or the key's
 * default company.
 */

require_once dirname(__DIR__, 3) . '/includes/service_context.php';
require_once dirname(__DIR__, 3) . '/includes/services/accounts.php';

/** The most rows one sync call may carry. Bigger charts: split them by company. */
const API_ACCOUNT_SYNC_MAX = 10000;

/** The SELECT for accounts, with the parent's code and name, the company name and the number of child accounts. */
function apiAccountSelect(): string {
    return "SELECT a.*, p.code AS parent_code, p.name AS parent_name, t.name AS company_name,
                   (SELECT COUNT(*) FROM accounts k WHERE k.parent_id = a.id) AS child_count
              FROM accounts a
         LEFT JOIN accounts p ON p.id = a.parent_id
         LEFT JOIN tenants t ON t.id = a.tenant_id";
}

/**
 * Shapes an account row for the API.
 *
 * @param array $r  the accounts row as apiAccountSelect() loads it
 */
function apiSerializeAccount(array $r): array {
    return [
        'id'          => (int)$r['id'],
        'code'        => $r['code'],
        'name'        => $r['name'],
        'kind'        => $r['kind'],
        'description' => $r['description'],
        'parent'      => $r['parent_id'] === null ? null : ['id' => (int)$r['parent_id'], 'code' => $r['parent_code'], 'name' => $r['parent_name']],
        'is_active'   => (bool)(int)$r['is_active'],
        'company'     => ['id' => (int)$r['tenant_id'], 'name' => $r['company_name']],
        'child_count' => (int)$r['child_count'],
        'created_at'  => apiIsoDate($r['created_datetime']),
        'updated_at'  => apiIsoDate($r['updated_datetime']),
    ];
}

/**
 * The by-id gate: existence + company scope, both failing as 404.
 *
 * @param int $id  the accounts id
 */
function apiLoadAccount(PDO $conn, array $apiKey, int $id): array {
    $st = $conn->prepare(apiAccountSelect() . " WHERE a.id = ?");
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row || !apiKeyCanAccessTenantRow($conn, $apiKey, 'accounts', $id)) {
        apiError(404, 'not_found', 'Account not found.');
    }
    return $row;
}

/**
 * company_id from the body/query, checked against the key - or the key's default company.
 *
 * @param mixed $raw  the caller's company_id (untrusted); null or '' means the key's default company
 */
function apiAccountCompany(PDO $conn, array $apiKey, $raw): int {
    if ($raw === null || $raw === '') return apiKeyDefaultTenantId($conn, $apiKey);
    $tenantId = (int)$raw;
    if (!getTenantById($conn, $tenantId)) apiError(422, 'invalid_field', "Unknown company id: {$tenantId}");
    if (!apiKeyCanAccessTenant($conn, $apiKey, $tenantId)) apiError(403, 'forbidden', 'This key cannot access that company.');
    return $tenantId;
}

/** Answers 503 and stops when the accounts table is not there yet. */
function apiAccountsReady(PDO $conn): void {
    if (!AccountsService::ready($conn)) {
        apiError(503, 'not_ready', 'The chart of accounts is not set up on this install yet: an administrator needs to run System > Database Verification.');
    }
}

// ---------------------------------------------------------------------------
// GET /accounts
// ---------------------------------------------------------------------------
function apiAccountsList(PDO $conn, array $apiKey, array $params, array $body): void {
    apiAccountsReady($conn);
    $where = ['1=1'];
    $args  = [];
    if (isset($_GET['company_id']) && $_GET['company_id'] !== '') {
        $where[] = 'a.tenant_id = ?';
        $args[] = apiAccountCompany($conn, $apiKey, $_GET['company_id']);
    }
    if (isset($_GET['code']) && trim($_GET['code']) !== '') {
        $where[] = 'a.code = ?';                 // _ci collation: ignores case, like everywhere else
        $args[] = trim((string)$_GET['code']);
    }
    if (isset($_GET['kind']) && $_GET['kind'] !== '') {
        if (!in_array($_GET['kind'], AccountsService::KINDS, true)) {
            apiError(400, 'invalid_parameter', "'kind' must be one of: " . implode(', ', AccountsService::KINDS) . '.');
        }
        $where[] = 'a.kind = ?';
        $args[] = $_GET['kind'];
    }
    if (isset($_GET['q']) && trim($_GET['q']) !== '') {
        $like = '%' . trim($_GET['q']) . '%';
        $where[] = '(a.code LIKE ? OR a.name LIKE ? OR a.description LIKE ?)';
        array_push($args, $like, $like, $like);
    }
    if (isset($_GET['active'])) {
        if (!in_array($_GET['active'], ['true', 'false'], true)) apiError(400, 'invalid_parameter', "'active' must be true or false.");
        $where[] = 'a.is_active = ?';
        $args[] = $_GET['active'] === 'true' ? 1 : 0;
    }
    if (isset($_GET['parent_id']) && $_GET['parent_id'] !== '') {
        if ((int)$_GET['parent_id'] === 0) {
            $where[] = 'a.parent_id IS NULL';
        } else {
            $where[] = 'a.parent_id = ?';
            $args[] = (int)$_GET['parent_id'];
        }
    }

    $sortable = ['code' => 'a.code', 'name' => 'a.name', 'kind' => 'a.kind', 'updated_at' => 'a.updated_datetime', 'id' => 'a.id'];
    $sortParam = trim($_GET['sort'] ?? 'code');
    $desc = strncmp($sortParam, '-', 1) === 0;
    $sortKey = ltrim($sortParam, '-');
    if (!isset($sortable[$sortKey])) {
        apiError(400, 'invalid_parameter', "Unknown sort field '{$sortKey}'. Sortable: " . implode(', ', array_keys($sortable)));
    }
    $orderSql = $sortable[$sortKey] . ($desc ? ' DESC' : ' ASC') . ', a.id';

    [$scopeSql, $scopeArgs] = apiKeyTenantFilter($conn, $apiKey, 'a');
    [$page, $perPage, $offset] = apiPagination();
    $whereSql = implode(' AND ', $where) . $scopeSql;
    $args = array_merge($args, $scopeArgs);

    $count = $conn->prepare("SELECT COUNT(*) FROM accounts a WHERE $whereSql");
    $count->execute($args);
    $total = (int)$count->fetchColumn();

    $st = $conn->prepare(apiAccountSelect() . " WHERE $whereSql ORDER BY $orderSql LIMIT $perPage OFFSET $offset");
    $st->execute($args);
    apiRespond(array_map('apiSerializeAccount', $st->fetchAll(PDO::FETCH_ASSOC)), 200, [
        'page' => $page, 'per_page' => $perPage, 'total' => $total, 'total_pages' => (int)ceil($total / max(1, $perPage)),
    ]);
}

// ---------------------------------------------------------------------------
// GET /accounts/{id}
// ---------------------------------------------------------------------------
function apiAccountsGet(PDO $conn, array $apiKey, array $params, array $body): void {
    apiAccountsReady($conn);
    apiRespond(apiSerializeAccount(apiLoadAccount($conn, $apiKey, (int)$params[0])));
}

// ---------------------------------------------------------------------------
// POST /accounts
// ---------------------------------------------------------------------------
function apiAccountsCreate(PDO $conn, array $apiKey, array $params, array $body): void {
    apiAccountsReady($conn);
    $tenantId = apiAccountCompany($conn, $apiKey, $body['company_id'] ?? null);
    $in = array_intersect_key($body, array_flip(['code', 'name', 'kind', 'description', 'parent_id', 'parent_code', 'is_active']));
    try {
        $id = AccountsService::create($conn, ActorContext::fromApiKey($apiKey), $tenantId, $in);
        apiRespond(apiSerializeAccount(apiLoadAccount($conn, $apiKey, $id)), 201);
    } catch (ServiceError $e) {
        apiFailFromService($e);
    }
}

// ---------------------------------------------------------------------------
// PATCH /accounts/{id}
// ---------------------------------------------------------------------------
function apiAccountsUpdate(PDO $conn, array $apiKey, array $params, array $body): void {
    apiAccountsReady($conn);
    $id = (int)$params[0];
    apiLoadAccount($conn, $apiKey, $id);          // 404 first, before any body check
    if (array_key_exists('company_id', $body)) {
        apiError(422, 'invalid_field', "An account cannot move between companies. Create it in the other company instead.");
    }
    $in = array_intersect_key($body, array_flip(['code', 'name', 'kind', 'description', 'parent_id', 'parent_code', 'is_active']));
    try {
        AccountsService::update($conn, ActorContext::fromApiKey($apiKey), $id, $in);
        apiRespond(apiSerializeAccount(apiLoadAccount($conn, $apiKey, $id)));
    } catch (ServiceError $e) {
        apiFailFromService($e);
    }
}

// ---------------------------------------------------------------------------
// DELETE /accounts/{id}
// ---------------------------------------------------------------------------
function apiAccountsDelete(PDO $conn, array $apiKey, array $params, array $body): void {
    apiAccountsReady($conn);
    $id = (int)$params[0];
    apiLoadAccount($conn, $apiKey, $id);
    try {
        AccountsService::delete($conn, ActorContext::fromApiKey($apiKey), $id);
        apiRespond(['id' => $id, 'deleted' => true]);
    } catch (ServiceError $e) {
        apiFailFromService($e);
    }
}

// ---------------------------------------------------------------------------
// POST /accounts/sync — bring a company's chart into line, matched on code
// ---------------------------------------------------------------------------
function apiAccountsSync(PDO $conn, array $apiKey, array $params, array $body): void {
    apiAccountsReady($conn);
    // The route asks for update; a sync can also add, so it needs create too.
    apiRequirePermission($apiKey, 'accounts', 'create');
    $tenantId = apiAccountCompany($conn, $apiKey, $body['company_id'] ?? null);
    $list = $body['accounts'] ?? null;
    if (!is_array($list) || array_values($list) !== $list) {
        apiError(422, 'invalid_field', "'accounts' must be an array of objects, each with at least a code.");
    }
    if (count($list) > API_ACCOUNT_SYNC_MAX) {
        apiError(422, 'invalid_field', 'At most ' . API_ACCOUNT_SYNC_MAX . ' accounts per call.');
    }
    $rows = [];
    foreach ($list as $i => $item) {
        if (!is_array($item)) apiError(422, 'invalid_field', "accounts[{$i}] is not an object.");
        $rows[] = ['line' => $i + 1] + array_intersect_key($item, array_flip(['code', 'name', 'kind', 'description', 'parent_code', 'is_active']));
    }
    try {
        $report = AccountsService::sync($conn, ActorContext::fromApiKey($apiKey), $tenantId, $rows, [
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
        // 'line' is the 1-based position in accounts[]; null for one made inactive
        // because it was missing from the list.
        'changes'    => array_values(array_filter($report['rows'], fn($r) => $r['action'] !== 'unchanged')),
    ];
    if ($report['errors']) {
        apiError(422, 'invalid_rows', count($report['errors']) . ' account(s) have problems, so nothing was changed.', [
            'errors' => $report['errors'], 'counts' => $report['counts'],
        ]);
    }
    apiRespond($out);
}
