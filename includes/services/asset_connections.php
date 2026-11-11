<?php
/**
 * Connected assets (GH #200 part C): a monitor plugged into a computer, a phone in a
 * dock, a printer on a print server.
 *
 * A PHYSICAL attachment, kept apart from the two things that look similar:
 *   - custody (users_assets, handover) - who holds an asset. A monitor can be connected
 *     to a computer AND signed out to a person; neither updates the other.
 *   - CMDB relationships - service dependencies that carry impact. A connection never
 *     says "if this fails, that is affected".
 *
 * One row per source. A person's confirmation is source 'manual'; a REST tool's
 * observation is 'rest:<name>' (through POST /assets/{id}/inventory, section
 * connections). The same monitor confirmed by a person and observed by a tool is two
 * rows, side by side, and:
 *   - a source only ever opens and closes its OWN rows
 *   - a source never touches a person's row; a person disconnects their own
 *   - nothing is disconnected by silence: only a report from the source that saw it -
 *     connected: false, or a complete list without it - closes that source's row.
 *     An old observation simply ages and is shown with its date.
 *
 * Every connection and disconnection is written to BOTH assets' history.
 *
 * @wiki Connected-Assets-Developer-Guide
 */
require_once __DIR__ . '/../service_context.php';   // ServiceError, thrown below (GH #288)
require_once __DIR__ . '/assets.php';
require_once __DIR__ . '/asset_review.php';

final class AssetConnectionsService
{
    /**
     * An asset's connections, from either end: what is connected now (grouped by the
     * other asset, each source's row beside the others), and every row that has ended.
     *
     * @return array{current: array<int,array>, history: array<int,array>}
     */
    public static function listFor(PDO $conn, ActorContext $ctx, int $assetId): array
    {
        self::asset($conn, $ctx, $assetId);
        $st = $conn->prepare("
            SELECT c.id, c.asset_id, c.connected_asset_id, c.source, c.connected_at, c.first_observed_at, c.last_observed_at,
                   c.disconnected_at, c.confirmed_by_id, c.disconnected_by_id,
                   o.id AS other_id, o.hostname AS other_hostname, o.asset_type_id AS other_type_id, o.service_tag AS other_serial,
                   o.manufacturer AS other_manufacturer, o.model AS other_model,
                   cb.full_name AS confirmed_by_name, db.full_name AS disconnected_by_name
              FROM asset_connections c
              JOIN assets o ON o.id = IF(c.asset_id = ?, c.connected_asset_id, c.asset_id)
              LEFT JOIN analysts cb ON cb.id = c.confirmed_by_id
              LEFT JOIN analysts db ON db.id = c.disconnected_by_id
             WHERE c.asset_id = ? OR c.connected_asset_id = ?
             ORDER BY c.disconnected_at IS NULL DESC, COALESCE(c.disconnected_at, c.last_observed_at, c.connected_at) DESC, c.id DESC");
        $st->execute([$assetId, $assetId, $assetId]);
        $current = []; $history = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $row = [
                'id'                 => (int)$r['id'],
                'source'             => $r['source'],
                'manual'             => $r['source'] === 'manual',
                'reported_here'      => (int)$r['asset_id'] === $assetId,
                'connected_at'       => self::iso($r['connected_at']),
                'first_observed_at'  => self::iso($r['first_observed_at']),
                'last_observed_at'   => self::iso($r['last_observed_at']),
                'disconnected_at'    => self::iso($r['disconnected_at']),
                'confirmed_by'       => $r['confirmed_by_name'],
                'disconnected_by'    => $r['disconnected_by_name'],
                'other'              => ['id' => (int)$r['other_id'], 'hostname' => $r['other_hostname'],
                                         'asset_type_id' => $r['other_type_id'] === null ? null : (int)$r['other_type_id'],
                                         'service_tag' => $r['other_serial'], 'manufacturer' => $r['other_manufacturer'], 'model' => $r['other_model']],
            ];
            if ($r['disconnected_at'] === null) {
                $key = (int)$r['other_id'];
                $current[$key] ??= ['other' => $row['other'], 'rows' => []];
                $current[$key]['rows'][] = $row;
            } else {
                $history[] = $row;
            }
        }
        return ['current' => array_values($current), 'history' => $history];
    }

