<?php
/**
 * Projects for an assistant (3.3.0): the read-only answers the MCP server
 * (includes/mcp/tools.php) and Warbot (includes/warbot/tools.php) both give -
 * which projects are off track, how one is going, its RAID log, its tasks.
 *
 * 🔑 ONE COPY, TWO DOORS. Moved here from the MCP server when Warbot gained the
 * same tools, so the two can never describe a project differently. What differs
 * is only WHOSE projects, and the caller passes that in as a scope:
 *
 *   $scope = [' AND p.tenant_id ...', [args]]   (alias `p`, NULL company = Default)
 *
 *   MCP     mcpProjectScopeSql()      the KEY's effective companies
 *   Warbot  activeTenantReadFilter()  the asking analyst's active company (or
 *                                     every company they can see in "All"),
 *                                     the same rule as the Projects portfolio
 *
 * Health, progress and exceptions come from the same projectDecorate() the
 * screens use, so an assistant can never call a project green that its page
 * calls red.
 *
 * Plain text out, "- " lists, no markdown: Warbot's chat shows text as typed,
 * and an MCP client reads it either way.
 */

require_once __DIR__ . '/read.php';
require_once __DIR__ . '/methodologies.php';
require_once __DIR__ . '/../service_context.php';   // ServiceError

/** Decorated project rows (health, progress, exceptions, budget totals) for a WHERE, inside $scope. */
function projectAssistRows(PDO $conn, array $scope, string $where, array $args, int $limit): array
{
    [$sSql, $sArgs] = $scope;
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

/** One project inside $scope, by code (PRJ-0042), id or a unique part of its name. Throws ServiceError. */
function projectAssistFind(PDO $conn, array $scope, $ref): array
{
    $ref = trim((string)$ref);
    if ($ref === '') throw new ServiceError('validation', 'missing_field', 'Say which project: its code (PRJ-0042), id or name.');
    if (preg_match('/^(?:PRJ-?)?0*(\d+)$/i', $ref, $m)) {
        $rows = projectAssistRows($conn, $scope, 'p.id = ?', [(int)$m[1]], 1);
    } else {
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $ref) . '%';
        $rows = projectAssistRows($conn, $scope, 'p.name LIKE ?', [$like], 6);
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

function projectAssistMoney(?float $v, string $cur): string
{
    return $v === null ? '-' : $cur . ' ' . number_format($v, 2);
}

/** Why a project is the colour it is, in words. */
function projectAssistWhy(array $p): string
{
    $why = [];
    if ($p['health'] !== 'auto') $why[] = 'set by hand' . ($p['health_note'] ? ': ' . $p['health_note'] : '');
    foreach ($p['exceptions'] as $e) {
        if ($e['kind'] === 'risk') $why[] = 'a risk scores ' . $e['score'] . ' (tolerance ' . $e['allowed'] . ')';
        elseif ($e['kind'] === 'cost') $why[] = (($e['basis'] ?? '') === 'forecast' ? 'forecast ' : '') . $e['over_pct'] . '% over budget (tolerance ' . $e['allowed'] . '%)';
        else $why[] = $e['late'] . ' days late' . ($e['kind'] === 'stage_time' ? ' on the current stage' : '') . ' (tolerance ' . $e['allowed'] . ')';
    }
    if ($p['task_overdue'] > 0) $why[] = $p['task_overdue'] . ' overdue task(s)';
    if (!empty($p['milestones_missed'])) $why[] = $p['milestones_missed'] . ' milestone(s) missed';
    if (!empty($p['raid_overdue'])) $why[] = $p['raid_overdue'] . ' dependency or decision late';
    if (!empty($p['raid_escalated'])) $why[] = $p['raid_escalated'] . ' escalated';
    if (!empty($p['changes_pending'])) $why[] = $p['changes_pending'] . ' change request(s) waiting for a decision';
    if (!empty($p['benefits_due'])) $why[] = $p['benefits_due'] . ' benefit review(s) due';
    if (!empty($p['ticket_spike'])) $why[] = $p['tickets_7d'] . ' linked tickets raised in the last 7 days';
    return implode('; ', $why);
}

function projectAssistLine(array $p): string
{
    $why = projectAssistWhy($p);
    return sprintf('%s %s [%s, %s, %s priority, %d%% of %d task(s) done%s%s]%s',
        $p['code'], $p['name'], $p['status'], $p['shown_health'] ?? 'no health', $p['priority'] ?? 'medium', $p['progress'], $p['task_total'],
        $p['target_end_date'] ? ', target ' . $p['target_end_date'] : '', $p['owner_name'] ? ', led by ' . $p['owner_name'] : '',
        $why !== '' ? ' - ' . $why : '');
}

/** args: status (live | all | a status), health, q, limit. */
function projectAssistList(PDO $conn, array $scope, array $args): string
{
    $status = trim((string)($args['status'] ?? 'live')) ?: 'live';
    $where = '1=1'; $wArgs = [];
    if ($status === 'live') $where = "p.status IN ('proposed', 'active')";
    elseif ($status !== 'all') {
        if (!in_array($status, projectStatuses(), true)) return 'Unknown status "' . $status . '". Use live, all, or one of ' . implode(', ', projectStatuses()) . '.';
        $where = 'p.status = ?'; $wArgs[] = $status;
    }
    if (trim((string)($args['q'] ?? '')) !== '') {
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], trim((string)$args['q'])) . '%';
        $where .= ' AND (p.name LIKE ? OR p.summary LIKE ? OR p.goal LIKE ?)';
        array_push($wArgs, $like, $like, $like);
    }
    $limit = max(1, min(100, (int)($args['limit'] ?? 25)));
    $rows = projectAssistRows($conn, $scope, $where, $wArgs, 500);
    $health = trim((string)($args['health'] ?? ''));
    if ($health !== '') $rows = array_values(array_filter($rows, fn($p) => $p['shown_health'] === $health));
    if (!$rows) return 'No projects match.';
    $lines = [count($rows) . ' project(s)' . (count($rows) > $limit ? ', the first ' . $limit . ':' : ':')];
    foreach (array_slice($rows, 0, $limit) as $p) $lines[] = '- ' . projectAssistLine($p);
    return implode("\n", $lines);
}

