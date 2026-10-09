<?php
/**
 * GET - download a spreadsheet (3.3.0):
 *   ?what=portfolio&ids=3,1,7&format=csv|xlsx   the projects in the portfolio's view, in its order
 *   ?what=raid&project_id=N&format=csv|xlsx     one project's whole RAID log, open and closed
 *
 * The portfolio filters and sorts in the browser, so the page sends the ids it
 * is showing; only ids this analyst may see (projectListRows) are written, so
 * the list cannot be widened by editing the URL. Writing is includes/spreadsheet.php:
 * every .xlsx cell is text, and CSV cells are guarded against formula injection.
 */
require_once __DIR__ . '/../../includes/projects/api_bootstrap.php';
require_once __DIR__ . '/../../includes/spreadsheet.php';
require_once __DIR__ . '/../../includes/i18n.php';

projectApiRun(function () use ($conn, $ctx, $analystId) {
    require_once __DIR__ . '/../../includes/projects/settings.php';
    $format = ($_GET['format'] ?? 'xlsx') === 'csv' ? 'csv' : 'xlsx';
    $what = (string)($_GET['what'] ?? '');
    $T = fn(string $k) => t('projects.' . $k);
    $date = fn($d) => $d ? substr((string)$d, 0, 10) : '';

    if ($what === 'portfolio') {
        require_once __DIR__ . '/../../includes/projects/budget.php';
        $want = array_values(array_filter(array_map('intval', explode(',', (string)($_GET['ids'] ?? '')))));
        $byId = [];
        foreach (projectListRows($conn, $analystId) as $r) $byId[(int)$r['id']] = $r;
        $rows = [];
        foreach ($want ?: array_keys($byId) as $id) if (isset($byId[$id])) $rows[$id] = $byId[$id];
        $budgets = [];
        if ($rows && projectBudgetReady($conn)) {
            $ph = implode(',', array_fill(0, count($rows), '?'));
            $st = $conn->prepare("SELECT id, currency FROM projects WHERE id IN ($ph)");
            $st->execute(array_keys($rows));
            $cur = [];
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $c) $cur[(int)$c['id']] = $c;
            $budgets = projectBudgetTotals($conn, $cur);
        }
        $prio = projectScaleLabels($conn, 'priority');
        $multi = isMultiTenant($conn);
        $head = array_merge([$T('export.code'), $T('export.name')], $multi ? [$T('export.company')] : [], [
            $T('export.status'), $T('export.priority'), $T('export.health'), $T('export.progress'), $T('export.owner'), $T('export.stage'),
            $T('export.start'), $T('export.target'), $T('export.finished'), $T('export.tasks_done'), $T('export.tasks'), $T('export.overdue'),
            $T('export.next_milestone'), $T('export.missed'), $T('export.escalated'), $T('export.currency'), $T('export.planned'), $T('export.spent'), $T('export.forecast'),
        ]);
        $out = [];
        foreach ($rows as $id => $p) {
            $b = $budgets[$id] ?? null;
            $money = fn($v) => $b && (!empty($b['has_budget']) || !empty($b['actual'])) && $v !== null ? number_format((float)$v, 2, '.', '') : '';
            $nm = $p['next_milestone'] ?? null;
            $out[] = array_merge([$p['code'], $p['name']], $multi ? [$p['company_name'] ?? ''] : [], [
                $T('status.' . $p['status']),
                $prio[array_search($p['priority'] ?? '', projectPriorities(), true)] ?? '',   // a key (low ... critical) -> its label
                $p['shown_health'] ? $T('health.' . $p['shown_health']) : '',
                (int)$p['progress'] . '%', $p['owner_name'] ?? '', $p['active_stage_name'] ?? '',
                $date($p['start_date']), $date($p['target_end_date']), $date($p['actual_end_date']),
                (int)$p['task_done'], (int)$p['task_total'], (int)$p['task_overdue'],
                $nm ? $nm['name'] . ' (' . $date($nm['due_date']) . ')' : '', (int)($p['milestones_missed'] ?? 0), (int)($p['raid_escalated'] ?? 0),
                $b['currency'] ?? '', $money($b['planned'] ?? null), $money($b['actual'] ?? null), $money($b['forecast'] ?? ($b['actual'] ?? null)),
            ]);
        }
        $name = 'projects-' . gmdate('Y-m-d');
        $sheet = $T('export.sheet_portfolio');
    } elseif ($what === 'raid') {
        $pid = (int)($_GET['project_id'] ?? 0);
        $project = ProjectsService::loadForActor($conn, $ctx, $pid);   // not found / no access throws
        require_once __DIR__ . '/../../includes/services/project_tools.php';
        $head = [$T('export.type'), $T('raid.title'), $T('raid.description'), $T('raid.status'), $T('raid.owner'), $T('raid.probability'), $T('raid.impact'), $T('export.score'),
                 $T('raid.response'), $T('raid.plan'), $T('raid.due'), $T('export.raised'), $T('export.closed'), $T('export.escalated_on'), $T('export.decided_by'), $T('export.rationale'),
                 $T('raid.ticket'), $T('export.actions')];
        $out = [];
        foreach (ProjectToolsService::raid($conn, $pid) as $r) {
            $acts = $r['actions'] ?? [];
            $out[] = [
                $T('raid.' . $r['type']), $r['title'], (string)($r['description'] ?? ''), $T('raid.' . $r['status']), $r['owner_name'] ?? '',
                $r['probability'] ?? '', $r['impact'] ?? '', $r['score'] ?? '',
                $r['response'] ? $T('raid.resp_' . $r['response']) : '', (string)($r['response_plan'] ?? ''), $date($r['due_date']),
                $date($r['raised_datetime']), $date($r['closed_datetime']), $date($r['escalated_datetime'] ?? null),
                (string)($r['decided_by'] ?? ''), (string)($r['rationale'] ?? ''),
                $r['ticket_number'] ? $r['ticket_number'] . ' ' . $r['ticket_subject'] : '',
                implode('; ', array_map(fn($a) => $a['title'] . ($a['is_closed'] ? ' (' . $T('export.done') . ')' : ''), $acts)),
            ];
        }
        $name = 'raid-' . preg_replace('/[^A-Za-z0-9-]+/', '-', (string)projectCode((int)$project['id'])) . '-' . gmdate('Y-m-d');
        $sheet = $T('export.sheet_raid');
    } else {
        projectApiFail('Unknown export.');
    }

    $body = $format === 'csv' ? spreadsheetWriteCsv($head, $out) : spreadsheetWriteXlsx($sheet, $head, $out);
    header_remove('Content-Type');
    header($format === 'csv' ? 'Content-Type: text/csv; charset=utf-8' : 'Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $name . '.' . $format . '"');
    header('Content-Length: ' . strlen($body));
    header('X-Content-Type-Options: nosniff');
    echo $body;
    exit;
});
