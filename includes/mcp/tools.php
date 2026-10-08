<?php
/**
 * The MCP server's tools (3.2.0) - api/mcp/index.php is the protocol, this is
 * what it offers.
 *
 * 🔑 A THIN ADAPTER OVER THE WARBOT REGISTRY (includes/warbot/tools.php), as
 * that file always intended: the same names, descriptions, schemas and plain PHP
 * handlers, plus a few tools only an MCP client gets (Projects, below).
 *
 * 🔑 READ-ONLY, EVERY ONE. Nothing here writes. An assistant reading tickets
 * can be fed instructions by whoever wrote the ticket; read-only makes that
 * embarrassing rather than dangerous. Writes are a later, separate decision.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * 🔴 THE GATE THIS FILE ADDS, AND WHY WARBOT DID NOT NEED IT
 *
 * Warbot answers in a war-room channel and checks only that the asker can open
 * the war room. Its tools are NOT company-scoped - its own header leaves "whose
 * data" open - and they do not check the module behind each answer. Exposed
 * as they are over MCP, an analyst limited to one company could read another
 * company's tickets through their assistant. So every tool here is declared
 * with:
 *
 *   module        the module whose data it reads - the key's analyst must be
 *                 able to open it (analystCanAccessModule);
 *   company_safe  true when the tool either reads install-wide data (services,
 *                 suppliers, the rota) or scopes itself (Knowledge visibility,
 *                 the Projects tools below). A tool that is NOT company-safe is
 *                 offered on a multi-company install only to a key that sees
 *                 every company (company_scope null) - never narrowed by guess.
 *                 company_scope here is the EFFECTIVE one (mcpEffectiveScope):
 *                 the key's companies narrowed to its analyst's.
 *
 * Plus the key's own API permission (mcp.read) and Warbot's capability rule. A
 * tool the key may not run is not LISTED, rather than listed and refused: an
 * assistant told about a tool it cannot use keeps reaching for it.
 */

require_once __DIR__ . '/../warbot/tools.php';
require_once __DIR__ . '/../tenancy.php';
require_once __DIR__ . '/../service_context.php';   // ServiceError

/** name => [module, company_safe] for the Warbot tools. A Warbot tool not listed here is not offered. */
function mcpWarbotToolMap(): array
{
    return [
        'open_incidents'   => ['tickets',        false],
        'ticket_spike'     => ['tickets',        false],
        'related_tickets'  => ['tickets',        false],
        'on_call'          => ['tickets',        true],    // the rota is install-wide
        'service_status'   => ['service-status', true],    // services are install-wide
        'recent_changes'   => ['changes',        false],
        'asset_lookup'     => ['assets',         false],
        'impact_of'        => ['cmdb',           false],
        'known_errors'     => ['problems',       false],
        'supplier_contact' => ['contracts',      true],    // suppliers and contracts are install-wide
        'morning_checks'   => ['morning-checks', true],
        // NOT search_chat (security review, 2026-10-09): the war room's search
        // includes the analyst's DIRECT MESSAGES and private channels, and an
        // administrator can make a key act as any analyst - so a key would read
        // somebody's private conversations, which no screen lets an admin do.
        // NOT Warbot's search_knowledge either: it scopes by the analyst's ACTIVE
        // company (a session value an API call does not have) and ignores the
        // key's companies. MCP has its own, below, through KnowledgeViewer::forApiKey.
    ];
}

