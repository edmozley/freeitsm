<?php
/* 🔴 NEVER OVER THE WEB. A test writes to the real tables - it creates analysts, a
   team, portal users, forms, a chain, requests and tickets, and tidies them up in its
   finally block. See tests/README.md. Run it against a disposable install. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * Approval steps and chains for catalogue requests (GH #160 Part C1).
 *
 *   FREEITSM_TEST_DATABASE=<throwaway db> php tests/catalogue-approval-steps.php
 *
 * Covers:
 *   - a form without steps behaves exactly as before (the upgrade promise);
 *   - a bad step list is refused on save;
 *   - person then team: approving step 1 moves it on (new round, recorded), the team's
 *     votes then approve it and raise ONE ticket; a rejection at step 1 ends it;
 *   - "Only when": a step that does not apply is skipped and recorded with the answer;
 *     a blank amount, or a question not on the form, means the step RUNS;
 *   - every step skipped = approved at once, ticket raised;
 *   - a step that finds nobody goes to its fallback, then the install default, else Stuck;
 *   - a named chain is followed; a chain that has gone leaves the request Stuck;
 *   - the steps are snapshotted (editing the form later changes nothing waiting);
 *   - createVersion() carries the steps and the chain; Override passes one step only;
 *   - the inbox shows "step 2 of 3".
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/services/forms.php';
require_once __DIR__ . '/../includes/catalogue_approvals.php';

$pass = 0; $fail = 0;
function check(string $label, bool $ok, string $detail = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ok    $label\n"; }
    else     { $fail++; echo "  FAIL  $label" . ($detail !== '' ? "  - $detail" : '') . "\n"; }
}
function refused(callable $fn): string {
    try { $fn(); return ''; } catch (Throwable $e) { return $e->getMessage(); }
}

echo "Approval steps and chains (GH #160 Part C1)\n" . str_repeat('=', 64) . "\n";
$conn = connectToDatabase();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$expected = getenv('FREEITSM_TEST_DATABASE');
if (!$expected || $expected !== $conn->query('SELECT DATABASE()')->fetchColumn()) { echo "  SKIPPED - set FREEITSM_TEST_DATABASE to this install's (throwaway) database.\n"; exit(0); }
if (!catalogueStepsAvailable($conn)) { echo "  SKIPPED - run Database Verification first.\n"; exit(0); }

$made = ['analysts' => [], 'users' => [], 'forms' => [], 'team' => null, 'chain' => null, 'setting' => false];
$tag = 'zzstp' . bin2hex(random_bytes(3));
$oldDefault = $conn->query("SELECT setting_value FROM system_settings WHERE setting_key = 'forms_approval_fallback'")->fetchColumn();

try {
    $mkAnalyst = function (string $name, bool $admin = false) use ($conn, $tag, &$made) {
        $conn->prepare("INSERT INTO analysts (username, password_hash, full_name, email, is_active, is_admin) VALUES (?, 'x', ?, ?, 1, ?)")
             ->execute([$tag . $name, 'ZZSTP ' . $name, $tag . $name . '@example.invalid', $admin ? 1 : 0]);
        return $made['analysts'][] = (int)$conn->lastInsertId();
    };
    $alice = $mkAnalyst('alice'); $bob = $mkAnalyst('bob'); $carol = $mkAnalyst('carol'); $rita = $mkAnalyst('rita');
    $admin = $mkAnalyst('admin', true);
    $conn->prepare("INSERT INTO users (email, display_name, created_at) VALUES (?, 'ZZSTP Uma', UTC_TIMESTAMP())")->execute([$tag . 'uma@example.invalid']);
    $uma = $made['users'][] = (int)$conn->lastInsertId();
    $conn->prepare("INSERT INTO users (email, display_name, created_at) VALUES (?, 'ZZSTP Rita (portal)', UTC_TIMESTAMP())")->execute([$tag . 'rita@example.invalid']);
    $portalRita = $made['users'][] = (int)$conn->lastInsertId();

    $conn->prepare("INSERT INTO teams (name, is_active) VALUES (?, 1)")->execute(['ZZSTP finance ' . $tag]);
    $team = $made['team'] = (int)$conn->lastInsertId();
    $join = $conn->prepare("INSERT INTO analyst_teams (analyst_id, team_id) VALUES (?, ?)");
    foreach ([$bob, $carol] as $m) $join->execute([$m, $team]);

    $ctx = new ActorContext(actorId: $admin, source: 'ui');
    $_SESSION = ['analyst_id' => $admin];
    $as = function (int $who) { $_SESSION = ['analyst_id' => $who]; };
    $mkForm = function (array $extra) use ($conn, $ctx, &$made) {
        $id = (int)FormsService::saveForm($conn, $ctx, ['title' => 'ZZSTP form', 'is_portal_visible' => 1, 'requires_approval' => 1,
            'fields' => [['field_type' => 'text', 'label' => 'ZZSTP what'], ['field_type' => 'number', 'label' => 'Order total']]] + $extra)['id'];
        return $made['forms'][] = $id;
    };
    $fields = fn(int $formId) => $conn->query("SELECT label, id FROM form_fields WHERE form_id = $formId")->fetchAll(PDO::FETCH_KEY_PAIR);
    $submit = function (int $formId, int $user, $total = '100') use ($conn, $fields) {
        $f = $fields($formId);
        $data = [$f['ZZSTP what'] => 'ZZSTP laptop'];
        if ($total !== null) $data[$f['Order total']] = $total;
        return FormsService::submitForm($conn, new ActorContext(actorId: 0, source: 'portal'), $formId, $data, $user);
    };
    $row = fn(int $sid) => $conn->query("SELECT * FROM form_submissions WHERE id = $sid")->fetch(PDO::FETCH_ASSOC);
    $events = fn(int $sid) => $conn->query("SELECT action, detail, reason FROM form_submission_approval_events WHERE submission_id = $sid ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    $person = fn(int $id, string $name = '', array $more = []) => ['name' => $name, 'who' => 'person', 'approver_id' => $id] + $more;
    $teamStep = fn(string $name = '', int $a = 1, array $more = []) => ['name' => $name, 'who' => 'team', 'team_id' => $team, 'approvals_required' => $a, 'rejections_required' => 1] + $more;
    $over5000 = ['match' => 'all', 'rules' => [['question' => 'Order total', 'op' => 'greater_than', 'value' => '5000']]];

    // ---- 1. No steps: exactly as before ----
    $f0 = $mkForm(['approver_id' => $alice]);
    $s = $submit($f0, $uma);
    $r = $row($s);
    check('no steps: waits on the named approver, no steps snapshotted', (int)$r['approver_id'] === $alice && $r['approval_steps'] === null && $r['approval_step_no'] === null);
    $as($alice);
    check('no steps: one approval decides it', catalogueApprovalDecide($conn, $alice, $s, 'approved')['decision'] === 'approved');
    $as($admin);

    // ---- 2. A bad list is refused ----
    check('a step with nobody chosen is refused', str_contains(refused(fn() => $mkForm(['approval_steps' => [['name' => 'x', 'who' => 'person']]])), 'choose a person'));
    check('eleven steps are refused', str_contains(refused(fn() => $mkForm(['approval_steps' => array_fill(0, 11, $person($alice))])), 'at most'));
    check('a condition with an unknown operator is refused', str_contains(refused(fn() => $mkForm(['approval_steps' => [$person($alice, '', ['when' => ['rules' => [['question' => 'Order total', 'op' => 'bigger', 'value' => '1']]]])]])), 'compares'));

    // ---- 3. Person, then team ----
    $f1 = $mkForm(['approval_steps' => [$person($alice, 'IT manager'), $teamStep('Finance', 2)]]);
    $s = $submit($f1, $uma);
    $r = $row($s);
    check('steps: snapshotted, on step 1, waiting on Alice', (int)$r['approval_step_no'] === 1 && (int)$r['approver_id'] === $alice && count(json_decode($r['approval_steps'], true)) === 2);
    $bell = fn(int $who, int $sid) => (int)$conn->query("SELECT COUNT(*) FROM notifications WHERE analyst_id = $who AND event_type = 'catalogue_request.awaiting' AND entity_id = $sid")->fetchColumn();
    check('the step 1 approver is told on the bell at submission', $bell($alice, $s) === 1 && $bell($bob, $s) === 0);
    $as($alice);
    $res = catalogueApprovalDecide($conn, $alice, $s, 'approved', 'ok from IT');
    $r = $row($s);
    check('approving step 1 moves it on, not approved', $res['decision'] === 'step_passed' && $r['approval_status'] === 'pending' && (int)$r['approval_step_no'] === 2, json_encode($res));
    check('...to the team, in a new round', (int)$r['approval_team_id'] === $team && $r['approver_id'] === null && (int)$r['approvals_required'] === 2 && (int)$r['approval_round'] === 2);
    $ev = $events($s);
    check('passing step 1 tells each team member who may vote', $bell($bob, $s) === 1 && $bell($carol, $s) === 1);
    check('...recorded as a passed step, with who and the note', ($ev[0]['action'] ?? '') === 'step_passed' && str_contains($ev[0]['detail'], 'Step 1 of 2: IT manager -> Step 2 of 2: Finance') && $ev[0]['reason'] === 'ok from IT', json_encode($ev));
    check('Alice can no longer decide it', refused(fn() => catalogueApprovalDecide($conn, $alice, $s, 'approved')) !== '');
    check('Alice sees it under Decided', in_array($s, array_map('intval', array_column(catalogueApprovalsList($conn, $alice, 'decided')['items'], 'id')), true));
    $mine = array_values(array_filter(catalogueApprovalsList($conn, $bob, 'mine')['items'], fn($i) => (int)$i['id'] === $s));
    check('Bob\'s inbox shows it at step 2 of 2', $mine && $mine[0]['steps']['position'] === 2 && $mine[0]['steps']['count'] === 2 && $mine[0]['steps']['name'] === 'Finance');
    $before = (int)$conn->query("SELECT COUNT(*) FROM tickets")->fetchColumn();
    $as($bob);
    check('one team vote of two is recorded', catalogueApprovalDecide($conn, $bob, $s, 'approved')['decision'] === 'recorded');
    $as($carol);
    $res = catalogueApprovalDecide($conn, $carol, $s, 'approved');
    check('the second vote on the last step approves it and raises one ticket', $res['decision'] === 'approved' && !empty($res['ticket_id'])
        && $row($s)['approval_status'] === 'approved' && (int)$conn->query("SELECT COUNT(*) FROM tickets")->fetchColumn() === $before + 1);
    $as($admin);

    $s = $submit($f1, $uma);
    $as($alice);
    catalogueApprovalDecide($conn, $alice, $s, 'rejected', 'not this one');
    $r = $row($s);
    check('a rejection at step 1 ends it - step 2 never runs', $r['approval_status'] === 'rejected' && (int)$r['approval_step_no'] === 1 && $r['ticket_id'] === null);
    $as($admin);

    // ---- 4. Only when ----
    $f2 = $mkForm(['approval_steps' => [$person($alice, 'IT manager'), $person($bob, 'Board', ['when' => $over5000])]]);
    $s = $submit($f2, $uma, '3000');
    $steps = json_decode($row($s)['approval_steps'], true);
    $ev = $events($s);
    check('under the threshold: the board step is skipped at submission', $steps[1]['skipped'] === true && $steps[0]['skipped'] === false);
    check('...and the record says why', ($ev[0]['action'] ?? '') === 'step_skipped' && str_contains($ev[0]['detail'], 'Step 2 of 2: Board: Order total was 3000'), json_encode($ev));
    $as($alice);
    check('...so approving step 1 approves the request', catalogueApprovalDecide($conn, $alice, $s, 'approved')['decision'] === 'approved');
    $as($admin);
    $s = $submit($f2, $uma, '9000');
    check('over the threshold: the board step applies', json_decode($row($s)['approval_steps'], true)[1]['skipped'] === false);
    $s = $submit($f2, $uma, null);
    check('a blank amount: the board step RUNS', json_decode($row($s)['approval_steps'], true)[1]['skipped'] === false);
    $f3 = $mkForm(['approval_steps' => [$person($alice), $person($bob, 'Board', ['when' => ['rules' => [['question' => 'Not on this form', 'op' => 'greater_than', 'value' => '1']]]])]]);
    $s = $submit($f3, $uma);
    check('a question not on the form: the step RUNS', json_decode($row($s)['approval_steps'], true)[1]['skipped'] === false);

    // ---- 5. Every step skipped ----
    $f4 = $mkForm(['approval_steps' => [$person($bob, 'Board', ['when' => $over5000])]]);
    $before = (int)$conn->query("SELECT COUNT(*) FROM tickets")->fetchColumn();
    $s = $submit($f4, $uma, '10');
    $r = $row($s);
    $ev = array_column($events($s), 'action');
    check('no step applies: approved at once, with a ticket', $r['approval_status'] === 'approved' && !empty($r['ticket_id']) && (int)$conn->query("SELECT COUNT(*) FROM tickets")->fetchColumn() === $before + 1);
    check('...recorded as skipped, then approved automatically', $ev === ['step_skipped', 'auto_approved'], json_encode($ev));

    // ---- 6. Fallback ----
    $f5 = $mkForm(['approval_steps' => [$person($rita, 'Own manager', ['exclude_requester' => 1, 'fallback' => ['who' => 'person', 'approver_id' => $bob]])]]);
    $s = $submit($f5, $portalRita);
    $r = $row($s);
    check('the approver is the requester: the step goes to its fallback', (int)$r['approver_id'] === $bob, (string)$r['approver_id']);
    check('...recorded', in_array('step_fallback', array_column($events($s), 'action'), true));
    $conn->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES ('forms_approval_fallback', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")
         ->execute([json_encode(['who' => 'team', 'team_id' => $team, 'approvals_required' => 1, 'rejections_required' => 1])]);
    $made['setting'] = true;
    $f6 = $mkForm(['approval_steps' => [$person($rita, 'Own manager', ['exclude_requester' => 1])]]);
    $s = $submit($f6, $portalRita);
    check('no fallback on the step: the install default decides', (int)$row($s)['approval_team_id'] === $team);
    $conn->prepare("UPDATE system_settings SET setting_value = '' WHERE setting_key = 'forms_approval_fallback'")->execute();
    $s = $submit($f6, $portalRita);
    $r = $row($s);
    check('no default either: Stuck, never skipped', $r['approval_status'] === 'pending' && (int)$r['approver_id'] === $rita && catalogueApprovalProgress($conn, $r)['stuck']);

    // ---- 7. A chain ----
    $conn->prepare("INSERT INTO form_approval_chains (name, steps) VALUES (?, ?)")->execute(["ZZSTP chain $tag", json_encode([$person($carol, 'Chain step 1'), $person($alice, 'Chain step 2')])]);
    $chain = $made['chain'] = (int)$conn->lastInsertId();
    $f7 = $mkForm(['approver_id' => $bob, 'approval_steps' => [$person($bob)], 'approval_chain_id' => $chain]);
    $s = $submit($f7, $uma);
    check('a form on a chain follows the chain, not its own steps', (int)$row($s)['approver_id'] === $carol);
    $conn->prepare("UPDATE form_approval_chains SET steps = ? WHERE id = ?")->execute([json_encode([$person($bob, 'Changed')]), $chain]);
    $as($carol);
    catalogueApprovalDecide($conn, $carol, $s, 'approved');
    check('changing the chain does not change a waiting request', (int)$row($s)['approver_id'] === $alice && (int)$row($s)['approval_step_no'] === 2);
    $as($admin);
    $conn->prepare("UPDATE form_approval_chains SET is_active = 0 WHERE id = ?")->execute([$chain]);
    $s = $submit($f7, $uma);
    $r = $row($s);
    check('a chain that has gone: Stuck, never waved through', $r['approval_status'] === 'pending' && $r['approver_id'] === null && catalogueApprovalProgress($conn, $r)['stuck']);
    $conn->prepare("UPDATE form_approval_chains SET is_active = 1 WHERE id = ?")->execute([$chain]);

    // ---- 8. Snapshot, versions, override ----
    $s = $submit($f1, $uma);
    FormsService::saveForm($conn, $ctx, ['id' => $f1, 'approval_steps' => [$person($carol, 'Somebody else')]]);
    check('editing the form\'s steps does not reroute a waiting request', (int)$row($s)['approver_id'] === $alice);
    $v = FormsService::createVersion($conn, $ctx, $f7);
    $made['forms'][] = $newId = (int)$v['id'];
    $nr = $conn->query("SELECT approval_steps, approval_chain_id FROM forms WHERE id = $newId")->fetch(PDO::FETCH_ASSOC);
    check('a new version keeps the steps and the chain', (int)$nr['approval_chain_id'] === $chain && $nr['approval_steps'] !== null);
    $res = catalogueApprovalOverride($conn, $admin, $s, 'approved', 'urgent');
    check('an override on step 1 passes step 1 only', $res['decision'] === 'step_passed' && $row($s)['approval_status'] === 'pending' && (int)$row($s)['approval_step_no'] === 2);
    check('an override needs a reason', str_contains(refused(fn() => catalogueApprovalOverride($conn, $admin, $s, 'approved', '')), 'reason'));
} catch (Throwable $e) {
    check('no exception', false, $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    if ($made['setting']) {
        if ($oldDefault === false) $conn->exec("DELETE FROM system_settings WHERE setting_key = 'forms_approval_fallback'");
        else $conn->prepare("UPDATE system_settings SET setting_value = ? WHERE setting_key = 'forms_approval_fallback'")->execute([$oldDefault]);
    }
    foreach ($made['forms'] as $fid) {
        foreach ($conn->query("SELECT ticket_id FROM form_submissions WHERE form_id = " . (int)$fid . " AND ticket_id IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN) as $tid) {
            foreach (['emails', 'ticket_audit'] as $tb) { try { $conn->prepare("DELETE FROM $tb WHERE ticket_id = ?")->execute([$tid]); } catch (Throwable $e) {} }
            $conn->prepare("UPDATE form_submissions SET ticket_id = NULL WHERE ticket_id = ?")->execute([$tid]);
            try { $conn->prepare("DELETE FROM tickets WHERE id = ?")->execute([$tid]); } catch (Throwable $e) {}
        }
        $conn->prepare("DELETE sd FROM form_submission_data sd JOIN form_submissions s ON s.id = sd.submission_id WHERE s.form_id = ?")->execute([$fid]);
        $conn->prepare("DELETE FROM form_submissions WHERE form_id = ?")->execute([$fid]);
    }
    foreach (array_reverse($made['forms']) as $fid) {
        $conn->prepare("DELETE FROM form_fields WHERE form_id = ?")->execute([$fid]);
        try { $conn->prepare("DELETE FROM forms WHERE id = ?")->execute([$fid]); } catch (Throwable $e) {}
    }
    if ($made['chain']) $conn->prepare("DELETE FROM form_approval_chains WHERE id = ?")->execute([$made['chain']]);
    if ($made['team']) {
        $conn->prepare("DELETE FROM analyst_teams WHERE team_id = ?")->execute([$made['team']]);
        $conn->prepare("DELETE FROM teams WHERE id = ?")->execute([$made['team']]);
    }
    foreach ($made['users'] as $uid) $conn->prepare("DELETE FROM users WHERE id = ?")->execute([$uid]);
    foreach ($made['analysts'] as $aid) { try { $conn->prepare("DELETE FROM notifications WHERE analyst_id = ?")->execute([$aid]); } catch (Throwable $e) {} }
    foreach ($made['analysts'] as $aid) { try { $conn->prepare("DELETE FROM analysts WHERE id = ?")->execute([$aid]); } catch (Throwable $e) {} }
    $left = (int)$conn->query("SELECT COUNT(*) FROM forms WHERE title = 'ZZSTP form'")->fetchColumn()
          + (int)$conn->query("SELECT COUNT(*) FROM analysts WHERE username LIKE '$tag%'")->fetchColumn();
    check('cleanup removed everything it made', $left === 0, "$left left");
    echo str_repeat('-', 64) . "\n$pass passed, $fail failed\n";
    exit($fail ? 1 : 0);
}
