<?php
/**
 * Warbot's tools — the registry.
 *
 * 🔑 THIS FILE IS THE PRODUCT, NOT THE BOT. Each tool is declared once: a name, a
 * description the model reads, a JSON schema, the capability required to run it,
 * and a plain PHP handler. Warbot is one consumer. An MCP server is a second one,
 * and it is a thin adapter over this array rather than a rewrite — which is why
 * the registry came first and the protocol later.
 *
 * 🔑 THE HANDLERS NEED NO INTERNET. That is the whole design. The war room exists
 * for the day the internet is down, so a bot whose every answer requires a remote
 * model would be useless in exactly the situation it was built for. Only the
 * MODEL needs the network; the HANDS are local SQL. When the provider cannot be
 * reached, Warbot falls back to slash commands that run these same handlers
 * directly — see warbot.php. Degraded, but not dead.
 *
 * ⚠️ READ-ONLY, ALL OF THEM. Warbot reads chat, so anybody in the room can type
 * instructions at it, and the moment it reads ticket content a customer can plant
 * instructions in an email. Read-only makes prompt injection embarrassing rather
 * than dangerous. Ed was already wary of letting the CMDB graph take actions;
 * the same instinct applies harder to something with a chat box.
 *
 * ⚠️ WHAT THESE MAY RETURN, AND WHY IT IS NARROW. Warbot answers IN A CHANNEL, so
 * every member sees the reply. That makes the audience for any answer "everyone
 * in this room", not "the person who asked". So these return OPERATIONAL facts —
 * counts, states, names of services, hostnames, what changed — and deliberately
 * NOT ticket bodies, requester details, notes or anything else that belongs to a
 * person. Anything of that kind needs a one-to-one conversation, which is
 * outstanding work rather than an oversight.
 *
 * ⚠️ EVERY COLUMN NAME BELOW WAS READ OUT OF information_schema, NOT REMEMBERED.
 * Four of five guessed column names were wrong the last time this was done from
 * memory (see the Forms lookup fields work), and a wrong column here reads as
 * "no results" rather than as an error — the failure mode is a bot that
 * confidently says nothing is wrong.
 */

require_once __DIR__ . '/../capabilities.php';
require_once __DIR__ . '/../rbac.php';
require_once __DIR__ . '/../knowledge/visibility.php';

/**
 * @return array<string,array{description:string,schema:array,capability:?string,handler:callable}>
 */