/** Every tool: [description, schema, module, company_safe, capability, handler(conn, args, analystId, apiKey)]. */
function mcpTools(): array
{
    static $tools = null;
    if ($tools !== null) return $tools;
    $tools = [];
    $warbot = warbotTools();
    foreach (mcpWarbotToolMap() as $name => [$module, $safe]) {
        if (!isset($warbot[$name])) continue;
        $w = $warbot[$name];
        $tools[$name] = ['description' => $w['description'], 'schema' => $w['schema'], 'module' => $module, 'company_safe' => $safe,
            // The handler itself, NOT warbotRunTool(): that returns an exception's
            // message as the answer ('That lookup failed: SQLSTATE...'), which would
            // hand table and column names to the client. mcpRunTool() catches,
            // logs and answers generically. Capability is checked by mcpToolAllowed().
            'capability' => $w['capability'], 'handler' => fn($conn, $args, $analystId, $key) => call_user_func($w['handler'], $conn, $args, $analystId)];
    }
    $project = ['type' => 'string', 'description' => 'The project: its code (PRJ-0042), its id, or enough of its name to be unique.'];
    $tools += [
        'search_knowledge' => [
            'description' => 'Search published Knowledge article titles. Use it for "is there a runbook for X" or "how do we do Y". Titles only - open the article in Knowledge to read it.',
            'schema' => ['type' => 'object', 'properties' => ['query' => ['type' => 'string', 'description' => 'Words in the title.']], 'required' => ['query']],
            'module' => 'knowledge', 'company_safe' => true, 'capability' => null, 'handler' => 'mcpToolSearchKnowledge',
        ],
        'list_projects' => [
            'description' => 'List projects with their health (green / amber / red, worked out from the plan), status, progress, target finish, project manager and any tolerance exceptions. '
                           . 'Use it for "which projects are off track", "what is Sam running", "what finishes this month".',
            'schema' => ['type' => 'object', 'properties' => [
                'status' => ['type' => 'string', 'description' => 'live (proposed + active, the default), all, or one of proposed, active, on_hold, closed, cancelled.'],
                'health' => ['type' => 'string', 'description' => 'green, amber or red - worked out, as the portfolio shows it.'],
                'q'      => ['type' => 'string', 'description' => 'Words in the name, summary or goal.'],
                'limit'  => ['type' => 'integer', 'description' => 'How many (default 25, max 100).'],
            ], 'required' => []],
            'module' => 'projects', 'company_safe' => true, 'capability' => null, 'handler' => 'mcpToolListProjects',
        ],
        'project_overview' => [
            'description' => 'One project in full: goal, health and why, progress, dates, its stages with their gates, open risks and issues, budget against actual, '
                           . 'linked changes not yet approved, and recent history. Use it before writing a status report or answering "how is X going".',
            'schema' => ['type' => 'object', 'properties' => ['project' => $project], 'required' => ['project']],
            'module' => 'projects', 'company_safe' => true, 'capability' => null, 'handler' => 'mcpToolProjectOverview',
        ],
        'project_raid' => [
            'description' => 'A project\'s RAID log - risks (with probability x impact score), assumptions, issues, decisions and lessons.',
            'schema' => ['type' => 'object', 'properties' => [
                'project' => $project,
                'type'    => ['type' => 'string', 'description' => 'risk, assumption, issue, decision or lesson. Omit for all.'],
                'status'  => ['type' => 'string', 'description' => 'open (default), closed or all.'],
            ], 'required' => ['project']],
            'module' => 'projects', 'company_safe' => true, 'capability' => null, 'handler' => 'mcpToolProjectRaid',
        ],
        'project_budget' => [
            'description' => 'A project\'s budget: planned against actual in its own currency, each budget line, and the labour from time logged on its tasks. It does not list hourly rates; with labour costed per analyst and only one person\'s time logged, cost divided by hours is that person\'s rate - as on the Budget tab.',
            'schema' => ['type' => 'object', 'properties' => ['project' => $project], 'required' => ['project']],
            'module' => 'projects', 'company_safe' => true, 'capability' => null, 'handler' => 'mcpToolProjectBudget',
        ],
        'project_tasks' => [
            'description' => 'A project\'s tasks - overdue first, then by due date - with stage, status and who has them.',
            'schema' => ['type' => 'object', 'properties' => [
                'project'   => $project,
                'open_only' => ['type' => 'boolean', 'description' => 'Only tasks not yet done (default true).'],
            ], 'required' => ['project']],
            'module' => 'projects', 'company_safe' => true, 'capability' => null, 'handler' => 'mcpToolProjectTasks',
        ],
    ];
    return $tools;
}

/**
 * 🔴 THE KEY'S EFFECTIVE COMPANIES (security review, 2026-10-09). A key's own
 * company_scope is null when an administrator left it on "all companies" - and
 * the REST API reads null as EVERY company, even when the analyst the key acts
 * as is limited to one. The MCP server promises "you see what that analyst may
 * see", so it intersects: the key's companies AND the analyst's.
 *
 *   single-company install                     -> null (nothing to narrow)
 *   analyst who may see every company          -> the key's own scope (null = all)
 *   analyst limited to some companies          -> key scope ∩ theirs (null key = theirs)
 *
 * The endpoint stores the result back in $apiKey['company_scope'] straight after
 * authenticating, so every tool and helper below reads the narrowed value.
 */
