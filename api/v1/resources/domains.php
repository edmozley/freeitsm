<?php
/**
 * FreeITSM REST API v1 — domains resource (#154).
 *
 * Every WRITE goes through DomainsService (includes/services/domains.php), the
 * same code the Domains screens use, so a domain added or changed through the
 * API gets the same validation, the same history rows and the same workflow
 * events as one changed by a person. The reads + serialiser live here: the API
 * shape is a public contract, not the screens' shape.
 *
 * COMPANY SCOPE: domains are scoped data. The list is filtered to the key's
 * companies (apiKeyTenantFilter), every by-id route 404s outside them
 * (apiKeyCanAccessTenantRow — never 403, so ids cannot be probed), and a new
 * domain lands in `company_id` (checked against the key) or the key's default
 * company. Registrar accounts follow the same rule.
 *
 * 🔴 AUTH CODES NEVER CROSS THE API, in either direction. The serialiser says
 * only whether one is set; a body containing `auth_code` is refused. A
 * transfer secret belongs behind the analyst capability and its audit trail,
 * not behind a key that may be sitting in a monitoring tool's config file.
 */

require_once dirname(__DIR__, 3) . '/includes/service_context.php';
require_once dirname(__DIR__, 3) . '/includes/services/domains.php';

// ---------------------------------------------------------------------------
// Shared helpers
// ---------------------------------------------------------------------------

/**
 * The SELECT for domains, with status, registrar, account, owner, technical contact and
 * company names and, once Database Verification has added them, the customer columns.
 */
function apiDomainSelect(?PDO $conn = null): string {
    // GH #203: the interval health is worked out with, for apiSerializeDomain().
    if ($conn !== null) domainShapeInterval(domainInternalInterval(domainSettings($conn)));
    require_once dirname(__DIR__, 3) . '/includes/domains/customer.php';
    $cust = $conn !== null && domainCustomerReady($conn);   // #153, once Database Verification has run
    $custCols = $cust ? ", COALESCE(NULLIF(cu.display_name, ''), cu.email) AS customer_user_name, cu.email AS customer_user_email" : '';
    $custJoin = $cust ? " LEFT JOIN users cu ON cu.id = d.customer_user_id" : '';
    // #162: a supplier customer (and its contact), and an analyst tech contact.
    if ($conn !== null && domainPartiesReady($conn)) {
        $custCols .= ", COALESCE(NULLIF(csup.trading_name, ''), csup.legal_name) AS customer_supplier_name,
                       TRIM(CONCAT(COALESCE(cct.first_name, ''), ' ', COALESCE(cct.surname, ''))) AS customer_contact_name,
                       cct.email AS customer_contact_email, ta.full_name AS tech_analyst_name";
        $custJoin .= " LEFT JOIN suppliers csup ON csup.id = d.customer_supplier_id
                       LEFT JOIN contacts cct ON cct.id = d.customer_contact_id
                       LEFT JOIN analysts ta ON ta.id = d.tech_analyst_id";
    }
    return "SELECT d.*,
                   s.name AS status_name, s.colour AS status_colour,
                   COALESCE(NULLIF(sup.trading_name, ''), sup.legal_name) AS supplier_name,
                   acc.account_name,
                   a.full_name AS owner_name,
                   TRIM(CONCAT(COALESCE(ct.first_name, ''), ' ', COALESCE(ct.surname, ''))) AS tech_contact_name,
                   tn.name AS company_name$custCols
              FROM domains d
         LEFT JOIN domain_statuses s          ON s.id = d.status_id
         LEFT JOIN suppliers sup              ON sup.id = d.registrar_supplier_id
         LEFT JOIN domain_registrar_accounts acc ON acc.id = d.registrar_account_id
         LEFT JOIN analysts a                 ON a.id = d.owner_analyst_id
         LEFT JOIN contacts ct                ON ct.id = d.tech_contact_id
         LEFT JOIN tenants tn                 ON tn.id = d.tenant_id$custJoin";
}

