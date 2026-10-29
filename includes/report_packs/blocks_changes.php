<?php
/**
 * Report packs: the Change Management blocks - changes grouped by status, type,
 * priority, impact or risk, and a list of the period's changes.
 *
 * WHICH CHANGES ARE "IN THE PERIOD" - the basis option:
 *   created    raised in the period (created_datetime), like the ticket blocks
 *   scheduled  due to be carried out in the period (work_start_datetime) - what
 *              a CAB or a monthly change report usually means, and what the
 *              change calendar shows
 *
 * ⚠️ A change has no "completed" date (nothing records the moment it closed), so
 * a status chart is the CURRENT status of the period's changes, not their status
 * at the end of the period. The tool descriptions say so.
 *
 * Module 'changes'; company through rpTenantClause() like every other block.
 */

/** dimension => [label SQL, joins, colour SQL or null] */
const RP_CHANGE_DIMENSIONS = [
    'status'   => ["COALESCE(cs.name, 'Unknown')",      '', 'MAX(cs.colour)'],
    'type'     => ["COALESCE(ct.name, 'Unknown')",      '', 'MAX(ct.colour)'],
    'priority' => ["COALESCE(cp.name, 'Not set')",      'LEFT JOIN change_priorities cp ON cp.id = c.priority_id', 'MAX(cp.colour)'],
    'impact'   => ["COALESCE(ci.name, 'Not set')",      'LEFT JOIN change_impacts ci ON ci.id = c.impact_id', 'MAX(ci.colour)'],
    // Risk has no lookup table: ChangeService::riskLevel() stores the level as text.
    'risk'     => ["COALESCE(c.risk_level, 'Not assessed')", '',
                   "CASE MAX(c.risk_level) WHEN 'Low' THEN '#16a34a' WHEN 'Medium' THEN '#ca8a04' WHEN 'High' THEN '#ea580c'
                                           WHEN 'Very High' THEN '#dc2626' WHEN 'Critical' THEN '#991b1b' ELSE '#94a3b8' END"],
];

const RP_CHANGE_LOOKUPS = 'LEFT JOIN change_statuses cs ON cs.id = c.status_id LEFT JOIN change_types ct ON ct.id = c.change_type_id';

/**
 * The WHERE clause for "the period's changes" on the chosen basis, with the company.
 *
 * @param string $basis  'created' or 'scheduled'
 * @return array{0:string, 1:array}  ' WHERE ...' and its parameters
 */
function rpChangeWhere(PDO $conn, int $analystId, string $basis, array $range, $tenant): array
{
    $col = $basis === 'scheduled' ? 'c.work_start_datetime' : 'c.created_datetime';
    [$tSql, $tParams] = rpTenantClause($conn, $analystId, $tenant, 'c.tenant_id');
    return [" WHERE $col >= ? AND $col < ?$tSql", array_merge([$range['from_utc'], $range['to_utc']], $tParams)];
}

/**
 * Changes block: a chart of the period's changes grouped by status, type, priority, impact or risk.
 *
 * @param array $o  the block's cleaned options: by (an RP_CHANGE_DIMENSIONS key), basis, limit, chart
 */
function rpChangesBreakdown(PDO $conn, int $analystId, array $o, array $range, $tenant): array
{
    [$label, $join, $colour] = RP_CHANGE_DIMENSIONS[$o['by']];
    [$where, $params] = rpChangeWhere($conn, $analystId, $o['basis'], $range, $tenant);
    $colSql = $colour ? ", $colour AS colour" : '';
    $s = $conn->prepare("SELECT $label AS label, COUNT(*) AS value $colSql
                           FROM changes c " . RP_CHANGE_LOOKUPS . " $join
                           $where
                          GROUP BY label ORDER BY value DESC");
    $s->execute($params);
    return rpChartFromRows($s->fetchAll(PDO::FETCH_ASSOC), $o['limit'], $o['chart'], t('reporting.packs.series.changes'));
}

/**
 * Changes block: the period's changes as a table - reference, title, type, status,
 * risk, who it is assigned to, and when it is scheduled.
 *
 * @param array $o  the block's cleaned options: basis, limit (rows)
 */
function rpChangesList(PDO $conn, int $analystId, array $o, array $range, $tenant): array
{
    [$where, $params] = rpChangeWhere($conn, $analystId, $o['basis'], $range, $tenant);
    $s = $conn->prepare("SELECT c.id, c.title, c.risk_level, c.work_start_datetime, c.created_datetime,
                                cs.name AS status, cs.colour AS status_colour, ct.name AS type,
                                a.full_name AS assignee
                           FROM changes c " . RP_CHANGE_LOOKUPS . "
                      LEFT JOIN analysts a ON a.id = c.assigned_to_id
                           $where
                          ORDER BY " . ($o['basis'] === 'scheduled' ? 'c.work_start_datetime' : 'c.created_datetime') . " ASC, c.id ASC
                          LIMIT " . ((int)$o['limit'] + 1));
    $s->execute($params);
    $rows = $s->fetchAll(PDO::FETCH_ASSOC);
    $more = count($rows) > $o['limit'];
    if ($more) array_pop($rows);

    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'ref'       => '#' . $r['id'],
            'title'     => $r['title'],
            'type'      => (string)$r['type'],
            'status'    => ['pill' => (string)$r['status'], 'colour' => $r['status_colour']],
            'risk'      => $r['risk_level'] ?: t('reporting.packs.not_assessed'),
            'assignee'  => $r['assignee'] ?? t('reporting.packs.unassigned'),
            'scheduled' => $r['work_start_datetime'] ? rpFmtDateTime($r['work_start_datetime']) : '',
        ];
    }
    return ['kind' => 'table', 'rows' => $out,
            'columns' => [
                ['key' => 'ref',       'label' => t('reporting.packs.col.ref'),       'w' => 8],
                ['key' => 'title',     'label' => t('reporting.packs.col.title'),     'w' => 32],
                ['key' => 'type',      'label' => t('reporting.packs.col.type'),      'w' => 12],
                ['key' => 'status',    'label' => t('reporting.packs.col.status'),    'w' => 13],
                ['key' => 'risk',      'label' => t('reporting.packs.col.risk'),      'w' => 11],
                ['key' => 'assignee',  'label' => t('reporting.packs.col.assignee'),  'w' => 14],
                ['key' => 'scheduled', 'label' => t('reporting.packs.col.scheduled'), 'w' => 16],
            ],
            'note'  => $more ? t('reporting.packs.truncated', ['n' => $o['limit']]) : null,
            'empty' => t('reporting.packs.empty.changes')];
}
