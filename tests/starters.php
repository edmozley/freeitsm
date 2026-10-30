<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * Starters & leavers: a case drives other modules' services and keeps nothing twice.
 *
 *   - a template's steps become subtasks of one parent task, due relative to the date;
 *   - progress is read back from Tasks;
 *   - kit is issued and recovered through Assets custody, and a status ticked
 *     Retired retires the asset;
 *   - a leaver is switched off in Users the way the person screen does it, and a
 *     directory-managed person is refused;
 *   - the People page lists the person's cases;
 *   - the endpoints answer, and refuse a GET that tries to write.
 *
 *   FREEITSM_TEST_DATABASE=<throwaway db> FREEITSM_BASE_URL=http://localhost/<that install> php tests/starters.php
 *
 * Writes, so it runs only when FREEITSM_TEST_DATABASE names the database config.php
 * points at. Everything it makes is named ZZ-JML-... and removed at the end.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/starters/service.php';
require_once __DIR__ . '/../includes/people.php';

$pass = 0; $fail = 0;
function ok(string $what, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  PASS  {$what}\n"; }
    else       { $fail++; echo "  FAIL  {$what}" . ($detail !== '' ? "  <- {$detail}" : '') . "\n"; }
}
/** Runs $fn and returns the ServiceError message it threw, or '' if it did not throw. */
function refused(callable $fn): string {
    try { $fn(); return ''; } catch (ServiceError $e) { return $e->getMessage(); }
}

$conn = connectToDatabase();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$expected = getenv('FREEITSM_TEST_DATABASE');
if (!$expected || $conn->query('SELECT DATABASE()')->fetchColumn() !== $expected) {
    exit("Set FREEITSM_TEST_DATABASE to the throwaway database config.php points at - this test writes cases, tasks, people and assets.\n");
}
$base = getenv('FREEITSM_BASE_URL');
if (!$base || str_contains($base, 'freeitsm-app')) {
    exit("Set FREEITSM_BASE_URL to the throwaway install too - otherwise the endpoint checks would run against the real one.\n");
}
if (!StartersService::ready($conn)) exit("Run Database Verification on this database first.\n");

echo "\nStarters & leavers\n" . str_repeat('=', 72) . "\n";
$admin = (int)$conn->query("SELECT id FROM analysts WHERE is_active = 1 ORDER BY id LIMIT 1")->fetchColumn();
$ctx = new ActorContext(actorId: $admin, companyScope: null, source: 'ui', actorName: 'test');
$tag = bin2hex(random_bytes(3));
$made = ['templates' => [], 'cases' => [], 'users' => [], 'assets' => [], 'statuses' => [], 'forms' => [], 'submissions' => []];
$sid = 'zzjml' . bin2hex(random_bytes(4));
$csrf = bin2hex(random_bytes(32));
$sessFile = rtrim(ini_get('session.save_path') ?: 'c:/wamp64/tmp', "/\\") . '/sess_' . $sid;

