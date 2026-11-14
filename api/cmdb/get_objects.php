<?php
/**
 * API: List CMDB objects, optionally filtered by class, text search and
 * property filters. Returns lightweight rows for the browse page table.
 *
 * Query params:
 *   class_id  the class to list (filters and columns need it)
 *   search    matches the name OR any text / drop-down property value of the
 *             class (GH #312 follow-up)
 *   f[]       a property filter, repeatable, combined with AND:
 *             "<property_id>~<op>~<value>"  (the value is everything after the
 *             second ~, so it may itself contain ~)
 *               op   has    text contains                  (text)
 *                    eq     equals / is                    (number, boolean 1|0,
 *                                                           dropdown, object_ref id,
 *                                                           record id)
 *                    gte    at least / on or after         (number, date Y-m-d)
 *                    lte    at most / on or before         (number, date Y-m-d)
 *                    set    has a value                    (any)
 *                    empty  has no value                   (any)
 *             A filter whose property is not the class's, whose operator does
 *             not suit the type, or whose value does not parse is IGNORED and
 *             counted in `ignored_filters` - a bookmarked list whose property
 *             was since deleted still opens. At most 10 are read.
 *   cols      up to 3 property ids, comma-separated - their values come back
 *             per object in `columns` (CI and record names resolved under the
 *             same company rules as the object page)
 *
 * 🔴 No SQL is built from input: property ids are checked against the class,
 * operators and value columns come from the whitelists below, and every value
 * is bound. Company scoping is exactly what it was before the filters.
 *
 * @wiki CMDB
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/rbac.php';
require_once '../../includes/tenancy.php';
require_once '../../includes/cmdb_record_refs.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

requireModuleAccessJson('cmdb');

/** LIKE pattern for "contains $s", with the wildcards in $s taken literally. */
function cmdbListLike(string $s): string
{
    return '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $s) . '%';
}

/** The value column a property type is stored in, or null for an unknown type. */
function cmdbListValueColumn(string $type, bool $refsReady): ?string
{
    switch ($type) {
        case 'text':
        case 'dropdown':   return 'value_text';
        case 'number':     return 'value_number';
        case 'date':       return 'value_date';
        case 'boolean':    return 'value_boolean';
        case 'object_ref': return 'value_object_id';
    }
    if (cmdbIsRecordType($type)) {
        return $refsReady ? 'value_ref_id' : null;
    }
    return null;
}

/**
 * One filter -> [condition on alias $a, params], or null when it is not valid
 * for the property. $col is from cmdbListValueColumn() (a whitelist), never input.
 */
function cmdbListFilterSql(array $prop, string $op, string $value, string $col, string $a): ?array
{
    $type = $prop['property_type'];
    $isText = ($col === 'value_text');
    if ($op === 'set' || $op === 'empty') {
        return ["$a.$col IS NOT NULL" . ($isText ? " AND $a.$col <> ''" : ''), []];
    }
    switch ($type) {
        case 'text':
            if ($op !== 'has' || trim($value) === '' || mb_strlen($value) > 255) return null;
            return ["$a.value_text LIKE ?", [cmdbListLike(trim($value))]];
        case 'dropdown':
            if ($op !== 'eq' || $value === '' || mb_strlen($value) > 255) return null;
            return ["$a.value_text = ?", [$value]];
        case 'number':
            if (!in_array($op, ['eq', 'gte', 'lte'], true) || !is_numeric(trim($value))) return null;
            $sym = ['eq' => '=', 'gte' => '>=', 'lte' => '<='][$op];
            return ["$a.value_number $sym ?", [(string)(float)trim($value)]];
        case 'date':
            if (!in_array($op, ['gte', 'lte'], true) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m)
                || !checkdate((int)$m[2], (int)$m[3], (int)$m[1])) return null;
            // A date property can carry a 00:00:00 tail (or a time): "on or before"
            // takes the whole of that day.
            return $op === 'gte'
                ? ["$a.value_date >= ?", [$value . ' 00:00:00']]
                : ["$a.value_date < DATE_ADD(?, INTERVAL 1 DAY)", [$value . ' 00:00:00']];
        case 'boolean':
            if ($op !== 'eq' || ($value !== '1' && $value !== '0')) return null;
            return ["$a.value_boolean = ?", [(int)$value]];
        default:   // object_ref and the record types: an id
            if ($op !== 'eq' || !ctype_digit($value) || (int)$value <= 0) return null;
            return ["$a.$col = ?", [(int)$value]];
    }
}

