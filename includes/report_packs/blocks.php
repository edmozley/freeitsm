<?php
/**
 * Report Packs: the block registry - everything that can be dragged into a pack.
 *
 * TWO LEVELS, ON PURPOSE
 * ----------------------
 *   handlers  what a block IS: its module, its options, and the PHP function that
 *             fetches its data. A pack stores the handler key and the options.
 *   toolbox   what the designer OFFERS: a searchable, previewable item that drops
 *             in a handler with options already chosen. "Tickets by status" and
 *             "Tickets by priority" are two toolbox items over one handler, so
 *             either can be turned into the other later from the Properties pane,
 *             the way a Word chart changes type without being re-inserted.
 *
 * Adding a block is one handler entry plus one or more toolbox entries, plus its
 * function in the area's file. Nothing in the designer or the PDF engine changes:
 * they draw by the data's `kind` (chart / kpi / table / uptime), not by block.
 *
 * MODULE ACCESS
 * -------------
 * Each handler names the module whose data it shows. The toolbox only offers what
 * the viewer may use, and api/reporting/packs/block_data.php refuses the rest - so
 * a pack shared with someone who lacks Contracts shows them a placeholder, never
 * the figures. 'reporting' is everyone who can open Report Packs at all.
 *
 * Every data function receives ($conn, $analystId, $opts, $range, $tenant) and
 * returns an array with a `kind`. $opts has already been validated against the
 * handler's option schema, so a function never sees a value it did not declare.
 */

require_once __DIR__ . '/criteria.php';
require_once __DIR__ . '/blocks_tickets.php';
require_once __DIR__ . '/blocks_status.php';
require_once __DIR__ . '/blocks_software.php';
require_once __DIR__ . '/blocks_assets.php';
require_once __DIR__ . '/blocks_projects.php';

const RP_CHART_TYPES = ['bar', 'hbar', 'line', 'doughnut', 'pie'];

/** Option helpers, so the handler table below stays readable. */
function rpOptSelect(string $label, array $values, string $default): array
{
    return ['type' => 'select', 'label' => $label, 'values' => $values, 'default' => $default];
}
function rpOptInt(string $label, int $min, int $max, int $default): array
{
    return ['type' => 'int', 'label' => $label, 'min' => $min, 'max' => $max, 'default' => $default];
}
function rpOptBool(string $label, bool $default): array
{
    return ['type' => 'bool', 'label' => $label, 'default' => $default];
}
function rpOptMulti(string $label, string $source): array
{
    return ['type' => 'multi', 'label' => $label, 'source' => $source, 'default' => []];
}
function rpChartOpt(string $default): array
{
    $v = [];
    foreach (RP_CHART_TYPES as $c) $v[$c] = t('reporting.packs.chart.' . $c);
    return rpOptSelect(t('reporting.packs.opt.chart'), $v, $default);
}

/**
 * Handlers, keyed. `module` gates the data; `kinds` lists what it can return (for
 * the designer's preview); `fn` fetches it; `opts` is the option schema.
 */
