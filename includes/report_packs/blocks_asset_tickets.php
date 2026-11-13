<?php
/**
 * Report Packs: assets and their tickets (GH #310) - which assets, models and
 * types cause the most tickets. Asked for in a user's email.
 *
 * WHICH TICKETS: the same `basis` as the ticket blocks (created in the period,
 * closed in it, or open at its end), through rpTicketWhere() - so the pack's
 * company AND the department rule apply (includes/ticket_department_scope.php):
 * a pack never counts a ticket the person it is built for could not open.
 *
 * A ticket linked to two assets counts once for each asset in the per-asset
 * table, but ONCE per group in the charts (COUNT(DISTINCT t.id)) - two laptops of
 * one model on one ticket are one ticket for that model.
 *
 * The blocks need Assets (the handler's module) AND Tickets, checked here, since
 * they show ticket counts.
 */

require_once __DIR__ . '/blocks_tickets.php';

/** dimension => [label SQL, joins] for the chart; 'category' is the TICKET's top-level category. */
const RP_ASSET_TICKET_DIMENSIONS = [
    'model'        => ["COALESCE(NULLIF(TRIM(CONCAT_WS(' ', a.manufacturer, a.model)), ''), 'Model not recorded')", ''],
    'type'         => ["COALESCE(aty.name, 'No type')", 'LEFT JOIN asset_types aty ON aty.id = a.asset_type_id'],
    'manufacturer' => ["COALESCE(NULLIF(a.manufacturer, ''), 'Not recorded')", ''],
    'location'     => ["COALESCE(al.name, 'No location')", 'LEFT JOIN asset_locations al ON al.id = a.location_id'],
    'category'     => [RP_TICKET_DIMENSIONS['category'][0], RP_TICKET_DIMENSIONS['category'][1]],
];

/** The name an asset is known by: its hostname, else its tag, else its number. */
const RP_ASSET_NAME_SQL = "COALESCE(NULLIF(a.hostname, ''), NULLIF(a.asset_tag, ''), CONCAT('#', a.id))";

/** Refuses the block when the person cannot open Tickets - these blocks show ticket counts. */
function rpAssetTicketsGuard(PDO $conn, int $analystId): void
{
    if (!analystCanAccessModule($conn, $analystId, 'tickets')) {
        throw new RuntimeException(t('reporting.packs.err.no_module', ['module' => rpModuleName('tickets')]));
    }
}

/**
 * Summary: tickets linked to an asset, their share of all the period's tickets,
 * how many assets had tickets, and the asset with the most.
 *
 * @param array $o  the block's cleaned options: basis
 */
