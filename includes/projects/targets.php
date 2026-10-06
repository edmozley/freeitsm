<?php
/**
 * Projects - asset targets (3.2.0): live progress measured from Assets.
 *
 * A target is "these assets, and when each one counts as done":
 *   scope  - 'linked': the assets on the project's Connections tab, or
 *            'filter': every asset of a type and/or with a field containing a
 *            value - always inside the project's company;
 *   done   - one field, one operator, one value: status is Retired, operating
 *            system contains "Windows 11", feature release at least 24H2...
 *
 * 🔑 COUNTS ARE WORKED OUT ON READ, NEVER STORED - the same rule as task
 * progress. The moment somebody retires a laptop in Assets the project moves.
 * The snapshot table only keeps one point a day for the burn-up line.
 *
 * 🔑 THE SQL IS BUILT FROM WHITELISTS ONLY. Field names and operators come from
 * projectTargetDoneFields() / projectTargetScopeFields(); a value is always a
 * bound parameter. projectTargetNormalise() refuses anything else, and
 * projectTargetSql() asks it again rather than trusting a stored row.
 *
 * Health: a live target that is past its date and not finished is red; one that
 * is more than 25 points behind a straight line from when it was set to its date
 * is amber. The project's automatic health takes the worse of tasks and targets
 * (projectAutoHealth).
 */

require_once __DIR__ . '/../tenancy.php';
require_once __DIR__ . '/../entity_links.php';

/** Has Database Verification created the tables? */
function projectTargetsReady(PDO $conn): bool
{
    static $ready = null;
    if ($ready === null) {
        try { $conn->query("SELECT 1 FROM project_asset_targets LIMIT 0"); $conn->query("SELECT 1 FROM project_asset_target_snapshots LIMIT 0"); $ready = true; }
        catch (Throwable $e) { $ready = false; }
    }
    return $ready;
}

/**
 * The fields a "done" rule can test: column, kind (list = an id from a list,
 * text = free text) and the operators that make sense for it.
 */
function projectTargetDoneFields(): array
{
    return [
        'status'           => ['col' => 'a.asset_status_id', 'kind' => 'list', 'ops' => ['is', 'is_not']],
        'location'         => ['col' => 'a.location_id',     'kind' => 'list', 'ops' => ['is', 'is_not']],
        'operating_system' => ['col' => 'a.operating_system', 'kind' => 'text', 'ops' => ['contains', 'not_contains', 'is']],
        'feature_release'  => ['col' => 'a.feature_release',  'kind' => 'text', 'ops' => ['at_least', 'is']],
        'model'            => ['col' => 'a.model',            'kind' => 'text', 'ops' => ['contains', 'not_contains', 'is']],
        'manufacturer'     => ['col' => 'a.manufacturer',     'kind' => 'text', 'ops' => ['contains', 'not_contains', 'is']],
        'bitlocker_status' => ['col' => 'a.bitlocker_status', 'kind' => 'text', 'ops' => ['is', 'is_not']],
        'tpm_version'      => ['col' => 'a.tpm_version',      'kind' => 'text', 'ops' => ['at_least', 'is']],
    ];
}

/** The fields a filter scope can match with "contains". */
function projectTargetScopeFields(): array
{
    return ['model' => 'a.model', 'manufacturer' => 'a.manufacturer', 'operating_system' => 'a.operating_system', 'hostname' => 'a.hostname'];
}

/**
 * Check and tidy a target from the screen. Returns the row to store; throws
 * ServiceError on anything the SQL builder would not accept.
 */