/**
 * Shapes a domain row for the API - names, company, status, registrar and account, dates
 * with days to expiry, and the rest of the record. The auth code itself is never
 * included - only auth_code_set.
 *
 * @param array $r  the domains row as apiDomainSelect() loads it, with the lookup, contact and company names
 */
/** An internal domain's health for the API: the state, what was last known, and the two dates (GH #203). */
function apiDomainHealth(array $h): array {
    return [
        'state'             => $h['state'],
        'known'             => $h['known'],
        'last_completed_at' => apiIsoDate($h['last_completed']),
        'last_healthy_at'   => apiIsoDate($h['last_healthy']),
        'since_incomplete'  => $h['since_incomplete'],
        'interval_minutes'  => $h['interval_minutes'],
    ];
}

function apiSerializeDomain(array $r): array {
    $rel = function ($id, $name) { return $id === null ? null : ['id' => (int)$id, 'name' => $name]; };
    $tri = function ($v) { return $v === null ? null : (bool)(int)$v; };
    $list = function ($v, string $sep) {
        $v = trim((string)$v);
        return $v === '' ? [] : array_values(array_filter(array_map('trim', preg_split($sep, $v))));
    };
    $days = null;
    if (!empty($r['expiry_date'])) {
        $days = (int)(new DateTimeImmutable(gmdate('Y-m-d')))->diff(new DateTimeImmutable($r['expiry_date']))->format('%r%a');
    }
    return [
        'id'                => (int)$r['id'],
        'domain_name'       => $r['domain_name'],
        'display_name'      => $r['display_name'],
        'company'           => $rel($r['tenant_id'] ?? null, $r['company_name'] ?? null),
        'status'            => $rel($r['status_id'], $r['status_name']),
        'purpose'           => $r['purpose'],
        // GH #203: public | internal. An internal domain has a health instead of a grade.
        'scope'             => ($r['scope'] ?? 'public') === 'internal' ? 'internal' : 'public',
        'health'            => ($r['scope'] ?? '') === 'internal' ? apiDomainHealth(domainHealth($r, domainShapeInterval())) : null,
        'registrar'         => $rel($r['registrar_supplier_id'], $r['supplier_name']),
        'registrar_name'    => $r['registrar_name'],
        'registrar_account' => $rel($r['registrar_account_id'], $r['account_name']),
        'registration_date' => $r['registration_date'],
        'expiry_date'       => $r['expiry_date'],
        'days_remaining'    => $days,
        'last_renewed_date' => $r['last_renewed_date'],
        'registry_updated_date' => $r['registry_updated_date'],
        'renewal_mode'      => $r['renewal_mode'],
        'transfer_lock'     => $tri($r['transfer_lock']),
        'registry_lock'     => $tri($r['registry_lock']),
        'dnssec'            => $tri($r['dnssec']),
        'registry_statuses' => $list($r['registry_statuses'], '/\s*,\s*/'),
        'registrant_name'   => $r['registrant_name'],
        'owner'             => $rel($r['owner_analyst_id'], $r['owner_name']),
        'tech_contact'      => $rel($r['tech_contact_id'], $r['tech_contact_name'] !== '' ? $r['tech_contact_name'] : null),
        'customer'          => empty($r['customer_user_id']) ? null
            : ['id' => (int)$r['customer_user_id'], 'name' => $r['customer_user_name'] ?? null, 'email' => $r['customer_user_email'] ?? null],
        // #162, added beside the fields above rather than changing them: the
        // technical contact may be an analyst, the customer a supplier and/or
        // one of its contacts. At most one of tech_contact / tech_analyst is set,
        // and customer is never set together with customer_supplier.
        'tech_analyst'      => $rel($r['tech_analyst_id'] ?? null, $r['tech_analyst_name'] ?? null),
        'customer_supplier' => $rel($r['customer_supplier_id'] ?? null, $r['customer_supplier_name'] ?? null),
        'customer_contact'  => empty($r['customer_contact_id']) ? null
            : ['id' => (int)$r['customer_contact_id'], 'name' => ($r['customer_contact_name'] ?? '') !== '' ? $r['customer_contact_name'] : null, 'email' => $r['customer_contact_email'] ?? null],
        'nameservers'       => $list($r['nameservers'], '/\R/'),
        'dns_provider'      => $r['dns_provider'],
        'hosting_provider'  => $r['hosting_provider'],
        'ssl_hosts'         => $list($r['ssl_hosts'], '/\s*,\s*/'),
        'dkim_selectors'    => $list($r['dkim_selectors'], '/[\s,;]+/'),
        'cost'              => $r['cost'] === null ? null : (float)$r['cost'],
        'currency'          => $r['currency'],
        'billing_years'     => (int)$r['billing_years'],
        'cost_centre'       => $r['cost_centre'],                       // the old free text, until a cost centre is chosen
        'cost_centre_id'    => isset($r['cost_centre_id']) && $r['cost_centre_id'] !== null ? (int)$r['cost_centre_id'] : null,   // GH #188
        'cost_centre_label' => $r['cost_centre_label'] ?? null,
        'contract_id'       => $r['contract_id'] === null ? null : (int)$r['contract_id'],
        'tags'              => $list($r['tags'], '/\s*,\s*/'),
        'notes'             => $r['notes'],
        'monitoring_enabled'=> (bool)(int)$r['monitoring_enabled'],
        'parent_domain_id'  => isset($r['parent_domain_id']) ? (int)$r['parent_domain_id'] : null,   // a subdomain's parent domain
        'security_grade'    => $r['security_grade'],
        'security_score'    => $r['security_score'] === null ? null : (int)$r['security_score'],
        'ssl_expiry_date'   => $r['ssl_expiry_date'],
        'ssl_issuer'        => $r['ssl_issuer'],
        'lookup_source'     => $r['lookup_source'],
        'last_lookup_at'    => apiIsoDate($r['last_lookup_datetime']),
        'last_lookup_error' => $r['last_lookup_error'],
        'last_check_at'     => apiIsoDate($r['last_check_datetime']),
        'auth_code_set'     => !empty($r['auth_code']),
        'created_at'        => apiIsoDate($r['created_datetime']),
        'updated_at'        => apiIsoDate($r['updated_datetime']),
    ];
}

