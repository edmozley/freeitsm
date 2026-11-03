<?php
/**
 * The evidence report for one repeating task (#181 Parts E and F) - on screen, and printed to PDF.
 *
 * For a chosen period of SCHEDULED dates: every occurrence that should have happened, what
 * happened to each (on time, on time after rescheduling, late, overdue, open, skipped,
 * missing, not created yet, removed), who did it, the checklist results and exceptions, and
 * what the repeating-tasks job's run history shows for a missing or late one. Worked out by
 * includes/task_evidence.php from the record; viewing it never creates a task.
 *
 * Printing is the browser's Print -> Save as PDF, the same as Change Management's evidence
 * export. Opening the page with ?print=1 records the export on the series' history and opens
 * the print dialog; the CSV files come from api/tasks/evidence_export.php and are recorded too.
 *
 * Gated on Tasks access, then "Run the evidence report" where Tasks -> Settings -> Evidence
 * restricts it, then the series' company. Occurrences in companies the viewer cannot see are
 * left out and counted.
 *
 * @wiki Repeating-Task-Evidence
 */
session_start();
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/i18n.php';
require_once '../../includes/theme.php';
require_once '../../includes/timezone.php';
require_once '../../includes/rbac.php';
require_once '../../includes/task_evidence.php';
I18n::initFromSession();
Tz::init();

requireModuleAccess('tasks');
$conn = connectToDatabase();
$analystId = (int)$_SESSION['analyst_id'];
$rid = (int)($_GET['series'] ?? 0);

$T = fn($k, $p = []) => htmlspecialchars(t('tasks.evidence.' . $k, $p));
$err = null;
if (!analystMayAct($conn, $analystId, Cap::TASKS_EVIDENCE_REPORT)) {
    $err = t('tasks.evidence.no_permission');
} else {
    $header = TaskEvidence::seriesHeader($conn, $rid);
    $visible = $header && (!isMultiTenant($conn) || analystCanAccessTenant($conn, $analystId,
        $header['tenant_id'] === null ? getDefaultTenantId($conn) : (int)$header['tenant_id']));
    if (!$visible) $err = t('tasks.evidence.not_found');
}

$isDate = fn($s) => is_string($s) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $s);
$to   = $isDate($_GET['to'] ?? null) ? $_GET['to'] : date('Y-m-d');
$from = $isDate($_GET['from'] ?? null) ? $_GET['from'] : date('Y-m-d', strtotime($to . ' -12 months +1 day'));
if ($from > $to) [$from, $to] = [$to, $from];

$r = null;
if (!$err) {
    $r = TaskEvidence::report($conn, $rid, $from, $to, $analystId);
    if (!empty($_GET['print'])) {
        TaskEvidence::recordExport($conn, ActorContext::fromSession($conn), $rid, 'print', $from, $to);
    }
}

$current_page = 'evidence';
$path_prefix = '../../';
$translationNamespaces = ['common', 'tasks'];

/** One outcome as a pill. */
function evOutcome(string $o): string {
    return '<span class="ev-pill ev-out-' . htmlspecialchars($o) . '">' . htmlspecialchars(t('tasks.evidence.outcome_' . $o)) . '</span>';
}
/** The flags beside an outcome. */
function evFlags(array $row): string {
    $out = [];
    foreach (['rescheduled', 'due_changed_after_completion', 'created_late', 'changed_after_completion', 'scheduled_not_recorded',
              'due_history_not_recorded', 'history_not_recorded', 'not_recorded'] as $f) {
        if (!empty($row['flags'][$f])) $out[] = '<span class="ev-flag ev-flag-' . $f . '">' . htmlspecialchars(t('tasks.evidence.flag_' . $f)) . '</span>';
    }
    return implode(' ', $out);
}
/** What the run history says, as a sentence. */
function evExplain(?array $e): string {
    if (!$e) return '';
    $p = ['from' => TaskEvidence::day($e['from'] ?? null), 'to' => TaskEvidence::day($e['to'] ?? null),
          'at' => TaskEvidence::when($e['run_at'] ?? null), 'message' => $e['message'] ?? '',
          'expected' => TaskEvidence::when($e['expected_at'] ?? null)];
    $key = $e['code'] === 'history_not_available' ? 'explain_not_available_' . ($e['reason'] ?? 'before_recording') : 'explain_' . $e['code'];
    return htmlspecialchars(t('tasks.evidence.' . $key, $p));
}
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <link rel="icon" type="image/svg+xml" href="<?php echo defined('BASE_URL') ? BASE_URL : '/'; ?>favicon.svg">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(pageTitle('tasks', $r ? t('tasks.evidence.report_title', ['title' => $r['series']['title']]) : t('tasks.nav.evidence'))); ?></title>
    <link rel="stylesheet" href="../../assets/css/theme.css?v=27">
    <link rel="stylesheet" href="../../assets/css/inbox.css?v=79">
    <link rel="stylesheet" href="../../assets/css/tasks.css?v=58">
    <link rel="stylesheet" href="../../assets/css/task-evidence.css?v=1">
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <?php echo Tz::scriptTag(); ?>
    <script src="../../assets/js/tz.js?v=5"></script>
    <script src="../../assets/js/i18n.js?v=3"></script>
    <link rel="stylesheet" href="../../assets/css/mobile.css?v=196">
