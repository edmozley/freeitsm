<?php
/**
 * Report Packs: Software blocks.
 *
 * Counted the way the Software dashboard counts - machines (DISTINCT host), so an
 * application installed twice on one PC counts once, and publisher NULL and ''
 * both read "Not recorded". The inventory is a snapshot of NOW: the date range
 * does not apply to it, and the blocks say so rather than pretend.
 *
 * ⚠️ One deliberate difference from the dashboard: these are scoped to the pack's
 * COMPANY, through the machine each install is on (software_inventory_detail.host_id
 * is assets.id). The Software dashboard itself counts every company's machines.
 */

/**
 * "JOIN assets + company + system components" for inventory queries.
 *
 * @param mixed $tenant  the pack's company: 'active', 'all' or a company id (matched through the machine's asset)
 * @param bool $includeSystem  true to count system components too; false leaves them out
 */
function rpSoftwareScope(PDO $conn, int $analystId, $tenant, bool $includeSystem): array
{
    [$tSql, $tParams] = rpTenantClause($conn, $analystId, $tenant, 'h.tenant_id');
    $sys = $includeSystem ? '' : ' AND d.system_component = 0';
    return ["INNER JOIN assets h ON h.id = d.host_id", "WHERE 1=1$sys$tSql", $tParams];
}

/**
 * Software block: a chart of how many machines have software from each publisher. A snapshot of today.
 *
 * @param array $o  the block's cleaned options: system (include system components), limit, chart
 * @param array $range  the pack's range (not used - a snapshot of today)
 * @param mixed $tenant  the pack's company: 'active', 'all' or a company id
 */
