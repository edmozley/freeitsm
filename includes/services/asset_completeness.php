<?php
/**
 * Assets: missing information (GH #404) - what each asset type is EXPECTED to have
 * filled in eventually, and which assets still lack it.
 *
 * Flags, never refuses. An expected field changes nothing about saving: the asset
 * page, imports, the REST API and the agent write exactly what they wrote before, and
 * an asset missing an expected field is saved and updated as normal. It simply shows
 * up on Assets -> Missing information with the field named, until someone fills it in
 * or marks it Not applicable for that asset.
 *
 * Findings are WORKED OUT, not stored. There is no case to open or close: the list is
 * a query over the assets as they are now, so filling a field from any route - the
 * page, an import, the API, the agent - clears its finding at once, clearing it brings
 * it back, and changing a type's expectations changes the list the next time it loads.
 *
 * Missing means: NULL, blank text, an unselected reference (NULL or 0). 0 as a number
 * and false as a yes/no count as filled in - presence, not correctness. A custom field
 * is missing when it has no stored value at all.
 *
 * Not applicable is an explicit, reversible exception per asset and field, kept in its
 * own table; it never writes a placeholder into the field. A type's LAYOUT hiding a
 * section does not exempt anything (asset type profiles are presentation only - see
 * includes/services/asset_type_layout.php).
 *
 * Retired assets are left out: nobody is expected to complete a record that is gone.
 *
 * @wiki Asset-Missing-Information
 */

require_once __DIR__ . '/../tenancy.php';

final class AssetCompleteness
{
    /**
     * The built-in fields a type may expect, by section, in display order.
     * key => [section, column, kind]. kind: 'text' (blank is missing), 'ref' (NULL or 0 is
     * missing), 'value' (only NULL is missing - dates and numbers, so a cost of 0 counts).
     */
    public const BUILTIN = [
        'asset_tag'       => ['identity',  'asset_tag',       'text'],
        'service_tag'     => ['identity',  'service_tag',     'text'],
        'location_id'     => ['identity',  'location_id',     'ref'],
        'manufacturer'    => ['make_model', 'manufacturer',   'text'],
        'model'           => ['make_model', 'model',          'text'],
        'purchase_date'   => ['financial', 'purchase_date',   'value'],
        'purchase_cost'   => ['financial', 'purchase_cost',   'value'],
        'supplier_id'     => ['financial', 'supplier_id',     'ref'],
        'cost_centre_id'  => ['financial', 'cost_centre_id',  'ref'],
        'order_number'    => ['financial', 'order_number',    'text'],
        'warranty_expiry' => ['financial', 'warranty_expiry', 'value'],
    ];
    public const SECTIONS = ['identity', 'make_model', 'financial', 'custom'];

    /** Keys in screen order: the built-in fields as listed in BUILTIN, then custom fields by id. */
    public static function orderKeys(array $keys): array
    {
        $pos = array_flip(array_keys(self::BUILTIN));
        usort($keys, function ($x, $y) use ($pos) {
            $px = $pos[$x] ?? 1000 + (int)substr($x, 7);
            $py = $pos[$y] ?? 1000 + (int)substr($y, 7);
            return $px <=> $py;
        });
        return array_values($keys);
    }

    /** Are the two tables there? Before Database Verification nothing is expected and nothing is listed. */
    public static function ready(PDO $conn): bool
    {
        static $ok = null;
        if ($ok !== null) return $ok;
        try {
            $conn->query('SELECT 1 FROM asset_type_expected_fields LIMIT 0');
            $conn->query('SELECT 1 FROM asset_field_not_applicable LIMIT 0');
            return $ok = true;
        } catch (Throwable $e) {
            return $ok = false;
        }
    }

    /** Is anything expected at all? The Missing information button shows only then. */
    public static function anyExpected(PDO $conn): bool
    {
        if (!self::ready($conn)) return false;
        return (bool)$conn->query('SELECT 1 FROM asset_type_expected_fields LIMIT 1')->fetchColumn();
    }

    /** The section a field key belongs to: a built-in section, or 'custom'. */
    public static function sectionOf(string $key): ?string
    {
        if (isset(self::BUILTIN[$key])) return self::BUILTIN[$key][0];
        return preg_match('/^custom:\d+$/', $key) ? 'custom' : null;
    }