function mcpEffectiveScope(PDO $conn, array $apiKey): ?array
{
    // apiAuthenticate() now narrows every key this way (api/v1/lib/auth.php), so
    // this is the same rule applied again - harmless, and it keeps the MCP server
    // safe if it is ever authenticated by another route.
    return apiEffectiveCompanyScope($conn, (int)$apiKey['analyst_id'], $apiKey['company_scope'] ?? null);
}

/** May this key run this tool? (permission is checked once, by the endpoint) */
function mcpToolAllowed(PDO $conn, array $apiKey, array $t): bool
{
    $analystId = (int)$apiKey['analyst_id'];
    if (!analystCanAccessModule($conn, $analystId, $t['module'])) return false;
    if ($t['capability'] !== null && !analystHasCapability($conn, $analystId, $t['capability'])) return false;
    if (!$t['company_safe'] && isMultiTenant($conn) && $apiKey['company_scope'] !== null) return false;
    return true;
}

/** tools/list: what this key may use, in MCP's shape. */
function mcpToolsFor(PDO $conn, array $apiKey): array
{
    $out = [];
    foreach (mcpTools() as $name => $t) {
        if (!mcpToolAllowed($conn, $apiKey, $t)) continue;
        $schema = $t['schema'];
        if (isset($schema['properties']) && is_array($schema['properties']) && !$schema['properties']) $schema['properties'] = new stdClass();
        $out[] = [
            'name'        => $name,
            'description' => $t['description'],
            'inputSchema' => $schema,
            'annotations' => ['readOnlyHint' => true, 'openWorldHint' => false],
        ];
    }
    return $out;
}

/**
 * tools/call. Returns [text, isError]. An unknown or forbidden tool is null -
 * the endpoint answers that as a protocol error, not as tool output.
 */
function mcpRunTool(PDO $conn, array $apiKey, string $name, array $args): ?array
{
    $t = mcpTools()[$name] ?? null;
    if (!$t || !mcpToolAllowed($conn, $apiKey, $t)) return null;
    try {
        return [(string)call_user_func($t['handler'], $conn, $args, (int)$apiKey['analyst_id'], $apiKey), false];
    } catch (ServiceError $e) {
        return [$e->getMessage(), true];
    } catch (Throwable $e) {
        error_log('MCP tool ' . $name . ': ' . $e->getMessage());
        return ['That lookup failed.', true];
    }
}

/* ══════════════════════════════════════════════════════════════════════════
   PROJECTS - company-scoped by the key, read through the Projects code.
   ══════════════════════════════════════════════════════════════════════════ */

function mcpProjectCtx(array $apiKey): ActorContext
{
    require_once __DIR__ . '/../service_context.php';
    return new ActorContext((int)$apiKey['analyst_id'], $apiKey['company_scope'] ?? null, 'api', 'en', (string)($apiKey['analyst_name'] ?? ''));
}

/** " AND p.tenant_id ..." for the key's companies (NULL company = the Default one). */
function mcpProjectScopeSql(PDO $conn, array $apiKey): array
{
    if (!isMultiTenant($conn) || $apiKey['company_scope'] === null) return ['', []];
    $ids = array_map('intval', $apiKey['company_scope']);
    if (!$ids) return [' AND 1 = 0', []];
    $ph = implode(',', array_fill(0, count($ids), '?'));
    return in_array((int)getDefaultTenantId($conn), $ids, true)
        ? [" AND (p.tenant_id IN ($ph) OR p.tenant_id IS NULL)", $ids]
        : [" AND p.tenant_id IN ($ph)", $ids];
}

