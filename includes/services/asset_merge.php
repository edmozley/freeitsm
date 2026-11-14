<?php
/**
 * Merging two assets into one (GH #200 part D) - only ever by a person, from the
 * review list, and keeping EVERYTHING both records had.
 *
 * The person chooses the record to keep. Every row anywhere that points at the other
 * one moves to it, then the other is removed:
 *   - history, custody, tickets, contracts, documents, projects, leases, custom field
 *     values (and other assets' links to it), connections, review cases, inventory
 *   - where both hold the same thing (the same ticket link, the same custom field), the
 *     kept record's row wins and the duplicate goes
 *   - the kept record's own fields win; an empty one is filled from the other
 *   - each inventory source's link moves, so the agent and REST tools still recognise
 *     the merged asset; a source that reported both keeps its link to the kept one
 *   - asset_merges remembers the removed asset, so its printed QR label and a REST tool
 *     still posting to its id find the kept one (intoForQrToken(), current())
 *   - both histories end up on the kept asset, with one row saying what was merged
 *   - the configuration item link (GH #201) moves; if BOTH are linked, the person chose
 *     which link ends (AssetCiLinkService::mergeLinks) and neither CI is touched
 *
 * TRAP: every column that refers to an asset must be in REFERENCES (or POLYMORPHIC).
 *   One left out keeps pointing at a deleted asset - or, behind an ON DELETE CASCADE,
 *   is silently deleted with it. tests/asset-merge.php fails if a schema column that
 *   looks like an asset reference is missing here.
 *
 * @wiki Asset-Review-Developer-Guide
 */
require_once __DIR__ . '/../service_context.php';   // ServiceError, thrown below (GH #288)
require_once __DIR__ . '/assets.php';
require_once __DIR__ . '/asset_review.php';
require_once __DIR__ . '/asset_ci_links.php';

final class AssetMergeService
{
    /**
     * [table, column, how, key columns]
     *   move        repoint every row
     *   unique      repoint unless the kept asset already has a row with the same key
     *               columns; those duplicates are removed
     *   agent_only  agent-only tables with no identity: if the kept asset has rows, the
     *               other's are removed (the agent's next report rewrites them), else moved
     *   sources / connections / review / ci_links   handled by their own methods below
     */
    public const REFERENCES = [
        ['asset_history',              'asset_id',           'move'],
        ['asset_checkout_log',         'asset_id',           'move'],
        ['users_assets',               'asset_id',           'unique', ['user_id', 'analyst_id']],
        ['ticket_assets',              'asset_id',           'unique', ['ticket_id']],
        ['contract_assets',            'asset_id',           'unique', ['contract_id']],
        ['project_assets',             'asset_id',           'unique', ['project_id']],
        ['lease_assets',               'asset_id',           'unique', ['schedule_id']],
        ['asset_field_set_assets',     'asset_id',           'unique', ['set_id']],
        ['asset_field_values',         'asset_id',           'unique', ['field_id', 'seq']],
        ['asset_disk_hide_rules',      'asset_id',           'move'],
        ['asset_import_run_entries',   'asset_id',           'move'],
        ['intune_devices',             'asset_id',           'move'],
        ['intune_device_user_history', 'asset_id',           'move'],   // GH #335: the device's primary-user history follows it
        ['intune_app_sync_job_assets', 'asset_id',           'move'],
        ['software_inventory_log',     'host_id',            'move'],
        ['software_inventory_detail',  'host_id',            'unique', ['app_id', 'source']],
        ['asset_disks',                'asset_id',           'unique', ['source', 'external_id']],
        ['asset_network_adapters',     'asset_id',           'unique', ['source', 'external_id']],
        ['asset_physical_disks',       'asset_id',           'agent_only'],
        ['asset_devices',              'asset_id',           'agent_only'],
        ['asset_inventory_sources',    'asset_id',           'sources'],
        ['asset_connections',          'asset_id',           'connections'],
        ['asset_connections',          'connected_asset_id', 'connections'],
        ['asset_review_items',         'asset_id',           'review'],
        ['asset_review_items',         'other_asset_id',     'review'],
        ['asset_merges',               'into_asset_id',      'move'],
        ['asset_protected_fields',     'asset_id',           'unique', ['field_name']],
        ['asset_ci_links',             'asset_id',           'ci_links'],
    ];

