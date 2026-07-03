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
 * Contracts are install-wide (no tenant_id — matches the UI). No audit trail
 * exists in the product and none is invented here.
 */

// ---------------------------------------------------------------------------
// Serializers + loaders
// ---------------------------------------------------------------------------

function apiContractSelect(): string {
    return "SELECT c.*,
                   COALESCE(NULLIF(TRIM(s.trading_name), ''), s.legal_name) AS supplier_name,
                   a.full_name AS owner_name,
                   cs.name AS status_name,
                   ps.name AS payment_schedule_name
            FROM contracts c
            LEFT JOIN suppliers s ON s.id = c.supplier_id
            LEFT JOIN analysts a ON a.id = c.contract_owner_id
            LEFT JOIN contract_statuses cs ON cs.id = c.contract_status_id
            LEFT JOIN payment_schedules ps ON ps.id = c.payment_schedule_id";
}

function apiSerializeContract(array $r): array {
    $rel = function ($id, $name) {
        return $id === null ? null : ['id' => (int)$id, 'name' => $name];
    };
    return [
        'id'              => (int)$r['id'],
        'contract_number' => $r['contract_number'],
        'title'           => $r['title'],
        'description'     => $r['description'],
        'supplier'        => $rel($r['supplier_id'], $r['supplier_name']),
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
        'cost_centre'     => $r['cost_centre'],
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

function apiLoadContract(PDO $conn, int $contractId): array {
    $stmt = $conn->prepare(apiContractSelect() . " WHERE c.id = ?");
    $stmt->execute([$contractId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        apiError(404, 'not_found', 'Contract not found.');
    }
    return $row;
}

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

function apiLoadSupplier(PDO $conn, int $supplierId): array {
    $stmt = $conn->prepare(apiSupplierSelect() . " WHERE s.id = ?");
    $stmt->execute([$supplierId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        apiError(404, 'not_found', 'Supplier not found.');
    }
    return $row;
}

/** Validate a nullable lookup id against a table; 422 with the label on unknown. */
function apiContractLookup(PDO $conn, string $table, $value, string $label): ?int {
    if ($value === null || $value === '') {
        return null;
    }
    $id = (int)$value;
    $stmt = $conn->prepare("SELECT id FROM `$table` WHERE id = ?");
    $stmt->execute([$id]);
    if (!$stmt->fetchColumn()) {
        apiError(422, 'invalid_field', "Unknown {$label} id: {$id}");
    }
    return $id;
}

/** Collect + validate contract fields from a request body (create or patch). */
function apiContractFieldsFromBody(PDO $conn, array $body, array $current = []): array {
    $get = function (string $key, $default = null) use ($body, $current) {
        if (array_key_exists($key, $body)) {
            return $body[$key];
        }
        return $current[$key] ?? $default;
    };

    $f = [];
    $f['contract_number'] = trim((string)$get('contract_number', ''));
    $f['title']           = trim((string)$get('title', ''));
    if ($f['contract_number'] === '') {
        apiError(422, 'missing_field', "'contract_number' is required.");
    }
    if ($f['title'] === '') {
        apiError(422, 'missing_field', "'title' is required.");
    }
    $f['description'] = ($v = trim((string)$get('description', ''))) !== '' ? $v : null;

    $f['supplier_id']         = apiContractLookup($conn, 'suppliers', $get('supplier_id'), 'supplier');
    $f['contract_status_id']  = apiContractLookup($conn, 'contract_statuses', $get('contract_status_id'), 'contract status');
    $f['payment_schedule_id'] = apiContractLookup($conn, 'payment_schedules', $get('payment_schedule_id'), 'payment schedule');
    $f['contract_owner_id']   = null;
    if ($get('contract_owner_id') !== null && $get('contract_owner_id') !== '') {
        $f['contract_owner_id'] = (int)$get('contract_owner_id');
        apiResolveAnalyst($conn, $f['contract_owner_id']);
    }

    foreach (['contract_start', 'contract_end', 'notice_date', 'dpia_completed_date'] as $d) {
        $f[$d] = apiParseDateOnly($get($d), $d);
    }
    $f['notice_period_days'] = null;
    if ($get('notice_period_days') !== null && $get('notice_period_days') !== '') {
        $f['notice_period_days'] = max(0, (int)$get('notice_period_days'));
    }

    $f['contract_value'] = null;
    if ($get('contract_value') !== null && $get('contract_value') !== '') {
        if (!is_numeric($get('contract_value'))) {
            apiError(422, 'invalid_field', "'contract_value' must be a number.");
        }
        $f['contract_value'] = (string)round((float)$get('contract_value'), 2);
    }
    $f['currency'] = null;
    if ($get('currency') !== null && trim((string)$get('currency')) !== '') {
        $cur = strtoupper(trim((string)$get('currency')));
        if (!preg_match('/^[A-Z]{3}$/', $cur)) {
            apiError(422, 'invalid_field', "'currency' must be a 3-letter code, e.g. GBP.");
        }
        $f['currency'] = $cur;
    }

    $f['cost_centre']  = ($v = trim((string)$get('cost_centre', ''))) !== '' ? $v : null;
    $f['dms_link']     = ($v = trim((string)$get('dms_link', ''))) !== '' ? $v : null;
    $f['terms_status'] = ($v = trim((string)$get('terms_status', ''))) !== '' ? $v : null;
    $f['personal_data_transferred'] = $get('personal_data_transferred') === null ? null : (int)(bool)$get('personal_data_transferred');
    $f['dpia_required']             = $get('dpia_required') === null ? null : (int)(bool)$get('dpia_required');
    $f['dpia_dms_link'] = ($v = trim((string)$get('dpia_dms_link', ''))) !== '' ? $v : null;
    $f['is_active']     = $get('is_active', 1) ? 1 : 0;

    return $f;
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
    if (isset($_GET['q']) && trim($_GET['q']) !== '') {
        // Mirrors get_contracts.php: number, title and supplier legal name.
        $where[] = '(c.contract_number LIKE ? OR c.title LIKE ? OR s.legal_name LIKE ?)';
        $like = '%' . trim($_GET['q']) . '%';
        array_push($args, $like, $like, $like);
    }
    // Renewal shapes — the Watchtower/dashboard windows as parameters.
    if (isset($_GET['expiring_within_days']) && $_GET['expiring_within_days'] !== '') {
        $where[] = 'c.is_active = 1 AND c.contract_end BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL ? DAY)';
        $args[]  = max(0, (int)$_GET['expiring_within_days']);
    }
    if (($_GET['expired'] ?? '') === 'true') {
        $where[] = 'c.contract_end IS NOT NULL AND c.contract_end < CURDATE()';
    }
    if (isset($_GET['notice_within_days']) && $_GET['notice_within_days'] !== '') {
        $where[] = 'c.is_active = 1 AND c.notice_date IS NOT NULL AND c.notice_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL ? DAY)';
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
    $whereSql = implode(' AND ', $where);

    $countStmt = $conn->prepare("SELECT COUNT(*) FROM contracts c LEFT JOIN suppliers s ON s.id = c.supplier_id WHERE $whereSql");
    $countStmt->execute($args);
    $total = (int)$countStmt->fetchColumn();

    $stmt = $conn->prepare(apiContractSelect() . " WHERE $whereSql ORDER BY $orderSql LIMIT $perPage OFFSET $offset");
    $stmt->execute($args);
    apiRespond(array_map('apiSerializeContract', $stmt->fetchAll(PDO::FETCH_ASSOC)), 200, [
        'page'        => $page,
        'per_page'    => $perPage,
        'total'       => $total,
        'total_pages' => (int)ceil($total / $perPage),
    ]);
}

function apiContractsGet(PDO $conn, array $apiKey, array $params, array $body): void {
    apiRespond(apiSerializeContract(apiLoadContract($conn, $params[0])));
}

// ---------------------------------------------------------------------------
// POST /contracts
// ---------------------------------------------------------------------------
function apiContractsCreate(PDO $conn, array $apiKey, array $params, array $body): void {
    $f = apiContractFieldsFromBody($conn, $body);

    // No DB unique exists on contract_number (and the UI allows duplicates) —
    // but machines shouldn't create them, so the API refuses. Documented.
    $dup = $conn->prepare("SELECT id FROM contracts WHERE contract_number = ?");
    $dup->execute([$f['contract_number']]);
    $existing = $dup->fetchColumn();
    if ($existing !== false) {
        apiError(409, 'conflict', "A contract with this contract_number already exists (id {$existing}).");
    }

    $ins = $conn->prepare(
        "INSERT INTO contracts (contract_number, title, description, supplier_id, contract_owner_id,
            contract_status_id, contract_start, contract_end, notice_period_days, notice_date,
            contract_value, currency, payment_schedule_id, cost_centre, dms_link, terms_status,
            personal_data_transferred, dpia_required, dpia_completed_date, dpia_dms_link,
            is_active, created_datetime)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())"
    );
    $ins->execute([
        $f['contract_number'], $f['title'], $f['description'], $f['supplier_id'], $f['contract_owner_id'],
        $f['contract_status_id'], $f['contract_start'], $f['contract_end'], $f['notice_period_days'], $f['notice_date'],
        $f['contract_value'], $f['currency'], $f['payment_schedule_id'], $f['cost_centre'], $f['dms_link'], $f['terms_status'],
        $f['personal_data_transferred'], $f['dpia_required'], $f['dpia_completed_date'], $f['dpia_dms_link'],
        $f['is_active'],
    ]);

    apiRespond(apiSerializeContract(apiLoadContract($conn, (int)$conn->lastInsertId())), 201);
}

// ---------------------------------------------------------------------------
// PATCH /contracts/{id}
// ---------------------------------------------------------------------------
function apiContractsUpdate(PDO $conn, array $apiKey, array $params, array $body): void {
    $current = apiLoadContract($conn, $params[0]);
    if (!$body) {
        apiError(422, 'missing_field', 'No fields to update.');
    }
    $f = apiContractFieldsFromBody($conn, $body, $current);

    if ($f['contract_number'] !== $current['contract_number']) {
        $dup = $conn->prepare("SELECT id FROM contracts WHERE contract_number = ? AND id != ?");
        $dup->execute([$f['contract_number'], $params[0]]);
        if ($dup->fetchColumn()) {
            apiError(409, 'conflict', 'Another contract already uses this contract_number.');
        }
    }

    $upd = $conn->prepare(
        "UPDATE contracts SET contract_number=?, title=?, description=?, supplier_id=?, contract_owner_id=?,
            contract_status_id=?, contract_start=?, contract_end=?, notice_period_days=?, notice_date=?,
            contract_value=?, currency=?, payment_schedule_id=?, cost_centre=?, dms_link=?, terms_status=?,
            personal_data_transferred=?, dpia_required=?, dpia_completed_date=?, dpia_dms_link=?, is_active=?
         WHERE id=?"
    );
    $upd->execute([
        $f['contract_number'], $f['title'], $f['description'], $f['supplier_id'], $f['contract_owner_id'],
        $f['contract_status_id'], $f['contract_start'], $f['contract_end'], $f['notice_period_days'], $f['notice_date'],
        $f['contract_value'], $f['currency'], $f['payment_schedule_id'], $f['cost_centre'], $f['dms_link'], $f['terms_status'],
        $f['personal_data_transferred'], $f['dpia_required'], $f['dpia_completed_date'], $f['dpia_dms_link'],
        $f['is_active'], $params[0],
    ]);

    apiRespond(apiSerializeContract(apiLoadContract($conn, $params[0])));
}

// ---------------------------------------------------------------------------
// DELETE /contracts/{id} — explicit children (the contracts domain had no FKs)
// ---------------------------------------------------------------------------
function apiContractsDelete(PDO $conn, array $apiKey, array $params, array $body): void {
    apiLoadContract($conn, $params[0]);
    $conn->beginTransaction();
    try {
        $conn->prepare("DELETE FROM contract_term_values WHERE contract_id = ?")->execute([$params[0]]);
        // Referrers whose FKs are SET NULL on fresh installs — done explicitly
        // so grown installs behave identically.
        foreach (['tasks', 'calendar_events', 'rfps'] as $t) {
            try {
                $conn->prepare("UPDATE `$t` SET contract_id = NULL WHERE contract_id = ?")->execute([$params[0]]);
            } catch (Exception $e) { /* table absent on a minimal install */ }
        }
        $conn->prepare("DELETE FROM contracts WHERE id = ?")->execute([$params[0]]);
        $conn->commit();
    } catch (Exception $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        throw $e;
    }
    apiRespond(['id' => $params[0], 'deleted' => true]);
}

// ---------------------------------------------------------------------------
// Contract terms — GET + bulk upsert (mirrors save_contract_terms.php)
// ---------------------------------------------------------------------------
function apiContractTermsGet(PDO $conn, array $apiKey, array $params, array $body): void {
    apiLoadContract($conn, $params[0]);
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

function apiContractTermsSave(PDO $conn, array $apiKey, array $params, array $body): void {
    apiLoadContract($conn, $params[0]);
    $terms = $body['terms'] ?? null;
    if (!is_array($terms)) {
        apiError(422, 'missing_field', "'terms' is required: [{\"term_tab_id\": 1, \"content\": \"…\"}, …].");
    }
    foreach ($terms as $term) {
        $tabId = isset($term['term_tab_id']) ? (int)$term['term_tab_id'] : 0;
        if ($tabId <= 0) {
            apiError(422, 'invalid_field', "Each term needs a 'term_tab_id'.");
        }
        apiContractLookup($conn, 'contract_term_tabs', $tabId, 'term tab');
        $content = (string)($term['content'] ?? '');

        $stmt = $conn->prepare("SELECT id FROM contract_term_values WHERE contract_id = ? AND term_tab_id = ?");
        $stmt->execute([$params[0], $tabId]);
        $existing = $stmt->fetchColumn();
        if ($existing !== false) {
            $conn->prepare("UPDATE contract_term_values SET content = ?, updated_datetime = UTC_TIMESTAMP() WHERE id = ?")
                 ->execute([$content, (int)$existing]);
        } else {
            $conn->prepare("INSERT INTO contract_term_values (contract_id, term_tab_id, content, created_datetime) VALUES (?, ?, ?, UTC_TIMESTAMP())")
                 ->execute([$params[0], $tabId, $content]);
        }
    }
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

function apiSuppliersCreate(PDO $conn, array $apiKey, array $params, array $body): void {
    apiSupplierWrite($conn, $body, null);
}

function apiSuppliersUpdate(PDO $conn, array $apiKey, array $params, array $body): void {
    apiLoadSupplier($conn, $params[0]);
    if (!$body) {
        apiError(422, 'missing_field', 'No fields to update.');
    }
    apiSupplierWrite($conn, $body, $params[0]);
}

function apiSupplierWrite(PDO $conn, array $body, ?int $id): void {
    $current = [];
    if ($id !== null) {
        $current = apiLoadSupplier($conn, $id);
    }
    $get = function (string $key, $default = null) use ($body, $current) {
        return array_key_exists($key, $body) ? $body[$key] : ($current[$key] ?? $default);
    };

    $legalName = trim((string)$get('legal_name', ''));
    if ($legalName === '') {
        apiError(422, 'missing_field', "'legal_name' is required.");
    }
    $typeId   = apiContractLookup($conn, 'supplier_types', $get('supplier_type_id'), 'supplier type');
    $statusId = apiContractLookup($conn, 'supplier_statuses', $get('supplier_status_id'), 'supplier status');
    $qIssued   = apiParseDateOnly($get('questionnaire_date_issued'), 'questionnaire_date_issued');
    $qReceived = apiParseDateOnly($get('questionnaire_date_received'), 'questionnaire_date_received');
    $str = function ($key) use ($get) {
        $v = trim((string)($get($key) ?? ''));
        return $v !== '' ? $v : null;
    };
    $isActive = $get('is_active', 1) ? 1 : 0;
    // The UI never writes supplies_assets (it's set by the asset-import
    // migration only) — the API exposes it so integrations can flag asset
    // suppliers. Documented divergence.
    $suppliesAssets = (int)(bool)$get('supplies_assets', $current['supplies_assets'] ?? 0);

    $fields = [
        $legalName, $str('trading_name'), $str('reg_number'), $str('vat_number'),
        $typeId, $statusId,
        $str('address_line_1'), $str('address_line_2'), $str('city'), $str('county'), $str('postcode'), $str('country'),
        $qIssued, $qReceived, $str('comments'), $isActive, $suppliesAssets,
    ];

    if ($id !== null) {
        $conn->prepare(
            "UPDATE suppliers SET legal_name=?, trading_name=?, reg_number=?, vat_number=?,
                supplier_type_id=?, supplier_status_id=?,
                address_line_1=?, address_line_2=?, city=?, county=?, postcode=?, country=?,
                questionnaire_date_issued=?, questionnaire_date_received=?, comments=?, is_active=?, supplies_assets=?
             WHERE id=?"
        )->execute(array_merge($fields, [$id]));
    } else {
        $conn->prepare(
            "INSERT INTO suppliers (legal_name, trading_name, reg_number, vat_number,
                supplier_type_id, supplier_status_id,
                address_line_1, address_line_2, city, county, postcode, country,
                questionnaire_date_issued, questionnaire_date_received, comments, is_active, supplies_assets, created_datetime)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())"
        )->execute($fields);
        $id = (int)$conn->lastInsertId();
    }

    apiRespond(apiSerializeSupplier(apiLoadSupplier($conn, $id)), $current ? 200 : 201);
}

/** DELETE /suppliers/{id} — mirrors delete_supplier.php's explicit nullify-then-delete. */
function apiSuppliersDelete(PDO $conn, array $apiKey, array $params, array $body): void {
    apiLoadSupplier($conn, $params[0]);
    $conn->beginTransaction();
    try {
        $conn->prepare("UPDATE contacts SET supplier_id = NULL WHERE supplier_id = ?")->execute([$params[0]]);
        $conn->prepare("UPDATE contracts SET supplier_id = NULL WHERE supplier_id = ?")->execute([$params[0]]);
        foreach ([
            "UPDATE assets SET supplier_id = NULL WHERE supplier_id = ?",
            "UPDATE rfps SET chosen_supplier_id = NULL WHERE chosen_supplier_id = ?",
            "DELETE FROM rfp_invited_suppliers WHERE supplier_id = ?",
            "DELETE FROM rfp_scores WHERE supplier_id = ?",
        ] as $sql) {
            try {
                $conn->prepare($sql)->execute([$params[0]]);
            } catch (Exception $e) { /* table absent on a minimal install */ }
        }
        $conn->prepare("DELETE FROM suppliers WHERE id = ?")->execute([$params[0]]);
        $conn->commit();
    } catch (Exception $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        throw $e;
    }
    apiRespond(['id' => $params[0], 'deleted' => true]);
}

// ---------------------------------------------------------------------------
// Supplier contacts
// ---------------------------------------------------------------------------
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

function apiSupplierContactsList(PDO $conn, array $apiKey, array $params, array $body): void {
    apiLoadSupplier($conn, $params[0]);
    $stmt = $conn->prepare(
        "SELECT id, first_name, surname, email, mobile, job_title, direct_dial, switchboard, is_active
         FROM contacts WHERE supplier_id = ? ORDER BY surname, first_name"
    );
    $stmt->execute([$params[0]]);
    apiRespond(array_map('apiSerializeContact', $stmt->fetchAll(PDO::FETCH_ASSOC)));
}

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

function apiContractStatusesList(PDO $conn, array $apiKey, array $params, array $body): void {
    apiContractLookupList($conn, 'contract_statuses');
}
function apiPaymentSchedulesList(PDO $conn, array $apiKey, array $params, array $body): void {
    apiContractLookupList($conn, 'payment_schedules');
}
function apiSupplierTypesList(PDO $conn, array $apiKey, array $params, array $body): void {
    apiContractLookupList($conn, 'supplier_types');
}
function apiSupplierStatusesList(PDO $conn, array $apiKey, array $params, array $body): void {
    apiContractLookupList($conn, 'supplier_statuses');
}
function apiContractTermTabsList(PDO $conn, array $apiKey, array $params, array $body): void {
    apiContractLookupList($conn, 'contract_term_tabs');
}
