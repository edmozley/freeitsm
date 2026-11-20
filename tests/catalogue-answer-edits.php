<?php
/* 🔴 NEVER OVER THE WEB. A test writes to the real tables - it creates analysts, a
   team, portal users, forms and requests, and only tidies them up if it runs to the
   end. See tests/README.md. Run it against a disposable install. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * An approver changes a waiting request's answers (discussion #298).
 *   - who may: the named approver, a member of the approving team, an administrator;
 *     never anyone else, and never the requester - even where they could approve;
 *   - every change is an 'answer_changed' event with the field, old and new, and reason;
 *     an unchanged answer records nothing;
 *   - the rules a submission passes (choices, numbers, required, dates) are applied, and
 *     a refused edit changes nothing at all - not even its valid half;
 *   - a question the requester did not answer can be filled in;
 *   - the ticket's body lists what changed; a decided request cannot be edited;
 *   - the inbox says can_edit, and lists the fields with lookups and tables locked.
 * ⚠️ Touches the database. Everything is prefixed ZZEDIT and removed in the finally block.
 * Run:  php tests/catalogue-answer-edits.php
 *
 * @wiki Catalogue-Request-Approvals-Developer-Guide
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/services/forms.php';
require_once __DIR__ . '/../includes/catalogue_approvals.php';

$pass = 0; $fail = 0;
function check(string $label, bool $ok, string $detail = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ok    $label\n"; }
    else     { $fail++; echo "  FAIL  $label" . ($detail !== '' ? "  <- $detail" : '') . "\n"; }
}
function refused(callable $fn): string {
    try { $fn(); return ''; } catch (Throwable $e) { return $e->getMessage(); }
}

echo "Approvers change a waiting request's answers (#298)\n" . str_repeat('=', 64) . "\n";
$conn = connectToDatabase();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
if (!catalogueTeamApprovalAvailable($conn)) { echo "  SKIPPED - run Database Verification first.\n"; exit(0); }

$made = ['analysts' => [], 'users' => [], 'forms' => [], 'team' => null];
$tag = 'zzedit' . bin2hex(random_bytes(3));

try {
    $mkAnalyst = function (string $name, bool $admin = false) use ($conn, $tag, &$made) {
        $conn->prepare("INSERT INTO analysts (username, password_hash, full_name, email, is_active, is_admin) VALUES (?, 'x', ?, ?, 1, ?)")
             ->execute([$tag . $name, 'ZZEDIT ' . $name, $tag . $name . '@example.invalid', $admin ? 1 : 0]);
        return $made['analysts'][] = (int)$conn->lastInsertId();
    };
    $alice = $mkAnalyst('alice'); $bob = $mkAnalyst('bob'); $carol = $mkAnalyst('carol'); $rita = $mkAnalyst('rita');
    $admin = $mkAnalyst('admin', true);
    $conn->prepare("INSERT INTO users (email, display_name, created_at) VALUES (?, 'ZZEDIT Rita (portal)', UTC_TIMESTAMP())")->execute([$tag . 'rita@example.invalid']);
    $portalRita = $made['users'][] = (int)$conn->lastInsertId();
    $conn->prepare("INSERT INTO users (email, display_name, created_at) VALUES (?, 'ZZEDIT Uma', UTC_TIMESTAMP())")->execute([$tag . 'uma@example.invalid']);
    $portalUma = $made['users'][] = (int)$conn->lastInsertId();
    $conn->prepare("INSERT INTO teams (name, is_active) VALUES (?, 1)")->execute(['ZZEDIT approvers ' . $tag]);
    $team = $made['team'] = (int)$conn->lastInsertId();
    $join = $conn->prepare("INSERT INTO analyst_teams (analyst_id, team_id) VALUES (?, ?)");
    foreach ([$alice, $bob, $rita] as $m) $join->execute([$m, $team]);

    $ctx = new ActorContext(actorId: $admin, source: 'ui');
    $as = function (int $who) { $_SESSION = ['analyst_id' => $who]; };
    $as($admin);
    $fields = [
        ['field_type' => 'text',       'label' => 'Username'],
        ['field_type' => 'dropdown',   'label' => 'Site',   'options' => ['North', 'South']],
        ['field_type' => 'checkboxes', 'label' => 'Access', 'options' => ['VPN', 'Email']],
        ['field_type' => 'number',     'label' => 'Cost'],
        ['field_type' => 'text',       'label' => 'Why', 'is_required' => 1],
        ['field_type' => 'checkbox',   'label' => 'Laptop'],
        ['field_type' => 'grid',       'label' => 'Kit', 'config' => ['columns' => [['id' => 1, 'label' => 'Item', 'type' => 'text']]]],
    ];
    $mkForm = function (array $policy) use ($conn, $ctx, &$made, $fields) {
        $id = (int)FormsService::saveForm($conn, $ctx, ['title' => 'ZZEDIT form', 'is_portal_visible' => 1, 'requires_approval' => 1,
            'fields' => $fields] + $policy)['id'];
        return $made['forms'][] = $id;
    };
    $fid = function (int $formId, string $label) use ($conn) {
        $st = $conn->prepare("SELECT id FROM form_fields WHERE form_id = ? AND label = ?"); $st->execute([$formId, $label]);
        return (int)$st->fetchColumn();
    };
    $submit = fn(int $formId, int $user) => FormsService::submitForm($conn, new ActorContext(actorId: 0, source: 'portal'), $formId,
        [$fid($formId, 'Site') => 'North', $fid($formId, 'Why') => 'New starter', $fid($formId, 'Access') => ['VPN']], $user);
    $answer = function (int $sid, int $fieldId) use ($conn) {
        $st = $conn->prepare("SELECT field_value FROM form_submission_data WHERE submission_id = ? AND field_id = ?"); $st->execute([$sid, $fieldId]);
        $v = $st->fetchColumn(); return $v === false ? null : (string)$v;
    };
    $events = fn(int $sid) => array_values(array_filter(catalogueApprovalEvents($conn, $sid), fn($e) => $e['action'] === 'answer_changed'));

    // ---- 1. A named approver ----
    $f1 = $mkForm(['approver_id' => $alice]);
    $s = $submit($f1, $portalUma);
    $user = $fid($f1, 'Username'); $site = $fid($f1, 'Site'); $access = $fid($f1, 'Access'); $cost = $fid($f1, 'Cost');
    $why = $fid($f1, 'Why'); $laptop = $fid($f1, 'Laptop'); $kit = $fid($f1, 'Kit');

    $as($bob);
    check('somebody else cannot change the answers', str_contains(refused(fn() => catalogueEditAnswers($conn, $bob, $s, [$user => 'x'])), 'Only the approver'));
    $as($alice);
    $ch = catalogueEditAnswers($conn, $alice, $s, [$user => 'jsmith', $site => 'South', $access => ['VPN', 'Email'], $why => 'New starter'], 'Checked AD');
    check('the approver changes three answers; the unchanged fourth is not recorded', count($ch) === 3, json_encode($ch));
    check('a question the requester left empty is filled in', $answer($s, $user) === 'jsmith');
    check('a choice is stored as a submission stores it', $answer($s, $site) === 'South' && $answer($s, $access) === json_encode(['VPN', 'Email']));
    $ev = $events($s);
    $d = json_decode((string)($ev[1]['detail'] ?? ''), true) ?: [];
    check('each change is an event: field, old, new, who, reason', count($ev) === 3 && ($d['label'] ?? '') === 'Site' && ($d['old'] ?? '') === 'North'
        && ($d['new'] ?? '') === 'South' && ($ev[1]['reason'] ?? '') === 'Checked AD' && ($ev[1]['full_name'] ?? '') === 'ZZEDIT alice', json_encode($ev[1] ?? null));
    check('the same values again change nothing and record nothing', catalogueEditAnswers($conn, $alice, $s, [$site => 'South']) === [] && count($events($s)) === 3);

    // The rules a submission passes - and a refused edit changes nothing at all.
    check('a choice not on the list is refused', str_contains(refused(fn() => catalogueEditAnswers($conn, $alice, $s, [$site => 'East'])), 'not on its list'));
    check('a number that is not a number is refused', str_contains(refused(fn() => catalogueEditAnswers($conn, $alice, $s, [$cost => 'lots'])), 'must be a number'));
    check('a required answer cannot be emptied', str_contains(refused(fn() => catalogueEditAnswers($conn, $alice, $s, [$why => ''])), 'required'));
    check('a tick-list option not on the list is refused', refused(fn() => catalogueEditAnswers($conn, $alice, $s, [$access => ['Root']])) !== '');
    refused(fn() => catalogueEditAnswers($conn, $alice, $s, [$user => 'jsmith2', $site => 'East']));
    check('a refused edit keeps nothing - not even its valid half', $answer($s, $user) === 'jsmith' && count($events($s)) === 3);
    check('a table cannot be changed here', str_contains(refused(fn() => catalogueEditAnswers($conn, $alice, $s, [$kit => '[]'])), 'cannot be changed'));
    check('a question from another form is refused', str_contains(refused(fn() => catalogueEditAnswers($conn, $alice, $s, [999999999 => 'x'])), 'not on this form'));
    catalogueEditAnswers($conn, $alice, $s, [$laptop => true, $cost => '450']);
    check('a single tick and a number are stored as a submission stores them', $answer($s, $laptop) === '1' && $answer($s, $cost) === '450');

    // The inbox and the ticket.
    $list = catalogueApprovalsList($conn, $alice, 'mine');
    $card = null; foreach ($list['items'] as $it) if ((int)$it['id'] === $s) $card = $it;
    $byLabel = []; foreach ($card['edit_fields'] ?? [] as $f) $byLabel[$f['label']] = $f;
    check('the inbox says the approver can edit it', !empty($card['can_edit']));
    check('...and lists every question, the table locked', isset($byLabel['Username']) && $byLabel['Username']['editable'] && isset($byLabel['Kit']) && !$byLabel['Kit']['editable']);
    $body = catalogueSubmissionBodyHtml($conn, $s, 'ZZEDIT form');
    check("the ticket's body has the corrected answers and lists the changes", str_contains($body, 'jsmith') && str_contains($body, 'Checked AD') && substr_count($body, '<li>') >= 5, substr(strip_tags($body), 0, 200));
    check('the requester sees the changes, without the reason', count(catalogueAnswerChanges($conn, $s)) === 5);
    $res = catalogueApprovalDecide($conn, $alice, $s, 'approved');
    check('approving still raises the ticket', !empty($res['ticket_id']));
    check('a decided request cannot be edited', str_contains(refused(fn() => catalogueEditAnswers($conn, $alice, $s, [$user => 'x'])), 'not awaiting approval'));

    // ---- 2. The requester never edits their own request ----
    $f2 = $mkForm(['approver_id' => $rita]);
    $s = $submit($f2, $portalRita);
    $as($rita);
    check('the requester cannot change their own request, even as its approver', str_contains(refused(fn() => catalogueEditAnswers($conn, $rita, $s, [$fid($f2, 'Username') => 'x'])), 'made yourself'));
    check('...and the inbox does not offer it', !catalogueCanEditAnswers($conn, $rita, $conn->query("SELECT * FROM form_submissions WHERE id = $s")->fetch(PDO::FETCH_ASSOC)));

    // ---- 3. A team, and an administrator ----
    $as($admin);
    $f3 = $mkForm(['approval_team_id' => $team, 'approvals_required' => 2]);
    $s = $submit($f3, $portalUma);
    $as($bob);
    check('a member of the approving team can edit', count(catalogueEditAnswers($conn, $bob, $s, [$fid($f3, 'Username') => 'bob-set'])) === 1);
    $as($carol);
    check('someone outside the team cannot', str_contains(refused(fn() => catalogueEditAnswers($conn, $carol, $s, [$fid($f3, 'Username') => 'x'])), 'Only the approver'));
    $as($admin);
    check('an administrator can', count(catalogueEditAnswers($conn, $admin, $s, [$fid($f3, 'Username') => 'admin-set'])) === 1);
} finally {
    foreach ($made['forms'] as $f) {
        foreach ($conn->query("SELECT ticket_id FROM form_submissions WHERE form_id = " . (int)$f . " AND ticket_id IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN) as $tid) {
            foreach (['emails', 'ticket_audit'] as $tb) { try { $conn->prepare("DELETE FROM $tb WHERE ticket_id = ?")->execute([$tid]); } catch (Throwable $e) {} }
            $conn->prepare("UPDATE form_submissions SET ticket_id = NULL WHERE ticket_id = ?")->execute([$tid]);
            try { $conn->prepare("DELETE FROM tickets WHERE id = ?")->execute([$tid]); } catch (Throwable $e) {}
        }
        $conn->prepare("DELETE sd FROM form_submission_data sd JOIN form_submissions s ON s.id = sd.submission_id WHERE s.form_id = ?")->execute([$f]);
        $conn->prepare("DELETE FROM form_submissions WHERE form_id = ?")->execute([$f]);
    }
    foreach (array_reverse($made['forms']) as $f) {
        $conn->prepare("DELETE FROM form_fields WHERE form_id = ?")->execute([$f]);
        try { $conn->prepare("DELETE FROM forms WHERE id = ?")->execute([$f]); } catch (Throwable $e) {}
    }
    if ($made['team']) {
        $conn->prepare("DELETE FROM analyst_teams WHERE team_id = ?")->execute([$made['team']]);
        $conn->prepare("DELETE FROM teams WHERE id = ?")->execute([$made['team']]);
    }
    foreach ($made['users'] as $uid) $conn->prepare("DELETE FROM users WHERE id = ?")->execute([$uid]);
    foreach ($made['analysts'] as $aid) { try { $conn->prepare("DELETE FROM analysts WHERE id = ?")->execute([$aid]); } catch (Throwable $e) {} }
    $left = (int)$conn->query("SELECT COUNT(*) FROM forms WHERE title = 'ZZEDIT form'")->fetchColumn()
          + (int)$conn->query("SELECT COUNT(*) FROM analysts WHERE username LIKE '$tag%'")->fetchColumn();
    check('cleanup removed everything it made', $left === 0, "$left left");
    echo str_repeat('-', 64) . "\n$pass passed, $fail failed\n";
    exit($fail ? 1 : 0);
}