function rpHandlers(): array
{
    static $h = null;
    if ($h !== null) return $h;

    $ticketBy = [];
    foreach (RP_TICKET_DIMENSIONS as $k => $_) $ticketBy[$k] = t('reporting.packs.dim.' . $k);
    $basis = [
        'created' => t('reporting.packs.basis.created'),
        'closed'  => t('reporting.packs.basis.closed'),
        'open'    => t('reporting.packs.basis.open'),
    ];
    $grouping = [
        'auto'  => t('reporting.packs.group.auto'),
        'day'   => t('reporting.packs.group.day'),
        'week'  => t('reporting.packs.group.week'),
        'month' => t('reporting.packs.group.month'),
    ];
    $assetBy = [];
    foreach (RP_ASSET_DIMENSIONS as $k => $_) $assetBy[$k] = t('reporting.packs.dim.' . $k);
    $intuneBy = [];
    foreach (RP_INTUNE_DIMENSIONS as $k => $_) $intuneBy[$k] = t('reporting.packs.dim.' . $k);

    return $h = [
        // ── Tickets ─────────────────────────────────────────────────────
        'tickets.breakdown' => [
            'module' => 'tickets', 'kind' => 'chart', 'fn' => 'rpTicketsBreakdown',
            'opts' => [
                'by'    => rpOptSelect(t('reporting.packs.opt.by'), $ticketBy, 'status'),
                'basis' => rpOptSelect(t('reporting.packs.opt.basis'), $basis, 'created'),
                'chart' => rpChartOpt('doughnut'),
                'limit' => rpOptInt(t('reporting.packs.opt.limit'), 3, 50, 12),
            ],
        ],
        'tickets.trend' => [
            'module' => 'tickets', 'kind' => 'chart', 'fn' => 'rpTicketsTrend',
            'opts' => [
                'series'   => rpOptSelect(t('reporting.packs.opt.series'), [
                    'both'    => t('reporting.packs.series.both'),
                    'created' => t('reporting.packs.series.created'),
                    'closed'  => t('reporting.packs.series.closed'),
                ], 'both'),
                'grouping' => rpOptSelect(t('reporting.packs.opt.grouping'), $grouping, 'auto'),
                'chart'    => rpOptSelect(t('reporting.packs.opt.chart'), [
                    'line' => t('reporting.packs.chart.line'), 'bar' => t('reporting.packs.chart.bar'),
                ], 'line'),
            ],
        ],
        'tickets.kpis' => [
            'module' => 'tickets', 'kind' => 'kpi', 'fn' => 'rpTicketsKpis', 'opts' => [],
        ],
        'tickets.list' => [
            'module' => 'tickets', 'kind' => 'table', 'fn' => 'rpTicketsList',
            'opts' => [
                'basis'  => rpOptSelect(t('reporting.packs.opt.basis'), $basis, 'created'),
                'limit'  => rpOptInt(t('reporting.packs.opt.rows'), 10, 2000, 200),
                'status' => rpOptBool(t('reporting.packs.opt.col_status'), true),
                'priority' => rpOptBool(t('reporting.packs.opt.col_priority'), true),
                'analyst'  => rpOptBool(t('reporting.packs.opt.col_analyst'), true),
                'category' => rpOptBool(t('reporting.packs.opt.col_category'), false),
                'closed'   => rpOptBool(t('reporting.packs.opt.col_closed'), true),
            ],
        ],

        // ── Service Status ──────────────────────────────────────────────
        'status.uptime' => [
            'module' => 'service-status', 'kind' => 'uptime', 'fn' => 'rpStatusUptime',
            'opts' => [
                'services'  => rpOptMulti(t('reporting.packs.opt.services'), 'status_services'),
                'strip'     => rpOptBool(t('reporting.packs.opt.strip'), true),
                'incidents' => rpOptBool(t('reporting.packs.opt.incidents'), true),
            ],
        ],
        'status.uptime_table' => [
            'module' => 'service-status', 'kind' => 'table', 'fn' => 'rpStatusUptimeTable',
            'opts' => [
                'services' => rpOptMulti(t('reporting.packs.opt.services'), 'status_services'),
            ],
        ],
        'status.incident_log' => [
            'module' => 'service-status', 'kind' => 'table', 'fn' => 'rpStatusIncidentLog',
            'opts' => [
                'internal' => rpOptBool(t('reporting.packs.opt.internal'), false),
                'comments' => rpOptBool(t('reporting.packs.opt.comments'), true),
            ],
        ],
        'status.incident_summary' => [
            'module' => 'service-status', 'kind' => 'table', 'fn' => 'rpStatusIncidentSummary', 'opts' => [],
        ],
        'status.kpis' => [
            'module' => 'service-status', 'kind' => 'kpi', 'fn' => 'rpStatusKpis', 'opts' => [],
        ],

        // ── Software ────────────────────────────────────────────────────
        'software.by_publisher' => [
            'module' => 'software', 'kind' => 'chart', 'fn' => 'rpSoftwareByPublisher',
            'opts' => [
                'chart'  => rpChartOpt('doughnut'),
                'limit'  => rpOptInt(t('reporting.packs.opt.limit'), 3, 50, 15),
                'system' => rpOptBool(t('reporting.packs.opt.system_components'), false),
            ],
        ],
        'software.top_apps' => [
            'module' => 'software', 'kind' => 'chart', 'fn' => 'rpSoftwareTopApps',
            'opts' => [
                'chart'  => rpChartOpt('bar'),
                'limit'  => rpOptInt(t('reporting.packs.opt.limit'), 3, 50, 20),
                'system' => rpOptBool(t('reporting.packs.opt.system_components'), false),
            ],
        ],
        'software.kpis' => [
            'module' => 'software', 'kind' => 'kpi', 'fn' => 'rpSoftwareKpis', 'opts' => [],
        ],
        'software.licences' => [
            'module' => 'software', 'kind' => 'table', 'fn' => 'rpSoftwareLicences',
            'opts' => [
                'renewing' => rpOptBool(t('reporting.packs.opt.renewing_only'), false),
            ],
        ],

        // ── Assets and Intune ───────────────────────────────────────────
        'assets.breakdown' => [
            'module' => 'assets', 'kind' => 'chart', 'fn' => 'rpAssetsBreakdown',
            'opts' => [
                'by'    => rpOptSelect(t('reporting.packs.opt.by'), $assetBy, 'type'),
                'chart' => rpChartOpt('bar'),
                'limit' => rpOptInt(t('reporting.packs.opt.limit'), 3, 50, 12),
            ],
        ],
        'assets.kpis' => [
            'module' => 'assets', 'kind' => 'kpi', 'fn' => 'rpAssetsKpis', 'opts' => [],
        ],
        'assets.expiring' => [
            'module' => 'assets', 'kind' => 'table', 'fn' => 'rpAssetsExpiring',
            'opts' => [
                'what' => rpOptSelect(t('reporting.packs.opt.expiry'), [
                    'warranty' => t('reporting.packs.expiry.warranty'),
                    'lease'    => t('reporting.packs.expiry.lease'),
                    'both'     => t('reporting.packs.expiry.both'),
                ], 'both'),
            ],
        ],
        'intune.breakdown' => [
            'module' => 'reporting', 'kind' => 'chart', 'fn' => 'rpIntuneBreakdown',
            'opts' => [
                'by'    => rpOptSelect(t('reporting.packs.opt.by'), $intuneBy, 'compliance'),
                'chart' => rpChartOpt('doughnut'),
                'limit' => rpOptInt(t('reporting.packs.opt.limit'), 3, 50, 12),
            ],
        ],
        'intune.kpis' => [
            'module' => 'reporting', 'kind' => 'kpi', 'fn' => 'rpIntuneKpis', 'opts' => [],
        ],

        // ── Projects (3.2.0) ────────────────────────────────────────────
        'projects.kpis' => [
            'module' => 'projects', 'kind' => 'kpi', 'fn' => 'rpProjectsKpis', 'opts' => [],
        ],
        'projects.health' => [
            'module' => 'projects', 'kind' => 'chart', 'fn' => 'rpProjectsHealth',
            'opts' => [
                'projects' => rpOptMulti(t('reporting.packs.opt.projects'), 'projects'),
                'chart'    => rpChartOpt('doughnut'),
            ],
        ],
        'projects.status' => [
            'module' => 'projects', 'kind' => 'table', 'fn' => 'rpProjectsStatus',
            'opts' => [
                'projects' => rpOptMulti(t('reporting.packs.opt.projects'), 'projects'),
                'scope'    => rpOptSelect(t('reporting.packs.opt.which_projects'), [
                    'live' => t('reporting.packs.projects.scope_live'),
                    'all'  => t('reporting.packs.projects.scope_all'),
                ], 'live'),
                'manager'  => rpOptBool(t('reporting.packs.opt.col_manager'), true),
                'notes'    => rpOptBool(t('reporting.packs.opt.health_notes'), true),
            ],
        ],
        'projects.milestones' => [
            'module' => 'projects', 'kind' => 'table', 'fn' => 'rpProjectsMilestones',
            'opts' => [
                'projects' => rpOptMulti(t('reporting.packs.opt.projects'), 'projects'),
            ],
        ],
        'projects.risks' => [
            'module' => 'projects', 'kind' => 'table', 'fn' => 'rpProjectsRisks',
            'opts' => [
                'projects' => rpOptMulti(t('reporting.packs.opt.projects'), 'projects'),
                'limit'    => rpOptInt(t('reporting.packs.opt.rows'), 3, 100, 10),
                'plans'    => rpOptBool(t('reporting.packs.opt.risk_plans'), true),
            ],
        ],
    ];
}