    /**
     * A person confirms two assets are connected. Both must be in the same company and
     * reachable by the caller; a second confirmation of the same pair is refused.
     */
    public static function connect(PDO $conn, ActorContext $ctx, int $assetId, int $otherId): array
    {
        if ($assetId === $otherId) { throw new ServiceError('validation', 'invalid_field', 'An asset cannot be connected to itself.'); }
        $ownTransaction = !$conn->inTransaction();
        if ($ownTransaction) { $conn->beginTransaction(); }
        try {
            $a = self::asset($conn, $ctx, $assetId, true);
            $b = self::asset($conn, $ctx, $otherId, true);
            if ($a['tenant'] !== $b['tenant']) {
                throw new ServiceError('validation', 'invalid_field', 'Both assets must be in the same company to be connected.');
            }
            $open = $conn->prepare("SELECT id FROM asset_connections WHERE source = 'manual' AND disconnected_at IS NULL
                AND ((asset_id = ? AND connected_asset_id = ?) OR (asset_id = ? AND connected_asset_id = ?)) LIMIT 1");
            $open->execute([$assetId, $otherId, $otherId, $assetId]);
            if ($open->fetchColumn()) {
                throw new ServiceError('conflict', 'conflict', "{$b['hostname']} is already confirmed as connected to this asset.");
            }
            $conn->prepare("INSERT INTO asset_connections (asset_id, connected_asset_id, source, connected_at, confirmed_by_id)
                VALUES (?, ?, 'manual', UTC_TIMESTAMP(), ?)")->execute([$assetId, $otherId, $ctx->actorId > 0 ? $ctx->actorId : null]);
            $id = (int)$conn->lastInsertId();
            self::history($conn, $ctx->actorId, $a, $b, 'manual', true);
            if ($ownTransaction) { $conn->commit(); }
            return ['id' => $id];
        } catch (Throwable $e) {
            if ($ownTransaction && $conn->inTransaction()) { $conn->rollBack(); }
            throw $e;
        }
    }

    /**
     * A person ends a connection THEY (or another person) confirmed. A source's row is
     * the source's to close - a person who disagrees confirms their own view beside it.
     */
    public static function disconnect(PDO $conn, ActorContext $ctx, int $connectionId): array
    {
        $ownTransaction = !$conn->inTransaction();
        if ($ownTransaction) { $conn->beginTransaction(); }
        try {
            $st = $conn->prepare('SELECT * FROM asset_connections WHERE id = ? FOR UPDATE');
            $st->execute([$connectionId]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row) { throw new ServiceError('not_found', 'not_found', 'Connection not found.'); }
            $a = self::asset($conn, $ctx, (int)$row['asset_id'], true);
            $b = self::asset($conn, $ctx, (int)$row['connected_asset_id'], true);
            if ($row['source'] !== 'manual') {
                throw new ServiceError('validation', 'invalid_field', 'Only a connection confirmed by a person can be disconnected here. A tool\'s observation ends when the tool reports it gone.');
            }
            if ($row['disconnected_at'] !== null) {
                throw new ServiceError('conflict', 'conflict', 'This connection has already ended.');
            }
            $conn->prepare('UPDATE asset_connections SET disconnected_at = UTC_TIMESTAMP(), disconnected_by_id = ? WHERE id = ?')
                ->execute([$ctx->actorId > 0 ? $ctx->actorId : null, $connectionId]);
            self::history($conn, $ctx->actorId, $a, $b, 'manual', false);
            if ($ownTransaction) { $conn->commit(); }
            return ['id' => $connectionId];
        } catch (Throwable $e) {
            if ($ownTransaction && $conn->inTransaction()) { $conn->rollBack(); }
            throw $e;
        }
    }

    /**
     * A REST source's observed connections for one asset - the `connections` section of
     * POST /assets/{id}/inventory. Runs inside AssetInventoryService::submit()'s savepoint.
     *
     * Each item names the other asset by its FreeITSM id (already resolved by the tool) or
     * by serial (matched with the same resolver as every inventory source, which never
     * guesses). One that cannot be matched - no asset has the serial, or several do - is
     * kept for review (asset_review_items) and reported back, never created.
     *
     * @param array  $section validated: ['complete' => bool, 'items' => [[connected_asset_id|serial, name, model, connected], ...]]
     * @param string $stored  'rest:<name>'
     * @return array{added:int, updated:int, removed:int, unmatched:array<int,string>}
     */
    public static function applyObserved(PDO $conn, int $hostId, ?int $hostTenantRaw, string $stored, array $section, string $observed): array
    {
        $tenant = $hostTenantRaw ?? getDefaultTenantId($conn);
        $hostname = (string)$conn->query('SELECT hostname FROM assets WHERE id = ' . $hostId)->fetchColumn();
        $host = ['id' => $hostId, 'hostname' => $hostname, 'tenant' => $tenant];
        $counts = ['added' => 0, 'updated' => 0, 'removed' => 0, 'unmatched' => []];
        $openRows = $conn->prepare('SELECT id, connected_asset_id FROM asset_connections WHERE asset_id = ? AND source = ? AND disconnected_at IS NULL');
        $openRows->execute([$hostId, $stored]);
        $open = [];
        foreach ($openRows->fetchAll(PDO::FETCH_ASSOC) as $r) { $open[(int)$r['connected_asset_id']] = (int)$r['id']; }

        $seen = [];
        foreach ($section['items'] as $item) {
            $otherId = null;
            if ($item['connected_asset_id'] !== null) {
                // An id a tool resolved before the asset was merged into another still finds it.
                require_once __DIR__ . '/asset_merge.php';
                $st = $conn->prepare('SELECT id, hostname, tenant_id FROM assets WHERE id = ?');
                $st->execute([AssetMergeService::current($conn, $item['connected_asset_id']) ?? $item['connected_asset_id']]);
                $o = $st->fetch(PDO::FETCH_ASSOC);
                if (!$o || (int)($o['tenant_id'] ?? getDefaultTenantId($conn)) !== $tenant) {
                    throw new ServiceError('validation', 'invalid_field', "connected_asset_id {$item['connected_asset_id']} is not an asset in this asset's company.");
                }
                $otherId = (int)$o['id'];
            } else {
                $serial = $item['serial'];
                $key = 'peripheral:' . $stored . ':' . $hostId . ':' . mb_strtolower($serial);
                $what = trim(($item['name'] ?? '') . ' ' . ($item['model'] ?? '')) ?: 'A device';
                $r = AssetsService::resolveAssetIdentity($conn, ['service_tag' => $serial], $hostTenantRaw);
                if ($r['asset_id'] === null || $r['asset_id'] === $hostId) {
                    if (!$item['connected']) { continue; }   // "gone" for something never matched: nothing to end
                    $ambiguous = $r['ambiguous'];
                    AssetReviewService::record($conn, $tenant, $ambiguous ? 'ambiguous_match' : 'unmatched_peripheral', $key, $hostId, null, $stored,
                        json_encode(['serial' => $serial, 'name' => $item['name'], 'model' => $item['model'], 'connected' => $item['connected'], 'observed_at' => $observed], JSON_UNESCAPED_UNICODE));
                    $counts['unmatched'][] = $serial;
                    continue;
                }
                AssetReviewService::settle($conn, $tenant, $key);
                $otherId = (int)$r['asset_id'];
            }
            if ($otherId === $hostId) { throw new ServiceError('validation', 'invalid_field', 'An asset cannot be connected to itself.'); }
            $seen[$otherId] = true;
            if (!$item['connected']) {
                if (isset($open[$otherId])) {
                    self::close($conn, $open[$otherId], $observed, $host, $otherId, $stored);
                    unset($open[$otherId]);
                    $counts['removed']++;
                }
                continue;
            }
            if (isset($open[$otherId])) {
                $conn->prepare('UPDATE asset_connections SET last_observed_at = GREATEST(COALESCE(last_observed_at, ?), ?) WHERE id = ?')
                    ->execute([$observed, $observed, $open[$otherId]]);
                $counts['updated']++;
                continue;
            }
            $conn->prepare('INSERT INTO asset_connections (asset_id, connected_asset_id, source, connected_at, first_observed_at, last_observed_at)
                VALUES (?, ?, ?, ?, ?, ?)')->execute([$hostId, $otherId, $stored, $observed, $observed, $observed]);
            $open[$otherId] = (int)$conn->lastInsertId();
            self::history($conn, 0, $host, self::assetRow($conn, $otherId), $stored, true);
            $counts['added']++;
        }
        // A complete list is the source saying "and nothing else": its other rows here end.
        // A partial list never ends anything.
        if ($section['complete']) {
            foreach ($open as $otherId => $rowId) {
                if (isset($seen[$otherId])) { continue; }
                self::close($conn, $rowId, $observed, $host, $otherId, $stored);
                $counts['removed']++;
            }
        }
        return $counts;
    }

    // ======================================================================
    //  Internals
    // ======================================================================

    private static function close(PDO $conn, int $rowId, string $at, array $host, int $otherId, string $source): void
    {
        $conn->prepare('UPDATE asset_connections SET disconnected_at = ? WHERE id = ?')->execute([$at, $rowId]);
        self::history($conn, 0, $host, self::assetRow($conn, $otherId), $source, false);
    }

    /** "MONITOR-01 (lansweeper)" on both assets: a connection on the new side, a disconnection on the old. */
    private static function history(PDO $conn, int $actorId, array $a, array $b, string $source, bool $connected): void
    {
        $by = $source === 'manual' ? 'confirmed by a person' : (str_starts_with($source, 'rest:') ? 'observed by ' . substr($source, 5) : $source);
        $add = $conn->prepare('INSERT INTO asset_history (asset_id, analyst_id, field_name, old_value, new_value, created_datetime)
            VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP())');
        foreach ([[$a, $b], [$b, $a]] as [$self, $other]) {
            $text = $other['hostname'] . ' (' . $by . ')';
            $add->execute([$self['id'], $actorId > 0 ? $actorId : null, 'connection', $connected ? null : $text, $connected ? $text : null]);
        }
    }

    private static function assetRow(PDO $conn, int $id): array
    {
        $st = $conn->prepare('SELECT id, hostname, tenant_id FROM assets WHERE id = ?');
        $st->execute([$id]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return ['id' => $id, 'hostname' => (string)($r['hostname'] ?? ('#' . $id)), 'tenant' => $r && $r['tenant_id'] !== null ? (int)$r['tenant_id'] : getDefaultTenantId($conn)];
    }

    /** The asset, if the caller may reach it - one message for "not there" and "not yours". */
    private static function asset(PDO $conn, ActorContext $ctx, int $id, bool $lock = false): array
    {
        $st = $conn->prepare('SELECT id, hostname, tenant_id FROM assets WHERE id = ?' . ($lock ? ' FOR UPDATE' : ''));
        $st->execute([$id]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        $tenant = $r && $r['tenant_id'] !== null ? (int)$r['tenant_id'] : getDefaultTenantId($conn);
        if (!$r || ($ctx->companyScope !== null && !in_array($tenant, $ctx->companyScope, true))) {
            throw new ServiceError('not_found', 'not_found', 'Asset not found.');
        }
        return ['id' => (int)$r['id'], 'hostname' => (string)$r['hostname'], 'tenant' => $tenant];
    }

    private static function iso(?string $utc): ?string
    {
        return $utc === null ? null : str_replace(' ', 'T', $utc) . 'Z';
    }
}
