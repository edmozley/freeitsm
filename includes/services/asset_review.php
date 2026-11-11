<?php
/**
 * Reconciliation you can review (GH #200 part D).
 *
 * FreeITSM's matching already refuses to guess: two assets sharing a serial are
 * never chosen between, a reused hostname becomes a new asset, and a peripheral a
 * tool reports that matches no asset is not created. Each of those used to end in
 * silence or a history line. This keeps them as cases in asset_review_items for a
 * person to decide - the same case is one row however often it is seen again
 * (match_key), and a decision sticks.
 *
 * Kinds, and what a person can decide:
 *   shared_serial         two assets in one company with the same usable serial   merge | different | ignore
 *   hostname_reused       an agent found a new machine wearing an old one's name  merge | different | ignore
 *   unmatched_peripheral  a REST source reported a connection to a serial no      create the asset | ignore
 *                         asset has
 *   ambiguous_match       ...to a serial two or more assets share                 ignore (decide the shared serial)
 *
 * shared_serial and hostname_reused are found by sync(), from the assets themselves
 * and from the history rows the agent has always written; the other two are recorded
 * by AssetConnectionsService as reports arrive. A case that sorts itself out (one of
 * the twins is given another serial, or an asset appears with the peripheral's serial)
 * is closed as 'resolved' with no person.
 *
 * The merge itself is AssetMergeService.
 *
 * @wiki Asset-Review-Developer-Guide
 */
require_once __DIR__ . '/../service_context.php';   // ServiceError, thrown below (GH #288)
require_once __DIR__ . '/assets.php';

final class AssetReviewService
{
    public const KINDS = ['shared_serial', 'hostname_reused', 'unmatched_peripheral', 'ambiguous_match'];

    /** Kinds about two assets, which a merge can settle. */
    public const PAIR_KINDS = ['shared_serial', 'hostname_reused'];

    /**
     * Keep a case for review, or note that it was seen again. A case a person has
     * already decided stays decided; one that had resolved itself opens again.
     *
     * @param string $matchKey what makes two sightings the same case, e.g. 'peripheral:rest:lansweeper:42:abc123'
     * @param bool   $touch    false: only add it if new (a backfill), never move last_seen_at
     */
    public static function record(PDO $conn, int $tenantId, string $kind, string $matchKey, ?int $assetId, ?int $otherAssetId,
                                  ?string $source, ?string $detail, bool $touch = true): void
    {
        if (!self::ready($conn)) { return; }
        $sql = "INSERT INTO asset_review_items (tenant_id, kind, match_key, asset_id, other_asset_id, source, detail, first_seen_at, last_seen_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())";
        $sql .= $touch
            ? " ON DUPLICATE KEY UPDATE last_seen_at = VALUES(last_seen_at), detail = VALUES(detail),
                status = IF(status = 'resolved', 'open', status), resolved_at = IF(status = 'open', NULL, resolved_at)"
            : " ON DUPLICATE KEY UPDATE id = id";
        $conn->prepare($sql)->execute([$tenantId, $kind, mb_substr($matchKey, 0, 191), $assetId, $otherAssetId, $source,
            $detail === null ? null : mb_substr($detail, 0, 1000)]);
    }