/**
 * The toolbox: [key => [handler, preset opts, area, default span, keywords]].
 * Titles and descriptions come from lang/en/reporting.php (packs.tool.<key>).
 */
function rpToolboxItems(): array
{
    return [
        // Layout - no data, no module
        'heading'   => ['handler' => null, 'type' => 'heading',   'area' => 'layout', 'span' => 12, 'kw' => 'title section h1 h2'],
        'text'      => ['handler' => null, 'type' => 'text',      'area' => 'layout', 'span' => 12, 'kw' => 'paragraph rich text box summary write'],
        'pagebreak' => ['handler' => null, 'type' => 'pagebreak', 'area' => 'layout', 'span' => 12, 'kw' => 'new page break'],
        'spacer'    => ['handler' => null, 'type' => 'spacer',    'area' => 'layout', 'span' => 12, 'kw' => 'gap space blank'],
        'divider'   => ['handler' => null, 'type' => 'divider',   'area' => 'layout', 'span' => 12, 'kw' => 'line rule separator'],

        // Tickets
        'tickets_kpis'        => ['handler' => 'tickets.kpis',      'area' => 'tickets', 'span' => 12, 'opts' => [], 'kw' => 'summary totals numbers created closed open resolution first time fix'],
        'tickets_trend'       => ['handler' => 'tickets.trend',     'area' => 'tickets', 'span' => 12, 'opts' => [], 'kw' => 'created closed over time line volume'],
        'tickets_by_status'   => ['handler' => 'tickets.breakdown', 'area' => 'tickets', 'span' => 6,  'opts' => ['by' => 'status',   'chart' => 'doughnut'], 'kw' => 'status pie'],
        'tickets_by_priority' => ['handler' => 'tickets.breakdown', 'area' => 'tickets', 'span' => 6,  'opts' => ['by' => 'priority', 'chart' => 'doughnut'], 'kw' => 'priority urgent'],
        'tickets_by_category' => ['handler' => 'tickets.breakdown', 'area' => 'tickets', 'span' => 12, 'opts' => ['by' => 'category', 'chart' => 'hbar'], 'kw' => 'category classification'],
        'tickets_by_analyst'  => ['handler' => 'tickets.breakdown', 'area' => 'tickets', 'span' => 12, 'opts' => ['by' => 'analyst',  'chart' => 'hbar', 'basis' => 'closed'], 'kw' => 'analyst workload who'],
        'tickets_by_team'     => ['handler' => 'tickets.breakdown', 'area' => 'tickets', 'span' => 6,  'opts' => ['by' => 'team',     'chart' => 'bar'], 'kw' => 'team group'],
        'tickets_by_department' => ['handler' => 'tickets.breakdown', 'area' => 'tickets', 'span' => 6, 'opts' => ['by' => 'department', 'chart' => 'bar'], 'kw' => 'department'],
        'tickets_by_origin'   => ['handler' => 'tickets.breakdown', 'area' => 'tickets', 'span' => 6,  'opts' => ['by' => 'origin',   'chart' => 'pie'], 'kw' => 'origin channel source email phone portal'],
        'tickets_list'        => ['handler' => 'tickets.list',      'area' => 'tickets', 'span' => 12, 'opts' => [], 'kw' => 'table list rows tickets'],

        // Service Status
        'status_kpis'             => ['handler' => 'status.kpis',             'area' => 'status', 'span' => 12, 'opts' => [], 'kw' => 'summary outage downtime incidents availability'],
        'status_uptime'           => ['handler' => 'status.uptime',           'area' => 'status', 'span' => 12, 'opts' => [], 'kw' => 'uptime availability services bars history disponibilidad'],
        'status_uptime_table'     => ['handler' => 'status.uptime_table',     'area' => 'status', 'span' => 12, 'opts' => [], 'kw' => 'uptime percentage table services'],
        'status_incident_log'     => ['handler' => 'status.incident_log',     'area' => 'status', 'span' => 12, 'opts' => [], 'kw' => 'incidents updates log timeline incidencias'],
        'status_incident_summary' => ['handler' => 'status.incident_summary', 'area' => 'status', 'span' => 12, 'opts' => [], 'kw' => 'incidents list affected services status'],

        // Software
        'software_kpis'         => ['handler' => 'software.kpis',         'area' => 'software', 'span' => 12, 'opts' => [], 'kw' => 'summary applications machines installs'],
        'software_by_publisher' => ['handler' => 'software.by_publisher', 'area' => 'software', 'span' => 6,  'opts' => [], 'kw' => 'publisher vendor doughnut'],
        'software_top_apps'     => ['handler' => 'software.top_apps',     'area' => 'software', 'span' => 12, 'opts' => [], 'kw' => 'top installed applications most common'],
        'software_licences'     => ['handler' => 'software.licences',     'area' => 'software', 'span' => 12, 'opts' => [], 'kw' => 'licences licenses renewals cost'],

        // Assets / Intune
        'assets_kpis'        => ['handler' => 'assets.kpis',      'area' => 'assets', 'span' => 12, 'opts' => [], 'kw' => 'summary devices total warranty'],
        'assets_by_type'     => ['handler' => 'assets.breakdown', 'area' => 'assets', 'span' => 6,  'opts' => ['by' => 'type',   'chart' => 'doughnut'], 'kw' => 'type devices laptops desktops'],
        'assets_by_os'       => ['handler' => 'assets.breakdown', 'area' => 'assets', 'span' => 6,  'opts' => ['by' => 'os',     'chart' => 'bar'], 'kw' => 'operating system windows'],
        'assets_by_status'   => ['handler' => 'assets.breakdown', 'area' => 'assets', 'span' => 6,  'opts' => ['by' => 'status', 'chart' => 'pie'], 'kw' => 'status in use stock'],
        'assets_expiring'    => ['handler' => 'assets.expiring',  'area' => 'assets', 'span' => 12, 'opts' => [], 'kw' => 'warranty lease expiring expiry renewals'],
        'intune_kpis'        => ['handler' => 'intune.kpis',      'area' => 'assets', 'span' => 12, 'opts' => [], 'kw' => 'intune devices compliance encrypted stale'],
        'intune_compliance'  => ['handler' => 'intune.breakdown', 'area' => 'assets', 'span' => 6,  'opts' => ['by' => 'compliance', 'chart' => 'doughnut'], 'kw' => 'intune compliance compliant'],
        'intune_os'          => ['handler' => 'intune.breakdown', 'area' => 'assets', 'span' => 6,  'opts' => ['by' => 'os', 'chart' => 'bar'], 'kw' => 'intune operating system'],
        'intune_encryption'  => ['handler' => 'intune.breakdown', 'area' => 'assets', 'span' => 6,  'opts' => ['by' => 'encryption', 'chart' => 'pie'], 'kw' => 'intune bitlocker encryption'],

        // Projects
        'projects_kpis'       => ['handler' => 'projects.kpis',       'area' => 'projects', 'span' => 12, 'opts' => [], 'kw' => 'summary portfolio on track at risk off track rag'],
        'projects_health'     => ['handler' => 'projects.health',     'area' => 'projects', 'span' => 6,  'opts' => [], 'kw' => 'health rag green amber red doughnut portfolio'],
        'projects_status'     => ['handler' => 'projects.status',     'area' => 'projects', 'span' => 12, 'opts' => [], 'kw' => 'status highlight report progress health rag board'],
        'projects_milestones' => ['handler' => 'projects.milestones', 'area' => 'projects', 'span' => 12, 'opts' => [], 'kw' => 'milestones stages deadlines dates met missed gates'],
        'projects_risks'      => ['handler' => 'projects.risks',      'area' => 'projects', 'span' => 12, 'opts' => [], 'kw' => 'risks raid top risk register score'],
    ];
}

