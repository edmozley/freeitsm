<?php
/**
 * Domains — the reads the screens share: the register list and one domain.
 *
 * Reads live here, not in the service (see the Service-Layer page: writes are
 * unified, reads stay per surface). The REST API has its own serialiser in
 * api/v1/resources/domains.php — a frozen public contract, a different shape.
 *
 * 🔑 SCOPE IN SQL, NOT IN JS. The list is filtered by company in the query
 * (activeTenantReadFilter — one company, or every company the analyst can see
 * in the "All companies" view). A row the analyst may not see is never sent.
 */

require_once __DIR__ . '/../tenancy.php';
require_once __DIR__ . '/customer.php';
require_once __DIR__ . '/subdomains.php';
require_once __DIR__ . '/../cost_centre_assign.php';   // GH #188
require_once __DIR__ . '/settings.php';   // GH #203: scope, health and the interval

/** The columns + joins every register read uses. */
function domainListSelect(?PDO $conn = null): string
{
    // The customer person (#153), once Database Verification has added the column.
    $cust = $conn !== null && domainCustomerReady($conn);
    $custCols = $cust
        ? "d.customer_user_id, COALESCE(NULLIF(cu.display_name, ''), cu.email) AS customer_user_name, cu.email AS customer_user_email,"
        : "NULL AS customer_user_id, NULL AS customer_user_name, NULL AS customer_user_email,";
    $custJoin = $cust ? " LEFT JOIN users cu ON cu.id = d.customer_user_id" : '';
    // #162: a supplier customer (and its contact), and an analyst tech contact.
    if ($conn !== null && domainPartiesReady($conn)) {
        $custCols .= " d.customer_supplier_id, COALESCE(NULLIF(csup.trading_name, ''), csup.legal_name) AS customer_supplier_name,
                       d.customer_contact_id, TRIM(CONCAT(COALESCE(cct.first_name, ''), ' ', COALESCE(cct.surname, ''))) AS customer_contact_name,
                       cct.email AS customer_contact_email,
                       d.tech_analyst_id, ta.full_name AS tech_analyst_name,";
        $custJoin .= " LEFT JOIN suppliers csup ON csup.id = d.customer_supplier_id
                       LEFT JOIN contacts cct ON cct.id = d.customer_contact_id
                       LEFT JOIN analysts ta ON ta.id = d.tech_analyst_id";
    } else {
        $custCols .= " NULL AS customer_supplier_id, NULL AS customer_supplier_name, NULL AS customer_contact_id,
                       NULL AS customer_contact_name, NULL AS customer_contact_email, NULL AS tech_analyst_id, NULL AS tech_analyst_name,";
    }
    // A subdomain's parent, and the soonest certificate recorded by hand - once
    // Database Verification has added them (includes/domains/subdomains.php).
    if ($conn !== null && domainSubdomainsReady($conn)) {
        $custCols .= " d.parent_domain_id, pd.domain_name AS parent_name,";
        $custJoin .= " LEFT JOIN domains pd ON pd.id = d.parent_domain_id";
    } else {
        $custCols .= " NULL AS parent_domain_id, NULL AS parent_name,";
    }
    $custCols .= $conn !== null && domainManualCertsReady($conn)
        ? " (SELECT MIN(mc.expiry_date) FROM domain_manual_certificates mc WHERE mc.domain_id = d.id) AS manual_cert_expiry,"
        : " NULL AS manual_cert_expiry,";
    return "SELECT d.id, d.tenant_id, tn.name AS company_name,
                   d.domain_name, d.display_name, d.purpose,
                   d.status_id, s.name AS status_name, s.colour AS status_colour, s.alerts_enabled,
                   d.registrar_supplier_id, COALESCE(NULLIF(sup.trading_name, ''), sup.legal_name) AS supplier_name,
                   d.registrar_name, d.registrar_account_id, acc.account_name,
                   d.registration_date, d.expiry_date, d.last_renewed_date, d.renewal_mode,
                   d.transfer_lock, d.registry_lock, d.dnssec,
                   d.owner_analyst_id, a.full_name AS owner_name,
                   d.tech_contact_id, TRIM(CONCAT(COALESCE(ct.first_name, ''), ' ', COALESCE(ct.surname, ''))) AS tech_contact_name,
                   d.registrant_name, d.dns_provider, d.hosting_provider,
                   d.tags, d.cost, d.currency, d.billing_years, d.cost_centre" . ($conn !== null ? costCentreSelectSql($conn, 'domains', 'd') : ', NULL AS cost_centre_id, NULL AS cost_centre_label') . ",
                   d.contract_id, d.monitoring_enabled, $custCols
                   d.ssl_expiry_date, d.ssl_issuer, d.security_score, d.security_grade,
                   " . ($conn !== null && domainScopeReady($conn)
                        ? 'd.scope, d.health_status, d.health_completed_datetime, d.health_healthy_datetime, d.health_incomplete,'
                        : "'public' AS scope, NULL AS health_status, NULL AS health_completed_datetime, NULL AS health_healthy_datetime, 0 AS health_incomplete,") . "
                   d.lookup_source, d.last_lookup_datetime, d.last_lookup_error, d.last_check_datetime,
                   d.created_datetime, d.updated_datetime,
                   (SELECT COUNT(*) FROM domain_lookalikes l WHERE l.domain_id = d.id AND l.dismissed = 0) AS lookalike_count,
                   (SELECT COUNT(*) FROM domain_certificates c WHERE c.domain_id = d.id AND c.acknowledged = 0) AS new_certificate_count
              FROM domains d
         LEFT JOIN domain_statuses s ON s.id = d.status_id
         LEFT JOIN suppliers sup ON sup.id = d.registrar_supplier_id
         LEFT JOIN domain_registrar_accounts acc ON acc.id = d.registrar_account_id
         LEFT JOIN analysts a ON a.id = d.owner_analyst_id
         LEFT JOIN contacts ct ON ct.id = d.tech_contact_id
         LEFT JOIN tenants tn ON tn.id = d.tenant_id$custJoin";
}