</head>
<body data-analyst-id="<?php echo $analystId; ?>" class="ev-report-body">
    <?php include '../includes/header.php'; ?>

    <div class="ev-page">
    <?php if ($err): ?>
        <div class="ev-empty"><?php echo htmlspecialchars($err); ?></div>
    <?php else:
        $s = $r['series']; $sum = $r['summary'];
        $qs = fn(array $extra = []) => htmlspecialchars(http_build_query(array_merge(['series' => $rid, 'from' => $from, 'to' => $to], $extra)));
    ?>
        <div class="ev-head">
            <div>
                <div class="ev-kicker"><?php echo $T('report_kicker'); ?></div>
                <h2><?php echo htmlspecialchars($s['title']); ?></h2>
                <div class="ev-sub"><?php echo htmlspecialchars(TaskEvidence::describeRule($s['rule'])); ?> ·
                    <span class="ev-pill ev-state-<?php echo htmlspecialchars($s['state']); ?>"><?php echo $T('state_' . $s['state']); ?></span></div>
            </div>
            <div class="ev-actions no-print">
                <a class="btn btn-primary" href="report.php?<?php echo $qs(['print' => 1]); ?>" target="_blank" rel="noopener"><?php echo $T('print'); ?></a>
                <a class="btn btn-secondary" href="../../api/tasks/evidence_export.php?<?php echo $qs(['file' => 'occurrences']); ?>"><?php echo $T('csv_occurrences'); ?></a>
                <a class="btn btn-secondary" href="../../api/tasks/evidence_export.php?<?php echo $qs(['file' => 'checklist_results']); ?>"><?php echo $T('csv_checklists'); ?></a>
                <a class="btn btn-secondary" href="../../api/tasks/evidence_export.php?<?php echo $qs(['file' => 'evidence']); ?>"><?php echo $T('csv_evidence'); ?></a>
                <a class="btn btn-secondary" href="../../api/tasks/evidence_export.php?<?php echo $qs(['file' => 'form_answers']); ?>"><?php echo $T('csv_forms'); ?></a>
            </div>
        </div>

        <form class="ev-period no-print" method="get">
            <input type="hidden" name="series" value="<?php echo $rid; ?>">
            <label><?php echo $T('from'); ?> <input type="date" name="from" value="<?php echo htmlspecialchars($from); ?>"></label>
            <label><?php echo $T('to'); ?> <input type="date" name="to" value="<?php echo htmlspecialchars($to); ?>"></label>
            <button type="submit" class="btn btn-secondary"><?php echo $T('show'); ?></button>
        </form>

        <p class="ev-meta"><?php echo $T('meta', ['from' => TaskEvidence::day($from), 'to' => TaskEvidence::day($to),
            'tz' => $r['period']['tz'], 'produced' => TaskEvidence::when($r['period']['produced_utc']),
            'who' => (string)($_SESSION['analyst_name'] ?? '')]); ?></p>

        <?php foreach ($r['notes'] as $n): ?>
            <div class="ev-note"><?php
                if ($n['kind'] === 'rule_not_recorded') echo $T('note_rule_not_recorded', ['date' => TaskEvidence::day($n['before'])]);
                elseif ($n['kind'] === 'stopped') echo $T($n['to'] === '9999-12-31' ? 'note_stopped_open' : 'note_stopped',
                    ['from' => TaskEvidence::day($n['from']), 'to' => TaskEvidence::day($n['to']), 'by' => $n['by']]);
                elseif ($n['kind'] === 'completion_mode') echo $T('note_completion_mode');
            ?></div>
        <?php endforeach; ?>
        <?php if ($r['hidden']): ?><div class="ev-note"><?php echo $T('note_hidden', ['n' => $r['hidden']]); ?></div><?php endif; ?>

        <div class="ev-summary">
            <?php foreach (['expected', 'on_time', 'on_time_rescheduled', 'late', 'overdue', 'open', 'skipped', 'missing', 'not_created_yet', 'removed', 'exceptions', 'created_late', 'not_recorded'] as $k):
                if ($k !== 'expected' && empty($sum[$k])) continue; ?>
                <div class="ev-chip ev-chip-<?php echo $k; ?>"><span><?php echo (int)$sum[$k]; ?></span><?php echo $T('sum_' . $k); ?></div>
            <?php endforeach; ?>
        </div>

        <?php if (!$r['rows']): ?>
            <div class="ev-empty"><?php echo $T('none_in_period'); ?></div>
        <?php else: ?>
        <div class="ev-table-wrap">
        <table class="ev-table">
            <thead><tr>
                <th><?php echo $T('col_scheduled'); ?></th>
                <th><?php echo $T('col_due'); ?></th>
                <th><?php echo $T('col_task'); ?></th>
                <th><?php echo $T('col_outcome'); ?></th>
                <th><?php echo $T('col_created'); ?></th>
                <th><?php echo $T('col_completed'); ?></th>
                <th><?php echo $T('col_assigned'); ?></th>
                <th><?php echo $T('col_exceptions'); ?></th>
            </tr></thead>
            <tbody>
            <?php foreach ($r['rows'] as $row): ?>
                <tr class="ev-row-<?php echo htmlspecialchars($row['outcome']); ?>">
                    <td><?php echo htmlspecialchars(TaskEvidence::day($row['scheduled'])); ?>
                        <?php /* GH #190 phase 4: the period it checks, and a due date set apart from the scheduled day. */ ?>
                        <?php if (!empty($row['period_start'])): ?><div class="ev-sub"><?php echo $T('period', ['from' => TaskEvidence::day($row['period_start']), 'to' => TaskEvidence::day($row['period_end'])]); ?></div><?php endif; ?>
                        <?php if (!empty($row['original_due']) && $row['original_due'] !== $row['scheduled']): ?><div class="ev-sub"><?php echo $T('original_due', ['date' => TaskEvidence::day($row['original_due'])]); ?></div><?php endif; ?></td>
                    <td class="ev-sub"><?php
                        if (!empty($row['due_at_completion']) && $row['due_at_completion'] !== $row['scheduled']) echo $T('due_at_completion', ['d' => TaskEvidence::day($row['due_at_completion'])]) . '<br>';
                        if (!empty($row['current_due']) && $row['current_due'] !== ($row['due_at_completion'] ?? $row['scheduled'])) echo $T('due_now', ['d' => TaskEvidence::day($row['current_due'])]);
                    ?></td>
                    <td><?php if ($row['task_id']): ?>#<?php echo (int)$row['task_id']; ?><?php if (!empty($row['status'])): ?><div class="ev-sub"><?php echo htmlspecialchars($row['status']); ?></div><?php endif; ?><?php else: ?>-<?php endif; ?></td>
                    <td><?php echo evOutcome($row['outcome']); ?> <?php echo evFlags($row); ?>
                        <?php if (!empty($row['skip_reason'])): ?><div class="ev-sub"><?php echo $T('skip_reason_is', ['reason' => $row['skip_reason']]); ?></div><?php endif; ?>
                        <?php if (!empty($row['removed'])): ?><div class="ev-sub"><?php echo $T('removed_by', ['who' => $row['removed']['by'] ?: '-', 'when' => TaskEvidence::when($row['removed']['at_utc']), 'before' => t('tasks.evidence.outcome_' . $row['removed']['outcome_before'])]); ?></div><?php endif; ?>
                        <?php if (!empty($row['missing_successor_of'])): ?><div class="ev-sub"><?php echo $T('successor_of', ['id' => $row['missing_successor_of']]); ?></div><?php endif; ?>
                        <?php if (!empty($row['explanation'])): ?><div class="ev-explain"><?php echo evExplain($row['explanation']); ?></div><?php endif; ?>
                        <?php if (isset($row['gap_days'])): ?><div class="ev-sub"><?php echo $T('gap_days', ['n' => $row['gap_days']]); ?></div><?php endif; ?></td>
                    <td class="ev-sub"><?php echo htmlspecialchars(TaskEvidence::when($row['created_utc'] ?? null)); ?></td>
                    <td class="ev-sub"><?php echo htmlspecialchars(TaskEvidence::when($row['completed_utc'] ?? null)); ?>
                        <?php if (!empty($row['completed_by'])): ?><br><?php echo htmlspecialchars($row['completed_by']); ?><?php endif; ?></td>
                    <td class="ev-sub"><?php echo htmlspecialchars(trim(($row['assignee'] ?? '') . (!empty($row['team']) ? ' / ' . $row['team'] : ''), ' /')); ?></td>
                    <td><?php foreach ($row['exceptions'] ?? [] as $x): ?>
                        <div class="ev-exception"><?php echo $x['kind'] === 'failed_step'
                            ? $T('exception_failed', ['checklist' => $x['checklist'], 'step' => $x['step'], 'note' => $x['note'] ?? ''])
                            : ($x['kind'] === 'form_answer'
                                ? ($x['answer'] !== null ? $T('exception_form', ['form' => $x['form'], 'question' => $x['question'], 'answer' => $x['answer']])
                                                         : $T('exception_form_hidden', ['form' => $x['form']]))
                                : $T('exception_outstanding', ['note' => $x['note'] ?? ''])); ?></div>
                    <?php endforeach; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>

        <h3 class="ev-section"><?php echo $T('details_heading'); ?></h3>
        <?php foreach ($r['rows'] as $row): if (!$row['task_id']) continue; ?>
            <details class="ev-detail">
                <summary><strong>#<?php echo (int)$row['task_id']; ?></strong> · <?php echo htmlspecialchars(TaskEvidence::day($row['scheduled'])); ?> · <?php echo evOutcome($row['outcome']); ?></summary>
                <?php foreach ($row['checklists'] as $c): ?>
                    <div class="ev-checklist">
                        <div class="ev-checklist-title"><?php echo htmlspecialchars($c['title']); ?>
                            <?php if ($c['template_version']): ?><span class="ev-sub"><?php echo $T('template_version', ['n' => $c['template_version']]); ?></span><?php endif; ?></div>
                        <table class="ev-steps">
                            <?php foreach ($c['items'] as $it): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($it['title']); ?><?php if ($it['mandatory']): ?> <span class="ev-sub">(<?php echo $T('mandatory'); ?>)</span><?php endif; ?></td>
                                <td><?php echo $it['result'] ? '<span class="ev-pill ev-res-' . htmlspecialchars($it['result']) . '">' . $T('result_' . $it['result']) . '</span>' : ($it['done'] ? $T('ticked') : $T('not_done')); ?>
                                    <?php if ($it['value'] !== null && $it['value'] !== ''): ?><div class="ev-sub"><?php echo htmlspecialchars($it['value']); ?></div><?php endif; ?>
                                    <?php if ($it['exception_note']): ?><div class="ev-exception"><?php echo htmlspecialchars($it['exception_note']); ?></div><?php endif; ?></td>
                                <td class="ev-sub"><?php echo htmlspecialchars(trim(($it['by'] ?? '') . ' ' . TaskEvidence::when($it['at_utc']))); ?></td>
                            </tr>
                            <?php endforeach; ?>

        <?php /* GH #190 phase 2: each procedure text the occurrences above were completed
                 against, ONCE - and only for a viewer who may read Knowledge. Shown as text:
                 this is the record of the words, not a rendering of the article. */ ?>
        <?php if (!empty($r['procedure_texts'])): ?>
            <h3 class="ev-section"><?php echo $T('procedures_used'); ?></h3>
            <?php foreach ($r['procedure_texts'] as $sid => $pt): ?>
                <details class="ev-detail">
                    <summary><strong><?php echo htmlspecialchars($pt['title']); ?></strong>
                        <span class="ev-sub"><?php echo $T('procedure_ref', ['ref' => (int)$sid, 'hash' => substr((string)$pt['content_hash'], 0, 12)]); ?></span></summary>
                    <div style="white-space:pre-wrap"><?php echo htmlspecialchars(trim(html_entity_decode(strip_tags(preg_replace('#<(br\s*/?|/p|/li|/h[1-6]|/div|/tr)>#i', "\n", (string)$pt['body'])), ENT_QUOTES | ENT_HTML5, 'UTF-8'))); ?></div>
                </details>
            <?php endforeach; ?>
        <?php elseif (!empty($r['procedure_texts_hidden'])): ?>
            <p class="ev-sub"><?php echo $T('procedures_hidden'); ?></p>
        <?php endif; ?>
                        </table>
                    </div>
                <?php endforeach; ?>
                <?php /* GH #190 phase 2: the procedures this occurrence followed, and the version
                         whose text was frozen when it was completed (the text itself is listed
                         once, under "Procedures used"). */ ?>
                <?php if (!empty($row['procedures'])): ?>
                    <div class="ev-checklist">
                        <div class="ev-checklist-title"><?php echo $T('procedures_heading'); ?></div>
                        <ul>
                        <?php foreach ($row['procedures'] as $p): ?>
                            <li><?php echo htmlspecialchars((string)$p['title']); ?>
                                <span class="ev-sub"><?php echo !empty($p['snapshot_id'])
                                    ? $T('procedure_frozen', ['n' => $p['article_version'] ?? '-', 'ref' => (int)$p['snapshot_id']])
                                    : $T('procedure_not_frozen'); ?></span></li>
                        <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>
                <?php /* GH #190 phase 3: follow-up tickets and tasks, with their state now. */ ?>
                <?php if (!empty($row['follow_ups'])): ?>
                    <div class="ev-checklist">
                        <div class="ev-checklist-title"><?php echo $T('follow_ups_heading'); ?></div>
                        <ul>
                        <?php foreach ($row['follow_ups'] as $fu): ?>
                            <li><?php echo htmlspecialchars((string)($fu['number'] ?? ($fu['kind'] . ' #' . $fu['record_id']))); ?>
                                <?php if (!empty($fu['title'])): ?> - <?php echo htmlspecialchars($fu['title']); ?><?php endif; ?>
                                <span class="ev-sub">(<?php echo $T($fu['open'] === null ? 'follow_up_gone' : ($fu['open'] ? 'follow_up_open' : 'follow_up_closed')); ?><?php if (!empty($fu['created_here'])): ?>, <?php echo $T('follow_up_raised_here'); ?><?php endif; ?>)</span></li>
                        <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <?php /* GH #190: the forms this occurrence needed, who submitted them, and the
                         answers - only where the viewer may read them (TaskForms::mayReadAnswers). */ ?>
                <?php foreach ($row['forms'] ?? [] as $f): ?>
                    <div class="ev-checklist">
                        <div class="ev-checklist-title"><?php echo htmlspecialchars($f['title']); ?>
                            <span class="ev-sub"><?php echo $T('form_label'); ?><?php if ($f['required']): ?> · <?php echo $T('form_required'); ?><?php endif; ?></span></div>
                        <?php if (!$f['submission_id']): ?>
                            <div class="ev-sub"><?php echo $T('form_not_submitted'); ?></div>
                        <?php else: ?>
                            <div class="ev-sub"><?php echo $T('form_submitted', ['who' => (string)($f['by'] ?? '-'), 'when' => TaskEvidence::when($f['at_utc'])]); ?>
                                <?php if ($f['earlier']): ?> · <?php echo $T('form_earlier', ['n' => $f['earlier']]); ?><?php endif; ?></div>
                            <?php if ($f['answers_hidden']): ?>
                                <div class="ev-sub"><em><?php echo htmlspecialchars(t('tasks.forms.no_answer_access')); ?></em></div>
                            <?php else: ?>
                                <table class="ev-steps">
                                    <?php foreach ($f['answers'] ?? [] as $a): ?>
                                    <tr><td><?php echo htmlspecialchars($a['label']); ?></td><td style="white-space:pre-wrap"><?php echo htmlspecialchars($a['value']); ?></td></tr>
                                    <?php endforeach; ?>
                                </table>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
                <?php if ($row['events'] || $row['steps']): ?>
                    <div class="ev-history">
                        <div class="ev-checklist-title"><?php echo $T('history'); ?></div>
                        <ul>
                        <?php foreach ($row['events'] as $e): ?>
                            <li><span class="ev-sub"><?php echo htmlspecialchars(TaskEvidence::when($e['created_datetime'])); ?></span>
                                <?php echo htmlspecialchars(t('tasks.evidence.event_' . $e['event_type'], [
                                    'from' => (string)($e['from_value'] ?? ''), 'to' => (string)($e['to_value'] ?? ''),
                                    'who' => (string)($e['actor_name'] ?: t('tasks.history.by_system'))])); ?></li>
                        <?php endforeach; ?>
                        <?php foreach ($row['steps'] as $st): $after = json_decode((string)$st['after_json'], true) ?: []; ?>
                            <li><span class="ev-sub"><?php echo htmlspecialchars(TaskEvidence::when($st['created_datetime'])); ?></span>
                                <?php echo $T('step_event', ['step' => $st['item_title'], 'action' => t('tasks.evidence.step_' . $st['action']),
                                    'who' => (string)($st['actor_name'] ?? '-'), 'result' => isset($after['result']) ? t('tasks.evidence.result_' . $after['result']) : '']); ?>
                                <?php if (!empty($st['after_completion'])): ?><span class="ev-flag"><?php echo $T('flag_changed_after_completion'); ?></span><?php endif; ?></li>
                        <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>
                <?php if (!empty($row['ticket_id']) || !empty($row['change_id'])): ?>
                    <div class="ev-sub"><?php echo $T('linked', ['ticket' => $row['ticket_id'] ? '#' . $row['ticket_id'] : '-', 'change' => $row['change_id'] ? '#' . $row['change_id'] : '-']); ?></div>
                <?php endif; ?>
            </details>
        <?php endforeach; ?>
        <?php endif; ?>

        <h3 class="ev-section"><?php echo $T('versions_heading'); ?></h3>
        <div class="ev-table-wrap">
        <table class="ev-table">
            <thead><tr><th>#</th><th><?php echo $T('col_when'); ?></th><th><?php echo $T('col_change'); ?></th><th><?php echo $T('col_rule'); ?></th><th><?php echo $T('col_by'); ?></th></tr></thead>
            <tbody>
            <?php foreach ($r['versions'] as $v): ?>
                <tr><td><?php echo (int)$v['version_no']; ?></td>
                    <td class="ev-sub"><?php echo htmlspecialchars(TaskEvidence::when($v['created_datetime'])); ?></td>
                    <td><?php echo $T('version_' . $v['change_kind']); ?><?php if (!empty($v['rule']['cause'])): ?> <span class="ev-sub">(<?php echo $T('cause_' . $v['rule']['cause']); ?>)</span><?php endif; ?></td>
                    <td class="ev-sub"><?php echo htmlspecialchars(TaskEvidence::describeRule($v['rule'])); ?></td>
                    <td class="ev-sub"><?php echo htmlspecialchars($v['actor_name'] ?: t('tasks.history.by_system')); ?></td></tr>
            <?php endforeach; ?>
            <?php if (!$r['versions']): ?><tr><td colspan="5" class="ev-sub"><?php echo $T('no_versions'); ?></td></tr><?php endif; ?>
            </tbody>
        </table>
        </div>

        <?php if ($r['exports']): ?>
        <h3 class="ev-section"><?php echo $T('exports_heading'); ?></h3>
        <ul class="ev-exports">
            <?php foreach ($r['exports'] as $x): $d = json_decode((string)$x['detail_json'], true) ?: []; ?>
                <li><?php echo $T('export_line', ['when' => TaskEvidence::when($x['created_datetime']), 'who' => (string)$x['actor_name'],
                    'what' => t('tasks.evidence.export_' . $x['to_value']), 'from' => TaskEvidence::day($d['from'] ?? null), 'to' => TaskEvidence::day($d['to'] ?? null)]); ?></li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>
    <?php endif; ?>
    </div>
    <script>
        // A closed <details> prints nothing, so open every one for the printout.
        window.addEventListener('beforeprint', () => document.querySelectorAll('details.ev-detail').forEach(d => d.open = true));
        <?php if (!empty($_GET['print']) && !$err): ?>
        window.addEventListener('load', () => setTimeout(() => window.print(), 300));
        <?php endif; ?>
    </script>
</body>
</html>
