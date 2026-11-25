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

    echo "\n$pass passed, $fail failed\n";
} finally {
    foreach ($made['tickets'] as $id) {
        try { $conn->prepare("DELETE FROM ticket_participants WHERE ticket_id = ?")->execute([$id]); } catch (Throwable) {}
        $conn->prepare("DELETE FROM ticket_audit WHERE ticket_id = ?")->execute([$id]);
        $conn->prepare("DELETE FROM tickets WHERE id = ?")->execute([$id]);
    }
    foreach ($made['users'] as $id) {
        try { $conn->prepare("DELETE FROM ticket_participants WHERE user_id = ?")->execute([$id]); } catch (Throwable) {}
        $conn->prepare("DELETE FROM user_email_addresses WHERE user_id = ?")->execute([$id]);
        $conn->prepare("DELETE FROM users WHERE id = ?")->execute([$id]);
    }
    foreach ($made['mailboxes'] as $id) $conn->prepare("DELETE FROM target_mailboxes WHERE id = ?")->execute([$id]);
}
exit($fail ? 1 : 0);