function warbotTools(): array
{
    return [

        /* ── what is on fire right now ─────────────────────────────────────── */
        'open_incidents' => [
            'description' => 'Count and list the open tickets at a given priority, newest first. '
                           . 'Use this to answer "how many P1s are open" or "what is outstanding".',
            'schema' => [
                'type' => 'object',
                'properties' => [
                    'priority' => ['type' => 'string', 'description' => 'Priority name, e.g. Critical, Urgent, High. Omit for all open tickets.'],
                    'limit'    => ['type' => 'integer', 'description' => 'How many to list (default 10, max 25).'],
                ],
                'required' => [],
            ],
            'capability' => null,           // module access to war-room is enough
            'handler'    => 'warbotToolOpenIncidents',
        ],

        /* ── is the thing the business is asking about actually down ───────── */
        'service_status' => [
            'description' => 'The current state of published services and any open service-status incidents. '
                           . 'Use this for "is the VPN down", "what is degraded", "what are we telling customers".',
            'schema' => ['type' => 'object', 'properties' => new stdClass(), 'required' => []],
            'capability' => null,
            'handler'    => 'warbotToolServiceStatus',
        ],

        /* ── THE incident question ─────────────────────────────────────────── */
        'recent_changes' => [
            'description' => 'Changes whose work or outage window falls in the last N days. '
                           . 'Use this for "what changed", "did anything go out this morning", '
                           . 'which is usually the first useful question in an incident.',
            'schema' => [
                'type' => 'object',
                'properties' => [
                    'days' => ['type' => 'integer', 'description' => 'How far back to look (default 2, max 30).'],
                ],
                'required' => [],
            ],
            'capability' => null,
            'handler'    => 'warbotToolRecentChanges',
        ],

        /* ── who do I wake up ──────────────────────────────────────────────── */
        'on_call' => [
            'description' => 'Who is on call today, and who is on shift, from the rota.',
            'schema' => [
                'type' => 'object',
                'properties' => [
                    'date' => ['type' => 'string', 'description' => 'YYYY-MM-DD. Defaults to today.'],
                ],
                'required' => [],
            ],
            'capability' => null,
            'handler'    => 'warbotToolOnCall',
        ],

        /* ── the box somebody just named in the chat ───────────────────────── */
        'asset_lookup' => [
            'description' => 'Find a machine by hostname, asset tag or service tag, and return its '
                           . 'operating system, status, location and warranty date.',
            'schema' => [
                'type' => 'object',
                'properties' => [
                    'query' => ['type' => 'string', 'description' => 'Hostname, asset tag or service tag; a partial name works.'],
                ],
                'required' => ['query'],
            ],
            'capability' => null,
            'handler'    => 'warbotToolAssetLookup',
        ],

        /* ── what else falls over if this does ─────────────────────────────── */
        'impact_of' => [
            'description' => 'What depends on a CMDB object — the blast radius if it is down. '
                           . 'Use this for "what else does this affect".',
            'schema' => [
                'type' => 'object',
                'properties' => [
                    'name' => ['type' => 'string', 'description' => 'Name of the configuration item.'],
                ],
                'required' => ['name'],
            ],
            'capability' => null,
            'handler'    => 'warbotToolImpactOf',
        ],

        /* ── the highest-value question in an incident ─────────────────────── */
        // Problem Management holds root causes and workarounds that nobody reads
        // at 3am because nobody thinks to open the module. Warbot can.
        'known_errors' => [
            'description' => 'Search Problem Management for known errors, root causes and workarounds. '
                           . 'Use this for "have we seen this before", "is there a workaround", '
                           . '"is this a known error" — ask it EARLY, before diagnosing from scratch.',
            'schema' => [
                'type' => 'object',
                'properties' => [
                    'query' => ['type' => 'string', 'description' => 'Words to look for in the problem title or description.'],
                ],
                'required' => ['query'],
            ],
            'capability' => null,
            'handler'    => 'warbotToolKnownErrors',
        ],

        /* ── the thing nobody in the room knows yet ─────────────────────────── */
        'ticket_spike' => [
            'description' => 'Compare how many tickets have been raised in the last hour against the '
                           . 'usual rate for the same hour on previous days. Use this to answer '
                           . '"are we seeing a spike", "how many people are affected", or to check '
                           . 'whether something is bigger than it looks.',
            'schema' => [
                'type' => 'object',
                'properties' => [
                    'minutes' => ['type' => 'integer', 'description' => 'Window to measure, default 60, max 720.'],
                ],
                'required' => [],
            ],
            'capability' => null,
            'handler'    => 'warbotToolTicketSpike',
        ],

        /* ── who do I ring at 3am ───────────────────────────────────────────── */
        'supplier_contact' => [
            'description' => 'Find a supplier, their contract, and the people to ring — direct dial, '
                           . 'switchboard, mobile and email. Use this for "who is our supplier for X", '
                           . '"what is the support number", "do we have a contract for this".',
            'schema' => [
                'type' => 'object',
                'properties' => [
                    'query' => ['type' => 'string', 'description' => 'Supplier name, or words from a contract title.'],
                ],
                'required' => ['query'],
            ],
            'capability' => null,
            'handler'    => 'warbotToolSupplierContact',
        ],

        /* ── the incident that was already flagged this morning ─────────────── */
        'morning_checks' => [
            'description' => 'The results of the morning checks for a given day, and which ones failed. '
                           . 'Use this for "did the checks pass this morning" — an incident at 09:30 is '
                           . 'often something the 08:00 checks already caught.',
            'schema' => [
                'type' => 'object',
                'properties' => [
                    'date' => ['type' => 'string', 'description' => 'YYYY-MM-DD. Defaults to today.'],
                ],
                'required' => [],
            ],
            'capability' => null,
            'handler'    => 'warbotToolMorningChecks',
        ],

        /* ── what did we already say in here ────────────────────────────────── */
        'search_chat' => [
            'description' => 'Search the war room conversations this person can see. Use this for '
                           . '"what did we say about X", "who mentioned the certificate", or when '
                           . 'somebody joins late and asks what has happened.',
            'schema' => [
                'type' => 'object',
                'properties' => [
                    'query' => ['type' => 'string', 'description' => 'Words to look for in the chat.'],
                ],
                'required' => ['query'],
            ],
            'capability' => null,
            'handler'    => 'warbotToolSearchChat',
        ],

        /* ── the duplicates and children ────────────────────────────────────── */
        'related_tickets' => [
            'description' => 'Tickets linked to a given ticket number — duplicates, children, related. '
                           . 'Use this to find out whether several reports are the same thing.',
            'schema' => [
                'type' => 'object',
                'properties' => [
                    'ticket' => ['type' => 'string', 'description' => 'Ticket number, e.g. ABC-123-45678.'],
                ],
                'required' => ['ticket'],
            ],
            'capability' => null,
            'handler'    => 'warbotToolRelatedTickets',
        ],

        /* ── have we seen this before ──────────────────────────────────────── */
        'search_knowledge' => [
            'description' => 'Search published knowledge base article titles for a phrase, and return '
                           . 'the matching titles. Use this for "is there a runbook for this".',
            'schema' => [
                'type' => 'object',
                'properties' => [
                    'query' => ['type' => 'string', 'description' => 'Words to look for in the title.'],
                ],
                'required' => ['query'],
            ],
            'capability' => null,
            'handler'    => 'warbotToolSearchKnowledge',
        ],

        /* ── projects (3.3.0) - is a project behind this, or about to cause one ── */
        // 🔑 These five carry 'module' => 'projects': only somebody who can open
        // Projects is offered them, and they answer only about the projects that
        // person can see (their active company, as on the portfolio). The answers
        // are includes/projects/assistant.php - the same text the MCP server gives -
        // WITHOUT the budget: a channel is read by everyone in it, and money is not
        // an operational fact.
        'list_projects' => [
            'description' => 'List projects with their health (green / amber / red, worked out from the plan), status, progress, target finish and project manager. '
                           . 'Use this for "which projects are off track" or "what projects are live".',
            'schema' => [
                'type' => 'object',
                'properties' => [
                    'status' => ['type' => 'string', 'description' => 'live (proposed + active, the default), all, or one of proposed, active, on_hold, closed, cancelled.'],
                    'health' => ['type' => 'string', 'description' => 'green, amber or red.'],
                    'q'      => ['type' => 'string', 'description' => 'Words in the name, summary or goal.'],
                ],
                'required' => [],
            ],
            'capability' => null,
            'module'     => 'projects',
            'handler'    => 'warbotToolListProjects',
        ],
        'project_overview' => [
            'description' => 'One project in full: health and why, progress, dates, stages and gates, milestones (reached, missed or due), open risks and issues, '
                           . 'linked changes not yet approved, and recent history. Use this for "how is the office move going".',
            'schema' => [
                'type' => 'object',
                'properties' => ['project' => ['type' => 'string', 'description' => 'Its code (PRJ-0042), id, or enough of its name to be unique.']],
                'required' => ['project'],
            ],
            'capability' => null,
            'module'     => 'projects',
            'handler'    => 'warbotToolProjectOverview',
        ],
        'project_raid' => [
            'description' => 'A project\'s RAID log - risks with their scores, assumptions, issues, decisions and lessons. Open ones unless asked.',
            'schema' => [
                'type' => 'object',
                'properties' => [
                    'project' => ['type' => 'string', 'description' => 'Its code (PRJ-0042), id, or enough of its name to be unique.'],
                    'type'    => ['type' => 'string', 'description' => 'risk, assumption, issue, decision or lesson. Omit for all.'],
                    'status'  => ['type' => 'string', 'description' => 'open (default), closed or all.'],
                ],
                'required' => ['project'],
            ],
            'capability' => null,
            'module'     => 'projects',
            'handler'    => 'warbotToolProjectRaid',
        ],
        'project_tasks' => [
            'description' => 'A project\'s tasks - overdue first, then by due date - with stage, status and who has them.',
            'schema' => [
                'type' => 'object',
                'properties' => [
                    'project'   => ['type' => 'string', 'description' => 'Its code (PRJ-0042), id, or enough of its name to be unique.'],
                    'open_only' => ['type' => 'boolean', 'description' => 'Only tasks not yet done (default true).'],
                ],
                'required' => ['project'],
            ],
            'capability' => null,
            'module'     => 'projects',
            'handler'    => 'warbotToolProjectTasks',
        ],
        'project_dates' => [
            'description' => 'Project dates coming up across every project - stage ends, milestones and target finishes in the next N days - and milestones already missed. '
                           . 'Use this for "is any project doing something big this week" - a go-live or a move is often what is behind an incident.',
            'schema' => [
                'type' => 'object',
                'properties' => ['days' => ['type' => 'integer', 'description' => 'How far ahead (default 14, max 90).']],
                'required' => [],
            ],
            'capability' => null,
            'module'     => 'projects',
            'handler'    => 'warbotToolProjectDates',
        ],
    ];
}

