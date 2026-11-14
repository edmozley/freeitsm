<?php
/**
 * FreeITSM REST API v1 — contracts resource (contracts, suppliers, contacts,
 * contract terms). The RFP Builder is deliberately NOT exposed (internal-only
 * by design).
 *
 * Mirrors the module's internal endpoints:
 *   - contract create/update mirror save_contract.php (contract_number +
 *     title mandatory, everything else nullable, is_active default 1);
 *   - supplier create/update mirror save_supplier.php — plus the API can set
 *     `supplies_assets` (the UI never writes it; it's the flag the Assets
 *     module filters suppliers by, so integrations need it);
 *   - contract terms mirror save_contract_terms.php's per-tab upsert;
 *   - supplier delete mirrors delete_supplier.php's explicit nullify-then-
 *     delete; contract delete FIXES the internal endpoint's orphan bug by
 *     removing contract_term_values explicitly (the contracts domain
 *     historically had no foreign keys at all — see changelog #691).
 *
 * Deliberate API improvements, documented: duplicate contract_number on
 * create → 409 (no DB unique exists and the UI allows dupes — machines
 * shouldn't create them); lookup ids validated with friendly 422s; renewal
 * filters mirror the Watchtower/dashboard SQL shapes (expiring_within_days,
 * notice_within_days, expired).
 *
 * Every contract belongs to a COMPANY (3.5.0) - `company` in the payload, NULL
 * stored as the Default company. A key sees only contracts in companies its
 * scope reaches (one not reached is a 404, never a 403, so ids cannot be
 * probed); a new contract lands in `company_id` (checked against the key) or the
 * key's default company; PATCH with `company_id` moves one, refused while it has
 * assets, projects or RFPs in its old company. Contract numbers are unique per
 * company. Who it is WITH is `party_type` / `customer`, set independently. No
 * audit trail exists in the product and none is invented here.
 *
 * Contract / supplier / term WRITES are delegated to ContractsService
 * (includes/services/contracts.php); the read handlers + serializers, the
 * supplier CONTACTS endpoints, and the read-only lookup lists stay here.
 */

require_once dirname(__DIR__, 3) . '/includes/service_context.php';
require_once dirname(__DIR__, 3) . '/includes/services/contracts.php';
require_once dirname(__DIR__, 3) . '/includes/contract_party.php';
require_once dirname(__DIR__, 3) . '/includes/cost_centre_assign.php';
require_once dirname(__DIR__, 3) . '/includes/timezone.php';   // naive_today_sql() — contract_end/notice_date are bare dates (GH #126)

// ---------------------------------------------------------------------------
// Serializers + loaders
// ---------------------------------------------------------------------------

/**
 * The SELECT for contracts, with supplier, owner, status and payment schedule names, the
 * customer and company columns and, once Database Verification has added it, the cost
 * centre.
 */
function apiContractSelect(PDO $conn): string {
    [$partyCols, $partyJoins] = contractPartySql($conn, 'c');
    // After c.*, so its effective tenant_id (NULL read as Default) wins the key.
    [$ownCols, $ownJoins] = contractOwnerSql($conn, 'c');
    $partyCols .= $ownCols;
    $partyJoins .= ' ' . $ownJoins;
    // The cost centre it is charged to (GH #160), once Database Verification has added it.
    if (costCentreReady($conn, 'contracts')) {
        $partyCols .= ", ccx.code AS cost_centre_code, ccx.name AS cost_centre_name";
        $partyJoins .= ' LEFT JOIN cost_centres ccx ON ccx.id = c.cost_centre_id';
    }
    return "SELECT c.*,
                   COALESCE(NULLIF(TRIM(s.trading_name), ''), s.legal_name) AS supplier_name,
                   a.full_name AS owner_name,
                   cs.name AS status_name,
                   ps.name AS payment_schedule_name
                   $partyCols
            FROM contracts c
            LEFT JOIN suppliers s ON s.id = c.supplier_id
            LEFT JOIN analysts a ON a.id = c.contract_owner_id
            LEFT JOIN contract_statuses cs ON cs.id = c.contract_status_id
            LEFT JOIN payment_schedules ps ON ps.id = c.payment_schedule_id
            $partyJoins";
}

