<?php
/**
 * Report Packs: Starters & leavers blocks (GH #324) - so the monthly pack shows
 * who joined, moved and left, whether they were ready on the day, and what is late.
 *
 * WHICH CASES ARE "IN THE PERIOD"
 *   A case is in the period when its DATE is - the first day, move date or last
 *   day (jml_cases.effective_date) - not when it was opened: "starters this
 *   month" means people starting this month.
 *
 * PROGRESS comes from the case's steps, which are the subtasks of its task
 * (jml_cases.parent_task_id), exactly as the Starters register counts them: a
 * step is done when its task status is a closed one. Nothing is stored twice.
 *
 * READY ON THE DAY: a case dated in the period, on or before today, whose every
 * step was closed by the end of its date. A case with no steps is not counted
 * either way. Cancelled cases are left out of everything except the status chart.
 *
 * Module 'starters'; company through rpTenantClause() on jml_cases.tenant_id
 * (NULL = the Default company), like every other block.
 */

require_once __DIR__ . '/../starters/service.php';

/** dimension => label SQL (the chart's "by" option). Kind and status are translated afterwards. */
const RP_STARTERS_DIMENSIONS = [
    'kind'     => 'c.kind',
    'status'   => 'c.status',
    'template' => "COALESCE(tp.name, '')",
];

const RP_STARTERS_KIND_COLOURS   = ['starter' => '#16a34a', 'mover' => '#2563eb', 'leaver' => '#f59e0b'];
const RP_STARTERS_STATUS_COLOURS = ['open' => '#2563eb', 'done' => '#16a34a', 'cancelled' => '#94a3b8'];

/** Steps done and total for a case, as SQL columns on alias c. */
function rpStartersProgressCols(): string
{
    return "(SELECT COUNT(*) FROM tasks s WHERE s.parent_task_id = c.parent_task_id) AS steps_total,
            (SELECT COUNT(*) FROM tasks s JOIN task_statuses ss ON ss.id = s.status_id
              WHERE s.parent_task_id = c.parent_task_id AND ss.is_closed = 1) AS steps_done";
}

/**
 * The WHERE clause for the period's cases (dated in the range), with the company
 * and an optional kind.
 *
 * @param string $kind  '' or 'all' for every kind, else starter | mover | leaver
 * @param bool $withCancelled  true to keep cancelled cases (the status chart only)
 * @return array{0:string, 1:array}  ' WHERE ...' and its parameters
 */
function rpStartersWhere(PDO $conn, int $analystId, array $range, $tenant, string $kind = '', bool $withCancelled = false): array
{
    [$tSql, $tArgs] = rpTenantClause($conn, $analystId, $tenant, 'c.tenant_id');
    $sql = ' WHERE c.effective_date BETWEEN ? AND ?';
    $args = [$range['from_date'], $range['to_date']];
    if (!$withCancelled) $sql .= " AND c.status <> 'cancelled'";
    if ($kind !== '' && $kind !== 'all' && JmlKind::tryFrom($kind)) { $sql .= ' AND c.kind = ?'; $args[] = $kind; }
    return [$sql . $tSql, array_merge($args, $tArgs)];
}

/** The kind choices for a block's option: all, starters, movers, leavers. */
function rpStartersKindChoices(): array
{
    return [
        'all'     => t('starters.kinds.all'),
        'starter' => t('starters.kinds.starter'),
        'mover'   => t('starters.kinds.mover'),
        'leaver'  => t('starters.kinds.leaver'),
    ];
}

/**
 * Summary: starters, movers and leavers dated in the period; cases ready on the
 * day; and, now, the open cases and the steps that are overdue.
 *
 * @param array $o  the block's cleaned options (none)
 * @param array $range  the pack's range from rpResolveRange() - from_date and to_date are used
 * @param mixed $tenant  the pack's company: 'active', 'all' or a company id
 */