/**
 * The register, scoped to what this analyst may see.
 *
 * @param array $f optional filters: q, status_id, purpose, owner_analyst_id,
 *                 registrar_supplier_id, expiring_days, attention (bool), tag
 */
function domainListRows(PDO $conn, int $analystId, array $f = []): array
{
    [$tSql, $tArgs] = activeTenantReadFilter($conn, $analystId, 'd');
    $where = ['1=1']; $args = [];
    if (!empty($f['q'])) {
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], trim((string)$f['q'])) . '%';
        $where[] = '(d.domain_name LIKE ? OR d.display_name LIKE ? OR d.registrar_name LIKE ? OR d.tags LIKE ? OR d.notes LIKE ?)';
        array_push($args, $like, $like, $like, $like, $like);
    }
    foreach (['status_id', 'owner_analyst_id', 'registrar_supplier_id', 'registrar_account_id'] as $k) {
        if (isset($f[$k]) && $f[$k] !== '') { $where[] = "d.$k = ?"; $args[] = (int)$f[$k]; }
    }
    if (!empty($f['purpose'])) { $where[] = 'd.purpose = ?'; $args[] = (string)$f['purpose']; }
    if (!empty($f['tag']))     { $where[] = 'CONCAT(", ", LOWER(d.tags), ",") LIKE ?'; $args[] = '%, ' . mb_strtolower(trim((string)$f['tag'])) . ',%'; }
    if (!empty($f['expiring_days'])) {
        $where[] = 'd.expiry_date IS NOT NULL AND d.expiry_date <= DATE_ADD(UTC_DATE(), INTERVAL ? DAY)';
        $args[] = (int)$f['expiring_days'];
    }
    if (!empty($f['scope']) && domainScopeReady($conn)) { $where[] = 'd.scope = ?'; $args[] = (string)$f['scope']; }   // GH #203
    domainShapeInterval(domainInternalInterval(domainSettings($conn)));
    $sql = domainListSelect($conn) . ' WHERE ' . implode(' AND ', $where) . $tSql . ' ORDER BY d.expiry_date IS NULL, d.expiry_date, d.domain_name';
    $st = $conn->prepare($sql);
    $st->execute(array_merge($args, $tArgs));
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    return array_map('domainShapeRow', $rows);
}