/** The key's view of customer contracts (#153): " AND (...)" + its args. */
function apiContractVisibility(PDO $conn, array $apiKey): array {
    return contractVisibilitySqlForScope($conn, $apiKey['company_scope'] ?? null, 'c');
}

/**
 * Shapes a contract row for the API - number, title, company, supplier or customer
 * (company and person), owner, status, dates, value and the rest of the record.
 *
 * @param array $r  the contracts row as apiContractSelect() loads it, with supplier, customer, owner, status and company names
 */
function apiSerializeContract(array $r): array {
    $rel = function ($id, $name) {
        return $id === null ? null : ['id' => (int)$id, 'name' => $name];
    };
    return [
        'id'              => (int)$r['id'],
        'contract_number' => $r['contract_number'],
        'title'           => $r['title'],
        'description'     => $r['description'],
        'company'         => $rel($r['tenant_id'] ?? null, $r['company_name'] ?? null),
        'party_type'      => $r['party_type'] ?? 'supplier',
        'supplier'        => $rel($r['supplier_id'], $r['supplier_name']),
        'customer'        => ($r['party_type'] ?? 'supplier') === 'customer' ? [
            'company' => $rel($r['customer_tenant_id'] ?? null, $r['customer_company_name'] ?? null),
            'person'  => ($r['customer_user_id'] ?? null) === null ? null : [
                'id' => (int)$r['customer_user_id'], 'name' => $r['customer_person_name'] ?? null, 'email' => $r['customer_person_email'] ?? null,
            ],
        ] : null,
        'owner'           => $rel($r['contract_owner_id'], $r['owner_name']),
        'status'          => $rel($r['contract_status_id'], $r['status_name']),
        'payment_schedule' => $rel($r['payment_schedule_id'], $r['payment_schedule_name']),
        'dates'           => [
            'start'              => $r['contract_start'],
            'end'                => $r['contract_end'],
            'notice_date'        => $r['notice_date'],
            'notice_period_days' => $r['notice_period_days'] !== null ? (int)$r['notice_period_days'] : null,
        ],
        'value'           => [
            'amount'   => $r['contract_value'] !== null ? (float)$r['contract_value'] : null,
            'currency' => $r['currency'],
        ],
        // GH #160: a string as it always was - the linked cost centre's CODE, or the
        // free text recorded before 3.5.0 when none is linked yet - plus the link itself.
        'cost_centre'     => !empty($r['cost_centre_id']) ? ($r['cost_centre_code'] ?? null) : $r['cost_centre'],
        'cost_centre_id'  => !empty($r['cost_centre_id']) ? (int)$r['cost_centre_id'] : null,
        'cost_centre_name' => !empty($r['cost_centre_id']) ? ($r['cost_centre_name'] ?? null) : null,
        'dms_link'        => $r['dms_link'],
        'governance'      => [
            'terms_status'              => $r['terms_status'],
            'personal_data_transferred' => $r['personal_data_transferred'] === null ? null : (bool)$r['personal_data_transferred'],
            'dpia_required'             => $r['dpia_required'] === null ? null : (bool)$r['dpia_required'],
            'dpia_completed_date'       => $r['dpia_completed_date'],
            'dpia_dms_link'             => $r['dpia_dms_link'],
        ],
        'is_active'       => (bool)$r['is_active'],
        'created_at'      => apiIsoDate($r['created_datetime']),
    ];
}

/**
 * Loads one contract row the key may see (apiContractVisibility), or answers 404 and stops.
 *
 * @param int $contractId  the contracts id
 */