/** Calls an api/starters endpoint as the admin; JSON body for a POST. */
function jml(string $base, string $sid, string $csrf, string $path, ?array $post = null): array {
    $ch = curl_init(rtrim($base, '/') . '/api/starters/' . $path);
    $opts = [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIE => 'PHPSESSID=' . $sid, CURLOPT_TIMEOUT => 30];
    if ($post !== null) {
        $opts += [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($post),
                  CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-CSRF-Token: ' . $csrf]];
    }
    curl_setopt_array($ch, $opts);
    $body = (string)curl_exec($ch); curl_close($ch);
    return json_decode($body, true) ?? ['raw' => substr($body, 0, 300)];
}

try {
    file_put_contents($sessFile, 'analyst_id|i:' . $admin . ';analyst_name|s:5:"zztst";is_admin|i:1;csrf_token|s:64:"' . $csrf . '";');
    $team = $conn->query("SELECT id FROM teams WHERE is_active = 1 ORDER BY id LIMIT 1")->fetchColumn();
    $team = $team ? (int)$team : null;

    // ---- a template ---------------------------------------------------------
    $tpl = StartersService::saveTemplate($conn, $ctx, ['kind' => 'starter', 'name' => "ZZ-JML-office-$tag", 'steps' => [
        ['title' => 'ZZ-JML order laptop', 'due_offset_days' => -5, 'assigned_team_id' => $team],
        ['title' => '', 'due_offset_days' => 0],   // blank: dropped
        ['title' => 'ZZ-JML create account', 'due_offset_days' => -2, 'assigned_analyst_id' => $admin],
        ['title' => 'ZZ-JML welcome', 'due_offset_days' => 0],
    ]]);
    $made['templates'][] = $tpl;
    $steps = StartersService::steps($conn, $tpl);
    ok('a template keeps its steps in order, blank ones dropped', array_column($steps, 'title') === ['ZZ-JML order laptop', 'ZZ-JML create account', 'ZZ-JML welcome'], json_encode(array_column($steps, 'title')));
    ok('...with the team, analyst and day offset of each', $steps[0]['assigned_team_id'] === $team && $steps[1]['assigned_analyst_id'] === $admin && $steps[0]['due_offset_days'] === -5);

    ok('a template for a missing kind is refused', refused(fn() => StartersService::saveTemplate($conn, $ctx, ['kind' => 'visitor', 'name' => 'x'])) !== '');

    // ---- a starter not yet in Users -------------------------------------------
    $date = date('Y-m-d', strtotime('+14 days'));
    $warn = [];
    $case = StartersService::createCase($conn, $ctx, ['kind' => 'starter', 'person_name' => "ZZ-JML Jo $tag", 'person_email' => "zzjml-$tag@example.test",
                                                      'effective_date' => $date, 'template_id' => $tpl], $warn);
    $made['cases'][] = $case;
    ok('a starter can be opened before they are in Users', $case > 0 && !$warn, json_encode($warn));
    $c = StartersService::getCase($conn, $ctx, $case);
    ok('the case has a parent task, due on the date', $c['parent_task_id'] > 0
       && $conn->query("SELECT due_date FROM tasks WHERE id = " . (int)$c['parent_task_id'])->fetchColumn() === $date);
    ok('each step is a subtask of it, in Tasks', count($c['steps']) === 3, (string)count($c['steps']));
    $due = array_column($c['steps'], 'due_date', 'title');
    ok('...due its offset from the date', ($due['ZZ-JML order laptop'] ?? '') === date('Y-m-d', strtotime("$date -5 days"))
       && ($due['ZZ-JML welcome'] ?? '') === $date, json_encode($due));
    $teamOf = (int)$conn->query("SELECT assigned_team_id FROM tasks WHERE parent_task_id = " . (int)$c['parent_task_id'] . " AND title = 'ZZ-JML order laptop'")->fetchColumn();
    ok('...given to the step\'s team', $team === null || $teamOf === $team);
    ok('progress starts at 0 of 3', $c['progress'] === ['done' => 0, 'total' => 3], json_encode($c['progress']));

    // Progress is read from Tasks: close a step there, the case sees it.
    $closed = $conn->query("SELECT id FROM task_statuses WHERE is_closed = 1 ORDER BY id LIMIT 1")->fetchColumn();
    if ($closed) {
        $conn->prepare("UPDATE tasks SET status_id = ? WHERE parent_task_id = ? AND title = 'ZZ-JML welcome'")->execute([(int)$closed, (int)$c['parent_task_id']]);
        ok('a step closed in Tasks counts on the case', StartersService::getCase($conn, $ctx, $case)['progress']['done'] === 1);
        $listed = array_values(array_filter(StartersService::listCases($conn, $admin, ['kind' => 'starter']), fn($r) => $r['id'] === $case));
        ok('...and in the list', $listed && $listed[0]['steps_done'] === 1 && $listed[0]['steps_total'] === 3, json_encode($listed));
    }

    ok('a leaver must be someone in Users', refused(fn() => StartersService::createCase($conn, $ctx, ['kind' => 'leaver', 'person_name' => 'x', 'effective_date' => $date])) !== '');
    ok('a template of another kind is refused', refused(fn() => StartersService::createCase($conn, $ctx, ['kind' => 'mover', 'person_name' => 'x', 'user_id' => null, 'effective_date' => $date, 'template_id' => $tpl])) !== '');
    ok('a date is required', refused(fn() => StartersService::createCase($conn, $ctx, ['kind' => 'starter', 'person_name' => 'x', 'effective_date' => 'soon'])) !== '');

    // The task is written in the case's language: the owner's saved choice, else the opener's.
    $deCtx = new ActorContext(actorId: $admin, companyScope: null, source: 'ui', locale: 'de', actorName: 'test');
    $deCase = StartersService::createCase($conn, $deCtx, ['kind' => 'starter', 'person_name' => "ZZ-JML De $tag", 'effective_date' => $date]);
    $made['cases'][] = $deCase;
    $pref = $conn->query("SELECT preference_value FROM user_preferences WHERE analyst_id = $admin AND preference_key = 'interface_language'")->fetchColumn() ?: 'de';
    $deTask = $conn->query("SELECT t.title, t.description FROM tasks t JOIN jml_cases c ON c.parent_task_id = t.id WHERE c.id = $deCase")->fetch(PDO::FETCH_ASSOC);
    ok("the case's task is written in its language ($pref)", str_starts_with($deTask['title'], I18n::tFor($pref, 'starters.kind.starter') . ': ZZ-JML De')
       && str_contains($deTask['description'], htmlspecialchars(I18n::tFor($pref, 'starters.written.task_open'))), $deTask['title']);

    // ---- they arrive in Users: link, then kit through Assets ---------------------
    $conn->prepare("INSERT INTO users (email, display_name, is_active) VALUES (?, ?, 1)")->execute(["zzjml-$tag@example.test", "ZZ-JML Jo $tag"]);
    $user = (int)$conn->lastInsertId(); $made['users'][] = $user;
    ok('kit cannot be issued before the case is linked', refused(fn() => StartersService::issueKit($conn, $ctx, $case, 0)) !== '');
    StartersService::linkUser($conn, $ctx, $case, $user);
    $conn->prepare("INSERT INTO assets (hostname, service_tag) VALUES (?, ?)")->execute(["ZZ-JML-LAPTOP-$tag", "ZZJML$tag"]);
    $asset = (int)$conn->lastInsertId(); $made['assets'][] = $asset;
    StartersService::issueKit($conn, $ctx, $case, $asset);
    ok('issued kit is Assets custody (users_assets)', (int)$conn->query("SELECT COUNT(*) FROM users_assets WHERE user_id = $user AND asset_id = $asset")->fetchColumn() === 1);
    ok('...and the case shows it', in_array($asset, array_column(StartersService::getCase($conn, $ctx, $case)['kit'], 'id'), true));
    StartersService::setStatus($conn, $ctx, $case, 'done');
    ok('done stamps the case', $conn->query("SELECT completed_datetime FROM jml_cases WHERE id = $case")->fetchColumn() !== null);
    ok('...and leaves every task as it was', StartersService::getCase($conn, $ctx, $case)['progress']['total'] === 3);

    // ---- the same person leaves -----------------------------------------------
    $leave = StartersService::createCase($conn, $ctx, ['kind' => 'leaver', 'user_id' => $user, 'effective_date' => date('Y-m-d')]);
    $made['cases'][] = $leave;
    ok('a leaver case takes the name and email from Users', ($l = StartersService::getCase($conn, $ctx, $leave))['person_name'] === "ZZ-JML Jo $tag" && $l['person_email'] === "zzjml-$tag@example.test");
    $retired = null;
    try {
        $conn->prepare("INSERT INTO asset_status_types (name, is_active, is_retired) VALUES (?, 1, 1)")->execute(["ZZ-JML-gone-$tag"]);
        $retired = (int)$conn->lastInsertId(); $made['statuses'][] = $retired;
    } catch (Throwable $e) { /* no retired column: recover without a status */ }
    StartersService::recoverKit($conn, $ctx, $leave, $asset, $retired);
    ok('recovered kit leaves custody', (int)$conn->query("SELECT COUNT(*) FROM users_assets WHERE user_id = $user AND asset_id = $asset")->fetchColumn() === 0);
    if ($retired) {
        $a = $conn->query("SELECT asset_status_id, retired_datetime FROM assets WHERE id = $asset")->fetch(PDO::FETCH_ASSOC);
        ok('...and a status ticked Retired retires it, as Assets does', (int)$a['asset_status_id'] === $retired && $a['retired_datetime'] !== null, json_encode($a));
    }

    StartersService::markLeft($conn, $ctx, $leave);
    $u = $conn->query("SELECT is_active, deactivated_datetime FROM users WHERE id = $user")->fetch(PDO::FETCH_ASSOC);
    ok('Left switches them off in Users, with the date (userActiveSets)', (int)$u['is_active'] === 0 && $u['deactivated_datetime'] !== null, json_encode($u));
    ok('only a leaver can be marked as left', refused(fn() => StartersService::markLeft($conn, $ctx, $case)) !== '');

    $conn->prepare("INSERT INTO users (email, display_name, is_active, is_managed) VALUES (?, ?, 1, 1)")->execute(["zzjml-dir-$tag@example.test", "ZZ-JML Dir $tag"]);
    $dir = (int)$conn->lastInsertId(); $made['users'][] = $dir;
    $dirCase = StartersService::createCase($conn, $ctx, ['kind' => 'leaver', 'user_id' => $dir, 'effective_date' => date('Y-m-d')]);
    $made['cases'][] = $dirCase;
    ok('a person kept from a directory is not switched off here', refused(fn() => StartersService::markLeft($conn, $ctx, $dirCase)) !== ''
       && (int)$conn->query("SELECT is_active FROM users WHERE id = $dir")->fetchColumn() === 1);

    // ---- People ---------------------------------------------------------------
    $detail = personDetail($conn, $admin, $user);
    $rows = $detail['sections']['starters']['rows'] ?? [];
    $kinds = array_column($rows, 'kind'); sort($kinds);
    ok('the person\'s People page lists both cases', $kinds === ['leaver', 'starter'], json_encode($rows));

    // ---- questions from Forms ---------------------------------------------------
    require_once __DIR__ . '/../includes/services/forms.php';
    $form = FormsService::saveForm($conn, $ctx, ['title' => "ZZ-JML request $tag", 'fields' => [
        ['label' => 'Full name', 'field_type' => 'text', 'is_required' => true],
        ['label' => 'Email', 'field_type' => 'email'],
        ['label' => 'Start date', 'field_type' => 'datetime', 'config' => ['date_mode' => 'date']],
        ['label' => 'Department', 'field_type' => 'dropdown', 'options' => ['Dispute Resolution', 'Finance', 'Property']],
        ['label' => 'Job title', 'field_type' => 'text'],
        ['label' => 'DMS access?', 'field_type' => 'radio', 'options' => ['Yes', 'No']],
        ['label' => 'Private mailbox?', 'field_type' => 'radio', 'options' => ['Yes', 'No']],
    ]]);
    $formId = (int)$form['id']; $made['forms'][] = $formId;
    $fid = [];
    foreach ($conn->query("SELECT id, label FROM form_fields WHERE form_id = $formId")->fetchAll(PDO::FETCH_ASSOC) as $r) $fid[$r['label']] = (int)$r['id'];
    $answer = function (array $byLabel) use ($conn, $ctx, $formId, $fid, &$made): int {
        $data = [];
        foreach ($byLabel as $l => $v) $data[$fid[$l]] = $v;
        return $made['submissions'][] = FormsService::submitForm($conn, $ctx, $formId, $data);
    };
    $legal = StartersService::saveTemplate($conn, $ctx, ['kind' => 'starter', 'name' => "ZZ-JML legal $tag", 'form_id' => $formId,
        'match_rule' => ['rules' => [['question' => 'department', 'op' => 'equals', 'value' => 'Dispute Resolution']]],
        'steps' => [
            ['title' => 'Create account for {Full name} - {Job title}, {Department}', 'due_offset_days' => -3],
            ['title' => 'ZZ-JML set up DMS', 'condition_rule' => ['rules' => [['question' => 'DMS access?', 'op' => 'equals', 'value' => 'Yes']]]],
            ['title' => 'ZZ-JML private mailbox', 'condition_rule' => ['rules' => [['question' => 'Private mailbox?', 'op' => 'equals', 'value' => 'Yes']]]],
        ]]);
    $general = StartersService::saveTemplate($conn, $ctx, ['kind' => 'starter', 'name' => "ZZ-JML general $tag", 'form_id' => $formId,
        'steps' => [['title' => 'ZZ-JML general step for {name}']]]);
    $made['templates'][] = $legal; $made['templates'][] = $general;
    ok('a template keeps its form, its rule and each step\'s "only if"',
       ($t = array_values(array_filter(StartersService::templates($conn), fn($x) => $x['id'] === $legal))[0] ?? null)
       && $t['form_id'] === $formId && $t['match_rule']['rules'][0]['question'] === 'department' && $t['steps'][1]['condition_rule']['rules'][0]['value'] === 'Yes');

    // A manager's request: the form opens the case (what the workflow action does).
    $sub = $answer(['Full name' => "Sarah ZZ $tag", 'Email' => "zzjml-sarah-$tag@example.test", 'Start date' => $date,
                    'Department' => 'Dispute Resolution', 'Job title' => 'Lawyer', 'DMS access?' => 'Yes', 'Private mailbox?' => 'No']);
    $r = StartersService::openFromSubmission($conn, ['kind' => 'starter'], ['submission' => ['id' => $sub]]);
    $made['cases'][] = $r['case_id'];
    $fc = StartersService::getCase($conn, $ctx, $r['case_id']);
    ok('the answers choose the template by its rule (Dispute Resolution -> legal)', $r['template_id'] === $legal, (string)$r['template_id']);
    ok('the name, email and date come from the answers', $fc['person_name'] === "Sarah ZZ $tag" && $fc['person_email'] === "zzjml-sarah-$tag@example.test" && $fc['effective_date'] === $date && $fc['job_title'] === 'Lawyer', json_encode([$fc['person_name'], $fc['person_email'], $fc['effective_date'], $fc['job_title']]));
    $titles = array_column($fc['steps'], 'title');
    ok('only the steps whose "only if" the answers meet are added', count($titles) === 2 && in_array('ZZ-JML set up DMS', $titles, true) && !in_array('ZZ-JML private mailbox', $titles, true), json_encode($titles));
    ok('{Question} is filled from the answers', in_array("Create account for Sarah ZZ $tag - Lawyer, Dispute Resolution", $titles, true), json_encode($titles));
    ok('the case keeps the answers', count($fc['answers']) >= 7 && $fc['form_submission_id'] === $sub);
    $desc = (string)$conn->query("SELECT description FROM tasks WHERE id = " . (int)$fc['parent_task_id'])->fetchColumn();
    ok('...and they are on the case\'s task, where the work is done', str_contains($desc, 'Dispute Resolution') && str_contains($desc, 'Lawyer'));

    $sub2 = $answer(['Full name' => "Finn ZZ $tag", 'Start date' => $date, 'Department' => 'Finance', 'DMS access?' => 'No', 'Private mailbox?' => 'No']);
    $r2 = StartersService::openFromSubmission($conn, ['kind' => 'starter'], ['submission' => ['id' => $sub2]]);
    $made['cases'][] = $r2['case_id'];
    ok('no rule met: the template with no rule is used', $r2['template_id'] === $general, (string)$r2['template_id']);
    ok('...and {name} is filled from the case', in_array("ZZ-JML general step for Finn ZZ $tag", array_column(StartersService::getCase($conn, $ctx, $r2['case_id'])['steps'], 'title'), true));

    // An analyst opens one by hand: the questions come first, the steps wait.
    $manual = StartersService::createCase($conn, $ctx, ['kind' => 'starter', 'person_name' => "Mo ZZ $tag", 'effective_date' => $date, 'template_id' => $legal]);
    $made['cases'][] = $manual;
    $mc = StartersService::getCase($conn, $ctx, $manual);
    ok('a template with a form waits for the answers before adding steps', $mc['steps_pending'] && !$mc['steps'], json_encode([$mc['steps_pending'], count($mc['steps'])]));
    $sub3 = $answer(['Full name' => "Mo ZZ $tag", 'Start date' => $date, 'Department' => 'Dispute Resolution', 'DMS access?' => 'No', 'Private mailbox?' => 'Yes']);
    StartersService::taskFormSubmitted($conn, $ctx, (int)$mc['parent_task_id'], $sub3);
    $mc = StartersService::getCase($conn, $ctx, $manual);
    $titles = array_column($mc['steps'], 'title');
    ok('answered on the task: the steps that apply arrive', !$mc['steps_pending'] && count($titles) === 2 && in_array('ZZ-JML private mailbox', $titles, true) && !in_array('ZZ-JML set up DMS', $titles, true), json_encode($titles));

    ok('"any of" matches one of several answers', StartersService::ruleMet(['rules' => [['question' => 'Department', 'op' => 'any_of', 'value' => 'Property, Finance']]], ['department' => ['raw' => 'Finance']])
       && !StartersService::ruleMet(['rules' => [['question' => 'Department', 'op' => 'any_of', 'value' => 'Property, Finance']]], ['department' => ['raw' => 'Dispute Resolution']]));
    require_once __DIR__ . '/../workflow/includes/engine.php';
    ok('"Open a Starters & leavers case" is a workflow action', isset(WorkflowEngine::availableActions()['open_jml_case']));

    // ---- the endpoints ---------------------------------------------------------
    $r = jml($base, $sid, $csrf, 'get.php?id=' . $case);
    ok('get.php returns the case with links from entityLink()', ($r['success'] ?? false) && str_starts_with((string)$r['links']['task'], 'tasks/') && str_starts_with((string)$r['links']['person'], 'people/'), json_encode($r));
    $r = jml($base, $sid, $csrf, 'list.php?kind=leaver');
    ok('list.php filters by kind', ($r['success'] ?? false) && !array_filter($r['cases'], fn($x) => $x['kind'] !== 'leaver'));
    $r = jml($base, $sid, $csrf, 'status.php?id=' . $case . '&status=cancelled');
    ok('a GET cannot change a case', empty($r['success']) && $conn->query("SELECT status FROM jml_cases WHERE id = $case")->fetchColumn() === 'done', json_encode($r));
    $r = jml($base, $sid, $csrf, 'status.php', ['id' => $case, 'status' => 'open']);
    ok('status.php reopens it', ($r['success'] ?? false) && $conn->query("SELECT status FROM jml_cases WHERE id = $case")->fetchColumn() === 'open', json_encode($r));
    $r = jml($base, $sid, $csrf, 'lookups.php?people=' . rawurlencode("ZZ-JML Jo $tag"));
    ok('lookups.php finds people through the Assets service', ($r['success'] ?? false) && in_array($user, array_column($r['people'], 'id'), true), json_encode($r));
    $r = jml($base, $sid, $csrf, 'create.php', ['kind' => 'starter', 'person_name' => "ZZ-JML Api $tag", 'effective_date' => $date, 'template_id' => $tpl]);
    if (!empty($r['id'])) $made['cases'][] = (int)$r['id'];
    ok('create.php opens a case with its steps', ($r['success'] ?? false) && ($r['warnings'] ?? null) === []
       && count(StartersService::getCase($conn, $ctx, (int)$r['id'])['steps']) === 3, json_encode($r));
} catch (Throwable $e) {
    ok('no unexpected error', false, $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    // Tasks first (subtasks, then parents), then what the cases pointed at.
    foreach ($made['cases'] as $id) {
        $p = $conn->query("SELECT parent_task_id FROM jml_cases WHERE id = $id")->fetchColumn();
        if ($p) {
            $ids = array_merge($conn->query("SELECT id FROM tasks WHERE parent_task_id = " . (int)$p)->fetchAll(PDO::FETCH_COLUMN), [(int)$p]);
            foreach ($ids as $t) {
                foreach (['task_checklists', 'task_audit', 'task_notes', 'task_forms', 'task_history'] as $tbl) {
                    try { $conn->exec("DELETE FROM $tbl WHERE task_id = " . (int)$t); } catch (Throwable $e) { /* not on this install */ }
                }
                $conn->exec("DELETE FROM tasks WHERE id = " . (int)$t);
            }
        }
        $conn->exec("DELETE FROM jml_cases WHERE id = $id");
    }
    foreach ($made['templates'] as $id) $conn->exec("DELETE FROM jml_templates WHERE id = $id");
    // The submissions (their answers cascade), then the form and its questions.
    foreach ($made['submissions'] as $id) {
        try { $conn->exec("DELETE FROM workflow_executions WHERE trigger_payload LIKE '%\"submission\":{\"id\":$id,%'"); } catch (Throwable $e) {}
        $conn->exec("DELETE FROM form_submissions WHERE id = $id");
    }
    foreach ($made['forms'] as $id) {
        try { $conn->exec("DELETE FROM form_drafts WHERE form_id = $id"); } catch (Throwable $e) {}
        $conn->exec("DELETE FROM form_fields WHERE form_id = $id");
        $conn->exec("DELETE FROM forms WHERE id = $id");
    }
    foreach ($made['assets'] as $id) {
        foreach (['users_assets', 'asset_history'] as $tbl) { try { $conn->exec("DELETE FROM $tbl WHERE asset_id = $id"); } catch (Throwable $e) {} }
        $conn->exec("DELETE FROM assets WHERE id = $id");
    }
    foreach ($made['statuses'] as $id) $conn->exec("DELETE FROM asset_status_types WHERE id = $id");
    foreach ($made['users'] as $id) { try { $conn->exec("DELETE FROM users_assets WHERE user_id = $id"); } catch (Throwable $e) {} $conn->exec("DELETE FROM users WHERE id = $id"); }
    @unlink($sessFile);
}

echo str_repeat('-', 72) . "\n{$pass} passed, {$fail} failed\n";
exit($fail ? 1 : 0);