/**
 * Types, and the derived numbers every screen wants (days left, attention).
 *
 * @param array $r  one row from domainListSelect(), straight from PDO (strings)
 */
function domainShapeRow(array $r): array
{
    $today = new DateTimeImmutable(gmdate('Y-m-d'));
    $days = fn($d) => $d ? (int)$today->diff(new DateTimeImmutable(substr($d, 0, 10)))->format('%r%a') : null;
    foreach (['id', 'tenant_id', 'status_id', 'registrar_supplier_id', 'registrar_account_id', 'owner_analyst_id',
              'tech_contact_id', 'customer_user_id', 'customer_supplier_id', 'customer_contact_id', 'tech_analyst_id', 'contract_id', 'cost_centre_id', 'security_score', 'billing_years', 'lookalike_count', 'new_certificate_count', 'parent_domain_id'] as $k) {
        if (array_key_exists($k, $r)) $r[$k] = $r[$k] === null ? null : (int)$r[$k];
    }
    foreach (['transfer_lock', 'registry_lock', 'dnssec'] as $k) {
        if (array_key_exists($k, $r)) $r[$k] = $r[$k] === null ? null : (bool)(int)$r[$k];
    }
    foreach (['monitoring_enabled', 'alerts_enabled'] as $k) {
        if (array_key_exists($k, $r)) $r[$k] = $r[$k] === null ? null : (bool)(int)$r[$k];
    }
    $r['tech_contact_name'] = isset($r['tech_contact_name']) && trim($r['tech_contact_name']) !== '' ? trim($r['tech_contact_name']) : null;
    $r['customer_contact_name'] = isset($r['customer_contact_name']) && trim($r['customer_contact_name']) !== '' ? trim($r['customer_contact_name']) : null;
    $r['days_left']     = $days($r['expiry_date'] ?? null);
    $r['ssl_days_left'] = $days($r['ssl_expiry_date'] ?? null);
    $r['manual_cert_days_left'] = $days($r['manual_cert_expiry'] ?? null);
    $r['annual_cost']   = ($r['cost'] ?? null) !== null ? round((float)$r['cost'] / max(1, (int)($r['billing_years'] ?? 1)), 2) : null;
    // GH #203: an internal domain has a health status instead of a grade.
    $r['scope']  = ($r['scope'] ?? '') === 'internal' ? 'internal' : 'public';
    $r['health'] = $r['scope'] === 'internal' ? domainHealth($r, domainShapeInterval()) : null;
    unset($r['health_status'], $r['health_completed_datetime'], $r['health_healthy_datetime'], $r['health_incomplete']);
    $r['attention']     = domainNeedsAttention($r);
    return $r;
}

/**
 * The reasons a domain belongs on the "needs attention" list — one place, so
 * the register filter, the dashboard and Watchtower cannot disagree.
 *
 * @param array $r  a row already through domainShapeRow() (needs days_left and ssl_days_left)
 */