    /** References that are not an asset_id column: handled in polymorphic(). */
    public const POLYMORPHIC = [
        'document_links.parent_id (parent_type = asset)',
        'asset_field_values.value_ref_id (fields whose ref_kind is asset)',
        'analyst_recent_trail.entity_id (entity_type = asset)',
    ];

    /** Columns whose value means the asset itself, not a link to it - never moved. */
    public const NOT_REFERENCES = ['asset_merges.merged_asset_id',
        // GH #201: the asset a CI link was MADE on - kept through merges, by design.
        'asset_ci_links.original_asset_id',
        // GH #199: a HYPER-V host in the virtual estate, not an asset (software_inventory's host_id is one).
        'virt_vms.host_id', 'virt_placements.host_id'];

    /** Built-in fields an empty kept value is filled from. Identity (hostname, tenant, qr_token) never is. */
    private const FILL = ['manufacturer', 'model', 'memory', 'service_tag', 'operating_system', 'feature_release', 'build_number',
        'cpu_name', 'speed', 'bios_version', 'asset_type_id', 'asset_status_id', 'location_id', 'domain', 'logged_in_user',
        'last_boot_utc', 'tpm_version', 'bitlocker_status', 'gpu_name', 'purchase_date', 'purchase_cost', 'supplier_id',
        'cost_centre_id', 'order_number', 'warranty_expiry', 'lease_start', 'lease_expiry', 'asset_tag'];

