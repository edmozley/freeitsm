<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * Starters & leavers: template steps raised as tickets (GitHub #316).
 *
 *   FREEITSM_TEST_DATABASE=<throwaway db> FREEITSM_BASE_URL=http://localhost/<that install> php tests/starters-tickets.php
 *
 * A template with two steps on one ticket ("IT accounts"), a step that is a ticket of
 * its own, and a task step. Opens cases and checks: one ticket per ticket name, its
 * subject, requester (the chain), team, department, type, schedule and checklist; the
 * link and the requester switch; progress in every place it is counted; Done leaves the
 * tickets alone; a case with nobody to raise tickets for gets tasks instead; the template
 * round trip; and the case endpoint. Everything ZZ is removed in finally.
 *
 * @wiki Starters-Steps-As-Tickets-Developer-Guide
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/starters/service.php';
require_once __DIR__ . '/../includes/services/tickets.php';
require_once __DIR__ . '/../includes/template_email.php';
require_once __DIR__ . '/../includes/ticket_requester_mute.php';
require_once __DIR__ . '/../includes/report_packs/blocks_starters.php';

$pass = 0; $fail = 0;
function ok(string $what, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  PASS  {$what}\n"; }
    else       { $fail++; echo "  FAIL  {$what}" . ($detail !== '' ? "  <- {$detail}" : '') . "\n"; }
}

$conn = connectToDatabase();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$expected = getenv('FREEITSM_TEST_DATABASE');
if (!$expected || $conn->query('SELECT DATABASE()')->fetchColumn() !== $expected) {
    exit("Set FREEITSM_TEST_DATABASE to the throwaway database config.php points at - this test writes cases, tickets and people.\n");
}
$base = getenv('FREEITSM_BASE_URL');
if (!$base || str_contains($base, 'freeitsm-app')) exit("Set FREEITSM_BASE_URL to the throwaway install too.\n");
if (!StartersService::ready($conn) || !StartersService::ticketsReady($conn)) exit("Run Database Verification on this database first.\n");

echo "\nStarters & leavers - steps as tickets\n" . str_repeat('=', 72) . "\n";
$admin = (int)$conn->query("SELECT id FROM analysts WHERE is_active = 1 AND is_admin = 1 ORDER BY id LIMIT 1")->fetchColumn();
$ctx = new ActorContext(actorId: $admin, companyScope: null, source: 'ui', actorName: 'test');
$tag = 'ZZ316-' . bin2hex(random_bytes(3));
$made = ['templates' => [], 'cases' => [], 'users' => [], 'team' => 0, 'dept' => 0, 'type' => 0];
$sid = 'zzjt' . bin2hex(random_bytes(4));
$csrf = bin2hex(random_bytes(32));
$sessFile = rtrim(ini_get('session.save_path') ?: 'c:/wamp64/tmp', "/\\") . '/sess_' . $sid;

/** The tickets a case raised, by ticket name. */
$ticketsOf = function (int $caseId) use ($conn): array {
    $st = $conn->prepare("SELECT ct.ticket_name, ct.mute_requester, t.* FROM jml_case_tickets ct JOIN tickets t ON t.id = ct.ticket_id WHERE ct.case_id = ? ORDER BY ct.id");
    $st->execute([$caseId]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $out[$r['ticket_name']] = $r;
    return $out;
};

try {
    file_put_contents($sessFile, 'analyst_id|i:' . $admin . ';analyst_name|s:5:"zztst";is_admin|i:1;csrf_token|s:64:"' . $csrf . '";');
    // A team in exactly one department (so the ticket takes that department), and a ticket type.
    $conn->prepare("INSERT INTO teams (name, is_active) VALUES (?, 1)")->execute(["$tag IT"]);
    $team = $made['team'] = (int)$conn->lastInsertId();
    $conn->prepare("INSERT INTO departments (name) VALUES (?)")->execute(["$tag Dept"]);
    $dept = $made['dept'] = (int)$conn->lastInsertId();
    $conn->prepare("INSERT INTO department_teams (department_id, team_id) VALUES (?, ?)")->execute([$dept, $team]);
    $conn->prepare("INSERT INTO ticket_types (name, is_active) VALUES (?, 1)")->execute(["$tag Starter"]);
    $type = $made['type'] = (int)$conn->lastInsertId();
    $u = $conn->prepare("INSERT INTO users (email, display_name) VALUES (?, ?)");
    $u->execute([strtolower($tag) . '.manager@example.com', "$tag Manager"]); $manager = $made['users'][] = (int)$conn->lastInsertId();

    echo "The template\n";
    $steps = [
        ['title' => 'ZZ AD account for {name}', 'description' => 'Username first.last', 'raise_as' => 'ticket', 'ticket_name' => 'IT accounts', 'assigned_team_id' => $team, 'due_offset_days' => -3],
        ['title' => 'ZZ Mailbox', 'raise_as' => 'ticket', 'ticket_name' => 'it accounts', 'assigned_team_id' => $team, 'due_offset_days' => -1],
        ['title' => 'ZZ MFA token', 'raise_as' => 'ticket', 'ticket_name' => '', 'assigned_team_id' => $team, 'due_offset_days' => 0],
        ['title' => 'ZZ Induction', 'raise_as' => 'task', 'ticket_name' => 'ignored', 'due_offset_days' => 1],
    ];
    $tpl = $made['templates'][] = StartersService::saveTemplate($conn, $ctx, ['kind' => 'starter', 'name' => "$tag template", 'is_active' => 1,
        'ticket_type_id' => $type, 'email_requester' => 0, 'steps' => $steps]);
    $t = array_values(array_filter(StartersService::templates($conn), fn($x) => $x['id'] === $tpl))[0] ?? [];
    ok('saved: the ticket type and the requester switch', ($t['ticket_type_id'] ?? 0) === $type && ($t['email_requester'] ?? null) === false, json_encode([$t['ticket_type_id'] ?? null, $t['email_requester'] ?? null]));
    ok('saved: Raise as and Ticket per step; a task keeps no ticket name', array_map(fn($s) => [$s['raise_as'], $s['ticket_name']], $t['steps'] ?? [])
        === [['ticket', 'IT accounts'], ['ticket', 'it accounts'], ['ticket', null], ['task', null]], json_encode(array_map(fn($s) => [$s['raise_as'], $s['ticket_name']], $t['steps'] ?? [])));
    $bad = '';
    try { StartersService::saveTemplate($conn, $ctx, ['id' => $tpl, 'kind' => 'starter', 'name' => "$tag template", 'ticket_type_id' => 999999999, 'steps' => $steps]); }
    catch (ServiceError $e) { $bad = $e->getMessage(); }
    ok('a ticket type that does not exist is refused', $bad !== '', $bad);

    echo "Opening a case\n";
    $date = date('Y-m-d', strtotime('+10 days'));
    $warn = [];
    $case = $made['cases'][] = StartersService::createCase($conn, $ctx, ['kind' => 'starter', 'person_name' => "$tag Jane", 'effective_date' => $date,
        'template_id' => $tpl, 'manager_user_id' => $manager], $warn);
    $tk = $ticketsOf($case);
    ok('two tickets: "IT accounts" (two steps, the name matched whatever its case) and the MFA step on its own', count($tk) === 2 && isset($tk['IT accounts'], $tk['ZZ MFA token']), json_encode(array_keys($tk)) . ' ' . json_encode($warn));
    $it = $tk['IT accounts'] ?? [];
    $earliest = date('Y-m-d', strtotime("$date -3 days"));
    ok('subject: ticket name, person and due date', str_contains((string)($it['subject'] ?? ''), 'IT accounts') && str_contains((string)$it['subject'], "$tag Jane"), (string)($it['subject'] ?? ''));
    ok('requester: the case\'s manager (no form, the person not in Users)', (int)($it['user_id'] ?? 0) === $manager, (string)($it['user_id'] ?? ''));
    ok('team, its one department, and the template\'s ticket type', (int)$it['assigned_team_id'] === $team && (int)$it['department_id'] === $dept && (int)$it['ticket_type_id'] === $type,
       json_encode([$it['assigned_team_id'], $it['department_id'], $it['ticket_type_id']]));
    ok('scheduled all day on the earliest due date of its steps', substr((string)$it['work_start_datetime'], 0, 10) === $earliest && (int)$it['work_all_day'] === 1, (string)$it['work_start_datetime']);
    $items = $conn->query("SELECT i.title FROM ticket_checklist_items i JOIN ticket_checklists l ON l.id = i.ticket_checklist_id WHERE l.ticket_id = " . (int)$it['id'] . " ORDER BY i.sort_order")->fetchAll(PDO::FETCH_COLUMN);
    ok('a checklist with one item per step, in order, {name} filled', $items === ["ZZ AD account for $tag Jane", 'ZZ Mailbox'], json_encode($items));
    $body = (string)$conn->query("SELECT body_content FROM emails WHERE ticket_id = " . (int)$it['id'] . " AND is_initial = 1")->fetchColumn();
    ok('its text says what it is for, links the case, and lists the steps', str_contains($body, 'starters/view.php?id=' . $case) && str_contains($body, 'Username first.last'), substr(strip_tags($body), 0, 200));
    ok('the MFA ticket is named after its step', str_contains((string)($tk['ZZ MFA token']['subject'] ?? ''), 'ZZ MFA token'));
    $tasks = $conn->query("SELECT title FROM tasks WHERE parent_task_id = (SELECT parent_task_id FROM jml_cases WHERE id = $case)")->fetchAll(PDO::FETCH_COLUMN);
    ok('the task step is still a subtask, and only it', $tasks === ['ZZ Induction'], json_encode($tasks));
    ok('the link remembers "do not email the requester"', (int)$it['mute_requester'] === 1);

    echo "Progress\n";
    $c = StartersService::getCase($conn, $ctx, $case);
    ok('the case page lists both tickets, with checklist progress', count($c['tickets']) === 2 && $c['tickets'][0]['items_total'] === 2 && $c['tickets'][0]['items_done'] === 0, json_encode($c['tickets'][0] ?? null));
    ok('progress counts the tickets: 0 of 3', $c['progress'] === ['done' => 0, 'total' => 3], json_encode($c['progress']));
    $closed = (int)$conn->query("SELECT id FROM ticket_statuses WHERE is_closed = 1 ORDER BY id LIMIT 1")->fetchColumn();
    TicketsService::updateTicket($conn, $ctx, (int)$it['id'], ['status_id' => $closed], false);
    $c = StartersService::getCase($conn, $ctx, $case);
    ok('a closed ticket is a done step: 1 of 3', $c['progress'] === ['done' => 1, 'total' => 3], json_encode($c['progress']));
    $row = array_values(array_filter(StartersService::listCases($conn, $admin), fn($r) => $r['id'] === $case))[0] ?? [];
    ok('the case list counts the same', ($row['steps_done'] ?? -1) === 1 && ($row['steps_total'] ?? -1) === 3, json_encode([$row['steps_done'] ?? null, $row['steps_total'] ?? null]));
    $rp = $conn->query("SELECT " . rpStartersProgressCols($conn) . " FROM jml_cases c WHERE c.id = $case")->fetch(PDO::FETCH_ASSOC);
    ok('Report Packs count the same', (int)$rp['steps_done'] === 1 && (int)$rp['steps_total'] === 3, json_encode($rp));

    echo "The requester\n";
    ok('muted for the case\'s ticket', ticketRequesterEmailsMuted($conn, (int)$it['id']));
    $before = (int)$conn->query("SELECT COALESCE(MAX(id), 0) FROM email_send_log")->fetchColumn();
    sendTemplateEmail($conn, (int)$it['id'], 'ticket_closed');
    $log = $conn->query("SELECT status, error_message FROM email_send_log WHERE id > $before AND ticket_id = " . (int)$it['id'])->fetch(PDO::FETCH_ASSOC) ?: [];
    ok('the closed email is not sent - logged as skipped, saying why', ($log['status'] ?? '') === 'skipped' && str_contains((string)$log['error_message'], 'Starters & leavers'), json_encode($log));
    $other = (int)$conn->query("SELECT id FROM tickets WHERE id NOT IN (SELECT ticket_id FROM jml_case_tickets) ORDER BY id DESC LIMIT 1")->fetchColumn();
    ok('any other ticket is not muted', !$other || !ticketRequesterEmailsMuted($conn, $other));

    echo "Done leaves the tickets alone\n";
    StartersService::setStatus($conn, $ctx, $case, 'done');
    $mfaOpen = (int)$conn->query("SELECT COALESCE(ts.is_closed, 0) FROM tickets t LEFT JOIN ticket_statuses ts ON ts.id = t.status_id WHERE t.id = " . (int)$tk['ZZ MFA token']['id'])->fetchColumn() === 0;
    ok('marking the case done does not close its open ticket', $mfaOpen);

    echo "Emailing the requester, and nobody to raise for\n";
    StartersService::saveTemplate($conn, $ctx, ['id' => $tpl, 'kind' => 'starter', 'name' => "$tag template", 'is_active' => 1, 'ticket_type_id' => $type, 'email_requester' => 1, 'steps' => $steps]);
    $case2 = $made['cases'][] = StartersService::createCase($conn, $ctx, ['kind' => 'starter', 'person_name' => "$tag Bob", 'effective_date' => $date, 'template_id' => $tpl, 'manager_user_id' => $manager]);
    $tk2 = $ticketsOf($case2);
    ok('with "Email the requester" on, its tickets are not muted', ($tk2['IT accounts']['mute_requester'] ?? null) === 0 && !ticketRequesterEmailsMuted($conn, (int)$tk2['IT accounts']['id']));
    ok('...and the first case\'s ticket still is (kept on the link, not read from the template)', ticketRequesterEmailsMuted($conn, (int)$it['id']));
    $warn3 = [];
    $case3 = $made['cases'][] = StartersService::createCase($conn, $ctx, ['kind' => 'starter', 'person_name' => "$tag Nobody", 'effective_date' => $date, 'template_id' => $tpl], $warn3);
    $tasks3 = $conn->query("SELECT title FROM tasks WHERE parent_task_id = (SELECT parent_task_id FROM jml_cases WHERE id = $case3) ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
    ok('no requester at all: no tickets, the ticket steps become tasks', $ticketsOf($case3) === [] && count($tasks3) === 4, json_encode($tasks3));
    $hist = $conn->query("SELECT COUNT(*) FROM jml_audit WHERE case_id = $case3 AND field_name = 'ticket_no_requester'")->fetchColumn();
    ok('...the history and the warnings say why', (int)$hist === 2 && count($warn3) === 2, json_encode($warn3));

    echo "The case page endpoint\n";
    $ch = curl_init(rtrim($base, '/') . '/api/starters/get.php?id=' . $case);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIE => 'PHPSESSID=' . $sid, CURLOPT_TIMEOUT => 30]);
    $g = json_decode((string)curl_exec($ch), true) ?: [];
    $first = $g['case']['tickets'][0] ?? [];
    ok('get.php returns the tickets with a link to each', count($g['case']['tickets'] ?? []) === 2 && str_contains((string)($first['url'] ?? ''), (string)$it['id']) && ($first['is_closed'] ?? null) === true, json_encode($first));
} catch (Throwable $e) {
    ok('no exception', false, get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    foreach ($made['cases'] as $id) {
        $tids = $conn->query("SELECT ticket_id FROM jml_case_tickets WHERE case_id = $id")->fetchAll(PDO::FETCH_COLUMN);
        if ($tids) {
            $in = implode(',', array_map('intval', $tids));
            $conn->exec("DELETE FROM jml_case_tickets WHERE ticket_id IN ($in)");
            $conn->exec("DELETE FROM ticket_checklist_items WHERE ticket_checklist_id IN (SELECT id FROM ticket_checklists WHERE ticket_id IN ($in))");
            $conn->exec("DELETE FROM email_attachments WHERE email_id IN (SELECT id FROM emails WHERE ticket_id IN ($in))");
            foreach ($conn->query("SELECT TABLE_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME = 'ticket_id' AND TABLE_NAME NOT IN ('tickets', 'jml_case_tickets')")->fetchAll(PDO::FETCH_COLUMN) as $tbl) {
                try { $conn->exec("DELETE FROM `$tbl` WHERE ticket_id IN ($in)"); } catch (Throwable $x) {}
            }
            $conn->exec("DELETE FROM tickets WHERE id IN ($in)");
        }
        $p = $conn->query("SELECT parent_task_id FROM jml_cases WHERE id = $id")->fetchColumn();
        if ($p) {
            $ids = array_merge($conn->query("SELECT id FROM tasks WHERE parent_task_id = " . (int)$p)->fetchAll(PDO::FETCH_COLUMN), [(int)$p]);
            foreach ($ids as $t) {
                foreach (['task_checklists', 'task_audit', 'task_notes', 'task_forms', 'task_history'] as $tbl) {
                    try { $conn->exec("DELETE FROM $tbl WHERE task_id = " . (int)$t); } catch (Throwable $x) {}
                }
                $conn->exec("DELETE FROM tasks WHERE id = " . (int)$t);
            }
        }
        $conn->exec("DELETE FROM jml_cases WHERE id = $id");
    }
    foreach ($made['templates'] as $id) $conn->exec("DELETE FROM jml_templates WHERE id = $id");
    foreach ($made['users'] as $id) $conn->exec("DELETE FROM users WHERE id = $id");
    if ($made['team']) { $conn->exec("DELETE FROM department_teams WHERE team_id = " . $made['team']); $conn->exec("DELETE FROM teams WHERE id = " . $made['team']); }
    if ($made['dept']) $conn->exec("DELETE FROM departments WHERE id = " . $made['dept']);
    if ($made['type']) $conn->exec("DELETE FROM ticket_types WHERE id = " . $made['type']);
    @unlink($sessFile);
    $left = (int)$conn->query("SELECT COUNT(*) FROM tickets WHERE subject LIKE " . $conn->quote("%$tag%"))->fetchColumn()
          + (int)$conn->query("SELECT COUNT(*) FROM jml_cases WHERE person_name LIKE " . $conn->quote("$tag%"))->fetchColumn();
    ok('cleaned up', $left === 0, (string)$left);
}
echo str_repeat('-', 72) . "\n{$pass} passed, {$fail} failed\n";
exit($fail ? 1 : 0);