/**
 * May this analyst use this tool? The capability, and - for a tool that names
 * one (3.3.0, the Projects tools) - access to the module whose data it reads.
 * The older tools name no module and are unchanged: whether the war room is
 * install-wide is the open question in warbotToolSearchKnowledge() below.
 */
function warbotToolAllowed(PDO $conn, int $analystId, array $t): bool
{
    if ($t['capability'] !== null && !analystHasCapability($conn, $analystId, $t['capability'])) return false;
    if (!empty($t['module']) && !analystCanAccessModule($conn, $analystId, $t['module'])) return false;
    return true;
}

/**
 * The tools this analyst may actually use, in the shape aiProviderChatTools wants.
 * A capability they lack means the tool is not OFFERED at all, rather than offered
 * and then refused — a model told about a tool it cannot use will keep reaching
 * for it and narrate the failure to the room.
 */
function warbotToolsFor(PDO $conn, int $analystId): array
{
    $out = [];
    foreach (warbotTools() as $name => $t) {
        if (!warbotToolAllowed($conn, $analystId, $t)) continue;
        $out[] = ['name' => $name, 'description' => $t['description'], 'schema' => $t['schema']];
    }
    return $out;
}

/**
 * Run one tool. NEVER throws — a failure is returned as words, because "the CMDB
 * lookup failed" is something the model can usefully tell the room, whereas an
 * exception here would lose the whole answer.
 */
function warbotRunTool(PDO $conn, int $analystId, string $name, array $args): string
{
    $tools = warbotTools();
    if (!isset($tools[$name])) return 'No such tool.';
    $t = $tools[$name];
    if (!warbotToolAllowed($conn, $analystId, $t)) {
        return 'You do not have permission to use that.';
    }
    try {
        return (string) call_user_func($t['handler'], $conn, $args, $analystId);
    } catch (Throwable $e) {
        return 'That lookup failed: ' . $e->getMessage();
    }
}

/* ══════════════════════════════════════════════════════════════════════════
   HANDLERS — plain SQL, no network. Each returns text for the model to read.
   ══════════════════════════════════════════════════════════════════════════ */

