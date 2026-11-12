<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * Tickets: an analyst in teams sees only their teams' departments' tickets - on
 * every path, not only the folders (includes/ticket_department_scope.php).
 *
 * Sets up two departments (ZZDS Facilities, ZZDS HR), a team linked to Facilities,
 * an analyst in it, an analyst in no team, an administrator in the team, six
 * tickets and an API key, drives the REAL endpoints over HTTP with forged
 * sessions, and removes everything in `finally`.
 *
 * Run: FREEITSM_TEST_DATABASE=<throwaway db> FREEITSM_TEST_URL=http://localhost/<that install> php tests/ticket-department-scope.php
 */
chdir(__DIR__ . '/..');
$pass = 0; $fail = 0;
function check(string $what, bool $ok, string $detail = ''): void { global $pass, $fail; echo ($ok ? '  [PASS] ' : '  [FAIL] ') . $what . ($ok || $detail === '' ? '' : "  <- $detail") . "\n"; $ok ? $pass++ : $fail++; }

$expected = getenv('FREEITSM_TEST_DATABASE');
$base = rtrim((string)getenv('FREEITSM_TEST_URL'), '/');
if (!$expected || !$base) { echo "(skipped: set FREEITSM_TEST_DATABASE and FREEITSM_TEST_URL)\n"; exit(0); }
require_once 'config.php';
require_once 'includes/functions.php';
$conn = connectToDatabase();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
if ($conn->query('SELECT DATABASE()')->fetchColumn() !== $expected) { echo "(skipped: config.php does not use $expected)\n"; exit(0); }

$sessDir = rtrim(ini_get('session.save_path') ?: 'C:/wamp64/tmp', '/\\');
$made = ['tickets' => [], 'analysts' => [], 'key' => 0, 'team' => 0];
$sess = [];
$forge = function (int $id, string $user) use ($sessDir, &$sess) {
    $sid = 'zzds' . $id . bin2hex(random_bytes(4));
    file_put_contents("$sessDir/sess_$sid", 'analyst_id|i:' . $id . ';analyst_username|s:' . strlen($user) . ':"' . $user . '";analyst_name|s:4:"ZZDS";csrf_token|s:1:"x";');
    $sess[] = "$sessDir/sess_$sid";
    return $sid;
};
$http = function (string $sid, string $path, ?array $post = null) use ($base) {
    $ch = curl_init("$base/$path");
    $o = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_COOKIE => "PHPSESSID=$sid", CURLOPT_HTTPHEADER => ['X-CSRF-Token: x', 'Content-Type: application/json']];
    if ($post !== null) { $o[CURLOPT_POST] = true; $o[CURLOPT_POSTFIELDS] = json_encode($post); }
    curl_setopt_array($ch, $o);
    return (string)curl_exec($ch);
};
$has = fn(string $body, string $word) => str_contains($body, $word);

