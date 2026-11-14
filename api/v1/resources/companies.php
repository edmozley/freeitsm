<?php
/**
 * FreeITSM REST API v1 - companies (tenants), GH #279.
 *
 *   GET   /companies               the companies this key can see (?q= ?external_ref= ?tax_id= ?active=)
 *   GET   /companies/{id}          one company the key can see
 *   POST  /companies               create
 *   PATCH /companies/{id}          change only the fields sent
 *   POST  /companies/upsert        create or update by external_ref - the call an ERP / CRM
 *                                  sync makes for each customer, every night, without duplicates
 *
 * The rules (name required; ticket code, external_ref and tax_id unique when set; the
 * Default company stays active; only what is sent changes) are in
 * includes/services/companies.php, shared with System -> Companies. This adapter adds
 * the KEY rule: 🔴 every write needs a key that (a) acts as a System administrator and
 * (b) is scoped to all companies. In the app only administrators manage companies
 * (admin_api_guard.php); a key limited to some companies cannot create the next one,
 * because it could never see it afterwards, and must not rename or switch off a
 * company it shares with others.
 */

require_once __DIR__ . '/../../../includes/services/companies.php';

/** 403 unless this key may create and change companies. */
function apiCompaniesRequireManager(PDO $conn, array $apiKey): void
{
    if ($apiKey['company_scope'] !== null) {
        apiError(403, 'forbidden', 'Managing companies needs a key scoped to all companies: a company belongs to the whole install.');
    }
    if (empty($apiKey['analyst_id']) || !analystIsAdmin($conn, (int)$apiKey['analyst_id'])) {
        apiError(403, 'forbidden', 'Managing companies needs a key that acts as a System administrator, as System -> Companies does in the app.');
    }
}

/**
 * The writable fields from a request body, type-checked. Unknown keys are ignored;
 * the values themselves are checked by CompaniesService::save().
 */
function apiCompaniesInput(array $body): array
{
    $in = [];
    foreach (['name', 'ticket_code', 'external_ref', 'tax_id'] as $k) {
        if (!array_key_exists($k, $body)) continue;
        if ($body[$k] !== null && !is_string($body[$k]) && !is_int($body[$k])) {
            apiError(422, 'invalid_field', "'$k' must be text.");
        }
        $in[$k] = $body[$k];
    }
    if (array_key_exists('is_active', $body)) {
        if (!is_bool($body['is_active']) && !in_array($body['is_active'], [0, 1, '0', '1'], true)) {
            apiError(422, 'invalid_field', "'is_active' must be true or false.");
        }
        $in['is_active'] = (bool)(int)$body['is_active'];
    }
    return $in;
}

/** ?active= -> true / false / null (both). 400 on anything else. */
function apiCompaniesActiveFilter(?bool $default): ?bool
{
    if (!isset($_GET['active']) || trim((string)$_GET['active']) === '') return $default;
    $v = strtolower(trim((string)$_GET['active']));
    if (in_array($v, ['true', '1', 'yes'], true))  return true;
    if (in_array($v, ['false', '0', 'no'], true))  return false;
    if ($v !== 'all') apiError(400, 'invalid_parameter', "'active' must be true, false or all.");
    return null;
}

