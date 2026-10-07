<?php
/**
 * People (#153 step 2) — one page per person and per company.
 *
 * Reads only. A person is a row in `users`; a company is a row in `tenants`.
 * Nothing here is stored: every section is read from the module that owns it,
 * at the moment the page is opened, so nothing is copied and nothing can drift.
 *
 * 🔑 TWO GATES ON EVERY SECTION, AND BOTH ARE THE OWNING MODULE'S.
 *   1. The module: a person's tickets appear only for an analyst who can open
 *      Tickets, their courses only for one who can open the LMS, and so on.
 *      A section the reader may not see is ABSENT from the result, not empty -
 *      "no tickets" and "you cannot see tickets" are different answers.
 *   2. The company: each section applies the same company rule its own module
 *      does - tickets, assets and domains by the companies the analyst can
 *      access, contracts by contractVisibilitySql(). The page as a whole needs
 *      analystCanAccessUser() / analystCanAccessTenant() first; out of reach
 *      reads exactly like "no such person".
 *
 * Accessible companies, not the ACTIVE one: a person page is reached by id from
 * anywhere (a contract, a domain, search), and showing nothing because the
 * header's company picker points elsewhere would read as "this person has no
 * tickets". Every company shown is one the analyst may already open.
 *
 * SUPPLIERS AND THEIR CONTACTS (#153 step 3, #162). Shown here, not moved here:
 * they stay in `suppliers` and `contacts`, owned and edited by Contracts, so a
 * supplier's contact never becomes somebody who can sign in, raise a ticket or
 * be swept up by directory sync. Contracts owns them, so their pages need
 * Contracts as well as People (peopleCanSeeSuppliers). They have no company -
 * they are install-wide, as in Contracts - but every section on their pages
 * still applies its own module's company rule.
 */

require_once __DIR__ . '/tenancy.php';
require_once __DIR__ . '/entity_links.php';

const PEOPLE_SECTION_LIMIT = 50;

/** " AND (<col> IN (accessible) [OR <col> IS NULL])", or '' on a single-company install. */
function peopleScope(PDO $conn, int $analystId, string $qualified): array
{
    if (!isMultiTenant($conn)) return ['', []];
    return allAccessibleTenantsFilter($conn, $analystId, $qualified);
}

/** " AND <col> = ?" for one company - NULL counts as the Default company's. */
function peopleCompanyMatch(PDO $conn, int $tenantId, string $qualified): array
{
    if (!isMultiTenant($conn)) return ['', []];
    if ($tenantId === getDefaultTenantId($conn)) return [" AND ($qualified = ? OR $qualified IS NULL)", [$tenantId]];
    return [" AND $qualified = ?", [$tenantId]];
}

function peopleName(array $r): string
{
    foreach (['preferred_name', 'display_name', 'email', 'username'] as $k) {
        $v = trim((string)($r[$k] ?? ''));
        if ($v !== '') return $v;
    }
    return '#' . (int)($r['id'] ?? 0);
}

// ---------------------------------------------------------------------------
//  Lists
// ---------------------------------------------------------------------------

/**
 * People the analyst can see, newest-relevant first by name.
 * @param array $f q, company (tenant id), status ('active' default | 'leavers' | 'all')
 */
function peopleListRows(PDO $conn, int $analystId, array $f = [], int $limit = 200): array
{
    [$scope, $args] = peopleScope($conn, $analystId, 'u.tenant_id');
    $where = '1=1' . $scope;
    if (!empty($f['company'])) {
        $co = (int)$f['company'];
        if (!analystCanAccessTenant($conn, $analystId, $co)) return [];
        [$m, $mArgs] = peopleCompanyMatch($conn, $co, 'u.tenant_id');
        $where .= $m; $args = array_merge($args, $mArgs);
    }
    $status = $f['status'] ?? 'active';
    if ($status === 'active')  $where .= ' AND u.is_active = 1';
    if ($status === 'leavers') $where .= ' AND u.is_active = 0';
    $q = trim((string)($f['q'] ?? ''));
    if ($q !== '') {
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
        $where .= ' AND (u.display_name LIKE ? OR u.preferred_name LIKE ? OR u.email LIKE ? OR u.username LIKE ?
                         OR u.job_title LIKE ? OR u.department LIKE ? OR u.employee_id LIKE ?)';
        array_push($args, $like, $like, $like, $like, $like, $like, $like);
    }
    $st = $conn->prepare(
        "SELECT u.id, u.display_name, u.preferred_name, u.email, u.username, u.job_title, u.department,
                u.is_active, u.tenant_id, tn.name AS company_name
           FROM users u LEFT JOIN tenants tn ON tn.id = u.tenant_id
          WHERE $where
          ORDER BY (COALESCE(NULLIF(u.preferred_name, ''), NULLIF(u.display_name, ''), u.email) IS NULL),
                   COALESCE(NULLIF(u.preferred_name, ''), NULLIF(u.display_name, ''), u.email)
          LIMIT " . (int)$limit
    );
    $st->execute($args);
    // No company means the Default company, as everywhere else - say so.
    $defaultName = isMultiTenant($conn) ? (getTenantById($conn, getDefaultTenantId($conn))['name'] ?? null) : null;
    return array_map(fn($r) => [
        'id' => (int)$r['id'], 'name' => peopleName($r), 'email' => $r['email'],
        'job_title' => $r['job_title'], 'department' => $r['department'],
        'company' => $r['company_name'] ?? $defaultName, 'is_active' => (int)$r['is_active'] === 1,
    ], $st->fetchAll(PDO::FETCH_ASSOC));
}