    /**
     * Merge $goneId into $keepId. Both must be in one company the caller can reach.
     *
     * @param ?int $reviewItemId the review case this settles, closed as 'merged'
     * @param ?array $ciLinks  GH #201: what the merge window showed and chose -
     *        ['seen' => live link ids shown, 'keep_link_id' => the link that survives when both are linked].
     *        Null from a caller that showed nothing: then two linked assets are refused (ci_link_choice_required).
     * @return array{kept:int, merged:int, moved:array<string,int>, dropped:array<string,int>, filled:string[]}
     */
    public static function merge(PDO $conn, ActorContext $ctx, int $keepId, int $goneId, ?int $reviewItemId = null, ?array $ciLinks = null): array
    {
        if ($keepId === $goneId) { throw new ServiceError('validation', 'invalid_field', 'Choose two different assets.'); }
        if ($conn->inTransaction()) { throw new LogicException('A merge must own its transaction.'); }
        $conn->beginTransaction();
        try {
            $keep = self::lockAsset($conn, $ctx, $keepId);
            $gone = self::lockAsset($conn, $ctx, $goneId);
            $tenant = static fn(array $a) => $a['tenant_id'] === null ? getDefaultTenantId($conn) : (int)$a['tenant_id'];
            if ($tenant($keep) !== $tenant($gone)) {
                throw new ServiceError('validation', 'invalid_field', 'Only two assets in the same company can be merged. Move one first.');
            }
            self::refuseTwoLeases($conn, $keepId, $goneId);

            $moved = []; $dropped = [];
            foreach (self::REFERENCES as $ref) {
                [$table, $col, $how] = $ref;
                if (!self::tableExists($conn, $table)) { continue; }
                $label = "$table.$col";
                switch ($how) {
                    case 'move':
                        $st = $conn->prepare("UPDATE `$table` SET `$col` = ? WHERE `$col` = ?");
                        $st->execute([$keepId, $goneId]);
                        $moved[$label] = $st->rowCount();
                        break;
                    case 'unique':
                        [$m, $d] = self::moveUnique($conn, $table, $col, $ref[3], $keepId, $goneId);
                        $moved[$label] = $m; $dropped[$label] = $d;
                        break;
                    case 'agent_only':
                        $has = $conn->prepare("SELECT COUNT(*) FROM `$table` WHERE `$col` = ?");
                        $has->execute([$keepId]);
                        if ((int)$has->fetchColumn() > 0) {
                            $st = $conn->prepare("DELETE FROM `$table` WHERE `$col` = ?");
                            $st->execute([$goneId]);
                            $dropped[$label] = $st->rowCount();
                        } else {
                            $st = $conn->prepare("UPDATE `$table` SET `$col` = ? WHERE `$col` = ?");
                            $st->execute([$keepId, $goneId]);
                            $moved[$label] = $st->rowCount();
                        }
                        break;
                    case 'sources':
                        [$m, $d] = self::moveSources($conn, $keepId, $goneId);
                        $moved[$label] = $m; $dropped[$label] = $d;
                        break;
                    case 'connections':
                        if ($col === 'asset_id') {   // both columns in one pass
                            [$m, $d] = self::moveConnections($conn, $keepId, $goneId);
                            $moved['asset_connections'] = $m; $dropped['asset_connections'] = $d;
                        }
                        break;
                    case 'review':
                        if ($col === 'asset_id') {
                            $moved['asset_review_items'] = self::moveReview($conn, $ctx, $keepId, $goneId, $reviewItemId);
                        }
                        break;
                    case 'ci_links':
                        [$m, $d] = AssetCiLinkService::mergeLinks($conn, $ctx, $keepId, $goneId,
                            isset($ciLinks['keep_link_id']) ? (int)$ciLinks['keep_link_id'] : null,
                            isset($ciLinks['seen']) && is_array($ciLinks['seen']) ? $ciLinks['seen'] : null);
                        $moved[$label] = $m; $dropped[$label] = $d;
                        break;
                }
            }
            foreach (self::polymorphic($conn, $keepId, $goneId) as $label => [$m, $d]) {
                $moved[$label] = $m; $dropped[$label] = $d;
            }

            // The kept record's fields win; empty ones are filled from the other, and the
            // two seen dates widen to cover both.
            $filled = [];
            $sets = []; $args = [];
            foreach (self::FILL as $f) {
                if (!array_key_exists($f, $keep)) { continue; }
                $empty = $keep[$f] === null || (is_string($keep[$f]) && trim($keep[$f]) === '');
                $has = $gone[$f] !== null && !(is_string($gone[$f]) && trim($gone[$f]) === '');
                if ($empty && $has) { $sets[] = "`$f` = ?"; $args[] = $gone[$f]; $filled[] = $f; }
            }
            if (!empty($gone['first_seen']) && (empty($keep['first_seen']) || $gone['first_seen'] < $keep['first_seen'])) { $sets[] = 'first_seen = ?'; $args[] = $gone['first_seen']; }
            if (!empty($gone['last_seen']) && (empty($keep['last_seen']) || $gone['last_seen'] > $keep['last_seen'])) { $sets[] = 'last_seen = ?'; $args[] = $gone['last_seen']; }

            $summary = self::summary($gone, $moved, $dropped, $filled);
            $conn->prepare('INSERT INTO asset_merges (merged_asset_id, into_asset_id, tenant_id, merged_hostname, merged_service_tag, merged_asset_tag,
                    merged_qr_token, merged_by_id, merged_at, summary) VALUES (?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), ?)')
                ->execute([$goneId, $keepId, $gone['tenant_id'], $gone['hostname'], $gone['service_tag'], $gone['asset_tag'] ?? null,
                    $gone['qr_token'] ?? null, $ctx->actorId > 0 ? $ctx->actorId : null,
                    json_encode(['moved' => $moved, 'dropped' => $dropped, 'filled' => $filled], JSON_UNESCAPED_UNICODE)]);
            $conn->prepare("INSERT INTO asset_history (asset_id, analyst_id, field_name, old_value, new_value, created_datetime)
                VALUES (?, ?, 'merged', ?, ?, UTC_TIMESTAMP())")
                ->execute([$keepId, $ctx->actorId > 0 ? $ctx->actorId : null, mb_substr($gone['hostname'] . ' (#' . $goneId . ')', 0, 255), mb_substr($summary, 0, 255)]);

            $conn->prepare('DELETE FROM assets WHERE id = ?')->execute([$goneId]);
            // After the delete, so a filled asset tag or serial never meets its own original.
            if ($sets) {
                $args[] = $keepId;
                $conn->prepare('UPDATE assets SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($args);
            }
            $conn->commit();
            return ['kept' => $keepId, 'merged' => $goneId, 'moved' => $moved, 'dropped' => $dropped, 'filled' => $filled];
        } catch (Throwable $e) {
            if ($conn->inTransaction()) { $conn->rollBack(); }
            throw $e;
        }
    }

    /**
     * The asset an id means today: itself, or - if it was merged away - the one it went
     * into (following a chain of merges). Null if it never existed or has since gone.
     */
    public static function current(PDO $conn, int $assetId): ?int
    {
        for ($hops = 0; $hops < 10; $hops++) {
            $st = $conn->prepare('SELECT id FROM assets WHERE id = ?');
            $st->execute([$assetId]);
            if ($st->fetchColumn()) { return $assetId; }
            try {
                $m = $conn->prepare('SELECT into_asset_id FROM asset_merges WHERE merged_asset_id = ?');
                $m->execute([$assetId]);
                $into = $m->fetchColumn();
            } catch (PDOException) {
                return null;   // before Database Verification
            }
            if ($into === false) { return null; }
            $assetId = (int)$into;
        }
        return null;
    }

    /** The kept asset for a QR label printed for an asset that has since been merged away. */
    public static function intoForQrToken(PDO $conn, string $token): ?int
    {
        try {
            $st = $conn->prepare('SELECT into_asset_id FROM asset_merges WHERE merged_qr_token = ? ORDER BY id DESC LIMIT 1');
            $st->execute([$token]);
            $into = $st->fetchColumn();
        } catch (PDOException) {
            return null;
        }
        return $into === false ? null : self::current($conn, (int)$into);
    }

    // ======================================================================
    //  Internals
    // ======================================================================

    /** Repoint unless the kept asset already has a row with the same key; then drop the duplicates. */
    private static function moveUnique(PDO $conn, string $table, string $col, array $keys, int $keepId, int $goneId): array
    {
        $on = implode(' AND ', array_map(fn($k) => "k.`$k` <=> g.`$k`", $keys));
        $st = $conn->prepare("UPDATE `$table` g LEFT JOIN `$table` k ON k.`$col` = ? AND $on SET g.`$col` = ? WHERE g.`$col` = ? AND k.`$col` IS NULL");
        $st->execute([$keepId, $keepId, $goneId]);
        $moved = $st->rowCount();
        $del = $conn->prepare("DELETE FROM `$table` WHERE `$col` = ?");
        $del->execute([$goneId]);
        return [$moved, $del->rowCount()];
    }

    /**
     * Inventory source links. A source that reported only the other asset moves with it;
     * one that reported both keeps its link to the kept asset (its rows on the other were
     * already merged or dropped by moveUnique above), and the other link goes with its
     * section times.
     */
    private static function moveSources(PDO $conn, int $keepId, int $goneId): array
    {
        $moved = 0; $dropped = 0;
        $st = $conn->prepare('SELECT id, source FROM asset_inventory_sources WHERE asset_id = ?');
        $st->execute([$goneId]);
        $has = $conn->prepare('SELECT id FROM asset_inventory_sources WHERE asset_id = ? AND source = ?');
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $link) {
            $has->execute([$keepId, $link['source']]);
            if ($has->fetchColumn()) {
                $conn->prepare('DELETE FROM asset_inventory_sources WHERE id = ?')->execute([$link['id']]);
                $dropped++;
            } else {
                // The agent's reference is the asset itself ('asset:<id>'), so it follows.
                $ref = $link['source'] === 'agent' ? ', source_device_ref = ' . $conn->quote('asset:' . $keepId) : '';
                $conn->prepare("UPDATE asset_inventory_sources SET asset_id = ?$ref WHERE id = ?")->execute([$keepId, $link['id']]);
                $moved++;
            }
        }
        return [$moved, $dropped];
    }