function warbotToolOpenIncidents(PDO $conn, array $args, int $analystId): string
{
    $limit    = max(1, min(25, (int)($args['limit'] ?? 10)));
    $priority = trim((string)($args['priority'] ?? ''));

    $where  = ["t.deleted_datetime IS NULL", "(s.is_closed IS NULL OR s.is_closed = 0)"];
    $params = [];
    if ($priority !== '') {
        $where[] = "p.name = :prio";
        $params[':prio'] = $priority;
    }
    // What the model sees - a confidential ticket counts, but by number only (#62).
    require_once __DIR__ . '/../ticket_sensitivity.php';
    $sql = "SELECT t.ticket_number, " . ticketAiSubjectSql($conn, 't') . " AS subject, p.name AS priority, s.name AS status,
                   a.full_name AS assignee, t.created_datetime
              FROM tickets t
              LEFT JOIN ticket_statuses   s ON s.id = t.status_id
              LEFT JOIN ticket_priorities p ON p.id = t.priority_id
              LEFT JOIN analysts          a ON a.id = t.assigned_analyst_id
             WHERE " . implode(' AND ', $where) . "
             ORDER BY t.id DESC LIMIT $limit";
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $countSql = "SELECT COUNT(*) FROM tickets t
                   LEFT JOIN ticket_statuses s ON s.id = t.status_id
                   LEFT JOIN ticket_priorities p ON p.id = t.priority_id
                  WHERE " . implode(' AND ', $where);
    $cs = $conn->prepare($countSql);
    $cs->execute($params);
    $total = (int) $cs->fetchColumn();

    if ($total === 0) return 'No open tickets' . ($priority !== '' ? " at priority $priority" : '') . '.';

    $lines = [$total . ' open ticket(s)' . ($priority !== '' ? " at priority $priority" : '') . '. Most recent:'];
    foreach ($rows as $r) {
        $lines[] = sprintf('- %s [%s/%s] %s — %s',
            $r['ticket_number'], $r['priority'] ?: '?', $r['status'] ?: '?',
            $r['subject'], $r['assignee'] ? 'assigned to ' . $r['assignee'] : 'unassigned');
    }
    return implode("\n", $lines);
}

function warbotToolServiceStatus(PDO $conn, array $args, int $analystId): string
{
    $svc = $conn->query(
        "SELECT name FROM status_services WHERE is_active = 1 ORDER BY COALESCE(display_order,0), name"
    )->fetchAll(PDO::FETCH_COLUMN);

    // An "open" incident is one with no resolved timestamp AND a status that is
    // not flagged resolved — the two can disagree, and either alone would report
    // a resolved incident as live.
    $inc = $conn->query(
        "SELECT i.title, i.comment, st.name AS state, i.updated_datetime,
                (SELECT GROUP_CONCAT(s2.name ORDER BY s2.name SEPARATOR ', ')
                   FROM status_incident_services sis
                   JOIN status_services s2 ON s2.id = sis.service_id
                  WHERE sis.incident_id = i.id) AS services
           FROM status_incidents i
           LEFT JOIN service_incident_statuses st ON st.id = i.status_id
          WHERE i.resolved_datetime IS NULL
            AND (st.is_resolved IS NULL OR st.is_resolved = 0)
          ORDER BY i.id DESC LIMIT 10"
    )->fetchAll(PDO::FETCH_ASSOC);

    $lines = [count($svc) . ' published service(s): ' . (implode(', ', $svc) ?: 'none')];
    if (!$inc) {
        $lines[] = 'No open service-status incidents — nothing is currently being published to customers.';
        return implode("\n", $lines);
    }
    $lines[] = count($inc) . ' open service-status incident(s):';
    foreach ($inc as $i) {
        $lines[] = sprintf('- "%s" [%s] affecting %s. Latest note: %s',
            $i['title'], $i['state'] ?: '?', $i['services'] ?: 'no services listed',
            trim((string)$i['comment']) !== '' ? mb_substr(trim((string)$i['comment']), 0, 200) : '(none)');
    }
    return implode("\n", $lines);
}

function warbotToolRecentChanges(PDO $conn, array $args, int $analystId): string
{
    $days = max(1, min(30, (int)($args['days'] ?? 2)));
    // Match on the work OR outage window, not created_datetime: a change raised
    // three weeks ago and executed this morning is the one that matters, and
    // filtering on when it was typed up would hide exactly that case.
    $stmt = $conn->prepare(
        "SELECT c.title, c.work_start_datetime, c.outage_start_datetime,
                st.name AS state, a.full_name AS owner
           FROM changes c
           LEFT JOIN change_statuses st ON st.id = c.status_id
           LEFT JOIN analysts a ON a.id = c.assigned_to_id
          WHERE (c.work_start_datetime   >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL :d1 DAY)
              OR c.outage_start_datetime >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL :d2 DAY))
          ORDER BY COALESCE(c.outage_start_datetime, c.work_start_datetime) DESC
          LIMIT 20"
    );
    $stmt->bindValue(':d1', $days, PDO::PARAM_INT);
    $stmt->bindValue(':d2', $days, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$rows) return "No changes with a work or outage window in the last $days day(s).";

    $lines = [count($rows) . " change(s) in the last $days day(s):"];
    foreach ($rows as $r) {
        $when = $r['outage_start_datetime'] ?: $r['work_start_datetime'];
        $lines[] = sprintf('- "%s" [%s] %s%s', $r['title'], $r['state'] ?: '?',
            $when ? 'window from ' . $when . ' UTC' : 'no window set',
            $r['owner'] ? ', owned by ' . $r['owner'] : '');
    }
    return implode("\n", $lines);
}