function apiLoadContract(PDO $conn, int $contractId, array $apiKey): array {
    [$vis, $visArgs] = apiContractVisibility($conn, $apiKey);
    $stmt = $conn->prepare(apiContractSelect($conn) . " WHERE c.id = ?$vis");
    $stmt->execute(array_merge([$contractId], $visArgs));
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        apiError(404, 'not_found', 'Contract not found.');
    }
    return $row;
}

/** The SELECT for suppliers, with type and status names and their numbers of contacts and contracts. */
function apiSupplierSelect(): string {
    return "SELECT s.*,
                   st.name AS type_name,
                   ss.name AS status_name,
                   (SELECT COUNT(*) FROM contacts ct WHERE ct.supplier_id = s.id) AS contact_count,
                   (SELECT COUNT(*) FROM contracts co WHERE co.supplier_id = s.id) AS contract_count
            FROM suppliers s
            LEFT JOIN supplier_types st ON st.id = s.supplier_type_id
            LEFT JOIN supplier_statuses ss ON ss.id = s.supplier_status_id";
}

/**
 * Shapes a supplier row for the API - legal, trading and display name, registration
 * numbers, type, status, address and counts.
 *
 * @param array $r  the suppliers row as apiSupplierSelect() loads it, with type and status names and the counts
 */
function apiSerializeSupplier(array $r): array {
    $rel = function ($id, $name) {
        return $id === null ? null : ['id' => (int)$id, 'name' => $name];
    };
    return [
        'id'           => (int)$r['id'],
        'legal_name'   => $r['legal_name'],
        'trading_name' => $r['trading_name'],
        'display_name' => trim((string)$r['trading_name']) !== '' ? $r['trading_name'] : $r['legal_name'],
        'reg_number'   => $r['reg_number'],
        'vat_number'   => $r['vat_number'],
        'ticket_email' => $r['ticket_email'] ?? null,   // GH #307
        'type'         => $rel($r['supplier_type_id'], $r['type_name']),
        'status'       => $rel($r['supplier_status_id'], $r['status_name']),
        'address'      => [
            'line_1'   => $r['address_line_1'],
            'line_2'   => $r['address_line_2'],
            'city'     => $r['city'],
            'county'   => $r['county'],
            'postcode' => $r['postcode'],
            'country'  => $r['country'],
        ],
        'questionnaire' => [
            'date_issued'   => $r['questionnaire_date_issued'],
            'date_received' => $r['questionnaire_date_received'],
        ],
        'comments'        => $r['comments'],
        'supplies_assets' => (bool)$r['supplies_assets'],
        'is_active'       => (bool)$r['is_active'],
        'contact_count'   => (int)($r['contact_count'] ?? 0),
        'contract_count'  => (int)($r['contract_count'] ?? 0),
        'created_at'      => apiIsoDate($r['created_datetime']),
    ];
}

/**
 * Loads one supplier row (apiSupplierSelect), or answers 404 and stops.
 *
 * @param int $supplierId  the suppliers id
 */