    /**
     * Connections, both columns. A connection between the two assets themselves is
     * meaningless once they are one, and goes; where the same source now has two open
     * rows for the same pair, the older stays and the newer is closed.
     */
    private static function moveConnections(PDO $conn, int $keepId, int $goneId): array
    {
        $a = $conn->prepare('UPDATE asset_connections SET asset_id = ? WHERE asset_id = ?');
        $a->execute([$keepId, $goneId]);
        $b = $conn->prepare('UPDATE asset_connections SET connected_asset_id = ? WHERE connected_asset_id = ?');
        $b->execute([$keepId, $goneId]);
        $self = $conn->prepare('DELETE FROM asset_connections WHERE asset_id = connected_asset_id AND asset_id = ?');
        $self->execute([$keepId]);
        $dropped = $self->rowCount();
        $open = $conn->prepare('SELECT id, LEAST(asset_id, connected_asset_id) AS x, GREATEST(asset_id, connected_asset_id) AS y, source
            FROM asset_connections WHERE disconnected_at IS NULL AND (asset_id = ? OR connected_asset_id = ?) ORDER BY id');
        $open->execute([$keepId, $keepId]);
        $seen = [];
        foreach ($open->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $k = $r['x'] . ':' . $r['y'] . ':' . $r['source'];
            if (isset($seen[$k])) {
                $conn->prepare('UPDATE asset_connections SET disconnected_at = UTC_TIMESTAMP() WHERE id = ?')->execute([$r['id']]);
                $dropped++;
            }
            $seen[$k] = true;
        }
        return [$a->rowCount() + $b->rowCount(), $dropped];
    }

    /**
     * Review cases. The case between these two closes as 'merged' and forgets the removed
     * asset (keeping its name in the detail); every other case about it moves to the kept
     * one. A case left about the kept asset and itself is closed too.
     */
    private static function moveReview(PDO $conn, ActorContext $ctx, int $keepId, int $goneId, ?int $reviewItemId): int
    {
        $by = $ctx->actorId > 0 ? $ctx->actorId : null;
        $pair = $conn->prepare("UPDATE asset_review_items SET status = 'merged', resolved_by_id = ?, resolved_at = UTC_TIMESTAMP(),
                asset_id = IF(asset_id = ?, NULL, asset_id), other_asset_id = IF(other_asset_id = ?, NULL, other_asset_id)
            WHERE (asset_id = ? AND other_asset_id = ?) OR (asset_id = ? AND other_asset_id = ?)" . ($reviewItemId ? ' OR id = ?' : ''));
        $pair->execute(array_merge([$by, $goneId, $goneId, $keepId, $goneId, $goneId, $keepId], $reviewItemId ? [$reviewItemId] : []));
        $a = $conn->prepare('UPDATE asset_review_items SET asset_id = ? WHERE asset_id = ?');
        $a->execute([$keepId, $goneId]);
        $b = $conn->prepare('UPDATE asset_review_items SET other_asset_id = ? WHERE other_asset_id = ?');
        $b->execute([$keepId, $goneId]);
        $conn->prepare("UPDATE asset_review_items SET status = 'merged', resolved_by_id = ?, resolved_at = UTC_TIMESTAMP(), other_asset_id = NULL
            WHERE asset_id = ? AND other_asset_id = ? AND status = 'open'")->execute([$by, $keepId, $keepId]);
        return $pair->rowCount() + $a->rowCount() + $b->rowCount();
    }

    /** Documents, other assets' link fields pointing at it, and the recent trail. */
    private static function polymorphic(PDO $conn, int $keepId, int $goneId): array
    {
        $out = [];
        if (self::tableExists($conn, 'document_links')) {
            $st = $conn->prepare("UPDATE document_links g LEFT JOIN document_links k ON k.parent_type = 'asset' AND k.parent_id = ? AND k.document_id = g.document_id
                SET g.parent_id = ? WHERE g.parent_type = 'asset' AND g.parent_id = ? AND k.id IS NULL");
            $st->execute([$keepId, $keepId, $goneId]);
            $del = $conn->prepare("DELETE FROM document_links WHERE parent_type = 'asset' AND parent_id = ?");
            $del->execute([$goneId]);
            $out['document_links.parent_id'] = [$st->rowCount(), $del->rowCount()];
        }
        if (self::tableExists($conn, 'asset_fields')) {
            $ids = [];
            foreach ($conn->query("SELECT id, config FROM asset_fields WHERE field_type = 'ref'")->fetchAll(PDO::FETCH_ASSOC) as $f) {
                $cfg = json_decode((string)$f['config'], true);
                if (($cfg['ref_kind'] ?? '') === 'asset') { $ids[] = (int)$f['id']; }
            }
            if ($ids) {
                $in = implode(',', $ids);
                $st = $conn->prepare("UPDATE asset_field_values SET value_ref_id = ? WHERE value_ref_id = ? AND field_id IN ($in)");
                $st->execute([$keepId, $goneId]);
                // The kept asset can no longer link to itself (a field's no-self-reference rule).
                $self = $conn->prepare("DELETE FROM asset_field_values WHERE asset_id = ? AND value_ref_id = ? AND field_id IN ($in)");
                $self->execute([$keepId, $keepId]);
                $out['asset_field_values.value_ref_id'] = [$st->rowCount(), $self->rowCount()];
            }
        }
        if (self::tableExists($conn, 'analyst_recent_trail')) {
            $del = $conn->prepare("DELETE FROM analyst_recent_trail WHERE entity_type = 'asset' AND entity_id = ?");
            $del->execute([$goneId]);
            $out['analyst_recent_trail.entity_id'] = [0, $del->rowCount()];
        }
        return $out;
    }

    /** An asset can be on one lease at a time; two active leases are a decision for a person. */
    private static function refuseTwoLeases(PDO $conn, int $keepId, int $goneId): void
    {
        require_once dirname(__DIR__) . '/leases.php';
        if (!leaseReady($conn)) { return; }
        $active = $conn->prepare("SELECT COUNT(DISTINCT la.asset_id) FROM lease_assets la LEFT JOIN lease_outcomes lo ON lo.id = la.outcome_id
            WHERE la.asset_id IN (?, ?) AND " . leaseActiveSql());
        $active->execute([$keepId, $goneId]);
        if ((int)$active->fetchColumn() > 1) {
            throw new ServiceError('validation', 'invalid_field', 'Both assets are out on a lease. Mark one returned or take it off its lease first.');
        }
    }

    private static function lockAsset(PDO $conn, ActorContext $ctx, int $id): array
    {
        $st = $conn->prepare('SELECT * FROM assets WHERE id = ? FOR UPDATE');
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        $tenant = $row && $row['tenant_id'] !== null ? (int)$row['tenant_id'] : getDefaultTenantId($conn);
        if (!$row || ($ctx->companyScope !== null && !in_array($tenant, $ctx->companyScope, true))) {
            throw new ServiceError('not_found', 'not_found', 'Asset not found.');
        }
        return $row;
    }

    private static function tableExists(PDO $conn, string $table): bool
    {
        static $known = [];
        if (!isset($known[$table])) {
            try { $conn->query("SELECT 1 FROM `$table` LIMIT 0"); $known[$table] = true; }
            catch (PDOException) { $known[$table] = false; }
        }
        return $known[$table];
    }

    /** "LAPTOP-OLD (#12): 3 tickets, 41 history rows, ... moved; filled serial" - for the history row. */
    private static function summary(array $gone, array $moved, array $dropped, array $filled): string
    {
        $bits = [];
        foreach ($moved as $label => $n) { if ($n > 0) { $bits[] = $n . ' ' . explode('.', $label)[0]; } }
        $text = 'Merged in: ' . ($bits ? implode(', ', $bits) : 'no linked records');
        if ($filled) { $text .= '; filled ' . implode(', ', $filled); }
        return $text;
    }
}