/**
 * The by-id gate: existence + company scope, both failing as 404.
 *
 * @param int $id  the domains id
 */
function apiLoadDomain(PDO $conn, array $apiKey, int $id): array {
    $stmt = $conn->prepare(apiDomainSelect($conn) . " WHERE d.id = ?");
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row || !apiKeyCanAccessTenantRow($conn, $apiKey, 'domains', $id)) {
        apiError(404, 'not_found', 'Domain not found.');
    }
    return $row;
}

/**
 * Answers 422 and stops when a request body carries auth_code - it can only be read or
 * set on the Domains screen, where it is permission-controlled and audited.
 */
function apiDomainRefuseAuthCode(array $body): void {
    if (array_key_exists('auth_code', $body)) {
        apiError(422, 'invalid_field', "'auth_code' cannot be read or set through the API. Use the Domains screen, where it is permission-controlled and audited.");
    }
}

// ---------------------------------------------------------------------------
// GET /domains
// ---------------------------------------------------------------------------
function apiDomainsList(PDO $conn, array $apiKey, array $params, array $body): void {
    $where = ['1=1'];
    $args  = [];
    foreach ([
        'status_id'             => 'd.status_id',
        'owner_analyst_id'      => 'd.owner_analyst_id',
        'registrar_supplier_id' => 'd.registrar_supplier_id',
        'registrar_account_id'  => 'd.registrar_account_id',
    ] as $param => $col) {
        if (isset($_GET[$param]) && $_GET[$param] !== '') { $where[] = "$col = ?"; $args[] = (int)$_GET[$param]; }
    }
    if (isset($_GET['purpose']) && $_GET['purpose'] !== '') {
        if (!in_array($_GET['purpose'], domainPurposes(), true)) {
            apiError(400, 'invalid_parameter', "Unknown purpose. One of: " . implode(', ', domainPurposes()));
        }
        $where[] = 'd.purpose = ?'; $args[] = $_GET['purpose'];
    }
    if (isset($_GET['scope']) && $_GET['scope'] !== '') {   // GH #203
        if (!in_array($_GET['scope'], array_column(DomainScope::cases(), 'value'), true)) {
            apiError(400, 'invalid_parameter', 'Unknown scope. One of: public, internal');
        }
        if (domainScopeReady($conn)) { $where[] = 'd.scope = ?'; $args[] = $_GET['scope']; }
        elseif ($_GET['scope'] === 'internal') { $where[] = '1=0'; }
    }
    if (isset($_GET['domain_name']) && $_GET['domain_name'] !== '') {
        $n = domainNormalise((string)$_GET['domain_name']);
        $where[] = 'd.domain_name = ?'; $args[] = $n['ok'] ? $n['name'] : trim((string)$_GET['domain_name']);
    }
    if (isset($_GET['q']) && trim($_GET['q']) !== '') {
        $like = '%' . trim($_GET['q']) . '%';
        $where[] = '(d.domain_name LIKE ? OR d.display_name LIKE ? OR d.registrar_name LIKE ? OR d.tags LIKE ?)';
        array_push($args, $like, $like, $like, $like);
    }
    if (isset($_GET['tag']) && trim($_GET['tag']) !== '') {
        $where[] = 'CONCAT(", ", LOWER(d.tags), ",") LIKE ?';
        $args[] = '%, ' . mb_strtolower(trim($_GET['tag'])) . ',%';
    }
    if (isset($_GET['expiring_within_days']) && $_GET['expiring_within_days'] !== '') {
        $where[] = 'd.expiry_date IS NOT NULL AND d.expiry_date <= DATE_ADD(UTC_DATE(), INTERVAL ? DAY)';
        $args[]  = max(0, (int)$_GET['expiring_within_days']);
    }
    if (($_GET['expired'] ?? '') === 'true') {
        $where[] = 'd.expiry_date IS NOT NULL AND d.expiry_date < UTC_DATE()';
    }
    if (isset($_GET['transfer_lock']) && in_array($_GET['transfer_lock'], ['true', 'false'], true)) {
        $where[] = 'd.transfer_lock = ?'; $args[] = $_GET['transfer_lock'] === 'true' ? 1 : 0;
    }
    if (isset($_GET['grade']) && $_GET['grade'] !== '') {
        $grades = array_filter(array_map('trim', explode(',', strtoupper((string)$_GET['grade']))));
        if ($grades) {
            $where[] = 'd.security_grade IN (' . implode(',', array_fill(0, count($grades), '?')) . ')';
            array_push($args, ...$grades);
        }
    }

    $sortable = ['domain_name' => 'd.domain_name', 'expiry_date' => 'd.expiry_date', 'security_score' => 'd.security_score',
                 'ssl_expiry_date' => 'd.ssl_expiry_date', 'created_at' => 'd.created_datetime', 'id' => 'd.id'];
    $sortParam = trim($_GET['sort'] ?? 'expiry_date');
    $desc = strncmp($sortParam, '-', 1) === 0;
    $sortKey = ltrim($sortParam, '-');
    if (!isset($sortable[$sortKey])) {
        apiError(400, 'invalid_parameter', "Unknown sort field '{$sortKey}'. Sortable: " . implode(', ', array_keys($sortable)));
    }
    // Domains with no date sort last whichever way round.
    $orderSql = $sortable[$sortKey] . ' IS NULL, ' . $sortable[$sortKey] . ($desc ? ' DESC' : ' ASC') . ', d.id';

    [$scopeSql, $scopeArgs] = apiKeyTenantFilter($conn, $apiKey, 'd');
    if (isset($_GET['company_id']) && $_GET['company_id'] !== '') {
        $cid = (int)$_GET['company_id'];
        if (!apiKeyCanAccessTenant($conn, $apiKey, $cid)) apiError(403, 'forbidden', 'This key cannot access that company.');
        $where[] = ($cid === getDefaultTenantId($conn)) ? '(d.tenant_id = ? OR d.tenant_id IS NULL)' : 'd.tenant_id = ?';
        $args[] = $cid;
    }

    [$page, $perPage, $offset] = apiPagination();
    $whereSql = implode(' AND ', $where) . $scopeSql;
    $args = array_merge($args, $scopeArgs);

    $count = $conn->prepare("SELECT COUNT(*) FROM domains d WHERE $whereSql");
    $count->execute($args);
    $total = (int)$count->fetchColumn();

    $stmt = $conn->prepare(apiDomainSelect($conn) . " WHERE $whereSql ORDER BY $orderSql LIMIT $perPage OFFSET $offset");
    $stmt->execute($args);
    apiRespond(array_map('apiSerializeDomain', $stmt->fetchAll(PDO::FETCH_ASSOC)), 200, [
        'page' => $page, 'per_page' => $perPage, 'total' => $total, 'total_pages' => (int)ceil($total / max(1, $perPage)),
    ]);
}