function projectTargetNormalise(array $in): array
{
    $err = fn(string $m) => new ServiceError('validation', 'invalid_field', $m);
    $name = trim((string)($in['name'] ?? ''));
    if ($name === '') throw $err('Give the target a name.');
    if (mb_strlen($name) > 150) throw $err('The name is too long (150 characters at most).');
    $scope = ($in['scope'] ?? 'filter') === 'linked' ? 'linked' : 'filter';
    $typeId = !empty($in['scope_type_id']) ? (int)$in['scope_type_id'] : null;
    $sField = trim((string)($in['scope_field'] ?? ''));
    $sValue = trim((string)($in['scope_value'] ?? ''));
    if ($scope === 'filter') {
        if ($sValue === '') $sField = '';
        if ($sField !== '' && !isset(projectTargetScopeFields()[$sField])) throw $err('That is not a field a target can match.');
        if ($typeId === null && $sField === '') throw $err('Choose a type or a field to match, so the target is not every asset.');
    } else {
        $typeId = null; $sField = ''; $sValue = '';
    }
    $fields = projectTargetDoneFields();
    $dField = (string)($in['done_field'] ?? '');
    if (!isset($fields[$dField])) throw $err('Choose what makes an asset done.');
    $dOp = (string)($in['done_op'] ?? '');
    if (!in_array($dOp, $fields[$dField]['ops'], true)) throw $err('That comparison does not work for this field.');
    $dValue = trim((string)($in['done_value'] ?? ''));
    if ($fields[$dField]['kind'] === 'list') {
        if (!preg_match('/^\d+$/', $dValue)) throw $err('Choose a value.');
    } elseif ($dValue === '' && !in_array($dOp, ['is', 'is_not'], true)) {
        throw $err('Enter a value.');
    }
    if (mb_strlen($dValue) > 100 || mb_strlen($sValue) > 100) throw $err('A value is too long (100 characters at most).');
    $date = trim((string)($in['target_date'] ?? ''));
    if ($date !== '') {
        $d = DateTime::createFromFormat('!Y-m-d', $date);
        if (!$d || $d->format('Y-m-d') !== $date) throw $err("\"$date\" is not a date.");
    }
    return [
        'name' => $name, 'scope' => $scope, 'scope_type_id' => $typeId,
        'scope_field' => $sField !== '' ? $sField : null, 'scope_value' => $sField !== '' ? $sValue : null,
        'done_field' => $dField, 'done_op' => $dOp, 'done_value' => $dValue,
        'target_date' => $date !== '' ? $date : null,
    ];
}

/**
 * [FROM/WHERE for the assets in scope, args, the "is done" expression, its args]
 * for one target on one project. Built from the whitelists only.
 */
