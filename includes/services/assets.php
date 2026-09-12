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
require_once dirname(__DIR__, 2) . '/workflow/includes/engine.php';

class AssetsService
{
    /**
     * Multi-tenancy gate: refuse to touch an asset outside the actor's companies.
     * companyScope null = all companies (single-company install or an all-access
     * actor) → no gate. Framed as not-found so it never reveals another company's
     * asset. $row must include tenant_id.
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
     * records the acting key, the UI records the analyst — see
     * api/assets/create_asset.php, added in #1132 because a television cannot
     * run the inventory agent).
     */
    public static function createAsset(PDO $conn, ActorContext $ctx, array $in, string $creationNote, ?int $tenantId = null): int
    {
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

        // hostname is the identity every ingest path upserts on — a duplicate
        // would split an asset's records, so refuse rather than silently fork.
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
            $columns[] = $field;
            $values[]  = self::validateField($conn, $field, $in[$field], $def);
        }
        $columns[] = 'tenant_id';
        $values[]  = $storeTenant;

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
        $sql = "INSERT INTO assets (" . implode(', ', $columns) . ", first_seen)
                VALUES ($placeholders, UTC_TIMESTAMP())";
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
        self::assertScope($conn, $ctx, $current);    // 404 if in another company
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
     */
    public static function people(PDO $conn, ActorContext $ctx, string $search = '', string $scope = 'current', int $limit = 500): array
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

        $limit = max(1, min($limit, 1000));
        $sql = "SELECT u.id, u.email, u.username, u.display_name, u.preferred_name,
                       u.job_title, u.department, u.office, u.phone, u.mobile,
                       u.employee_id, u.manager_id, u.is_active, u.is_managed,
                       u.directory_username, u.last_seen_in_source, u.deactivated_datetime,
                       u.tenant_id,
                       m.display_name AS manager_name,
                       (SELECT COUNT(*) FROM users_assets ua2 WHERE ua2.user_id = u.id) AS asset_count
                  FROM users u
             LEFT JOIN users m ON m.id = u.manager_id $mgrTenantSql
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
     */
    public static function assetsForUser(PDO $conn, ActorContext $ctx, int $userId): ?array
    {
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
                    m.display_name AS manager_name
               FROM users u
          LEFT JOIN users m ON m.id = u.manager_id $mgrTenantSql
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
        $user['manager_id'] = $user['manager_id'] !== null ? (int)$user['manager_id'] : null;

        // Who reports to this person. The relationship is stored once, pointing
        // upwards, so the only way to answer "who does she manage" is to look for
        // everybody pointing at her. Leavers are included and flagged rather than
        // hidden: a manager whose reports have all left is worth seeing, and so is
        // a leaver who still has people pointed at them.
        $r = $conn->prepare(
            "SELECT id, display_name, email, is_active
               FROM users
              WHERE manager_id = ?
              ORDER BY (display_name IS NULL OR display_name = ''), display_name, email"
        );
        $r->execute([$userId]);
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

    /** Best available human name for a requester row, falling back to the email. */
    private static function personName(array $row): string
    {
        foreach (['display_name', 'preferred_name', 'email'] as $k) {
            if (!empty($row[$k])) {
                return (string)$row[$k];
            }
        }
        return '#' . ($row['id'] ?? '?');
    }
}