// ---------------------------------------------------------------------------
// GET /domains/{id}
// ---------------------------------------------------------------------------
function apiDomainsGet(PDO $conn, array $apiKey, array $params, array $body): void {
    apiRespond(apiSerializeDomain(apiLoadDomain($conn, $apiKey, (int)$params[0])));
}

// ---------------------------------------------------------------------------
// POST /domains
// ---------------------------------------------------------------------------
function apiDomainsCreate(PDO $conn, array $apiKey, array $params, array $body): void {
    apiDomainRefuseAuthCode($body);
    if (isset($body['company_id']) && $body['company_id'] !== '' && $body['company_id'] !== null) {
        $tenantId = (int)$body['company_id'];
        if (!getTenantById($conn, $tenantId)) apiError(422, 'invalid_field', "Unknown company id: {$tenantId}");
        if (!apiKeyCanAccessTenant($conn, $apiKey, $tenantId)) apiError(403, 'forbidden', 'This key cannot create domains for that company.');
    } else {
        $tenantId = apiKeyDefaultTenantId($conn, $apiKey);
    }
    unset($body['company_id'], $body['id']);
    try {
        $id = DomainsService::createDomain($conn, ActorContext::fromApiKey($apiKey), $body, $tenantId, 'api');
        // `lookup: true` asks for the registry details straight away, as the
        // screen does; without it the scheduled run fills them in.
        if (!empty($body['lookup']) && domainSetting($conn, 'domain_lookup_mode') !== 'off') {
            DomainsService::refreshLookup($conn, ActorContext::fromApiKey($apiKey), $id);
        }
        apiRespond(apiSerializeDomain(apiLoadDomain($conn, $apiKey, $id)), 201);
    } catch (ServiceError $e) {
        apiFailFromService($e);
    }
}

