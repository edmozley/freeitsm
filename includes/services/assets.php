<?php
/**
 * AssetsService — the shared write rules for assets: create, per-field update
 * (with audit trail + warranty-calendar sync), and user assignment / removal
 * (with the custody trail).
 *
 * Shared by the UI endpoints (api/assets/update_asset_field.php,
 * assign_asset_user.php, unassign_asset_user.php) and the REST API
 * (api/v1/resources/assets.php). Each adapter distils its caller into an
 * ActorContext + canonical input; this layer validates + writes and returns the
 * affected id(s) / a small result array, or throws ServiceError. It never emits
 * HTTP.
 *
 * Canonical behaviour = the API resource's, so the API stays byte-identical
 * while the UI's looser writes converge to it:
 *   - an unknown asset id is a not_found (the UI used to UPDATE 0 rows yet still
 *     write a history entry for a ghost);
 *   - lookup ids / dates are validated (422) rather than written blindly;
 *   - a no-op field write records NO history row (the UI logged one every time);
 *   - assignments require the requester to exist (422).
 *
 * Two UI-only behaviours are preserved as optional input, defaulting to the
 * API's behaviour so the API bytes don't move:
 *   - assignUser() accepts `previous_user_id` — on a re-assign the UI records the
 *     outgoing holder as the audit's old_value (the API always logs null);
 *   - unassignUser() accepts $skipAudit — the UI suppresses the intermediate
 *     history row when a re-assign removes the previous holder before adding the
 *     new one (the assign call then logs the "A -> B" transition).
 *
 * Assets are install-wide (no tenant_id), so companyScope is not consulted.
 */

require_once __DIR__ . '/../service_context.php';
require_once __DIR__ . '/../tenancy.php';
require_once __DIR__ . '/../users.php';            // userDirectoryOwnedFields()
require_once dirname(__DIR__, 2) . '/workflow/includes/engine.php';

class AssetsService
{
    /**
     * Multi-tenancy gate: refuse to touch an asset outside the actor's companies.
     * companyScope null = all companies (single-company install or an all-access
     * actor) → no gate. Framed as not-found so it never reveals another company's
     * asset. $row must include tenant_id.
     *
     * @param array $row  the asset row; must include tenant_id
     */
    private static function assertScope(PDO $conn, ActorContext $ctx, array $row): void
    {
        if ($ctx->companyScope === null) {
            return;
        }
        $tid = ($row['tenant_id'] === null) ? getDefaultTenantId($conn) : (int)$row['tenant_id'];
        if (!in_array($tid, $ctx->companyScope, true)) {
            throw new ServiceError('not_found', 'not_found', 'Asset not found.');
        }
    }

    /**
     * The editable columns, their audit field keys (the SAME stable keys the UI
     * history view localises via t('asset-management.field.<key>')), and how to
     * validate/resolve each. 'lookup'/'supplier' fields audit display NAMES.
     */
    public static function fieldMap(): array
    {
        return [
            'asset_type_id'    => ['audit' => 'type',            'kind' => 'lookup', 'table' => 'asset_types',        'label' => 'asset type'],
            'asset_status_id'  => ['audit' => 'status',          'kind' => 'lookup', 'table' => 'asset_status_types', 'label' => 'asset status'],
            'location_id'      => ['audit' => 'location',        'kind' => 'lookup', 'table' => 'asset_locations',    'label' => 'location'],
            'supplier_id'      => ['audit' => 'supplier',        'kind' => 'supplier'],
            // GH #160: a cost centre of the asset's OWN company, active unless it is
            // the one already held - checked in updateFields(), where the company is known.
            'cost_centre_id'   => ['audit' => 'cost_centre',     'kind' => 'cost_centre'],
            'purchase_date'    => ['audit' => 'purchase_date',   'kind' => 'date'],
            'purchase_cost'    => ['audit' => 'purchase_cost',   'kind' => 'decimal'],
            'order_number'     => ['audit' => 'order_number',    'kind' => 'string', 'max' => 100],
            'warranty_expiry'  => ['audit' => 'warranty_expiry', 'kind' => 'date'],
            'lease_start'      => ['audit' => 'lease_start',     'kind' => 'date'],
            'lease_expiry'     => ['audit' => 'lease_expiry',    'kind' => 'date'],
            'hostname'         => ['audit' => 'hostname',         'kind' => 'string', 'max' => 50],
            'manufacturer'     => ['audit' => 'manufacturer',     'kind' => 'string', 'max' => 50],
            'model'            => ['audit' => 'model',            'kind' => 'string', 'max' => 50],
            'service_tag'      => ['audit' => 'service_tag',      'kind' => 'string', 'max' => 50],
            'memory'           => ['audit' => 'memory',           'kind' => 'int'],
            'operating_system' => ['audit' => 'operating_system', 'kind' => 'string', 'max' => 50],
            'feature_release'  => ['audit' => 'feature_release',  'kind' => 'string', 'max' => 10],
            'build_number'     => ['audit' => 'build_number',     'kind' => 'string', 'max' => 50],
            'cpu_name'         => ['audit' => 'cpu_name',         'kind' => 'string', 'max' => 250],
            'speed'            => ['audit' => 'speed',            'kind' => 'int'],
            'bios_version'     => ['audit' => 'bios_version',     'kind' => 'string', 'max' => 20],
            'gpu_name'         => ['audit' => 'gpu_name',         'kind' => 'string', 'max' => 250],
            'tpm_version'      => ['audit' => 'tpm_version',      'kind' => 'string', 'max' => 50],
            'bitlocker_status' => ['audit' => 'bitlocker_status', 'kind' => 'string', 'max' => 20],
            'domain'           => ['audit' => 'domain',           'kind' => 'string', 'max' => 100],
            'logged_in_user'   => ['audit' => 'logged_in_user',   'kind' => 'string', 'max' => 100],
        ];
    }

    // ======================================================================
    //  Writes
    // ======================================================================