function rpAssetTicketsKpis(PDO $conn, int $analystId, array $o, array $range, $tenant): array
{
    rpAssetTicketsGuard($conn, $analystId);
    [$where, $p] = rpTicketWhere($conn, $analystId, $o['basis'], $range, $tenant);
    $st = $conn->prepare("SELECT COUNT(*) AS total,
                                 SUM(EXISTS (SELECT 1 FROM ticket_assets ta WHERE ta.ticket_id = t.id)) AS linked
                            FROM tickets t $where");
    $st->execute($p);
    $r = $st->fetch(PDO::FETCH_ASSOC) ?: ['total' => 0, 'linked' => 0];
    $total = (int)$r['total']; $linked = (int)$r['linked'];

    $st = $conn->prepare("SELECT " . RP_ASSET_NAME_SQL . " AS name, COUNT(*) AS n
                            FROM tickets t JOIN ticket_assets ta ON ta.ticket_id = t.id JOIN assets a ON a.id = ta.asset_id
                          $where GROUP BY a.id ORDER BY n DESC, name LIMIT 1");
    $st->execute($p);
    $top = $st->fetch(PDO::FETCH_ASSOC);
    $st = $conn->prepare("SELECT COUNT(DISTINCT ta.asset_id) FROM tickets t JOIN ticket_assets ta ON ta.ticket_id = t.id $where");
    $st->execute($p);
    $assets = (int)$st->fetchColumn();

    $hint = t('reporting.packs.basis.' . $o['basis']);
    return ['kind' => 'kpi', 'tiles' => [
        ['label' => t('reporting.packs.asset_tickets.linked'), 'value' => number_format($linked), 'hint' => $hint],
        ['label' => t('reporting.packs.asset_tickets.share'), 'value' => $total > 0 ? round(100 * $linked / $total) . '%' : '-',
         'hint' => t('reporting.packs.asset_tickets.share_hint', ['n' => number_format($linked), 'total' => number_format($total)])],
        ['label' => t('reporting.packs.asset_tickets.assets'), 'value' => number_format($assets), 'hint' => $hint],
        ['label' => t('reporting.packs.asset_tickets.busiest'), 'value' => $top ? (string)$top['name'] : '-',
         'hint' => $top ? t('reporting.packs.asset_tickets.n_tickets', ['n' => (int)$top['n']]) : $hint],
    ]];
}

/**
 * Table: assets with their number of tickets - the top N (sort 'count') or every
 * asset with a ticket, by name (sort 'name').
 *
 * @param array $o  the block's cleaned options: basis, sort, model, location, limit
 */
function rpAssetTicketsList(PDO $conn, int $analystId, array $o, array $range, $tenant): array
{
    rpAssetTicketsGuard($conn, $analystId);
    [$where, $p] = rpTicketWhere($conn, $analystId, $o['basis'], $range, $tenant);
    $order = $o['sort'] === 'name' ? 'name ASC, a.id' : 'n DESC, name ASC, a.id';
    $st = $conn->prepare(
        "SELECT a.id, " . RP_ASSET_NAME_SQL . " AS name, a.asset_tag, a.manufacturer, a.model, a.retired_datetime,
                aty.name AS type_name, al.name AS location_name, COUNT(*) AS n, MAX(t.created_datetime) AS latest
           FROM tickets t
           JOIN ticket_assets ta ON ta.ticket_id = t.id
           JOIN assets a ON a.id = ta.asset_id
      LEFT JOIN asset_types aty ON aty.id = a.asset_type_id
      LEFT JOIN asset_locations al ON al.id = a.location_id
         $where
       GROUP BY a.id
       ORDER BY $order
          LIMIT " . ((int)$o['limit'] + 1));
    $st->execute($p);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    $more = count($rows) > $o['limit'];
    if ($more) array_pop($rows);

    $out = [];
    foreach ($rows as $r) {
        $row = [
            'asset'    => (string)$r['name'],
            'type'     => (string)($r['type_name'] ?? ''),
            'model'    => trim((string)$r['manufacturer'] . ' ' . (string)$r['model']),
            'location' => (string)($r['location_name'] ?? ''),
            'tickets'  => number_format((int)$r['n']),
            'latest'   => rpFmtDateTime($r['latest']),
        ];
        $detail = [];
        if ($r['asset_tag'] !== null && $r['asset_tag'] !== '' && $r['asset_tag'] !== $r['name']) $detail[] = t('reporting.packs.asset_tickets.tag', ['tag' => $r['asset_tag']]);
        if ($r['retired_datetime']) $detail[] = t('reporting.packs.asset_tickets.retired');
        if ($detail) $row['_detail'] = implode(' · ', $detail);
        $out[] = $row;
    }
    $cols = [['key' => 'asset', 'label' => t('reporting.packs.asset_tickets.col_asset'), 'w' => 24],
             ['key' => 'type',  'label' => t('reporting.packs.col.type'), 'w' => 14]];
    if ($o['model'])    $cols[] = ['key' => 'model',    'label' => t('reporting.packs.asset_tickets.col_model'),    'w' => 22];
    if ($o['location']) $cols[] = ['key' => 'location', 'label' => t('reporting.packs.asset_tickets.col_location'), 'w' => 16];
    $cols[] = ['key' => 'tickets', 'label' => t('reporting.packs.asset_tickets.col_tickets'), 'w' => 10, 'align' => 'right'];
    $cols[] = ['key' => 'latest',  'label' => t('reporting.packs.asset_tickets.col_latest'),  'w' => 16];
    return ['kind' => 'table', 'rows' => $out, 'columns' => $cols,
            'note'  => $more ? t('reporting.packs.truncated', ['n' => $o['limit']]) : null,
            'empty' => t('reporting.packs.asset_tickets.empty')];
}

/**
 * Chart: the tickets linked to assets, grouped by the assets' model, type,
 * manufacturer or location, or by the tickets' own category.
 *
 * @param array $o  the block's cleaned options: by (an RP_ASSET_TICKET_DIMENSIONS key), basis, chart, limit
 */
function rpAssetTicketsBreakdown(PDO $conn, int $analystId, array $o, array $range, $tenant): array
{
    rpAssetTicketsGuard($conn, $analystId);
    [$label, $join] = RP_ASSET_TICKET_DIMENSIONS[$o['by']];
    [$where, $p] = rpTicketWhere($conn, $analystId, $o['basis'], $range, $tenant);
    $st = $conn->prepare("SELECT $label AS label, COUNT(DISTINCT t.id) AS value
                            FROM tickets t
                            JOIN ticket_assets ta ON ta.ticket_id = t.id
                            JOIN assets a ON a.id = ta.asset_id
                            $join
                          $where
                          GROUP BY label ORDER BY value DESC, label");
    $st->execute($p);
    return rpChartFromRows($st->fetchAll(PDO::FETCH_ASSOC), $o['limit'], $o['chart'], t('reporting.packs.series.tickets'));
}
