<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * Forms a task occurrence needs (GH #190, phase 1).
 *
 *   php tests/task-forms.php [base-url]
 *
 * Builds a throwaway form ('ZZ-TF ...') and throwaway tasks, and proves, each with
 * a positive control: the requirement uses the form's newest version; the ONE
 * completion gate refuses (block) or warns (warn) while a required form is not
 * submitted, and lets go once it is; the shared draft's revision check turns a
 * stale save into a conflict naming who saved; a stale SUBMIT is a conflict that
 * leaves no submission behind; a retried submit returns the same submission; a
 * correction supersedes; the evidence cannot be deleted from Forms; the repeat
 * copies the requirement without the answers; the frozen record keeps the answers;
 * deleting the task keeps the submission; the company scope hides it from others.
 * With a base URL, drives api/tasks/forms.php over HTTP as an administrator.
 *
 * Tidies everything it made - history included (it outlives tasks, #181) - and
 * restores the settings it changed.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../includes/services/tasks.php';
require_once __DIR__ . '/../includes/services/forms.php';
require_once __DIR__ . '/../includes/services/checklists.php';
require_once __DIR__ . '/../includes/services/task_forms.php';
require_once __DIR__ . '/../includes/task_evidence.php';
require_once __DIR__ . '/../includes/services/task_procedures.php';
require_once __DIR__ . '/../includes/services/task_follow_ups.php';
require_once __DIR__ . '/../includes/services/task_recurrence.php';

$BASE = isset($argv[1]) ? rtrim($argv[1], '/') . '/' : null;
$pass = 0; $fail = 0;
function ok(string $what, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  PASS  {$what}\n"; }
    else       { $fail++; echo "  FAIL  {$what}" . ($detail ? "  <- {$detail}" : '') . "\n"; }
}
function section(string $t): void { echo "\n{$t}\n"; }

$conn = connectToDatabase();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
if (!TaskForms::columnsReady($conn)) { echo "Run Database Verification first (task_forms / form_submissions.task_id missing).\n"; exit(1); }
$admin = (int)$conn->query("SELECT id FROM analysts WHERE is_active = 1 AND is_admin = 1 ORDER BY id LIMIT 1")->fetchColumn();
$ctx   = new ActorContext($admin, null, 'ui', 'en', 'ZZ forms tester');
$other = new ActorContext($admin, null, 'ui', 'en', 'ZZ second analyst');
$open  = (int)$conn->query("SELECT id FROM task_statuses WHERE is_active=1 AND is_closed=0 ORDER BY is_default DESC, display_order, id LIMIT 1")->fetchColumn();

$tids = []; $formIds = []; $rids = [];
$keys = ['tasks_forms_enabled', 'tasks_evidence_completed_checklist', 'tasks_procedures_enabled', 'tasks_checklists_enabled', 'tasks_evidence_open_followups'];
$maxSnap = (int)$conn->query("SELECT COALESCE(MAX(id), 0) FROM task_procedure_snapshots")->fetchColumn();
$tplIds = [];
$settingsBefore = $conn->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('" . implode("','", $keys) . "')")->fetchAll(PDO::FETCH_KEY_PAIR);

function setSetting(PDO $c, string $k, string $v): void {
    $c->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")->execute([$k, $v]);
}
function zzTask(PDO $c, string $title = 'ZZ-TF occurrence'): int {
    global $tids, $open, $admin;
    $c->prepare("INSERT INTO tasks (title, status_id, due_date, scheduled_date, created_by_id, created_datetime, updated_datetime)
                 VALUES (?, ?, CURDATE(), CURDATE(), ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())")->execute([$title, $open, $admin]);
    return $tids[] = (int)$c->lastInsertId();
}
function cleanup(): void {
    global $conn, $tids, $formIds, $settingsBefore, $keys, $rids, $maxSnap, $tplIds;
    try {
        $conn->exec("DELETE FROM task_procedure_snapshots WHERE id > " . (int)$maxSnap);
        foreach ($tplIds as $tid) {
            $conn->exec("DELETE FROM checklist_template_items WHERE template_id = " . (int)$tid);
            $conn->exec("DELETE FROM checklist_template_versions WHERE template_id = " . (int)$tid);
            $conn->exec("DELETE FROM checklist_templates WHERE id = " . (int)$tid);
        }
        foreach ($rids as $rid) {
            $conn->exec("DELETE FROM task_events WHERE recurrence_id = " . (int)$rid);
            $conn->exec("DELETE FROM task_recurrence_versions WHERE recurrence_id = " . (int)$rid);
            $conn->exec("UPDATE tasks SET recurrence_id = NULL WHERE recurrence_id = " . (int)$rid);
            $conn->exec("DELETE FROM task_recurrences WHERE id = " . (int)$rid);
        }
        if ($tids) {
            $p = implode(',', array_map('intval', $tids));
            $conn->exec("DELETE FROM task_events WHERE task_id IN ($p)");
            $conn->exec("DELETE FROM task_removed_records WHERE task_id IN ($p)");
            $conn->exec("DELETE FROM task_audit WHERE task_id IN ($p)");
            $conn->exec("DELETE FROM task_forms WHERE task_id IN ($p)");
            $conn->exec("DELETE FROM task_procedures WHERE task_id IN ($p)");
            $conn->exec("DELETE FROM task_follow_ups WHERE task_id IN ($p)");
            $conn->exec("DELETE i FROM task_checklist_items i JOIN task_checklists c ON c.id = i.task_checklist_id WHERE c.task_id IN ($p)");
            $conn->exec("DELETE FROM task_checklist_item_events WHERE task_id IN ($p)");
            $conn->exec("DELETE FROM task_checklists WHERE task_id IN ($p)");
            $conn->exec("DELETE FROM tasks WHERE id IN ($p)");
        }
        if ($formIds) {
            $p = implode(',', array_map('intval', $formIds));
            $conn->exec("DELETE sd FROM form_submission_data sd JOIN form_submissions s ON s.id = sd.submission_id WHERE s.form_id IN ($p)");
            $conn->exec("DELETE FROM form_submissions WHERE form_id IN ($p)");
            $conn->exec("DELETE FROM form_fields WHERE form_id IN ($p)");
            foreach (array_reverse($formIds) as $f) $conn->exec("DELETE FROM forms WHERE id = " . (int)$f);
        }
        $conn->exec("DELETE FROM system_settings WHERE setting_key IN ('" . implode("','", $keys) . "')");
        $ins = $conn->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?)");
        foreach ($settingsBefore as $k => $v) $ins->execute([$k, $v]);
    } catch (Throwable $e) {
        echo "CLEANUP FAILED: " . $e->getMessage() . "\n";
    }
}
register_shutdown_function('cleanup');

function threw(callable $fn, ?string $class = null): ?Throwable {
    try { $fn(); return null; } catch (Throwable $e) { return ($class === null || $e instanceof $class) ? $e : $e; }
}

// ── The form: a daily backup check ──────────────────────────────────────────
$v1 = FormsService::saveForm($conn, $ctx, ['title' => 'ZZ-TF Backup check', 'is_active' => 1, 'fields' => [
    ['field_type' => 'radio', 'label' => 'Result', 'is_required' => 1, 'options' => ['OK', 'Warning', 'Failure']],
    ['field_type' => 'textarea', 'label' => 'Explanation', 'is_required' => 0],
]]);
$formIds[] = $v1['id'];
$v2 = FormsService::createVersion($conn, $ctx, $v1['id']);
$formIds[] = $v2['id'];
$fid = $conn->prepare("SELECT id, label FROM form_fields WHERE form_id = ? ORDER BY sort_order, id");
$fid->execute([$v2['id']]);
$fields = $fid->fetchAll(PDO::FETCH_KEY_PAIR);
$resultField = (int)array_search('Result', $fields, true);
$explField   = (int)array_search('Explanation', $fields, true);

section('1. Switched off, nothing is asked');
setSetting($conn, 'tasks_forms_enabled', '0');
$t1 = zzTask($conn);
$conn->prepare("INSERT INTO task_forms (task_id, form_id, title, is_required, requirement_mode) VALUES (?, ?, 'ZZ-TF Backup check', 1, 'block')")->execute([$t1, $v2['id']]);
ok('the gate ignores task forms while the feature is off', ChecklistsService::taskCompletionCheck($conn, $t1)['blocking'] === []);
$conn->prepare("DELETE FROM task_forms WHERE task_id = ?")->execute([$t1]);
setSetting($conn, 'tasks_forms_enabled', '1');

section('2. Adding a form uses its newest version');
$tf = TaskForms::add($conn, $ctx, $t1, $v1['id'], true, 'block');
$row = TaskForms::row($conn, $tf);
ok('added from version 1, it is filled on version 2 (the leaf)', (int)$row['form_id'] === (int)$v2['id'], 'form_id ' . $row['form_id']);
ok('a second add of the same form is refused', threw(fn() => TaskForms::add($conn, $ctx, $t1, $v2['id'])) instanceof ServiceError);
ok('history: form_added', (int)$conn->query("SELECT COUNT(*) FROM task_events WHERE task_id = $t1 AND event_type = 'form_added'")->fetchColumn() === 1);

section('3. The one completion gate');
$chk = ChecklistsService::taskCompletionCheck($conn, $t1);
ok('a required, blocking form that is not submitted blocks', !empty($chk['blocking']), json_encode($chk));
$e = threw(fn() => ChecklistsService::assertTaskCompletionAllowed($conn, $t1));
ok('...and assertTaskCompletionAllowed() refuses, naming the form', $e instanceof ServiceError && str_contains($e->getMessage(), 'ZZ-TF Backup check'), $e ? $e->getMessage() : 'no throw');
TaskForms::update($conn, $ctx, $tf, true, 'warn');
$chk = ChecklistsService::taskCompletionCheck($conn, $t1);
ok('set to warn, it warns and does not block', !$chk['blocking'] && !empty($chk['warning']), json_encode($chk));
TaskForms::update($conn, $ctx, $tf, false, 'block');
ok('optional, it neither blocks nor warns', ChecklistsService::taskCompletionCheck($conn, $t1) === ['blocking' => [], 'warning' => []]);
TaskForms::update($conn, $ctx, $tf, true, 'block');

section('4. The shared draft, and two people at once');
$d = TaskForms::saveDraft($conn, $ctx, $tf, 0, [(string)$resultField => 'Warning']);
ok('a draft saved from revision 0 moves it to 1', $d['revision'] === 1);
$e = threw(fn() => TaskForms::saveDraft($conn, $other, $tf, 0, [(string)$resultField => 'OK']));
ok('a second save from the STALE revision 0 is a conflict', $e instanceof TaskFormConflict, $e ? get_class($e) : 'no throw');
ok('...naming who saved, and carrying their draft', $e instanceof TaskFormConflict && $e->detail['by'] === 'ZZ forms tester'
    && ($e->detail['draft'][(string)$resultField] ?? '') === 'Warning', $e instanceof TaskFormConflict ? json_encode($e->detail) : '');
ok('...and nothing was overwritten', json_decode((string)TaskForms::row($conn, $tf)['draft_json'], true)[(string)$resultField] === 'Warning');
$d = TaskForms::saveDraft($conn, $other, $tf, 1, [(string)$resultField => 'OK']);
ok('"keep mine" saves on top from the revision it was shown (1 -> 2)', $d['revision'] === 2);
ok('the draft says who saved it last', TaskForms::row($conn, $tf)['draft_by_name'] === 'ZZ second analyst');

section('5. Submitting');
$before = (int)$conn->query("SELECT COUNT(*) FROM form_submissions WHERE task_id = $t1")->fetchColumn();
$e = threw(fn() => TaskForms::submit($conn, $ctx, $tf, 1, 'ref-stale', [(string)$resultField => 'OK']));
ok('a submit from a stale revision is a conflict', $e instanceof TaskFormConflict, $e ? $e->getMessage() : 'no throw');
ok('...and leaves NO submission behind (rolled back with it)', (int)$conn->query("SELECT COUNT(*) FROM form_submissions WHERE task_id = $t1")->fetchColumn() === $before);
$e = threw(fn() => TaskForms::submit($conn, $ctx, $tf, 2, 'ref-invalid', [(string)$explField => 'no result given']));
ok('a submit missing a required answer is refused by the Forms validation', $e instanceof ServiceError && !($e instanceof TaskFormConflict), $e ? $e->getMessage() : 'no throw');
ok('...and links nothing', TaskForms::row($conn, $tf)['submission_id'] === null);
$s = TaskForms::submit($conn, $ctx, $tf, 2, 'ref-one', [(string)$resultField => 'Warning', (string)$explField => 'Job 4 retried']);
$sub = $conn->query("SELECT * FROM form_submissions WHERE id = " . (int)$s['submission_id'])->fetch(PDO::FETCH_ASSOC);
ok('submitted: linked to the occurrence, with its retry key', (int)$sub['task_id'] === $t1 && $sub['client_reference'] === 'ref-one');
ok('...on the form version the requirement names', (int)$sub['form_id'] === (int)$v2['id']);
$row = TaskForms::row($conn, $tf);
ok('the requirement is met and the draft cleared', (int)$row['submission_id'] === $s['submission_id'] && $row['draft_json'] === null);
ok('history: form_submitted', (int)$conn->query("SELECT COUNT(*) FROM task_events WHERE task_id = $t1 AND event_type = 'form_submitted'")->fetchColumn() === 1);
$r = TaskForms::submit($conn, $ctx, $tf, 2, 'ref-one', [(string)$resultField => 'Warning']);
ok('a RETRY with the same reference returns the same submission', $r['replayed'] === true && $r['submission_id'] === $s['submission_id']);
ok('...and makes no second one', (int)$conn->query("SELECT COUNT(*) FROM form_submissions WHERE task_id = $t1")->fetchColumn() === $before + 1);
ok('the gate now lets it complete', ChecklistsService::taskCompletionCheck($conn, $t1)['blocking'] === []);
$ans = TaskForms::answers($conn, $s['submission_id']);
ok('answers read back with their labels', count($ans) === 2 && $ans[0]['label'] === 'Result' && $ans[0]['value'] === 'Warning', json_encode($ans));

section('6. Correcting a submission');
$fill = TaskForms::forFill($conn, $tf);
ok('the fill page starts from what was submitted', ($fill['prefill'][(string)$resultField] ?? '') === 'Warning');
$s2 = TaskForms::submit($conn, $ctx, $tf, $fill['revision'], 'ref-two', [(string)$resultField => 'Failure', (string)$explField => 'Job 4 failed again']);
ok('a correction replaces it on the task', (int)TaskForms::row($conn, $tf)['submission_id'] === $s2['submission_id']);
ok('...the old one is kept, marked superseded', (int)$conn->query("SELECT superseded_by_id FROM form_submissions WHERE id = " . (int)$s['submission_id'])->fetchColumn() === $s2['submission_id']);
ok('history: form_replaced', (int)$conn->query("SELECT COUNT(*) FROM task_events WHERE task_id = $t1 AND event_type = 'form_replaced'")->fetchColumn() === 1);

section('7. The evidence cannot be deleted from Forms');
$e = threw(fn() => FormsService::deleteSubmission($conn, $ctx, $s2['submission_id']));
ok('deleting the submission is refused', $e instanceof ServiceError && $e->errorCode === 'task_evidence', $e ? $e->getMessage() : 'no throw');
$e = threw(fn() => FormsService::deleteForm($conn, $ctx, $v2['id']));
ok('deleting the form is refused', $e instanceof ServiceError && $e->errorCode === 'task_evidence', $e ? $e->getMessage() : 'no throw');
$plain = FormsService::submitForm($conn, $ctx, $v2['id'], [(string)$resultField => 'OK']);
ok('control: an ordinary submission (no task) still submits and deletes', FormsService::deleteSubmission($conn, $ctx, $plain) === $plain);

section('8. The repeat copies the requirement, not the answers');
$t2 = zzTask($conn, 'ZZ-TF next occurrence');
$v3 = FormsService::createVersion($conn, $ctx, $v2['id']);
$formIds[] = $v3['id'];
TaskForms::copyForOccurrence($conn, $t1, $t2);
$n = $conn->query("SELECT * FROM task_forms WHERE task_id = $t2")->fetch(PDO::FETCH_ASSOC);
ok('the next occurrence needs the same form', $n && (int)$n['is_required'] === 1 && $n['requirement_mode'] === 'block');
ok('...unanswered, with no draft', $n && $n['submission_id'] === null && $n['draft_json'] === null);
ok('...on the form\'s newest version at that moment', $n && (int)$n['form_id'] === (int)$v3['id'], $n ? $n['form_id'] : '');
ok('...so the next occurrence is blocked until it is filled in', !empty(ChecklistsService::taskCompletionCheck($conn, $t2)['blocking']));

section('9. The frozen record, and deleting the task');
$snap = TaskHistory::snapshot($conn, $t1);
ok('the #181 snapshot carries the forms', isset($snap['forms']['requirements'][0]) && count($snap['forms']['submissions']) === 2);
ok('...with the answers and their labels', ($snap['forms']['submissions'][1]['answers'][0]['value'] ?? '') === 'Failure');
TasksService::deleteTask($conn, $ctx, $t2);
$tids = array_values(array_diff($tids, [$t2]));
ok('deleting a task removes its requirements', (int)$conn->query("SELECT COUNT(*) FROM task_forms WHERE task_id = $t2")->fetchColumn() === 0);
$conn->exec("DELETE FROM task_events WHERE task_id = $t2");
$conn->exec("DELETE FROM task_removed_records WHERE task_id = $t2");

section('10. Company scope in the Forms lists');
[$sql, $args] = TaskForms::submissionScopeSql($conn, [987654], 's');
$q = $conn->prepare("SELECT COUNT(*) FROM form_submissions s WHERE s.form_id = ? AND $sql");
$q->execute(array_merge([$v2['id']], $args));
ok('an analyst of another company does not see the task\'s submissions', (int)$q->fetchColumn() === 0);
[$sql, $args] = TaskForms::submissionScopeSql($conn, null, 's');
$q = $conn->prepare("SELECT COUNT(*) FROM form_submissions s WHERE s.form_id = ? AND $sql");
$q->execute(array_merge([$v2['id']], $args));
ok('control: an all-companies analyst sees both', (int)$q->fetchColumn() === 2);

section('11. The access rule');
ok('an administrator may read the answers', TaskForms::mayReadAnswers($conn, $admin));
$noForms = null;
foreach ($conn->query("SELECT id FROM analysts WHERE is_active = 1 AND is_admin = 0 ORDER BY id")->fetchAll(PDO::FETCH_COLUMN) as $aid) {
    if (analystModuleLevel($conn, (int)$aid, 'forms') === null && !analystMayAct($conn, (int)$aid, Cap::TASKS_FORM_ANSWERS)) { $noForms = (int)$aid; break; }
}
if ($noForms) {
    ok("an analyst with neither Forms nor the permission may not (#$noForms)", !TaskForms::mayReadAnswers($conn, $noForms));
    $list = TaskForms::forTask($conn, $t1, $noForms, true);
    ok('...and forTask() gives them the state but no answers', isset($list[0]) && $list[0]['may_read'] === false && !isset($list[0]['answers']));
} else {
    echo "  SKIP  no analyst here lacks Forms access, so the negative case cannot be shown on this data\n";
}
ok('Read form answers on tasks is always restricted (granted only by a role)', capActionRestricted($conn, Cap::TASKS_FORM_ANSWERS) === true);

section('12. A finished task\'s forms follow the #181 lock');
setSetting($conn, 'tasks_evidence_completed_checklist', 'refuse');
$closed = (int)$conn->query("SELECT id FROM task_statuses WHERE is_active=1 AND is_closed=1 ORDER BY display_order, id LIMIT 1")->fetchColumn();
$conn->exec("UPDATE tasks SET status_id = $closed WHERE id = $t1");
$e = threw(fn() => TaskForms::saveDraft($conn, $ctx, $tf, (int)TaskForms::row($conn, $tf)['revision'], []));
ok('with "refused until reopened", a done task\'s form cannot be changed', $e instanceof ServiceError, $e ? $e->getMessage() : 'no throw');
setSetting($conn, 'tasks_evidence_completed_checklist', 'allow');
$d = TaskForms::saveDraft($conn, $ctx, $tf, (int)TaskForms::row($conn, $tf)['revision'], []);
ok('control: allowed, it can', $d['revision'] > 0);

section('12b. The #181 evidence report carries the forms');
$conn->exec("INSERT INTO task_recurrences (mode, freq, interval_n, ends_mode, occurrences_created, is_active, created_datetime, updated_datetime)
             VALUES ('schedule', 'daily', 1, 'never', 1, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP())");
$rid = $rids[] = (int)$conn->lastInsertId();
$conn->exec("UPDATE tasks SET recurrence_id = $rid WHERE id = $t1");
$rep = TaskEvidence::report($conn, $rid, date('Y-m-d', strtotime('-3 days')), date('Y-m-d', strtotime('+1 day')), $admin);
$row = null;
foreach ($rep['rows'] as $x) if ((int)($x['task_id'] ?? 0) === $t1) $row = $x;
ok('the occurrence row lists its form', $row && ($row['forms'][0]['title'] ?? '') === 'ZZ-TF Backup check', $row ? json_encode($row['forms'] ?? null) : 'no row');
ok('...with who submitted it and the answers, for a viewer who may read them', $row && ($row['forms'][0]['answers'][0]['value'] ?? '') === 'Failure');
ok('...and the earlier submission it replaced is counted', $row && (int)($row['forms'][0]['earlier'] ?? 0) === 1);
if ($noForms) {
    $rep2 = TaskEvidence::report($conn, $rid, date('Y-m-d', strtotime('-3 days')), date('Y-m-d', strtotime('+1 day')), $noForms);
    $row2 = null;
    foreach ($rep2['rows'] as $x) if ((int)($x['task_id'] ?? 0) === $t1) $row2 = $x;
    ok('for a viewer who may not: submitted, by whom - but no answers', $row2 && $row2['forms'][0]['answers'] === null && $row2['forms'][0]['answers_hidden'] === true
        && ($row2['forms'][0]['by'] ?? '') !== '', $row2 ? json_encode($row2['forms'][0]) : 'no row (company scope?)');
}

section('P2. Procedures (phase 2)');
setSetting($conn, 'tasks_procedures_enabled', '1');
$article = (int)$conn->query("SELECT id FROM knowledge_articles WHERE is_published = 1 AND is_archived = 0 ORDER BY id LIMIT 1")->fetchColumn();
if (!$article) {
    echo "  SKIP  no published Knowledge article here to reference\n";
} else {
    $t3 = zzTask($conn, 'ZZ-TF procedure occurrence');
    $pid = TaskProcedures::add($conn, $ctx, $t3, $article);
    $list = TaskProcedures::forTask($conn, $t3, $admin);
    ok('a procedure is listed with the live article for a reader', ($list[0]['may_read'] ?? false) === true && ($list[0]['body'] ?? null) !== null);
    ok('...not frozen while the occurrence is open', $list[0]['frozen'] === null);
    ok('history: procedure_added', (int)$conn->query("SELECT COUNT(*) FROM task_events WHERE task_id = $t3 AND event_type = 'procedure_added'")->fetchColumn() === 1);
    if ($noForms && !TaskProcedures::mayReadKnowledge($conn, $noForms)) {
        $l2 = TaskProcedures::forTask($conn, $t3, $noForms);
        ok('for a viewer without Knowledge: no title, no text', $l2[0]['may_read'] === false && $l2[0]['title'] === null && $l2[0]['body'] === null);
    }
    $closedName = (string)$conn->query("SELECT name FROM task_statuses WHERE is_active=1 AND is_closed=1 ORDER BY display_order, id LIMIT 1")->fetchColumn();
    TasksService::saveTask($conn, $ctx, ['id' => $t3, 'status' => $closedName]);
    $row = $conn->query("SELECT * FROM task_procedures WHERE id = $pid")->fetch(PDO::FETCH_ASSOC);
    ok('completing the occurrence freezes the text it was done against', $row['snapshot_id'] !== null && $row['article_version'] !== null);
    $snapRow = $conn->query("SELECT * FROM task_procedure_snapshots WHERE id = " . (int)$row['snapshot_id'])->fetch(PDO::FETCH_ASSOC);
    $live = $conn->query("SELECT title, body FROM knowledge_articles WHERE id = $article")->fetch(PDO::FETCH_ASSOC);
    ok('...the frozen text is the article as it stood', $snapRow && $snapRow['body'] === $live['body'] && $snapRow['title'] === $live['title']);
    $t4 = zzTask($conn, 'ZZ-TF procedure occurrence 2');
    TaskProcedures::add($conn, $ctx, $t4, $article);
    TasksService::saveTask($conn, $ctx, ['id' => $t4, 'status' => $closedName]);
    $s4 = (int)$conn->query("SELECT snapshot_id FROM task_procedures WHERE task_id = $t4")->fetchColumn();
    ok('a second occurrence done against the same text shares ONE snapshot', $s4 === (int)$row['snapshot_id']);
    $t5 = zzTask($conn, 'ZZ-TF procedure next');
    TaskProcedures::copyForOccurrence($conn, $t3, $t5);
    $c5 = $conn->query("SELECT article_id, snapshot_id FROM task_procedures WHERE task_id = $t5")->fetch(PDO::FETCH_ASSOC);
    ok('the repeat copies the procedure, unfrozen', $c5 && (int)$c5['article_id'] === $article && $c5['snapshot_id'] === null);
    $snapT3 = TaskHistory::snapshot($conn, $t3);
    ok('the #181 frozen record carries the procedure and its snapshot', (int)($snapT3['procedures'][0]['snapshot_id'] ?? 0) === (int)$row['snapshot_id']);
    $conn->exec("INSERT INTO task_recurrences (mode, freq, interval_n, ends_mode, occurrences_created, is_active, created_datetime, updated_datetime)
                 VALUES ('schedule', 'daily', 1, 'never', 1, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP())");
    $rid2 = $rids[] = (int)$conn->lastInsertId();
    $conn->exec("UPDATE tasks SET recurrence_id = $rid2 WHERE id IN ($t3, $t4)");
    $rep = TaskEvidence::report($conn, $rid2, date('Y-m-d', strtotime('-3 days')), date('Y-m-d', strtotime('+1 day')), $admin);
    ok('the evidence report lists each distinct procedure text once', count($rep['procedure_texts']) === 1 && isset($rep['procedure_texts'][(int)$row['snapshot_id']]));
    if ($noForms && !TaskProcedures::mayReadKnowledge($conn, $noForms)) {
        $rep2 = TaskEvidence::report($conn, $rid2, date('Y-m-d', strtotime('-3 days')), date('Y-m-d', strtotime('+1 day')), $noForms);
        if ($rep2['rows']) {
            ok('...and to a viewer without Knowledge, says the text is kept but not shown', $rep2['procedure_texts'] === [] && $rep2['procedure_texts_hidden'] === true);
        }
    }
}

section('P2. Step descriptions are copied to the task');
setSetting($conn, 'tasks_checklists_enabled', '1');
$conn->exec("INSERT INTO checklist_templates (title, scope, closure_mode, is_active, created_datetime) VALUES ('ZZ-TF template', 'task', 'warn', 1, UTC_TIMESTAMP())");
$tpl = $tplIds[] = (int)$conn->lastInsertId();
$conn->prepare("INSERT INTO checklist_template_items (template_id, title, description, is_mandatory, sort_order) VALUES (?, 'Check job 4', 'Open the console and read the last run', 1, 1)")->execute([$tpl]);
$t6 = zzTask($conn, 'ZZ-TF checklist occurrence');
ChecklistsService::attachTemplateToTask($conn, $ctx, $t6, $tpl);
$lists = ChecklistsService::taskChecklists($conn, $t6);
ok('a template step\'s description is on the task\'s step', ($lists[0]['items'][0]['description'] ?? '') === 'Open the console and read the last run', json_encode($lists[0]['items'][0] ?? null));
$t7 = zzTask($conn, 'ZZ-TF checklist next');
ChecklistsService::copyTaskChecklists($conn, $t6, $t7);
$lists7 = ChecklistsService::taskChecklists($conn, $t7);
ok('...and the repeat copies it to the next occurrence', ($lists7[0]['items'][0]['description'] ?? '') === 'Open the console and read the last run');

section('P3. Follow-ups (phase 3)');
$t8 = zzTask($conn, 'ZZ-TF follow-up source');
$fu = TaskFollowUps::createTask($conn, $ctx, $t8, 'ZZ-TF fix job 4', 'Job 4 failed twice', 'step', 123, 'fu-ref-1');
$tids[] = $fu['record_id'];
ok('a follow-up task is raised and linked', $fu['kind'] === 'task' && $fu['record_id'] > 0 && $fu['replayed'] === false);
$again = TaskFollowUps::createTask($conn, $ctx, $t8, 'ZZ-TF fix job 4', '', null, null, 'fu-ref-1');
ok('a retried Create with the same reference returns it - no second task', $again['replayed'] === true && $again['record_id'] === $fu['record_id']
    && (int)$conn->query("SELECT COUNT(*) FROM tasks WHERE title = 'ZZ-TF fix job 4'")->fetchColumn() === 1);
$new = $conn->query("SELECT tenant_id, description FROM tasks WHERE id = " . (int)$fu['record_id'])->fetch(PDO::FETCH_ASSOC);
ok('...in the occurrence\'s company, saying where it came from', str_contains((string)$new['description'], 'TASK-' . $t8));
$list = TaskFollowUps::forTask($conn, $t8, $admin);
ok('listed with its live state (open) and where it came from', ($list[0]['open'] ?? null) === true && $list[0]['source_kind'] === 'step' && $list[0]['visible'] === true);
ok('history: follow_up_created', (int)$conn->query("SELECT COUNT(*) FROM task_events WHERE task_id = $t8 AND event_type = 'follow_up_created'")->fetchColumn() === 1);

ok('by default an open follow-up does not stop completion', ChecklistsService::taskCompletionCheck($conn, $t8) === ['blocking' => [], 'warning' => []]);
setSetting($conn, 'tasks_evidence_open_followups', 'block');
ok('set to block, it does - naming the follow-up', !empty(ChecklistsService::taskCompletionCheck($conn, $t8)['blocking']));
setSetting($conn, 'tasks_evidence_open_followups', 'warn');
ok('set to warn, it warns', !empty(ChecklistsService::taskCompletionCheck($conn, $t8)['warning']) && !ChecklistsService::taskCompletionCheck($conn, $t8)['blocking']);
$closedId = (int)$conn->query("SELECT id FROM task_statuses WHERE is_active=1 AND is_closed=1 ORDER BY display_order, id LIMIT 1")->fetchColumn();
$conn->exec("UPDATE tasks SET status_id = $closedId WHERE id = " . (int)$fu['record_id']);
ok('...and once the follow-up is closed, nothing is outstanding', ChecklistsService::taskCompletionCheck($conn, $t8) === ['blocking' => [], 'warning' => []]);
setSetting($conn, 'tasks_evidence_open_followups', 'allow');

$ticket = (int)$conn->query("SELECT id FROM tickets ORDER BY id DESC LIMIT 1")->fetchColumn();
if ($ticket) {
    $l = TaskFollowUps::link($conn, $ctx, $t8, 'ticket', (string)$ticket);
    $row = array_values(array_filter(TaskFollowUps::forTask($conn, $t8, $admin), fn($x) => $x['kind'] === 'ticket'))[0] ?? null;
    ok('an existing ticket links by id, shown with its number', $row && $row['number'] !== null && $row['created_here'] === false);
    ok('linking it twice is refused', threw(fn() => TaskFollowUps::link($conn, $ctx, $t8, 'ticket', (string)$ticket)) instanceof ServiceError);
    TaskFollowUps::unlink($conn, $ctx, $l['id']);
    ok('unlinking removes the link and leaves the ticket alone', (int)$conn->query("SELECT COUNT(*) FROM tickets WHERE id = $ticket")->fetchColumn() === 1
        && count(TaskFollowUps::forTask($conn, $t8, $admin)) === 1);
}
ok('a task cannot be its own follow-up', threw(fn() => TaskFollowUps::link($conn, $ctx, $t8, 'task', (string)$t8)) instanceof ServiceError);
ok('the #181 frozen record carries the follow-ups', count(TaskHistory::snapshot($conn, $t8)['follow_ups'] ?? []) === 1);
TasksService::deleteTask($conn, $ctx, $t8);
$tids = array_values(array_diff($tids, [$t8]));
ok('deleting the occurrence removes the link, not the follow-up task', (int)$conn->query("SELECT COUNT(*) FROM task_follow_ups WHERE task_id = $t8")->fetchColumn() === 0
    && (int)$conn->query("SELECT COUNT(*) FROM tasks WHERE id = " . (int)$fu['record_id'])->fetchColumn() === 1);
$conn->exec("DELETE FROM task_events WHERE task_id = $t8");
$conn->exec("DELETE FROM task_removed_records WHERE task_id = $t8");

section('P4. Period checked, and when it is due');
ok('same day: due on the day it is for', TaskRecurrence::dueFor(['due_offset_mode' => 'same_day'], '2026-10-17') === '2026-10-17');
ok('2 days later', TaskRecurrence::dueFor(['due_offset_mode' => 'days', 'due_offset_days' => 2], '2026-10-17') === '2026-10-19');
ok('next working day: Saturday -> Monday', TaskRecurrence::dueFor(['due_offset_mode' => 'next_working_day'], '2026-10-17') === '2026-10-19');
ok('next working day: Sunday -> Monday', TaskRecurrence::dueFor(['due_offset_mode' => 'next_working_day'], '2026-10-18') === '2026-10-19');
ok('next working day: Friday -> Monday', TaskRecurrence::dueFor(['due_offset_mode' => 'next_working_day'], '2026-10-16') === '2026-10-19');
ok('next working day: Monday -> Tuesday', TaskRecurrence::dueFor(['due_offset_mode' => 'next_working_day'], '2026-10-19') === '2026-10-20');
ok('period: the day it is for', TaskRecurrence::periodFor($conn, ['period_mode' => 'scheduled_day'], '2026-10-17', 0) === ['2026-10-17', '2026-10-17']);
ok('period: the day before', TaskRecurrence::periodFor($conn, ['period_mode' => 'day_before'], '2026-10-17', 0) === ['2026-10-16', '2026-10-16']);
ok('period: none', TaskRecurrence::periodFor($conn, ['period_mode' => 'none'], '2026-10-17', 0) === [null, null]);
$rule = TaskRecurrence::ruleFromInput(['mode' => 'schedule', 'freq' => 'daily', 'period_mode' => 'bogus', 'due_offset_mode' => 'days', 'due_offset_days' => 99]);
ok('the rule refuses an unknown period and caps the offset', $rule['period_mode'] === 'none' && $rule['due_offset_days'] === 60);

// Saturday's check, due Monday, reviewed Monday -> ON TIME (not "on time after rescheduling").
$conn->exec("INSERT INTO task_recurrences (mode, freq, interval_n, ends_mode, occurrences_created, is_active, created_datetime, updated_datetime)
             VALUES ('schedule', 'daily', 1, 'never', 1, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP())");
$rid4 = $rids[] = (int)$conn->lastInsertId();
$closedId = (int)$conn->query("SELECT id FROM task_statuses WHERE is_active=1 AND is_closed=1 ORDER BY display_order, id LIMIT 1")->fetchColumn();
$sat = zzTask($conn, 'ZZ-TF Saturday check');
$conn->prepare("UPDATE tasks SET recurrence_id = ?, scheduled_date = '2026-10-17', due_date = '2026-10-19', original_due_date = '2026-10-19',
                period_start = '2026-10-16', period_end = '2026-10-16', status_id = ?, completed_datetime = '2026-10-19 09:00:00' WHERE id = ?")
     ->execute([$rid4, $closedId, $sat]);
$conn->prepare("INSERT INTO task_events (task_id, recurrence_id, event_type, actor_kind, to_value, created_datetime) VALUES (?, ?, 'completed', 'analyst', '2026-10-19', '2026-10-19 09:00:00')")->execute([$sat, $rid4]);
$tue = zzTask($conn, 'ZZ-TF Sunday check done Tuesday');
$conn->prepare("UPDATE tasks SET recurrence_id = ?, scheduled_date = '2026-10-18', due_date = '2026-10-19', original_due_date = '2026-10-19',
                status_id = ?, completed_datetime = '2026-10-20 09:00:00' WHERE id = ?")->execute([$rid4, $closedId, $tue]);
$conn->prepare("INSERT INTO task_events (task_id, recurrence_id, event_type, actor_kind, to_value, created_datetime) VALUES (?, ?, 'completed', 'analyst', '2026-10-19', '2026-10-20 09:00:00')")->execute([$tue, $rid4]);
$rep = TaskEvidence::report($conn, $rid4, '2026-10-16', '2026-10-19', $admin);
$byId = [];
foreach ($rep['rows'] as $x) if (!empty($x['task_id'])) $byId[(int)$x['task_id']] = $x;
ok('Saturday\'s check, due Monday by the repeat, done Monday: ON TIME', ($byId[$sat]['outcome'] ?? '') === 'on_time', $byId[$sat]['outcome'] ?? 'no row');
ok('...not flagged rescheduled', empty($byId[$sat]['flags']['rescheduled']));
ok('...and the report carries the period it checked and its original due date', ($byId[$sat]['period_start'] ?? '') === '2026-10-16' && ($byId[$sat]['original_due'] ?? '') === '2026-10-19');
ok('Sunday\'s, due Monday, done Tuesday: LATE', ($byId[$tue]['outcome'] ?? '') === 'late', $byId[$tue]['outcome'] ?? 'no row');

// The real creation path, with the offset and period on the rule.
$conn->exec("UPDATE task_recurrences SET period_mode = 'day_before', due_offset_mode = 'next_working_day' WHERE id = $rid4");
$ruleRow = $conn->query("SELECT * FROM task_recurrences WHERE id = $rid4")->fetch(PDO::FETCH_ASSOC);
$m = new ReflectionMethod('TaskRecurrence', 'createOccurrence');
$m->setAccessible(true);
$made = $m->invoke(null, $conn, $sat, $ruleRow, '2026-10-24', 'repeat_schedule', null);
if ($made) $tids[] = (int)$made;
$mrow = $made ? $conn->query("SELECT scheduled_date, due_date, original_due_date, period_start, period_end FROM tasks WHERE id = " . (int)$made)->fetch(PDO::FETCH_ASSOC) : [];
ok('a repeat creates Saturday 24th\'s occurrence due Monday 26th, checking Friday 23rd',
   ($mrow['scheduled_date'] ?? '') === '2026-10-24' && ($mrow['due_date'] ?? '') === '2026-10-26' && ($mrow['original_due_date'] ?? '') === '2026-10-26'
   && ($mrow['period_start'] ?? '') === '2026-10-23' && ($mrow['period_end'] ?? '') === '2026-10-23', json_encode($mrow));

section('P4. Answers that count as an exception');
$fx = FormsService::saveForm($conn, $ctx, ['title' => 'ZZ-TF Exception check', 'is_active' => 1, 'fields' => [
    ['field_type' => 'radio', 'label' => 'Result', 'is_required' => 1, 'options' => ['OK', 'Warning', 'Failure'],
     'config' => ['exception_values' => ['Warning', 'Failure', 'Not an option']]],
]]);
$formIds[] = $fx['id'];
$cfg = json_decode((string)$conn->query("SELECT config FROM form_fields WHERE form_id = " . (int)$fx['id'])->fetchColumn(), true);
ok('the designer\'s exception answers are kept - only real options', ($cfg['exception_values'] ?? null) === ['Warning', 'Failure'], json_encode($cfg));
$fieldX = (int)$conn->query("SELECT id FROM form_fields WHERE form_id = " . (int)$fx['id'])->fetchColumn();
$tx = zzTask($conn, 'ZZ-TF exception occurrence');
$conn->prepare("UPDATE tasks SET recurrence_id = ?, scheduled_date = '2026-10-18' WHERE id = ?")->execute([$rid4, $tx]);
$tfx = TaskForms::add($conn, $ctx, $tx, $fx['id'], true, 'block');
$sx = TaskForms::submit($conn, $ctx, $tfx, 0, 'ref-x', [(string)$fieldX => 'Failure']);
$ax = TaskForms::answers($conn, $sx['submission_id']);
ok('a "Failure" answer is marked as an exception', !empty($ax[0]['exception']));
$rep = TaskEvidence::report($conn, $rid4, '2026-10-16', '2026-10-19', $admin);
$rowx = null;
foreach ($rep['rows'] as $x) if ((int)($x['task_id'] ?? 0) === $tx) $rowx = $x;
$fe = array_values(array_filter($rowx['exceptions'] ?? [], fn($e) => $e['kind'] === 'form_answer'));
ok('...and is one of the occurrence\'s exceptions in the report, naming the answer', ($fe[0]['answer'] ?? '') === 'Failure' && ($fe[0]['question'] ?? '') === 'Result');
ok('...counted in the summary', ($rep['summary']['exceptions'] ?? 0) >= 1);
if ($noForms) {
    $rep2 = TaskEvidence::report($conn, $rid4, '2026-10-16', '2026-10-19', $noForms);
    $r2 = null;
    foreach ($rep2['rows'] as $x) if ((int)($x['task_id'] ?? 0) === $tx) $r2 = $x;
    if ($r2) {
        $fe2 = array_values(array_filter($r2['exceptions'] ?? [], fn($e) => $e['kind'] === 'form_answer'));
        ok('to a viewer without access to answers: the exception is listed, the answer is not', count($fe2) === 1 && $fe2[0]['answer'] === null);
    }
}
$okSub = FormsService::submitForm($conn, $ctx, $fx['id'], [(string)$fieldX => 'OK']);
$ao = TaskForms::answers($conn, $okSub);
ok('control: an "OK" answer is not an exception', empty($ao[0]['exception']));
$conn->exec("DELETE FROM form_submission_data WHERE submission_id = $okSub");
$conn->exec("DELETE FROM form_submissions WHERE id = $okSub");

if ($BASE) {
    section('13. Over HTTP (api/tasks/forms.php)');
    $sess = 'zztf' . bin2hex(random_bytes(3));
    $sessDir = rtrim((string)(ini_get('session.save_path') ?: sys_get_temp_dir()), '/\\');
    $name = 'ZZ forms tester';
    file_put_contents("$sessDir/sess_$sess", 'analyst_id|i:' . $admin . ';analyst_name|s:' . strlen($name) . ':"' . $name . '";csrf_token|s:8:"zztfcsrf";');
    $http = function (string $path, ?array $body = null) use ($BASE, $sess) {
        $ch = curl_init($BASE . 'api/tasks/' . $path);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIE => "PHPSESSID=$sess", CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-CSRF-Token: zztfcsrf']]);
        if ($body !== null) { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body)); }
        $raw = (string)curl_exec($ch);
        return [json_decode($raw, true), $raw];
    };
    $conn->exec("UPDATE tasks SET status_id = $open WHERE id = $t1");
    [$g, $raw] = $http('forms.php?task_id=' . $t1 . '&answers=1');
    ok('GET lists the task\'s form with its answers', ($g['forms'][0]['answers'][0]['value'] ?? '') === 'Failure', substr($raw, 0, 200));
    [$c, $raw] = $http('checklists.php?task_id=' . $t1 . '&check=complete');
    ok('the window\'s completion check sees the forms gate (met: nothing blocking)', isset($c['blocking']) && $c['blocking'] === [], substr($raw, 0, 200));
    $rev = (int)TaskForms::row($conn, $tf)['revision'];
    [$p, $raw] = $http('forms.php', ['action' => 'draft', 'task_form_id' => $tf, 'revision' => $rev - 1, 'answers' => []]);
    ok('a stale draft over HTTP answers with the conflict and who saved', ($p['code'] ?? '') === 'task_form_conflict' && isset($p['conflict']['by']), substr($raw, 0, 200));
    [$p, $raw] = $http('forms.php', ['action' => 'draft', 'task_form_id' => $tf, 'revision' => $rev, 'answers' => [(string)$resultField => 'OK']]);
    ok('a current one saves', !empty($p['success']) && ($p['revision'] ?? 0) === $rev + 1, substr($raw, 0, 200));
    $ch = curl_init($BASE . 'api/tasks/evidence_export.php?series=' . $rid . '&file=form_answers&from=' . date('Y-m-d', strtotime('-3 days')) . '&to=' . date('Y-m-d', strtotime('+1 day')));
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIE => "PHPSESSID=$sess"]);
    $csv = (string)curl_exec($ch);
    ok('the form answers CSV has the question and the answer', str_contains($csv, 'Explanation') && str_contains($csv, 'Job 4 failed again'), substr($csv, 0, 300));
    // ── The REST API (phase 5) ─────────────────────────────────────────────
    section('P5. REST API (GET /tasks/{id}/forms, POST .../submissions)');
    $rawKey = 'fitsm_zz' . bin2hex(random_bytes(16));
    $conn->prepare("INSERT INTO api_keys (name, key_hash, key_prefix, analyst_id, permissions, active, created_datetime)
                    VALUES ('ZZ task forms test', ?, ?, ?, ?, 1, UTC_TIMESTAMP())")
         ->execute([hash('sha256', $rawKey), substr($rawKey, 0, 12), $admin, json_encode(['tasks' => ['read'], 'task_forms' => ['read', 'create']])]);
    $keyId = (int)$conn->lastInsertId();
    $rest = function (string $method, string $path, ?array $body = null) use ($BASE, $rawKey) {
        $ch = curl_init($BASE . 'api/v1' . $path);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $rawKey, 'Content-Type: application/json']]);
        if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        $raw = (string)curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        return [$code, json_decode($raw, true), $raw];
    };
    try {
        $t9 = zzTask($conn, 'ZZ-TF REST occurrence');
        $tf9 = TaskForms::add($conn, $ctx, $t9, $v1['id'], true, 'block');
        // The requirement is on the form's NEWEST version, whose field ids are its own.
        $f9 = $conn->prepare("SELECT label, id FROM form_fields WHERE form_id = ?");
        $f9->execute([(int)TaskForms::row($conn, $tf9)['form_id']]);
        $f9 = $f9->fetchAll(PDO::FETCH_KEY_PAIR);
        $resultField = (int)$f9['Result'];
        $explField = (int)$f9['Explanation'];
        [$c, $j, $raw] = $rest('GET', "/tasks/$t9/forms");
        $list = $j['data'] ?? $j;
        ok('GET /tasks/{id}/forms lists the requirement', $c === 200 && (int)($list[0]['id'] ?? 0) === $tf9 && ($list[0]['requirement'] ?? '') === 'block', "$c " . substr($raw, 0, 200));
        $payload = ['data' => [(string)$resultField => 'Warning'], 'client_reference' => 'rest-ref-1'];
        [$c, $j, $raw] = $rest('POST', "/tasks/$t9/forms/$tf9/submissions", $payload);
        $d = $j['data'] ?? $j;
        ok('POST .../submissions submits it, linked to the task (201)', $c === 201 && !empty($d['submission_id']), "$c " . substr($raw, 0, 200));
        $sid = (int)($d['submission_id'] ?? 0);
        [$c, $j, $raw] = $rest('POST', "/tasks/$t9/forms/$tf9/submissions", $payload);
        $d2 = $j['data'] ?? $j;
        ok('a retry with the same client_reference returns it (200, replayed) - no second', $c === 200 && !empty($d2['replayed']) && (int)$d2['submission_id'] === $sid
            && (int)$conn->query("SELECT COUNT(*) FROM form_submissions WHERE task_id = $t9")->fetchColumn() === 1, "$c " . substr($raw, 0, 200));
        [$c, $j, $raw] = $rest('POST', "/tasks/$t9/forms/$tf9/submissions", ['data' => [(string)$resultField => 'OK'], 'revision' => 0]);
        ok('a stale revision is refused with 409', $c === 409, "$c " . substr($raw, 0, 200));
        [$c, $j, $raw] = $rest('POST', "/tasks/$t9/forms/$tf9/submissions", ['data' => [(string)$explField => 'no result']]);
        ok('a missing required answer is refused with 422 by the Forms validation', $c === 422, "$c " . substr($raw, 0, 200));
        [$c, $j, $raw] = $rest('GET', "/tasks/$t9/forms");
        $list = $j['data'] ?? $j;
        ok('GET now shows it submitted, with the answers (the key\'s analyst may read them)', ($list[0]['submission_id'] ?? 0) === $sid && ($list[0]['answers'][0]['answer'] ?? '') === 'Warning', substr($raw, 0, 300));
        [$c] = $rest('POST', "/tasks/$t9/forms/" . ($tf9 + 100000) . "/submissions", $payload);
        ok('a requirement that is not on this task: 404', $c === 404);
        $conn->prepare("UPDATE api_keys SET permissions = ? WHERE id = ?")->execute([json_encode(['tasks' => ['read'], 'task_forms' => ['read']]), $keyId]);
        [$c] = $rest('POST', "/tasks/$t9/forms/$tf9/submissions", $payload);
        ok('without task_forms.create: 403', $c === 403, (string)$c);
    } finally {
        $conn->prepare("DELETE FROM api_keys WHERE id = ?")->execute([$keyId]);
    }

    @unlink("$sessDir/sess_$sess");
}

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail ? 1 : 0);