// ---------------------------------------------------------------------------
// PATCH /domains/{id}
// ---------------------------------------------------------------------------
function apiDomainsUpdate(PDO $conn, array $apiKey, array $params, array $body): void {
    apiDomainRefuseAuthCode($body);
    $id = (int)$params[0];
    apiLoadDomain($conn, $apiKey, $id);          // 404 first, before any body check
    unset($body['company_id'], $body['id'], $body['lookup']);
    try {
        DomainsService::updateDomain($conn, ActorContext::fromApiKey($apiKey), $id, $body, 'api');
        apiRespond(apiSerializeDomain(apiLoadDomain($conn, $apiKey, $id)));
    } catch (ServiceError $e) {
        apiFailFromService($e);
    }
}

// ---------------------------------------------------------------------------
// DELETE /domains/{id}
// ---------------------------------------------------------------------------
function apiDomainsDelete(PDO $conn, array $apiKey, array $params, array $body): void {
    apiLoadDomain($conn, $apiKey, (int)$params[0]);
    try {
        DomainsService::deleteDomain($conn, ActorContext::fromApiKey($apiKey), (int)$params[0]);
        apiRespond(['id' => $params[0], 'deleted' => true]);
    } catch (ServiceError $e) {
        apiFailFromService($e);
    }
}