function domainNeedsAttention(array $r): array
{
    $why = [];
    // GH #203: an internal domain has no registration, lock, grade or watches - a
    // value left from when it was public is not something to act on. Its health is.
    if (($r['scope'] ?? '') === 'internal') {
        $state = $r['health']['state'] ?? null;
        if ($state === 'problem' || ($r['health']['known'] ?? null) === 'problem') $why[] = 'health';
        if ($state === 'stale') $why[] = 'stale';
        if ((($r['manual_cert_days_left'] ?? null) !== null && $r['manual_cert_days_left'] <= 14)) $why[] = 'certificate';
        return $why;
    }
    $alerting = !array_key_exists('alerts_enabled', $r) || $r['alerts_enabled'] === null || $r['alerts_enabled'];
    if ($alerting && $r['days_left'] !== null && $r['days_left'] < 0 && ($r['renewal_mode'] ?? '') !== 'do_not_renew') $why[] = 'expired';
    elseif ($alerting && $r['days_left'] !== null && $r['days_left'] <= 30 && ($r['renewal_mode'] ?? '') !== 'do_not_renew') $why[] = 'expiring';
    if (($r['ssl_days_left'] !== null && $r['ssl_days_left'] <= 14)
        || (($r['manual_cert_days_left'] ?? null) !== null && $r['manual_cert_days_left'] <= 14)) $why[] = 'certificate';
    if (($r['transfer_lock'] ?? null) === false) $why[] = 'unlocked';
    if (in_array($r['security_grade'] ?? '', ['D', 'F'], true)) $why[] = 'grade';
    if (!empty($r['lookalike_count'])) $why[] = 'lookalikes';
    if (!empty($r['new_certificate_count'])) $why[] = 'certificates_issued';
    return $why;
}

/**
 * One domain for its page: everything but the auth code's value.
 *
 * @param int $id  the domain's id - access is not checked here, the caller does that
 */
