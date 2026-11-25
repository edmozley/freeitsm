<?php
/* 🔴 NEVER OVER THE WEB - it writes to the database. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * Ticket participants (GitHub #432): end users copied on a ticket's emails.
 *
 *   FREEITSM_TEST_DATABASE=<throwaway db> FREEITSM_TEST_URL=http://localhost/<that install> php tests/ticket-participants.php
 *
 * Runs Database Verify first. Makes ZZ people (requester, participants, one who has
 * left), ZZ tickets (normal, confidential, trashed), a ZZ mailbox, ZZ analysts and
 * keys; all removed in `finally`, with every setting changed put back. Nothing is
 * emailed: automatic emails are checked through the function that builds their Cc.
 */

chdir(__DIR__ . '/..');
$pass = 0; $fail = 0;
function check(string $what, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    echo ($ok ? '  [PASS] ' : '  [FAIL] ') . $what . (!$ok && $detail !== '' ? " - $detail" : '') . "\n";
    $ok ? $pass++ : $fail++;
}
/** The ServiceError code a call throws, or 'ok'. */
function errCode(callable $fn): string
{
    try { $fn(); return 'ok'; }
    catch (ServiceError $e) { return $e->errorCode; }
}

$expected = getenv('FREEITSM_TEST_DATABASE');
$base = rtrim((string)getenv('FREEITSM_TEST_URL'), '/');
if (!$expected) { echo "(skipped: set FREEITSM_TEST_DATABASE)\n"; exit(0); }
require_once 'config.php';
require_once 'includes/functions.php';
require_once 'includes/tenancy.php';
require_once 'includes/services/ticket_participants.php';
$conn = connectToDatabase();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
if ($conn->query('SELECT DATABASE()')->fetchColumn() !== $expected) { echo "(skipped: config.php does not use $expected)\n"; exit(0); }
exec(escapeshellarg(PHP_BINARY) . ' scripts/db_verify_cli.php --apply 2>&1', $o, $code);
if ($code !== 0) { check('Database Verify ran', false); exit(1); }

$tag = 'zz432' . bin2hex(random_bytes(3));
$made = ['users' => [], 'tickets' => [], 'mailboxes' => [], 'analysts' => [], 'keys' => [], 'sessions' => [], 'settings' => []];
$mail = fn(string $who) => "$tag-$who@example.test";