/**
 * Validate a block's options against its handler's schema. Unknown keys are
 * dropped, out-of-range values clamped, missing ones defaulted - so the data
 * function, the PDF and the saved design all see the same, legal values.
 */
function rpCleanOpts(string $handler, $opts): array
{
    $schema = rpHandlers()[$handler]['opts'] ?? [];
    $opts = is_array($opts) ? $opts : [];
    $out = [];
    foreach ($schema as $k => $def) {
        $v = $opts[$k] ?? $def['default'];
        switch ($def['type']) {
            case 'select': $out[$k] = (is_string($v) && isset($def['values'][$v])) ? $v : $def['default']; break;
            case 'int':    $out[$k] = max($def['min'], min($def['max'], is_numeric($v) ? (int)$v : $def['default'])); break;
            case 'bool':   $out[$k] = (bool)$v; break;
            case 'multi':  $out[$k] = is_array($v) ? array_values(array_unique(array_filter(array_map('intval', $v), fn($x) => $x > 0))) : []; break;
        }
    }
    return $out;
}

/**
 * Fetch one block's data for this viewer. Throws RuntimeException with a message
 * fit to show in the block when the viewer may not see it.
 */
function rpBlockData(PDO $conn, int $analystId, string $handler, $opts, array $criteria): array
{
    $h = rpHandlers()[$handler] ?? null;
    if (!$h) throw new RuntimeException(t('reporting.packs.err.unknown_block'));
    if (!analystCanAccessModule($conn, $analystId, $h['module'])) {
        throw new RuntimeException(t('reporting.packs.err.no_module', ['module' => rpModuleName($h['module'])]));
    }
    $range  = rpResolveRange(is_array($criteria['range'] ?? null) ? $criteria['range'] : []);
    $tenant = $criteria['tenant'] ?? 'active';
    $data = call_user_func($h['fn'], $conn, $analystId, rpCleanOpts($handler, $opts), $range, $tenant);
    $data['kind'] = $data['kind'] ?? $h['kind'];
    return $data;
}