function rpStartersKpis(PDO $conn, int $analystId, array $o, array $range, $tenant): array
{
    [$where, $args] = rpStartersWhere($conn, $analystId, $range, $tenant);
    $st = $conn->prepare("SELECT c.kind, COUNT(*) AS n FROM jml_cases c $where GROUP BY c.kind");
    $st->execute($args);
    $byKind = ['starter' => 0, 'mover' => 0, 'leaver' => 0];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) if (isset($byKind[$r['kind']])) $byKind[$r['kind']] = (int)$r['n'];

    $ready = rpStartersReadyCounts($conn, $analystId, $range, $tenant);

    [$tSql, $tArgs] = rpTenantClause($conn, $analystId, $tenant, 'c.tenant_id');
    $st = $conn->prepare("SELECT COUNT(*) FROM jml_cases c WHERE c.status = 'open' $tSql");
    $st->execute($tArgs);
    $open = (int)$st->fetchColumn();
    $st = $conn->prepare(
        "SELECT COUNT(*) FROM jml_cases c
           JOIN tasks s ON s.parent_task_id = c.parent_task_id
      LEFT JOIN task_statuses ss ON ss.id = s.status_id
          WHERE c.status = 'open' AND COALESCE(ss.is_closed, 0) = 0
            AND s.due_date IS NOT NULL AND s.due_date < ? $tSql");
    $st->execute(array_merge([gmdate('Y-m-d')], $tArgs));
    $overdue = (int)$st->fetchColumn();

    $inPeriod = t('reporting.packs.kpi.in_period');
    $now = t('reporting.packs.kpi.now');
    return ['kind' => 'kpi', 'tiles' => [
        ['label' => t('starters.kinds.starter'), 'value' => number_format($byKind['starter']), 'hint' => $inPeriod],
        ['label' => t('starters.kinds.mover'),   'value' => number_format($byKind['mover']),   'hint' => $inPeriod],
        ['label' => t('starters.kinds.leaver'),  'value' => number_format($byKind['leaver']),  'hint' => $inPeriod],
        ['label' => t('reporting.packs.starters.ready'),
         'value' => $ready['total'] > 0 ? round(100 * $ready['ready'] / $ready['total']) . '%' : '-',
         'hint'  => t('reporting.packs.starters.ready_hint', ['n' => $ready['ready'], 'total' => $ready['total']])],
        ['label' => t('reporting.packs.starters.open'),    'value' => number_format($open),    'hint' => $now],
        ['label' => t('reporting.packs.starters.overdue'), 'value' => number_format($overdue), 'hint' => $now],
    ]];
}

/**
 * Of the period's cases dated on or before today that have steps: how many had
 * every step closed by the end of their date.
 *
 * @return array{ready:int, total:int}
 */