/**
 * One project in full. $withBudget: the money line - the MCP server gives it to
 * the key's analyst; Warbot leaves it out, because its answer is read by
 * everyone in the channel.
 */
function projectAssistOverview(PDO $conn, array $scope, int $analystId, array $args, bool $withBudget): string
{
    require_once __DIR__ . '/../services/project_tools.php';
    require_once __DIR__ . '/links.php';
    require_once __DIR__ . '/budget.php';
    $p = projectAssistFind($conn, $scope, $args['project'] ?? '');
    $pid = (int)$p['id'];
    $out = [projectAssistLine($p)];
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
    $ms = projectMilestones($conn, $pid);
    if ($ms) {
        $out[] = 'Milestones:';
        foreach ($ms as $m) $out[] = sprintf('- %s, due %s: %s', $m['name'], $m['due_date'],
            $m['state'] === 'done' ? 'reached ' . $m['done_date'] . ($m['met'] ? ' (on time)' : ' (late)') : ($m['state'] === 'missed' ? 'MISSED' : 'not reached yet'));
    }
    $raid = array_filter(ProjectToolsService::raid($conn, $pid), fn($r) => $r['status'] === 'open' && in_array($r['type'], ['risk', 'issue', 'dependency', 'decision'], true));
    if ($raid) {
        $today = gmdate('Y-m-d');
        $out[] = 'Open risks, issues, dependencies and decisions to make:';
        foreach (array_slice($raid, 0, 10) as $r) $out[] = sprintf('- %s: %s%s%s%s%s', $r['type'], $r['title'], $r['score'] !== null ? ' (score ' . (int)$r['score'] . ')' : '',
            $r['owner_name'] ? ', owner ' . $r['owner_name'] : '', $r['due_date'] && in_array($r['type'], ['dependency', 'decision'], true) ? ', due ' . $r['due_date'] . ($r['due_date'] < $today ? ' (LATE)' : '') : '',
            !empty($r['escalated_datetime']) ? ' - ESCALATED: ' . $r['escalation_note'] : '');
    }
    $b = $p['_budget'] ?? null;
    if ($withBudget && $b && ($b['planned'] > 0 || $b['actual'] > 0)) {
        $cur = projectCurrencyOf($conn, $p);
        $out[] = 'Budget: ' . projectAssistMoney($b['planned'], $cur) . ' planned, ' . projectAssistMoney($b['actual'], $cur) . ' spent'
            . (isset($b['forecast']) ? ', ' . projectAssistMoney($b['forecast'], $cur) . ' forecast' : '') . '.';
    }
    if (analystCanAccessModule($conn, $analystId, 'changes')) {
        $unapproved = projectUnapprovedChanges($conn, $pid);
        if ($unapproved) $out[] = count($unapproved) . ' linked change(s) not yet approved: ' . implode(', ', array_map(fn($c) => $c['label'] . ' ' . $c['title'], $unapproved)) . '.';
    }
    $h = $conn->prepare("SELECT pa.field_name, pa.old_value, pa.new_value, pa.created_datetime, an.full_name FROM project_audit pa LEFT JOIN analysts an ON an.id = pa.analyst_id
                          WHERE pa.project_id = ? ORDER BY pa.id DESC LIMIT 8");
    $h->execute([$pid]);
    // A history row can name a record in another module ("contract: Fibre circuit",
    // a raised ticket, a Knowledge article). Leave out the ones whose module the
    // analyst cannot open - the MCP security review's finding 6. Budget lines too,
    // when the caller leaves the money out.
    $hist = array_values(array_filter($h->fetchAll(PDO::FETCH_ASSOC), function ($r) use ($conn, $analystId, $withBudget) {
        if (in_array($r['field_name'], ['link_added', 'link_removed'], true)) {
            $kind = strtok((string)$r['new_value'] ?: (string)$r['old_value'], ':');
            return projectLinkKindAllowed($conn, $analystId, (string)$kind);
        }
        if ($r['field_name'] === 'raid_ticket_raised') return analystCanAccessModule($conn, $analystId, 'tickets');
        if ($r['field_name'] === 'raid_to_knowledge') return analystCanAccessModule($conn, $analystId, 'knowledge');
        if ($r['field_name'] === 'disruption_announced' || $r['field_name'] === 'disruption_withdrawn') return analystCanAccessModule($conn, $analystId, 'service-status');
        if (!$withBudget && (strpos($r['field_name'], 'budget_') === 0 || strpos($r['field_name'], 'labour_') === 0 || $r['field_name'] === 'currency')) return false;
        return true;
    }));
    if ($hist) {
        $out[] = 'Recent history:';
        foreach ($hist as $r) $out[] = sprintf('- %s %s %s%s', substr($r['created_datetime'], 0, 10), $r['full_name'] ?: 'Someone', str_replace('_', ' ', $r['field_name']), $r['new_value'] !== null ? ': ' . mb_substr($r['new_value'], 0, 120) : '');
    }
    return implode("\n", $out);
}

/** args: project, type (risk | assumption | issue | dependency | decision | lesson), status (open | closed | all). */
function projectAssistRaid(PDO $conn, array $scope, array $args): string
{
    require_once __DIR__ . '/../services/project_tools.php';
    $p = projectAssistFind($conn, $scope, $args['project'] ?? '');
    $type = trim((string)($args['type'] ?? ''));
    $status = trim((string)($args['status'] ?? 'open')) ?: 'open';
    $rows = array_values(array_filter(ProjectToolsService::raid($conn, (int)$p['id']), fn($r) =>
        ($type === '' || $r['type'] === $type) && ($status === 'all' || $r['status'] === $status)));
    if (!$rows) return $p['code'] . ' has no ' . ($status === 'all' ? '' : $status . ' ') . ($type ?: 'RAID') . ' entries.';
    $lines = [$p['code'] . ' ' . $p['name'] . ' - ' . count($rows) . ' entr' . (count($rows) === 1 ? 'y' : 'ies') . ':'];
    foreach ($rows as $r) {
        $lines[] = sprintf('- [%s, %s] %s%s%s%s%s%s%s', $r['type'], $r['status'], $r['title'],
            $r['score'] !== null ? ' - score ' . (int)$r['score'] . ' (probability ' . (int)$r['probability'] . ' x impact ' . (int)$r['impact'] . ')' : '',
            $r['owner_name'] ? ', owner ' . $r['owner_name'] : '', $r['due_date'] ? ', ' . ($r['type'] === 'dependency' ? 'needed by ' : 'due ') . $r['due_date'] : '',
            $r['response_plan'] ? '. Plan: ' . mb_substr($r['response_plan'], 0, 300) : '',
            $r['type'] === 'decision' && !empty($r['decided_date']) ? '. Decided ' . $r['decided_date'] . ($r['decided_by'] ? ' by ' . $r['decided_by'] : '') . ($r['rationale'] ? ' - why: ' . mb_substr($r['rationale'], 0, 300) : '') : '',
            !empty($r['escalated_datetime']) ? '. ESCALATED: ' . $r['escalation_note'] : '')
            . (!empty($r['actions']) ? ' (' . count(array_filter($r['actions'], fn($a) => $a['is_closed'])) . ' of ' . count($r['actions']) . ' follow-up actions done)' : '');
    }
    return implode("\n", $lines);
}

/** args: project, open_only (default true). Overdue first, then by due date. */
function projectAssistTasks(PDO $conn, array $scope, array $args): string
{
    $p = projectAssistFind($conn, $scope, $args['project'] ?? '');
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

/**
 * The project dates coming up across every project in $scope - stage ends,
 * milestones and target finishes in the next N days (default 14), and
 * milestones already missed. Warbot's "is anything big happening this week?".
 */
function projectAssistDates(PDO $conn, array $scope, array $args): string
{
    [$sSql, $sArgs] = $scope;
    $days = max(1, min(90, (int)($args['days'] ?? 14)));
    $live = "p.status IN ('proposed', 'active')";
    $rows = [];
    $q = function (string $sql, array $a) use ($conn, $sArgs) { $st = $conn->prepare($sql); $st->execute(array_merge($a, $sArgs)); return $st->fetchAll(PDO::FETCH_ASSOC); };
    foreach ($q("SELECT s.end_date AS d, p.id, p.name, CONCAT(s.name, ' ends') AS what FROM project_stages s JOIN projects p ON p.id = s.project_id
                  WHERE s.status <> 'closed' AND s.end_date BETWEEN UTC_DATE() AND DATE_ADD(UTC_DATE(), INTERVAL ? DAY) AND $live $sSql", [$days]) as $r) $rows[] = $r;
    foreach ($q("SELECT p.target_end_date AS d, p.id, p.name, 'target finish' AS what FROM projects p
                  WHERE p.target_end_date BETWEEN UTC_DATE() AND DATE_ADD(UTC_DATE(), INTERVAL ? DAY) AND $live $sSql", [$days]) as $r) $rows[] = $r;
    $missed = [];
    if (projectMilestonesReady($conn)) {
        foreach ($q("SELECT m.due_date AS d, p.id, p.name, CONCAT('milestone: ', m.name) AS what FROM project_milestones m JOIN projects p ON p.id = m.project_id
                      WHERE m.done_date IS NULL AND m.due_date BETWEEN UTC_DATE() AND DATE_ADD(UTC_DATE(), INTERVAL ? DAY) AND $live $sSql", [$days]) as $r) $rows[] = $r;
        $missed = $q("SELECT m.due_date AS d, p.id, p.name, CONCAT('milestone MISSED: ', m.name) AS what FROM project_milestones m JOIN projects p ON p.id = m.project_id
                       WHERE m.done_date IS NULL AND m.due_date < UTC_DATE() AND $live $sSql", []);
    }
    usort($rows, fn($a, $b) => strcmp($a['d'], $b['d']) ?: strcmp($a['name'], $b['name']));
    if (!$rows && !$missed) return "No project dates in the next $days days, and no missed milestones.";
    $lines = [];
    if ($missed) { $lines[] = 'Missed milestones:'; foreach ($missed as $r) $lines[] = sprintf('- %s %s (%s): %s', $r['d'], $r['name'], projectCode((int)$r['id']), $r['what']); }
    $lines[] = $rows ? "Project dates in the next $days days:" : "No project dates in the next $days days.";
    foreach ($rows as $r) $lines[] = sprintf('- %s %s (%s): %s', $r['d'], $r['name'], projectCode((int)$r['id']), $r['what']);
    return implode("\n", $lines);
}