function apiLoadSupplier(PDO $conn, int $supplierId): array {
    $stmt = $conn->prepare(apiSupplierSelect() . " WHERE s.id = ?");
    $stmt->execute([$supplierId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        apiError(404, 'not_found', 'Supplier not found.');
    }
    return $row;
}

// ---------------------------------------------------------------------------
// GET /contracts
// ---------------------------------------------------------------------------
function apiContractsList(PDO $conn, array $apiKey, array $params, array $body): void {
    $where = ['1=1'];
    $args  = [];

    foreach ([
        'supplier_id'         => 'c.supplier_id',
        'contract_status_id'  => 'c.contract_status_id',
        'contract_owner_id'   => 'c.contract_owner_id',
        'payment_schedule_id' => 'c.payment_schedule_id',
    ] as $param => $col) {
        if (isset($_GET[$param]) && $_GET[$param] !== '') {
            $where[] = "$col = ?";
            $args[]  = (int)$_GET[$param];
        }
    }
    if (isset($_GET['is_active']) && $_GET['is_active'] !== '') {
        $where[] = 'c.is_active = ?';
        $args[]  = $_GET['is_active'] === 'true' ? 1 : 0;
    }
    $partyReady = contractPartyReady($conn);
    if (isset($_GET['party_type']) && $_GET['party_type'] !== '') {
        if (!in_array($_GET['party_type'], ['supplier', 'customer'], true)) {
            apiError(400, 'invalid_parameter', "party_type must be 'supplier' or 'customer'.");
        }
        if ($partyReady) {
            $where[] = 'c.party_type = ?';
            $args[]  = $_GET['party_type'];
        } elseif ($_GET['party_type'] === 'customer') {
            $where[] = '1=0';   // no customer contracts before Database Verification
        }
    }
    if (isset($_GET['company_id']) && $_GET['company_id'] !== '') {
        $cid = (int)$_GET['company_id'];
        if (!apiKeyCanAccessTenant($conn, $apiKey, $cid)) apiError(403, 'forbidden', 'This key cannot access that company.');
        if (contractTenantReady($conn)) {
            $where[] = 'COALESCE(c.tenant_id, ?) = ?';
            array_push($args, getDefaultTenantId($conn), $cid);
        }
    }
    foreach (['customer_company_id' => 'c.customer_tenant_id', 'customer_user_id' => 'c.customer_user_id'] as $param => $col) {
        if (isset($_GET[$param]) && $_GET[$param] !== '') {
            $where[] = $partyReady ? "$col = ?" : '1=0';
            if ($partyReady) $args[] = (int)$_GET[$param];
        }
    }
    if (isset($_GET['q']) && trim($_GET['q']) !== '') {
        // Mirrors get_contracts.php: number, title, supplier and customer names.
        $like = '%' . trim($_GET['q']) . '%';
        if ($partyReady) {
            $where[] = '(c.contract_number LIKE ? OR c.title LIKE ? OR s.legal_name LIKE ? OR s.trading_name LIKE ? OR cpt.name LIKE ? OR cpu.display_name LIKE ? OR cpu.email LIKE ?)';
            array_push($args, $like, $like, $like, $like, $like, $like, $like);
        } else {
            $where[] = '(c.contract_number LIKE ? OR c.title LIKE ? OR s.legal_name LIKE ?)';
            array_push($args, $like, $like, $like);
        }
    }
    // Renewal shapes — the Watchtower/dashboard windows as parameters.
    // ⚠️ naive_today_sql(), not CURDATE(): contract_end and notice_date are BARE
    // DATES with no zone, and CURDATE() is the UTC date now that the connection is
    // pinned (GH #126) — so "expiring within 30 days" would answer to a calendar
    // an hour ahead of the one the dates were typed against.
    $today = naive_today_sql();
    if (isset($_GET['expiring_within_days']) && $_GET['expiring_within_days'] !== '') {
        $where[] = "c.is_active = 1 AND c.contract_end BETWEEN $today AND DATE_ADD($today, INTERVAL ? DAY)";
        $args[]  = max(0, (int)$_GET['expiring_within_days']);
    }
    if (($_GET['expired'] ?? '') === 'true') {
        $where[] = "c.contract_end IS NOT NULL AND c.contract_end < $today";
    }
    if (isset($_GET['notice_within_days']) && $_GET['notice_within_days'] !== '') {
        $where[] = "c.is_active = 1 AND c.notice_date IS NOT NULL AND c.notice_date BETWEEN $today AND DATE_ADD($today, INTERVAL ? DAY)";
        $args[]  = max(0, (int)$_GET['notice_within_days']);
    }
    foreach (['ends_before' => ['c.contract_end', '<='], 'ends_after' => ['c.contract_end', '>=']] as $param => [$col, $op]) {
        if (isset($_GET[$param]) && $_GET[$param] !== '') {
            $where[] = "$col $op ?";
            $args[]  = apiParseDateOnly($_GET[$param], $param);
        }
    }

    $sortable = [
        'contract_end' => 'c.contract_end', 'contract_start' => 'c.contract_start',
        'created_at' => 'c.created_datetime', 'title' => 'c.title',
        'contract_number' => 'c.contract_number', 'contract_value' => 'c.contract_value', 'id' => 'c.id',
    ];
    $sortParam = trim($_GET['sort'] ?? 'contract_end');
    $desc = strncmp($sortParam, '-', 1) === 0;
    $sortKey = ltrim($sortParam, '-');
    if (!isset($sortable[$sortKey])) {
        apiError(400, 'invalid_parameter', "Unknown sort field '{$sortKey}'. Sortable: " . implode(', ', array_keys($sortable)));
    }
    $orderSql = $sortable[$sortKey] . ($desc ? ' DESC' : ' ASC');

    [$page, $perPage, $offset] = apiPagination();
    [$vis, $visArgs] = apiContractVisibility($conn, $apiKey);
    $whereSql = implode(' AND ', $where) . $vis;
    $args = array_merge($args, $visArgs);
    [, $partyJoins] = contractPartySql($conn, 'c');

    $countStmt = $conn->prepare("SELECT COUNT(*) FROM contracts c LEFT JOIN suppliers s ON s.id = c.supplier_id $partyJoins WHERE $whereSql");
    $countStmt->execute($args);
    $total = (int)$countStmt->fetchColumn();

    $stmt = $conn->prepare(apiContractSelect($conn) . " WHERE $whereSql ORDER BY $orderSql LIMIT $perPage OFFSET $offset");
    $stmt->execute($args);
    apiRespond(array_map('apiSerializeContract', $stmt->fetchAll(PDO::FETCH_ASSOC)), 200, [
        'page'        => $page,
        'per_page'    => $perPage,
        'total'       => $total,
        'total_pages' => (int)ceil($total / $perPage),
    ]);
}

/** GET /contracts/{id} - one contract. */
function apiContractsGet(PDO $conn, array $apiKey, array $params, array $body): void {
    apiRespond(apiSerializeContract(apiLoadContract($conn, $params[0], $apiKey)));
}

// ---------------------------------------------------------------------------
// POST /contracts
// ---------------------------------------------------------------------------
/**
 * `company_id` from a request body as the service's tenant_id: checked to exist
 * and to be in the key's reach (403), or - on a create with none - the key's
 * default company. On an update with none, nothing: the contract stays put.
 *
 * @param bool $creating  true for a create, where a missing company_id falls back to the key's default company
 */
function apiContractCompany(PDO $conn, array $apiKey, array $body, bool $creating): array {
    $raw = $body['company_id'] ?? null;
    unset($body['company_id'], $body['tenant_id']);
    if ($raw !== null && $raw !== '') {
        $tid = (int)$raw;
        if (!getTenantById($conn, $tid)) apiError(422, 'invalid_field', "Unknown company id: {$tid}");
        if (!apiKeyCanAccessTenant($conn, $apiKey, $tid)) apiError(403, 'forbidden', 'This key cannot put contracts in that company.');
        $body['tenant_id'] = $tid;
    } elseif ($creating) {
        $body['tenant_id'] = apiKeyDefaultTenantId($conn, $apiKey);
    }
    return $body;
}

/**
 * POST /contracts - creates a contract through ContractsService in the company named (or
 * the key's default) and returns it.
 */
function apiContractsCreate(PDO $conn, array $apiKey, array $params, array $body): void {
    $body = apiContractCompany($conn, $apiKey, $body, true);
    try {
        $res = ContractsService::saveContract($conn, ActorContext::fromApiKey($apiKey), $body);
        apiRespond(apiSerializeContract(apiLoadContract($conn, $res['id'], $apiKey)), 201);
    } catch (ServiceError $e) { apiFailFromService($e); }
}

// ---------------------------------------------------------------------------
// PATCH /contracts/{id}
// ---------------------------------------------------------------------------
function apiContractsUpdate(PDO $conn, array $apiKey, array $params, array $body): void {
    $body = apiContractCompany($conn, $apiKey, $body, false);
    try {
        $res = ContractsService::saveContract($conn, ActorContext::fromApiKey($apiKey), array_merge($body, ['id' => (int)$params[0]]));
        apiRespond(apiSerializeContract(apiLoadContract($conn, $res['id'], $apiKey)));
    } catch (ServiceError $e) { apiFailFromService($e); }
}

// ---------------------------------------------------------------------------
// DELETE /contracts/{id} — explicit children (the contracts domain had no FKs)
// ---------------------------------------------------------------------------
function apiContractsDelete(PDO $conn, array $apiKey, array $params, array $body): void {
    try {
        ContractsService::deleteContract($conn, ActorContext::fromApiKey($apiKey), (int)$params[0]);
        apiRespond(['id' => $params[0], 'deleted' => true]);
    } catch (ServiceError $e) { apiFailFromService($e); }
}

// ---------------------------------------------------------------------------
// Contract terms — GET + bulk upsert (mirrors save_contract_terms.php)
// ---------------------------------------------------------------------------
function apiContractTermsGet(PDO $conn, array $apiKey, array $params, array $body): void {
    apiLoadContract($conn, $params[0], $apiKey);
    $stmt = $conn->prepare(
        "SELECT t.id AS term_tab_id, t.name, v.content, v.updated_datetime
         FROM contract_term_tabs t
         LEFT JOIN contract_term_values v ON v.term_tab_id = t.id AND v.contract_id = ?
         WHERE t.is_active = 1 ORDER BY t.display_order, t.name"
    );
    $stmt->execute([$params[0]]);
    apiRespond(array_map(function ($t) {
        return [
            'term_tab_id' => (int)$t['term_tab_id'],
            'name'        => $t['name'],
            'content'     => $t['content'],
            'updated_at'  => apiIsoDate($t['updated_datetime']),
        ];
    }, $stmt->fetchAll(PDO::FETCH_ASSOC)));
}

/**
 * POST /contracts/{id}/terms - saves the contract's terms through ContractsService and
 * returns them.
 */
function apiContractTermsSave(PDO $conn, array $apiKey, array $params, array $body): void {
    try {
        ContractsService::saveTerms($conn, ActorContext::fromApiKey($apiKey), (int)$params[0], $body['terms'] ?? null);
    } catch (ServiceError $e) { apiFailFromService($e); return; }
    apiContractTermsGet($conn, $apiKey, $params, $body);
}

// ---------------------------------------------------------------------------
// Suppliers — full CRUD (GET /suppliers stays the lite reference list)
// ---------------------------------------------------------------------------
function apiSuppliersGet(PDO $conn, array $apiKey, array $params, array $body): void {
    $supplier = apiSerializeSupplier(apiLoadSupplier($conn, $params[0]));
    $c = $conn->prepare(
        "SELECT id, first_name, surname, email, mobile, job_title, direct_dial, switchboard, is_active
         FROM contacts WHERE supplier_id = ? ORDER BY surname, first_name"
    );
    $c->execute([$params[0]]);
    $supplier['contacts'] = array_map('apiSerializeContact', $c->fetchAll(PDO::FETCH_ASSOC));
    apiRespond($supplier);
}

/** POST /suppliers - creates a supplier through ContractsService and returns it. */
function apiSuppliersCreate(PDO $conn, array $apiKey, array $params, array $body): void {
    try {
        $res = ContractsService::saveSupplier($conn, ActorContext::fromApiKey($apiKey), $body);
        apiRespond(apiSerializeSupplier(apiLoadSupplier($conn, $res['id'])), 201);
    } catch (ServiceError $e) { apiFailFromService($e); }
}

/** PATCH /suppliers/{id} - updates a supplier through ContractsService and returns it. */
function apiSuppliersUpdate(PDO $conn, array $apiKey, array $params, array $body): void {
    try {
        $res = ContractsService::saveSupplier($conn, ActorContext::fromApiKey($apiKey), array_merge($body, ['id' => (int)$params[0]]));
        apiRespond(apiSerializeSupplier(apiLoadSupplier($conn, $res['id'])));
    } catch (ServiceError $e) { apiFailFromService($e); }
}

/** DELETE /suppliers/{id} — mirrors delete_supplier.php's explicit nullify-then-delete. */
function apiSuppliersDelete(PDO $conn, array $apiKey, array $params, array $body): void {
    try {
        ContractsService::deleteSupplier($conn, ActorContext::fromApiKey($apiKey), (int)$params[0]);
        apiRespond(['id' => $params[0], 'deleted' => true]);
    } catch (ServiceError $e) { apiFailFromService($e); }
}

// ---------------------------------------------------------------------------
// Supplier contacts
// ---------------------------------------------------------------------------
// @param array $c  a contacts row (a supplier's contact person)
function apiSerializeContact(array $c): array {
    return [
        'id'          => (int)$c['id'],
        'first_name'  => $c['first_name'],
        'surname'     => $c['surname'],
        'email'       => $c['email'],
        'mobile'      => $c['mobile'],
        'job_title'   => $c['job_title'],
        'direct_dial' => $c['direct_dial'],
        'switchboard' => $c['switchboard'],
        'is_active'   => (bool)$c['is_active'],
    ];
}

/** GET /suppliers/{id}/contacts - the supplier's contacts, by surname. */
function apiSupplierContactsList(PDO $conn, array $apiKey, array $params, array $body): void {
    apiLoadSupplier($conn, $params[0]);
    $stmt = $conn->prepare(
        "SELECT id, first_name, surname, email, mobile, job_title, direct_dial, switchboard, is_active
         FROM contacts WHERE supplier_id = ? ORDER BY surname, first_name"
    );
    $stmt->execute([$params[0]]);
    apiRespond(array_map('apiSerializeContact', $stmt->fetchAll(PDO::FETCH_ASSOC)));
}

/**
 * POST /suppliers/{id}/contacts - adds a contact to the supplier. First name and surname
 * are required.
 */
function apiSupplierContactsCreate(PDO $conn, array $apiKey, array $params, array $body): void {
    apiLoadSupplier($conn, $params[0]);
    $firstName = trim((string)($body['first_name'] ?? ''));
    $surname   = trim((string)($body['surname'] ?? ''));
    if ($firstName === '' || $surname === '') {
        apiError(422, 'missing_field', "'first_name' and 'surname' are required.");
    }
    $str = function ($key) use ($body) {
        $v = trim((string)($body[$key] ?? ''));
        return $v !== '' ? $v : null;
    };
    $conn->prepare(
        "INSERT INTO contacts (supplier_id, first_name, surname, email, mobile, job_title, direct_dial, switchboard, is_active, created_datetime)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())"
    )->execute([
        $params[0], $firstName, $surname, $str('email'), $str('mobile'),
        $str('job_title'), $str('direct_dial'), $str('switchboard'),
        isset($body['is_active']) ? (int)(bool)$body['is_active'] : 1,
    ]);
    $id = (int)$conn->lastInsertId();
    $get = $conn->prepare("SELECT id, first_name, surname, email, mobile, job_title, direct_dial, switchboard, is_active FROM contacts WHERE id = ?");
    $get->execute([$id]);
    apiRespond(apiSerializeContact($get->fetch(PDO::FETCH_ASSOC)), 201);
}

/**
 * PATCH /suppliers/{id}/contacts/{contactId} - updates the fields sent, keeping the
 * rest. First name and surname cannot be emptied.
 */
function apiSupplierContactsUpdate(PDO $conn, array $apiKey, array $params, array $body): void {
    [$supplierId, $contactId] = $params;
    apiLoadSupplier($conn, $supplierId);
    $stmt = $conn->prepare("SELECT * FROM contacts WHERE id = ? AND supplier_id = ?");
    $stmt->execute([$contactId, $supplierId]);
    $current = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$current) {
        apiError(404, 'not_found', 'Contact not found for this supplier.');
    }
    $get = function (string $key) use ($body, $current) {
        return array_key_exists($key, $body) ? $body[$key] : $current[$key];
    };
    $firstName = trim((string)$get('first_name'));
    $surname   = trim((string)$get('surname'));
    if ($firstName === '' || $surname === '') {
        apiError(422, 'invalid_field', "'first_name' and 'surname' cannot be empty.");
    }
    $str = function ($key) use ($get) {
        $v = trim((string)($get($key) ?? ''));
        return $v !== '' ? $v : null;
    };
    $conn->prepare(
        "UPDATE contacts SET first_name=?, surname=?, email=?, mobile=?, job_title=?, direct_dial=?, switchboard=?, is_active=? WHERE id=?"
    )->execute([
        $firstName, $surname, $str('email'), $str('mobile'), $str('job_title'),
        $str('direct_dial'), $str('switchboard'), $get('is_active') ? 1 : 0, $contactId,
    ]);
    $out = $conn->prepare("SELECT id, first_name, surname, email, mobile, job_title, direct_dial, switchboard, is_active FROM contacts WHERE id = ?");
    $out->execute([$contactId]);
    apiRespond(apiSerializeContact($out->fetch(PDO::FETCH_ASSOC)));
}