    /** The case sorted itself out: close it, if it is still open. */
    public static function settle(PDO $conn, int $tenantId, string $matchKey): void
    {
        if (!self::ready($conn)) { return; }
        $conn->prepare("UPDATE asset_review_items SET status = 'resolved', resolved_at = UTC_TIMESTAMP(), resolved_by_id = NULL
            WHERE tenant_id = ? AND match_key = ? AND status = 'open'")->execute([$tenantId, mb_substr($matchKey, 0, 191)]);
    }

    /**
     * Find the cases that come from the assets themselves, for one company: every pair
     * of assets sharing a usable serial, and every hostname the agent has found reused
     * (from the history row it has always written). Pairs that no longer share a serial
     * close themselves.
     */
    public static function sync(PDO $conn, int $tenantId): void
    {
        if (!self::ready($conn)) { return; }
        $store = $tenantId === getDefaultTenantId($conn) ? null : $tenantId;
        $ignored = AssetsService::getIgnoredServiceTags($conn);

        $groups = $conn->prepare("SELECT MIN(service_tag) AS tag, GROUP_CONCAT(id ORDER BY id) AS ids FROM assets
            WHERE tenant_id <=> ? AND service_tag IS NOT NULL AND TRIM(service_tag) <> ''
            GROUP BY service_tag HAVING COUNT(*) > 1");
        $groups->execute([$store]);
        $live = [];
        foreach ($groups->fetchAll(PDO::FETCH_ASSOC) as $g) {
            if (!AssetsService::isUsableServiceTag($g['tag'], $ignored)) { continue; }
            $ids = array_map('intval', explode(',', $g['ids']));
            $first = array_shift($ids);
            foreach ($ids as $other) {
                $key = 'serial:' . mb_strtolower($g['tag']) . ':' . $first . ':' . $other;
                $live[$key] = true;
                self::record($conn, $tenantId, 'shared_serial', $key, $first, $other, null, json_encode(['serial' => $g['tag']], JSON_UNESCAPED_UNICODE), false);
            }
        }
        $open = $conn->prepare("SELECT match_key FROM asset_review_items WHERE tenant_id = ? AND kind = 'shared_serial' AND status = 'open'");
        $open->execute([$tenantId]);
        foreach ($open->fetchAll(PDO::FETCH_COLUMN) as $key) {
            if (!isset($live[$key])) { self::settle($conn, $tenantId, $key); }
        }

        // hostname_reused: the history row createDiscoveredAsset() writes on the NEW asset, '#<old id>'.
        $reused = $conn->prepare("SELECT h.asset_id, h.new_value, h.created_datetime FROM asset_history h JOIN assets a ON a.id = h.asset_id
            WHERE h.field_name = 'hostname_reused' AND a.tenant_id <=> ?");
        $reused->execute([$store]);
        foreach ($reused->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $old = (int)ltrim((string)$r['new_value'], '#');
            if ($old <= 0 || !$conn->query('SELECT 1 FROM assets WHERE id = ' . $old)->fetchColumn()) { continue; }
            self::record($conn, $tenantId, 'hostname_reused', 'hostname:' . (int)$r['asset_id'] . ':' . $old, (int)$r['asset_id'], $old, 'agent',
                json_encode(['found_at' => $r['created_datetime']]), false);
        }
    }

    /**
     * sync()'s shared-serial check for ONE asset's serial, so the asset's own page can say
     * it shares one without waiting for somebody to open the review list. Same keys, so
     * the two never disagree.
     */
    private static function syncSerialOf(PDO $conn, int $assetId): void
    {
        $st = $conn->prepare('SELECT service_tag, tenant_id FROM assets WHERE id = ?');
        $st->execute([$assetId]);
        $a = $st->fetch(PDO::FETCH_ASSOC);
        if (!$a || !AssetsService::isUsableServiceTag($a['service_tag'])) { return; }
        $ids = $conn->prepare('SELECT id FROM assets WHERE service_tag = ? AND tenant_id <=> ? ORDER BY id');
        $ids->execute([$a['service_tag'], $a['tenant_id']]);
        $group = array_map('intval', $ids->fetchAll(PDO::FETCH_COLUMN));
        if (count($group) < 2) { return; }
        $tenant = $a['tenant_id'] === null ? getDefaultTenantId($conn) : (int)$a['tenant_id'];
        $first = array_shift($group);
        foreach ($group as $other) {
            self::record($conn, $tenant, 'shared_serial', 'serial:' . mb_strtolower((string)$a['service_tag']) . ':' . $first . ':' . $other,
                $first, $other, null, json_encode(['serial' => $a['service_tag']], JSON_UNESCAPED_UNICODE), false);
        }
    }

    /**
     * The cases in the analyst's active company, newest first, with both assets.
     *
     * @param string $which 'open' or 'decided'
     */
    public static function listFor(PDO $conn, int $analystId, string $which = 'open'): array
    {
        if (!self::ready($conn)) { return []; }
        [$tSql, $tArgs] = activeTenantFilter($conn, $analystId, 'r');
        $status = $which === 'decided' ? "r.status IN ('merged', 'different', 'ignored')" : "r.status = 'open'";
        $st = $conn->prepare("SELECT r.*, a.hostname AS a_hostname, a.service_tag AS a_serial, a.asset_tag AS a_tag, a.manufacturer AS a_make, a.model AS a_model,
                a.last_seen AS a_last_seen, a.asset_type_id AS a_type,
                o.hostname AS o_hostname, o.service_tag AS o_serial, o.asset_tag AS o_tag, o.manufacturer AS o_make, o.model AS o_model,
                o.last_seen AS o_last_seen, o.asset_type_id AS o_type,
                rb.full_name AS resolved_by_name
            FROM asset_review_items r
            LEFT JOIN assets a ON a.id = r.asset_id
            LEFT JOIN assets o ON o.id = r.other_asset_id
            LEFT JOIN analysts rb ON rb.id = r.resolved_by_id
            WHERE $status $tSql
            ORDER BY COALESCE(r.resolved_at, r.last_seen_at) DESC, r.id DESC LIMIT 500");
        $st->execute($tArgs);
        return array_map([self::class, 'shape'], $st->fetchAll(PDO::FETCH_ASSOC));
    }

    /** The open cases one asset is part of, for the line on its page. */
    public static function openFor(PDO $conn, int $assetId): array
    {
        if (!self::ready($conn)) { return []; }
        self::syncSerialOf($conn, $assetId);
        $st = $conn->prepare("SELECT r.*, a.hostname AS a_hostname, o.hostname AS o_hostname FROM asset_review_items r
            LEFT JOIN assets a ON a.id = r.asset_id LEFT JOIN assets o ON o.id = r.other_asset_id
            WHERE r.status = 'open' AND (r.asset_id = ? OR r.other_asset_id = ?) ORDER BY r.id");
        $st->execute([$assetId, $assetId]);
        return array_map([self::class, 'shape'], $st->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * A person's decision: 'different' (they are separate assets, stop asking),
     * 'ignored' (not worth deciding), or 'open' (undo a decision). Merging is
     * AssetMergeService, which closes the case as 'merged'.
     */
    public static function decide(PDO $conn, ActorContext $ctx, int $analystId, int $itemId, string $decision): array
    {
        $item = self::item($conn, $analystId, $itemId);
        $allowed = in_array($item['kind'], self::PAIR_KINDS, true) ? ['different', 'ignored', 'open'] : ['ignored', 'open'];
        if (!in_array($decision, $allowed, true)) {
            throw new ServiceError('validation', 'invalid_field', 'That decision does not apply to this case.');
        }
        if ($decision === 'open') {
            $conn->prepare("UPDATE asset_review_items SET status = 'open', resolved_by_id = NULL, resolved_at = NULL WHERE id = ?")->execute([$itemId]);
        } else {
            $conn->prepare('UPDATE asset_review_items SET status = ?, resolved_by_id = ?, resolved_at = UTC_TIMESTAMP() WHERE id = ?')
                ->execute([$decision, $ctx->actorId > 0 ? $ctx->actorId : null, $itemId]);
        }
        $text = self::describe($item) . ' - ' . ($decision === 'open' ? 'back for review' : ($decision === 'different' ? 'marked as different assets' : 'ignored'));
        foreach (array_unique(array_filter([$item['asset_id'], $item['other_asset_id']])) as $id) {
            $conn->prepare("INSERT INTO asset_history (asset_id, analyst_id, field_name, old_value, new_value, created_datetime)
                VALUES (?, ?, 'review', NULL, ?, UTC_TIMESTAMP())")->execute([(int)$id, $ctx->actorId > 0 ? $ctx->actorId : null, mb_substr($text, 0, 255)]);
        }
        return ['id' => $itemId, 'status' => $decision];
    }

    /**
     * An unmatched peripheral is a real device FreeITSM does not have: create it, with
     * the serial and model the tool reported, and open the connection the tool saw.
     */
    public static function createPeripheral(PDO $conn, ActorContext $ctx, int $analystId, int $itemId): array
    {
        require_once __DIR__ . '/asset_connections.php';
        $item = self::item($conn, $analystId, $itemId);
        if ($item['kind'] !== 'unmatched_peripheral' || $item['status'] !== 'open') {
            throw new ServiceError('validation', 'invalid_field', 'Only an open unmatched device can be created.');
        }
        $d = $item['detail'];
        $serial = trim((string)($d['serial'] ?? ''));
        if ($serial === '' || !$item['asset_id']) { throw new ServiceError('validation', 'invalid_field', 'This case has no serial to create an asset from.'); }
        $tenant = (int)$item['tenant_id'];
        $exists = $conn->prepare('SELECT COUNT(*) FROM assets WHERE service_tag = ? AND tenant_id <=> ?');
        $exists->execute([$serial, $tenant === getDefaultTenantId($conn) ? null : $tenant]);
        if ((int)$exists->fetchColumn() > 0) {
            throw new ServiceError('conflict', 'conflict', "An asset with the serial $serial already exists; the tool's next report will connect it.");
        }
        $name = trim((string)($d['model'] ?? '')) ?: (trim((string)($d['name'] ?? '')) ?: $serial);
        $hostname = mb_substr($name . ' ' . $serial, 0, 50);
        $newId = AssetsService::createAsset($conn, $ctx, ['hostname' => $hostname, 'service_tag' => mb_substr($serial, 0, 50),
            'model' => isset($d['model']) ? mb_substr((string)$d['model'], 0, 50) : null], 'From the review list: ' . self::describe($item), $tenant);
        $conn->beginTransaction();
        try {
            $seen = isset($d['observed_at']) ? (string)$d['observed_at'] : gmdate('Y-m-d H:i:s');
            if (!empty($d['connected']) || !array_key_exists('connected', $d)) {
                $conn->prepare('INSERT INTO asset_connections (asset_id, connected_asset_id, source, connected_at, first_observed_at, last_observed_at) VALUES (?, ?, ?, ?, ?, ?)')
                    ->execute([(int)$item['asset_id'], $newId, (string)$item['source'], $seen, $seen, $seen]);
            }
            $conn->prepare("UPDATE asset_review_items SET status = 'resolved', resolved_by_id = ?, resolved_at = UTC_TIMESTAMP() WHERE id = ?")
                ->execute([$ctx->actorId > 0 ? $ctx->actorId : null, $itemId]);
            $conn->commit();
        } catch (Throwable $e) {
            if ($conn->inTransaction()) { $conn->rollBack(); }
            throw $e;
        }
        return ['id' => $itemId, 'asset_id' => $newId];
    }

    /** One case, if it is in a company the analyst may see - otherwise "not found". */
    public static function item(PDO $conn, int $analystId, int $itemId): array
    {
        $st = $conn->prepare('SELECT r.*, a.hostname AS a_hostname, o.hostname AS o_hostname FROM asset_review_items r
            LEFT JOIN assets a ON a.id = r.asset_id LEFT JOIN assets o ON o.id = r.other_asset_id WHERE r.id = ?');
        $st->execute([$itemId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row || !in_array((int)$row['tenant_id'], array_map('intval', getAccessibleTenantIds($conn, $analystId)), true)) {
            throw new ServiceError('not_found', 'not_found', 'Review case not found.');
        }
        return self::shape($row);
    }

    /** The case in a sentence, for history rows. */
    public static function describe(array $item): string
    {
        $a = $item['asset']['hostname'] ?? ('#' . $item['asset_id']);
        $o = $item['other']['hostname'] ?? ('#' . $item['other_asset_id']);
        $serial = $item['detail']['serial'] ?? '';
        return match ($item['kind']) {
            'shared_serial'        => "$a and $o share the serial $serial",
            'hostname_reused'      => "$a was found with the name $o had",
            'unmatched_peripheral' => "A device with serial $serial was reported on $a",
            'ambiguous_match'      => "Serial $serial was reported on $a and matches more than one asset",
            default                => $item['kind'],
        };
    }

    private static function shape(array $r): array
    {
        $asset = static fn(string $p) => $r[$p . '_hostname'] === null && !isset($r[$p . '_serial']) ? null : [
            'hostname' => $r[$p . '_hostname'], 'service_tag' => $r[$p . '_serial'] ?? null, 'asset_tag' => $r[$p . '_tag'] ?? null,
            'manufacturer' => $r[$p . '_make'] ?? null, 'model' => $r[$p . '_model'] ?? null,
            'last_seen' => isset($r[$p . '_last_seen']) ? $r[$p . '_last_seen'] : null,
            'asset_type_id' => isset($r[$p . '_type']) ? (int)$r[$p . '_type'] : null];
        $detail = json_decode((string)($r['detail'] ?? ''), true);
        return [
            'id' => (int)$r['id'], 'tenant_id' => (int)$r['tenant_id'], 'kind' => $r['kind'], 'status' => $r['status'],
            'asset_id' => $r['asset_id'] === null ? null : (int)$r['asset_id'],
            'other_asset_id' => $r['other_asset_id'] === null ? null : (int)$r['other_asset_id'],
            'asset' => $r['asset_id'] === null ? null : $asset('a'), 'other' => $r['other_asset_id'] === null ? null : $asset('o'),
            'source' => $r['source'], 'detail' => is_array($detail) ? $detail : [],
            'first_seen_at' => $r['first_seen_at'], 'last_seen_at' => $r['last_seen_at'],
            'resolved_at' => $r['resolved_at'] ?? null, 'resolved_by' => $r['resolved_by_name'] ?? null,
        ];
    }

    /** Before Database Verification has created the table, nothing is kept - and nothing fails. */
    public static function ready(PDO $conn): bool
    {
        static $ready = null;
        if ($ready === null) {
            try { $conn->query('SELECT 1 FROM asset_review_items LIMIT 0'); $ready = true; }
            catch (Throwable) { $ready = false; }
        }
        return $ready;
    }
}