/** Decorated project rows (health, progress, exceptions, budget) for a WHERE. */
function mcpProjectRows(PDO $conn, array $apiKey, string $where, array $args, int $limit): array
{
    require_once __DIR__ . '/../projects/read.php';
    require_once __DIR__ . '/../projects/budget.php';
    [$sSql, $sArgs] = mcpProjectScopeSql($conn, $apiKey);
    $st = $conn->prepare("SELECT p.*, a.full_name AS owner_name,
                                 (SELECT s.name FROM project_stages s WHERE s.project_id = p.id AND s.status = 'active' ORDER BY s.position, s.id LIMIT 1) AS active_stage_name,
                                 " . projectExceptionColumns($conn) . "
                            FROM projects p LEFT JOIN analysts a ON a.id = p.owner_analyst_id
                           WHERE $where $sSql
                        ORDER BY FIELD(p.status, 'active', 'proposed', 'on_hold', 'closed', 'cancelled'), p.target_end_date IS NULL, p.target_end_date, p.name
                           LIMIT " . max(1, min(500, $limit)));
    $st->execute(array_merge($args, $sArgs));
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) return [];
    $stats = projectTaskStats($conn, array_map(fn($r) => (int)$r['id'], $rows));
    $cfg = projectHealthConfig($conn);
    return array_map(fn($r) => projectDecorate($r, $stats[(int)$r['id']] ?? [], $cfg) + ['_budget' => $stats[(int)$r['id']]['budget'] ?? null], $rows);
}

/** Find one project the key may see, by code, id or a unique part of its name. */
function mcpFindProject(PDO $conn, array $apiKey, $ref): array
{
    $ref = trim((string)$ref);
    if ($ref === '') throw new ServiceError('validation', 'missing_field', 'Say which project: its code (PRJ-0042), id or name.');
    if (preg_match('/^(?:PRJ-?)?0*(\d+)$/i', $ref, $m)) {
        $rows = mcpProjectRows($conn, $apiKey, 'p.id = ?', [(int)$m[1]], 1);
    } else {
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $ref) . '%';
        $rows = mcpProjectRows($conn, $apiKey, 'p.name LIKE ?', [$like], 6);
        if (count($rows) > 1) {
            $exact = array_values(array_filter($rows, fn($r) => mb_strtolower($r['name']) === mb_strtolower($ref)));
            if (count($exact) === 1) return $exact[0];
            throw new ServiceError('validation', 'ambiguous', 'Several projects match "' . $ref . '": '
                . implode(', ', array_map(fn($r) => $r['code'] . ' ' . $r['name'], $rows)) . '. Use the code.');
        }
    }
    if (!$rows) throw new ServiceError('not_found', 'not_found', 'No project "' . $ref . '" that you can see.');
    return $rows[0];
}

function mcpMoney(?float $v, string $cur): string
{
    return $v === null ? '-' : $cur . ' ' . number_format($v, 2);
}

/** Why a project is the colour it is, in words. */
function mcpProjectWhy(array $p): string
{
    $why = [];
    if ($p['health'] !== 'auto') $why[] = 'set by hand' . ($p['health_note'] ? ': ' . $p['health_note'] : '');
    foreach ($p['exceptions'] as $e) {
        if ($e['kind'] === 'risk') $why[] = 'a risk scores ' . $e['score'] . ' (tolerance ' . $e['allowed'] . ')';
        elseif ($e['kind'] === 'cost') $why[] = $e['over_pct'] . '% over budget (tolerance ' . $e['allowed'] . '%)';
        else $why[] = $e['late'] . ' days late' . ($e['kind'] === 'stage_time' ? ' on the current stage' : '') . ' (tolerance ' . $e['allowed'] . ')';
    }
    if ($p['task_overdue'] > 0) $why[] = $p['task_overdue'] . ' overdue task(s)';
    if (!empty($p['ticket_spike'])) $why[] = $p['tickets_7d'] . ' linked tickets raised in the last 7 days';
    return implode('; ', $why);
}

function mcpProjectLine(array $p): string
{
    $why = mcpProjectWhy($p);
    return sprintf('%s %s [%s, %s, %d%% of %d task(s) done%s%s]%s',
        $p['code'], $p['name'], $p['status'], $p['shown_health'] ?? 'no health', $p['progress'], $p['task_total'],
        $p['target_end_date'] ? ', target ' . $p['target_end_date'] : '', $p['owner_name'] ? ', led by ' . $p['owner_name'] : '',
        $why !== '' ? ' - ' . $why : '');
}