/** A ZZ person: main address, optional linked address, active or left. */
$person = function (string $who, ?string $linked = null, int $active = 1) use ($conn, $mail, &$made): int {
    $conn->prepare("INSERT INTO users (email, display_name, is_active, created_at) VALUES (?, ?, ?, UTC_TIMESTAMP())")
         ->execute([$mail($who), "ZZ $who", $active]);
    $id = $made['users'][] = (int)$conn->lastInsertId();
    if ($linked !== null) {
        $conn->prepare("INSERT INTO user_email_addresses (user_id, email, added_via) VALUES (?, ?, 'analyst')")->execute([$id, $mail($linked)]);
    }
    return $id;
};
/** A ZZ ticket for a requester. */
$ticket = function (string $suffix, ?int $requester, string $sensitivity = 'normal', bool $trashed = false) use ($conn, $tag, &$made): int {
    $conn->prepare("INSERT INTO tickets (ticket_number, subject, user_id, sensitivity, deleted_datetime, created_datetime, updated_datetime)
                    VALUES (?, 'ZZ432 participants', ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())")
         ->execute([strtoupper("$tag-$suffix"), $requester, $sensitivity, $trashed ? gmdate('Y-m-d H:i:s') : null]);
    return $made['tickets'][] = (int)$conn->lastInsertId();
};
$audits = function (int $ticketId) use ($conn): array {
    $s = $conn->prepare("SELECT old_value, new_value FROM ticket_audit WHERE ticket_id = ? AND field_name = 'Participants' ORDER BY id");
    $s->execute([$ticketId]);
    return $s->fetchAll(PDO::FETCH_ASSOC);
};
$rows = function (int $ticketId) use ($conn): array {
    $s = $conn->prepare("SELECT user_id FROM ticket_participants WHERE ticket_id = ? ORDER BY user_id");
    $s->execute([$ticketId]);
    return array_map('intval', $s->fetchAll(PDO::FETCH_COLUMN));
};

try {
    $actorId = (int)$conn->query("SELECT id FROM analysts WHERE is_active = 1 ORDER BY id LIMIT 1")->fetchColumn();
    $ctx = new ActorContext($actorId, null, 'ui', 'en', 'ZZ actor');

    $req    = $person('req', 'req-alt');
    $linked = $person('main', 'alt');
    $p2     = $person('p2');
    $left   = $person('left', null, 0);
    $t      = $ticket('a', $req);
    $tConf  = $ticket('conf', $req, 'confidential');
    $tTrash = $ticket('trash', $req, 'normal', true);
    $tNoReq = $ticket('noreq', null);
    $conn->prepare("INSERT INTO target_mailboxes (name, target_mailbox, azure_tenant_id, azure_client_id, azure_client_secret, oauth_redirect_uri, imap_server, is_active)
                    VALUES (?, ?, '', '', '', '', '', 0)")->execute([$tag, $mail('mbox')]);
    $made['mailboxes'][] = (int)$conn->lastInsertId();

    echo "The service\n";
    check('the table is there after Database Verify', TicketParticipants::ready($conn));
    $r = TicketParticipants::add($conn, $ctx, $t, $p2, null, true);
    check('add by id', $r['added'] === true && $r['user_id'] === $p2, json_encode($r));
    $list = TicketParticipants::list($conn, $ctx, $t);
    check('...listed, with name and address', count($list) === 1 && $list[0]['user_id'] === $p2 && $list[0]['email'] === $mail('p2') && $list[0]['name'] === 'ZZ p2', json_encode($list));
    check('...source analyst, added by the actor', $list[0]['source'] === 'analyst' && $list[0]['added_by'] === $actorId);
    $a = $audits($t);
    check('...one history row', count($a) === 1 && $a[0]['old_value'] === '' && str_contains($a[0]['new_value'], $mail('p2')) && str_ends_with($a[0]['new_value'], 'added'), json_encode($a));
    $r = TicketParticipants::add($conn, $ctx, $t, $p2, null, true);
    check('adding again: added false, no second history row', $r['added'] === false && count($audits($t)) === 1);

    $r = TicketParticipants::add($conn, $ctx, $t, null, '  ' . strtoupper($mail('alt')) . ' ', true);
    check('add by a linked address, any case and spaces, finds the person', $r['user_id'] === $linked, json_encode($r));
    $cc = TicketParticipants::ccAddresses($conn, $t);
    sort($cc);
    $want = [$mail('main'), $mail('p2')]; sort($want);
    check('Cc: each participant once, by their main address', $cc === $want, json_encode($cc));

    $r = TicketParticipants::add($conn, $ctx, $t, null, $mail('new'), true);
    $s = $conn->prepare("SELECT id FROM users WHERE email = ?"); $s->execute([$mail('new')]);
    $newId = (int)$s->fetchColumn();
    if ($newId) $made['users'][] = $newId;
    check('add by an unknown address creates the person', $r['added'] === true && $newId > 0 && $r['user_id'] === $newId);
    check('a bad address is refused', errCode(fn() => TicketParticipants::add($conn, $ctx, $t, null, 'nope', true)) === 'invalid_email');
    check('neither an id nor an address is refused', errCode(fn() => TicketParticipants::add($conn, $ctx, $t, null, null, true)) === 'invalid_field');

    echo "\nWho cannot be added\n";
    check('the requester', errCode(fn() => TicketParticipants::add($conn, $ctx, $t, $req, null, true)) === 'is_requester');
    check('...also by their linked address', errCode(fn() => TicketParticipants::add($conn, $ctx, $t, null, $mail('req-alt'), true)) === 'is_requester');
    check('someone who has left', errCode(fn() => TicketParticipants::add($conn, $ctx, $t, $left, null, true)) === 'inactive');
    check("one of the install's own mailbox addresses", errCode(fn() => TicketParticipants::add($conn, $ctx, $t, null, ' ' . strtoupper($mail('mbox')), true)) === 'mailbox_address');
    check('anyone, on a trashed ticket', errCode(fn() => TicketParticipants::add($conn, $ctx, $tTrash, $p2, null, true)) === 'trashed');
    check('anyone, on a confidential ticket', errCode(fn() => TicketParticipants::add($conn, $ctx, $tConf, $p2, null, true)) === 'confidential');
    check('anyone, without write access', errCode(fn() => TicketParticipants::add($conn, $ctx, $t, $p2, null, false)) === 'read_only');
    check('a person who does not exist', errCode(fn() => TicketParticipants::add($conn, $ctx, $t, 999999999, null, true)) === 'not_found');
    check('on a ticket that does not exist', errCode(fn() => TicketParticipants::add($conn, $ctx, 999999999, $p2, null, true)) === 'not_found');
    check('an actor with no id', errCode(fn() => TicketParticipants::add($conn, new ActorContext(0, null, 'ui', 'en', ''), $t, $p2, null, true)) === 'not_found');
    check('adding to a ticket with no requester works', TicketParticipants::add($conn, $ctx, $tNoReq, $p2, null, true)['added'] === true);
    check('...and its Cc has no empty entry', TicketParticipants::ccAddresses($conn, $tNoReq) === [$mail('p2')], json_encode(TicketParticipants::ccAddresses($conn, $tNoReq)));
    check('other_company is false for a person in the same company', TicketParticipants::add($conn, $ctx, $t, $p2, null, true)['other_company'] === false);

    echo "\nPeople who left, confidential, removing\n";
    $conn->prepare("INSERT INTO ticket_participants (ticket_id, user_id, added_by_id, source, added_datetime) VALUES (?, ?, NULL, 'analyst', UTC_TIMESTAMP())")->execute([$t, $left]);
    $byId = array_column(TicketParticipants::list($conn, $ctx, $t), null, 'user_id');
    check('someone who has left stays on the list, shown as left', isset($byId[$left]) && $byId[$left]['is_active'] === false);
    check('...but is not copied', !in_array($mail('left'), TicketParticipants::ccAddresses($conn, $t), true));
    $conn->prepare("INSERT INTO ticket_participants (ticket_id, user_id, added_by_id, source, added_datetime) VALUES (?, ?, NULL, 'analyst', UTC_TIMESTAMP())")->execute([$tConf, $p2]);
    check('a confidential ticket copies nobody, even with a leftover row', TicketParticipants::ccAddresses($conn, $tConf) === []);
    $r = TicketParticipants::remove($conn, $ctx, $tConf, $p2, true);
    check('...and the leftover can be removed', $r['removed'] === true && $rows($tConf) === []);
    check('removing needs write access', errCode(fn() => TicketParticipants::remove($conn, $ctx, $t, $p2, false)) === 'read_only');
    $before = count($audits($t));
    $r = TicketParticipants::remove($conn, $ctx, $t, $p2, true);
    $a = $audits($t);
    check('remove takes them off with a history row', $r['removed'] === true && !in_array($p2, $rows($t), true) && count($a) === $before + 1 && str_contains(end($a)['old_value'], $mail('p2')) && end($a)['new_value'] === 'removed', json_encode(end($a)));
    check('removing someone not there: removed false', TicketParticipants::remove($conn, $ctx, $t, $p2, true)['removed'] === false);

    echo "\nHelpers for the lifecycle\n";
    TicketParticipants::add($conn, $ctx, $t, $p2, null, true);
    $n = count($rows($t));
    $before = count($audits($t));
    $cleared = TicketParticipants::clearForConfidential($conn, [$t], $actorId);
    $a = $audits($t);
    check('clearForConfidential removes every participant, one history row each', $cleared === $n && $rows($t) === [] && count($a) === $before + $n && str_contains(end($a)['new_value'], 'confidential'), json_encode([$cleared, $n, end($a)]));
    TicketParticipants::add($conn, $ctx, $t, $p2, null, true);
    $conn->prepare("UPDATE tickets SET user_id = ? WHERE id = ?")->execute([$p2, $t]);
    TicketParticipants::removeIfRequester($conn, $t, $actorId);
    check('removeIfRequester takes off a participant who became the requester', $rows($t) === []);
    $conn->prepare("UPDATE tickets SET user_id = ? WHERE id = ?")->execute([$req, $t]);
    TicketParticipants::add($conn, $ctx, $t, $p2, null, true);
    TicketParticipants::add($conn, $ctx, $t, $linked, null, true);
    TicketParticipants::add($conn, $ctx, $tNoReq, $linked, null, true);
    $conn->prepare("UPDATE tickets SET user_id = ? WHERE id = ?")->execute([$linked, $tNoReq]);
    TicketParticipants::moveForUserMerge($conn, $p2, $linked);
    check('moveForUserMerge: a duplicate on the same ticket is dropped', $rows($t) === [$linked], json_encode($rows($t)));
    check('...and a row where the survivor is the requester is dropped', $rows($tNoReq) === [], json_encode($rows($tNoReq)));
    TicketParticipants::deleteForTickets($conn, [$t]);
    check('deleteForTickets empties the ticket', $rows($t) === []);

    // ── Task 4: lifecycle ────────────────────────────────────────────────────
    require_once 'includes/services/tickets.php';
    require_once 'includes/ticket_sensitivity.php';
    require_once 'includes/ticket_merge.php';
    require_once 'includes/user_emails.php';
    require_once 'includes/users.php';
    $add = fn(int $ticketId, int $userId) => TicketParticipants::add($conn, $ctx, $ticketId, $userId, null, true);
    $sens = function (int $ticketId) use ($conn): string {
        $s = $conn->prepare("SELECT sensitivity FROM tickets WHERE id = ?"); $s->execute([$ticketId]);
        return (string)$s->fetchColumn();
    };
    $pA = $person('pa'); $pB = $person('pb'); $pC = $person('pc');

    echo "\nBecoming confidential\n";
    $tm = $ticket('manual', $req);
    $add($tm, $pA); $add($tm, $pB);
    $before = count($audits($tm));
    TicketsService::updateTicket($conn, $ctx, $tm, ['sensitivity' => 'confidential'], true);
    $a = $audits($tm);
    check('marked confidential by hand: participants removed', $sens($tm) === 'confidential' && $rows($tm) === [], json_encode($rows($tm)));
    check('...one history row each', count($a) === $before + 2 && str_contains(end($a)['new_value'], 'confidential'), json_encode($a));
    $tn = $ticket('none', $req);
    TicketsService::updateTicket($conn, $ctx, $tn, ['sensitivity' => 'confidential'], true);
    check('a ticket with no participants still becomes confidential, with no Participants rows (negative control)', $sens($tn) === 'confidential' && $audits($tn) === []);

    $conn->prepare("INSERT INTO departments (name, default_sensitivity) VALUES (?, 'confidential')")->execute(["$tag dept"]);
    $deptId = $made['departments'][] = (int)$conn->lastInsertId();
    $td = $ticket('dept', $req);
    $add($td, $pA);
    TicketsService::updateTicket($conn, $ctx, $td, ['department_id' => $deptId], true);
    check('moved to a confidential department: participants removed', $sens($td) === 'confidential' && $rows($td) === [], json_encode([$sens($td), $rows($td)]));
    $tr = $ticket('raise', $req);
    $add($tr, $pA);
    ticketSensitivityRaise($conn, $tr, 'ZZ test rule');
    check('raised by any rule (department default in bulk, the requester, a split): participants removed', $rows($tr) === []);

    echo "\nMerge\n";
    $src = $ticket('src', $req); $tgt = $ticket('tgt', $pC);
    $add($src, $pA); $add($src, $pB);
    $conn->prepare("INSERT INTO ticket_participants (ticket_id, user_id, added_by_id, source, added_datetime) VALUES (?, ?, NULL, 'analyst', UTC_TIMESTAMP())")->execute([$src, $pC]);
    $add($tgt, $pB);
    mergeTickets($conn, $actorId, [$src], $tgt);
    check('merge: the survivor has each participant once, and never its own requester', $rows($tgt) === [$pA, $pB], json_encode($rows($tgt)));
    check('...the merged-away ticket has none', $rows($src) === []);
    $m = $conn->prepare("SELECT id FROM ticket_merges WHERE source_ticket_id = ? ORDER BY id DESC LIMIT 1"); $m->execute([$src]);
    $mergeId = (int)$m->fetchColumn();
    unmergeTicket($conn, $actorId, $mergeId);
    check('Undo merge: the ticket that came back has all its participants again', $rows($src) === [$pA, $pB, $pC], json_encode($rows($src)));
    check('...and the survivor keeps its own', $rows($tgt) === [$pB], json_encode($rows($tgt)));

    $cSrc = $ticket('csrc', $req, 'confidential'); $nTgt = $ticket('ntgt', $pC);
    $add($nTgt, $pA);
    mergeTickets($conn, $actorId, [$cSrc], $nTgt);
    check('a confidential ticket merged into a normal one: the survivor is confidential with no participants', $sens($nTgt) === 'confidential' && $rows($nTgt) === [], json_encode([$sens($nTgt), $rows($nTgt)]));
    $nSrc = $ticket('nsrc', $req); $cTgt = $ticket('ctgt', $pC, 'confidential');
    $add($nSrc, $pA);
    mergeTickets($conn, $actorId, [$nSrc], $cTgt);
    check("a normal ticket merged into a confidential one: its participants do not arrive", $rows($cTgt) === [], json_encode($rows($cTgt)));

    echo "\nRequester, people, delete\n";
    $tq = $ticket('req', $req);
    $add($tq, $pA);
    TicketsService::updateTicket($conn, $ctx, $tq, ['user_id' => $pA], true);
    $a = $audits($tq);
    check('the requester changes to a participant: taken off the list, with a history row', $rows($tq) === [] && str_contains(end($a)['new_value'], 'requester'), json_encode($a));

    $dup = $person('dup'); $keep = $person('keep');
    $tp = $ticket('pm', $req);
    $add($tp, $dup);
    $made['users'] = array_values(array_diff($made['users'], [$dup]));   // the merge deletes them
    $res = userMergeInto($conn, $actorId, $dup, $keep);
    check('a person merge moves their participations to the person kept', $rows($tp) === [$keep], json_encode($rows($tp)));
    check('...and deletes the duplicate rather than switching them off', ($res['outcome'] ?? '') === 'deleted', json_encode($res['outcome'] ?? null));

    $gone = $person('gone');
    $tg = $ticket('gone', $req);
    $add($tg, $gone);
    check('participations do not stop a person being deleted', !userDeleteBlockedByRecords($conn, $gone));
    userDeletePerson($conn, $gone);
    $made['users'] = array_values(array_diff($made['users'], [$gone]));
    check('...and the delete removes them', $rows($tg) === []);

    $tx = $ticket('perm', $req);
    $add($tx, $pA);
    TicketParticipants::deleteForTickets($conn, [$tx]);
    $s = $conn->prepare("SELECT COUNT(*) FROM ticket_participants WHERE ticket_id = ?"); $s->execute([$tx]);
    check('a permanent delete removes the ticket\'s participants', (int)$s->fetchColumn() === 0);
    foreach (['api/tickets/permanently_delete_ticket.php', 'api/tickets/empty_trash.php', 'api/system/debug-tools/D002_delete_ticket.php'] as $f) {
        check("$f removes participants", str_contains((string)file_get_contents($f), 'ticket_participants'));
    }

    // ── Task 5: the browser endpoint and the REST API ────────────────────────
    if ($base === '') {
        echo "\n(skipped the HTTP checks: set FREEITSM_TEST_URL)\n";
    } else {
        echo "\nThe browser endpoint\n";
        $sessDir = rtrim((string)(getenv('FREEITSM_SESS_DIR') ?: sys_get_temp_dir()), '/');
        $sid = $tag . 'sess';
        $actorName = (string)$conn->query("SELECT username FROM analysts WHERE id = $actorId")->fetchColumn();
        file_put_contents("$sessDir/sess_$sid", 'analyst_id|i:' . $actorId . ';analyst_username|s:' . strlen($actorName) . ':"' . $actorName . '";analyst_name|s:2:"ZZ";csrf_token|s:1:"x";');
        $made['sessions'][] = "$sessDir/sess_$sid";
        $http = function (string $method, array $body = [], string $q = '', bool $csrf = true) use ($base, $sid): array {
            $ch = curl_init("$base/api/tickets/participants.php$q");
            $h = ['Content-Type: application/json', 'Cookie: PHPSESSID=' . $sid];
            if ($csrf) $h[] = 'X-CSRF-Token: x';
            $o = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_HTTPHEADER => $h];
            if ($method === 'POST') { $o[CURLOPT_POST] = true; $o[CURLOPT_POSTFIELDS] = json_encode($body); }
            curl_setopt_array($ch, $o);
            $raw = (string)curl_exec($ch);
            return [(int)curl_getinfo($ch, CURLINFO_HTTP_CODE), json_decode($raw, true) ?: ['raw' => substr($raw, 0, 300)]];
        };
        $th = $ticket('http', $req);
        [$c, $j] = $http('POST', ['action' => 'add', 'ticket_id' => $th, 'user_id' => $pA]);
        check("POST add ($c)", $c === 200 && ($j['added'] ?? null) === true && ($j['user_id'] ?? 0) === $pA, json_encode($j));
        [$c, $j] = $http('POST', ['action' => 'add', 'ticket_id' => $th, 'email' => $mail('pb')]);
        check("POST add by address ($c)", $c === 200 && ($j['user_id'] ?? 0) === $pB, json_encode($j));
        [$c, $j] = $http('GET', [], "?ticket_id=$th");
        check("GET lists them, ready, can edit, not confidential ($c)", $c === 200 && ($j['ready'] ?? null) === true && ($j['can_edit'] ?? null) === true
            && ($j['confidential'] ?? null) === false && array_column($j['participants'] ?? [], 'user_id') === [$pA, $pB], json_encode($j));
        [$c, $j] = $http('POST', ['action' => 'remove', 'ticket_id' => $th, 'user_id' => $pA]);
        check("POST remove ($c)", $c === 200 && ($j['removed'] ?? null) === true && $rows($th) === [$pB], json_encode($j));
        [$c, $j] = $http('POST', ['action' => 'add', 'ticket_id' => $th, 'user_id' => $req]);
        check("the requester is refused with a code the page can show ($c)", $c === 400 && ($j['code'] ?? '') === 'is_requester', json_encode($j));
        [$c, $j] = $http('POST', ['action' => 'add', 'ticket_id' => $th, 'user_id' => $pC], '', false);
        check("no CSRF token: refused, nothing added ($c)", $c !== 200 && !in_array($pC, $rows($th), true), json_encode($j));
        [$c, $j] = $http('GET', [], '?ticket_id=999999999');
        check("a ticket that does not exist is 404 ($c)", $c === 404);
        [$c, $j] = $http('GET', [], "?ticket_id=$tConf");
        check("a confidential ticket says so ($c)", $c === 200 && ($j['confidential'] ?? null) === true, json_encode($j));

        echo "\nThe REST API\n";
        $keys = [];
        foreach (['rw' => ['ticket_participants' => ['read', 'create', 'delete'], 'tickets' => ['read']], 'ro' => ['ticket_participants' => ['read'], 'tickets' => ['read']]] as $k => $perm) {
            $keys[$k] = 'fitsm_' . bin2hex(random_bytes(24));
            $conn->prepare('INSERT INTO api_keys (name, key_prefix, key_hash, analyst_id, permissions) VALUES (?, ?, ?, ?, ?)')
                 ->execute([$tag . $k, substr($keys[$k], 0, 12), hash('sha256', $keys[$k]), $actorId, json_encode($perm)]);
            $made['keys'][] = (int)$conn->lastInsertId();
        }
        $api = function (string $method, string $path, string $key, ?array $body = null) use ($base): array {
            $ch = curl_init($base . '/api/v1/index.php?path=' . rawurlencode($path));
            $o = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_CUSTOMREQUEST => $method,
                  CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $key, 'Content-Type: application/json']];
            if ($body !== null) $o[CURLOPT_POSTFIELDS] = json_encode($body);
            curl_setopt_array($ch, $o);
            $raw = (string)curl_exec($ch);
            return [(int)curl_getinfo($ch, CURLINFO_HTTP_CODE), json_decode($raw, true) ?: ['raw' => substr($raw, 0, 300)]];
        };
        $tr2 = $ticket('rest', $req);
        [$c, $j] = $api('POST', "/tickets/$tr2/participants", $keys['rw'], ['email' => $mail('pc')]);
        check("POST by address: 201 ($c)", $c === 201 && ($j['data']['person']['id'] ?? 0) === $pC, json_encode($j));
        $s = $conn->prepare("SELECT source FROM ticket_participants WHERE ticket_id = ? AND user_id = ?"); $s->execute([$tr2, $pC]);
        check('...recorded as added through the API', $s->fetchColumn() === 'api');
        [$c, $j] = $api('POST', "/tickets/$tr2/participants", $keys['rw'], ['user_id' => $pC]);
        check("again: 200 ($c)", $c === 200, json_encode($j));
        [$c, $j] = $api('GET', "/tickets/$tr2/participants", $keys['ro']);
        check("GET with a read-only key ($c)", $c === 200 && count($j['data'] ?? []) === 1 && ($j['data'][0]['person']['email'] ?? '') === $mail('pc'), json_encode($j));
        [$c, $j] = $api('POST', "/tickets/$tr2/participants", $keys['ro'], ['user_id' => $pA]);
        check("a key without ticket_participants.create is refused ($c)", $c === 403);
        [$c, $j] = $api('POST', "/tickets/$tConf/participants", $keys['rw'], ['user_id' => $pA]);
        check("a confidential ticket is refused ($c)", in_array($c, [400, 422], true) && ($j['error']['code'] ?? $j['error'] ?? '') !== '' , json_encode($j));
        [$c, $j] = $api('GET', "/tickets/$tr2", $keys['rw']);
        check("the ticket's related counts include participants ($c)", ($j['data']['related']['counts']['participants'] ?? null) === 1, json_encode($j['data']['related'] ?? $j));
        [$c, $j] = $api('DELETE', "/tickets/$tr2/participants/$pC", $keys['rw']);
        check("DELETE ($c)", $c === 200 && ($j['data']['removed'] ?? null) === true && $rows($tr2) === [], json_encode($j));
        [$c, $j] = $api('DELETE', "/tickets/$tr2/participants/$pC", $keys['rw']);
        check("DELETE someone not there: 404 ($c)", $c === 404);
    }

    echo "\n$pass passed, $fail failed\n";
} finally {
    foreach ($made['tickets'] as $id) {
        try { $conn->prepare("DELETE FROM ticket_participants WHERE ticket_id = ?")->execute([$id]); } catch (Throwable) {}
        try { $conn->prepare("DELETE FROM ticket_merges WHERE source_ticket_id = ? OR target_ticket_id = ?")->execute([$id, $id]); } catch (Throwable) {}
        try { $conn->prepare("DELETE FROM ticket_links WHERE source_ticket_id = ? OR target_ticket_id = ?")->execute([$id, $id]); } catch (Throwable) {}
        try { $conn->prepare("DELETE FROM email_attachments WHERE email_id IN (SELECT id FROM emails WHERE ticket_id = ?)")->execute([$id]); } catch (Throwable) {}
        $conn->prepare("DELETE FROM emails WHERE ticket_id = ?")->execute([$id]);
        $conn->prepare("DELETE FROM ticket_audit WHERE ticket_id = ?")->execute([$id]);
        $conn->prepare("DELETE FROM tickets WHERE id = ?")->execute([$id]);
    }
    foreach ($made['users'] as $id) {
        try { $conn->prepare("DELETE FROM ticket_participants WHERE user_id = ?")->execute([$id]); } catch (Throwable) {}
        $conn->prepare("DELETE FROM user_email_addresses WHERE user_id = ?")->execute([$id]);
        try { $conn->prepare("DELETE FROM user_audit WHERE user_id = ?")->execute([$id]); } catch (Throwable) {}
        $conn->prepare("DELETE FROM users WHERE id = ?")->execute([$id]);
    }
    foreach ($made['mailboxes'] as $id) $conn->prepare("DELETE FROM target_mailboxes WHERE id = ?")->execute([$id]);
    foreach ($made['departments'] ?? [] as $id) $conn->prepare("DELETE FROM departments WHERE id = ?")->execute([$id]);
    foreach ($made['keys'] as $id) { try { $conn->exec("DELETE FROM api_key_rate_limits WHERE api_key_id = $id"); } catch (Throwable) {} $conn->exec("DELETE FROM api_keys WHERE id = $id"); }
    foreach ($made['sessions'] as $f) @unlink($f);
}
exit($fail ? 1 : 0);
