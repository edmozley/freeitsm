<?php
/**
 * Report Packs: Tickets blocks.
 *
 * Same rules as the ticket dashboard (api/tickets/get_ticket_widget_data.php) so a
 * pack and the dashboard agree: trashed tickets never count, categories roll up to
 * their ROOT, and the empty buckets are named the same ("Unassigned", "Not
 * categorised"). What differs is the period: a dashboard says "last 30 days from
 * now", a pack says "1 to 30 September" and means it.
 *
 * Three ways to pick tickets for a period (the `basis` option):
 *   created  created inside the range
 *   closed   closed inside the range
 *   open     still open at the END of the range - a snapshot, which is what
 *            "how big was the backlog on 30 September?" means
 */

/** dimension => [label SQL, joins, colour SQL or null] */
const RP_TICKET_DIMENSIONS = [
    'status'     => ["COALESCE(ts.name, 'Unknown')", '', 'MAX(ts.colour)'],
    'priority'   => ["COALESCE(tp.name, 'Unknown')", '', 'MAX(tp.colour)'],
    'category'   => ["COALESCE(croot.name, cmid.name, cleaf.name, 'Not categorised')",
                     'LEFT JOIN ticket_categories cleaf ON cleaf.id = t.category_id
                      LEFT JOIN ticket_categories cmid  ON cmid.id  = cleaf.parent_id
                      LEFT JOIN ticket_categories croot ON croot.id = cmid.parent_id', null],
    'department' => ["COALESCE(d.name, 'Unassigned')", 'LEFT JOIN departments d ON d.id = t.department_id', null],
    'type'       => ["COALESCE(tt.name, 'Unassigned')", 'LEFT JOIN ticket_types tt ON tt.id = t.ticket_type_id', null],
    'analyst'    => ["COALESCE(a.full_name, 'Unassigned')", 'LEFT JOIN analysts a ON a.id = t.assigned_analyst_id', null],
    'team'       => ["COALESCE(tm.name, 'Unassigned')", 'LEFT JOIN teams tm ON tm.id = t.assigned_team_id', null],
    'origin'     => ["COALESCE(o.name, 'Unknown')", 'LEFT JOIN ticket_origins o ON o.id = t.origin_id', null],
    'resolution' => ["COALESCE(rc.name, 'Not recorded')", 'LEFT JOIN ticket_resolution_codes rc ON rc.id = t.resolution_code_id', null],
    'first_time_fix' => ["CASE WHEN t.first_time_fix = 1 THEN 'Yes' WHEN t.first_time_fix = 0 THEN 'No' ELSE 'Not set' END", '', null],
];

const RP_TICKET_LOOKUPS = 'LEFT JOIN ticket_statuses ts ON ts.id = t.status_id LEFT JOIN ticket_priorities tp ON tp.id = t.priority_id';

/**
 * WHERE for "tickets in this period, for this company, not trashed".
 *
 * @param string $basis  which tickets: 'created' or 'closed' in the range, or 'open' at its end
 * @param array $range  the pack's range from rpResolveRange() - from_utc and to_utc are used
 * @param mixed $tenant  the pack's company: 'active', 'all' or a company id
 * @return array{0:string,1:array}
 */
function rpTicketWhere(PDO $conn, int $analystId, string $basis, array $range, $tenant): array
{
    [$tSql, $tParams] = rpTenantClause($conn, $analystId, $tenant, 't.tenant_id');
    $w = 'WHERE t.deleted_datetime IS NULL' . $tSql;
    $p = $tParams;
    // And only the departments the person the pack is built for may see (includes/ticket_department_scope.php).
    require_once __DIR__ . '/../ticket_department_scope.php';
    [$dSql, $dParams] = ticketDepartmentScopeSql($conn, $analystId, 't');
    $w .= $dSql;
    $p = array_merge($p, $dParams);
    if ($basis === 'closed') {
        $w .= ' AND t.closed_datetime >= ? AND t.closed_datetime < ?';
        array_push($p, $range['from_utc'], $range['to_utc']);
    } elseif ($basis === 'open') {
        $w .= ' AND t.created_datetime < ? AND (t.closed_datetime IS NULL OR t.closed_datetime >= ?)';
        array_push($p, $range['to_utc'], $range['to_utc']);
    } else {
        $w .= ' AND t.created_datetime >= ? AND t.created_datetime < ?';
        array_push($p, $range['from_utc'], $range['to_utc']);
    }
    return [$w, $p];
}

/**
 * Tickets block: a chart of tickets grouped by the chosen dimension (status, priority, category...).
 *
 * @param array $o  the block's cleaned options: by (an RP_TICKET_DIMENSIONS key), basis, limit, chart
 * @param array $range  the pack's range from rpResolveRange()
 * @param mixed $tenant  the pack's company: 'active', 'all' or a company id
 */
function rpTicketsBreakdown(PDO $conn, int $analystId, array $o, array $range, $tenant): array
{
    [$label, $join, $colour] = RP_TICKET_DIMENSIONS[$o['by']];
    [$where, $params] = rpTicketWhere($conn, $analystId, $o['basis'], $range, $tenant);
    $colSql = $colour ? ", $colour AS colour" : '';
    $sql = "SELECT $label AS label, COUNT(*) AS value $colSql
              FROM tickets t " . RP_TICKET_LOOKUPS . " $join
              $where
             GROUP BY label ORDER BY value DESC";
    $s = $conn->prepare($sql);
    $s->execute($params);
    $rows = $s->fetchAll(PDO::FETCH_ASSOC);
    return rpChartFromRows($rows, $o['limit'], $o['chart'], t('reporting.packs.series.tickets'));
}

/**
 * Turn [label, value, colour?] rows into chart data, folding everything past
 * `limit` into one "Other" slice so a pie never has fifty slivers.
 *
 * @param array $rows  the grouped rows, biggest first: label, value and optionally colour
 * @param int $limit  how many slices to keep before the rest is folded into "Other"
 * @param string $chart  the chart type, one of RP_CHART_TYPES
 * @param string $seriesName  the series' name, e.g. "Tickets"
 */
function rpChartFromRows(array $rows, int $limit, string $chart, string $seriesName): array
{
    $labels = []; $values = []; $colours = []; $other = 0;
    foreach ($rows as $i => $r) {
        if ($i < $limit) {
            $labels[]  = (string)$r['label'];
            $values[]  = (int)$r['value'];
            $colours[] = isset($r['colour']) && preg_match('/^#[0-9a-f]{3,8}$/i', (string)$r['colour']) ? $r['colour'] : null;
        } else {
            $other += (int)$r['value'];
        }
    }
    if ($other > 0) { $labels[] = t('reporting.packs.other'); $values[] = $other; $colours[] = null; }
    return [
        'kind'    => 'chart',
        'chart'   => $chart,
        'labels'  => $labels,
        'series'  => [['name' => $seriesName, 'values' => $values]],
        'colours' => array_filter($colours) ? $colours : null,
        'total'   => array_sum($values),
    ];
}

/**
 * Buckets for a time series over the range: day, week (Monday) or month, chosen
 * from the range's length when 'auto'. Returned as local-date keys with labels.
 *
 * @param array $range  the pack's range from rpResolveRange() - days, tz, from_date and to_date are used
 * @param string $grouping  'day', 'week', 'month', or 'auto' to pick one from the range's length
 * @return array{0:string,1:array<string,string>}  [grouping, key => label]
 */
function rpTimeBuckets(array $range, string $grouping): array
{
    if ($grouping === 'auto') {
        $grouping = $range['days'] <= 45 ? 'day' : ($range['days'] <= 190 ? 'week' : 'month');
    }
    $zone = new DateTimeZone($range['tz']);
    $d    = new DateTimeImmutable($range['from_date'], $zone);
    $end  = new DateTimeImmutable($range['to_date'], $zone);
    if ($grouping === 'week')  $d = $d->modify('monday this week');
    if ($grouping === 'month') $d = $d->modify('first day of this month');
    $out = []; $guard = 0;
    while ($d <= $end && $guard++ < 1000) {
        $key = rpBucketKey($d, $grouping);
        $out[$key] = $grouping === 'month' ? $d->format('M Y') : $d->format('Y-m-d');
        $d = $d->modify($grouping === 'day' ? '+1 day' : ($grouping === 'week' ? '+7 days' : '+1 month'));
    }
    return [$grouping, $out];
}

/**
 * The bucket a date falls in: the Monday of its week, its month (Y-m) or its day.
 *
 * @param DateTimeImmutable $d  the date, already in the viewer's timezone
 * @param string $grouping  'day', 'week' or 'month'
 */
function rpBucketKey(DateTimeImmutable $d, string $grouping): string
{
    if ($grouping === 'week')  return $d->modify('monday this week')->format('Y-m-d');
    if ($grouping === 'month') return $d->format('Y-m');
    return $d->format('Y-m-d');
}

/**
 * Count UTC timestamps into local buckets.
 *
 * @param array $stamps  the UTC timestamps to count, 'Y-m-d H:i:s'
 * @param array $range  the pack's range from rpResolveRange() - tz is used
 * @param string $grouping  'day', 'week' or 'month' (as rpTimeBuckets() settled it)
 * @param array $keys  the buckets from rpTimeBuckets(), key => label
 */
function rpBucketCount(array $stamps, array $range, string $grouping, array $keys): array
{
    $zone = new DateTimeZone($range['tz']);
    $counts = array_fill_keys(array_keys($keys), 0);
    foreach ($stamps as $utc) {
        $d = (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone($zone);
        $k = rpBucketKey($d, $grouping);
        if (isset($counts[$k])) $counts[$k]++;
    }
    return array_values($counts);
}

/**
 * Tickets block: a chart of tickets created and/or closed per day, week or month across the
 * period.
 *
 * Bucketed in PHP from the timestamps, since the buckets are local days and the database
 * only knows UTC.
 *
 * @param array $o  the block's cleaned options: grouping, series ('created', 'closed' or 'both'), chart
 * @param array $range  the pack's range from rpResolveRange()
 * @param mixed $tenant  the pack's company: 'active', 'all' or a company id
 */
function rpTicketsTrend(PDO $conn, int $analystId, array $o, array $range, $tenant): array
{
    [$grouping, $keys] = rpTimeBuckets($range, $o['grouping']);
    $series = [];
    foreach (['created', 'closed'] as $what) {
        if ($o['series'] !== 'both' && $o['series'] !== $what) continue;
        [$where, $params] = rpTicketWhere($conn, $analystId, $what, $range, $tenant);
        $col = $what === 'closed' ? 't.closed_datetime' : 't.created_datetime';
        // Timestamps, not GROUP BY DATE(): the buckets are LOCAL days, and the
        // database only knows UTC. A report's range is bounded, so this stays small.
        $s = $conn->prepare("SELECT $col FROM tickets t $where");
        $s->execute($params);
        $series[] = [
            'name'   => t('reporting.packs.series.' . $what),
            'values' => rpBucketCount($s->fetchAll(PDO::FETCH_COLUMN), $range, $grouping, $keys),
        ];
    }
    return ['kind' => 'chart', 'chart' => $o['chart'], 'labels' => array_values($keys), 'series' => $series, 'grouping' => $grouping];
}

/**
 * Tickets block: KPI tiles - created, closed, open at the end of the period, average time
 * to close and first-time-fix rate.
 *
 * @param array $o  the block's cleaned options (none are read)
 * @param array $range  the pack's range from rpResolveRange()
 * @param mixed $tenant  the pack's company: 'active', 'all' or a company id
 */
function rpTicketsKpis(PDO $conn, int $analystId, array $o, array $range, $tenant): array
{
    $count = function (string $basis) use ($conn, $analystId, $range, $tenant) {
        [$where, $params] = rpTicketWhere($conn, $analystId, $basis, $range, $tenant);
        $s = $conn->prepare("SELECT COUNT(*) FROM tickets t $where");
        $s->execute($params);
        return (int)$s->fetchColumn();
    };
    $created = $count('created');
    $closed  = $count('closed');
    $open    = $count('open');

    [$where, $params] = rpTicketWhere($conn, $analystId, 'closed', $range, $tenant);
    $s = $conn->prepare("SELECT AVG(TIMESTAMPDIFF(SECOND, t.created_datetime, t.closed_datetime)) AS avg_s,
                                SUM(t.first_time_fix = 1) AS ftf, SUM(t.first_time_fix IS NOT NULL) AS ftf_known
                           FROM tickets t $where AND t.closed_datetime >= t.created_datetime");
    $s->execute($params);
    $r = $s->fetch(PDO::FETCH_ASSOC) ?: [];
    $avg = $r['avg_s'] !== null ? (int)round((float)$r['avg_s']) : null;
    $ftfPct = (int)($r['ftf_known'] ?? 0) > 0 ? round(100 * (int)$r['ftf'] / (int)$r['ftf_known']) . '%' : '-';

    return ['kind' => 'kpi', 'tiles' => [
        ['label' => t('reporting.packs.kpi.created'),  'value' => number_format($created)],
        ['label' => t('reporting.packs.kpi.closed'),   'value' => number_format($closed)],
        ['label' => t('reporting.packs.kpi.open_end'), 'value' => number_format($open), 'hint' => t('reporting.packs.kpi.open_end_hint', ['date' => rpFmtDate($range['to_date'])])],
        ['label' => t('reporting.packs.kpi.avg_close'), 'value' => $avg !== null ? rpHumanDuration($avg) : '-'],
        ['label' => t('reporting.packs.kpi.ftf'),      'value' => $ftfPct],
    ]];
}

/**
 * Tickets block: a table of the tickets in the period, with the columns the block asks for,
 * up to its limit.
 *
 * @param array $o  the block's cleaned options: basis, limit, and the column switches status, priority, analyst, category, closed
 * @param array $range  the pack's range from rpResolveRange()
 * @param mixed $tenant  the pack's company: 'active', 'all' or a company id
 */
function rpTicketsList(PDO $conn, int $analystId, array $o, array $range, $tenant): array
{
    [$where, $params] = rpTicketWhere($conn, $analystId, $o['basis'], $range, $tenant);
    $sql = "SELECT t.ticket_number, t.subject, t.created_datetime, t.closed_datetime,
                   ts.name AS status, ts.colour AS status_colour, tp.name AS priority, tp.colour AS priority_colour,
                   a.full_name AS analyst,
                   COALESCE(croot.name, cmid.name, cleaf.name) AS category
              FROM tickets t " . RP_TICKET_LOOKUPS . "
              LEFT JOIN analysts a ON a.id = t.assigned_analyst_id
              LEFT JOIN ticket_categories cleaf ON cleaf.id = t.category_id
              LEFT JOIN ticket_categories cmid  ON cmid.id  = cleaf.parent_id
              LEFT JOIN ticket_categories croot ON croot.id = cmid.parent_id
              $where
             ORDER BY t.created_datetime ASC, t.id ASC
             LIMIT " . ((int)$o['limit'] + 1);
    $s = $conn->prepare($sql);
    $s->execute($params);
    $rows = $s->fetchAll(PDO::FETCH_ASSOC);
    $more = count($rows) > $o['limit'];
    if ($more) array_pop($rows);

    $cols = [
        ['key' => 'number',  'label' => t('reporting.packs.col.ticket'),  'w' => 18],
        ['key' => 'subject', 'label' => t('reporting.packs.col.subject'), 'w' => 38],
    ];
    if ($o['status'])   $cols[] = ['key' => 'status',   'label' => t('reporting.packs.col.status'),   'w' => 14];
    if ($o['priority']) $cols[] = ['key' => 'priority', 'label' => t('reporting.packs.col.priority'), 'w' => 12];
    if ($o['analyst'])  $cols[] = ['key' => 'analyst',  'label' => t('reporting.packs.col.analyst'),  'w' => 18];
    if ($o['category']) $cols[] = ['key' => 'category', 'label' => t('reporting.packs.col.category'), 'w' => 16];
    $cols[] = ['key' => 'created', 'label' => t('reporting.packs.col.created'), 'w' => 16];
    if ($o['closed'])   $cols[] = ['key' => 'closed',   'label' => t('reporting.packs.col.closed'),   'w' => 16];

    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'number'   => $r['ticket_number'],
            'subject'  => $r['subject'],
            'status'   => ['pill' => (string)$r['status'], 'colour' => $r['status_colour']],
            'priority' => ['pill' => (string)$r['priority'], 'colour' => $r['priority_colour']],
            'analyst'  => $r['analyst'] ?? t('reporting.packs.unassigned'),
            'category' => $r['category'] ?? '',
            'created'  => rpFmtDateTime($r['created_datetime']),
            'closed'   => $r['closed_datetime'] ? rpFmtDateTime($r['closed_datetime']) : '',
        ];
    }
    return ['kind' => 'table', 'columns' => $cols, 'rows' => $out,
            'note' => $more ? t('reporting.packs.truncated', ['n' => $o['limit']]) : null,
            'empty' => t('reporting.packs.empty.tickets')];
}

// ── Shared formatting (the viewer's own date format and timezone) ────────

/**
 * Formats a UTC date and time in the viewer's own format and timezone; '' for none.
 *
 * @param ?string $utc  a UTC 'Y-m-d H:i:s' timestamp; null or '' gives ''
 */
function rpFmtDateTime(?string $utc): string
{
    return $utc ? (function_exists('fmt_datetime') ? fmt_datetime($utc) : $utc) : '';
}

/**
 * A LOCAL Y-m-d (already in the viewer's zone) in their date format.
 *
 * @param string $ymd  a 'Y-m-d' date already in the viewer's timezone
 */
function rpFmtDate(string $ymd): string
{
    if (!class_exists('DateFmt')) return $ymd;
    $d = DateTime::createFromFormat('!Y-m-d', $ymd);
    return $d ? DateFmt::render($d, DateFmt::DATE_TEMPLATES[DateFmt::dateKey()]) : $ymd;
}

/**
 * A number of seconds as a short duration: 45s, 12m, 3h 5m, 2d 4h.
 *
 * @param int $seconds  the length of time in seconds
 */
function rpHumanDuration(int $seconds): string
{
    if ($seconds < 60) return $seconds . 's';
    $m = intdiv($seconds, 60);
    if ($m < 60) return $m . 'm';
    $h = intdiv($m, 60); $m %= 60;
    if ($h < 24) return $m ? "{$h}h {$m}m" : "{$h}h";
    $d = intdiv($h, 24); $h %= 24;
    return $h ? "{$d}d {$h}h" : "{$d}d";
}