function domainDetail(PDO $conn, int $id, ?int $analystId = null): ?array
{
    $st = $conn->prepare(domainListSelect($conn) . ' WHERE d.id = ?');
    $st->execute([$id]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) return null;
    domainShapeInterval(domainInternalInterval(domainSettings($conn)));
    $r = domainShapeRow($r);

    // A linked CUSTOMER contract the viewer cannot see shows no label (#153).
    require_once __DIR__ . '/../contract_party.php';
    [$kVis, $kArgs] = $analystId !== null ? contractVisibilitySql($conn, $analystId, 'k') : ['', []];
    $x = $conn->prepare("SELECT nameservers, ssl_hosts, dkim_selectors, notes, registry_statuses, registry_updated_date,
                                check_results, auth_code, contract_id,
                                (SELECT CONCAT(k.contract_number, ' - ', k.title) FROM contracts k WHERE k.id = domains.contract_id$kVis) AS contract_label
                           FROM domains WHERE id = ?");
    $x->execute(array_merge($kArgs, [$id]));
    $more = $x->fetch(PDO::FETCH_ASSOC) ?: [];
    $r['nameservers']           = $more['nameservers'] ?? null;
    $r['ssl_hosts']             = $more['ssl_hosts'] ?? null;
    $r['dkim_selectors']        = $more['dkim_selectors'] ?? null;
    $r['notes']                 = $more['notes'] ?? null;
    $r['registry_statuses']     = $more['registry_statuses'] ?? null;
    $r['registry_updated_date'] = $more['registry_updated_date'] ?? null;
    $r['contract_label']        = $more['contract_label'] ?? null;
    // 🔴 The code itself never leaves the server here — only whether one is set.
    $r['auth_code_set']         = !empty($more['auth_code']);
    $checks = !empty($more['check_results']) ? json_decode($more['check_results'], true) : null;
    $r['findings']     = $checks['findings'] ?? [];
    $r['certificates'] = $checks['certificates'] ?? [];
    $r['checked_at']   = $checks['at'] ?? null;
    // GH #188: the cost centres this domain may be charged to - its company's
    // active ones, plus its current one if that has since been switched off.
    // A subdomain's registration is its parent's: shown on its page as such.
    $r['parent'] = null;
    if (!empty($r['parent_domain_id'])) {
        $p = $conn->prepare("SELECT d.id, d.domain_name, d.display_name, d.expiry_date, d.renewal_mode, d.registrar_name,
                                    COALESCE(NULLIF(sup.trading_name, ''), sup.legal_name) AS supplier_name,
                                    d.transfer_lock, d.registry_lock, d.dnssec, d.last_lookup_datetime
                               FROM domains d LEFT JOIN suppliers sup ON sup.id = d.registrar_supplier_id WHERE d.id = ?");
        $p->execute([(int)$r['parent_domain_id']]);
        if ($pr = $p->fetch(PDO::FETCH_ASSOC)) {
            $today = new DateTimeImmutable(gmdate('Y-m-d'));
            $pr['id'] = (int)$pr['id'];
            $pr['days_left'] = $pr['expiry_date'] ? (int)$today->diff(new DateTimeImmutable($pr['expiry_date']))->format('%r%a') : null;
            foreach (['transfer_lock', 'registry_lock', 'dnssec'] as $k) $pr[$k] = $pr[$k] === null ? null : (bool)(int)$pr[$k];
            $r['parent'] = $pr;
        }
    }
    $r['cost_centre_ready']   = costCentreReady($conn, 'domains');
    $r['cost_centre_options'] = $r['cost_centre_ready']
        ? costCentreOptions($conn, costCentreRecordTenant($conn, $r['tenant_id']), $r['cost_centre_id'] ?? null) : [];
    return $r;
}

/** Statuses, owners, registrars, accounts, purposes — for filters and forms. */
function domainApiLookups(PDO $conn, int $analystId): array
{
    require_once __DIR__ . '/names.php';
    [$tSql, $tArgs] = activeTenantReadFilter($conn, $analystId, 'acc');
    require_once __DIR__ . '/../contract_party.php';
    $contracts = [];
    if (analystCanAccessModule($conn, $analystId, 'contracts')) {
        [$kVis, $kArgs] = contractVisibilitySql($conn, $analystId, 'k');   // #153
        $k = $conn->prepare("SELECT k.id, CONCAT(k.contract_number, ' - ', k.title) AS name FROM contracts k WHERE k.is_active = 1$kVis ORDER BY k.contract_number");
        $k->execute($kArgs);
        $contracts = $k->fetchAll(PDO::FETCH_ASSOC);
    }
    $acc = $conn->prepare("SELECT acc.id, acc.account_name, acc.supplier_id, acc.tenant_id FROM domain_registrar_accounts acc WHERE 1=1 $tSql ORDER BY acc.account_name");
    $acc->execute($tArgs);
    return [
        'statuses'   => $conn->query("SELECT id, name, colour, alerts_enabled, is_active FROM domain_statuses ORDER BY display_order, name")->fetchAll(PDO::FETCH_ASSOC),
        'analysts'   => $conn->query("SELECT id, full_name AS name FROM analysts WHERE is_active = 1 ORDER BY full_name")->fetchAll(PDO::FETCH_ASSOC),
        'suppliers'  => $conn->query("SELECT id, COALESCE(NULLIF(trading_name, ''), legal_name) AS name FROM suppliers WHERE is_active = 1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC),
        'accounts'   => $acc->fetchAll(PDO::FETCH_ASSOC),
        'purposes'   => domainPurposes(),
        'renewal_modes' => domainRenewalModes(),
        'companies'  => isMultiTenant($conn) ? array_values(array_filter(getAllTenants($conn, true), fn($t) => analystCanAccessTenant($conn, $analystId, (int)$t['id']))) : [],
        'active_company' => isMultiTenant($conn) ? getActiveTenantId($conn, $analystId) : null,
        // Contracts' own records: only for somebody who could open Contracts —
        // otherwise this list would show contract titles to people the
        // Contracts module deliberately keeps out.
        'contracts'  => $contracts,
        'contacts'   => analystCanAccessModule($conn, $analystId, 'contracts')
            ? $conn->query("SELECT c.id, CONCAT(c.first_name, ' ', c.surname, COALESCE(CONCAT(' (', COALESCE(NULLIF(s.trading_name, ''), s.legal_name), ')'), '')) AS name
                               FROM contacts c LEFT JOIN suppliers s ON s.id = c.supplier_id WHERE c.is_active = 1 ORDER BY c.first_name, c.surname LIMIT 1000")->fetchAll(PDO::FETCH_ASSOC) : [],
        // #162: the edit dialog offers an analyst as technical contact and a
        // supplier as customer only once Database Verification has added the columns.
        'parties_ready' => domainPartiesReady($conn),
    ];
}
