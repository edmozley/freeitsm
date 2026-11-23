<?php
/* 🔴 NEVER OVER THE WEB. A test writes to the real tables - it creates analysts, portal
   users, cost centres, a people group, forms, requests and tickets, and tidies them up
   in its finally block. See tests/README.md. Run it against a disposable install. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * Approval steps decided by people in the portal (GH #160 Part C2, #348 slice 2).
 *
 *   FREEITSM_TEST_DATABASE=<throwaway db> php tests/catalogue-approval-people.php
 *
 * Covers:
 *   - a step can be the requester's manager or a cost centre's owner; a fallback cannot;
 *   - the owner step routes to the requester's own cost centre, or the one chosen in a
 *     question, fixed at submission; the requester's cost centre is kept (#348 slice 4);
 *   - staff cannot decide a people step; the manager can, in the portal, and it moves on;
 *   - owner and deputy are equals by default; "only as cover": the deputy decides when
 *     the owner is away, after N days, or when the owner made the request;
 *   - a people group owns: any member decides;
 *   - the same person at two steps approves once ("Already approved by");
 *   - nobody found goes to the step's fallback; with none, Stuck;
 *   - a rejection needs a reason; the decision is recorded against the person;
 *   - Remind: once a day per step, the approver's portal bell, switched off = refused;
 *   - Move to staff clears the people step; the portal list shows only what I may decide;
 *   - who decides is checked at each decision: a new manager decides, the old one cannot.
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

echo "Approval steps decided by people (GH #160 Part C2)\n" . str_repeat('=', 64) . "\n";
$conn = connectToDatabase();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$expected = getenv('FREEITSM_TEST_DATABASE');
if (!$expected || $expected !== $conn->query('SELECT DATABASE()')->fetchColumn()) { echo "  SKIPPED - set FREEITSM_TEST_DATABASE to this install's (throwaway) database.\n"; exit(0); }
if (!catalogueStepsAvailable($conn) || !cataloguePeopleAvailable($conn)) { echo "  SKIPPED - run Database Verification first.\n"; exit(0); }

$made = ['analysts' => [], 'users' => [], 'forms' => [], 'ccs' => [], 'group' => null];
$tag = 'zzppl' . bin2hex(random_bytes(3));
$settingKeys = ['forms_approval_deputy_mode', 'forms_approval_deputy_days', 'forms_approval_remind', 'forms_approval_fallback', 'forms_approval_mailbox_id'];
$oldSettings = [];
foreach ($settingKeys as $k) {
    $st = $conn->prepare('SELECT setting_value FROM system_settings WHERE setting_key = ?');
    $st->execute([$k]);
    $oldSettings[$k] = $st->fetchColumn();
}
$tenant = (int)getDefaultTenantId($conn);

try {
    // No email in a test, and no install default fallback.
    catalogueApprovalSettingSave($conn, 'forms_approval_mailbox_id', '');
    catalogueApprovalSettingSave($conn, 'forms_approval_fallback', '');
    catalogueApprovalSettingSave($conn, 'forms_approval_deputy_mode', 'any');
    catalogueApprovalSettingSave($conn, 'forms_approval_deputy_days', '0');
    catalogueApprovalSettingSave($conn, 'forms_approval_remind', '1');

    $conn->prepare("INSERT INTO analysts (username, password_hash, full_name, email, is_active, is_admin) VALUES (?, 'x', ?, ?, 1, ?)")
         ->execute([$tag . 'admin', 'ZZPPL admin', $tag . 'admin@example.invalid', 1]);
    $admin = $made['analysts'][] = (int)$conn->lastInsertId();
    $conn->prepare("INSERT INTO analysts (username, password_hash, full_name, email, is_active, is_admin) VALUES (?, 'x', ?, ?, 1, 0)")
         ->execute([$tag . 'alice', 'ZZPPL Alice', $tag . 'alice@example.invalid']);
    $alice = $made['analysts'][] = (int)$conn->lastInsertId();

    $mkUser = function (string $name) use ($conn, $tag, $tenant, &$made) {
        $conn->prepare("INSERT INTO users (email, display_name, tenant_id, created_at) VALUES (?, ?, ?, UTC_TIMESTAMP())")
             ->execute([$tag . $name . '@example.invalid', 'ZZPPL ' . $name, $tenant]);
        return $made['users'][] = (int)$conn->lastInsertId();
    };
    $uma = $mkUser('uma'); $mo = $mkUser('mo'); $owen = $mkUser('owen'); $dee = $mkUser('dee');
    $gail = $mkUser('gail'); $gus = $mkUser('gus'); $ula = $mkUser('ula'); $nia = $mkUser('nia'); $max = $mkUser('max');

    $mkCc = function (string $code, ?int $owner, ?int $deputy, ?int $group = null) use ($conn, $tenant, $tag, &$made) {
        $conn->prepare("INSERT INTO cost_centres (tenant_id, code, name, owner_user_id, owner_group_id, deputy_user_id) VALUES (?, ?, ?, ?, ?, ?)")
             ->execute([$tenant, $tag . $code, 'ZZPPL ' . $code, $owner, $group, $deputy]);
        return $made['ccs'][] = (int)$conn->lastInsertId();
    };
    $cc1 = $mkCc('0010', $owen, $dee);
    $conn->prepare("INSERT INTO knowledge_user_groups (name, is_active) VALUES (?, 1)")->execute(['ZZPPL controlling ' . $tag]);
    $group = $made['group'] = (int)$conn->lastInsertId();
    $gm = $conn->prepare("INSERT INTO knowledge_user_group_members (group_id, member_type, member_id) VALUES (?, 'user', ?)");
    $gm->execute([$group, $gail]); $gm->execute([$group, $gus]);
    $cc2 = $mkCc('0020', null, null, $group);

    $setUser = fn(int $id, array $cols) => $conn->prepare('UPDATE users SET ' . implode(', ', array_map(fn($c) => "$c = ?", array_keys($cols))) . ' WHERE id = ?')
                                               ->execute([...array_values($cols), $id]);
    $setUser($uma, ['manager_id' => $mo, 'cost_centre_id' => $cc1]);
    $setUser($owen, ['manager_id' => $mo, 'cost_centre_id' => $cc1]);   // the owner asks for himself
    $setUser($ula, ['manager_id' => $owen, 'cost_centre_id' => $cc1]);  // manager = owner: approves once
    $setUser($nia, ['cost_centre_id' => $cc1]);                          // no manager

    $ctx = new ActorContext(actorId: $admin, source: 'ui');
    $_SESSION = ['analyst_id' => $admin];
    $mkForm = function (array $steps, array $extra = []) use ($conn, $ctx, &$made) {
        $id = (int)FormsService::saveForm($conn, $ctx, ['title' => 'ZZPPL form', 'is_portal_visible' => 1, 'requires_approval' => 1,
            'fields' => [['field_type' => 'text', 'label' => 'ZZPPL what'],
                         ['field_type' => 'lookup', 'label' => 'Charge to', 'config' => ['lookup_source' => 'cost_centre', 'portal_lookup' => true]]],
            'approval_steps' => $steps] + $extra)['id'];
        return $made['forms'][] = $id;
    };
    $submit = function (int $formId, int $user, ?int $chargeTo = null) use ($conn) {
        $f = $conn->query("SELECT label, id FROM form_fields WHERE form_id = $formId")->fetchAll(PDO::FETCH_KEY_PAIR);
        $data = [$f['ZZPPL what'] => 'ZZPPL laptop'];
        if ($chargeTo) $data[$f['Charge to']] = json_encode(['id' => $chargeTo, 'label' => 'x']);
        return FormsService::submitForm($conn, new ActorContext(actorId: 0, source: 'portal'), $formId, $data, $user);
    };
    $row = fn(int $sid) => $conn->query("SELECT s.*, f.title AS form_title FROM form_submissions s JOIN forms f ON f.id = s.form_id WHERE s.id = $sid")->fetch(PDO::FETCH_ASSOC);
    $events = fn(int $sid) => $conn->query("SELECT action, detail, reason, user_id FROM form_submission_approval_events WHERE submission_id = $sid ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    $mineIds = fn(int $u) => array_column(catalogueUserApprovalsList($conn, $u, 'mine')['items'], 'id');
    $pbell = fn(int $u, int $sid) => (int)$conn->query("SELECT COALESCE(SUM(event_count), 0) FROM portal_notifications WHERE user_id = $u AND entity_type = 'catalogue_request' AND entity_id = $sid")->fetchColumn();
    $manager = ['name' => 'Line manager', 'who' => 'manager'];
    $ownerOwn = ['name' => 'Budget', 'who' => 'cc_owner', 'cc_question' => ''];

    // ---- 1. Saving ----
    check('a fallback cannot be the manager', str_contains(refused(fn() => $mkForm([$manager + ['fallback' => ['who' => 'manager']]])), 'choose'));
    $f1 = $mkForm([$manager, $ownerOwn]);
    $saved = json_decode((string)$conn->query("SELECT approval_steps FROM forms WHERE id = $f1")->fetchColumn(), true);
    check('manager and owner steps save', ($saved[0]['who'] ?? '') === 'manager' && ($saved[1]['who'] ?? '') === 'cc_owner' && ($saved[1]['cc_question'] ?? null) === '');

    // ---- 2. Manager, then owner (equals) ----
    $s = $submit($f1, $uma);
    $r = $row($s);
    $snap = json_decode($r['approval_steps'], true);
    check('submitted: on the manager step, nobody stored', $r['approver_kind'] === 'manager' && $r['approver_id'] === null && $r['approval_team_id'] === null && $r['approval_step_datetime'] !== null);
    check("the owner step is routed to the requester's own cost centre", (int)($snap[1]['cost_centre_id'] ?? 0) === $cc1);
    check("the requester's cost centre is kept with the request (#348 slice 4)", (int)$r['requester_cost_centre_id'] === $cc1);
    check('the manager is told on the portal bell', $pbell($mo, $s) === 1 && $pbell($owen, $s) === 0);
    check('staff cannot decide it - not even an administrator', str_contains(refused(fn() => catalogueApprovalDecide($conn, $admin, $s, 'approved')), 'portal'));
    check("it is in the manager's portal list, nobody else's", in_array($s, $mineIds($mo), true) && !in_array($s, $mineIds($owen), true) && !in_array($s, $mineIds($uma), true));
    check('somebody else cannot decide it by its id', str_contains(refused(fn() => catalogueDecideAsPerson($conn, $owen, $s, 'approved')), 'not waiting for you'));
    check('the manager and the owner see the Approvals tab; the requester does not', catalogueUserIsApprover($conn, $mo) && catalogueUserIsApprover($conn, $owen) && !catalogueUserIsApprover($conn, $uma));
    $res = catalogueDecideAsPerson($conn, $mo, $s, 'approved', 'fine by me');
    $r = $row($s);
    $ev = $events($s);
    check('the manager approves: it moves on to the owner step', $res['decision'] === 'step_passed' && $r['approver_kind'] === 'cc_owner' && (int)$r['approval_cost_centre_id'] === $cc1 && (int)$r['approval_step_no'] === 2);
    check('...recorded against the manager, as a person', ($ev[0]['action'] ?? '') === 'step_passed' && (int)$ev[0]['user_id'] === $mo && $ev[0]['reason'] === 'fine by me');
    check('owner and deputy are both told, and both may decide (equals)', $pbell($owen, $s) === 1 && $pbell($dee, $s) === 1
        && in_array($s, $mineIds($owen), true) && in_array($s, $mineIds($dee), true));
    check("the manager sees it under Decided", in_array($s, array_column(catalogueUserApprovalsList($conn, $mo, 'decided')['items'], 'id'), true));

    // ---- 3. The deputy only as cover ----
    catalogueApprovalSettingSave($conn, 'forms_approval_deputy_mode', 'cover');
    check('cover: the deputy cannot decide while the owner can', !catalogueUserMayDecide($conn, $row($s), $dee) && catalogueUserMayDecide($conn, $row($s), $owen));
    catalogueSetAway($conn, $owen, gmdate('Y-m-d', time() + 86400 * 3));
    check('cover: the owner marked away - the deputy decides', catalogueUserMayDecide($conn, $row($s), $dee));
    check('a date in the past is refused', refused(fn() => catalogueSetAway($conn, $owen, '2000-01-01')) !== '');
    catalogueSetAway($conn, $owen, '');
    check('cover: back again - the deputy cannot', !catalogueUserMayDecide($conn, $row($s), $dee));
    catalogueApprovalSettingSave($conn, 'forms_approval_deputy_days', '2');
    $conn->exec("UPDATE form_submissions SET approval_step_datetime = UTC_TIMESTAMP() - INTERVAL 3 DAY WHERE id = $s");
    check('cover: after N days without a decision - the deputy decides', catalogueUserMayDecide($conn, $row($s), $dee));
    $before = (int)$conn->query("SELECT COUNT(*) FROM tickets")->fetchColumn();
    $res = catalogueDecideAsPerson($conn, $dee, $s, 'approved');
    $r = $row($s);
    check('the deputy approves the last step: approved, one ticket, recorded against the deputy',
        $res['decision'] === 'approved' && $r['approval_status'] === 'approved' && (int)$r['approval_decided_by_user_id'] === $dee
        && (int)$conn->query("SELECT COUNT(*) FROM tickets")->fetchColumn() === $before + 1);
    $body = (string)$conn->query("SELECT body_content FROM emails WHERE ticket_id = " . (int)$r['ticket_id'] . " AND is_initial = 1")->fetchColumn();
    check("the ticket names the requester's cost centre", str_contains($body, $tag . '0010'));
    catalogueApprovalSettingSave($conn, 'forms_approval_deputy_days', '0');

    // ---- 4. The owner asks: the deputy decides, even as cover ----
    $s = $submit($f1, $owen);
    catalogueDecideAsPerson($conn, $mo, $s, 'approved');
    $p = catalogueStepPeople($conn, $row($s));
    check('the owner made the request: only the deputy decides', $p['decide'] === [$dee] && !catalogueUserMayDecide($conn, $row($s), $owen));
    check('a rejection needs a reason', str_contains(refused(fn() => catalogueDecideAsPerson($conn, $dee, $s, 'rejected', '')), 'why'));
    catalogueDecideAsPerson($conn, $dee, $s, 'rejected', 'Over budget this quarter');
    $r = $row($s);
    check('...and the rejection ends it, recorded against the deputy', $r['approval_status'] === 'rejected' && (int)$r['approval_decided_by_user_id'] === $dee && $r['approval_comment'] === 'Over budget this quarter');
    catalogueApprovalSettingSave($conn, 'forms_approval_deputy_mode', 'any');

    // ---- 5. A people group owns the cost centre chosen in a question ----
    $f2 = $mkForm([['name' => 'Controlling', 'who' => 'cc_owner', 'cc_question' => 'Charge to']]);
    $s = $submit($f2, $uma, $cc2);
    $r = $row($s);
    check('the owner step goes to the cost centre chosen in the question', $r['approver_kind'] === 'cc_owner' && (int)$r['approval_cost_centre_id'] === $cc2);
    check('any member of the owning group may decide', catalogueUserMayDecide($conn, $r, $gail) && catalogueUserMayDecide($conn, $r, $gus) && !catalogueUserMayDecide($conn, $r, $owen));
    $res = catalogueDecideAsPerson($conn, $gus, $s, 'approved');
    check('one member settles it', $res['decision'] === 'approved' && !catalogueUserMayDecide($conn, $row($s), $gail));

    // ---- 6. The same person at two steps approves once ----
    $s = $submit($f1, $ula);
    check("Ula's manager Owen also owns her cost centre", catalogueUserMayDecide($conn, $row($s), $owen));
    $res = catalogueDecideAsPerson($conn, $owen, $s, 'approved');
    $kinds = array_column($events($s), 'action');
    check('he approves once: the owner step is "already approved", and the request is approved',
        $res['decision'] === 'approved' && $row($s)['approval_status'] === 'approved' && in_array('step_already_approved', $kinds, true), json_encode($kinds));

    // ---- 7. Nobody found: the fallback, else Stuck ----
    $f3 = $mkForm([$manager + ['fallback' => ['who' => 'person', 'approver_id' => $alice]]]);
    $s = $submit($f3, $nia);
    $r = $row($s);
    check('no manager: the step goes to its fallback (staff)', (int)$r['approver_id'] === $alice && $r['approver_kind'] === null
        && in_array('step_fallback', array_column($events($s), 'action'), true));
    $f4 = $mkForm([$manager, ['name' => 'IT', 'who' => 'person', 'approver_id' => $alice]]);
    $s = $submit($f4, $nia);
    $r = $row($s);
    $pr = catalogueApprovalProgress($conn, $r);
    check('no manager and no fallback: Stuck, never skipped', $r['approver_kind'] === 'manager' && $pr['stuck'] && $pr['stuck_reason'] === 'no_manager' && (int)$r['approval_step_no'] === 1);

    // ---- 8. Who decides is checked at each decision ----
    $s = $submit($f1, $uma);
    $setUser($uma, ['manager_id' => $max]);
    check('a new manager decides; the old one cannot', catalogueUserMayDecide($conn, $row($s), $max) && !catalogueUserMayDecide($conn, $row($s), $mo));
    $setUser($uma, ['manager_id' => $mo]);

    // ---- 9. Remind ----
    $before = $pbell($mo, $s);
    $told = catalogueRemind($conn, $uma, $s);
    check('Remind tells the approver again', $told >= 1 && $pbell($mo, $s) > $before && $row($s)['approval_reminded_datetime'] !== null);
    check('a second Remind the same day is refused', str_contains(refused(fn() => catalogueRemind($conn, $uma, $s)), 'already'));
    check("somebody else's request cannot be reminded", str_contains(refused(fn() => catalogueRemind($conn, $mo, $s)), 'not found'));
    $conn->exec("UPDATE form_submissions SET approval_reminded_datetime = NULL WHERE id = $s");
    catalogueApprovalSettingSave($conn, 'forms_approval_remind', '0');
    check('Remind switched off: refused', !catalogueCanRemind($conn, $row($s)) && refused(fn() => catalogueRemind($conn, $uma, $s)) !== '');
    catalogueApprovalSettingSave($conn, 'forms_approval_remind', '1');
    catalogueDecideAsPerson($conn, $mo, $s, 'approved');
    check('passing a step resets the Remind clock', $row($s)['approval_reminded_datetime'] === null && catalogueCanRemind($conn, $row($s)));

    // ---- 10. Move and Override (an administrator) ----
    $_SESSION = ['analyst_id' => $admin];
    catalogueApprovalMove($conn, $admin, $s, ['approver_id' => $alice], 'owner on leave');
    $r = $row($s);
    check('Move to staff clears the people step', $r['approver_kind'] === null && $r['approval_cost_centre_id'] === null && (int)$r['approver_id'] === $alice
        && !catalogueUserMayDecide($conn, $r, $owen));
    $s = $submit($f1, $uma);
    $res = catalogueApprovalOverride($conn, $admin, $s, 'approved', 'urgent');
    check('Override passes the manager step only', $res['decision'] === 'step_passed' && $row($s)['approver_kind'] === 'cc_owner');

    // ---- 11. The staff inbox names who a people step waits on ----
    $all = array_values(array_filter(catalogueApprovalsList($conn, $admin, 'all')['items'], fn($i) => (int)$i['id'] === $s));
    check('the staff inbox shows the owner and the requester cost centre', $all && ($all[0]['people']['kind'] ?? '') === 'cc_owner'
        && in_array('ZZPPL owen', $all[0]['people']['names'], true) && str_contains((string)$all[0]['requester_cost_centre'], $tag . '0010')
        && !$all[0]['can_decide'], json_encode($all[0]['people'] ?? null));
} finally {
    foreach ($oldSettings as $k => $v) {
        if ($v === false) $conn->prepare('DELETE FROM system_settings WHERE setting_key = ?')->execute([$k]);
        else $conn->prepare('UPDATE system_settings SET setting_value = ? WHERE setting_key = ?')->execute([$v, $k]);
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
    foreach ($made['users'] as $uid) $conn->prepare("UPDATE users SET manager_id = NULL, cost_centre_id = NULL WHERE id = ?")->execute([$uid]);
    foreach ($made['ccs'] as $cid) $conn->prepare("DELETE FROM cost_centres WHERE id = ?")->execute([$cid]);
    if ($made['group']) $conn->prepare("DELETE FROM knowledge_user_groups WHERE id = ?")->execute([$made['group']]);
    foreach ($made['users'] as $uid) {
        try { $conn->prepare("DELETE FROM portal_notifications WHERE user_id = ?")->execute([$uid]); } catch (Throwable $e) {}
        try { $conn->prepare("DELETE FROM users WHERE id = ?")->execute([$uid]); } catch (Throwable $e) { echo "  (user $uid: " . $e->getMessage() . ")\n"; }
    }
    foreach ($made['analysts'] as $aid) { try { $conn->prepare("DELETE FROM notifications WHERE analyst_id = ?")->execute([$aid]); } catch (Throwable $e) {} }
    foreach ($made['analysts'] as $aid) { try { $conn->prepare("DELETE FROM analysts WHERE id = ?")->execute([$aid]); } catch (Throwable $e) {} }
    $left = (int)$conn->query("SELECT COUNT(*) FROM forms WHERE title = 'ZZPPL form'")->fetchColumn()
          + (int)$conn->query("SELECT COUNT(*) FROM users WHERE email LIKE '$tag%'")->fetchColumn()
          + (int)$conn->query("SELECT COUNT(*) FROM cost_centres WHERE code LIKE '$tag%'")->fetchColumn()
          + (int)$conn->query("SELECT COUNT(*) FROM analysts WHERE username LIKE '$tag%'")->fetchColumn();
    check('cleanup removed everything it made', $left === 0, "$left left");
    echo str_repeat('-', 64) . "\n$pass passed, $fail failed\n";
    exit($fail ? 1 : 0);
}