function projectTargetSql(PDO $conn, array $project, array $t): array
{
    $t = projectTargetNormalise($t);   // never trust a stored row to be well formed
    $where = []; $args = [];
    if ($t['scope'] === 'linked') {
        $from = "assets a JOIN project_assets pa ON pa.asset_id = a.id AND pa.project_id = ?";
        $args[] = (int)$project['id'];
    } else {
        $from = "assets a";
        if ($t['scope_type_id']) { $where[] = 'a.asset_type_id = ?'; $args[] = $t['scope_type_id']; }
        if ($t['scope_field']) {
            $where[] = projectTargetScopeFields()[$t['scope_field']] . " LIKE ?";
            $args[] = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $t['scope_value']) . '%';
        }
    }
    if (isMultiTenant($conn)) {
        $def = (int)getDefaultTenantId($conn);
        $where[] = "COALESCE(a.tenant_id, $def) = ?";
        $args[] = $project['tenant_id'] === null ? $def : (int)$project['tenant_id'];
    }
    $col = projectTargetDoneFields()[$t['done_field']]['col'];
    $v = $t['done_value'];
    switch ($t['done_op']) {
        case 'is':           $done = $v === '' ? "COALESCE($col, '') = ''" : "$col = ?"; $dArgs = $v === '' ? [] : [$v]; break;
        case 'is_not':       $done = $v === '' ? "COALESCE($col, '') <> ''" : "($col IS NULL OR $col <> ?)"; $dArgs = $v === '' ? [] : [$v]; break;
        case 'contains':     $done = "$col LIKE ?"; $dArgs = ['%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $v) . '%']; break;
        case 'not_contains': $done = "($col IS NULL OR $col NOT LIKE ?)"; $dArgs = ['%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $v) . '%']; break;
        default:             $done = "($col IS NOT NULL AND $col <> '' AND $col >= ?)"; $dArgs = [$v]; break;   // at_least: 23H2 < 24H2, 1.2 < 2.0
    }
    return [$from . ($where ? ' WHERE ' . implode(' AND ', $where) : ''), $args, $done, $dArgs];
}

/** [done, total] for one target. */
function projectTargetCount(PDO $conn, array $project, array $t): array
{
    [$from, $args, $done, $dArgs] = projectTargetSql($conn, $project, $t);
    $st = $conn->prepare("SELECT COUNT(*), COALESCE(SUM(CASE WHEN $done THEN 1 ELSE 0 END), 0) FROM $from");
    $st->execute(array_merge($dArgs, $args));
    [$total, $d] = $st->fetch(PDO::FETCH_NUM) ?: [0, 0];
    return [(int)$d, (int)$total];
}

/** green | amber | red for a live target; null when there is nothing to measure. */
function projectTargetHealth(int $done, int $total, ?string $due, string $setOn): ?string
{
    if ($total === 0) return null;
    if ($done >= $total) return 'green';
    $today = gmdate('Y-m-d');
    if (!$due) return 'green';
    if ($due < $today) return 'red';
    $start = strtotime(substr($setOn, 0, 10)); $end = strtotime($due); $now = strtotime($today);
    if ($end <= $start) return 'green';
    $expected = max(0, min(100, ($now - $start) * 100 / ($end - $start)));
    return ($done * 100 / $total) < $expected - 25 ? 'amber' : 'green';
}

/**
 * Every target on these projects with its counts and health:
 * [project_id => [target, ...]]. Empty before Verification.
 */
function projectTargetsFor(PDO $conn, array $projectIds): array
{
    $out = [];
    if (!$projectIds || !projectTargetsReady($conn)) return $out;
    $ph = implode(',', array_fill(0, count($projectIds), '?'));
    $st = $conn->prepare("SELECT t.*, p.tenant_id AS p_tenant_id, p.target_end_date AS p_target_end_date, p.status AS p_status,
                                 ty.name AS scope_type_name
                            FROM project_asset_targets t JOIN projects p ON p.id = t.project_id
                       LEFT JOIN asset_types ty ON ty.id = t.scope_type_id
                           WHERE t.project_id IN ($ph) ORDER BY t.position, t.id");
    $st->execute(array_map('intval', $projectIds));
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $t) {
        $project = ['id' => (int)$t['project_id'], 'tenant_id' => $t['p_tenant_id']];
        try { [$done, $total] = projectTargetCount($conn, $project, $t); } catch (Throwable $e) { $done = $total = 0; }
        $due = $t['target_date'] ?: $t['p_target_end_date'];
        $live = !in_array($t['p_status'], ['closed', 'cancelled'], true);
        $out[(int)$t['project_id']][] = [
            'id' => (int)$t['id'], 'name' => $t['name'], 'scope' => $t['scope'],
            'scope_type_id' => $t['scope_type_id'] !== null ? (int)$t['scope_type_id'] : null, 'scope_type_name' => $t['scope_type_name'],
            'scope_field' => $t['scope_field'], 'scope_value' => $t['scope_value'],
            'done_field' => $t['done_field'], 'done_op' => $t['done_op'], 'done_value' => $t['done_value'],
            'target_date' => $t['target_date'], 'due' => $due,
            'done' => $done, 'total' => $total, 'pct' => $total ? (int)floor($done * 100 / $total) : 0,
            'health' => $live ? projectTargetHealth($done, $total, $due, $t['created_datetime']) : null,
            'created_datetime' => $t['created_datetime'],
        ];
    }
    return $out;
}

/** The worst health across a project's targets, or null. */
function projectTargetsWorst(array $targets): ?string
{
    $rank = ['green' => 1, 'amber' => 2, 'red' => 3]; $worst = null;
    foreach ($targets as $t) if ($t['health'] && ($worst === null || $rank[$t['health']] > $rank[$worst])) $worst = $t['health'];
    return $worst;
}

/**
 * For the project page: the targets, with today's point written and the last
 * 120 days of points for the burn-up line, and the words for the rule.
 */
function projectTargetsDetail(PDO $conn, int $projectId): array
{
    $targets = projectTargetsFor($conn, [$projectId])[$projectId] ?? [];
    if (!$targets) return [];
    $today = gmdate('Y-m-d');
    $up = $conn->prepare("INSERT INTO project_asset_target_snapshots (target_id, snap_date, done, total) VALUES (?, ?, ?, ?)
                          ON DUPLICATE KEY UPDATE done = VALUES(done), total = VALUES(total)");
    $pts = $conn->prepare("SELECT snap_date, done, total FROM project_asset_target_snapshots WHERE target_id = ? AND snap_date >= ? ORDER BY snap_date");
    $since = gmdate('Y-m-d', strtotime('-120 days'));
    $names = projectTargetValueNames($conn, $targets);
    foreach ($targets as &$t) {
        try { $up->execute([$t['id'], $today, $t['done'], $t['total']]); } catch (Throwable $e) { /* a reader on a read-only replica still sees the counts */ }
        $pts->execute([$t['id'], $since]);
        $t['points'] = array_map(fn($r) => ['d' => $r['snap_date'], 'done' => (int)$r['done'], 'total' => (int)$r['total']], $pts->fetchAll(PDO::FETCH_ASSOC));
        $t['done_value_name'] = $names[$t['done_field'] . ':' . $t['done_value']] ?? $t['done_value'];
    }
    unset($t);
    return $targets;
}

/** Names for list values in rules (status 6 => Retired), keyed "field:id". */
function projectTargetValueNames(PDO $conn, array $targets): array
{
    $out = [];
    $tables = ['status' => 'asset_status_types', 'location' => 'asset_locations'];
    foreach ($tables as $field => $table) {
        $ids = array_values(array_unique(array_map(fn($t) => (int)$t['done_value'], array_filter($targets, fn($t) => $t['done_field'] === $field))));
        if (!$ids) continue;
        $st = $conn->prepare("SELECT id, name FROM $table WHERE id IN (" . implode(',', array_fill(0, count($ids), '?')) . ")");
        $st->execute($ids);
        foreach ($st->fetchAll(PDO::FETCH_KEY_PAIR) as $id => $n) $out["$field:$id"] = $n;
    }
    return $out;
}

/**
 * The assets still to do on a target (or all of them, $done = true), at most
 * $limit - for the "what's left" list. Callers check Assets access first.
 */
function projectTargetAssets(PDO $conn, array $project, array $t, bool $doneOnes = false, int $limit = 200): array
{
    [$from, $args, $done, $dArgs] = projectTargetSql($conn, $project, $t);
    // TRAP: an asset with no status makes "status = 6" NULL, not false, and
    // NOT(NULL) is NULL too - the asset would vanish from "still to do". The
    // count's CASE WHEN already treats NULL as not done; this must agree.
    $cond = $doneOnes ? $done : "NOT COALESCE(($done), 0)";
    // The joins for names go on the outside so the scope SQL stays one piece.
    $st = $conn->prepare("SELECT q.id, q.hostname, q.asset_tag, q.model, q.operating_system, q.feature_release, s.name AS status_name, l.name AS location_name
                            FROM (SELECT a.* FROM $from" . (strpos($from, ' WHERE ') !== false ? ' AND ' : ' WHERE ') . "$cond ORDER BY a.hostname LIMIT " . (int)$limit . ") q
                       LEFT JOIN asset_status_types s ON s.id = q.asset_status_id
                       LEFT JOIN asset_locations l ON l.id = q.location_id
                        ORDER BY q.hostname");
    $st->execute(array_merge($args, $dArgs));
    return array_map(fn($r) => $r + ['url' => entityLink('asset', (int)$r['id'])], $st->fetchAll(PDO::FETCH_ASSOC));
}

/** The lists the target dialog offers, for the project's company. */
function projectTargetOptions(PDO $conn, array $project): array
{
    $tid = $project['tenant_id'] === null ? null : (int)$project['tenant_id'];
    $def = (int)getDefaultTenantId($conn);
    $own = $tid ?? $def;
    $q = function (string $sql, array $a) use ($conn) { $st = $conn->prepare($sql); $st->execute($a); return $st->fetchAll(PDO::FETCH_ASSOC); };
    return [
        'types'     => $q("SELECT id, name FROM asset_types WHERE is_active = 1 AND (tenant_id IS NULL OR tenant_id = ?) ORDER BY display_order, name", [$own]),
        'statuses'  => $q("SELECT id, name FROM asset_status_types WHERE is_active = 1 AND (tenant_id IS NULL OR tenant_id = ?) ORDER BY display_order, name", [$own]),
        'locations' => $q("SELECT id, name FROM asset_locations WHERE COALESCE(tenant_id, $def) = ? ORDER BY display_order, name", [$own]),
        'fields'    => array_map(fn($f) => ['kind' => $f['kind'], 'ops' => $f['ops']], projectTargetDoneFields()),
        'scope_fields' => array_keys(projectTargetScopeFields()),
    ];
}