// GET /companies - the companies (tenants) this key can see.
//
// Unpaginated, as it always was: it is a lookup list, and a sync wanting one company
// asks by ?external_ref= or ?tax_id=. Active companies only unless ?active= says
// otherwise - EXCEPT an exact ?external_ref= / ?tax_id= lookup, which finds the company
// whether or not it is active (a sync must find the customer it switched off, or it
// would try to create them again and be refused as a duplicate).
function apiCompaniesList(PDO $conn, array $apiKey, array $params, array $body): void {
    if (!tenancyTablesReady($conn)) {
        // tenants table not migrated yet - behave as a single Default company.
        apiRespond([['id' => getDefaultTenantId($conn), 'name' => 'Default', 'is_default' => true, 'is_active' => true,
                     'ticket_code' => null, 'external_ref' => null, 'tax_id' => null]]);
    }
    $ready = CompaniesService::refsReady($conn);
    $where = [];
    $args  = [];
    $exact = false;
    foreach (['external_ref', 'tax_id'] as $col) {
        if (!isset($_GET[$col]) || trim((string)$_GET[$col]) === '') continue;
        $exact = true;
        if (!$ready) apiRespond([]);   // no company can have one yet
        $where[] = "LOWER($col) = LOWER(?)";
        $args[]  = trim(preg_replace('/\s+/u', ' ', (string)$_GET[$col]) ?? '');
    }
    $active = apiCompaniesActiveFilter($exact ? null : true);
    if ($active !== null) {
        $where[] = 'is_active = ?';
        $args[]  = $active ? 1 : 0;
    }
    if (isset($_GET['q']) && trim((string)$_GET['q']) !== '') {
        $like = '%' . addcslashes(trim((string)$_GET['q']), '\\%_') . '%';
        $where[] = $ready ? '(name LIKE ? OR external_ref LIKE ? OR tax_id LIKE ?)' : 'name LIKE ?';
        array_push($args, ...array_fill(0, $ready ? 3 : 1, $like));
    }
    if ($apiKey['company_scope'] !== null) {
        if (!$apiKey['company_scope']) apiRespond([]);
        $where[] = 'id IN (' . implode(',', array_map('intval', $apiKey['company_scope'])) . ')';
    }
    $cols = 'id, name, ticket_code, is_default, is_active' . ($ready ? ', external_ref, tax_id' : '');
    $st = $conn->prepare("SELECT $cols FROM tenants" . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
        . ' ORDER BY is_default DESC, name ASC');
    $st->execute($args);
    apiRespond(array_map(['CompaniesService', 'serialize'], $st->fetchAll(PDO::FETCH_ASSOC)));
}

// GET /companies/{id} - unknown and outside the key's companies give the same 404.
function apiCompaniesGet(PDO $conn, array $apiKey, array $params, array $body): void {
    $id = (int)$params[0];
    $row = apiKeyCanAccessTenant($conn, $apiKey, $id) ? CompaniesService::get($conn, $id) : null;
    if (!$row) apiError(404, 'not_found', 'Company not found.');
    apiRespond(CompaniesService::serialize($row));
}

// POST /companies
function apiCompaniesCreate(PDO $conn, array $apiKey, array $params, array $body): void {
    apiCompaniesRequireManager($conn, $apiKey);
    $in = apiCompaniesInput($body);
    try {
        $r = CompaniesService::save($conn, $in, null);
        apiRespond(CompaniesService::serialize(CompaniesService::get($conn, $r['id'])), 201);
    } catch (ServiceError $e) { apiFailFromService($e); }
}

// PATCH /companies/{id} - only the fields sent change.
function apiCompaniesUpdate(PDO $conn, array $apiKey, array $params, array $body): void {
    $id = (int)$params[0];
    // Outside the key's companies looks exactly like an unknown id.
    if (!apiKeyCanAccessTenant($conn, $apiKey, $id) || !CompaniesService::get($conn, $id)) {
        apiError(404, 'not_found', 'Company not found.');
    }
    apiCompaniesRequireManager($conn, $apiKey);
    $in = apiCompaniesInput($body);
    if (!$in) apiError(422, 'missing_field', 'No fields to update.');
    try {
        CompaniesService::save($conn, $in, $id);
        apiRespond(CompaniesService::serialize(CompaniesService::get($conn, $id)));
    } catch (ServiceError $e) { apiFailFromService($e); }
}

// POST /companies/upsert - create or update by external_ref. 201 + created: true when
// it made a new company, 200 + created: false when it changed the one it found.
function apiCompaniesUpsert(PDO $conn, array $apiKey, array $params, array $body): void {
    // The route asks for companies.create; an upsert can also change one, so update too.
    apiRequirePermission($apiKey, 'companies', 'update');
    apiCompaniesRequireManager($conn, $apiKey);
    $in = apiCompaniesInput($body);
    try {
        $r = CompaniesService::upsert($conn, $in);
        $out = CompaniesService::serialize(CompaniesService::get($conn, $r['id']));
        $out['created'] = $r['created'];
        // Two literal calls: the OpenAPI generator reads the status codes from them.
        if ($r['created']) apiRespond($out, 201);
        apiRespond($out);
    } catch (ServiceError $e) { apiFailFromService($e); }
}
