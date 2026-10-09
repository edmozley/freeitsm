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
            'description' => 'One project in full: goal, health and why, progress, dates, its stages with their gates, its milestones (reached, missed or due), open risks and issues, budget against actual, '
                           . 'linked changes not yet approved, and recent history. Use it before writing a status report or answering "how is X going".',
            'schema' => ['type' => 'object', 'properties' => ['project' => $project], 'required' => ['project']],
            'module' => 'projects', 'company_safe' => true, 'capability' => null, 'handler' => 'mcpToolProjectOverview',
        ],
        'project_raid' => [
            'description' => 'A project\'s RAID log - risks (with probability x impact score), assumptions, issues, dependencies, decisions (who decided, when and why) and lessons, with what is escalated.',
            'schema' => ['type' => 'object', 'properties' => [
                'project' => $project,
                'type'    => ['type' => 'string', 'description' => 'risk, assumption, issue, dependency, decision or lesson. Omit for all.'],
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
        'project_dates' => [
            'description' => 'Project dates coming up across every project - stage ends, milestones and target finishes in the next N days - and milestones already missed. Use it for "is any project doing something big this week".',
            'schema' => ['type' => 'object', 'properties' => ['days' => ['type' => 'integer', 'description' => 'How far ahead (default 14, max 90).']], 'required' => []],
            'module' => 'projects', 'company_safe' => true, 'capability' => null, 'handler' => 'mcpToolProjectDates',
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
   PROJECTS - company-scoped by the key. The answers themselves live in
   includes/projects/assistant.php, shared with Warbot (3.3.0); only the
   scope (the key's effective companies) and the budget are the MCP server's.
   ══════════════════════════════════════════════════════════════════════════ */

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

function mcpToolListProjects(PDO $conn, array $args, int $analystId, array $apiKey): string
{
    require_once __DIR__ . '/../projects/assistant.php';
    return projectAssistList($conn, mcpProjectScopeSql($conn, $apiKey), $args);
}

function mcpToolProjectOverview(PDO $conn, array $args, int $analystId, array $apiKey): string
{
    require_once __DIR__ . '/../projects/assistant.php';
    return projectAssistOverview($conn, mcpProjectScopeSql($conn, $apiKey), $analystId, $args, true);
}

function mcpToolProjectRaid(PDO $conn, array $args, int $analystId, array $apiKey): string
{
    require_once __DIR__ . '/../projects/assistant.php';
    return projectAssistRaid($conn, mcpProjectScopeSql($conn, $apiKey), $args);
}

function mcpToolProjectTasks(PDO $conn, array $args, int $analystId, array $apiKey): string
{
    require_once __DIR__ . '/../projects/assistant.php';
    return projectAssistTasks($conn, mcpProjectScopeSql($conn, $apiKey), $args);
}

function mcpToolProjectDates(PDO $conn, array $args, int $analystId, array $apiKey): string
{
    require_once __DIR__ . '/../projects/assistant.php';
    return projectAssistDates($conn, mcpProjectScopeSql($conn, $apiKey), $args);
}

/** The budget stays MCP-only: Warbot answers in a channel everyone in it can read. */
function mcpToolProjectBudget(PDO $conn, array $args, int $analystId, array $apiKey): string
{
    require_once __DIR__ . '/../projects/assistant.php';
    require_once __DIR__ . '/../projects/budget.php';
    $p = projectAssistFind($conn, mcpProjectScopeSql($conn, $apiKey), $args['project'] ?? '');
    if (!projectBudgetReady($conn)) return 'Budgets are not switched on yet (Database Verification).';
    $d = projectBudgetDetail($conn, $p, $analystId);
    $cur = $d['currency'];
    $lines = [sprintf('%s %s budget (%s): %s planned, %s spent, %s remaining.', $p['code'], $p['name'], $cur,
        projectAssistMoney($d['planned'], $cur), projectAssistMoney($d['actual'], $cur), projectAssistMoney($d['remaining'], $cur))];
    $lab = $d['labour'];
    $lines[] = 'Labour: ' . round($lab['minutes'] / 60, 1) . ' hours logged'
        . ($lab['cost'] !== null ? ', costed at ' . projectAssistMoney($lab['cost'], $cur) : ' (shown in hours, not priced)')
        . ($lab['unpriced_minutes'] > 0 ? '; ' . round($lab['unpriced_minutes'] / 60, 1) . ' hours have no rate in ' . $cur : '') . '.';
    foreach ($d['lines'] as $l) {
        $lines[] = sprintf('- %s (%s): planned %s, actual %s%s', $l['title'], $l['category'], projectAssistMoney($l['planned'], $cur),
            $l['currency_mismatch'] ? 'not counted - its contract is in ' . $l['contract']['currency'] : projectAssistMoney($l['actual'], $cur),
            $l['actual_source'] === 'contract' ? ' (the contract\'s value)' : '');
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