/** A module's display name, for "You do not have access to X". */
function rpModuleName(string $key): string
{
    $reg = function_exists('getModuleRegistry') ? getModuleRegistry() : [];
    return (string)($reg[$key] ?? $key);
}

/** Choices for a 'multi' option's source (the Properties pane's checkboxes). */
function rpOptionSource(PDO $conn, string $source, int $analystId = 0): array
{
    if ($source === 'projects') {
        return rpProjectChoices($conn, $analystId);
    }
    if ($source === 'status_services') {
        $out = [];
        foreach ($conn->query("SELECT id, name FROM status_services WHERE is_active = 1 ORDER BY display_order, name") as $r) {
            $out[] = ['id' => (int)$r['id'], 'name' => $r['name']];
        }
        return $out;
    }
    return [];
}

/**
 * The toolbox and handler schemas as the designer needs them, filtered to what
 * this viewer may use. Blocks they cannot use are still DESCRIBED (so a shared
 * pack can show "needs Contracts") but marked unavailable.
 */
function rpCatalogue(PDO $conn, int $analystId): array
{
    $handlers = [];
    foreach (rpHandlers() as $key => $h) {
        $opts = [];
        foreach ($h['opts'] as $k => $def) {
            $o = $def;
            if ($def['type'] === 'select') {
                $o['values'] = array_map(fn($v, $l) => ['value' => $v, 'label' => $l], array_keys($def['values']), $def['values']);
            }
            if ($def['type'] === 'multi') {
                $o['choices'] = analystCanAccessModule($conn, $analystId, $h['module']) ? rpOptionSource($conn, $def['source'], $analystId) : [];
            }
            $opts[$k] = $o;
        }
        $handlers[$key] = [
            'module'    => $h['module'],
            'kind'      => $h['kind'],
            'opts'      => $opts,
            'available' => analystCanAccessModule($conn, $analystId, $h['module']),
            'module_name' => rpModuleName($h['module']),
        ];
    }
    $tools = [];
    foreach (rpToolboxItems() as $key => $it) {
        $available = $it['handler'] === null ? true : ($handlers[$it['handler']]['available'] ?? false);
        $tools[] = [
            'key'       => $key,
            'handler'   => $it['handler'],
            'type'      => $it['type'] ?? 'data',
            'area'      => $it['area'],
            'span'      => $it['span'],
            'opts'      => $it['opts'] ?? [],
            'title'     => t('reporting.packs.tool.' . $key . '.title'),
            'desc'      => t('reporting.packs.tool.' . $key . '.desc'),
            'keywords'  => $it['kw'],
            'available' => $available,
        ];
    }
    return ['handlers' => $handlers, 'tools' => $tools];
}