/** The companies this analyst can access, with how many people are in each. */
function peopleCompanies(PDO $conn, int $analystId): array
{
    $default = getDefaultTenantId($conn);
    $ids = isMultiTenant($conn) ? array_map('intval', getAccessibleTenantIds($conn, $analystId)) : [$default];
    if (!$ids) return [];
    $in = implode(',', array_fill(0, count($ids), '?'));
    $st = $conn->prepare("SELECT id, name, is_active, is_default FROM tenants WHERE id IN ($in) ORDER BY is_default DESC, name");
    $st->execute($ids);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    $counts = $conn->query("SELECT COALESCE(tenant_id, $default) AS t, COUNT(*) AS n FROM users WHERE is_active = 1 GROUP BY COALESCE(tenant_id, $default)")
                   ->fetchAll(PDO::FETCH_KEY_PAIR);
    return array_map(fn($r) => [
        'id' => (int)$r['id'], 'name' => $r['name'], 'is_active' => (int)$r['is_active'] === 1,
        'is_default' => (int)$r['is_default'] === 1, 'people' => (int)($counts[$r['id']] ?? 0),
    ], $rows);
}

/**
 * The companies page's cards: peopleCompanies() plus a few figures for each.
 *
 * One grouped query per figure rather than companyDetail() per company. Each
 * figure obeys the company page's own rules, so a card and the page it opens
 * cannot disagree: a module's figure only for an analyst who can open that
 * module (null, not 0, otherwise - "no tickets" would be a claim), limited to
 * the companies they can access, and a record with no company counted under
 * the Default company, as peopleCompanyMatch() does.
 */
function peopleCompanyCards(PDO $conn, int $analystId): array
{
    $cards = peopleCompanies($conn, $analystId);
    if (!$cards) return [];
    $default = getDefaultTenantId($conn);
    $can = fn(string $m) => analystCanAccessModule($conn, $analystId, $m);
    $grouped = function (string $sql, array $args) use ($conn): array {
        $st = $conn->prepare($sql);
        $st->execute($args);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_NUM) as $r) $out[(int)$r[0]] = array_map('intval', array_slice($r, 1));
        return $out;
    };

    $tickets = null;
    if ($can('tickets')) {
        [$scope, $args] = peopleScope($conn, $analystId, 't.tenant_id');
        $tickets = $grouped(
            "SELECT COALESCE(t.tenant_id, $default), SUM(COALESCE(ts.is_closed, 0) = 0)
               FROM tickets t LEFT JOIN ticket_statuses ts ON ts.id = t.status_id
              WHERE t.deleted_datetime IS NULL $scope GROUP BY 1", $args);
    }
    $assets = null;
    if ($can('assets')) {
        [$scope, $args] = peopleScope($conn, $analystId, 'a.tenant_id');
        $assets = $grouped("SELECT COALESCE(a.tenant_id, $default), COUNT(*) FROM assets a WHERE 1=1 $scope GROUP BY 1", $args);
    }

    // Everyone, leavers included: the company page's People figure counts its
    // list, which shows leavers (marked). peopleCompanies() counts current only.
    $everyone = $grouped("SELECT COALESCE(tenant_id, $default), COUNT(*) FROM users GROUP BY 1", []);

    $codes = [];
    foreach ($conn->query("SELECT id, ticket_code FROM tenants")->fetchAll(PDO::FETCH_KEY_PAIR) as $id => $code) $codes[(int)$id] = $code;
    $domains = [];
    try {
        foreach ($conn->query("SELECT tenant_id, COUNT(*) FROM tenant_domains GROUP BY tenant_id")->fetchAll(PDO::FETCH_KEY_PAIR) as $id => $n) $domains[(int)$id] = (int)$n;
    } catch (Throwable $e) { /* routing domains are optional */ }

    foreach ($cards as &$c) {
        $c['people']       = $everyone[$c['id']][0] ?? 0;
        $c['ticket_code']  = $codes[$c['id']] ?? null;
        $c['domains']      = $domains[$c['id']] ?? 0;
        $c['open_tickets'] = $tickets === null ? null : ($tickets[$c['id']][0] ?? 0);
        $c['assets']       = $assets === null ? null : ($assets[$c['id']][0] ?? 0);
    }
    return $cards;
}

// ---------------------------------------------------------------------------
//  One person
// ---------------------------------------------------------------------------