function mcpToolListProjects(PDO $conn, array $args, int $analystId, array $apiKey): string
{
    require_once __DIR__ . '/../projects/methodologies.php';
    $status = trim((string)($args['status'] ?? 'live')) ?: 'live';
    $where = '1=1'; $wArgs = [];
    if ($status === 'live') $where = "p.status IN ('proposed', 'active')";
    elseif ($status !== 'all') {
        if (!in_array($status, projectStatuses(), true)) return 'Unknown status "' . $status . '". Use live, all, or one of ' . implode(', ', projectStatuses()) . '.';
        $where = 'p.status = ?'; $wArgs[] = $status;
    }
    if (trim((string)($args['q'] ?? '')) !== '') {
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], trim($args['q'])) . '%';
        $where .= ' AND (p.name LIKE ? OR p.summary LIKE ? OR p.goal LIKE ?)';
        array_push($wArgs, $like, $like, $like);
    }
    $limit = max(1, min(100, (int)($args['limit'] ?? 25)));
    $rows = mcpProjectRows($conn, $apiKey, $where, $wArgs, 500);
    $health = trim((string)($args['health'] ?? ''));
    if ($health !== '') $rows = array_values(array_filter($rows, fn($p) => $p['shown_health'] === $health));
    if (!$rows) return 'No projects match.';
    $lines = [count($rows) . ' project(s)' . (count($rows) > $limit ? ', the first ' . $limit . ':' : ':')];
    foreach (array_slice($rows, 0, $limit) as $p) $lines[] = '- ' . mcpProjectLine($p);
    return implode("\n", $lines);
}