    /**
     * Create an asset (identified by its unique hostname). Returns the new id.
     * $creationNote is the audit new_value for the 'asset_created' row (the API
     * records the acting key, the UI records the analyst — see
     * api/assets/create_asset.php, added in #1132 because a television cannot
     * run the inventory agent).
     *
     * @param array $in  hostname (required), fieldMap() keys, asset_tag and optional fields:{field_key:value}
     * @param string $creationNote  the new_value of the 'asset_created' history row, e.g. who or what created it
     */
    public static function createAsset(PDO $conn, ActorContext $ctx, array $in, string $creationNote, ?int $tenantId = null): int
    {
        $customValues = self::customValues($in);
        $hostname = trim((string)($in['hostname'] ?? ''));
        if ($hostname === '') {
            throw new ServiceError('validation', 'missing_field', "'hostname' is required.");
        }
        if (mb_strlen($hostname) > 50) {
            throw new ServiceError('validation', 'invalid_field', "'hostname' must be at most 50 characters.");
        }

        // Multi-tenancy: normalise the Default company to NULL so API-created and
        // agent-created assets store the same thing (every read treats NULL as the
        // Default company).
        $storeTenant = ($tenantId !== null && $tenantId === getDefaultTenantId($conn)) ? null : $tenantId;

        // hostname is unique per company, and the inventory sources fall back to
        // it when a machine has no usable serial (resolveAssetIdentity()) — a
        // duplicate would split an asset's records, so refuse rather than fork.
        // Scoped to the target company (NULL-safe) so two companies may each hold
        // a "LAPTOP-01".
        $dup = $conn->prepare("SELECT id FROM assets WHERE hostname = ? AND tenant_id <=> ?");
        $dup->execute([$hostname, $storeTenant]);
        $existingId = $dup->fetchColumn();
        if ($existingId !== false) {
            throw new ServiceError('conflict', 'conflict', "An asset with this hostname already exists (id {$existingId}). Use PATCH /assets/{$existingId} to update it.");
        }

        $map = self::fieldMap();
        unset($map['hostname']); // handled above
        $columns = ['hostname'];
        $values  = [$hostname];
        foreach ($map as $field => $def) {
            if (!array_key_exists($field, $in)) {
                continue;
            }
            if ($def['kind'] === 'cost_centre') {
                // GH #160: one of the NEW asset's company, and active.
                require_once __DIR__ . '/../cost_centre_assign.php';
                if (!costCentreReady($conn, 'assets')) continue;
                $columns[] = $field;
                $values[]  = costCentreCheck($conn, costCentreRecordTenant($conn, $tenantId), $in[$field], null);
                continue;
            }
            $columns[] = $field;
            $values[]  = self::validateField($conn, $field, $in[$field], $def);
        }
        self::assertLeaseOrder($in, []);
        $columns[] = 'tenant_id';
        $values[]  = $storeTenant;

        // The tag: typed in, generated (when the company has it switched on and
        // nothing was typed), or none. AssetTagsService owns the lock, the
        // uniqueness check and the counter, and runs the insert below inside the
        // same transaction, so a failed create never burns a number (PR #164).
        require_once __DIR__ . '/asset_tags.php';
        $created = AssetTagsService::createWithTag(
            $conn,
            $storeTenant,
            array_key_exists('asset_tag', $in) ? (string)$in['asset_tag'] : null,
            function (?string $tag) use ($conn, $ctx, $columns, $values, $creationNote, $customValues): int {
                if ($tag !== null) {
                    $columns[] = 'asset_tag';
                    $values[]  = $tag;
                }
                // 🔑 first_seen ONLY. `last_seen` means "when did an agent last report
                // this machine", and nothing has ever reported a television, a SIM card
                // or a meeting-room monitor — the very things this path exists to add.
                //
                // It used to stamp both, which was invisible while last_seen was shown
                // nowhere. Now that the asset screen and the asset table both show it
                // (#1578), a hand-added television would read "21 days ago" in amber, as
                // though it had stopped reporting, and would sit in the Watchtower "not
                // seen" count alongside machines that genuinely have. NULL is what makes
                // the screen able to say **Never reported** instead, and it is the
                // truthful answer rather than a convenient one.
                //
                // first_seen stays: when the record was made is a real fact about it.
                $placeholders = implode(', ', array_fill(0, count($columns), '?'));
                $conn->prepare("INSERT INTO assets (" . implode(', ', $columns) . ", first_seen)
                                VALUES ($placeholders, UTC_TIMESTAMP())")->execute($values);
                $assetId = (int)$conn->lastInsertId();
                self::auditWrite($conn, $assetId, $ctx->actorId, 'asset_created', null, $creationNote);
                // GH #194: use the UI's type/applicability checks and field history.
                // Inside the tag transaction so invalid custom values leave no asset
                // (and consume no generated tag number).
                if ($customValues) {
                    require_once __DIR__ . '/asset_fields.php';
                    AssetFieldsService::saveValues($conn, $ctx, $assetId, $customValues);
                }
                return $assetId;
            }
        );
        $assetId = $created['id'];

        if ((array_key_exists('warranty_expiry', $in) && $in['warranty_expiry'])
            || (array_key_exists('lease_expiry', $in) && $in['lease_expiry'])) {
            self::syncWarranty($conn);
        }
        return $assetId;
    }

    /**
     * Apply a partial set of field updates to an asset. Writes one audit row per
     * changed field (no-ops are skipped) and re-syncs the warranty calendar when
     * warranty_expiry moves. Returns void; the adapter reloads for its response.
     *
     * @param array $in  fieldMap() keys and optional fields:{field_key:value}; omitted values are kept
     */
    public static function updateFields(PDO $conn, ActorContext $ctx, int $assetId, array $in): void
    {
        $current = self::loadRow($conn, $assetId);   // 404 if gone
        self::assertScope($conn, $ctx, $current);    // 404 if in another company
        $customValues = self::customValues($in);
        if (!$in) {
            throw new ServiceError('validation', 'missing_field', 'No fields to update.');
        }

        $map = self::fieldMap();
        $updates = [];
        $args    = [];
        $audits  = [];   // [fieldKey, oldDisplay, newDisplay]
        $warrantyChanged = false;

        foreach ($in as $field => $rawValue) {
            if (!isset($map[$field])) {
                continue; // unknown fields ignored, like the internal endpoints
            }
            $def = $map[$field];
            if ($def['kind'] === 'cost_centre') {
                require_once __DIR__ . '/../cost_centre_assign.php';
                if (!costCentreReady($conn, 'assets')) continue;   // before Database Verification
                $newValue = costCentreCheck($conn, costCentreRecordTenant($conn, $current['tenant_id'] ?? null), $rawValue,
                                            $current['cost_centre_id'] !== null ? (int)$current['cost_centre_id'] : null);
            } else {
                $newValue = self::validateField($conn, $field, $rawValue, $def);
            }

            if ($field === 'hostname') {
                if ($newValue === null) {
                    throw new ServiceError('validation', 'invalid_field', "'hostname' cannot be blank.");
                }
                // Scoped to this asset's own company (NULL-safe) — a matching
                // hostname in another company is not a clash.
                $dup = $conn->prepare("SELECT id FROM assets WHERE hostname = ? AND id != ? AND tenant_id <=> ?");
                $dup->execute([$newValue, $assetId, $current['tenant_id']]);
                if ($dup->fetchColumn()) {
                    throw new ServiceError('conflict', 'conflict', 'Another asset already uses this hostname.');
                }
            }

            // Normalise the current value the same way for change detection.
            $oldValue = $current[$field];
            if (in_array($def['kind'], ['lookup', 'supplier', 'int', 'cost_centre'], true) && $oldValue !== null) {
                $oldValue = (int)$oldValue;
            }
            $comparableNew = ($def['kind'] === 'decimal' && $newValue !== null) ? (float)$newValue : $newValue;
            $comparableOld = ($def['kind'] === 'decimal' && $oldValue !== null) ? (float)$oldValue : $oldValue;
            if ($comparableNew === $comparableOld || (string)$comparableNew === (string)$comparableOld && $comparableNew !== null && $comparableOld !== null) {
                continue; // no actual change
            }

            $updates[] = "$field = ?";
            $args[]    = $newValue;
            $audits[]  = [
                $def['audit'],
                self::auditDisplay($conn, $field, $oldValue, $def),
                self::auditDisplay($conn, $field, $newValue, $def),
            ];
            // Either date drives the same calendar pass, so either one changing
            // is a reason to run it.
            if ($field === 'warranty_expiry' || $field === 'lease_expiry') {
                $warrantyChanged = true;
            }
        }

        if (!$updates && !$customValues) {
            return; // idempotent — nothing to write
        }
        self::assertLeaseOrder($in, $current);

        // Core edits, custom values and their audit must succeed together. Resolve
        // custom applicability AFTER a type change, inside this same transaction.
        $owns = !$conn->inTransaction();
        if ($owns) { $conn->beginTransaction(); }
        try {
            if ($updates) {
                $args[] = $assetId;
                $conn->prepare('UPDATE assets SET ' . implode(', ', $updates) . ' WHERE id = ?')->execute($args);
            }
            if ($customValues) {
                require_once __DIR__ . '/asset_fields.php';
                AssetFieldsService::saveValues($conn, $ctx, $assetId, $customValues);
            }
            foreach ($audits as [$fieldKey, $old, $new]) {
                self::auditWrite($conn, $assetId, $ctx->actorId, $fieldKey, $old, $new);
            }
            if ($warrantyChanged) { self::syncWarranty($conn); }
            // Leases (3.5.0): propagate edited dates to the lease schedule.
            if (array_key_exists('lease_start', $in) || array_key_exists('lease_expiry', $in)) {
                require_once __DIR__ . '/../leases.php';
                leaseAssetDatesEdited($conn, $assetId);
            }
            if ($owns) { $conn->commit(); }
        } catch (Throwable $e) {
            if ($owns && $conn->inTransaction()) { $conn->rollBack(); }
            throw $e;
        }
    }

    /** Validate the optional custom-field map; absent/empty maps change nothing. */
    private static function customValues(array $in): array
    {
        if (!array_key_exists('fields', $in)) { return []; }
        if (!is_array($in['fields'])) {
            throw new ServiceError('validation', 'invalid_field', "'fields' must be an object keyed by custom field key.");
        }
        return $in['fields'];
    }

    /**
     * Assign an ANALYST to an asset — the desk's own kit.
     *
     * 🔑 Why this exists at all. Assets could only be held by a REQUESTER, and
     * analysts are not requesters: on a real install five of seven analysts had
     * no `users` row, so most of the desk could not be recorded as holding
     * anything. Asked for in the 2.5.0 request list.
     *
     * 🔴 EXACTLY ONE HOLDER COLUMN IS SET. A row names a requester or an
     * analyst, never both. The database cannot express that (a CHECK across two
     * columns is not portable to every MySQL version supported here), so it is
     * enforced here and nowhere else — which is precisely why assignment must
     * go through this service rather than an INSERT somewhere convenient.
     *
     * $in: analyst_id | analyst_email, plus optional notes,
     * expected_return_date, previous_analyst_id.
     *
     * @param array $in  analyst_id or analyst_email, plus optional notes, expected_return_date (Y-m-d), previous_analyst_id
     */
    public static function assignAnalyst(PDO $conn, ActorContext $ctx, int $assetId, array $in): array
    {
        self::loadRow($conn, $assetId);   // 404 if gone
        $actorId = $ctx->actorId;

        if (isset($in['analyst_id']) && $in['analyst_id'] !== '') {
            $a = $conn->prepare("SELECT id, full_name FROM analysts WHERE id = ? AND is_active = 1");
            $a->execute([(int)$in['analyst_id']]);
        } elseif (isset($in['analyst_email']) && trim((string)$in['analyst_email']) !== '') {
            $a = $conn->prepare("SELECT id, full_name FROM analysts WHERE email = ? AND is_active = 1");
            $a->execute([strtolower(trim((string)$in['analyst_email']))]);
        } else {
            throw new ServiceError('validation', 'missing_field', "Provide 'analyst_id' or 'analyst_email'.");
        }
        $row = $a->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            // Inactive is named separately from unknown: "there is no such
            // analyst" sends somebody looking for a typo that is not there.
            throw new ServiceError('validation', 'invalid_field', 'Unknown or inactive analyst.');
        }
        $analystId   = (int)$row['id'];
        $analystName = $row['full_name'];

        $notes = trim((string)($in['notes'] ?? '')) ?: null;
        $expectedReturn = self::parseDate($in['expected_return_date'] ?? null, 'expected_return_date');

        $check = $conn->prepare("SELECT id FROM users_assets WHERE asset_id = ? AND analyst_id = ?");
        $check->execute([$assetId, $analystId]);
        if ($check->fetchColumn()) {
            throw new ServiceError('conflict', 'conflict', 'This analyst is already assigned to this asset.');
        }

        // user_id is left NULL. That column was NOT NULL until this feature; see
        // api/system/db_verify.php, which relaxes it on an existing install.
        $conn->prepare(
            "INSERT INTO users_assets (asset_id, analyst_id, assigned_by_analyst_id, notes, expected_return_date, assigned_datetime)
             VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP())"
        )->execute([$assetId, $analystId, $actorId, $notes, $expectedReturn]);