/** Everything about one person this analyst may see, or null (unknown or out of reach). */
function personDetail(PDO $conn, int $analystId, int $userId): ?array
{
    if ($userId <= 0 || !analystCanAccessUser($conn, $analystId, $userId)) return null;
    $st = $conn->prepare(
        "SELECT u.id, u.email, u.username, u.display_name, u.preferred_name, u.job_title, u.department,
                u.office, u.phone, u.mobile, u.employee_id, u.manager_id, u.is_active, u.is_managed,
                u.deactivated_datetime, u.created_at, u.tenant_id, tn.name AS company_name,
                ap.display_name AS source_name
           FROM users u
      LEFT JOIN tenants tn ON tn.id = u.tenant_id
      LEFT JOIN auth_providers ap ON ap.id = u.auth_provider_id
          WHERE u.id = ?"
    );
    $st->execute([$userId]);
    $u = $st->fetch(PDO::FETCH_ASSOC);
    if (!$u) return null;

    $person = [
        'id' => (int)$u['id'], 'name' => peopleName($u),
        'display_name' => $u['display_name'], 'email' => $u['email'], 'username' => $u['username'],
        'job_title' => $u['job_title'], 'department' => $u['department'], 'office' => $u['office'],
        'phone' => $u['phone'], 'mobile' => $u['mobile'], 'employee_id' => $u['employee_id'],
        'is_active' => (int)$u['is_active'] === 1, 'is_managed' => (int)$u['is_managed'] === 1,
        'deactivated_datetime' => $u['deactivated_datetime'], 'created_at' => $u['created_at'],
        'company_id' => $u['tenant_id'] !== null ? (int)$u['tenant_id'] : getDefaultTenantId($conn),
        'company' => $u['company_name'] ?? (getTenantById($conn, getDefaultTenantId($conn))['name'] ?? null),
        'source' => $u['source_name'],
        'manager' => null, 'reports' => [],
    ];
    // The manager and the reports are people too: each is shown only if the
    // reader could open THEIR page (a manager can sit in another company).
    if ($u['manager_id'] !== null && analystCanAccessUser($conn, $analystId, (int)$u['manager_id'])) {
        $m = $conn->prepare("SELECT id, display_name, preferred_name, email, username FROM users WHERE id = ?");
        $m->execute([(int)$u['manager_id']]);
        if ($mr = $m->fetch(PDO::FETCH_ASSOC)) $person['manager'] = ['id' => (int)$mr['id'], 'name' => peopleName($mr)];
    }
    [$rs, $rArgs] = peopleScope($conn, $analystId, 'r.tenant_id');
    $r = $conn->prepare("SELECT r.id, r.display_name, r.preferred_name, r.email, r.username, r.is_active
                           FROM users r WHERE r.manager_id = ? $rs
                          ORDER BY r.is_active DESC, COALESCE(NULLIF(r.display_name, ''), r.email) LIMIT 200");
    $r->execute(array_merge([$userId], $rArgs));
    foreach ($r->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $person['reports'][] = ['id' => (int)$row['id'], 'name' => peopleName($row), 'is_active' => (int)$row['is_active'] === 1];
    }

    $sections = [];
    $can = fn(string $m) => analystCanAccessModule($conn, $analystId, $m);
    $who = ['user' => $userId];
    if ($can('tickets'))   $sections['tickets']   = peopleTickets($conn, $analystId, $who);
    if ($can('assets'))    $sections['assets']    = peopleAssets($conn, $analystId, $who);
    if ($can('contracts')) $sections['contracts'] = peopleContracts($conn, $analystId, $who);
    if ($can('domains'))   $sections['domains']   = peopleDomains($conn, $analystId, $who);
    if ($can('lms'))       $sections['courses']   = peopleCoursesForPerson($conn, $userId);
    if ($can('forms'))     $sections['forms']     = peopleForms($conn, $analystId, $who);
    if ($can('projects'))  $sections['projects']  = peopleProjects($conn, $analystId, $who);
    return ['person' => $person, 'sections' => $sections];
}

// ---------------------------------------------------------------------------
//  One company
// ---------------------------------------------------------------------------

/** Everything about one company this analyst may see, or null. */
function companyDetail(PDO $conn, int $analystId, int $tenantId): ?array
{
    $multi = isMultiTenant($conn);
    if ($tenantId <= 0) return null;
    if ($multi && !analystCanAccessTenant($conn, $analystId, $tenantId)) return null;
    if (!$multi && $tenantId !== getDefaultTenantId($conn)) return null;
    $t = getTenantById($conn, $tenantId);
    if (!$t) return null;

    $domains = [];
    try {
        $d = $conn->prepare("SELECT domain FROM tenant_domains WHERE tenant_id = ? ORDER BY domain");
        $d->execute([$tenantId]);
        $domains = $d->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) { /* routing domains are optional */ }

    [$m, $mArgs] = peopleCompanyMatch($conn, $tenantId, 'u.tenant_id');
    $cnt = $conn->prepare("SELECT SUM(u.is_active = 1), SUM(u.is_active = 0) FROM users u WHERE 1=1 $m");
    $cnt->execute($mArgs);
    [$active, $leavers] = array_map('intval', $cnt->fetch(PDO::FETCH_NUM) ?: [0, 0]);

    $company = [
        'id' => (int)$t['id'], 'name' => $t['name'], 'ticket_code' => $t['ticket_code'] ?? null,
        'is_default' => (int)($t['is_default'] ?? 0) === 1, 'is_active' => (int)($t['is_active'] ?? 1) === 1,
        'email_domains' => $domains, 'people_active' => $active, 'people_leavers' => $leavers,
        'multi_company' => $multi,
    ];

    $sections = ['people' => peopleListRows($conn, $analystId, ['company' => $multi ? $tenantId : null, 'status' => 'all'], 500)];
    $can = fn(string $mod) => analystCanAccessModule($conn, $analystId, $mod);
    $who = ['company' => $tenantId];
    if ($can('tickets'))   $sections['tickets']   = peopleTickets($conn, $analystId, $who);
    if ($can('assets'))    $sections['assets']    = peopleAssets($conn, $analystId, $who);
    if ($can('contracts')) $sections['contracts'] = peopleContracts($conn, $analystId, $who);
    if ($can('domains'))   $sections['domains']   = peopleDomains($conn, $analystId, $who);
    if ($can('lms'))       $sections['courses']   = peopleCoursesForCompany($conn, $tenantId);
    if ($can('forms'))     $sections['forms']     = peopleForms($conn, $analystId, $who);
    if ($can('projects'))  $sections['projects']  = peopleProjects($conn, $analystId, $who);
    return ['company' => $company, 'sections' => $sections];
}

// ---------------------------------------------------------------------------
//  Sections - each takes $who = ['user' => id] or ['company' => id]
// ---------------------------------------------------------------------------

/** Tickets raised by the person / in the company: the latest, and how many are open. */
function peopleTickets(PDO $conn, int $analystId, array $who): array
{
    [$scope, $args] = peopleScope($conn, $analystId, 't.tenant_id');
    if (isset($who['user'])) {
        $match = ' AND t.user_id = ?'; $mArgs = [(int)$who['user']];
    } else {
        [$match, $mArgs] = peopleCompanyMatch($conn, (int)$who['company'], 't.tenant_id');
    }
    $base = "FROM tickets t LEFT JOIN ticket_statuses ts ON ts.id = t.status_id
             WHERE t.deleted_datetime IS NULL $match $scope";
    $all = array_merge($mArgs, $args);

    $c = $conn->prepare("SELECT COUNT(*), SUM(COALESCE(ts.is_closed, 0) = 0) $base");
    $c->execute($all);
    [$total, $open] = array_map('intval', $c->fetch(PDO::FETCH_NUM) ?: [0, 0]);

    $st = $conn->prepare(
        "SELECT t.id, t.ticket_number, t.subject, t.created_datetime, ts.name AS status, ts.colour AS status_colour,
                COALESCE(ts.is_closed, 0) AS is_closed, tp.name AS priority, a.full_name AS analyst,
                COALESCE(NULLIF(u.display_name, ''), u.email) AS requester
           FROM tickets t
      LEFT JOIN ticket_statuses ts ON ts.id = t.status_id
      LEFT JOIN ticket_priorities tp ON tp.id = t.priority_id
      LEFT JOIN analysts a ON a.id = t.assigned_analyst_id
      LEFT JOIN users u ON u.id = t.user_id
          WHERE t.deleted_datetime IS NULL $match $scope
          ORDER BY COALESCE(ts.is_closed, 0), t.created_datetime DESC
          LIMIT " . PEOPLE_SECTION_LIMIT
    );
    $st->execute($all);
    $rows = array_map(fn($r) => [
        'id' => (int)$r['id'], 'number' => $r['ticket_number'], 'subject' => $r['subject'],
        'created' => $r['created_datetime'], 'status' => $r['status'], 'status_colour' => $r['status_colour'],
        'is_closed' => (int)$r['is_closed'] === 1, 'priority' => $r['priority'], 'analyst' => $r['analyst'],
        'requester' => $r['requester'], 'url' => entityLink('ticket', (int)$r['id']),
    ], $st->fetchAll(PDO::FETCH_ASSOC));
    return ['total' => $total, 'open' => $open, 'rows' => $rows];
}

/** Assets the person holds now / assets belonging to the company. */
function peopleAssets(PDO $conn, int $analystId, array $who): array
{
    [$scope, $args] = peopleScope($conn, $analystId, 'a.tenant_id');
    $cols = "a.id, a.hostname, a.manufacturer, a.model, a.service_tag, a.asset_tag, at.name AS asset_type, ast.name AS asset_status";
    $joins = "LEFT JOIN asset_types at ON at.id = a.asset_type_id LEFT JOIN asset_status_types ast ON ast.id = a.asset_status_id";
    if (isset($who['user'])) {
        $from = "FROM users_assets ua JOIN assets a ON a.id = ua.asset_id $joins WHERE ua.user_id = ? $scope";
        $cols .= ", ua.assigned_datetime";
        $all = array_merge([(int)$who['user']], $args);
    } else {
        [$match, $mArgs] = peopleCompanyMatch($conn, (int)$who['company'], 'a.tenant_id');
        $from = "FROM assets a $joins WHERE 1=1 $match $scope";
        $cols .= ", (SELECT COALESCE(NULLIF(hu.display_name, ''), hu.email) FROM users_assets hua JOIN users hu ON hu.id = hua.user_id WHERE hua.asset_id = a.id ORDER BY hua.id LIMIT 1) AS holder";
        $all = array_merge($mArgs, $args);
    }
    $c = $conn->prepare("SELECT COUNT(*) $from");
    $c->execute($all);
    $total = (int)$c->fetchColumn();
    $st = $conn->prepare("SELECT $cols $from ORDER BY at.name, a.hostname LIMIT " . PEOPLE_SECTION_LIMIT);
    $st->execute($all);
    $rows = array_map(fn($r) => [
        'id' => (int)$r['id'], 'name' => $r['hostname'] ?: ($r['asset_tag'] ?: '#' . $r['id']),
        'type' => $r['asset_type'], 'status' => $r['asset_status'],
        'model' => trim(($r['manufacturer'] ?? '') . ' ' . ($r['model'] ?? '')), 'serial' => $r['service_tag'],
        'tag' => $r['asset_tag'], 'assigned' => $r['assigned_datetime'] ?? null, 'holder' => $r['holder'] ?? null,
        'url' => entityLink('asset', (int)$r['id']),
    ], $st->fetchAll(PDO::FETCH_ASSOC));
    return ['total' => $total, 'rows' => $rows];
}

/** Contracts where the person / the company is the CUSTOMER (#153 step 1). */
function peopleContracts(PDO $conn, int $analystId, array $who): array
{
    require_once __DIR__ . '/contract_party.php';
    if (!contractPartyReady($conn)) return ['total' => 0, 'rows' => []];
    [$vis, $vArgs] = contractVisibilitySql($conn, $analystId, 'c');
    if (isset($who['user'])) {
        $match = 'c.customer_user_id = ?'; $mArgs = [(int)$who['user']];
    } else {
        $expr = contractCustomerTenantExpr('c');
        $tid = (int)$who['company'];
        $match = $tid === getDefaultTenantId($conn) ? "($expr = ? OR $expr IS NULL)" : "$expr = ?";
        $mArgs = [$tid];
    }
    [$partyCols, $partyJoins] = contractPartySql($conn, 'c');
    $st = $conn->prepare(
        "SELECT c.id, c.contract_number, c.title, c.contract_start, c.contract_end, c.notice_date, c.is_active,
                c.contract_value, c.currency, st.name AS status, NULL AS supplier_name, NULL AS supplier_trading_name
                $partyCols
           FROM contracts c
      LEFT JOIN contract_statuses st ON st.id = c.contract_status_id
                $partyJoins
          WHERE c.party_type = 'customer' AND $match $vis
          ORDER BY c.is_active DESC, c.contract_end IS NULL, c.contract_end
          LIMIT " . PEOPLE_SECTION_LIMIT
    );
    $st->execute(array_merge($mArgs, $vArgs));
    $rows = array_map(fn($r) => [
        'id' => (int)$r['id'], 'number' => $r['contract_number'], 'title' => $r['title'],
        'start' => $r['contract_start'], 'end' => $r['contract_end'], 'notice' => $r['notice_date'],
        'is_active' => (int)$r['is_active'] === 1, 'status' => $r['status'],
        'value' => $r['contract_value'] !== null ? (float)$r['contract_value'] : null, 'currency' => $r['currency'],
        'party' => contractPartyLabel($r), 'url' => entityLink('contract', (int)$r['id']),
    ], $st->fetchAll(PDO::FETCH_ASSOC));
    return ['total' => count($rows), 'rows' => $rows];
}

/** Domains the person is the customer for / the company holds. */
function peopleDomains(PDO $conn, int $analystId, array $who): array
{
    [$scope, $args] = peopleScope($conn, $analystId, 'd.tenant_id');
    if (isset($who['user'])) {
        require_once __DIR__ . '/domains/customer.php';
        if (!domainCustomerReady($conn)) return ['total' => 0, 'rows' => []];
        $match = ' AND d.customer_user_id = ?'; $mArgs = [(int)$who['user']];
    } else {
        [$match, $mArgs] = peopleCompanyMatch($conn, (int)$who['company'], 'd.tenant_id');
    }
    $st = $conn->prepare(
        "SELECT d.id, d.domain_name, d.display_name, d.expiry_date, d.security_grade, s.name AS status, s.colour AS status_colour
           FROM domains d LEFT JOIN domain_statuses s ON s.id = d.status_id
          WHERE 1=1 $match $scope
          ORDER BY d.expiry_date IS NULL, d.expiry_date, d.domain_name
          LIMIT " . PEOPLE_SECTION_LIMIT
    );
    $st->execute(array_merge($mArgs, $args));
    $rows = array_map(fn($r) => [
        'id' => (int)$r['id'], 'name' => $r['display_name'] ?: $r['domain_name'], 'domain' => $r['domain_name'],
        'expiry' => $r['expiry_date'], 'grade' => $r['security_grade'], 'status' => $r['status'],
        'status_colour' => $r['status_colour'], 'url' => entityLink('domain', (int)$r['id']),
    ], $st->fetchAll(PDO::FETCH_ASSOC));
    return ['total' => count($rows), 'rows' => $rows];
}

/**
 * Projects (3.2.0): for a person, the projects they are a member of (People
 * tab of a project) with their role and their RACI duties ("Accountable for
 * Phones working on day one"); for a company, its projects. Live ones first.
 * Limited to the companies the analyst can access, like Projects itself.
 */
function peopleProjects(PDO $conn, int $analystId, array $who): array
{
    require_once __DIR__ . '/projects/methodologies.php';
    if (!projectsSchemaReady($conn)) return ['total' => 0, 'rows' => []];
    require_once __DIR__ . '/projects/read.php';
    [$scope, $args] = peopleScope($conn, $analystId, 'p.tenant_id');
    if (isset($who['user'])) {
        if (!projectsPhase2Ready($conn)) return ['total' => 0, 'rows' => []];
        $sql = "SELECT p.*, a.full_name AS owner_name, GROUP_CONCAT(DISTINCT r.name ORDER BY r.display_order SEPARATOR ', ') AS role_names
                  FROM project_members m JOIN projects p ON p.id = m.project_id
             LEFT JOIN project_roles r ON r.id = m.role_id
             LEFT JOIN analysts a ON a.id = p.owner_analyst_id
                 WHERE m.user_id = ? $scope GROUP BY p.id";
        $mArgs = [(int)$who['user']];
    } else {
        [$match, $mArgs] = peopleCompanyMatch($conn, (int)$who['company'], 'p.tenant_id');
        $sql = "SELECT p.*, a.full_name AS owner_name, NULL AS role_names
                  FROM projects p LEFT JOIN analysts a ON a.id = p.owner_analyst_id
                 WHERE 1=1 $match $scope";
    }
    $st = $conn->prepare($sql . " ORDER BY FIELD(p.status, 'active', 'proposed', 'on_hold', 'closed', 'cancelled'), p.name LIMIT " . PEOPLE_SECTION_LIMIT);
    $st->execute(array_merge($mArgs, $args));
    $raw = $st->fetchAll(PDO::FETCH_ASSOC);
    $stats = projectTaskStats($conn, array_column($raw, 'id'));
    $cfg = projectHealthConfig($conn);
    // A person's RACI duties per project: letter => the deliverables, in scope order.
    $duties = [];
    if (isset($who['user']) && $raw) {
        $in = implode(',', array_map('intval', array_column($raw, 'id')));
        $dq = $conn->prepare("SELECT m.project_id, x.letter, i.title
                                FROM project_raci x
                                JOIN project_members m ON m.id = x.member_id
                                JOIN project_items i ON i.id = x.item_id
                               WHERE m.user_id = ? AND m.project_id IN ($in) AND i.status <> 'dropped'
                            ORDER BY FIELD(x.letter, 'A', 'R', 'C', 'I'), i.position, i.id");
        $dq->execute([(int)$who['user']]);
        foreach ($dq->fetchAll(PDO::FETCH_ASSOC) as $d) $duties[(int)$d['project_id']][$d['letter']][] = $d['title'];
    }
    $rows = array_map(function ($r) use ($stats, $cfg, $duties) {
        $d = projectDecorate($r, $stats[(int)$r['id']] ?? [], $cfg);
        return ['id' => (int)$d['id'], 'code' => $d['code'], 'name' => $d['name'], 'status' => $d['status'],
                'colour' => projectColourHex($d['colour']), 'health' => $d['shown_health'], 'progress' => (int)$d['progress'],
                'owner' => $d['owner_name'], 'role' => $r['role_names'], 'duties' => $duties[(int)$d['id']] ?? [], 'target_end_date' => $d['target_end_date'],
                'url' => entityLink('project', (int)$d['id'])];
    }, $raw);
    return ['total' => count($rows), 'rows' => $rows];
}

/** The person's courses - exactly what their own My courses page shows. */
function peopleCoursesForPerson(PDO $conn, int $userId): array
{
    require_once __DIR__ . '/lms_access.php';
    $learner = LmsLearner::user($userId);
    if (!$learner) return ['total' => 0, 'rows' => []];
    try { $courses = lmsMyCourses($conn, $learner); } catch (Throwable $e) { return ['total' => 0, 'rows' => []]; }
    $rows = array_map(fn($c) => [
        'id' => (int)$c['id'], 'title' => $c['title'], 'status' => (string)$c['status'],
        'deadline' => $c['deadline'] ?? null, 'completed' => $c['completion_datetime'] ?? null,
        'is_overdue' => !empty($c['is_overdue']),
        'lesson_position' => (int)($c['lesson_position'] ?? 0), 'lesson_count' => (int)($c['lesson_count'] ?? 0),
    ], $courses);
    return ['total' => count($rows), 'rows' => $rows];
}

/** Per course, how many people in the company have finished it and how many are part-way. */
function peopleCoursesForCompany(PDO $conn, int $tenantId): array
{
    [$m, $mArgs] = peopleCompanyMatch($conn, $tenantId, 'u.tenant_id');
    try {
        $st = $conn->prepare(
            "SELECT c.id, c.title,
                    SUM(p.status IN ('completed', 'passed')) AS finished,
                    SUM(p.status NOT IN ('completed', 'passed', 'not_started')) AS in_progress
               FROM lms_progress p
               JOIN users u ON u.id = p.learner_id
               JOIN lms_courses c ON c.id = p.course_id
              WHERE p.learner_type = 'user' $m
              GROUP BY c.id, c.title
              ORDER BY c.title"
        );
        $st->execute($mArgs);
    } catch (Throwable $e) {
        return ['total' => 0, 'rows' => []];
    }
    $rows = array_map(fn($r) => ['id' => (int)$r['id'], 'title' => $r['title'], 'finished' => (int)$r['finished'], 'in_progress' => (int)$r['in_progress']],
                      $st->fetchAll(PDO::FETCH_ASSOC));
    return ['total' => count($rows), 'rows' => $rows];
}

/** Forms the person submitted / people in the company submitted (from the portal). */
function peopleForms(PDO $conn, int $analystId, array $who): array
{
    if (isset($who['user'])) {
        $match = 's.submitted_by_user_id = ?'; $mArgs = [(int)$who['user']];
    } else {
        [$cm, $mArgs] = peopleCompanyMatch($conn, (int)$who['company'], 'u.tenant_id');
        $match = 's.submitted_by_user_id IS NOT NULL' . $cm;
    }
    // A submission linked to a ticket in a company the reader cannot see still
    // shows (forms have no company), but without the ticket link.
    try {
        $st = $conn->prepare(
            "SELECT s.id, s.form_id, f.title AS form_title, s.submitted_date, s.approval_status, s.ticket_id,
                    t.ticket_number, COALESCE(NULLIF(u.display_name, ''), u.email) AS submitter
               FROM form_submissions s
               JOIN forms f ON f.id = s.form_id
               JOIN users u ON u.id = s.submitted_by_user_id
          LEFT JOIN tickets t ON t.id = s.ticket_id
              WHERE $match
              ORDER BY s.submitted_date DESC
              LIMIT " . PEOPLE_SECTION_LIMIT
        );
        $st->execute($mArgs);
    } catch (Throwable $e) {
        return ['total' => 0, 'rows' => []];
    }
    $canTickets = analystCanAccessModule($conn, $analystId, 'tickets');
    $rows = array_map(function ($r) use ($conn, $analystId, $canTickets) {
        $ticketOk = $r['ticket_id'] !== null && $canTickets && analystCanAccessTicket($conn, $analystId, (int)$r['ticket_id']);
        return [
            'id' => (int)$r['id'], 'form' => $r['form_title'], 'submitted' => $r['submitted_date'],
            'approval' => $r['approval_status'], 'submitter' => $r['submitter'],
            'ticket' => $ticketOk ? $r['ticket_number'] : null,
            'ticket_url' => $ticketOk ? entityLink('ticket', (int)$r['ticket_id']) : null,
            'url' => 'forms/submissions.php?id=' . (int)$r['form_id'],
        ];
    }, $st->fetchAll(PDO::FETCH_ASSOC));
    return ['total' => count($rows), 'rows' => $rows];
}

// ---------------------------------------------------------------------------
//  Suppliers and supplier contacts (#153 step 3, #162)
// ---------------------------------------------------------------------------

/** Suppliers and contacts are Contracts' records: their pages need Contracts too. */
function peopleCanSeeSuppliers(PDO $conn, int $analystId): bool
{
    return analystCanAccessModule($conn, $analystId, 'people') && analystCanAccessModule($conn, $analystId, 'contracts');
}

function peopleSupplierName(array $r): string
{
    $t = trim((string)($r['trading_name'] ?? ''));
    return $t !== '' ? $t : (string)($r['legal_name'] ?? ('#' . (int)($r['id'] ?? 0)));
}

function peopleContactName(array $r): string
{
    $n = trim(($r['first_name'] ?? '') . ' ' . ($r['surname'] ?? ''));
    return $n !== '' ? $n : ($r['email'] ?: '#' . (int)($r['id'] ?? 0));
}

/**
 * Every supplier, with how many contacts and contracts each has.
 * @param array $f q, status ('active' default | 'all')
 */
function peopleSupplierRows(PDO $conn, int $analystId, array $f = []): array
{
    require_once __DIR__ . '/contract_party.php';
    $where = '1=1'; $args = [];
    if (($f['status'] ?? 'active') === 'active') $where .= ' AND s.is_active = 1';
    $q = trim((string)($f['q'] ?? ''));
    if ($q !== '') {
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
        // A supplier is found by one of its contacts' names too.
        $where .= " AND (s.legal_name LIKE ? OR s.trading_name LIKE ? OR s.city LIKE ?
                         OR EXISTS (SELECT 1 FROM contacts c WHERE c.supplier_id = s.id AND (CONCAT(c.first_name, ' ', c.surname) LIKE ? OR c.email LIKE ?)))";
        array_push($args, $like, $like, $like, $like, $like);
    }
    [$vis, $vArgs] = contractVisibilitySql($conn, $analystId, 'k');
    $st = $conn->prepare(
        "SELECT s.id, s.legal_name, s.trading_name, s.city, s.country, s.is_active,
                st.name AS type_name, ss.name AS status_name,
                (SELECT COUNT(*) FROM contacts c WHERE c.supplier_id = s.id AND c.is_active = 1) AS contacts,
                (SELECT GROUP_CONCAT(CONCAT_WS(' ', c.first_name, c.surname, c.email) SEPARATOR ' ') FROM contacts c WHERE c.supplier_id = s.id) AS contact_text,
                (SELECT COUNT(*) FROM contracts k WHERE k.supplier_id = s.id$vis) AS contracts
           FROM suppliers s
      LEFT JOIN supplier_types st ON st.id = s.supplier_type_id
      LEFT JOIN supplier_statuses ss ON ss.id = s.supplier_status_id
          WHERE $where
          ORDER BY COALESCE(NULLIF(s.trading_name, ''), s.legal_name)
          LIMIT 500"
    );
    // The visibility args sit inside the SELECT list, so they come first.
    $st->execute(array_merge($vArgs, $args));
    return array_map(fn($r) => [
        'id' => (int)$r['id'], 'name' => peopleSupplierName($r), 'legal_name' => $r['legal_name'],
        'type' => $r['type_name'], 'status' => $r['status_name'], 'is_active' => (int)$r['is_active'] === 1,
        'place' => implode(', ', array_filter([$r['city'], $r['country']])),
        'contacts' => (int)$r['contacts'], 'contracts' => (int)$r['contracts'],
        'contact_text' => (string)$r['contact_text'],
    ], $st->fetchAll(PDO::FETCH_ASSOC));
}

/** Everything about one supplier this analyst may see, or null. */
function supplierDetail(PDO $conn, int $analystId, int $supplierId): ?array
{
    if ($supplierId <= 0 || !peopleCanSeeSuppliers($conn, $analystId)) return null;
    $st = $conn->prepare(
        "SELECT s.*, st.name AS type_name, ss.name AS status_name
           FROM suppliers s
      LEFT JOIN supplier_types st ON st.id = s.supplier_type_id
      LEFT JOIN supplier_statuses ss ON ss.id = s.supplier_status_id
          WHERE s.id = ?"
    );
    $st->execute([$supplierId]);
    $s = $st->fetch(PDO::FETCH_ASSOC);
    if (!$s) return null;

    $supplier = [
        'id' => (int)$s['id'], 'name' => peopleSupplierName($s),
        'legal_name' => $s['legal_name'], 'trading_name' => $s['trading_name'],
        'reg_number' => $s['reg_number'], 'vat_number' => $s['vat_number'],
        'type' => $s['type_name'], 'status' => $s['status_name'], 'is_active' => (int)$s['is_active'] === 1,
        'address' => array_values(array_filter([$s['address_line_1'], $s['address_line_2'], $s['city'], $s['county'], $s['postcode'], $s['country']])),
        'supplies_assets' => (int)$s['supplies_assets'] === 1,
    ];

    $c = $conn->prepare("SELECT id, first_name, surname, email, mobile, job_title, direct_dial, switchboard, is_active
                           FROM contacts WHERE supplier_id = ? ORDER BY is_active DESC, first_name, surname");
    $c->execute([$supplierId]);
    $sections = ['contacts' => array_map(fn($r) => [
        'id' => (int)$r['id'], 'name' => peopleContactName($r), 'email' => $r['email'], 'job_title' => $r['job_title'],
        'phone' => $r['direct_dial'] ?: ($r['mobile'] ?: $r['switchboard']), 'is_active' => (int)$r['is_active'] === 1,
    ], $c->fetchAll(PDO::FETCH_ASSOC))];

    $can = fn(string $m) => analystCanAccessModule($conn, $analystId, $m);
    $sections['contracts'] = peopleSupplierContracts($conn, $analystId, $supplierId);
    if ($can('assets'))  $sections['assets']  = peopleSupplierAssets($conn, $analystId, $supplierId);
    if ($can('domains')) $sections['domains'] = peopleSupplierDomains($conn, $analystId, ['supplier' => $supplierId]);
    return ['supplier' => $supplier, 'sections' => $sections];
}

/** Everything about one supplier contact this analyst may see, or null. */
function supplierContactDetail(PDO $conn, int $analystId, int $contactId): ?array
{
    if ($contactId <= 0 || !peopleCanSeeSuppliers($conn, $analystId)) return null;
    $st = $conn->prepare(
        "SELECT c.*, s.legal_name, s.trading_name
           FROM contacts c LEFT JOIN suppliers s ON s.id = c.supplier_id
          WHERE c.id = ?"
    );
    $st->execute([$contactId]);
    $c = $st->fetch(PDO::FETCH_ASSOC);
    if (!$c) return null;
    $contact = [
        'id' => (int)$c['id'], 'name' => peopleContactName($c), 'email' => $c['email'], 'job_title' => $c['job_title'],
        'mobile' => $c['mobile'], 'direct_dial' => $c['direct_dial'], 'switchboard' => $c['switchboard'],
        'is_active' => (int)$c['is_active'] === 1, 'created' => $c['created_datetime'],
        'supplier_id' => $c['supplier_id'] !== null ? (int)$c['supplier_id'] : null,
        'supplier' => $c['supplier_id'] !== null ? peopleSupplierName(['id' => $c['supplier_id'], 'legal_name' => $c['legal_name'], 'trading_name' => $c['trading_name']]) : null,
    ];
    $sections = [];
    if (analystCanAccessModule($conn, $analystId, 'domains')) {
        $sections['domains'] = peopleSupplierDomains($conn, $analystId, ['contact' => $contactId]);
    }
    return ['contact' => $contact, 'sections' => $sections];
}

/** Contracts with the supplier - the ordinary, "we buy from them" kind. */
function peopleSupplierContracts(PDO $conn, int $analystId, int $supplierId): array
{
    require_once __DIR__ . '/contract_party.php';
    [$vis, $vArgs] = contractVisibilitySql($conn, $analystId, 'c');
    $st = $conn->prepare(
        "SELECT c.id, c.contract_number, c.title, c.contract_start, c.contract_end, c.notice_date, c.is_active,
                c.contract_value, c.currency, st.name AS status
           FROM contracts c
      LEFT JOIN contract_statuses st ON st.id = c.contract_status_id
          WHERE c.supplier_id = ? $vis
          ORDER BY c.is_active DESC, c.contract_end IS NULL, c.contract_end
          LIMIT " . PEOPLE_SECTION_LIMIT
    );
    $st->execute(array_merge([$supplierId], $vArgs));
    $rows = array_map(fn($r) => [
        'id' => (int)$r['id'], 'number' => $r['contract_number'], 'title' => $r['title'],
        'start' => $r['contract_start'], 'end' => $r['contract_end'], 'notice' => $r['notice_date'],
        'is_active' => (int)$r['is_active'] === 1, 'status' => $r['status'],
        'value' => $r['contract_value'] !== null ? (float)$r['contract_value'] : null, 'currency' => $r['currency'],
        'party' => null, 'url' => entityLink('contract', (int)$r['id']),
    ], $st->fetchAll(PDO::FETCH_ASSOC));
    return ['total' => count($rows), 'rows' => $rows];
}

/** Assets bought from the supplier, in companies the analyst can access. */
function peopleSupplierAssets(PDO $conn, int $analystId, int $supplierId): array
{
    [$scope, $args] = peopleScope($conn, $analystId, 'a.tenant_id');
    $from = "FROM assets a LEFT JOIN asset_types at ON at.id = a.asset_type_id LEFT JOIN asset_status_types ast ON ast.id = a.asset_status_id
             WHERE a.supplier_id = ? $scope";
    $all = array_merge([$supplierId], $args);
    try {
        $c = $conn->prepare("SELECT COUNT(*) $from");
        $c->execute($all);
        $total = (int)$c->fetchColumn();
        $st = $conn->prepare("SELECT a.id, a.hostname, a.manufacturer, a.model, a.service_tag, a.asset_tag, at.name AS asset_type, ast.name AS asset_status,
                                     (SELECT COALESCE(NULLIF(hu.display_name, ''), hu.email) FROM users_assets hua JOIN users hu ON hu.id = hua.user_id WHERE hua.asset_id = a.id ORDER BY hua.id LIMIT 1) AS holder
                              $from ORDER BY at.name, a.hostname LIMIT " . PEOPLE_SECTION_LIMIT);
        $st->execute($all);
    } catch (Throwable $e) {
        return ['total' => 0, 'rows' => []];   // assets.supplier_id arrives with Database Verification
    }
    $rows = array_map(fn($r) => [
        'id' => (int)$r['id'], 'name' => $r['hostname'] ?: ($r['asset_tag'] ?: '#' . $r['id']),
        'type' => $r['asset_type'], 'status' => $r['asset_status'],
        'model' => trim(($r['manufacturer'] ?? '') . ' ' . ($r['model'] ?? '')), 'serial' => $r['service_tag'],
        'tag' => $r['asset_tag'], 'assigned' => null, 'holder' => $r['holder'],
        'url' => entityLink('asset', (int)$r['id']),
    ], $st->fetchAll(PDO::FETCH_ASSOC));
    return ['total' => $total, 'rows' => $rows];
}

/**
 * Domains a supplier or a contact is involved in, and how: registrar, customer,
 * technical contact. $who = ['supplier' => id] or ['contact' => id]. A domain
 * belongs to a company, so only companies the analyst can access count.
 */
function peopleSupplierDomains(PDO $conn, int $analystId, array $who): array
{
    require_once __DIR__ . '/domains/customer.php';
    $parties = domainPartiesReady($conn);
    [$scope, $args] = peopleScope($conn, $analystId, 'd.tenant_id');
    if (isset($who['supplier'])) {
        $id = (int)$who['supplier'];
        $roles = ["(d.registrar_supplier_id = ?)" => 'registrar'];
        if ($parties) $roles["(d.customer_supplier_id = ?)"] = 'customer';
        // Through its contacts: the agency or host named as technical contact.
        $roles["(d.tech_contact_id IN (SELECT id FROM contacts WHERE supplier_id = ?))"] = 'tech';
    } else {
        $id = (int)$who['contact'];
        $roles = ["(d.tech_contact_id = ?)" => 'tech'];
        if ($parties) $roles["(d.customer_contact_id = ?)"] = 'customer';
    }
    $cols = []; $colArgs = [];
    foreach ($roles as $cond => $role) { $cols[] = "$cond AS is_$role"; $colArgs[] = $id; }
    $where = implode(' OR ', array_keys($roles));
    $whereArgs = array_fill(0, count($roles), $id);
    $st = $conn->prepare(
        "SELECT d.id, d.domain_name, d.display_name, d.expiry_date, d.security_grade, s.name AS status, s.colour AS status_colour,
                " . implode(', ', $cols) . "
           FROM domains d LEFT JOIN domain_statuses s ON s.id = d.status_id
          WHERE ($where) $scope
          ORDER BY d.expiry_date IS NULL, d.expiry_date, d.domain_name
          LIMIT " . PEOPLE_SECTION_LIMIT
    );
    $st->execute(array_merge($colArgs, $whereArgs, $args));
    $rows = array_map(function ($r) use ($roles) {
        $as = [];
        foreach ($roles as $role) if (!empty($r['is_' . $role])) $as[] = $role;
        return [
            'id' => (int)$r['id'], 'name' => $r['display_name'] ?: $r['domain_name'], 'domain' => $r['domain_name'],
            'expiry' => $r['expiry_date'], 'grade' => $r['security_grade'], 'status' => $r['status'],
            'status_colour' => $r['status_colour'], 'roles' => array_values(array_unique($as)),
            'url' => entityLink('domain', (int)$r['id']),
        ];
    }, $st->fetchAll(PDO::FETCH_ASSOC));
    return ['total' => count($rows), 'rows' => $rows];
}

/** A bare date (a contract end, a domain expiry, a deadline) - shown as stored, never shifted by a timezone. */
function peopleBareDate(?string $d): string
{
    if ($d === null || $d === '') return '';
    try {
        $dt = new DateTime(substr($d, 0, 10) . ' 00:00:00', new DateTimeZone('UTC'));
        return DateFmt::render($dt, DateFmt::DATE_TEMPLATES[DateFmt::dateKey()]);
    } catch (Throwable $e) {
        return (string)$d;
    }
}