function warbotToolOnCall(PDO $conn, array $args, int $analystId): string
{
    $date = (string)($args['date'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $date = gmdate('Y-m-d');

    $stmt = $conn->prepare(
        "SELECT a.full_name, sh.name AS shift, sh.start_time, sh.end_time, e.is_on_call
           FROM ticket_rota_entries e
           JOIN analysts a ON a.id = e.analyst_id
           LEFT JOIN ticket_rota_shifts sh ON sh.id = e.shift_id
          WHERE e.rota_date = :d
          ORDER BY e.is_on_call DESC, a.full_name"
    );
    $stmt->execute([':d' => $date]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$rows) return "Nobody is rostered for $date.";

    $onCall = array_filter($rows, function ($r) { return (int)$r['is_on_call'] === 1; });
    $lines  = [];
    $lines[] = $onCall
        ? 'On call ' . $date . ': ' . implode(', ', array_column($onCall, 'full_name'))
        : 'Nobody is flagged on call for ' . $date . '.';
    foreach ($rows as $r) {
        $lines[] = sprintf('- %s%s%s', $r['full_name'],
            $r['shift'] ? ' — ' . $r['shift'] : '',
            ($r['start_time'] && $r['end_time']) ? ' (' . substr($r['start_time'], 0, 5) . '–' . substr($r['end_time'], 0, 5) . ')' : '');
    }
    return implode("\n", $lines);
}

function warbotToolAssetLookup(PDO $conn, array $args, int $analystId): string
{
    $q = trim((string)($args['query'] ?? ''));
    if ($q === '') return 'Give me a hostname, asset tag or service tag to look for.';
    $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';

    $stmt = $conn->prepare(
        "SELECT a.hostname, a.asset_tag, a.service_tag, a.operating_system, a.logged_in_user,
                a.last_seen, a.warranty_expiry, t.name AS type, s.name AS status, l.name AS location
           FROM assets a
           LEFT JOIN asset_types        t ON t.id = a.asset_type_id
           LEFT JOIN asset_status_types s ON s.id = a.asset_status_id
           LEFT JOIN asset_locations    l ON l.id = a.location_id
          WHERE a.hostname LIKE :q1 ESCAPE '\\\\' OR a.asset_tag LIKE :q2 ESCAPE '\\\\' OR a.service_tag LIKE :q3 ESCAPE '\\\\'
          ORDER BY a.hostname LIMIT 8"
    );
    $stmt->execute([':q1' => $like, ':q2' => $like, ':q3' => $like]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$rows) return "No asset matches \"$q\".";
    $lines = [count($rows) . " asset(s) matching \"$q\":"];
    foreach ($rows as $r) {
        $lines[] = sprintf('- %s [%s/%s] %s%s%s%s',
            $r['hostname'] ?: '(no hostname)', $r['type'] ?: '?', $r['status'] ?: '?',
            $r['operating_system'] ?: 'OS unknown',
            $r['location'] ? ', at ' . $r['location'] : '',
            $r['logged_in_user'] ? ', last user ' . $r['logged_in_user'] : '',
            $r['last_seen'] ? ', last seen ' . $r['last_seen'] : '');
    }
    return implode("\n", $lines);
}

function warbotToolImpactOf(PDO $conn, array $args, int $analystId): string
{
    $name = trim((string)($args['name'] ?? ''));
    if ($name === '') return 'Give me the name of a configuration item.';
    $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $name) . '%';

    $find = $conn->prepare("SELECT id, name FROM cmdb_objects WHERE name LIKE :q ESCAPE '\\\\' ORDER BY name LIMIT 1");
    $find->execute([':q' => $like]);
    $obj = $find->fetch(PDO::FETCH_ASSOC);
    if (!$obj) return "No configuration item matches \"$name\".";

    // One hop only, in both directions. The full blast-radius walk lives in the
    // CMDB module and needs its edge-direction rules; repeating that here would
    // be a second implementation of the thing most likely to be subtly wrong.
    // ⚠️ from_object_id / to_object_id — NOT source_/target_, which is what these
    // were first written as. A wrong column name here does not error, it returns
    // nothing, and Warbot would have calmly reported "no recorded relationships"
    // for every object forever. Read out of information_schema, not remembered.
    $stmt = $conn->prepare(
        "SELECT o.name AS other, rt.verb, rt.inverse_verb,
                CASE WHEN r.from_object_id = :id1 THEN 'out' ELSE 'in' END AS dir
           FROM cmdb_object_relationships r
           JOIN cmdb_relationship_types rt ON rt.id = r.relationship_type_id
           JOIN cmdb_objects o ON o.id = CASE WHEN r.from_object_id = :id2 THEN r.to_object_id ELSE r.from_object_id END
          WHERE r.from_object_id = :id3 OR r.to_object_id = :id4
          ORDER BY o.name LIMIT 25"
    );
    foreach (['id1', 'id2', 'id3', 'id4'] as $p) $stmt->bindValue(':' . $p, (int)$obj['id'], PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$rows) return sprintf('"%s" exists in the CMDB but has no recorded relationships, so its blast radius is unknown rather than empty.', $obj['name']);

    $lines = [sprintf('"%s" is directly related to %d item(s):', $obj['name'], count($rows))];
    foreach ($rows as $r) {
        $verb = $r['dir'] === 'out' ? $r['verb'] : $r['inverse_verb'];
        $lines[] = sprintf('- %s %s', $verb ?: 'related to', $r['other']);
    }
    $lines[] = 'This is one hop only. Use the CMDB impact view for the full blast radius.';
    return implode("\n", $lines);
}

/** Escape the LIKE wildcards themselves, or a search for "50%" matches everything. */
function warbotLike(string $s): string
{
    return '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], trim($s)) . '%';
}

function warbotToolKnownErrors(PDO $conn, array $args, int $analystId): string
{
    $q = trim((string)($args['query'] ?? ''));
    if ($q === '') return 'Give me something to look for.';
    $like = warbotLike($q);

    $stmt = $conn->prepare(
        "SELECT p.problem_number, p.title, p.root_cause, p.workaround, p.is_known_error,
                st.name AS state, a.full_name AS owner,
                (SELECT COUNT(*) FROM problem_tickets pt WHERE pt.problem_id = p.id) AS ticket_count
           FROM problems p
           LEFT JOIN problem_statuses st ON st.id = p.status_id
           LEFT JOIN analysts a ON a.id = p.assigned_analyst_id
          WHERE p.title LIKE :q1 ESCAPE '\\\\' OR p.description LIKE :q2 ESCAPE '\\\\'
             OR p.root_cause LIKE :q3 ESCAPE '\\\\' OR p.workaround LIKE :q4 ESCAPE '\\\\'
          ORDER BY p.is_known_error DESC, p.id DESC
          LIMIT 6"
    );
    $stmt->execute([':q1' => $like, ':q2' => $like, ':q3' => $like, ':q4' => $like]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$rows) return "No problem record matches \"$q\". That does not mean it is new — it means nobody has written it up.";

    $lines = [count($rows) . " problem record(s) matching \"$q\":"];
    foreach ($rows as $r) {
        $lines[] = sprintf('- %s "%s" [%s]%s, %d linked ticket(s)',
            $r['problem_number'], $r['title'], $r['state'] ?: '?',
            $r['is_known_error'] ? ' KNOWN ERROR' : '', (int) $r['ticket_count']);
        // The workaround is the reason anybody asked, so it is quoted in full
        // rather than summarised away.
        if (trim((string)$r['workaround']) !== '') $lines[] = '    WORKAROUND: ' . mb_substr(trim((string)$r['workaround']), 0, 500);
        if (trim((string)$r['root_cause']) !== '') $lines[] = '    Root cause: ' . mb_substr(trim((string)$r['root_cause']), 0, 300);
    }
    return implode("\n", $lines);
}

function warbotToolTicketSpike(PDO $conn, array $args, int $analystId): string
{
    $mins = max(5, min(720, (int)($args['minutes'] ?? 60)));

    $now = $conn->prepare(
        "SELECT COUNT(*) FROM tickets
          WHERE deleted_datetime IS NULL
            AND created_datetime >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL :m MINUTE)"
    );
    $now->bindValue(':m', $mins, PDO::PARAM_INT);
    $now->execute();
    $current = (int) $now->fetchColumn();

    // The baseline is the SAME window on each of the previous 7 days, not simply
    // "the last week divided by seven": ticket volume is wildly time-of-day
    // dependent, so comparing 09:00 against a 24-hour average would call every
    // weekday morning a spike.
    $base = $conn->prepare(
        "SELECT COUNT(*) / 7.0 FROM tickets
          WHERE deleted_datetime IS NULL
            AND created_datetime >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 7 DAY)
            AND created_datetime <  DATE_SUB(UTC_TIMESTAMP(), INTERVAL :m1 MINUTE)
            AND TIME(created_datetime) BETWEEN TIME(DATE_SUB(UTC_TIMESTAMP(), INTERVAL :m2 MINUTE)) AND TIME(UTC_TIMESTAMP())"
    );
    $base->bindValue(':m1', $mins, PDO::PARAM_INT);
    $base->bindValue(':m2', $mins, PDO::PARAM_INT);
    $base->execute();
    $baseline = round((float) $base->fetchColumn(), 1);

    $lines = [sprintf('%d ticket(s) raised in the last %d minutes. Usual for this time of day: about %s.',
        $current, $mins, $baseline)];

    if ($baseline >= 1 && $current >= $baseline * 3) {
        $lines[] = 'That is roughly ' . round($current / max($baseline, 0.1)) . '× the normal rate — treat this as a spike.';
    } elseif ($current === 0) {
        $lines[] = 'Nothing at all, which is worth noticing too: if users cannot reach the service desk you would also see zero.';
    }

    // What they are ABOUT is the actionable half — a spike of one subject is an
    // incident, a spike of twenty different subjects is a bad morning.
    $subj = $conn->prepare(
        "SELECT " . ticketAiSubjectSql($conn, 'tickets') . " AS subject FROM tickets
          WHERE deleted_datetime IS NULL
            AND created_datetime >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL :m MINUTE)
          ORDER BY id DESC LIMIT 8"
    );
    $subj->bindValue(':m', $mins, PDO::PARAM_INT);
    $subj->execute();
    foreach ($subj->fetchAll(PDO::FETCH_COLUMN) as $s) $lines[] = '- ' . $s;

    return implode("\n", $lines);
}