function rpSoftwareByPublisher(PDO $conn, int $analystId, array $o, array $range, $tenant): array
{
    [$join, $where, $params] = rpSoftwareScope($conn, $analystId, $tenant, $o['system']);
    $s = $conn->prepare("SELECT COALESCE(NULLIF(a.publisher, ''), 'Not recorded') AS label, COUNT(DISTINCT d.host_id) AS value
                           FROM software_inventory_apps a
                           INNER JOIN software_inventory_detail d ON d.app_id = a.id
                           $join $where
                          GROUP BY label ORDER BY value DESC");
    $s->execute($params);
    $data = rpChartFromRows($s->fetchAll(PDO::FETCH_ASSOC), $o['limit'], $o['chart'], t('reporting.packs.series.machines'));
    $data['snapshot'] = true;
    return $data;
}

/**
 * Software block: a chart of the most installed applications, by number of machines. A snapshot of today.
 *
 * @param array $o  the block's cleaned options: system (include system components), limit, chart
 * @param array $range  the pack's range (not used - a snapshot of today)
 * @param mixed $tenant  the pack's company: 'active', 'all' or a company id
 */
function rpSoftwareTopApps(PDO $conn, int $analystId, array $o, array $range, $tenant): array
{
    [$join, $where, $params] = rpSoftwareScope($conn, $analystId, $tenant, $o['system']);
    $s = $conn->prepare("SELECT a.display_name AS label, COUNT(DISTINCT d.host_id) AS value
                           FROM software_inventory_apps a
                           INNER JOIN software_inventory_detail d ON d.app_id = a.id
                           $join $where
                          GROUP BY a.id, a.display_name ORDER BY value DESC
                          LIMIT " . (int)$o['limit']);
    $s->execute($params);
    // No "Other" bucket here: the rest of the inventory is not one thing.
    $rows = $s->fetchAll(PDO::FETCH_ASSOC);
    return [
        'kind' => 'chart', 'chart' => $o['chart'], 'snapshot' => true,
        'labels' => array_column($rows, 'label'),
        'series' => [['name' => t('reporting.packs.series.machines'), 'values' => array_map('intval', array_column($rows, 'value'))]],
    ];
}

/**
 * Software block: KPI tiles - applications, machines and installs in the inventory, and
 * licence renewals in the period.
 *
 * @param array $o  the block's cleaned options (none are read)
 * @param array $range  the pack's range from rpResolveRange() - for licence renewals in the period
 * @param mixed $tenant  the pack's company, for the inventory counts (the renewals count is not split by company)
 */
function rpSoftwareKpis(PDO $conn, int $analystId, array $o, array $range, $tenant): array
{
    [$join, $where, $params] = rpSoftwareScope($conn, $analystId, $tenant, false);
    $s = $conn->prepare("SELECT COUNT(DISTINCT d.app_id) AS apps, COUNT(DISTINCT d.host_id) AS hosts, COUNT(DISTINCT d.host_id,d.app_id) AS installs
                           FROM software_inventory_detail d $join $where");
    $s->execute($params);
    $r = $s->fetch(PDO::FETCH_ASSOC) ?: ['apps' => 0, 'hosts' => 0, 'installs' => 0];

    $l = $conn->prepare("SELECT COUNT(*) FROM software_licences WHERE renewal_date >= ? AND renewal_date <= ?");
    $l->execute([$range['from_date'], $range['to_date']]);

    return ['kind' => 'kpi', 'snapshot' => true, 'tiles' => [
        ['label' => t('reporting.packs.kpi.apps'),     'value' => number_format((int)$r['apps'])],
        ['label' => t('reporting.packs.kpi.machines'), 'value' => number_format((int)$r['hosts'])],
        ['label' => t('reporting.packs.kpi.installs'), 'value' => number_format((int)$r['installs'])],
        ['label' => t('reporting.packs.kpi.renewals'), 'value' => number_format((int)$l->fetchColumn()), 'hint' => t('reporting.packs.kpi.in_period')],
    ]];
}

/**
 * Licences, optionally only those renewing in the range. Licence keys are NEVER
 * included - a report is printed, emailed and left on desks.
 *
 * @param array $o  the block's cleaned options: renewing (only licences renewing in the range)
 * @param array $range  the pack's range from rpResolveRange() - from_date and to_date are used
 * @param mixed $tenant  the pack's company (not used - licences are not split by company)
 */
function rpSoftwareLicences(PDO $conn, int $analystId, array $o, array $range, $tenant): array
{
    $where = ''; $params = [];
    if ($o['renewing']) { $where = 'WHERE l.renewal_date >= ? AND l.renewal_date <= ?'; $params = [$range['from_date'], $range['to_date']]; }
    $s = $conn->prepare("SELECT a.display_name, l.licence_type, l.quantity, l.renewal_date, l.cost, l.currency, l.status
                           FROM software_licences l
                           LEFT JOIN software_inventory_apps a ON a.id = l.app_id
                           $where
                          ORDER BY l.renewal_date IS NULL, l.renewal_date ASC, a.display_name
                          LIMIT 1000");
    $s->execute($params);
    $rows = [];
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $rows[] = [
            'app'      => (string)$r['display_name'],
            'type'     => (string)$r['licence_type'],
            'quantity' => $r['quantity'] !== null ? (string)(int)$r['quantity'] : '',
            'renewal'  => $r['renewal_date'] ? rpFmtDate(substr((string)$r['renewal_date'], 0, 10)) : '',
            'cost'     => $r['cost'] !== null ? Money::format($r['cost'], $r['currency'] ?: null) : '',   // GH #189
            'status'   => (string)$r['status'],
        ];
    }
    return ['kind' => 'table', 'rows' => $rows, 'empty' => t('reporting.packs.empty.licences'), 'columns' => [
        ['key' => 'app',      'label' => t('reporting.packs.col.application'), 'w' => 32],
        ['key' => 'type',     'label' => t('reporting.packs.col.type'),        'w' => 15],
        ['key' => 'quantity', 'label' => t('reporting.packs.col.quantity'),    'w' => 10, 'align' => 'right'],
        ['key' => 'renewal',  'label' => t('reporting.packs.col.renewal'),     'w' => 15],
        ['key' => 'cost',     'label' => t('reporting.packs.col.cost'),        'w' => 15, 'align' => 'right'],
        ['key' => 'status',   'label' => t('reporting.packs.col.status'),      'w' => 13],
    ]];
}