function mcpToolProjectOverview(PDO $conn, array $args, int $analystId, array $apiKey): string
{
    require_once __DIR__ . '/../services/project_tools.php';
    require_once __DIR__ . '/../projects/links.php';
    $p = mcpFindProject($conn, $apiKey, $args['project'] ?? '');
    $pid = (int)$p['id'];
    $cur = projectCurrencyOf($conn, $p);
    $out = [mcpProjectLine($p)];
    if ($p['goal']) $out[] = 'Goal: ' . $p['goal'];
    if ($p['summary']) $out[] = 'Summary: ' . mb_substr($p['summary'], 0, 600);
    $out[] = 'Runs as: ' . $p['methodology'] . '. Start ' . ($p['start_date'] ?: '-') . ', target finish ' . ($p['target_end_date'] ?: '-') . '.';

    $st = $conn->prepare("SELECT name, kind, status, start_date, end_date, gate_decision, gate_notes FROM project_stages WHERE project_id = ? ORDER BY position, id");
    $st->execute([$pid]);
    $stages = $st->fetchAll(PDO::FETCH_ASSOC);
    if ($stages) {
        $out[] = 'Stages:';
        foreach ($stages as $s) $out[] = sprintf('- %s (%s) %s%s%s', $s['name'], $s['kind'], $s['status'],
            $s['end_date'] ? ', ends ' . $s['end_date'] : '', $s['gate_decision'] ? ', gate: ' . $s['gate_decision'] . ($s['gate_notes'] ? ' - ' . $s['gate_notes'] : '') : '');
    }
    $raid = array_filter(ProjectToolsService::raid($conn, $pid), fn($r) => $r['status'] === 'open' && in_array($r['type'], ['risk', 'issue'], true));
    if ($raid) {
        $out[] = 'Open risks and issues:';
        foreach (array_slice($raid, 0, 8) as $r) $out[] = sprintf('- %s: %s%s%s', $r['type'], $r['title'], $r['score'] !== null ? ' (score ' . (int)$r['score'] . ')' : '', $r['owner_name'] ? ', owner ' . $r['owner_name'] : '');
    }
    $b = $p['_budget'] ?? null;
    if ($b && ($b['planned'] > 0 || $b['actual'] > 0)) $out[] = 'Budget: ' . mcpMoney($b['planned'], $cur) . ' planned, ' . mcpMoney($b['actual'], $cur) . ' spent.';
    if (analystCanAccessModule($conn, $analystId, 'changes')) {
        $unapproved = projectUnapprovedChanges($conn, $pid);
        if ($unapproved) $out[] = count($unapproved) . ' linked change(s) not yet approved: ' . implode(', ', array_map(fn($c) => $c['label'] . ' ' . $c['title'], $unapproved)) . '.';
    }
    $h = $conn->prepare("SELECT pa.field_name, pa.old_value, pa.new_value, pa.created_datetime, an.full_name FROM project_audit pa LEFT JOIN analysts an ON an.id = pa.analyst_id
                          WHERE pa.project_id = ? ORDER BY pa.id DESC LIMIT 8");
    $h->execute([$pid]);
    // A history row can name a record in another module ("contract: Fibre circuit",
    // a raised ticket, a Knowledge article). Leave out the ones whose module the
    // analyst cannot open - the security review's finding 6.
    $hist = array_values(array_filter($h->fetchAll(PDO::FETCH_ASSOC), function ($r) use ($conn, $analystId) {
        if (in_array($r['field_name'], ['link_added', 'link_removed'], true)) {
            $kind = strtok((string)$r['new_value'] ?: (string)$r['old_value'], ':');
            return projectLinkKindAllowed($conn, $analystId, (string)$kind);
        }
        if ($r['field_name'] === 'raid_ticket_raised') return analystCanAccessModule($conn, $analystId, 'tickets');
        if ($r['field_name'] === 'raid_to_knowledge') return analystCanAccessModule($conn, $analystId, 'knowledge');
        if ($r['field_name'] === 'disruption_announced' || $r['field_name'] === 'disruption_withdrawn') return analystCanAccessModule($conn, $analystId, 'service-status');
        return true;
    }));
    if ($hist) {
        $out[] = 'Recent history:';
        foreach ($hist as $r) $out[] = sprintf('- %s %s %s%s', substr($r['created_datetime'], 0, 10), $r['full_name'] ?: 'Someone', str_replace('_', ' ', $r['field_name']), $r['new_value'] !== null ? ': ' . mb_substr($r['new_value'], 0, 120) : '');
    }
    return implode("\n", $out);
}

function mcpToolProjectRaid(PDO $conn, array $args, int $analystId, array $apiKey): string
{
    require_once __DIR__ . '/../services/project_tools.php';
    $p = mcpFindProject($conn, $apiKey, $args['project'] ?? '');
    $type = trim((string)($args['type'] ?? ''));
    $status = trim((string)($args['status'] ?? 'open')) ?: 'open';
    $rows = array_values(array_filter(ProjectToolsService::raid($conn, (int)$p['id']), fn($r) =>
        ($type === '' || $r['type'] === $type) && ($status === 'all' || $r['status'] === $status)));
    if (!$rows) return $p['code'] . ' has no ' . ($status === 'all' ? '' : $status . ' ') . ($type ?: 'RAID') . ' entries.';
    $lines = [$p['code'] . ' ' . $p['name'] . ' - ' . count($rows) . ' entr' . (count($rows) === 1 ? 'y' : 'ies') . ':'];
    foreach ($rows as $r) {
        $lines[] = sprintf('- [%s, %s] %s%s%s%s%s', $r['type'], $r['status'], $r['title'],
            $r['score'] !== null ? ' - score ' . (int)$r['score'] . ' (probability ' . (int)$r['probability'] . ' x impact ' . (int)$r['impact'] . ')' : '',
            $r['owner_name'] ? ', owner ' . $r['owner_name'] : '', $r['due_date'] ? ', review by ' . $r['due_date'] : '',
            $r['response_plan'] ? '. Plan: ' . mb_substr($r['response_plan'], 0, 300) : '');
    }
    return implode("\n", $lines);
}

function mcpToolProjectBudget(PDO $conn, array $args, int $analystId, array $apiKey): string
{
    $p = mcpFindProject($conn, $apiKey, $args['project'] ?? '');
    if (!projectBudgetReady($conn)) return 'Budgets are not switched on yet (Database Verification).';
    $d = projectBudgetDetail($conn, $p, $analystId);
    $cur = $d['currency'];
    $lines = [sprintf('%s %s budget (%s): %s planned, %s spent, %s remaining.', $p['code'], $p['name'], $cur,
        mcpMoney($d['planned'], $cur), mcpMoney($d['actual'], $cur), mcpMoney($d['remaining'], $cur))];
    $lab = $d['labour'];
    $lines[] = 'Labour: ' . round($lab['minutes'] / 60, 1) . ' hours logged'
        . ($lab['cost'] !== null ? ', costed at ' . mcpMoney($lab['cost'], $cur) : ' (shown in hours, not priced)')
        . ($lab['unpriced_minutes'] > 0 ? '; ' . round($lab['unpriced_minutes'] / 60, 1) . ' hours have no rate in ' . $cur : '') . '.';
    foreach ($d['lines'] as $l) {
        $lines[] = sprintf('- %s (%s): planned %s, actual %s%s', $l['title'], $l['category'], mcpMoney($l['planned'], $cur),
            $l['currency_mismatch'] ? 'not counted - its contract is in ' . $l['contract']['currency'] : mcpMoney($l['actual'], $cur),
            $l['actual_source'] === 'contract' ? ' (the contract\'s value)' : '');
    }
    return implode("\n", $lines);
}

function mcpToolProjectTasks(PDO $conn, array $args, int $analystId, array $apiKey): string
{
    $p = mcpFindProject($conn, $apiKey, $args['project'] ?? '');
    $openOnly = !array_key_exists('open_only', $args) || (bool)$args['open_only'];
    $st = $conn->prepare("SELECT t.title, t.due_date, ts.name AS status, COALESCE(ts.is_closed, 0) AS done, an.full_name AS assignee, s.name AS stage
                            FROM tasks t
                       LEFT JOIN task_statuses ts ON ts.id = t.status_id
                       LEFT JOIN analysts an ON an.id = t.assigned_analyst_id
                       LEFT JOIN project_stages s ON s.id = t.project_stage_id
                           WHERE t.project_id = ? AND t.parent_task_id IS NULL" . ($openOnly ? ' AND COALESCE(ts.is_closed, 0) = 0' : '') . "
                        ORDER BY (t.due_date IS NOT NULL AND t.due_date < UTC_DATE() AND COALESCE(ts.is_closed, 0) = 0) DESC, t.due_date IS NULL, t.due_date, t.id
                           LIMIT 100");
    $st->execute([(int)$p['id']]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) return $p['code'] . ' has no ' . ($openOnly ? 'open ' : '') . 'tasks.';
    $today = gmdate('Y-m-d');
    $lines = [$p['code'] . ' ' . $p['name'] . ' - ' . count($rows) . ($openOnly ? ' open' : '') . ' task(s):'];
    foreach ($rows as $r) {
        $late = !$r['done'] && $r['due_date'] && $r['due_date'] < $today;
        $lines[] = sprintf('- %s%s [%s]%s%s', $late ? 'OVERDUE ' : '', $r['title'], $r['status'] ?: '?',
            $r['due_date'] ? ', due ' . $r['due_date'] : '', ($r['assignee'] ? ', ' . $r['assignee'] : ', unassigned') . ($r['stage'] ? ', ' . $r['stage'] : ''));
    }
    return implode("\n", $lines);
}

/* ══════════════════════════════════════════════════════════════════════════
   KNOWLEDGE - by the KEY's companies (KnowledgeViewer::forApiKey), not the
   analyst's active company, which an API call does not have.
   ══════════════════════════════════════════════════════════════════════════ */
function mcpToolSearchKnowledge(PDO $conn, array $args, int $analystId, array $apiKey): string
{
    require_once __DIR__ . '/../knowledge/visibility.php';
    $q = trim((string)($args['query'] ?? ''));
    if ($q === '') return 'Give me something to search for.';
    $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
    [$visSql, $visParams] = knowledgeVisibilitySql($conn, KnowledgeViewer::forApiKey($conn, $apiKey), '', ['lifecycle' => 'live']);
    $st = $conn->prepare("SELECT id, title FROM knowledge_articles WHERE title LIKE ? ESCAPE '\\\\'" . $visSql . " ORDER BY view_count DESC, title LIMIT 8");
    $st->execute(array_merge([$like], $visParams));
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) return "No published article title matches \"$q\".";
    $lines = [count($rows) . " article(s) matching \"$q\":"];
    foreach ($rows as $r) $lines[] = sprintf('- #%d %s', $r['id'], $r['title']);
    $lines[] = 'Titles only - open the article in Knowledge to read it.';
    return implode("\n", $lines);
}