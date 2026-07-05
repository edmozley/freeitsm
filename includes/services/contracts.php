<?php
/**
 * ContractsService — the shared write rules for the contracts module's core
 * entities: contracts, suppliers and contract-term values.
 *
 * Shared by the UI endpoints (api/contracts/*.php) and the REST API
 * (api/v1/resources/contracts.php). Each caller passes an ActorContext +
 * canonical input; this layer validates + writes and returns the affected id(s)
 * or throws ServiceError. It never emits HTTP.
 *
 * SCOPE: this covers the cleanly-duplicated overlaps only. The module's lookup
 * SETTINGS (contract/supplier statuses, supplier types, payment schedules,
 * term tabs) are UI-only (the API exposes them read-only) and stay on their own
 * endpoints; supplier CONTACTS are modelled flat by the UI (supplier_id in the
 * body, movable) but nested under a supplier by the API — a structural mismatch
 * that isn't worth forcing into one shape — so they stay separate too.
 *
 * Canonical behaviour = the API resource's: required contract_number + title /
 * supplier legal_name, lookup ids validated (422), dates validated (422),
 * contract_value numeric + currency a 3-letter code, a duplicate
 * contract_number is a 409, and created timestamps are stamped UTC (the raw UI
 * relied on the server-local column default).
 */

require_once __DIR__ . '/../service_context.php';

class ContractsService
{
    // ======================================================================
    //  Contracts
    // ======================================================================