/** DELETE /suppliers/{id}/contacts/{contactId} - deletes one of the supplier's contacts. */
function apiSupplierContactsDelete(PDO $conn, array $apiKey, array $params, array $body): void {
    [$supplierId, $contactId] = $params;
    apiLoadSupplier($conn, $supplierId);
    $stmt = $conn->prepare("DELETE FROM contacts WHERE id = ? AND supplier_id = ?");
    $stmt->execute([$contactId, $supplierId]);
    if ($stmt->rowCount() === 0) {
        apiError(404, 'not_found', 'Contact not found for this supplier.');
    }
    apiRespond(['id' => $contactId, 'deleted' => true]);
}

// ---------------------------------------------------------------------------
// Reference lookups
// ---------------------------------------------------------------------------
// @param string $table  the lookup table to list (contract_statuses, payment_schedules ...) - fixed by the caller
function apiContractLookupList(PDO $conn, string $table): void {
    $rows = $conn->query(
        "SELECT id, name, description, is_active FROM `$table` ORDER BY display_order, name"
    )->fetchAll(PDO::FETCH_ASSOC);
    apiRespond(array_map(function ($r) {
        return [
            'id'          => (int)$r['id'],
            'name'        => $r['name'],
            'description' => $r['description'],
            'is_active'   => (bool)$r['is_active'],
        ];
    }, $rows));
}

/** GET /contract-statuses - the contract statuses. */
function apiContractStatusesList(PDO $conn, array $apiKey, array $params, array $body): void {
    apiContractLookupList($conn, 'contract_statuses');
}
/** GET /payment-schedules - the payment schedules. */
function apiPaymentSchedulesList(PDO $conn, array $apiKey, array $params, array $body): void {
    apiContractLookupList($conn, 'payment_schedules');
}
/** GET /supplier-types - the supplier types. */
function apiSupplierTypesList(PDO $conn, array $apiKey, array $params, array $body): void {
    apiContractLookupList($conn, 'supplier_types');
}
/** GET /supplier-statuses - the supplier statuses. */
function apiSupplierStatusesList(PDO $conn, array $apiKey, array $params, array $body): void {
    apiContractLookupList($conn, 'supplier_statuses');
}
/** GET /contract-term-tabs - the contract term tabs. */
function apiContractTermTabsList(PDO $conn, array $apiKey, array $params, array $body): void {
    apiContractLookupList($conn, 'contract_term_tabs');
}