try {
    $classId = isset($_GET['class_id']) && $_GET['class_id'] !== '' ? (int)$_GET['class_id'] : null;
    $search  = trim((string)($_GET['search'] ?? ''));

    $conn = connectToDatabase();
    $analystId = (int) $_SESSION['analyst_id'];
    $refsReady = cmdbRecordRefsReady($conn);

    // The class's properties - the only ones a filter or a column may name.
    $props = [];
    if ($classId !== null) {
        $ps = $conn->prepare("SELECT id, label, property_type, target_class_id FROM cmdb_class_properties WHERE class_id = ?");
        $ps->execute([$classId]);
        foreach ($ps->fetchAll(PDO::FETCH_ASSOC) as $p) {
            $props[(int)$p['id']] = $p;
        }
    }

    // Per-company scope. Three separate fragments because they land in three
    // different places and PDO binds positionally — SELECT-clause params bind
    // before JOIN params, which bind before WHERE params. Get that order wrong
    // and the filters silently swap values.
    [$tChild,  $aChild]  = activeTenantFilter($conn, $analystId, 'ch');
    [$tParent, $aParent] = activeTenantFilter($conn, $analystId, 'p');
    [$tSql,    $tArgs]   = activeTenantFilter($conn, $analystId, 'o');

    // The parent filter goes in the ON clause, not the WHERE: a CI whose parent
    // somehow sits in another company must still appear in the list, just with a
    // blank parent — never vanish from its own company's view.
    $sql = "SELECT o.id, o.name, o.class_id, c.name AS class_name,
                   o.parent_id, p.name AS parent_name, pc.name AS parent_class_name,
                   o.is_planned,
                   o.created_datetime, o.updated_datetime,
                   (SELECT COUNT(*) FROM cmdb_objects ch
                     WHERE ch.parent_id = o.id" . $tChild . ") AS child_count
              FROM cmdb_objects o
              JOIN cmdb_classes c ON c.id = o.class_id
         LEFT JOIN cmdb_objects p ON p.id = o.parent_id" . $tParent . "
         LEFT JOIN cmdb_classes pc ON pc.id = p.class_id
             WHERE 1 = 1" . $tSql;
    $params = array_merge($aChild, $aParent, $tArgs);

    if ($classId !== null) {
        $sql .= " AND o.class_id = ?";
        $params[] = $classId;
    }
    if ($search !== '') {
        // The name, or any text / drop-down value of the CI's own class.
        $like = cmdbListLike($search);
        $sql .= " AND (o.name LIKE ? OR EXISTS (
                     SELECT 1 FROM cmdb_object_properties sv
                       JOIN cmdb_class_properties sp ON sp.id = sv.property_id
                      WHERE sv.object_id = o.id AND sp.class_id = o.class_id
                        AND sp.property_type IN ('text', 'dropdown')
                        AND sv.value_text LIKE ?))";
        $params[] = $like;
        $params[] = $like;
    }

    // Property filters.
    $applied = [];
    $ignored = 0;
    $rawFilters = $_GET['f'] ?? [];
    if (!is_array($rawFilters)) $rawFilters = [$rawFilters];
    foreach (array_slice($rawFilters, 0, 10) as $i => $raw) {
        $parts = is_string($raw) ? explode('~', $raw, 3) : [];
        if (count($parts) < 2 || !ctype_digit($parts[0])) { $ignored++; continue; }
        $pid = (int)$parts[0];
        $op = $parts[1];
        $value = (string)($parts[2] ?? '');
        $prop = $props[$pid] ?? null;
        if ($prop === null || !in_array($op, ['has', 'eq', 'gte', 'lte', 'set', 'empty'], true)) { $ignored++; continue; }
        $col = cmdbListValueColumn($prop['property_type'], $refsReady);
        if ($col === null) { $ignored++; continue; }
        $alias = 'fv' . (int)$i;
        $cond = cmdbListFilterSql($prop, $op, $value, $col, $alias);
        if ($cond === null) { $ignored++; continue; }
        $sql .= ' AND ' . ($op === 'empty' ? 'NOT ' : '') . "EXISTS (SELECT 1 FROM cmdb_object_properties $alias
                    WHERE $alias.object_id = o.id AND $alias.property_id = ? AND {$cond[0]})";
        $params[] = $pid;
        array_push($params, ...$cond[1]);
        $applied[] = ['property_id' => $pid, 'op' => $op, 'value' => ($op === 'set' || $op === 'empty') ? '' : $value];
    }
    $sql .= " ORDER BY o.name";

    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as &$r) {
        $r['id'] = (int)$r['id'];
        $r['class_id'] = (int)$r['class_id'];
        $r['parent_id'] = $r['parent_id'] !== null ? (int)$r['parent_id'] : null;
        $r['child_count'] = (int)$r['child_count'];
        $r['is_planned'] = (int)$r['is_planned'] === 1;
    }
    unset($r);

    $reach = null;
    $reachKnown = false;
    $getReach = function () use ($conn, $analystId, &$reach, &$reachKnown) {
        if (!$reachKnown) {
            $reach = analystHasAllTenantAccess($conn, $analystId) ? null : getAccessibleTenantIds($conn, $analystId);
            $reachKnown = true;
        }
        return $reach;
    };
    // CI names for object_ref values - only CIs the analyst's company view holds.
    $ciNames = function (array $ids) use ($conn, $analystId): array {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids) return [];
        [$s, $a] = activeTenantFilter($conn, $analystId, 'o');
        $st = $conn->prepare("SELECT o.id, o.name FROM cmdb_objects o WHERE o.id IN (" . implode(',', array_fill(0, count($ids), '?')) . ")" . $s);
        $st->execute(array_merge($ids, $a));
        return array_map('strval', $st->fetchAll(PDO::FETCH_KEY_PAIR));
    };

    // A readable label for each applied id filter, so a bookmarked list's chips
    // say "Owner: Sarah" rather than "Owner: 42".
    foreach ($applied as &$f) {
        $type = $props[$f['property_id']]['property_type'];
        if ($f['value'] === '' || !($type === 'object_ref' || cmdbIsRecordType($type))) continue;
        $id = (int)$f['value'];
        if ($type === 'object_ref') {
            $n = $ciNames([$id]);
            $f['label'] = $n[$id] ?? null;
            $f['state'] = isset($n[$id]) ? 'ok' : 'hidden';
        } else {
            $info = cmdbRecordResolve($conn, $type, [$id], $getReach())[$id] ?? ['name' => null, 'state' => 'removed'];
            $f['label'] = $info['name'];
            $f['state'] = $info['state'];
        }
    }
    unset($f);

    // Property columns: up to 3 of the class's properties, values per object.
    $cols = [];
    foreach (array_slice(explode(',', (string)($_GET['cols'] ?? '')), 0, 3) as $c) {
        $c = trim($c);
        if (ctype_digit($c) && isset($props[(int)$c]) && cmdbListValueColumn($props[(int)$c]['property_type'], $refsReady) !== null) {
            $cols[(int)$c] = true;
        }
    }
    $cols = array_keys($cols);
    if ($cols && $rows) {
        $byId = [];
        foreach ($rows as $i => $r) { $byId[$r['id']] = $i; $rows[$i]['columns'] = new stdClass(); }
        $cph = implode(',', array_fill(0, count($cols), '?'));
        $vals = [];
        foreach (array_chunk(array_keys($byId), 1000) as $chunk) {
            $st = $conn->prepare(
                "SELECT v.object_id, v.property_id, v.value_text, v.value_number, v.value_date, v.value_boolean,
                        v.value_object_id, " . ($refsReady ? 'v.value_ref_id' : 'NULL AS value_ref_id') . "
                   FROM cmdb_object_properties v
                  WHERE v.property_id IN ($cph) AND v.object_id IN (" . implode(',', array_fill(0, count($chunk), '?')) . ")");
            $st->execute(array_merge($cols, $chunk));
            array_push($vals, ...$st->fetchAll(PDO::FETCH_ASSOC));
        }
        $ciIds = []; $recIds = [];
        foreach ($vals as $v) {
            $type = $props[(int)$v['property_id']]['property_type'];
            if ($type === 'object_ref' && $v['value_object_id'] !== null) $ciIds[] = (int)$v['value_object_id'];
            if (cmdbIsRecordType($type) && $v['value_ref_id'] !== null) $recIds[$type][] = (int)$v['value_ref_id'];
        }
        $ciN = $ciNames($ciIds);
        $recInfo = [];
        foreach ($recIds as $type => $ids) {
            $recInfo[$type] = cmdbRecordResolve($conn, $type, $ids, $getReach());
        }
        foreach ($vals as $v) {
            $pid = (int)$v['property_id'];
            $type = $props[$pid]['property_type'];
            $out = null;
            switch ($type) {
                case 'text':
                case 'dropdown': $out = ($v['value_text'] !== null && $v['value_text'] !== '') ? (string)$v['value_text'] : null; break;
                case 'number':   $out = $v['value_number'] !== null ? (float)$v['value_number'] : null; break;
                case 'date':     $out = $v['value_date'] !== null ? substr((string)$v['value_date'], 0, 10) : null; break;
                case 'boolean':  $out = $v['value_boolean'] !== null ? ((int)$v['value_boolean'] === 1) : null; break;
                case 'object_ref':
                    if ($v['value_object_id'] !== null) {
                        $oid = (int)$v['value_object_id'];
                        $out = ['id' => $oid, 'name' => $ciN[$oid] ?? null, 'state' => isset($ciN[$oid]) ? 'ok' : 'hidden'];
                    }
                    break;
                default:
                    if ($v['value_ref_id'] !== null) {
                        $rid = (int)$v['value_ref_id'];
                        $info = $recInfo[$type][$rid] ?? ['name' => null, 'state' => 'removed'];
                        $out = ['id' => $rid, 'name' => $info['name'], 'state' => $info['state']];
                    }
            }
            if ($out !== null && isset($byId[(int)$v['object_id']])) {
                $rows[$byId[(int)$v['object_id']]]['columns']->{$pid} = $out;
            }
        }
    }

    echo json_encode(['success' => true, 'objects' => $rows, 'filters' => $applied,
                      'ignored_filters' => $ignored, 'cols' => $cols]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