    /** Create (no id) or update (id present) a contract. Returns ['id','created']. */
    public static function saveContract(PDO $conn, ActorContext $ctx, array $in): array
    {
        if (!empty($in['id'])) {
            $id      = (int)$in['id'];
            $current = self::loadRow($conn, 'contracts', $id, 'Contract not found.');
            if (!array_diff_key($in, ['id' => true])) {
                throw new ServiceError('validation', 'missing_field', 'No fields to update.');
            }
            $f = self::contractFields($conn, $in, $current);
            if ($f['contract_number'] !== $current['contract_number']) {
                $dup = $conn->prepare("SELECT id FROM contracts WHERE contract_number = ? AND id != ?");
                $dup->execute([$f['contract_number'], $id]);
                if ($dup->fetchColumn()) {
                    throw new ServiceError('conflict', 'conflict', 'Another contract already uses this contract_number.');
                }
            }
            $conn->prepare(
                "UPDATE contracts SET contract_number=?, title=?, description=?, supplier_id=?, contract_owner_id=?,
                    contract_status_id=?, contract_start=?, contract_end=?, notice_period_days=?, notice_date=?,
                    contract_value=?, currency=?, payment_schedule_id=?, cost_centre=?, dms_link=?, terms_status=?,
                    personal_data_transferred=?, dpia_required=?, dpia_completed_date=?, dpia_dms_link=?, is_active=?
                 WHERE id=?"
            )->execute([...self::contractParams($f), $id]);
            return ['id' => $id, 'created' => false];
        }

        $f = self::contractFields($conn, $in);
        $dup = $conn->prepare("SELECT id FROM contracts WHERE contract_number = ?");
        $dup->execute([$f['contract_number']]);
        $existing = $dup->fetchColumn();
        if ($existing !== false) {
            throw new ServiceError('conflict', 'conflict', "A contract with this contract_number already exists (id {$existing}).");
        }
        $conn->prepare(
            "INSERT INTO contracts (contract_number, title, description, supplier_id, contract_owner_id,
                contract_status_id, contract_start, contract_end, notice_period_days, notice_date,
                contract_value, currency, payment_schedule_id, cost_centre, dms_link, terms_status,
                personal_data_transferred, dpia_required, dpia_completed_date, dpia_dms_link,
                is_active, created_datetime)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())"
        )->execute(self::contractParams($f));
        return ['id' => (int)$conn->lastInsertId(), 'created' => true];
    }

    /** Delete a contract (+ its term values; nullify referrers explicitly). Returns the id. */
    public static function deleteContract(PDO $conn, ActorContext $ctx, int $id): int
    {
        self::loadRow($conn, 'contracts', $id, 'Contract not found.');
        $conn->beginTransaction();
        try {
            $conn->prepare("DELETE FROM contract_term_values WHERE contract_id = ?")->execute([$id]);
            foreach (['tasks', 'calendar_events', 'rfps'] as $t) {
                try {
                    $conn->prepare("UPDATE `$t` SET contract_id = NULL WHERE contract_id = ?")->execute([$id]);
                } catch (Exception $e) { /* table absent on a minimal install */ }
            }
            $conn->prepare("DELETE FROM contracts WHERE id = ?")->execute([$id]);
            $conn->commit();
        } catch (Exception $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            throw $e;
        }
        return $id;
    }

    /** Bulk-upsert a contract's term values (per tab). Idempotent; returns nothing. */
    public static function saveTerms(PDO $conn, ActorContext $ctx, int $contractId, $terms): void
    {
        self::loadRow($conn, 'contracts', $contractId, 'Contract not found.');
        if (!is_array($terms)) {
            throw new ServiceError('validation', 'missing_field', "'terms' is required: [{\"term_tab_id\": 1, \"content\": \"…\"}, …].");
        }
        foreach ($terms as $term) {
            $tabId = isset($term['term_tab_id']) ? (int)$term['term_tab_id'] : 0;
            if ($tabId <= 0) {
                throw new ServiceError('validation', 'invalid_field', "Each term needs a 'term_tab_id'.");
            }
            self::lookup($conn, 'contract_term_tabs', $tabId, 'term tab');
            $content = (string)($term['content'] ?? '');

            $stmt = $conn->prepare("SELECT id FROM contract_term_values WHERE contract_id = ? AND term_tab_id = ?");
            $stmt->execute([$contractId, $tabId]);
            $existing = $stmt->fetchColumn();
            if ($existing !== false) {
                $conn->prepare("UPDATE contract_term_values SET content = ?, updated_datetime = UTC_TIMESTAMP() WHERE id = ?")
                     ->execute([$content, (int)$existing]);
            } else {
                $conn->prepare("INSERT INTO contract_term_values (contract_id, term_tab_id, content, created_datetime) VALUES (?, ?, ?, UTC_TIMESTAMP())")
                     ->execute([$contractId, $tabId, $content]);
            }
        }
    }

    // ======================================================================
    //  Suppliers
    // ======================================================================

    /** Create (no id) or update (id present) a supplier. Returns ['id','created']. */
    public static function saveSupplier(PDO $conn, ActorContext $ctx, array $in): array
    {
        $current = [];
        $id = null;
        if (!empty($in['id'])) {
            $id = (int)$in['id'];
            $current = self::loadRow($conn, 'suppliers', $id, 'Supplier not found.');
            if (!array_diff_key($in, ['id' => true])) {
                throw new ServiceError('validation', 'missing_field', 'No fields to update.');
            }
        }
        $get = function (string $key, $default = null) use ($in, $current) {
            return array_key_exists($key, $in) ? $in[$key] : ($current[$key] ?? $default);
        };

        $legalName = trim((string)$get('legal_name', ''));
        if ($legalName === '') {
            throw new ServiceError('validation', 'missing_field', "'legal_name' is required.");
        }
        $typeId   = self::lookup($conn, 'supplier_types', $get('supplier_type_id'), 'supplier type');
        $statusId = self::lookup($conn, 'supplier_statuses', $get('supplier_status_id'), 'supplier status');
        $qIssued   = self::parseDateOnly($get('questionnaire_date_issued'), 'questionnaire_date_issued');
        $qReceived = self::parseDateOnly($get('questionnaire_date_received'), 'questionnaire_date_received');
        $str = function ($key) use ($get) {
            $v = trim((string)($get($key) ?? ''));
            return $v !== '' ? $v : null;
        };
        $isActive = $get('is_active', 1) ? 1 : 0;
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
            return ['id' => $id, 'created' => false];
        }
        $conn->prepare(
            "INSERT INTO suppliers (legal_name, trading_name, reg_number, vat_number,
                supplier_type_id, supplier_status_id,
                address_line_1, address_line_2, city, county, postcode, country,
                questionnaire_date_issued, questionnaire_date_received, comments, is_active, supplies_assets, created_datetime)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())"
        )->execute($fields);
        return ['id' => (int)$conn->lastInsertId(), 'created' => true];
    }

    /** Delete a supplier — nullify/clean every referrer, then delete. Returns the id. */
    public static function deleteSupplier(PDO $conn, ActorContext $ctx, int $id): int
    {
        self::loadRow($conn, 'suppliers', $id, 'Supplier not found.');
        $conn->beginTransaction();
        try {
            $conn->prepare("UPDATE contacts SET supplier_id = NULL WHERE supplier_id = ?")->execute([$id]);
            $conn->prepare("UPDATE contracts SET supplier_id = NULL WHERE supplier_id = ?")->execute([$id]);
            foreach ([
                "UPDATE assets SET supplier_id = NULL WHERE supplier_id = ?",
                "UPDATE rfps SET chosen_supplier_id = NULL WHERE chosen_supplier_id = ?",
                "DELETE FROM rfp_invited_suppliers WHERE supplier_id = ?",
                "DELETE FROM rfp_scores WHERE supplier_id = ?",
            ] as $sql) {
                try {
                    $conn->prepare($sql)->execute([$id]);
                } catch (Exception $e) { /* table absent on a minimal install */ }
            }
            $conn->prepare("DELETE FROM suppliers WHERE id = ?")->execute([$id]);
            $conn->commit();
        } catch (Exception $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            throw $e;
        }
        return $id;
    }

    // ======================================================================
    //  Internals
    // ======================================================================

    private static function loadRow(PDO $conn, string $table, int $id, string $notFoundMsg): array
    {
        $stmt = $conn->prepare("SELECT * FROM `$table` WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new ServiceError('not_found', 'not_found', $notFoundMsg);
        }
        return $row;
    }

    /** Ordered contract column values for INSERT/UPDATE (matches both statements' column order). */
    private static function contractParams(array $f): array
    {
        return [
            $f['contract_number'], $f['title'], $f['description'], $f['supplier_id'], $f['contract_owner_id'],
            $f['contract_status_id'], $f['contract_start'], $f['contract_end'], $f['notice_period_days'], $f['notice_date'],
            $f['contract_value'], $f['currency'], $f['payment_schedule_id'], $f['cost_centre'], $f['dms_link'], $f['terms_status'],
            $f['personal_data_transferred'], $f['dpia_required'], $f['dpia_completed_date'], $f['dpia_dms_link'], $f['is_active'],
        ];
    }

    /** Collect + validate contract fields (mirrors the API resource's apiContractFieldsFromBody). */
    private static function contractFields(PDO $conn, array $in, array $current = []): array
    {
        $get = function (string $key, $default = null) use ($in, $current) {
            return array_key_exists($key, $in) ? $in[$key] : ($current[$key] ?? $default);
        };

        $f = [];
        $f['contract_number'] = trim((string)$get('contract_number', ''));
        $f['title']           = trim((string)$get('title', ''));
        if ($f['contract_number'] === '') {
            throw new ServiceError('validation', 'missing_field', "'contract_number' is required.");
        }
        if ($f['title'] === '') {
            throw new ServiceError('validation', 'missing_field', "'title' is required.");
        }
        $f['description'] = ($v = trim((string)$get('description', ''))) !== '' ? $v : null;

        $f['supplier_id']         = self::lookup($conn, 'suppliers', $get('supplier_id'), 'supplier');
        $f['contract_status_id']  = self::lookup($conn, 'contract_statuses', $get('contract_status_id'), 'contract status');
        $f['payment_schedule_id'] = self::lookup($conn, 'payment_schedules', $get('payment_schedule_id'), 'payment schedule');
        $f['contract_owner_id']   = null;
        if ($get('contract_owner_id') !== null && $get('contract_owner_id') !== '') {
            $f['contract_owner_id'] = (int)$get('contract_owner_id');
            self::resolveAnalyst($conn, $f['contract_owner_id']);
        }

        foreach (['contract_start', 'contract_end', 'notice_date', 'dpia_completed_date'] as $d) {
            $f[$d] = self::parseDateOnly($get($d), $d);
        }
        $f['notice_period_days'] = null;
        if ($get('notice_period_days') !== null && $get('notice_period_days') !== '') {
            $f['notice_period_days'] = max(0, (int)$get('notice_period_days'));
        }

        $f['contract_value'] = null;
        if ($get('contract_value') !== null && $get('contract_value') !== '') {
            if (!is_numeric($get('contract_value'))) {
                throw new ServiceError('validation', 'invalid_field', "'contract_value' must be a number.");
            }
            $f['contract_value'] = (string)round((float)$get('contract_value'), 2);
        }
        $f['currency'] = null;
        if ($get('currency') !== null && trim((string)$get('currency')) !== '') {
            $cur = strtoupper(trim((string)$get('currency')));
            if (!preg_match('/^[A-Z]{3}$/', $cur)) {
                throw new ServiceError('validation', 'invalid_field', "'currency' must be a 3-letter code, e.g. GBP.");
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

    /** Validate a nullable lookup id against a table; 422 with the label on unknown. */
    private static function lookup(PDO $conn, string $table, $value, string $label): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        $id = (int)$value;
        $stmt = $conn->prepare("SELECT id FROM `$table` WHERE id = ?");
        $stmt->execute([$id]);
        if (!$stmt->fetchColumn()) {
            throw new ServiceError('validation', 'invalid_field', "Unknown {$label} id: {$id}");
        }
        return $id;
    }

    /** Validate an analyst id exists + is active (throwing twin of apiResolveAnalyst). */
    private static function resolveAnalyst(PDO $conn, int $analystId): void
    {
        $stmt = $conn->prepare("SELECT full_name FROM analysts WHERE id = ? AND is_active = 1");
        $stmt->execute([$analystId]);
        if ($stmt->fetchColumn() === false) {
            throw new ServiceError('validation', 'invalid_field', "Unknown or inactive analyst id: {$analystId}");
        }
    }

    /** Validate an optional YYYY-MM-DD date (throwing twin of apiParseDateOnly). */
    private static function parseDateOnly($value, string $field): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $d = DateTimeImmutable::createFromFormat('Y-m-d', (string)$value);
        if (!$d || $d->format('Y-m-d') !== (string)$value) {
            throw new ServiceError('validation', 'invalid_field', "'{$field}' must be a date in YYYY-MM-DD format.");
        }
        return (string)$value;
    }
}