function warbotToolSupplierContact(PDO $conn, array $args, int $analystId): string
{
    $q = trim((string)($args['query'] ?? ''));
    if ($q === '') return 'Give me a supplier name or something from a contract title.';
    $like = warbotLike($q);

    $stmt = $conn->prepare(
        "SELECT DISTINCT s.id, s.trading_name, s.legal_name
           FROM suppliers s
           LEFT JOIN contracts c ON c.supplier_id = s.id
          WHERE s.trading_name LIKE :q1 ESCAPE '\\\\' OR s.legal_name LIKE :q2 ESCAPE '\\\\'
             OR c.title LIKE :q3 ESCAPE '\\\\'
          ORDER BY s.trading_name LIMIT 3"
    );
    $stmt->execute([':q1' => $like, ':q2' => $like, ':q3' => $like]);
    $sups = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$sups) return "No supplier or contract matches \"$q\".";

    $lines = [];
    foreach ($sups as $s) {
        $name = $s['trading_name'] ?: $s['legal_name'];
        $lines[] = $name . ':';

        $cs = $conn->prepare(
            "SELECT c.contract_number, c.title, c.contract_end, st.name AS state
               FROM contracts c LEFT JOIN contract_statuses st ON st.id = c.contract_status_id
              WHERE c.supplier_id = :id ORDER BY c.contract_end DESC LIMIT 3"
        );
        $cs->execute([':id' => (int)$s['id']]);
        foreach ($cs->fetchAll(PDO::FETCH_ASSOC) as $c) {
            $lines[] = sprintf('  Contract %s "%s" [%s]%s',
                $c['contract_number'] ?: '?', $c['title'], $c['state'] ?: '?',
                $c['contract_end'] ? ', ends ' . $c['contract_end'] : '');
        }

        // The numbers are the point of this tool, so they come last and in full.
        $ct = $conn->prepare(
            "SELECT first_name, surname, job_title, direct_dial, switchboard, mobile, email
               FROM contacts WHERE supplier_id = :id AND (is_active IS NULL OR is_active = 1)
              ORDER BY surname LIMIT 5"
        );
        $ct->execute([':id' => (int)$s['id']]);
        $contacts = $ct->fetchAll(PDO::FETCH_ASSOC);
        if (!$contacts) {
            $lines[] = '  No contacts recorded for this supplier.';
            continue;
        }
        foreach ($contacts as $c) {
            $bits = array_filter([
                $c['direct_dial']  ? 'direct ' . $c['direct_dial'] : null,
                $c['switchboard']  ? 'switchboard ' . $c['switchboard'] : null,
                $c['mobile']       ? 'mobile ' . $c['mobile'] : null,
                $c['email']        ?: null,
            ]);
            $lines[] = sprintf('  %s %s%s — %s',
                $c['first_name'], $c['surname'],
                $c['job_title'] ? ' (' . $c['job_title'] . ')' : '',
                implode(', ', $bits) ?: 'no contact details recorded');
        }
    }
    return implode("\n", $lines);
}