        // Custody trail, best-effort as elsewhere. asset_checkout_log.user_id
        // names a requester, so an analyst handover records the NAME and leaves
        // the id NULL rather than writing an analyst id into a requester column.
        try {
            $conn->prepare(
                "INSERT INTO asset_checkout_log (asset_id, user_id, user_name, action, expected_return_date, analyst_id, notes, action_datetime)
                 VALUES (?, NULL, ?, 'checkout', ?, ?, ?, UTC_TIMESTAMP())"
            )->execute([$assetId, $analystName, $expectedReturn, $actorId, $notes]);
        } catch (Exception $clogEx) { /* custody log not critical */ }

        $oldName = null;
        if (!empty($in['previous_analyst_id'])) {
            $prev = $conn->prepare("SELECT full_name FROM analysts WHERE id = ?");
            $prev->execute([(int)$in['previous_analyst_id']]);
            $prevRow = $prev->fetch(PDO::FETCH_ASSOC);
            $oldName = $prevRow ? $prevRow['full_name'] : (string)$in['previous_analyst_id'];
        }
        self::auditWrite($conn, $assetId, $actorId, 'assigned_analyst', $oldName, $analystName);

        self::dispatch('asset.assigned', $conn, $assetId, 0, $analystName);

        return [
            'asset_id'             => $assetId,
            'analyst_id'           => $analystId,
            'name'                 => $analystName,
            'expected_return_date' => $expectedReturn,
            'notes'                => $notes,
        ];
    }

    /**
     * Assign a requester to an asset. $in: user_id | user_email, plus optional
     * notes, expected_return_date, previous_user_id (UI re-assign old_value).
     * Returns [asset_id, user_id, name, expected_return_date, notes].
     *
     * @param array $in  user_id or user_email, plus optional notes, expected_return_date (Y-m-d), previous_user_id
     */
    public static function assignUser(PDO $conn, ActorContext $ctx, int $assetId, array $in): array
    {
        self::loadRow($conn, $assetId);   // 404 if gone
        $actorId = $ctx->actorId;

        // Accept user_id or user_email (must be an existing requester).
        if (isset($in['user_id']) && $in['user_id'] !== '') {
            $u = $conn->prepare("SELECT id, display_name FROM users WHERE id = ?");
            $u->execute([(int)$in['user_id']]);
        } elseif (isset($in['user_email']) && trim((string)$in['user_email']) !== '') {
            $u = $conn->prepare("SELECT id, display_name FROM users WHERE email = ?");
            $u->execute([strtolower(trim((string)$in['user_email']))]);
        } else {
            throw new ServiceError('validation', 'missing_field', "Provide 'user_id' or 'user_email'.");
        }
        $userRow = $u->fetch(PDO::FETCH_ASSOC);
        if (!$userRow) {
            throw new ServiceError('validation', 'invalid_field', 'Unknown requester. Create them first with POST /users.');
        }
        $userId   = (int)$userRow['id'];
        $userName = $userRow['display_name'];

        $notes = trim((string)($in['notes'] ?? '')) ?: null;
        $expectedReturn = self::parseDate($in['expected_return_date'] ?? null, 'expected_return_date');

        $check = $conn->prepare("SELECT id FROM users_assets WHERE asset_id = ? AND user_id = ?");
        $check->execute([$assetId, $userId]);
        if ($check->fetchColumn()) {
            throw new ServiceError('conflict', 'conflict', 'This user is already assigned to this asset.');
        }

        $conn->prepare(
            "INSERT INTO users_assets (asset_id, user_id, assigned_by_analyst_id, notes, expected_return_date, assigned_datetime)
             VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP())"
        )->execute([$assetId, $userId, $actorId, $notes, $expectedReturn]);

        // Custody trail (best-effort, like the UI).
        try {
            $conn->prepare(
                "INSERT INTO asset_checkout_log (asset_id, user_id, user_name, action, expected_return_date, analyst_id, notes, action_datetime)
                 VALUES (?, ?, ?, 'checkout', ?, ?, ?, UTC_TIMESTAMP())"
            )->execute([$assetId, $userId, $userName, $expectedReturn, $actorId, $notes]);
        } catch (Exception $clogEx) { /* custody log not critical */ }

        // On a UI re-assign the outgoing holder is the audit's old_value.
        $oldName = null;
        if (!empty($in['previous_user_id'])) {
            $prev = $conn->prepare("SELECT display_name FROM users WHERE id = ?");
            $prev->execute([(int)$in['previous_user_id']]);
            $prevRow = $prev->fetch(PDO::FETCH_ASSOC);
            $oldName = $prevRow ? $prevRow['display_name'] : (string)$in['previous_user_id'];
        }
        self::auditWrite($conn, $assetId, $actorId, 'assigned_user', $oldName, $userName);

        self::dispatch('asset.assigned', $conn, $assetId, $userId, $userName);

        return [
            'asset_id'             => $assetId,
            'user_id'              => $userId,
            'name'                 => $userName,
            'expected_return_date' => $expectedReturn,
            'notes'                => $notes,
        ];
    }

    /**
     * Remove a requester from an asset. $skipAudit suppresses the history row
     * (the UI's re-assign removes the previous holder silently, then the assign
     * logs the transition). Returns [asset_id, user_id].
     *
     * @param int $userId  the requester (users.id) to remove from the asset
     * @param bool $skipAudit  true to write no history row (a re-assign logs the change itself)
     */
    public static function unassignUser(PDO $conn, ActorContext $ctx, int $assetId, int $userId, bool $skipAudit = false): array
    {
        self::loadRow($conn, $assetId);   // 404 if gone
        $actorId = $ctx->actorId;

        // Snapshot holder + due-back before removal, for the custody trail + audit.
        // Filtered on user_id, so this only ever sees a requester assignment —
        // an analyst one is removed by unassignAnalyst() below.
        $snap = $conn->prepare(
            "SELECT u.display_name, ua.expected_return_date
             FROM users_assets ua INNER JOIN users u ON u.id = ua.user_id
             WHERE ua.asset_id = ? AND ua.user_id = ?"
        );
        $snap->execute([$assetId, $userId]);
        $row = $snap->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new ServiceError('not_found', 'not_found', 'Assignment not found.');
        }

        $conn->prepare("DELETE FROM users_assets WHERE asset_id = ? AND user_id = ?")->execute([$assetId, $userId]);

        try {
            $conn->prepare(
                "INSERT INTO asset_checkout_log (asset_id, user_id, user_name, action, expected_return_date, analyst_id, action_datetime)
                 VALUES (?, ?, ?, 'checkin', ?, ?, UTC_TIMESTAMP())"
            )->execute([$assetId, $userId, $row['display_name'], $row['expected_return_date'], $actorId]);
        } catch (Exception $clogEx) { /* custody log not critical */ }

        if (!$skipAudit) {
            self::auditWrite($conn, $assetId, $actorId, 'assigned_user', $row['display_name'], null);
        }

        self::dispatch('asset.unassigned', $conn, $assetId, $userId, $row['display_name']);

        return ['asset_id' => $assetId, 'user_id' => $userId];
    }

    /**
     * Remove an analyst from an asset — the mirror of unassignUser().
     *
     * Separate for the same reason assignAnalyst() is: it matches on a different
     * column, and a shared function would be an `if` at every line.
     *
     * @param int $analystId  the analyst HOLDING the asset who is removed - not the one acting, who is in $ctx
     * @param bool $skipAudit  true to write no history row (a re-assign logs the change itself)
     */
    public static function unassignAnalyst(PDO $conn, ActorContext $ctx, int $assetId, int $analystId, bool $skipAudit = false): array
    {
        self::loadRow($conn, $assetId);   // 404 if gone
        $actorId = $ctx->actorId;

        $snap = $conn->prepare(
            "SELECT a.full_name, ua.expected_return_date
             FROM users_assets ua INNER JOIN analysts a ON a.id = ua.analyst_id
             WHERE ua.asset_id = ? AND ua.analyst_id = ?"
        );
        $snap->execute([$assetId, $analystId]);
        $row = $snap->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new ServiceError('not_found', 'not_found', 'Assignment not found.');
        }

        $conn->prepare("DELETE FROM users_assets WHERE asset_id = ? AND analyst_id = ?")->execute([$assetId, $analystId]);

        // As in assignAnalyst: the custody log's user_id names a REQUESTER, so an
        // analyst handover records the name and leaves the id NULL rather than
        // writing an analyst id into a requester column, where it would later be
        // read back as whichever requester happened to share that number.
        try {
            $conn->prepare(
                "INSERT INTO asset_checkout_log (asset_id, user_id, user_name, action, expected_return_date, analyst_id, action_datetime)
                 VALUES (?, NULL, ?, 'checkin', ?, ?, UTC_TIMESTAMP())"
            )->execute([$assetId, $row['full_name'], $row['expected_return_date'], $actorId]);
        } catch (Exception $clogEx) { /* custody log not critical */ }

        if (!$skipAudit) {
            self::auditWrite($conn, $assetId, $actorId, 'assigned_analyst', $row['full_name'], null);
        }

        self::dispatch('asset.unassigned', $conn, $assetId, 0, $row['full_name']);

        return ['asset_id' => $assetId, 'analyst_id' => $analystId];
    }

    /**
     * Fire an asset.* workflow event (best-effort; the engine swallows its own errors).
     *
     * @param string $event  the workflow event, 'asset.assigned' or 'asset.unassigned'
     * @param int $userId  the requester holding it, or 0 when the holder is an analyst
     * @param ?string $userName  the holder's display name
     */
    private static function dispatch(string $event, PDO $conn, int $assetId, int $userId, ?string $userName): void
    {
        try {
            $hostname = $conn->query("SELECT hostname FROM assets WHERE id = " . (int)$assetId)->fetchColumn();
            WorkflowEngine::dispatch($event, [
                'asset' => ['id' => $assetId, 'hostname' => $hostname !== false ? $hostname : null],
                'user'  => ['id' => $userId, 'name' => $userName],
            ]);
        } catch (Exception $wfEx) {
            error_log('Workflow dispatch error in asset service (' . $event . '): ' . $wfEx->getMessage());
        }
    }

    /**
     * Move an asset to another company (2.6.0, from a customer's request list).
     *
     * The asset twin of TasksService::moveTaskToCompany, gated the same way: the
     * actor must reach the asset where it is AND the company it is going to,
     * checked against BOTH companyScope (what this caller may touch) and
     * analystCanAccessTenant (what the person may reach).
     *
     * An asset carries more company-owned things than a task, and they split
     * into two kinds on purpose:
     *
     *  CLEARED, and reported: its LOCATION, TYPE and STATUS when they are the old
     *  company's own. They simply do not exist in the new company, so keeping them
     *  would leave the asset wearing a value its own company cannot see or pick.
     *  A shared location, and a global type or status, move with it untouched.
     *
     *  REFUSED, with the reason: a HOSTNAME or ASSET TAG the new company already
     *  uses (both are unique per company, and agent ingest matches on hostname),
     *  and a HOLDER who is a person in another company. Those need a decision
     *  from the person, not a guess made on their behalf.
     *
     * An analyst holder moves with it: analysts are not company-scoped.
     *
     * @param int $targetTenantId  the company (tenant) id to move the asset to
     * @return array{moved:bool, from:string, to:string, cleared:string[]}
     *         `cleared` lists the audit keys (location/type/status) that were
     *         emptied, so the UI can say what changed.
     */
    public static function moveToCompany(PDO $conn, ActorContext $ctx, int $assetId, int $targetTenantId): array
    {
        require_once __DIR__ . '/../asset_locations.php';

        $row = self::loadRow($conn, $assetId);
        self::assertScope($conn, $ctx, $row);                    // 404 if out of scope

        if (!isMultiTenant($conn)) {
            throw new ServiceError('validation', 'invalid_field',
                'This install has only one company, so there is nowhere to move an asset to.');
        }
        // One message for "no access" and "no such company", so probing ids
        // cannot reveal which companies exist.
        if (($ctx->companyScope !== null && !in_array($targetTenantId, $ctx->companyScope, true))
                || !analystCanAccessTenant($conn, $ctx->actorId, $targetTenantId)) {
            throw new ServiceError('validation', 'invalid_field', 'You do not have access to that company.');
        }
        $target = getTenantById($conn, $targetTenantId);
        if (!$target) {
            throw new ServiceError('validation', 'invalid_field', 'You do not have access to that company.');
        }

        // NULL means the Default company, so resolve it before comparing, or a
        // Default asset "moved" into Default would write a nonsense audit line.
        $defaultId   = getDefaultTenantId($conn);
        $oldTenantId = ($row['tenant_id'] === null) ? $defaultId : (int)$row['tenant_id'];
        if ($oldTenantId === $targetTenantId) {
            return ['moved' => false, 'from' => $target['name'], 'to' => $target['name'], 'cleared' => []];
        }
        $oldName     = getTenantById($conn, $oldTenantId)['name'] ?? 'Unknown';
        $storeTenant = ($targetTenantId === $defaultId) ? null : $targetTenantId;

        // --- refusals --------------------------------------------------------
        $dup = $conn->prepare("SELECT id FROM assets WHERE hostname = ? AND id <> ? AND tenant_id <=> ?");
        $dup->execute([$row['hostname'], $assetId, $storeTenant]);
        if ($dup->fetchColumn() !== false) {
            throw new ServiceError('conflict', 'conflict',
                "{$target['name']} already has an asset called {$row['hostname']}. Rename one of them first.");
        }
        if (!empty($row['asset_tag'])) {
            $tag = $conn->prepare("SELECT id FROM assets WHERE asset_tag = ? AND id <> ? AND tenant_id <=> ?");
            $tag->execute([$row['asset_tag'], $assetId, $storeTenant]);
            if ($tag->fetchColumn() !== false) {
                throw new ServiceError('conflict', 'conflict',
                    "{$target['name']} already uses the asset tag {$row['asset_tag']}. Change one of them first.");
            }
        }
        // A person holding it who is not in the new company. NULL = Default.
        $h = $conn->prepare(
            "SELECT COALESCE(NULLIF(TRIM(u.display_name), ''), u.username) AS name
               FROM users_assets ua JOIN users u ON u.id = ua.user_id
              WHERE ua.asset_id = ? AND COALESCE(u.tenant_id, ?) <> ?"
        );
        $h->execute([$assetId, $defaultId, $targetTenantId]);
        $holders = $h->fetchAll(PDO::FETCH_COLUMN);
        if ($holders) {
            throw new ServiceError('validation', 'invalid_field',
                'This asset is held by ' . implode(', ', $holders) . ", who is not in {$target['name']}. Unassign it first.");
        }

        // Out on a lease: the lease belongs to the old company (3.5.0).
        require_once __DIR__ . '/../leases.php';
        if (leaseReady($conn)) {
            $ls = $conn->prepare("SELECT l.name FROM lease_assets la JOIN lease_schedules s ON s.id = la.schedule_id JOIN leases l ON l.id = s.lease_id
                                   LEFT JOIN lease_outcomes lo ON lo.id = la.outcome_id WHERE la.asset_id = ? AND " . leaseActiveSql() . " LIMIT 1");
            $ls->execute([$assetId]);
            if (($leaseName = $ls->fetchColumn()) !== false) {
                throw new ServiceError('validation', 'invalid_field',
                    "This asset is on lease {$leaseName}, which belongs to {$oldName}. Mark it returned or take it off the lease first.");
            }
        }

        // --- what does not exist in the new company -------------------------
        $clear = [];   // column => audit key
        if (!empty($row['location_id'])) {
            [$lSql, $lArgs] = assetLocationScope($conn, $targetTenantId, '');
            $lc = $conn->prepare("SELECT id FROM asset_locations WHERE id = ?" . $lSql);
            $lc->execute(array_merge([(int)$row['location_id']], $lArgs));
            if (!$lc->fetchColumn()) { $clear['location_id'] = 'location'; }
        }
        foreach ([['asset_type_id', 'asset_types', 'asset_type', 'type'],
                  ['asset_status_id', 'asset_status_types', 'asset_status_type', 'status']] as [$col, $table, $entity, $key]) {
            if (empty($row[$col])) continue;
            $ids = array_map(fn($r) => (int)$r['id'],
                             getTenantConfigRows($conn, $table, $entity, $targetTenantId, 'id'));
            if (!in_array((int)$row[$col], $ids, true)) { $clear[$col] = $key; }
        }

        $map = self::fieldMap();
        $ownTransaction = !$conn->inTransaction();
        if ($ownTransaction) { $conn->beginTransaction(); }
        try {
            $sets = ['tenant_id = ?'];
            $args = [$storeTenant];
            foreach (array_keys($clear) as $col) { $sets[] = "$col = NULL"; }
            $args[] = $assetId;
            $conn->prepare("UPDATE assets SET " . implode(', ', $sets) . " WHERE id = ?")->execute($args);

            self::auditWrite($conn, $assetId, $ctx->actorId, 'company', $oldName, $target['name']);
            foreach ($clear as $col => $key) {
                self::auditWrite($conn, $assetId, $ctx->actorId, $key,
                                 self::auditDisplay($conn, $col, $row[$col], $map[$col]), null);
            }
            if ($ownTransaction) { $conn->commit(); }
        } catch (Throwable $t) {
            if ($ownTransaction && $conn->inTransaction()) { $conn->rollBack(); }
            throw $t;
        }

        return ['moved' => true, 'from' => $oldName, 'to' => $target['name'], 'cleared' => array_values($clear)];
    }

    // ======================================================================
    //  Internals
    // ======================================================================

    /** Load the base asset row for write guards + change detection; 404 if unknown. */
    private static function loadRow(PDO $conn, int $assetId): array
    {
        $stmt = $conn->prepare("SELECT * FROM assets WHERE id = ?");
        $stmt->execute([$assetId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new ServiceError('not_found', 'not_found', 'Asset not found.');
        }
        return $row;
    }

    /**
     * Records one field change in the asset's history; analyst 0 (an inventory source or
     * Intune) is stored as NULL.
     *
     * @param string $fieldKey  the history field_name - a fieldMap() audit key or an event such as 'asset_created'
     * @param ?string $old  the value before, as shown in history (null = not set)
     * @param ?string $new  the value after, as shown in history (null = not set)
     */
    private static function auditWrite(PDO $conn, int $assetId, int $analystId, string $fieldKey, ?string $old, ?string $new): void
    {
        // No analyst (0): an inventory source or Intune made the change. NULL,
        // not 0 - 0 would point the foreign key at an analyst who does not
        // exist (PR #164; Database Verification relaxes the column).
        $conn->prepare(
            "INSERT INTO asset_history (asset_id, analyst_id, field_name, old_value, new_value, created_datetime)
             VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP())"
        )->execute([$assetId, $analystId > 0 ? $analystId : null, $fieldKey, $old, $new]);
    }

    /**
     * Validate a DATE field (YYYY-MM-DD); 422 naming the field. Null/'' clears.
     *
     * @param mixed $value  the date from the request, Y-m-d; null or '' gives null
     * @param string $field  the request field name, used in the error message
     */
    private static function parseDate($value, string $field): ?string
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

    /**
     * Validate one incoming field value per its map entry. Returns the DB-ready value.
     *
     * @param string $field  the asset column being set, a fieldMap() key
     * @param mixed $value  the value from the request; '' or null gives null
     * @param array $def  that column's fieldMap() entry (kind, table, label, max)
     */
    private static function validateField(PDO $conn, string $field, $value, array $def)
    {
        if ($value === '' || $value === null) {
            return null;
        }
        switch ($def['kind']) {
            case 'lookup':
                $stmt = $conn->prepare("SELECT id FROM {$def['table']} WHERE id = ?");
                $stmt->execute([(int)$value]);
                if (!$stmt->fetchColumn()) {
                    throw new ServiceError('validation', 'invalid_field', "Unknown {$def['label']} id: {$value}");
                }
                return (int)$value;
            case 'supplier':
                $stmt = $conn->prepare("SELECT id FROM suppliers WHERE id = ?");
                $stmt->execute([(int)$value]);
                if (!$stmt->fetchColumn()) {
                    throw new ServiceError('validation', 'invalid_field', "Unknown supplier id: {$value}");
                }
                return (int)$value;
            case 'date':
                return self::parseDate($value, $field);
            case 'int':
                if (!is_numeric($value)) {
                    throw new ServiceError('validation', 'invalid_field', "'{$field}' must be a number.");
                }
                return (int)$value;
            case 'decimal':
                if (!is_numeric($value)) {
                    throw new ServiceError('validation', 'invalid_field', "'{$field}' must be a number.");
                }
                return (string)round((float)$value, 2);
            default: // string
                $v = trim((string)$value);
                if (isset($def['max']) && mb_strlen($v) > $def['max']) {
                    throw new ServiceError('validation', 'invalid_field', "'{$field}' must be at most {$def['max']} characters.");
                }
                return $v === '' ? null : $v;
        }
    }

    /**
     * A lease cannot end before it starts. Checked on the dates as they will be
     * after the write (what was sent, else what is stored), so changing either one
     * alone is held to the other.
     *
     * @param array $in  the fields being written; lease_start / lease_expiry here win
     * @param array $current  the asset row as stored ([] on create)
     */
    private static function assertLeaseOrder(array $in, array $current): void
    {
        $pick = function (string $k) use ($in, $current) {
            $v = array_key_exists($k, $in) ? $in[$k] : ($current[$k] ?? null);
            return ($v === null || $v === '') ? null : substr((string)$v, 0, 10);
        };
        $start = $pick('lease_start');
        $end   = $pick('lease_expiry');
        if ($start !== null && $end !== null && $start > $end) {
            throw new ServiceError('validation', 'invalid_field', 'The lease cannot end before it starts.');
        }
    }

    /**
     * Resolve a lookup id to its display name for the audit trail.
     *
     * @param string $field  the asset column, a fieldMap() key
     * @param mixed $value  the stored value - an id for lookup, supplier and cost centre columns
     * @param array $def  that column's fieldMap() entry
     */
    private static function auditDisplay(PDO $conn, string $field, $value, array $def): ?string
    {
        if ($value === null) {
            return null;
        }
        if ($def['kind'] === 'lookup') {
            $stmt = $conn->prepare("SELECT name FROM {$def['table']} WHERE id = ?");
            $stmt->execute([(int)$value]);
            $name = $stmt->fetchColumn();
            return $name !== false ? $name : (string)$value;
        }
        if ($def['kind'] === 'cost_centre') {
            require_once __DIR__ . '/../cost_centre_assign.php';
            return costCentreLabel(costCentreRef($conn, $value)) ?: (string)$value;
        }
        if ($def['kind'] === 'supplier') {
            $stmt = $conn->prepare("SELECT COALESCE(NULLIF(TRIM(trading_name), ''), legal_name) FROM suppliers WHERE id = ?");
            $stmt->execute([(int)$value]);
            $name = $stmt->fetchColumn();
            return $name !== false ? $name : (string)$value;
        }
        return (string)$value;
    }

    /**
     * Re-sync the asset expiry calendar (best-effort; same hook the UI + API
     * used). One pass writes BOTH warranty and lease entries, which is why
     * there is no lease equivalent of this method.
     */
    private static function syncWarranty(PDO $conn): void
    {
        require_once __DIR__ . '/../asset_warranty_calendar.php';
        try { syncAssetWarrantyCalendar($conn); } catch (Exception $syncEx) { /* non-critical */ }
    }

    // ======================================================================
    //  Who holds what (discussion #56)
    // ======================================================================

    /**
     * Everyone who currently holds at least one asset, with how many.
     *
     * The list is driven by `users_assets`, not by `users`: the question being
     * answered is "who has kit", so somebody with nothing does not belong on the
     * list at all. Search is applied here rather than client-side because an
     * install with thousands of requesters should not ship them all to a browser.
     *
     * ⚠️ INNER JOIN to users on purpose. users_assets has no foreign key, and
     * older installs carry rows pointing at requesters that no longer exist —
     * Ed's own dev database has nine. A LEFT JOIN would list them as blank people
     * holding real equipment, which reads as data loss rather than as stale rows.
     *
     * @param string $search  text to match in the person's name or email; '' for everyone
     * @param int $limit  the most people to return (1-500)
     */
    public static function usersHoldingAssets(PDO $conn, ActorContext $ctx, string $search = '', int $limit = 200): array
    {
        [$tenantSql, $tenantArgs] = activeTenantFilter($conn, $ctx->actorId, 'a');

        $where = '';
        $args  = [];
        $search = trim($search);
        if ($search !== '') {
            $where = " AND (u.display_name LIKE ? OR u.email LIKE ?)";
            $args[] = '%' . $search . '%';
            $args[] = '%' . $search . '%';
        }

        $limit = max(1, min($limit, 500));
        $sql = "SELECT u.id, u.email, u.display_name,
                       COUNT(ua.id)            AS asset_count,
                       MAX(ua.assigned_datetime) AS latest_assignment
                  FROM users_assets ua
                  JOIN users  u ON u.id = ua.user_id
                  JOIN assets a ON a.id = ua.asset_id
                 WHERE 1=1 $tenantSql $where
                 GROUP BY u.id, u.email, u.display_name
                 ORDER BY (u.display_name IS NULL OR u.display_name = ''), u.display_name, u.email
                 LIMIT $limit";

        $stmt = $conn->prepare($sql);
        $stmt->execute(array_merge($tenantArgs, $args));
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as &$r) {
            $r['id']          = (int)$r['id'];
            $r['asset_count'] = (int)$r['asset_count'];
            $r['name']        = self::personName($r);
        }
        return $rows;
    }

    /**
     * Everyone, whether or not they hold anything.
     *
     * The companion to usersHoldingAssets(), and a different question: that one
     * answers "who has equipment", this one answers "who is there". You cannot
     * assign a laptop to somebody the list will not show you, so a people
     * directory that only lists current holders is unusable for the one job it
     * most needs to do — issuing kit to a new starter.
     *
     * $scope:
     *   'current'  — people who are still here. The default: a leaver in a
     *                picker is how equipment gets issued to somebody who left.
     *   'leavers'  — only those marked as having left.
     *   'everyone' — genuinely everyone, both of the above.
     *   'holding'  — anybody holding equipment, INCLUDING leavers. A leaver who
     *                still has a laptop is the single most actionable row on
     *                this screen, so a filter about equipment must not hide them
     *                behind a filter about employment.
     *
     * ⚠️ 'everyone' means everyone. It was originally the default and excluded
     * leavers, which is a contradiction — a filter that says Everyone and hides
     * people teaches you not to trust the others either.
     *
     * Tenancy is applied to the PERSON (users.tenant_id), not to their assets —
     * otherwise somebody who holds nothing would fall outside the filter and
     * silently vanish from the directory.
     *
     * @param string $search  text to match in name, email, username, department, job title or employee id; '' for all
     * @param string $scope  'current' (default), 'leavers', 'everyone' or 'holding' - see above
     * @param int $limit  the most people to return (1-1000)
     * @param string $source  '' for every source, 'local' for people linked to nothing, or an auth_providers id
     */
    public static function people(PDO $conn, ActorContext $ctx, string $search = '', string $scope = 'current', int $limit = 500, string $source = ''): array
    {
        [$tenantSql, $tenantArgs] = activeTenantFilter($conn, $ctx->actorId, 'u');

        // 🔴 The manager's NAME needs its own scope, and it belongs in the JOIN.
        //
        // `manager_id` is not tenant-scoped — nothing stops a person in one
        // company reporting to somebody in another, and until the same release
        // as this comment `save_user.php` would accept exactly that from any
        // analyst. So `LEFT JOIN users m` scoped only by `u` handed an analyst
        // who can see company A the display name of somebody in company B.
        //
        // ⚠️ In the JOIN's ON clause, never the WHERE. A LEFT JOIN with the
        // condition in WHERE stops being a LEFT JOIN: the row is dropped
        // entirely, so a person whose manager is out of scope would vanish from
        // the list rather than simply showing no manager. Same lesson as the
        // Watchtower scoping fix.
        [$mgrTenantSql, $mgrTenantArgs] = activeTenantFilter($conn, $ctx->actorId, 'm');

        $where = '';
        $args  = [];
        $search = trim($search);
        if ($search !== '') {
            $where .= " AND (u.display_name LIKE ? OR u.email LIKE ? OR u.username LIKE ?
                             OR u.department LIKE ? OR u.job_title LIKE ? OR u.employee_id LIKE ?)";
            for ($i = 0; $i < 6; $i++) $args[] = '%' . $search . '%';
        }

        // 'everyone' and 'holding' apply no employment filter at all — see the
        // docblock. Only 'current' and 'leavers' narrow by is_active.
        if ($scope === 'leavers')      $where .= " AND u.is_active = 0";
        elseif ($scope === 'current')  $where .= " AND u.is_active = 1";
        if ($scope === 'holding')      $where .= " AND EXISTS (SELECT 1 FROM users_assets ua WHERE ua.user_id = u.id)";

        // Source: only people added here, or one directory / address book.
        [$srcSql, $srcArgs] = userSourceFilter($source);
        $where .= $srcSql;
        $args = array_merge($args, $srcArgs);

        $limit = max(1, min($limit, 1000));
        $sql = "SELECT u.id, u.email, u.username, u.display_name, u.preferred_name,
                       u.job_title, u.department, u.office, u.phone, u.mobile,
                       u.employee_id, u.manager_id, u.is_active, u.is_managed,
                       u.directory_username, u.last_seen_in_source, u.deactivated_datetime,
                       u.tenant_id, ap.protocol AS managed_protocol, ap.carddav_write_back AS managed_write_back,
                       ap.id AS source_id, ap.display_name AS source_name,
                       m.display_name AS manager_name,
                       (SELECT COUNT(*) FROM users_assets ua2 WHERE ua2.user_id = u.id) AS asset_count
                  FROM users u
             LEFT JOIN users m ON m.id = u.manager_id $mgrTenantSql
             LEFT JOIN auth_providers ap ON ap.id = u.auth_provider_id
                 WHERE 1=1 $tenantSql $where
                 ORDER BY (u.display_name IS NULL OR u.display_name = ''), u.display_name, u.email
                 LIMIT $limit";

        // ⚠️ Order matters: the manager scope sits in the JOIN, which precedes
        // the WHERE, so its placeholders bind FIRST.
        $stmt = $conn->prepare($sql);
        $stmt->execute(array_merge($mgrTenantArgs, $tenantArgs, $args));
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as &$r) {
            $r['id']          = (int)$r['id'];
            $r['asset_count'] = (int)$r['asset_count'];
            $r['is_active']   = (int)$r['is_active'] === 1;
            $r['is_managed']  = (int)$r['is_managed'] === 1;
            // Resolved server-side so no screen retypes it. See the same note in
            // api/tickets/get_users.php: an address book owns five of the seven
            // person fields, LDAP owns all seven, and the list that used to be a
            // JavaScript literal in this module's own screen was wrong for the
            // first the moment CardDAV shipped.
            $r['managed_fields'] = $r['is_managed']
                ? array_values(userDirectoryOwnedFields(
                      $r['managed_protocol'] ?? null,
                      (int)($r['managed_write_back'] ?? 0) === 1
                  ))
                : [];
            $r['manager_id']  = $r['manager_id'] !== null ? (int)$r['manager_id'] : null;
            $r['name']        = self::personName($r);
        }
        return $rows;
    }

    /**
     * One person, and everything currently assigned to them.
     *
     * Returns ['user' => …, 'assets' => […]] or null when the person does not
     * exist. An existing person holding nothing returns an empty asset list
     * rather than null — "Ada has no equipment" is a real and useful answer,
     * particularly during offboarding.
     *
     * @param int $userId  the requester (users.id) to show
     */
    public static function assetsForUser(PDO $conn, ActorContext $ctx, int $userId): ?array
    {
        // 🔴 The person first. Without this, an analyst limited to one company
        // could read anybody's email, phones, job title and employee number by
        // changing user_id - the assets below were scoped, the person was not.
        // Out of reach reads exactly like "no such person".
        if (!analystCanAccessUser($conn, $ctx->actorId, $userId)) {
            return null;
        }
        // The whole person, not just the name. The screen used to take these from
        // the row it already had in the list, which silently produced a detail
        // panel with no details whenever the person was not IN that list — after
        // a search, or when following a link to somebody outside the current
        // filter. Reading them here means the panel is complete however you
        // arrived at it.
        // Same scope on the manager's name as the list above, for the same
        // reason — and in the ON clause, so a manager out of scope means "no
        // manager shown" rather than "this person does not exist".
        [$mgrTenantSql, $mgrTenantArgs] = activeTenantFilter($conn, $ctx->actorId, 'm');
        $u = $conn->prepare(
            "SELECT u.id, u.email, u.username, u.display_name, u.preferred_name,
                    u.job_title, u.department, u.office, u.phone, u.mobile,
                    u.employee_id, u.manager_id, u.is_active, u.is_managed,
                    u.directory_username, u.deactivated_datetime,
                    ap.protocol AS managed_protocol, ap.carddav_write_back AS managed_write_back,
                    ap.id AS source_id, ap.display_name AS source_name,
                    m.display_name AS manager_name
               FROM users u
          LEFT JOIN users m ON m.id = u.manager_id $mgrTenantSql
          LEFT JOIN auth_providers ap ON ap.id = u.auth_provider_id
              WHERE u.id = ?"
        );
        $u->execute(array_merge($mgrTenantArgs, [$userId]));
        $user = $u->fetch(PDO::FETCH_ASSOC);
        if (!$user) {
            return null;
        }
        $user['id']         = (int)$user['id'];
        $user['name']       = self::personName($user);
        $user['is_active']  = (int)$user['is_active'] === 1;
        $user['is_managed'] = (int)$user['is_managed'] === 1;
        $user['managed_fields'] = $user['is_managed']
            ? array_values(userDirectoryOwnedFields(
                  $user['managed_protocol'] ?? null,
                  (int)($user['managed_write_back'] ?? 0) === 1
              ))
            : [];
        $user['manager_id'] = $user['manager_id'] !== null ? (int)$user['manager_id'] : null;

        // Who reports to this person. The relationship is stored once, pointing
        // upwards, so the only way to answer "who does she manage" is to look for
        // everybody pointing at her. Leavers are included and flagged rather than
        // hidden: a manager whose reports have all left is worth seeing, and so is
        // a leaver who still has people pointed at them.
        // Scoped like the list: a report in a company the reader cannot see is
        // not shown (a manager can have reports in more than one company).
        [$repTenantSql, $repTenantArgs] = activeTenantFilter($conn, $ctx->actorId, 'r');
        $r = $conn->prepare(
            "SELECT r.id, r.display_name, r.email, r.is_active
               FROM users r
              WHERE r.manager_id = ? $repTenantSql
              ORDER BY (r.display_name IS NULL OR r.display_name = ''), r.display_name, r.email"
        );
        $r->execute(array_merge([$userId], $repTenantArgs));
        $user['reports'] = array_map(static function (array $row): array {
            return [
                'id'        => (int)$row['id'],
                'name'      => self::personName($row),
                'is_active' => (int)$row['is_active'] === 1,
            ];
        }, $r->fetchAll(PDO::FETCH_ASSOC));

        [$tenantSql, $tenantArgs] = activeTenantFilter($conn, $ctx->actorId, 'a');

        $sql = "SELECT a.id, a.hostname, a.manufacturer, a.model, a.service_tag, a.asset_tag,
                       a.operating_system, a.purchase_date, a.warranty_expiry,
                       at.name  AS asset_type,
                       ast.name AS asset_status,
                       loc.name AS location,
                       ua.assigned_datetime, ua.expected_return_date, ua.notes,
                       an.full_name AS assigned_by
                  FROM users_assets ua
                  JOIN assets a          ON a.id  = ua.asset_id
                  LEFT JOIN asset_types  at  ON at.id  = a.asset_type_id
                  LEFT JOIN asset_status_types ast ON ast.id = a.asset_status_id
                  LEFT JOIN asset_locations loc ON loc.id = a.location_id
                  LEFT JOIN analysts     an  ON an.id  = ua.assigned_by_analyst_id
                 WHERE ua.user_id = ? $tenantSql
                 ORDER BY at.name, a.hostname";

        $stmt = $conn->prepare($sql);
        $stmt->execute(array_merge([$userId], $tenantArgs));
        $assets = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($assets as &$a) {
            $a['id'] = (int)$a['id'];
        }
        unset($a);

        // Custom field values, so a handover document can show a monitor's size
        // or a headset's part number alongside the built-in columns.
        //
        // Attached HERE rather than in each of the three handover callers
        // (print, email, preview) — one batched query, and they cannot drift
        // apart. Keyed `custom` so nothing existing changes shape.
        //
        // 🔑 A field the asset has not got stays ABSENT, so the document can
        // print a dash for "not recorded" rather than an empty cell that might
        // mean "no".
        if ($assets) {
            require_once __DIR__ . '/asset_fields.php';
            if (AssetFieldsService::schemaReady($conn)) {
                $defs = AssetFieldsService::fieldsForSets(
                    $conn,
                    $conn->query("SELECT id FROM asset_field_sets WHERE is_deleted = 0")->fetchAll(PDO::FETCH_COLUMN)
                );
                if ($defs) {
                    $vals = AssetFieldsService::readForAssets(
                        $conn, array_map(static fn($x) => (int)$x['id'], $assets), $defs
                    );
                    foreach ($assets as &$a) {
                        $a['custom'] = $vals[(int)$a['id']] ?? [];
                    }
                    unset($a);
                }
            }
        }

        return ['user' => $user, 'assets' => $assets];
    }

    /**
     * Best available human name for a requester row, falling back to the email.
     *
     * @param array $row  a users row with display_name, preferred_name, email and id
     */
    private static function personName(array $row): string
    {
        foreach (['display_name', 'preferred_name', 'email'] as $k) {
            if (!empty($row[$k])) {
                return (string)$row[$k];
            }
        }
        return '#' . ($row['id'] ?? '?');
    }

    // =====================================================================
    //  Reconciliation - "is this incoming device one we already have?"
    //  (PR #164, Sandy's design; see the Asset Reconciliation developer guide)
    // =====================================================================

    /**
     * Serial numbers that identify nothing: what a motherboard reports when the
     * maker never filled the field in. Matching on one would merge every such
     * machine into a single asset. Editable under Assets -> Settings ->
     * Reconciliation; this list is only the default.
     */
    public const DEFAULT_IGNORED_SERVICE_TAGS = [
        'TO BE FILLED BY O.E.M.',
        'DEFAULT STRING',
        'NONE',
        'SYSTEM SERIAL NUMBER',
        'NOT SPECIFIED',
        '123456789',
    ];

    /**
     * The placeholder list as a lookup map [lowercased => true]. The stored
     * setting, one per line, replaces the defaults when it has anything in it.
     */
    public static function getIgnoredServiceTags(PDO $conn): array
    {
        $map = [];
        foreach (self::DEFAULT_IGNORED_SERVICE_TAGS as $tag) {
            $map[mb_strtolower(trim($tag))] = true;
        }
        try {
            $st = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'asset_reconciliation_ignored_serials' LIMIT 1");
            $st->execute();
            $val = $st->fetchColumn();
            if (is_string($val) && trim($val) !== '') {
                $map = [];
                foreach (preg_split('/\r\n|\r|\n/', $val) as $line) {
                    $line = mb_strtolower(trim($line));
                    if ($line !== '') $map[$line] = true;
                }
            }
        } catch (Throwable $e) { /* setting missing: the defaults stand */ }
        return $map;
    }

    /**
     * Is this serial worth matching on - not blank, not a placeholder?
     *
     * @param ?string $tag  the serial number reported
     * @param ?array $ignoredMap  placeholder serials as [lowercased => true], from getIgnoredServiceTags(); null uses the defaults
     */
    public static function isUsableServiceTag(?string $tag, ?array $ignoredMap = null): bool
    {
        $t = trim((string)$tag);
        if ($t === '') return false;
        $ignoredMap = $ignoredMap ?? array_fill_keys(array_map(fn($x) => mb_strtolower($x), self::DEFAULT_IGNORED_SERVICE_TAGS), true);
        return !isset($ignoredMap[mb_strtolower($t)]);
    }

    /**
     * Which existing asset, if any, an incoming device is. Reads only.
     *
     *   Tier 1  an authoritative connector link the caller already holds
     *   Tier 2  a usable serial - unless two assets share it (ambiguity guard)
     *   Tier 3  hostname - unless a usable serial CONTRADICTS it (below)
     *   Tier 4  nothing: a new asset
     *
     * 🔴 THE LAPTOP REFRESH. A replacement laptop is very often given the old
     * one's name. It reports a good serial nobody has seen, so tier 2 finds
     * nothing - and a plain hostname match would then put the NEW serial on the
     * OLD laptop's record, leaving one record describing two machines. So a
     * hostname match whose asset already holds a different usable serial is a
     * different machine: tier 4, flagged `hostname_reused`. An asset entered
     * without a serial (by hand, before the agent ran) still matches by name -
     * that is how the agent finds it the first time.
     *
     * @param array $ids        asset_id (tier 1, with $isExplicitLink), service_tag, hostname
     * @param ?int  $tenantId   the company to look in; NULL = the Default company
     * @param bool $isExplicitLink  true when $ids['asset_id'] is a stored connector link to trust (tier 1)
     * @param ?array $ignoredTags  placeholder serials from getIgnoredServiceTags(); null reads the setting
     * @param bool  $anyCompany look in EVERY company (tiers 2-3). Only for a
     *        connection that has no company of its own - see intuneLinkDevicesToAssets().
     * @return array{asset_id:?int, matched_by:string, ambiguous:bool, hostname_reused:?int}
     */
    public static function resolveAssetIdentity(PDO $conn, array $ids, ?int $tenantId = null, bool $isExplicitLink = false, ?array $ignoredTags = null, bool $anyCompany = false): array
    {
        $result = fn(?int $id, string $by, bool $amb = false, ?int $reused = null) =>
            ['asset_id' => $id, 'matched_by' => $by, 'ambiguous' => $amb, 'hostname_reused' => $reused];
        $linkId   = !empty($ids['asset_id']) ? (int)$ids['asset_id'] : 0;
        $serial   = trim((string)($ids['service_tag'] ?? ''));
        $hostname = trim((string)($ids['hostname'] ?? ''));
        $scope    = $anyCompany ? '' : ' AND tenant_id <=> ?';
        $scopeArg = $anyCompany ? [] : [$tenantId];

        // Tier 1. An explicit link names the asset; it must still be in the
        // company being asked about, or it is not this caller's to claim.
        if ($isExplicitLink && $linkId > 0) {
            $st = $conn->prepare("SELECT id FROM assets WHERE id = ?$scope LIMIT 1");
            $st->execute(array_merge([$linkId], $scopeArg));
            if ($id = $st->fetchColumn()) return $result((int)$id, 'explicit_link');
        }

        $ignoredTags = $ignoredTags ?? self::getIgnoredServiceTags($conn);
        $serialUsable = self::isUsableServiceTag($serial, $ignoredTags);

        // Tier 2. One asset with this serial is a match; several is a question
        // for a person, never a guess.
        if ($serialUsable) {
            $st = $conn->prepare("SELECT id FROM assets WHERE service_tag = ?$scope");
            $st->execute(array_merge([$serial], $scopeArg));
            $hits = $st->fetchAll(PDO::FETCH_COLUMN);
            if (count($hits) === 1) return $result((int)$hits[0], 'service_tag');
            if (count($hits) > 1) {
                if ($hostname !== '') {
                    $st = $conn->prepare("SELECT id FROM assets WHERE service_tag = ? AND hostname = ?$scope LIMIT 1");
                    $st->execute(array_merge([$serial, $hostname], $scopeArg));
                    if ($id = $st->fetchColumn()) return $result((int)$id, 'hostname', true);
                }
                return $result(null, 'none', true);
            }
        }

        // Tier 3, with the laptop-refresh rule above.
        if ($hostname !== '') {
            $st = $conn->prepare("SELECT id, service_tag FROM assets WHERE hostname = ?$scope LIMIT 1");
            $st->execute(array_merge([$hostname], $scopeArg));
            if ($hit = $st->fetch(PDO::FETCH_ASSOC)) {
                $theirs = (string)($hit['service_tag'] ?? '');
                $contradicts = $serialUsable && self::isUsableServiceTag($theirs, $ignoredTags)
                            && strcasecmp($theirs, $serial) !== 0;
                if (!$contradicts) return $result((int)$hit['id'], 'hostname');
                return $result(null, 'none', false, (int)$hit['id']);
            }
        }

        return $result(null, 'none');
    }

    /**
     * Apply a rename an inventory source reported for an asset it has matched
     * by something stronger than its name. Skipped, and reported as a conflict,
     * if another asset in the SAME company (the asset's own, wherever a person
     * may have moved it) already has the name - hostname is unique per company.
     *
     * @param string $newHostname  the name the inventory source reported (cut to 50 characters)
     * @param string $source  what reported it, e.g. 'Intune', added to the history row
     * @return array{updated:bool, conflict:bool}
     */
    public static function updateAssetHostname(PDO $conn, ActorContext $ctx, int $assetId, string $newHostname, string $source = 'system'): array
    {
        $newHostname = mb_substr(trim($newHostname), 0, 50);   // assets.hostname is VARCHAR(50)
        if ($newHostname === '') return ['updated' => false, 'conflict' => false];

        $st = $conn->prepare("SELECT hostname, tenant_id FROM assets WHERE id = ?");
        $st->execute([$assetId]);
        $cur = $st->fetch(PDO::FETCH_ASSOC);
        if (!$cur) return ['updated' => false, 'conflict' => false];
        $old = (string)($cur['hostname'] ?? '');
        if (strcasecmp($old, $newHostname) === 0) return ['updated' => false, 'conflict' => false];

        $clash = $conn->prepare("SELECT id FROM assets WHERE hostname = ? AND tenant_id <=> ? AND id <> ? LIMIT 1");
        $clash->execute([$newHostname, $cur['tenant_id'], $assetId]);
        if ($clash->fetchColumn()) return ['updated' => false, 'conflict' => true];

        $conn->prepare("UPDATE assets SET hostname = ? WHERE id = ?")->execute([$newHostname, $assetId]);
        // Best-effort: before Database Verification has relaxed analyst_id, an
        // automated history row cannot be written, and a rename must not fail
        // the agent's whole report over its own audit trail.
        try {
            self::auditWrite($conn, $assetId, $ctx->actorId, 'hostname', $old, $newHostname . ' (' . $source . ')');
        } catch (Throwable $e) {
            error_log('asset rename history: ' . $e->getMessage());
        }
        return ['updated' => true, 'conflict' => false];
    }

    /**
     * Resolve, then - for a match made by link or serial - apply a reported
     * rename. The single entry point every inventory source uses.
     *
     * @param array $ids  what the source reported: asset_id (with $isExplicitLink), service_tag, hostname
     * @param string $source  what reported it, e.g. 'software inventory', added to a rename's history row
     * @param bool $isExplicitLink  true when $ids['asset_id'] is a stored connector link to trust
     * @param ?array $ignoredTags  placeholder serials from getIgnoredServiceTags(); null reads the setting
     * @param bool $anyCompany  true to match across every company - only for a connection with no company of its own
     * @return array{asset_id:?int, matched_by:string, ambiguous:bool, hostname_reused:?int, hostname_updated:bool, hostname_conflict:bool}
     */
    public static function reconcileAsset(PDO $conn, ActorContext $ctx, array $ids, ?int $tenantId = null, string $source = 'system', bool $isExplicitLink = false, ?array $ignoredTags = null, bool $anyCompany = false): array
    {
        $r = self::resolveAssetIdentity($conn, $ids, $tenantId, $isExplicitLink, $ignoredTags, $anyCompany);
        $r['hostname_updated'] = false;
        $r['hostname_conflict'] = false;
        $newName = trim((string)($ids['hostname'] ?? ''));
        if ($r['asset_id'] !== null && in_array($r['matched_by'], ['explicit_link', 'service_tag'], true) && $newName !== '') {
            $m = self::updateAssetHostname($conn, $ctx, $r['asset_id'], $newName, $source);
            $r['hostname_updated'] = $m['updated'];
            $r['hostname_conflict'] = $m['conflict'];
        }
        return $r;
    }

    /**
     * Create an asset an inventory source has found - the inventory agents and
     * Intune. The one place such an asset is written, so the tag, the
     * transaction and the history row are the same whichever source found it
     * (the three used to INSERT for themselves).
     *
     * @param array $fields column => value, from the discovered-asset columns below
     * @param ?int  $tenantId the company it belongs to (NULL = Default)
     * @param string $source  what found it, e.g. 'the software inventory agent' - the history row's value
     * @param ?int  $hostnameReusedBy the asset a refreshed machine's name still belongs to, if any
     */
    public static function createDiscoveredAsset(PDO $conn, ActorContext $ctx, array $fields, ?int $tenantId, string $source, ?int $hostnameReusedBy = null): int
    {
        static $allowed = ['hostname', 'manufacturer', 'model', 'memory', 'service_tag', 'operating_system',
            'feature_release', 'build_number', 'cpu_name', 'speed', 'bios_version', 'domain', 'logged_in_user',
            'last_boot_utc', 'tpm_version', 'bitlocker_status', 'gpu_name', 'last_seen'];
        require_once __DIR__ . '/asset_tags.php';
        $store = AssetTagsService::storeTenant($conn, $tenantId);
        $cols = ['tenant_id'];
        $vals = [$store];
        foreach ($fields as $col => $val) {
            if (in_array($col, $allowed, true)) { $cols[] = $col; $vals[] = $val; }
        }
        // An inventory source has, by definition, just seen the machine.
        if (!in_array('last_seen', $cols, true)) { $cols[] = 'last_seen'; $vals[] = gmdate('Y-m-d H:i:s'); }

        $created = AssetTagsService::createWithTag($conn, $tenantId, null, function (?string $tag) use ($conn, $ctx, $cols, $vals, $source, $hostnameReusedBy): int {
            if ($tag !== null) { $cols[] = 'asset_tag'; $vals[] = $tag; }
            $ph = implode(', ', array_fill(0, count($cols), '?'));
            $conn->prepare("INSERT INTO assets (" . implode(', ', $cols) . ", first_seen) VALUES ($ph, UTC_TIMESTAMP())")->execute($vals);
            $id = (int)$conn->lastInsertId();
            try {
                // 🔴 NOT 'asset_created'. Database Verification's last_seen repair
                // (#1583) treats an asset_created row as proof that a PERSON or an
                // import made the record, and blanks last_seen where it equals
                // first_seen - which it does on every machine found here. A
                // discovered asset gets its own entry, so that evidence stays true.
                self::auditWrite($conn, $id, $ctx->actorId, 'asset_discovered', null, $source);
                if ($hostnameReusedBy !== null) {
                    // Say so where somebody will look: the old record still has
                    // this name, and is probably the machine this one replaced.
                    self::auditWrite($conn, $id, $ctx->actorId, 'hostname_reused', null, '#' . $hostnameReusedBy);
                }
            } catch (Throwable $e) {
                error_log('discovered asset history: ' . $e->getMessage());   // before Database Verification - see updateAssetHostname()
            }
            return $id;
        });
        return $created['id'];
    }
}