function rpStartersReadyCounts(PDO $conn, int $analystId, array $range, $tenant): array
{
    [$where, $args] = rpStartersWhere($conn, $analystId, $range, $tenant);
    // A step counts as done in time when its status is closed and it was completed
    // before the day after the case's date. completed_datetime is UTC; the date is
    // the organisation's calendar day - close enough for a monthly figure.
    $st = $conn->prepare(
        "SELECT COUNT(*) AS total,
                SUM(CASE WHEN NOT EXISTS (
                        SELECT 1 FROM tasks s LEFT JOIN task_statuses ss ON ss.id = s.status_id
                         WHERE s.parent_task_id = c.parent_task_id
                           AND (COALESCE(ss.is_closed, 0) = 0 OR s.completed_datetime IS NULL
                                OR s.completed_datetime >= DATE_ADD(c.effective_date, INTERVAL 1 DAY))
                    ) THEN 1 ELSE 0 END) AS ready
           FROM jml_cases c
         $where AND c.effective_date <= ?
            AND EXISTS (SELECT 1 FROM tasks s2 WHERE s2.parent_task_id = c.parent_task_id)");
    $st->execute(array_merge($args, [gmdate('Y-m-d')]));
    $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    return ['ready' => (int)($r['ready'] ?? 0), 'total' => (int)($r['total'] ?? 0)];
}

/**
 * Chart: the period's cases by kind, status or template.
 *
 * @param array $o  the block's cleaned options: by (an RP_STARTERS_DIMENSIONS key), chart, limit
 */
function rpStartersBreakdown(PDO $conn, int $analystId, array $o, array $range, $tenant): array
{
    $label = RP_STARTERS_DIMENSIONS[$o['by']];
    // The status chart is the one place cancelled cases belong.
    [$where, $args] = rpStartersWhere($conn, $analystId, $range, $tenant, '', $o['by'] === 'status');
    $st = $conn->prepare("SELECT $label AS label, COUNT(*) AS value
                            FROM jml_cases c LEFT JOIN jml_templates tp ON tp.id = c.template_id
                          $where GROUP BY label ORDER BY value DESC");
    $st->execute($args);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) {
        $key = (string)$r['label'];
        if ($o['by'] === 'kind') {
            $r['colour'] = RP_STARTERS_KIND_COLOURS[$key] ?? null;
            $r['label'] = t('starters.kinds.' . $key);
        } elseif ($o['by'] === 'status') {
            $r['colour'] = RP_STARTERS_STATUS_COLOURS[$key] ?? null;
            $r['label'] = t('starters.status.' . $key);
        } elseif ($key === '') {
            $r['label'] = t('reporting.packs.starters.no_template');
        }
    }
    unset($r);
    return rpChartFromRows($rows, $o['limit'], $o['chart'], t('reporting.packs.starters.series'));
}

/**
 * Table: the period's cases - person, kind, date, progress, status and, optionally, owner.
 *
 * @param array $o  the block's cleaned options: kind, status ('all' or 'open'), owner, limit
 */
function rpStartersList(PDO $conn, int $analystId, array $o, array $range, $tenant): array
{
    [$where, $args] = rpStartersWhere($conn, $analystId, $range, $tenant, $o['kind']);
    if ($o['status'] === 'open') $where .= " AND c.status = 'open'";
    $st = $conn->prepare(
        "SELECT c.id, c.kind, c.person_name, c.job_title, c.effective_date, c.status,
                tp.name AS template_name, own.full_name AS owner_name, " . rpStartersProgressCols() . "
           FROM jml_cases c
      LEFT JOIN jml_templates tp ON tp.id = c.template_id
      LEFT JOIN analysts own ON own.id = c.owner_analyst_id
         $where
       ORDER BY c.effective_date ASC, c.id ASC
          LIMIT " . ((int)$o['limit'] + 1));
    $st->execute($args);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    $more = count($rows) > $o['limit'];
    if ($more) array_pop($rows);

    $out = [];
    foreach ($rows as $r) {
        $total = (int)$r['steps_total']; $done = (int)$r['steps_done'];
        $row = [
            'person'   => $r['person_name'],
            'kind'     => ['pill' => t('starters.kind.' . $r['kind']), 'colour' => RP_STARTERS_KIND_COLOURS[$r['kind']] ?? '#64748b'],
            'date'     => rpFmtDate($r['effective_date']),
            'progress' => $total > 0 ? round(100 * $done / $total) . '% (' . $done . '/' . $total . ')' : '-',
            'status'   => ['pill' => t('starters.status.' . $r['status']), 'colour' => RP_STARTERS_STATUS_COLOURS[$r['status']] ?? '#64748b'],
            'owner'    => $r['owner_name'] ?: '-',
        ];
        $detail = trim(implode(' · ', array_filter([(string)$r['job_title'], (string)$r['template_name']])));
        if ($detail !== '') $row['_detail'] = $detail;
        $out[] = $row;
    }
    $cols = [
        ['key' => 'person',   'label' => t('reporting.packs.starters.col_person'),   'w' => 28],
        ['key' => 'kind',     'label' => t('reporting.packs.starters.col_kind'),     'w' => 12],
        ['key' => 'date',     'label' => t('reporting.packs.col.date'),              'w' => 14],
        ['key' => 'progress', 'label' => t('reporting.packs.col.progress'),          'w' => 16, 'align' => 'right'],
        ['key' => 'status',   'label' => t('reporting.packs.col.status'),            'w' => 12],
    ];
    if ($o['owner']) $cols[] = ['key' => 'owner', 'label' => t('reporting.packs.starters.col_owner'), 'w' => 18];
    return ['kind' => 'table', 'rows' => $out, 'columns' => $cols,
            'note'  => $more ? t('reporting.packs.truncated', ['n' => $o['limit']]) : null,
            'empty' => t('reporting.packs.starters.empty')];
}

/**
 * Table: the steps of open cases that are past their due date, now - the person,
 * the step, who it is with, when it was due and how late it is.
 *
 * @param array $o  the block's cleaned options: kind, limit
 * @param array $range  the pack's range (not used - overdue now)
 */
function rpStartersOverdue(PDO $conn, int $analystId, array $o, array $range, $tenant): array
{
    [$tSql, $tArgs] = rpTenantClause($conn, $analystId, $tenant, 'c.tenant_id');
    $kindSql = ''; $kindArgs = [];
    if ($o['kind'] !== 'all' && JmlKind::tryFrom($o['kind'])) { $kindSql = ' AND c.kind = ?'; $kindArgs[] = $o['kind']; }
    $today = gmdate('Y-m-d');
    $st = $conn->prepare(
        "SELECT c.person_name, c.kind, c.effective_date, s.title, s.due_date,
                a.full_name AS analyst_name, tm.name AS team_name
           FROM jml_cases c
           JOIN tasks s ON s.parent_task_id = c.parent_task_id
      LEFT JOIN task_statuses ss ON ss.id = s.status_id
      LEFT JOIN analysts a ON a.id = s.assigned_analyst_id
      LEFT JOIN teams tm ON tm.id = s.assigned_team_id
          WHERE c.status = 'open' AND COALESCE(ss.is_closed, 0) = 0
            AND s.due_date IS NOT NULL AND s.due_date < ? $kindSql $tSql
       ORDER BY s.due_date ASC, c.effective_date ASC, s.id ASC
          LIMIT " . ((int)$o['limit'] + 1));
    $st->execute(array_merge([$today], $kindArgs, $tArgs));
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    $more = count($rows) > $o['limit'];
    if ($more) array_pop($rows);

    $out = [];
    foreach ($rows as $r) {
        $late = (int)((strtotime($today) - strtotime($r['due_date'])) / 86400);
        $with = $r['analyst_name'] ?: ($r['team_name'] ?: t('reporting.packs.unassigned'));
        if ($r['analyst_name'] && $r['team_name']) $with = $r['analyst_name'] . ' (' . $r['team_name'] . ')';
        $out[] = [
            'person' => $r['person_name'],
            'step'   => $r['title'],
            'with'   => $with,
            'due'    => rpFmtDate($r['due_date']),
            'late'   => ['pill' => t('reporting.packs.starters.days_late', ['n' => $late]), 'colour' => $late > 7 ? '#dc2626' : '#f59e0b'],
            '_detail' => t('starters.date_label.' . $r['kind']) . ': ' . rpFmtDate($r['effective_date']),
        ];
    }
    return ['kind' => 'table', 'snapshot' => true, 'rows' => $out,
            'columns' => [
                ['key' => 'person', 'label' => t('reporting.packs.starters.col_person'), 'w' => 22],
                ['key' => 'step',   'label' => t('reporting.packs.starters.col_step'),   'w' => 32],
                ['key' => 'with',   'label' => t('reporting.packs.starters.col_with'),   'w' => 20],
                ['key' => 'due',    'label' => t('reporting.packs.starters.col_due'),    'w' => 13],
                ['key' => 'late',   'label' => t('reporting.packs.starters.col_late'),   'w' => 13],
            ],
            'note'  => $more ? t('reporting.packs.truncated', ['n' => $o['limit']]) : null,
            'empty' => t('reporting.packs.starters.empty_overdue')];
}