function warbotToolMorningChecks(PDO $conn, array $args, int $analystId): string
{
    $date = (string)($args['date'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $date = gmdate('Y-m-d');

    // ⚠️ This module uses PascalCase throughout — CheckID, StatusID, and the
    // status text is `Label`, not `name`. Guessing `st.name` here (as every other
    // lookup table in the schema uses) produced an outright SQL error, which is
    // the LUCKY version of this mistake: the CMDB one returned no rows and would
    // have lied quietly forever.
    $stmt = $conn->prepare(
        "SELECT c.CheckName, r.Status, r.Notes, st.Label AS state
           FROM morningchecks_results r
           LEFT JOIN morningchecks_checks   c  ON c.CheckID  = r.CheckID
           LEFT JOIN morningchecks_statuses st ON st.StatusID = r.StatusID
          WHERE DATE(r.CheckDate) = :d
          ORDER BY c.SortOrder, c.CheckName"
    );
    $stmt->execute([':d' => $date]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$rows) return "No morning checks were recorded for $date.";

    $bad = [];
    foreach ($rows as $r) {
        $state = strtolower((string)($r['state'] ?: $r['Status']));
        if ($state !== '' && !preg_match('/ok|pass|green|success|complete/i', $state)) $bad[] = $r;
    }

    $lines = [count($rows) . " check(s) recorded for $date; " . count($bad) . ' not clear.'];
    foreach (($bad ?: array_slice($rows, 0, 6)) as $r) {
        $lines[] = sprintf('- %s: %s%s', $r['CheckName'] ?: '(unnamed check)',
            $r['state'] ?: $r['Status'] ?: '?',
            trim((string)$r['Notes']) !== '' ? ' — ' . mb_substr(trim((string)$r['Notes']), 0, 200) : '');
    }
    return implode("\n", $lines);
}

function warbotToolSearchChat(PDO $conn, array $args, int $analystId): string
{
    $q = trim((string)($args['query'] ?? ''));
    if ($q === '') return 'Give me something to search the chat for.';

    // Reuses the module's own search, so the channel scoping is the same one the
    // search panel uses — Warbot cannot surface a channel the asker cannot read.
    require_once __DIR__ . '/../warroom.php';
    $hits = warRoomSearch($conn, $analystId, $q, null, 8);
    if (!$hits) return "Nothing in the war room matches \"$q\".";

    $lines = [count($hits) . " message(s) matching \"$q\":"];
    foreach ($hits as $h) {
        $lines[] = sprintf('- [#%s] %s at %s: %s', $h['channel'], $h['author'],
            substr((string)$h['created'], 11, 5) . ' UTC', $h['snippet']);
    }
    return implode("\n", $lines);
}

function warbotToolRelatedTickets(PDO $conn, array $args, int $analystId): string
{
    $ref = trim((string)($args['ticket'] ?? ''));
    if ($ref === '') return 'Give me a ticket number.';

    require_once __DIR__ . '/../ticket_sensitivity.php';
    $find = $conn->prepare("SELECT id, ticket_number, " . ticketAiSubjectSql($conn, 'tickets') . " AS subject FROM tickets WHERE ticket_number = :r AND deleted_datetime IS NULL LIMIT 1");
    $find->execute([':r' => $ref]);
    $t = $find->fetch(PDO::FETCH_ASSOC);
    if (!$t) return "No ticket numbered \"$ref\".";

    $stmt = $conn->prepare(
        "SELECT l.relation_type,
                CASE WHEN l.source_ticket_id = :id1 THEN 'to' ELSE 'from' END AS dir,
                o.ticket_number, " . ticketAiSubjectSql($conn, 'o') . " AS subject, s.name AS state
           FROM ticket_links l
           JOIN tickets o ON o.id = CASE WHEN l.source_ticket_id = :id2 THEN l.target_ticket_id ELSE l.source_ticket_id END
           LEFT JOIN ticket_statuses s ON s.id = o.status_id
          WHERE (l.source_ticket_id = :id3 OR l.target_ticket_id = :id4) AND o.deleted_datetime IS NULL
          LIMIT 20"
    );
    foreach (['id1', 'id2', 'id3', 'id4'] as $p) $stmt->bindValue(':' . $p, (int)$t['id'], PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$rows) return sprintf('%s "%s" has no linked tickets.', $t['ticket_number'], $t['subject']);

    $lines = [sprintf('%s "%s" is linked to %d ticket(s):', $t['ticket_number'], $t['subject'], count($rows))];
    foreach ($rows as $r) {
        $lines[] = sprintf('- %s (%s %s) [%s] %s', $r['ticket_number'], $r['relation_type'] ?: 'related', $r['dir'], $r['state'] ?: '?', $r['subject']);
    }
    return implode("\n", $lines);
}

function warbotToolSearchKnowledge(PDO $conn, array $args, int $analystId): string
{
    $q = trim((string)($args['query'] ?? ''));
    if ($q === '') return 'Give me something to search for.';
    $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';

    // ⚠️ BEHAVIOUR CHANGE on multi-company installs. This search took $analystId
    // and never used it: it matched titles across EVERY company, and Warbot
    // answers in a channel where everyone sees the reply. It now reads through
    // includes/knowledge/visibility.php as the asking analyst, so it returns the
    // company they are working in plus everything shared — the same rule as every
    // other Knowledge reader, and the access list when that lands.
    //
    // 📌 The broader question is NOT settled here: no Warbot tool is company-
    // scoped, and the header above reasons about WHAT a tool may return (no
    // bodies, no requester details) without ever reaching WHOSE it may return.
    // Whether a war room is deliberately install-wide is a decision about the
    // module, not about Knowledge, and it is left open rather than answered by
    // one tool quietly disagreeing with the rest.
    $viewer = KnowledgeViewer::forAnalyst($conn, $analystId);
    [$visSql, $visParams] = knowledgeVisibilitySql($conn, $viewer, '', ['lifecycle' => 'live']);

    $stmt = $conn->prepare(
        "SELECT id, title FROM knowledge_articles
          WHERE title LIKE ? ESCAPE '\\\\'" . $visSql . "
          ORDER BY view_count DESC, title LIMIT 8"
    );
    $stmt->execute(array_merge([$like], $visParams));
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$rows) return "No published article title matches \"$q\".";
    $lines = [count($rows) . " article(s) matching \"$q\":"];
    foreach ($rows as $r) $lines[] = sprintf('- #%d %s', $r['id'], $r['title']);
    // Titles only, on purpose: an article body pasted into a shared channel is a
    // wall of text nobody asked for, and may contain more than the room needs.
    $lines[] = 'Titles only — open the article in Knowledge to read it.';
    return implode("\n", $lines);
}

/* ══════════════════════════════════════════════════════════════════════════
   PROJECTS (3.3.0) - includes/projects/assistant.php, scoped like the portfolio.
   ══════════════════════════════════════════════════════════════════════════ */

/** The asking analyst's projects: their active company, or every company they can see under "All". */
function warbotProjectScope(PDO $conn, int $analystId): array
{
    require_once __DIR__ . '/../tenancy.php';
    require_once __DIR__ . '/../projects/assistant.php';
    return activeTenantReadFilter($conn, $analystId, 'p');
}

/** A ServiceError (no such project, several match) is an answer to give the room, not a failure. */
function warbotProjectAnswer(callable $fn): string
{
    try {
        return $fn();
    } catch (ServiceError $e) {
        return $e->getMessage();
    }
}

function warbotToolListProjects(PDO $conn, array $args, int $analystId): string
{
    $scope = warbotProjectScope($conn, $analystId);
    // A channel answer, so a shorter list than the MCP server's default.
    return warbotProjectAnswer(fn() => projectAssistList($conn, $scope, ['limit' => 10] + $args));
}

function warbotToolProjectOverview(PDO $conn, array $args, int $analystId): string
{
    $scope = warbotProjectScope($conn, $analystId);
    return warbotProjectAnswer(fn() => projectAssistOverview($conn, $scope, $analystId, $args, false));
}

function warbotToolProjectRaid(PDO $conn, array $args, int $analystId): string
{
    $scope = warbotProjectScope($conn, $analystId);
    return warbotProjectAnswer(fn() => projectAssistRaid($conn, $scope, $args));
}

function warbotToolProjectTasks(PDO $conn, array $args, int $analystId): string
{
    $scope = warbotProjectScope($conn, $analystId);
    return warbotProjectAnswer(fn() => projectAssistTasks($conn, $scope, $args));
}

function warbotToolProjectDates(PDO $conn, array $args, int $analystId): string
{
    $scope = warbotProjectScope($conn, $analystId);
    return warbotProjectAnswer(fn() => projectAssistDates($conn, $scope, $args));
}
