<?php
/**
 * Report Packs: Projects blocks (3.2.0) - so the monthly board pack includes
 * the projects without anybody copying figures out of the portfolio.
 *
 * Health and progress are worked out exactly as the Projects screens work them
 * out: the same projectTaskStats() + projectDecorate() (includes/projects/read.php),
 * so a pack can never show a project green that the portfolio shows red.
 *
 * WHAT THE DATE RANGE MEANS
 *   status, risks, the summary's health tiles, the health chart
 *       a snapshot of NOW - health has no history to look back on - and the
 *       blocks say so (`snapshot`);
 *   milestones, the summary's "stages ending" tile
 *       milestones (3.3.0), stage ends and target finishes INSIDE the range, so
 *       last month's pack lists last month's milestones and whether they were met.
 *
 * Company: the pack's company, through projects.tenant_id (NULL = Default), the
 * same rpTenantClause() every other area uses. A `projects` option narrows to
 * chosen projects; empty means all of them.
 */

require_once __DIR__ . '/../projects/read.php';

const RP_HEALTH_COLOURS = ['green' => '#16a34a', 'amber' => '#f59e0b', 'red' => '#dc2626'];

/** Projects in the pack's company, decorated with health and progress. */
function rpProjectRows(PDO $conn, int $analystId, $tenant, array $ids, bool $liveOnly): array
{
    [$tSql, $tArgs] = rpProjectClause($conn, $analystId, $tenant);
    $where = $liveOnly ? "p.status IN ('proposed', 'active')" : "p.status <> 'cancelled'";
    $args = [];
    if ($ids) {
        $where .= ' AND p.id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
        $args = array_map('intval', $ids);
    }
    $st = $conn->prepare(
        "SELECT p.id, p.tenant_id, p.name, p.goal, p.methodology, p.status, p.health, p.health_note, p.owner_analyst_id,
                a.full_name AS owner_name, p.start_date, p.target_end_date, p.actual_end_date, p.tailoring,
                (SELECT s.name FROM project_stages s WHERE s.project_id = p.id AND s.status = 'active' ORDER BY s.position, s.id LIMIT 1) AS active_stage_name,
                " . projectExceptionColumns($conn) . "
           FROM projects p
      LEFT JOIN analysts a ON a.id = p.owner_analyst_id
          WHERE $where $tSql
       ORDER BY FIELD(p.status, 'active', 'proposed', 'on_hold', 'closed'), p.target_end_date IS NULL, p.target_end_date, p.name");
    $st->execute(array_merge($args, $tArgs));
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    $stats = projectTaskStats($conn, array_column($rows, 'id'));
    $cfg = projectHealthConfig($conn);
    foreach ($rows as &$r) $r = projectDecorate($r, $stats[(int)$r['id']] ?? [], $cfg);
    unset($r);
    return $rows;
}

/**
 * The company clause plus members-only (3.3.0): a pack carries a members-only
 * project only when the person it is built for may see it - never for nobody.
 */
function rpProjectClause(PDO $conn, int $analystId, $tenant): array
{
    require_once __DIR__ . '/../projects/visibility.php';
    [$tSql, $tArgs] = rpTenantClause($conn, $analystId, $tenant, 'p.tenant_id');
    [$vSql, $vArgs] = projectVisibleSql($conn, $analystId, 'p', false);
    return [$tSql . $vSql, array_merge($tArgs, $vArgs)];
}

/** A health pill: the words the Projects module uses, in its colours. */
function rpProjectHealthPill(?string $h): array
{
    if ($h === null || !isset(RP_HEALTH_COLOURS[$h])) return ['pill' => t('projects.health.none'), 'colour' => '#64748b'];
    return ['pill' => t('projects.health.' . $h), 'colour' => RP_HEALTH_COLOURS[$h]];
}

function rpProjectsKpis(PDO $conn, int $analystId, array $o, array $range, $tenant): array
{
    $rows = rpProjectRows($conn, $analystId, $tenant, [], true);
    $count = ['green' => 0, 'amber' => 0, 'red' => 0];
    foreach ($rows as $r) if (isset($count[$r['shown_health']])) $count[$r['shown_health']]++;

    [$tSql, $tArgs] = rpProjectClause($conn, $analystId, $tenant);
    $st = $conn->prepare("SELECT COUNT(*) FROM project_stages s JOIN projects p ON p.id = s.project_id
                           WHERE s.end_date BETWEEN ? AND ? AND p.status <> 'cancelled' $tSql");
    $st->execute(array_merge([$range['from_date'], $range['to_date']], $tArgs));
    $now = t('reporting.packs.kpi.now');
    return ['kind' => 'kpi', 'tiles' => [
        ['label' => t('reporting.packs.kpi.projects_live'), 'value' => number_format(count($rows)), 'hint' => $now],
        ['label' => t('projects.health.green'), 'value' => number_format($count['green']), 'hint' => $now],
        ['label' => t('projects.health.amber'), 'value' => number_format($count['amber']), 'hint' => $now],
        ['label' => t('projects.health.red'),   'value' => number_format($count['red']),   'hint' => $now],
        ['label' => t('reporting.packs.kpi.stages_ending'), 'value' => number_format((int)$st->fetchColumn()), 'hint' => t('reporting.packs.kpi.in_period')],
    ]];
}

function rpProjectsHealth(PDO $conn, int $analystId, array $o, array $range, $tenant): array
{
    $count = ['green' => 0, 'amber' => 0, 'red' => 0];
    foreach (rpProjectRows($conn, $analystId, $tenant, $o['projects'], true) as $r) {
        if (isset($count[$r['shown_health']])) $count[$r['shown_health']]++;
    }
    return [
        'kind' => 'chart', 'chart' => $o['chart'], 'snapshot' => true,
        'labels'  => [t('projects.health.green'), t('projects.health.amber'), t('projects.health.red')],
        'series'  => [['name' => t('reporting.packs.series.projects'), 'values' => array_values($count)]],
        'colours' => array_values(RP_HEALTH_COLOURS),
        'total'   => array_sum($count),
    ];
}

function rpProjectsStatus(PDO $conn, int $analystId, array $o, array $range, $tenant): array
{
    $out = [];
    foreach (rpProjectRows($conn, $analystId, $tenant, $o['projects'], $o['scope'] === 'live') as $p) {
        $row = [
            'project'  => $p['name'] . ' (' . $p['code'] . ')',
            'health'   => rpProjectHealthPill($p['shown_health']),
            'progress' => $p['task_total'] > 0 ? $p['progress'] . '% (' . $p['task_done'] . '/' . $p['task_total'] . ')' : '-',
            'stage'    => $p['active_stage_name'] ?: t('projects.status.' . $p['status']),
            'target'   => $p['target_end_date'] ? rpFmtDate($p['target_end_date']) : '-',
            'manager'  => $p['owner_name'] ?: '-',
        ];
        // The line under the name: why it is the colour it is, if somebody said.
        if ($o['notes']) {
            $note = trim((string)($p['health_note'] ?? ''));
            if ($note === '' && $p['exceptions']) $note = t('reporting.packs.projects.exception');
            // The engine draws a detail line from the second column on, so it says
            // "Why:" rather than sitting under Health looking like a label for it.
            if ($note !== '') $row['_detail'] = t('reporting.packs.projects.why', ['note' => $note]);
        }
        $out[] = $row;
    }
    $cols = [
        ['key' => 'project',  'label' => t('reporting.packs.col.project'),  'w' => 30],
        ['key' => 'health',   'label' => t('reporting.packs.col.health'),   'w' => 12],
        ['key' => 'progress', 'label' => t('reporting.packs.col.progress'), 'w' => 13, 'align' => 'right'],
        ['key' => 'stage',    'label' => t('reporting.packs.col.stage'),    'w' => 17],
        ['key' => 'target',   'label' => t('reporting.packs.col.target'),   'w' => 12],
    ];
    if ($o['manager']) $cols[] = ['key' => 'manager', 'label' => t('reporting.packs.col.manager'), 'w' => 16];
    return ['kind' => 'table', 'snapshot' => true, 'rows' => $out, 'columns' => $cols, 'empty' => t('reporting.packs.empty.projects')];
}

/** Stage ends and target finishes inside the range, and whether each was met. */
function rpProjectsMilestones(PDO $conn, int $analystId, array $o, array $range, $tenant): array
{
    [$tSql, $tArgs] = rpProjectClause($conn, $analystId, $tenant);
    $only = ''; $ids = [];
    if ($o['projects']) { $ids = array_map('intval', $o['projects']); $only = ' AND p.id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')'; }
    require_once __DIR__ . '/../projects/milestones.php';
    $ms = projectMilestonesReady($conn);
    $st = $conn->prepare(
        "SELECT * FROM (
            SELECT s.end_date AS on_date, p.id, p.name, s.name AS what, s.kind, s.status, 'stage' AS type
              FROM project_stages s JOIN projects p ON p.id = s.project_id
             WHERE s.end_date BETWEEN ? AND ? AND p.status <> 'cancelled' $only $tSql
            UNION ALL
            SELECT p.target_end_date, p.id, p.name, NULL, NULL, p.status, 'project'
              FROM projects p
             WHERE p.target_end_date BETWEEN ? AND ? AND p.status <> 'cancelled' $only $tSql
            " . ($ms ? "UNION ALL
            SELECT ms.due_date, p.id, p.name, ms.name, NULL, IF(ms.done_date IS NULL, 'open', 'closed'), 'milestone'
              FROM project_milestones ms JOIN projects p ON p.id = ms.project_id
             WHERE ms.due_date BETWEEN ? AND ? AND p.status <> 'cancelled' $only $tSql" : '') . "
         ) m ORDER BY on_date, name LIMIT 500");
    $args = array_merge([$range['from_date'], $range['to_date']], $ids, $tArgs, [$range['from_date'], $range['to_date']], $ids, $tArgs);
    if ($ms) $args = array_merge($args, [$range['from_date'], $range['to_date']], $ids, $tArgs);
    $st->execute($args);
    $today = gmdate('Y-m-d');
    $rows = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $m) {
        $done = $m['status'] === 'closed';   // the stage, or the project, finished
        $state = $done ? ['done', '#16a34a'] : ($m['on_date'] < $today ? ['missed', '#dc2626'] : ['due', '#64748b']);
        $rows[] = [
            'date'      => rpFmtDate($m['on_date']),
            'project'   => $m['name'] . ' (' . projectCode((int)$m['id']) . ')',
            'milestone' => $m['type'] === 'stage'
                ? t('reporting.packs.projects.stage_ends', ['stage' => $m['what']])
                : ($m['type'] === 'milestone' ? $m['what'] : t('reporting.packs.projects.target_end')),
            'state'     => ['pill' => t('reporting.packs.projects.state.' . $state[0]), 'colour' => $state[1]],
        ];
    }
    return ['kind' => 'table', 'rows' => $rows, 'empty' => t('reporting.packs.empty.milestones'), 'columns' => [
        ['key' => 'date',      'label' => t('reporting.packs.col.date'),      'w' => 14],
        ['key' => 'project',   'label' => t('reporting.packs.col.project'),   'w' => 34],
        ['key' => 'milestone', 'label' => t('reporting.packs.col.milestone'), 'w' => 36],
        ['key' => 'state',     'label' => t('reporting.packs.col.status'),    'w' => 16],
    ]];
}

/** The open risks that matter most, across the chosen projects. */
function rpProjectsRisks(PDO $conn, int $analystId, array $o, array $range, $tenant): array
{
    [$tSql, $tArgs] = rpProjectClause($conn, $analystId, $tenant);
    $only = ''; $ids = [];
    if ($o['projects']) { $ids = array_map('intval', $o['projects']); $only = ' AND p.id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')'; }
    $st = $conn->prepare(
        "SELECT r.title, r.probability, r.impact, r.probability * r.impact AS score, r.response_plan,
                p.id, p.name, a.full_name AS owner_name
           FROM project_raid r
           JOIN projects p ON p.id = r.project_id
      LEFT JOIN analysts a ON a.id = r.owner_analyst_id
          WHERE r.type = 'risk' AND r.status = 'open' AND p.status IN ('proposed', 'active', 'on_hold') $only $tSql
       ORDER BY score IS NULL, score DESC, r.id
          LIMIT " . (int)$o['limit']);
    $st->execute(array_merge($ids, $tArgs));
    $prob = projectScaleLabels($conn, 'probability');
    $imp = projectScaleLabels($conn, 'impact');
    $rows = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $score = $r['score'] !== null ? (int)$r['score'] : null;
        $row = [
            'project' => $r['name'] . ' (' . projectCode((int)$r['id']) . ')',
            'risk'    => $r['title'],
            // The same bands as the heat map: 15+ red, 8+ amber.
            'score'   => $score === null ? ['pill' => '-', 'colour' => '#64748b']
                       : ['pill' => (string)$score, 'colour' => $score >= 15 ? RP_HEALTH_COLOURS['red'] : ($score >= 8 ? RP_HEALTH_COLOURS['amber'] : RP_HEALTH_COLOURS['green'])],
            'owner'   => $r['owner_name'] ?: '-',
        ];
        $detail = [];
        if ($score !== null) $detail[] = ($prob[(int)$r['probability'] - 1] ?? '') . ' / ' . ($imp[(int)$r['impact'] - 1] ?? '');
        if ($o['plans'] && trim((string)$r['response_plan']) !== '') $detail[] = trim((string)$r['response_plan']);
        if ($detail) $row['_detail'] = implode(' - ', $detail);
        $rows[] = $row;
    }
    return ['kind' => 'table', 'snapshot' => true, 'rows' => $rows, 'empty' => t('reporting.packs.empty.risks'), 'columns' => [
        ['key' => 'project', 'label' => t('reporting.packs.col.project'), 'w' => 26],
        ['key' => 'risk',    'label' => t('reporting.packs.col.risk'),    'w' => 42],
        ['key' => 'score',   'label' => t('reporting.packs.col.score'),   'w' => 10],
        ['key' => 'owner',   'label' => t('reporting.packs.col.owner'),   'w' => 22],
    ]];
}

/** The projects offered by a block's "Projects" option: those the viewer can see, live first. */
function rpProjectChoices(PDO $conn, int $analystId): array
{
    [$tSql, $tArgs] = isMultiTenant($conn) ? allAccessibleTenantsFilter($conn, $analystId, 'p.tenant_id') : ['', []];
    require_once __DIR__ . '/../projects/visibility.php';   // members-only (3.3.0)
    [$vSql, $vArgs] = projectVisibleSql($conn, $analystId, 'p', false);
    $tSql .= $vSql; $tArgs = array_merge($tArgs, $vArgs);
    $st = $conn->prepare("SELECT p.id, p.name FROM projects p WHERE p.status <> 'cancelled' $tSql
                          ORDER BY FIELD(p.status, 'active', 'proposed', 'on_hold', 'closed'), p.name LIMIT 300");
    $st->execute($tArgs);
    return array_map(fn($r) => ['id' => (int)$r['id'], 'name' => $r['name'] . ' (' . projectCode((int)$r['id']) . ')'], $st->fetchAll(PDO::FETCH_ASSOC));
}
