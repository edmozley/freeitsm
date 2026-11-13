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
require_once __DIR__ . '/../money.php';   // GH #189: currencies are checked against the registry
require_once dirname(__DIR__, 2) . '/workflow/includes/engine.php';
require_once __DIR__ . '/../contract_party.php';   // supplier or customer, and who may see a customer contract (#153)
require_once __DIR__ . '/../cost_centre_assign.php';   // GH #160: the cost centre it is charged to
require_once __DIR__ . '/../tenancy.php';   // GH #307: tenancyColumnExists() for suppliers.ticket_email

class ContractsService
{
    // ======================================================================
    //  Contracts
    // ======================================================================

    /**
     * Create (no id) or update (id present) a contract. Returns ['id','created'].
     *
     * @param array $in  id (absent = new) plus contract fields, party fields, tenant_id, cost_centre_id or cost_centre (a code)
     */
    public static function saveContract(PDO $conn, ActorContext $ctx, array $in): array
    {
        if (!empty($in['id'])) {
            $id      = (int)$in['id'];
            $current = self::loadRow($conn, 'contracts', $id, 'Contract not found.');
            self::assertVisible($conn, $ctx, $id);
            if (!array_diff_key($in, ['id' => true])) {
                throw new ServiceError('validation', 'missing_field', 'No fields to update.');
            }
            $f = self::contractFields($conn, $in, $current);
            $party = self::partyFields($conn, $ctx, $in, $current);
            $f['supplier_id'] = $party['supplier_id'];
            // The owning company (3.5.0): unchanged unless the request names one.
            $tenant = self::ownerTenant($conn, $ctx, $in, $current);
            if ($tenant !== self::effectiveTenant($conn, $current)) {
                self::assertMovable($conn, $id, $tenant);
            }
            if ($f['contract_number'] !== $current['contract_number'] || $tenant !== self::effectiveTenant($conn, $current)) {
                self::assertNumberFree($conn, $f['contract_number'], $tenant, $id);
            }
            [$ccId, $f['cost_centre']] = self::costCentreFor($conn, $in, $current, $tenant);
            $conn->prepare(
                "UPDATE contracts SET contract_number=?, title=?, description=?, supplier_id=?, contract_owner_id=?,
                    contract_status_id=?, contract_start=?, contract_end=?, notice_period_days=?, notice_date=?,
                    contract_value=?, currency=?, payment_schedule_id=?, cost_centre=?, dms_link=?, terms_status=?,
                    personal_data_transferred=?, dpia_required=?, dpia_completed_date=?, dpia_dms_link=?, is_active=?
                 WHERE id=?"
            )->execute([...self::contractParams($f), $id]);
            self::writeParty($conn, $id, $party);
            self::writeTenant($conn, $id, $tenant);
            self::writeCostCentre($conn, $id, $ccId);
            WorkflowEngine::dispatch('contract.updated', ['contract' => ['id' => $id, 'title' => $f['title'] ?? null, 'status_id' => $f['contract_status_id'] ?? null, 'supplier_id' => $f['supplier_id'] ?? null] + self::partyPayload($party)]);
            return ['id' => $id, 'created' => false];
        }

        $f = self::contractFields($conn, $in);
        $party = self::partyFields($conn, $ctx, $in, []);
        $f['supplier_id'] = $party['supplier_id'];
        // The owning company (3.5.0): the one named, else the Default company. The
        // screen always names the active company; the API names the key's default.
        $tenant = self::ownerTenant($conn, $ctx, $in, []);
        self::assertNumberFree($conn, $f['contract_number'], $tenant, null);
        [$ccId, $f['cost_centre']] = self::costCentreFor($conn, $in, [], $tenant);
        $conn->prepare(
            "INSERT INTO contracts (contract_number, title, description, supplier_id, contract_owner_id,
                contract_status_id, contract_start, contract_end, notice_period_days, notice_date,
                contract_value, currency, payment_schedule_id, cost_centre, dms_link, terms_status,
                personal_data_transferred, dpia_required, dpia_completed_date, dpia_dms_link,
                is_active, created_datetime)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())"
        )->execute(self::contractParams($f));
        $newId = (int)$conn->lastInsertId();
        self::writeParty($conn, $newId, $party);
        self::writeTenant($conn, $newId, $tenant);
        self::writeCostCentre($conn, $newId, $ccId);
        WorkflowEngine::dispatch('contract.created', ['contract' => ['id' => $newId, 'title' => $f['title'] ?? null, 'status_id' => $f['contract_status_id'] ?? null, 'supplier_id' => $f['supplier_id'] ?? null] + self::partyPayload($party)]);
        return ['id' => $newId, 'created' => true];
    }

    /**
     * Delete a contract (+ its term values; nullify referrers explicitly). Returns the id.
     *
     * @param int $id  the contract to delete
     */
    public static function deleteContract(PDO $conn, ActorContext $ctx, int $id): int
    {
        $row = self::loadRow($conn, 'contracts', $id, 'Contract not found.');
        self::assertVisible($conn, $ctx, $id);
        $conn->beginTransaction();
        try {
            $conn->prepare("DELETE FROM contract_term_values WHERE contract_id = ?")->execute([$id]);
            foreach (['tasks', 'calendar_events', 'rfps'] as $t) {
                try {
                    $conn->prepare("UPDATE `$t` SET contract_id = NULL WHERE contract_id = ?")->execute([$id]);
                } catch (Exception) { /* table absent on a minimal install */ }
            }
            $conn->prepare("DELETE FROM contracts WHERE id = ?")->execute([$id]);
            $conn->commit();
        } catch (Exception $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            throw $e;
        }
        WorkflowEngine::dispatch('contract.deleted', ['contract' => ['id' => $id, 'title' => $row['title'] ?? null, 'status_id' => isset($row['contract_status_id']) ? (int)$row['contract_status_id'] : null, 'supplier_id' => isset($row['supplier_id']) ? (int)$row['supplier_id'] : null,
            'party_type' => $row['party_type'] ?? 'supplier',
            'customer_tenant_id' => isset($row['customer_tenant_id']) ? (int)$row['customer_tenant_id'] : null,
            'customer_user_id' => isset($row['customer_user_id']) ? (int)$row['customer_user_id'] : null]]);
        return $id;
    }

    /**
     * Bulk-upsert a contract's term values (per tab). Idempotent; returns nothing.
     *
     * @param int $contractId  the contract whose terms are saved
     * @param mixed $terms  [{term_tab_id, content}] - one entry per tab to write; must be an array
     */
    public static function saveTerms(PDO $conn, ActorContext $ctx, int $contractId, $terms): void
    {
        self::loadRow($conn, 'contracts', $contractId, 'Contract not found.');
        self::assertVisible($conn, $ctx, $contractId);
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

    /**
     * Create (no id) or update (id present) a supplier. Returns ['id','created'].
     *
     * @param array $in  id (absent = new), legal_name (required), trading_name, type and status ids, address, questionnaire dates...
     */
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
        // GH #307: the address their service desk takes tickets on - suggested first in a ticket's To / Cc.
        $ticketEmail = $str('ticket_email');
        if ($ticketEmail !== null && !filter_var($ticketEmail, FILTER_VALIDATE_EMAIL)) {
            throw new ServiceError('validation', 'invalid_value', "'ticket_email' is not a valid email address.");
        }
        $hasTicketEmail = tenancyColumnExists($conn, 'suppliers', 'ticket_email');   // before Database Verification: not saved, nothing breaks
        $isActive = $get('is_active', 1) ? 1 : 0;
        $suppliesAssets = (int)(bool)$get('supplies_assets', $current['supplies_assets'] ?? 0);

        $fields = [
            $legalName, $str('trading_name'), $str('reg_number'), $str('vat_number'),
            $typeId, $statusId,
            $str('address_line_1'), $str('address_line_2'), $str('city'), $str('county'), $str('postcode'), $str('country'),
            $qIssued, $qReceived, $str('comments'), $isActive, $suppliesAssets,
        ];
        if ($hasTicketEmail) $fields[] = $ticketEmail;

        if ($id !== null) {
            $conn->prepare(
                "UPDATE suppliers SET legal_name=?, trading_name=?, reg_number=?, vat_number=?,
                    supplier_type_id=?, supplier_status_id=?,
                    address_line_1=?, address_line_2=?, city=?, county=?, postcode=?, country=?,
                    questionnaire_date_issued=?, questionnaire_date_received=?, comments=?, is_active=?, supplies_assets=?"
                 . ($hasTicketEmail ? ', ticket_email=?' : '') . "
                 WHERE id=?"
            )->execute(array_merge($fields, [$id]));
            WorkflowEngine::dispatch('supplier.updated', ['supplier' => ['id' => $id, 'name' => $legalName, 'status_id' => $statusId, 'type_id' => $typeId]]);
            return ['id' => $id, 'created' => false];
        }
        $conn->prepare(
            "INSERT INTO suppliers (legal_name, trading_name, reg_number, vat_number,
                supplier_type_id, supplier_status_id,
                address_line_1, address_line_2, city, county, postcode, country,
                questionnaire_date_issued, questionnaire_date_received, comments, is_active, supplies_assets"
             . ($hasTicketEmail ? ', ticket_email' : '') . ", created_datetime)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?" . ($hasTicketEmail ? ', ?' : '') . ", UTC_TIMESTAMP())"
        )->execute($fields);
        $newId = (int)$conn->lastInsertId();
        WorkflowEngine::dispatch('supplier.created', ['supplier' => ['id' => $newId, 'name' => $legalName, 'status_id' => $statusId, 'type_id' => $typeId]]);
        return ['id' => $newId, 'created' => true];
    }

    /**
     * Delete a supplier — nullify/clean every referrer, then delete. Returns the id.
     *
     * @param int $id  the supplier to delete; every reference to it is cleared first
     */
    public static function deleteSupplier(PDO $conn, ActorContext $ctx, int $id): int
    {
        $row = self::loadRow($conn, 'suppliers', $id, 'Supplier not found.');
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
                } catch (Exception) { /* table absent on a minimal install */ }
            }
            $conn->prepare("DELETE FROM suppliers WHERE id = ?")->execute([$id]);
            $conn->commit();
        } catch (Exception $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            throw $e;
        }
        WorkflowEngine::dispatch('supplier.deleted', ['supplier' => ['id' => $id, 'name' => $row['legal_name'] ?? null, 'status_id' => isset($row['supplier_status_id']) ? (int)$row['supplier_status_id'] : null, 'type_id' => isset($row['supplier_type_id']) ? (int)$row['supplier_type_id'] : null]]);
        return $id;
    }

    // ======================================================================
    //  Internals
    // ======================================================================

    /**
     * A contract row's company, with NULL read as the Default company.
     *
     * @param array $row  the contracts row
     */
    private static function effectiveTenant(PDO $conn, array $row): int
    {
        $t = $row['tenant_id'] ?? null;
        return $t === null ? getDefaultTenantId($conn) : (int)$t;
    }

    /**
     * The company a contract being saved belongs to. A named company must exist
     * and be one the caller can reach - refused, never quietly replaced with
     * another. Not named: the current one on an update, Default on a create.
     *
     * @param array $in  the request fields; tenant_id names the company
     * @param array $current  the stored contract row on update; [] on create
     */
    private static function ownerTenant(PDO $conn, ActorContext $ctx, array $in, array $current): int
    {
        $raw = $in['tenant_id'] ?? null;
        if ($raw === null || $raw === '' || !contractTenantReady($conn)) {
            if ($current) return self::effectiveTenant($conn, $current);
            // A create that names no company: Default - unless the actor cannot
            // reach Default, when it is their first company. Never a contract its
            // own creator could not then see.
            $def = getDefaultTenantId($conn);
            if (!isMultiTenant($conn) || $ctx->companyScope === null || in_array($def, $ctx->companyScope, true)) return $def;
            if (!$ctx->companyScope) throw new ServiceError('forbidden', 'forbidden', 'You cannot add contracts: you have no companies.');
            return (int)$ctx->companyScope[0];
        }
        $tid = (int)$raw;
        if ($tid <= 0 || !getTenantById($conn, $tid)) {
            throw new ServiceError('validation', 'invalid_field', 'That company does not exist.');
        }
        if (isMultiTenant($conn) && $ctx->companyScope !== null && !in_array($tid, $ctx->companyScope, true)) {
            throw new ServiceError('forbidden', 'forbidden', 'You cannot put a contract in that company.');
        }
        return $tid;
    }

    /**
     * Contract numbers are unique within a company - two clients can both have C-001.
     *
     * @param string $number  the contract number to check
     * @param int $tenant  the company it must be unique in (a real id, never null)
     * @param ?int $exceptId  the contract allowed to hold it (the one being saved), or null on create
     */
    private static function assertNumberFree(PDO $conn, string $number, int $tenant, ?int $exceptId): void
    {
        if (contractTenantReady($conn)) {
            $sql = "SELECT id FROM contracts WHERE contract_number = ? AND COALESCE(tenant_id, ?) = ?";
            $args = [$number, getDefaultTenantId($conn), $tenant];
        } else {
            $sql = "SELECT id FROM contracts WHERE contract_number = ?";
            $args = [$number];
        }
        if ($exceptId !== null) { $sql .= " AND id <> ?"; $args[] = $exceptId; }
        $st = $conn->prepare($sql);
        $st->execute($args);
        if ($st->fetchColumn() !== false) {
            throw new ServiceError('conflict', 'conflict', 'Another contract in this company already uses this contract number.');
        }
    }

    /**
     * Moving a contract to another company is refused while anything of the old
     * company hangs off it - its assets, projects or RFPs would otherwise link
     * across companies, which nothing here allows. Say what is in the way.
     *
     * @param int $id  the contract being moved
     * @param int $to  the company it is moving to
     */
    private static function assertMovable(PDO $conn, int $id, int $to): void
    {
        $def = getDefaultTenantId($conn);
        $checks = [
            'asset(s)'   => "SELECT COUNT(*) FROM contract_assets ca JOIN assets a ON a.id = ca.asset_id WHERE ca.contract_id = ? AND COALESCE(a.tenant_id, $def) <> ?",
            'project(s)' => "SELECT COUNT(*) FROM project_contracts pc JOIN projects p ON p.id = pc.project_id WHERE pc.contract_id = ? AND COALESCE(p.tenant_id, $def) <> ?",
            'RFP(s)'     => "SELECT COUNT(*) FROM rfps r WHERE r.contract_id = ? AND COALESCE(r.tenant_id, $def) <> ?",
        ];
        $blocked = [];
        foreach ($checks as $what => $sql) {
            try {
                $st = $conn->prepare($sql);
                $st->execute([$id, $to]);
                $n = (int)$st->fetchColumn();
                if ($n > 0) $blocked[] = "$n $what";
            } catch (Exception) { /* table or column absent before Database Verification */ }
        }
        if ($blocked) {
            throw new ServiceError('conflict', 'conflict',
                'This contract is linked to ' . implode(', ', $blocked) . ' in its current company. Remove those links before moving it to another company.');
        }
    }

    /**
     * The cost centre a contract is charged to (GH #160), and what is left of the
     * free text from before 3.5.0. Returns [cost_centre_id, legacy text].
     *
     *  - cost_centre_id: an ACTIVE cost centre of the contract's company, or the
     *    one it already holds (costCentreCheck). Choosing one clears the old text.
     *  - cost_centre (text): what the API always took. Now a CODE in the
     *    contract's company - linked if it exists, refused if not; '' clears.
     *  - Neither sent: unchanged - but a contract moving company cannot take its
     *    old company's cost centre with it, so that is refused with a reason.
     *
     * @param array $in  the request fields; cost_centre_id, or cost_centre as a code
     * @param array $current  the stored contract row on update; [] on create
     * @param int $tenant  the company the contract will belong to
     */
    private static function costCentreFor(PDO $conn, array $in, array $current, int $tenant): array
    {
        $curId   = isset($current['cost_centre_id']) && $current['cost_centre_id'] !== null ? (int)$current['cost_centre_id'] : null;
        $curText = isset($current['cost_centre']) && trim((string)$current['cost_centre']) !== '' ? (string)$current['cost_centre'] : null;
        if (!costCentreReady($conn, 'contracts')) {
            return [null, array_key_exists('cost_centre', $in) ? (trim((string)$in['cost_centre']) ?: null) : $curText];
        }
        if (array_key_exists('cost_centre_id', $in)) {
            $id = costCentreCheck($conn, $tenant, $in['cost_centre_id'], $curId);
            return [$id, $id !== null ? null : $curText];
        }
        if (array_key_exists('cost_centre', $in)) {
            $code = trim((string)$in['cost_centre']);
            if ($code === '') return [null, null];
            $cc = costCentreByCode($conn, $tenant, $code);
            if (!$cc) throw new ServiceError('validation', 'invalid_field', "No cost centre with the code \"{$code}\" in this contract's company.");
            return [costCentreCheck($conn, $tenant, $cc['id'], $curId), null];
        }
        if ($curId !== null) {
            $st = $conn->prepare("SELECT tenant_id FROM cost_centres WHERE id = ?");
            $st->execute([$curId]);
            if ((int)$st->fetchColumn() !== $tenant) {
                throw new ServiceError('validation', 'invalid_field', "This contract's cost centre belongs to its current company. Choose one of the new company's (or none) when moving it.");
            }
        }
        return [$curId, $curText];
    }

    /**
     * Sets a contract's cost centre, once that column exists.
     *
     * @param int $id  the contract
     * @param ?int $ccId  the cost centre to charge it to, or null for none
     */
    private static function writeCostCentre(PDO $conn, int $id, ?int $ccId): void
    {
        if (!costCentreReady($conn, 'contracts')) return;
        $conn->prepare("UPDATE contracts SET cost_centre_id = ? WHERE id = ?")->execute([$ccId, $id]);
    }

    /**
     * Sets a contract's company, once that column exists.
     *
     * @param int $id  the contract
     * @param int $tenant  the owning company as a real id; the Default company is stored as NULL
     */
    private static function writeTenant(PDO $conn, int $id, int $tenant): void
    {
        if (!contractTenantReady($conn)) return;
        $conn->prepare("UPDATE contracts SET tenant_id = ? WHERE id = ?")->execute([contractStoreTenant($conn, $tenant), $id]);
    }

    /**
     * A contract in a company the actor cannot reach is reported as not found,
     * never forbidden, so ids cannot be probed. By the ACTOR's company scope, not
     * the analyst's: an API key can be narrower than the analyst it acts as.
     *
     * @param int $id  the contract to check
     */
    private static function assertVisible(PDO $conn, ActorContext $ctx, int $id): void
    {
        [$sql, $args] = contractVisibilitySqlForScope($conn, $ctx->companyScope, 'c');
        $st = $conn->prepare("SELECT 1 FROM contracts c WHERE c.id = ?$sql");
        $st->execute(array_merge([$id], $args));
        if (!$st->fetchColumn()) {
            throw new ServiceError('not_found', 'not_found', 'Contract not found.');
        }
    }

    /**
     * Who the contract is with. Fields not sent keep their current value, so an
     * update that only changes the title leaves a customer contract a customer
     * contract. A supplier id is checked against suppliers as before.
     *
     * @param array $in  the request fields; party_type, supplier_id, customer_tenant_id, customer_user_id
     * @param array $current  the stored contract row on update; [] on create
     */
    private static function partyFields(PDO $conn, ActorContext $ctx, array $in, array $current): array
    {
        $pick = fn(string $k, $d = null) => array_key_exists($k, $in) ? $in[$k] : ($current[$k] ?? $d);
        $data = [
            'party_type'         => $pick('party_type', CONTRACT_PARTY_SUPPLIER),
            'supplier_id'        => $pick('supplier_id'),
            'customer_tenant_id' => $pick('customer_tenant_id'),
            'customer_user_id'   => $pick('customer_user_id'),
        ];
        if (!contractPartyReady($conn)) {
            $data['party_type'] = CONTRACT_PARTY_SUPPLIER;   // before Database Verification: suppliers only
        }
        try {
            $p = contractNormaliseParty($conn, $ctx->actorId, $data);
        } catch (InvalidArgumentException $e) {
            throw new ServiceError('validation', 'invalid_field', $e->getMessage());
        }
        $p['supplier_id'] = self::lookup($conn, 'suppliers', $p['supplier_id'], 'supplier');
        return $p;
    }

    /**
     * Sets who a contract is with (supplier or customer), once those columns exist.
     *
     * @param int $id  the contract
     * @param array $p  the party from partyFields(): party_type, customer_tenant_id, customer_user_id
     */
    private static function writeParty(PDO $conn, int $id, array $p): void
    {
        if (!contractPartyReady($conn)) return;
        $conn->prepare("UPDATE contracts SET party_type = ?, customer_tenant_id = ?, customer_user_id = ? WHERE id = ?")
             ->execute([$p['party_type'], $p['customer_tenant_id'], $p['customer_user_id'], $id]);
    }

    /**
     * The party fields of a contract as an array.
     *
     * @param array $p  the party from partyFields()
     */
    private static function partyPayload(array $p): array
    {
        return ['party_type' => $p['party_type'], 'customer_tenant_id' => $p['customer_tenant_id'], 'customer_user_id' => $p['customer_user_id']];
    }

    /**
     * Loads one row from the given table by id; throws not found with $notFoundMsg when it does not exist.
     *
     * @param string $table  the table to read, written in code - never user input
     * @param int $id  the row's id
     * @param string $notFoundMsg  the error message when it does not exist
     */
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

    /**
     * Ordered contract column values for INSERT/UPDATE (matches both statements' column order).
     *
     * @param array $f  the validated fields from contractFields(), with supplier_id and cost_centre set
     */
    private static function contractParams(array $f): array
    {
        return [
            $f['contract_number'], $f['title'], $f['description'], $f['supplier_id'], $f['contract_owner_id'],
            $f['contract_status_id'], $f['contract_start'], $f['contract_end'], $f['notice_period_days'], $f['notice_date'],
            $f['contract_value'], $f['currency'], $f['payment_schedule_id'], $f['cost_centre'], $f['dms_link'], $f['terms_status'],
            $f['personal_data_transferred'], $f['dpia_required'], $f['dpia_completed_date'], $f['dpia_dms_link'], $f['is_active'],
        ];
    }

    /**
     * Collect + validate contract fields (mirrors the API resource's apiContractFieldsFromBody).
     *
     * @param array $in  the request fields; only keys present are taken from here
     * @param array $current  the stored contract row on update, for absent keys; [] on create
     */
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
        // Optional; when given, a currency FreeITSM recognises (includes/money.php, GH #189).
        $f['currency'] = moneyServiceCurrency($get('currency'));

        $f['cost_centre']  = ($v = trim((string)$get('cost_centre', ''))) !== '' ? $v : null;
        $f['dms_link']     = ($v = trim((string)$get('dms_link', ''))) !== '' ? $v : null;
        $f['terms_status'] = ($v = trim((string)$get('terms_status', ''))) !== '' ? $v : null;
        $f['personal_data_transferred'] = $get('personal_data_transferred') === null ? null : (int)(bool)$get('personal_data_transferred');
        $f['dpia_required']             = $get('dpia_required') === null ? null : (int)(bool)$get('dpia_required');
        $f['dpia_dms_link'] = ($v = trim((string)$get('dpia_dms_link', ''))) !== '' ? $v : null;
        $f['is_active']     = $get('is_active', 1) ? 1 : 0;

        return $f;
    }

    /**
     * Validate a nullable lookup id against a table; 422 with the label on unknown.
     *
     * @param string $table  the lookup table, written in code - never user input
     * @param mixed $value  the id from the request; null or '' gives null
     * @param string $label  what the table holds, for the error message, e.g. 'supplier'
     */
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

    /**
     * Validate an analyst id exists + is active (throwing twin of apiResolveAnalyst).
     *
     * @param int $analystId  the analyst named as the contract's owner - not the actor
     */
    private static function resolveAnalyst(PDO $conn, int $analystId): void
    {
        $stmt = $conn->prepare("SELECT full_name FROM analysts WHERE id = ? AND is_active = 1");
        $stmt->execute([$analystId]);
        if ($stmt->fetchColumn() === false) {
            throw new ServiceError('validation', 'invalid_field', "Unknown or inactive analyst id: {$analystId}");
        }
    }

    /**
     * Validate an optional YYYY-MM-DD date (throwing twin of apiParseDateOnly).
     *
     * @param mixed $value  the date from the request, Y-m-d; null or '' gives null
     * @param string $field  the request field name, used in the error message
     */
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