// ---------------------------------------------------------------------------
// POST /domains/{id}/lookup — ask the registry now
// ---------------------------------------------------------------------------
function apiDomainsLookup(PDO $conn, array $apiKey, array $params, array $body): void {
    $id = (int)$params[0];
    apiLoadDomain($conn, $apiKey, $id);
    try {
        $r = DomainsService::refreshLookup($conn, ActorContext::fromApiKey($apiKey), $id);
        // GH #203: an internal domain is never sent to a registry - a refusal, not a failure.
        if (!empty($r['internal'])) apiError(409, 'internal_domain', (string)$r['error']);
        if (!$r['ok']) apiError(502, 'lookup_failed', (string)$r['error']);
        $out = apiSerializeDomain(apiLoadDomain($conn, $apiKey, $id));
        $out['changes'] = array_map(fn($c) => ['field' => $c['field'], 'old' => $c['old'], 'new' => $c['new']], $r['changes']);
        apiRespond($out);
    } catch (ServiceError $e) {
        apiFailFromService($e);
    }
}

// ---------------------------------------------------------------------------
// POST /domains/{id}/check — run the health and security checks now
// ---------------------------------------------------------------------------
function apiDomainsCheck(PDO $conn, array $apiKey, array $params, array $body): void {
    $id = (int)$params[0];
    apiLoadDomain($conn, $apiKey, $id);
    try {
        $r = DomainsService::runChecks($conn, ActorContext::fromApiKey($apiKey), $id);
        $out = apiSerializeDomain(apiLoadDomain($conn, $apiKey, $id));
        $out['findings'] = apiDomainFindings($r['findings']);
        $out['changes']  = $r['changes'];
        apiRespond($out);
    } catch (ServiceError $e) {
        apiFailFromService($e);
    }
}

// ---------------------------------------------------------------------------
// GET /domains/{id}/findings — the last check's findings, with English text
// ---------------------------------------------------------------------------
function apiDomainsFindings(PDO $conn, array $apiKey, array $params, array $body): void {
    $row = apiLoadDomain($conn, $apiKey, (int)$params[0]);
    $cr = $row['check_results'] ? json_decode($row['check_results'], true) : [];
    apiRespond(apiDomainFindings($cr['findings'] ?? []), 200, ['checked_at' => isset($cr['at']) ? apiIsoDate($cr['at']) : null]);
}

/**
 * Findings with their English title and advice filled in — a machine consumer has no lang file.
 *
 * @param array $findings  the 'findings' list from the domain's stored check_results JSON - each with key, area, level,
 *     weight and optional params for the text placeholders
 */
function apiDomainFindings(array $findings): array {
    static $lang = null;
    if ($lang === null) $lang = require dirname(__DIR__, 3) . '/lang/en/domains.php';
    return array_map(function ($f) use ($lang) {
        $fill = function ($s) use ($f) {
            foreach (($f['params'] ?? []) as $k => $v) $s = str_replace('{' . $k . '}', (string)$v, (string)$s);
            return str_replace('`', '', $s);
        };
        return [
            'key'    => $f['key'],
            'area'   => $f['area'],
            'level'  => $f['level'],
            'points' => (int)$f['weight'],
            'title'  => $fill($lang['check'][$f['key']]['title'] ?? $f['key']),
            'advice' => $fill($lang['check'][$f['key']]['advice'] ?? ''),
            'params' => (object)($f['params'] ?? []),
        ];
    }, $findings);
}