    /**
     * The custom fields a type can expect: the live fields in the field sets attached to it.
     *
     * @return array<int, array{key: string, label: string}>
     */
    public static function customCandidates(PDO $conn, int $typeId): array
    {
        $stmt = $conn->prepare(
            "SELECT DISTINCT f.id, f.label
               FROM asset_type_field_sets ts
               JOIN asset_field_set_fields sf ON sf.set_id = ts.set_id
               JOIN asset_fields f ON f.id = sf.field_id AND f.is_deleted = 0
              WHERE ts.asset_type_id = ?
              ORDER BY f.label");
        $stmt->execute([$typeId]);
        return array_map(fn($r) => ['key' => 'custom:' . (int)$r['id'], 'label' => (string)$r['label']], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Expected field keys for each type.
     *
     * @param int[] $typeIds
     * @return array<int, string[]>  typeId => keys
     */
    public static function expectedForTypes(PDO $conn, array $typeIds): array
    {
        $out = [];
        if (!$typeIds || !self::ready($conn)) return $out;
        $in = implode(',', array_fill(0, count($typeIds), '?'));
        $stmt = $conn->prepare("SELECT asset_type_id, field_key FROM asset_type_expected_fields WHERE asset_type_id IN ($in) ORDER BY id");
        $stmt->execute(array_map('intval', $typeIds));
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) $out[(int)$r['asset_type_id']][] = (string)$r['field_key'];
        return array_map([self::class, 'orderKeys'], $out);
    }

    /**
     * Replace a type's expected fields. Unknown keys, and custom fields not offered by
     * the type's field sets, are dropped rather than stored.
     *
     * @param mixed $keys  the editor's list of keys
     */
    public static function saveExpected(PDO $conn, int $typeId, $keys): void
    {
        if (!self::ready($conn)) return;
        $allowedCustom = array_column(self::customCandidates($conn, $typeId), 'key');
        $clean = [];
        foreach (is_array($keys) ? $keys : [] as $k) {
            $k = (string)$k;
            if (isset(self::BUILTIN[$k]) || in_array($k, $allowedCustom, true)) $clean[$k] = true;
        }
        $conn->prepare('DELETE FROM asset_type_expected_fields WHERE asset_type_id = ?')->execute([$typeId]);
        $ins = $conn->prepare('INSERT INTO asset_type_expected_fields (asset_type_id, field_key) VALUES (?, ?)');
        foreach (array_keys($clean) as $k) $ins->execute([$typeId, $k]);
    }

    /**
     * The SQL that is true when one expected field is missing on asset alias `a`.
     *
     * @return array{0: string, 1: array}  [sql, params]
     */
    private static function missingSql(string $key): array
    {
        if (isset(self::BUILTIN[$key])) {
            [, $col, $kind] = self::BUILTIN[$key];
            if ($kind === 'text') return ["(a.`$col` IS NULL OR TRIM(a.`$col`) = '')", []];
            if ($kind === 'ref')  return ["(a.`$col` IS NULL OR a.`$col` = 0)", []];
            return ["a.`$col` IS NULL", []];
        }
        $fieldId = (int)substr($key, 7);
        // A custom value row counts when any of its typed columns holds something
        // (blank text does not); boolean false and number 0 are values.
        return ["NOT EXISTS (SELECT 1 FROM asset_field_values v WHERE v.asset_id = a.id AND v.field_id = ?
                 AND ((v.value_text IS NOT NULL AND TRIM(v.value_text) <> '') OR v.value_number IS NOT NULL
                      OR v.value_date IS NOT NULL OR v.value_boolean IS NOT NULL OR v.value_ref_id IS NOT NULL))", [$fieldId]];
    }

    /**
     * Every finding the reader may see: one row per asset with what it is missing.
     *
     * @param int $analystId  the reader (company scope comes from the active company, or All companies)
     * @param array{type_id?: int, field?: string, section?: string, asset_id?: int} $filters
     * @return array<int, array{asset_id: int, name: ?string, asset_tag: ?string, type_id: int, missing: string[]}>
     */
    public static function findings(PDO $conn, int $analystId, array $filters = []): array
    {
        if (!self::ready($conn)) return [];
        $expected = [];
        foreach ($conn->query('SELECT asset_type_id, field_key FROM asset_type_expected_fields')->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $expected[(int)$r['asset_type_id']][] = (string)$r['field_key'];
        }
        $expected = array_map([self::class, 'orderKeys'], $expected);
        if (!empty($filters['type_id'])) $expected = array_intersect_key($expected, [(int)$filters['type_id'] => 1]);
        [$tSql, $tArgs] = activeTenantReadFilter($conn, $analystId, 'a');
        $hasRetired = tenancyColumnExists($conn, 'assets', 'retired_datetime');
        $out = [];
        foreach ($expected as $typeId => $keys) {
            if (!empty($filters['field']))   $keys = array_values(array_intersect($keys, [(string)$filters['field']]));
            if (!empty($filters['section'])) $keys = array_values(array_filter($keys, fn($k) => self::sectionOf($k) === $filters['section']));
            if (!$keys) continue;
            $sel = []; $params = [];
            foreach ($keys as $i => $k) {
                [$sql, $p] = self::missingSql($k);
                // Missing, and not marked Not applicable for this asset.
                $sel[] = "(($sql) AND NOT EXISTS (SELECT 1 FROM asset_field_not_applicable n WHERE n.asset_id = a.id AND n.field_key = ?)) AS m$i";
                $params = array_merge($params, $p, [$k]);
            }
            $where = 'a.asset_type_id = ?' . ($hasRetired ? ' AND a.retired_datetime IS NULL' : '') . $tSql;
            $args = array_merge($params, [$typeId], $tArgs);
            if (!empty($filters['asset_id'])) { $where .= ' AND a.id = ?'; $args[] = (int)$filters['asset_id']; }
            $stmt = $conn->prepare('SELECT a.id, a.hostname, a.asset_tag, ' . implode(', ', $sel) . " FROM assets a WHERE $where ORDER BY a.hostname, a.id");
            $stmt->execute($args);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $missing = [];
                foreach ($keys as $i => $k) if ((int)$r["m$i"] === 1) $missing[] = $k;
                if ($missing) $out[] = ['asset_id' => (int)$r['id'], 'name' => $r['hostname'], 'asset_tag' => $r['asset_tag'], 'type_id' => $typeId, 'missing' => $missing];
            }
        }
        return $out;
    }

    /**
     * One asset's picture: what its type expects, what is missing, what is marked Not applicable.
     *
     * @return array{expected: string[], missing: string[], not_applicable: array<int, array{key: string, by: ?string, at: string}>}
     */
    public static function forAsset(PDO $conn, int $analystId, int $assetId): array
    {
        $empty = ['expected' => [], 'missing' => [], 'not_applicable' => []];
        if (!self::ready($conn)) return $empty;
        $t = $conn->prepare('SELECT asset_type_id FROM assets WHERE id = ?');
        $t->execute([$assetId]);
        $typeId = (int)$t->fetchColumn();
        $expected = self::expectedForTypes($conn, [$typeId])[$typeId] ?? [];
        if (!$expected) return $empty;
        $f = self::findings($conn, $analystId, ['type_id' => $typeId, 'asset_id' => $assetId]);
        $na = $conn->prepare("SELECT n.field_key, n.set_datetime, an.full_name FROM asset_field_not_applicable n
                               LEFT JOIN analysts an ON an.id = n.set_by_analyst_id WHERE n.asset_id = ? ORDER BY n.id");
        $na->execute([$assetId]);
        $naRows = array_values(array_filter($na->fetchAll(PDO::FETCH_ASSOC), fn($r) => in_array($r['field_key'], $expected, true)));
        return [
            'expected' => $expected,
            'missing'  => $f[0]['missing'] ?? [],
            'not_applicable' => array_map(fn($r) => ['key' => $r['field_key'], 'by' => $r['full_name'], 'at' => $r['set_datetime']], $naRows),
        ];
    }

    /**
     * Mark (or unmark) one expected field Not applicable for one asset. Writes nothing to
     * the field itself; recorded in the asset's history.
     */
    public static function setNotApplicable(PDO $conn, int $assetId, string $key, bool $on, int $analystId, string $label): void
    {
        if (self::sectionOf($key) === null) throw new InvalidArgumentException('Unknown field.');
        if ($on) {
            $stmt = $conn->prepare('INSERT IGNORE INTO asset_field_not_applicable (asset_id, field_key, set_by_analyst_id, set_datetime) VALUES (?, ?, ?, UTC_TIMESTAMP())');
            $stmt->execute([$assetId, $key, $analystId ?: null]);
        } else {
            $stmt = $conn->prepare('DELETE FROM asset_field_not_applicable WHERE asset_id = ? AND field_key = ?');
            $stmt->execute([$assetId, $key]);
        }
        // History only when something changed: marking a field twice is not two events.
        if ($stmt->rowCount() > 0) {
            $conn->prepare('INSERT INTO asset_history (asset_id, analyst_id, field_name, old_value, new_value, created_datetime) VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP())')
                 ->execute([$assetId, $analystId ?: null, 'Not applicable: ' . $label, $on ? null : 'Yes', $on ? 'Yes' : null]);
        }
    }
}