try {
    $conn->exec("INSERT INTO departments (name, is_active, display_order) VALUES ('ZZDS Facilities', 1, 990), ('ZZDS HR', 1, 991)");
    $fac = (int)$conn->query("SELECT id FROM departments WHERE name = 'ZZDS Facilities'")->fetchColumn();
    $hr  = (int)$conn->query("SELECT id FROM departments WHERE name = 'ZZDS HR'")->fetchColumn();
    $conn->exec("INSERT INTO teams (name, is_active) VALUES ('ZZDS Facilities team', 1)");
    $team = $made['team'] = (int)$conn->lastInsertId();
    $conn->exec("INSERT INTO department_teams (department_id, team_id) VALUES ($fac, $team)");
    $mkAnalyst = function (string $user, int $admin) use ($conn, &$made) {
        $conn->prepare("INSERT INTO analysts (username, password_hash, full_name, email, is_active, is_admin, can_access_all_modules, can_access_all_tenants) VALUES (?, '!', ?, ?, 1, ?, 1, 1)")
             ->execute([$user, "ZZDS $user", "$user@example.invalid", $admin]);
        return $made['analysts'][] = (int)$conn->lastInsertId();
    };
    $inTeam = $mkAnalyst('zzdsfac', 0);
    $noTeam = $mkAnalyst('zzdsfree', 0);
    $admin  = $mkAnalyst('zzdsadmin', 1);
    $conn->exec("INSERT INTO analyst_teams (analyst_id, team_id) VALUES ($inTeam, $team), ($admin, $team)");

    $tenant = $conn->query("SELECT id FROM tenants ORDER BY id LIMIT 1")->fetchColumn();
    $ins = $conn->prepare("INSERT INTO tickets (ticket_number, subject, status_id, priority_id, created_datetime, updated_datetime, tenant_id, department_id, owner_id, assigned_team_id)
        VALUES (?, ?, (SELECT id FROM ticket_statuses WHERE is_active = 1 ORDER BY is_default DESC, display_order, id LIMIT 1),
                (SELECT id FROM ticket_priorities WHERE is_active = 1 ORDER BY is_default DESC, display_order, id LIMIT 1), UTC_TIMESTAMP(), UTC_TIMESTAMP(), ?, ?, ?, ?)");
    $t = [];
    foreach ([['radiator', $fac, null, null], ['grievance', $hr, null, null], ['ownedbyme', $hr, $inTeam, null],
              ['watchedbyme', $hr, null, null], ['nodepartment', null, null, null], ['teamassigned', $hr, null, $team]] as $i => [$word, $dept, $owner, $tm]) {
        $ins->execute(["ZZDS-$i", "ZZDS $word", $tenant, $dept, $owner, $tm]);
        $t[$word] = $made['tickets'][] = (int)$conn->lastInsertId();
    }
    $conn->prepare("INSERT INTO ticket_watchers (ticket_id, analyst_id, added_by_id) VALUES (?, ?, ?)")->execute([$t['watchedbyme'], $inTeam, $inTeam]);
    // Ticket search reads the first email, so two of them get one.
    $em = $conn->prepare("INSERT INTO emails (ticket_id, subject, from_address, direction, is_initial, received_datetime) VALUES (?, ?, 'zzds@example.invalid', 'Inbound', 1, UTC_TIMESTAMP())");
    foreach (['radiator', 'grievance'] as $w) $em->execute([$t[$w], "ZZDS $w"]);

    $a = $forge($inTeam, 'zzdsfac');
    echo "An analyst in the Facilities team\n";
    $list = $http($a, 'api/tickets/get_emails.php');
    check('All tickets: their department, no department, owned, watched and team-assigned tickets are listed',
        $has($list, 'ZZDS radiator') && $has($list, 'ZZDS nodepartment') && $has($list, 'ZZDS ownedbyme') && $has($list, 'ZZDS watchedbyme') && $has($list, 'ZZDS teamassigned'), substr($list, 0, 200));
    check('All tickets: another department\'s ticket is not', !$has($list, 'ZZDS grievance'));
    check('Asking for the other department directly returns nothing of it', !$has($http($a, 'api/tickets/get_emails.php?department_id=' . $hr), 'ZZDS grievance'));
    $ls = $http($a, 'api/tickets/get_emails.php?search=ZZDS');
    check('Searching the list does not find it (and does find their own)', !$has($ls, 'ZZDS grievance') && $has($ls, 'ZZDS radiator'), substr($ls, 0, 160));
    $detail = $http($a, 'api/tickets/get_email_detail.php?ticket_id=' . $t['grievance']);
    check('Opening it by id is refused', !$has($detail, 'ZZDS grievance'), substr($detail, 0, 160));
    check('...while their own department\'s ticket opens', $has($http($a, 'api/tickets/get_email_detail.php?ticket_id=' . $t['radiator']), 'ZZDS radiator'));
    check('Its thread is refused', !$has($http($a, 'api/tickets/get_ticket_thread.php?ticket_id=' . $t['grievance']), '"success":true'));
    $gs = $http($a, 'api/system/global_search.php?q=' . rawurlencode('ZZDS'));
    check('Global search does not find it (and does find their own)', !$has($gs, 'ZZDS grievance') && $has($gs, 'ZZDS radiator'), substr($gs, 0, 160));
    $ts = $http($a, 'api/tickets/search_tickets.php', ['subject' => 'ZZDS']);
    check('Ticket search does not find it (and does find their own)', !$has($ts, 'ZZDS grievance') && $has($ts, 'ZZDS radiator'), substr($ts, 0, 160));

    echo "An analyst in no team, and an administrator\n";
    check('An analyst in no team still sees every department', $has($http($forge($noTeam, 'zzdsfree'), 'api/tickets/get_emails.php'), 'ZZDS grievance'));
    check('An administrator sees every department', $has($http($forge($admin, 'zzdsadmin'), 'api/tickets/get_emails.php'), 'ZZDS grievance'));

    echo "The REST API, as the key's analyst\n";
    $key = 'fitsm_' . bin2hex(random_bytes(24));
    $conn->prepare('INSERT INTO api_keys (name, key_prefix, key_hash, analyst_id, permissions, company_ids, created_datetime) VALUES (?, ?, ?, ?, ?, NULL, UTC_TIMESTAMP())')
         ->execute(['ZZDS key', substr($key, 0, 12), hash('sha256', $key), $inTeam, json_encode(['tickets' => ['read']])]);
    $made['key'] = (int)$conn->lastInsertId();
    $api = function (string $path, string $query = '') use ($base, $key) {
        $ch = curl_init("$base/api/v1/index.php?path=" . rawurlencode($path) . ($query !== '' ? "&$query" : ''));
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $key]]);
        return [(int)curl_getinfo($ch, CURLINFO_HTTP_CODE) ?: 0, (string)curl_exec($ch), $ch];
    };
    [, $body, $ch] = $api('/tickets/' . $t['grievance']);
    check('GET /tickets/{id} of another department is 404', curl_getinfo($ch, CURLINFO_HTTP_CODE) === 404, substr($body, 0, 120));
    [, $body] = $api('/tickets', 'per_page=100&search=ZZDS');
    check('GET /tickets leaves it out', !$has($body, 'ZZDS grievance') && $has($body, 'ZZDS radiator'), substr($body, 0, 160));
} catch (Throwable $e) {
    check('no exception', false, $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    if ($made['tickets']) { $in = implode(',', $made['tickets']); $conn->exec("DELETE FROM emails WHERE ticket_id IN ($in)"); $conn->exec("DELETE FROM ticket_watchers WHERE ticket_id IN ($in)"); $conn->exec("DELETE FROM tickets WHERE id IN ($in)"); }
    if ($made['key']) $conn->exec("DELETE FROM api_keys WHERE id = " . $made['key']);
    if ($made['analysts']) { $in = implode(',', $made['analysts']); $conn->exec("DELETE FROM analyst_teams WHERE analyst_id IN ($in)"); $conn->exec("DELETE FROM user_preferences WHERE analyst_id IN ($in)"); $conn->exec("DELETE FROM analysts WHERE id IN ($in)"); }
    if ($made['team']) { $conn->exec("DELETE FROM department_teams WHERE team_id = " . $made['team']); $conn->exec("DELETE FROM teams WHERE id = " . $made['team']); }
    $conn->exec("DELETE FROM departments WHERE name LIKE 'ZZDS %'");
    foreach ($sess as $f) @unlink($f);
    $left = (int)$conn->query("SELECT (SELECT COUNT(*) FROM tickets WHERE ticket_number LIKE 'ZZDS-%') + (SELECT COUNT(*) FROM analysts WHERE username LIKE 'zzds%') + (SELECT COUNT(*) FROM departments WHERE name LIKE 'ZZDS %')")->fetchColumn();
    check('cleanup: nothing named ZZDS is left', $left === 0, "$left left");
}
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
