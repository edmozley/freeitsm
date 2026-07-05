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
require_once dirname(__DIR__, 2) . '/workflow/includes/engine.php';

class AssetsService
{
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
            'purchase_date'    => ['audit' => 'purchase_date',   'kind' => 'date'],
            'purchase_cost'    => ['audit' => 'purchase_cost',   'kind' => 'decimal'],
            'order_number'     => ['audit' => 'order_number',    'kind' => 'string', 'max' => 100],
            'warranty_expiry'  => ['audit' => 'warranty_expiry', 'kind' => 'date'],
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
     * records the acting key; the UI has no create path).
     */
    public static function createAsset(PDO $conn, ActorContext $ctx, array $in, string $creationNote): int
    {
        $hostname = trim((string)($in['hostname'] ?? ''));
        if ($hostname === '') {
            throw new ServiceError('validation', 'missing_field', "'hostname' is required.");
        }
        if (mb_strlen($hostname) > 50) {
            throw new ServiceError('validation', 'invalid_field', "'hostname' must be at most 50 characters.");
        }

        // hostname is the identity every ingest path upserts on — a duplicate
        // would split an asset's records, so refuse rather than silently fork.
        $dup = $conn->prepare("SELECT id FROM assets WHERE hostname = ?");
        $dup->execute([$hostname]);
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
            $columns[] = $field;
            $values[]  = self::validateField($conn, $field, $in[$field], $def);
        }

        $placeholders = implode(', ', array_fill(0, count($columns), '?'));
        $sql = "INSERT INTO assets (" . implode(', ', $columns) . ", first_seen, last_seen)
                VALUES ($placeholders, UTC_TIMESTAMP(), UTC_TIMESTAMP())";
        $conn->prepare($sql)->execute($values);
        $assetId = (int)$conn->lastInsertId();

        self::auditWrite($conn, $assetId, $ctx->actorId, 'asset_created', null, $creationNote);

        if (array_key_exists('warranty_expiry', $in) && $in['warranty_expiry']) {
            self::syncWarranty($conn);
        }
        return $assetId;
    }

    /**
     * Apply a partial set of field updates to an asset. Writes one audit row per
     * changed field (no-ops are skipped) and re-syncs the warranty calendar when
     * warranty_expiry moves. Returns void; the adapter reloads for its response.
     */
    public static function updateFields(PDO $conn, ActorContext $ctx, int $assetId, array $in): void
    {
        $current = self::loadRow($conn, $assetId);   // 404 if gone
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
            $newValue = self::validateField($conn, $field, $rawValue, $def);

            if ($field === 'hostname') {
                if ($newValue === null) {
                    throw new ServiceError('validation', 'invalid_field', "'hostname' cannot be blank.");
                }
                $dup = $conn->prepare("SELECT id FROM assets WHERE hostname = ? AND id != ?");
                $dup->execute([$newValue, $assetId]);
                if ($dup->fetchColumn()) {
                    throw new ServiceError('conflict', 'conflict', 'Another asset already uses this hostname.');
                }
            }

            // Normalise the current value the same way for change detection.
            $oldValue = $current[$field];
            if (in_array($def['kind'], ['lookup', 'supplier', 'int'], true) && $oldValue !== null) {
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
            if ($field === 'warranty_expiry') {
                $warrantyChanged = true;
            }
        }

        if (!$updates) {
            return; // idempotent — nothing to write
        }

        $args[] = $assetId;
        $conn->prepare('UPDATE assets SET ' . implode(', ', $updates) . ' WHERE id = ?')->execute($args);

        foreach ($audits as [$fieldKey, $old, $new]) {
            self::auditWrite($conn, $assetId, $ctx->actorId, $fieldKey, $old, $new);
        }

        if ($warrantyChanged) {
            self::syncWarranty($conn);
        }
    }

    /**
     * Assign a requester to an asset. $in: user_id | user_email, plus optional
     * notes, expected_return_date, previous_user_id (UI re-assign old_value).
     * Returns [asset_id, user_id, name, expected_return_date, notes].
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
     */
    public static function unassignUser(PDO $conn, ActorContext $ctx, int $assetId, int $userId, bool $skipAudit = false): array
    {
        self::loadRow($conn, $assetId);   // 404 if gone
        $actorId = $ctx->actorId;

        // Snapshot holder + due-back before removal, for the custody trail + audit.
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

    /** Fire an asset.* workflow event (best-effort; the engine swallows its own errors). */
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

    private static function auditWrite(PDO $conn, int $assetId, int $analystId, string $fieldKey, ?string $old, ?string $new): void
    {
        $conn->prepare(
            "INSERT INTO asset_history (asset_id, analyst_id, field_name, old_value, new_value, created_datetime)
             VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP())"
        )->execute([$assetId, $analystId, $fieldKey, $old, $new]);
    }

    /** Validate a DATE field (YYYY-MM-DD); 422 naming the field. Null/'' clears. */
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

    /** Validate one incoming field value per its map entry. Returns the DB-ready value. */
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

    /** Resolve a lookup id to its display name for the audit trail. */
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
        if ($def['kind'] === 'supplier') {
            $stmt = $conn->prepare("SELECT COALESCE(NULLIF(TRIM(trading_name), ''), legal_name) FROM suppliers WHERE id = ?");
            $stmt->execute([(int)$value]);
            $name = $stmt->fetchColumn();
            return $name !== false ? $name : (string)$value;
        }
        return (string)$value;
    }

    /** Re-sync the warranty calendar (best-effort; same hook the UI + API used). */
    private static function syncWarranty(PDO $conn): void
    {
        require_once __DIR__ . '/../asset_warranty_calendar.php';
        try { syncAssetWarrantyCalendar($conn); } catch (Exception $syncEx) { /* non-critical */ }
    }
}