// ---------------------------------------------------------------------------
// GET /domains/{id}/history
// ---------------------------------------------------------------------------
function apiDomainsHistory(PDO $conn, array $apiKey, array $params, array $body): void {
    $id = (int)$params[0];
    apiLoadDomain($conn, $apiKey, $id);
    [$page, $perPage, $offset] = apiPagination();
    $count = $conn->prepare("SELECT COUNT(*) FROM domain_audit WHERE domain_id = ?");
    $count->execute([$id]);
    $total = (int)$count->fetchColumn();
    $st = $conn->prepare("SELECT h.id, h.field_name, h.old_value, h.new_value, h.source, h.created_datetime, h.analyst_id, a.full_name
                            FROM domain_audit h LEFT JOIN analysts a ON a.id = h.analyst_id
                           WHERE h.domain_id = ? ORDER BY h.id DESC LIMIT $perPage OFFSET $offset");
    $st->execute([$id]);
    $rows = array_map(fn($r) => [
        'id' => (int)$r['id'], 'field' => $r['field_name'], 'old_value' => $r['old_value'], 'new_value' => $r['new_value'],
        'source' => $r['source'], 'analyst' => $r['analyst_id'] === null ? null : ['id' => (int)$r['analyst_id'], 'name' => $r['full_name']],
        'created_at' => apiIsoDate($r['created_datetime']),
    ], $st->fetchAll(PDO::FETCH_ASSOC));
    apiRespond($rows, 200, ['page' => $page, 'per_page' => $perPage, 'total' => $total, 'total_pages' => (int)ceil($total / max(1, $perPage))]);
}

// ---------------------------------------------------------------------------
// GET /domain-statuses · GET /domain-registrar-accounts
// ---------------------------------------------------------------------------
function apiDomainStatusesList(PDO $conn, array $apiKey, array $params, array $body): void {
    $rows = $conn->query("SELECT id, name, colour, alerts_enabled, is_active, display_order FROM domain_statuses ORDER BY display_order, name")->fetchAll(PDO::FETCH_ASSOC);
    apiRespond(array_map(fn($r) => [
        'id' => (int)$r['id'], 'name' => $r['name'], 'colour' => $r['colour'],
        'alerts_enabled' => (bool)(int)$r['alerts_enabled'], 'is_active' => (bool)(int)$r['is_active'], 'display_order' => (int)$r['display_order'],
    ], $rows));
}

/**
 * GET /domain-registrar-accounts - the registrar accounts in the key's companies, with
 * registrar, owner, company and how many domains each holds.
 */
function apiDomainAccountsList(PDO $conn, array $apiKey, array $params, array $body): void {
    [$scopeSql, $scopeArgs] = apiKeyTenantFilter($conn, $apiKey, 'acc');
    $st = $conn->prepare(
        "SELECT acc.id, acc.account_name, acc.account_reference, acc.login_url, acc.two_factor_holder,
                acc.supplier_id, COALESCE(NULLIF(s.trading_name, ''), s.legal_name) AS supplier_name,
                acc.owner_analyst_id, a.full_name AS owner_name, acc.tenant_id, tn.name AS company_name,
                (SELECT COUNT(*) FROM domains d WHERE d.registrar_account_id = acc.id) AS domain_count
           FROM domain_registrar_accounts acc
      LEFT JOIN suppliers s ON s.id = acc.supplier_id
      LEFT JOIN analysts a ON a.id = acc.owner_analyst_id
      LEFT JOIN tenants tn ON tn.id = acc.tenant_id
          WHERE 1=1 $scopeSql ORDER BY acc.account_name"
    );
    $st->execute($scopeArgs);
    $rel = fn($id, $n) => $id === null ? null : ['id' => (int)$id, 'name' => $n];
    apiRespond(array_map(fn($r) => [
        'id' => (int)$r['id'], 'account_name' => $r['account_name'], 'account_reference' => $r['account_reference'],
        'login_url' => $r['login_url'], 'two_factor_holder' => $r['two_factor_holder'],
        'registrar' => $rel($r['supplier_id'], $r['supplier_name']), 'owner' => $rel($r['owner_analyst_id'], $r['owner_name']),
        'company' => $rel($r['tenant_id'], $r['company_name']), 'domain_count' => (int)$r['domain_count'],
    ], $st->fetchAll(PDO::FETCH_ASSOC)));
}
