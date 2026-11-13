<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * Linked email addresses, merging a duplicate person, changing a ticket's requester (GitHub #308).
 *
 *   FREEITSM_TEST_DATABASE=<throwaway db> FREEITSM_BASE_URL=http://localhost/<that install> php tests/linked-emails.php
 *
 * ZZ people (a main person, a duplicate made from a private address, one who signs in, one in a
 * ZZ second company), their tickets, and the endpoints over real HTTP as an analyst and as a portal
 * user. TRAP: the second company is added before anything asks tenancy a question (cached per
 * process). Everything ZZ is removed in finally.
 *
 * @wiki Linked-Email-Addresses-Developer-Guide
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/service_context.php';
require_once __DIR__ . '/../includes/services/tickets.php';
require_once __DIR__ . '/../includes/user_emails.php';

$pass = 0; $fail = 0;
function ok(string $what, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  PASS  {$what}\n"; }
    else       { $fail++; echo "  FAIL  {$what}" . ($detail !== '' ? "  <- {$detail}" : '') . "\n"; }
}
$conn = connectToDatabase();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$expected = getenv('FREEITSM_TEST_DATABASE');
if (!$expected || $conn->query('SELECT DATABASE()')->fetchColumn() !== $expected) exit("Set FREEITSM_TEST_DATABASE to the throwaway database config.php points at.\n");
$base = rtrim((string)getenv('FREEITSM_BASE_URL'), '/');
if (!$base || str_contains($base, 'freeitsm-app')) exit("Set FREEITSM_BASE_URL to the throwaway install too.\n");
if (!userEmailsReady($conn)) exit("Run Database Verification first.\n");

$tag = 'zz308' . bin2hex(random_bytes(3));
$made = ['users' => [], 'tickets' => [], 'tenant' => 0, 'assets_link' => 0];
$sessDir = rtrim(ini_get('session.save_path') ?: 'c:/wamp64/tmp', "/\\");
$sessions = [];
try {
    // FIRST: a second company, before tenancy caches "single company".
    $conn->prepare("INSERT INTO tenants (name, is_default, is_active) VALUES (?, 0, 1)")->execute(["ZZ $tag Globex"]);
    $tB = $made['tenant'] = (int)$conn->lastInsertId();
    $tA = getDefaultTenantId($conn);
    $admin = (int)$conn->query("SELECT id FROM analysts WHERE is_active = 1 AND is_admin = 1 ORDER BY id LIMIT 1")->fetchColumn();
    $ctx = new ActorContext(actorId: $admin, companyScope: null, source: 'ui', actorName: 'test');
    $_SESSION = ['analyst_id' => $admin];
    $mkUser = function (string $email, string $name, ?int $tenant, array $extra = []) use ($conn, &$made): int {
        $cols = array_merge(['email' => $email, 'display_name' => $name, 'tenant_id' => $tenant], $extra);
        $conn->prepare("INSERT INTO users (" . implode(', ', array_keys($cols)) . ") VALUES (" . implode(', ', array_fill(0, count($cols), '?')) . ")")->execute(array_values($cols));
        return $made['users'][] = (int)$conn->lastInsertId();
    };
    $mkTicket = function (int $userId, int $tenant, string $subj) use ($conn, $ctx, &$made, $tag): int {
        return $made['tickets'][] = TicketsService::createTicket($conn, $ctx, $tenant, ['subject' => "ZZ $tag $subj", 'user_id' => $userId], null, 'ZZ test');
    };
    $jane = $mkUser("jane@$tag.example", "$tag Jane", $tA);
    $priv = "jane.private@$tag-mail.example";

    echo "Linking\n";
    ok('a bad address is refused', userLinkEmail($conn, $admin, $jane, 'not an address', 'analyst')['reason'] === 'invalid');
    ok('her own main address is refused', userLinkEmail($conn, $admin, $jane, strtoupper("jane@$tag.example"), 'analyst')['reason'] === 'own_main');
    ok('a private address links', userLinkEmail($conn, $admin, $jane, " Jane.Private@$tag-mail.example ", 'analyst')['ok'] === true);
    ok('...stored lower case, once', userLinkedEmails($conn, $jane)[0]['email'] ?? '' === $priv && count(userLinkedEmails($conn, $jane)) === 1);
    ok('...and in her history', (bool)array_filter(userHistory($conn, $jane), fn($h) => $h['field_name'] === 'email_linked' && $h['new_value'] === $priv));
    $bob = $mkUser("bob@$tag.example", "$tag Bob", $tA);
    $r = userLinkEmail($conn, $admin, $bob, $priv, 'analyst');
    ok('one owner: linked to Jane, it cannot be linked to Bob', $r['reason'] === 'linked_elsewhere' && $r['other_user_id'] === $jane, json_encode($r));
    ok('another person\'s MAIN address cannot be linked - it needs a merge', userLinkEmail($conn, $admin, $jane, "bob@$tag.example", 'analyst')['reason'] === 'main_elsewhere');

    echo "Mail from a linked address is hers\n";
    ok('userIdForEmail finds her by the linked address, any case', userIdForEmail($conn, strtoupper($priv)) === $jane);
    $t1 = $made['tickets'][] = TicketsService::createTicket($conn, $ctx, $tA, ['subject' => "ZZ $tag from private", 'requester_email' => $priv, 'requester_name' => 'Jane P'], null, 'ZZ test');
    $owner = (int)$conn->query("SELECT user_id FROM tickets WHERE id = $t1")->fetchColumn();
    ok('a new ticket from the private address is hers - no second person made', $owner === $jane && (int)$conn->query("SELECT COUNT(*) FROM users WHERE email = " . $conn->quote($priv))->fetchColumn() === 0);
    $conn->prepare("UPDATE users SET tenant_id = ? WHERE id = ?")->execute([$tB, $jane]);
    ok('a linked address reaches her company (shared mailbox, freemail-like domain)', resolveTicketTenantForEmail($conn, null, $priv) === $tB);
    $conn->prepare("UPDATE users SET tenant_id = ? WHERE id = ?")->execute([$tA, $jane]);

    echo "Merging a duplicate\n";
    $dup = $mkUser("jane.old@$tag-mail.example", "Jane (old address)", $tA);
    $d1 = $mkTicket($dup, $tA, 'dup one'); $d2 = $mkTicket($dup, $tA, 'dup two');
    $p = userMergePreview($conn, $admin, $dup, $jane);
    ok('preview: can merge, 2 tickets, nothing else refers to them -> deleted', $p['can'] && $p['tickets'] === 2 && $p['delete'] === true, json_encode([$p['can'], $p['reason'], $p['tickets'], $p['references']]));
    $m = userMergeInto($conn, $admin, $dup, $jane);
    ok('merged: both tickets are hers, the duplicate is gone', $m['moved'] === 2 && $m['outcome'] === 'deleted'
       && (int)$conn->query("SELECT COUNT(*) FROM tickets WHERE id IN ($d1, $d2) AND user_id = $jane")->fetchColumn() === 2
       && !$conn->query("SELECT 1 FROM users WHERE id = $dup")->fetchColumn());
    ok('...each ticket\'s history says the requester changed', (int)$conn->query("SELECT COUNT(*) FROM ticket_audit WHERE ticket_id IN ($d1, $d2) AND field_name = 'Requester'")->fetchColumn() === 2);
    ok('...and the old address is now one of hers', userIdForEmail($conn, "jane.old@$tag-mail.example") === $jane);
    $dup2 = $mkUser("jane.third@$tag-mail.example", "Jane third", $tA);
    $conn->prepare("UPDATE users SET manager_id = ? WHERE id = ?")->execute([$dup2, $bob]);   // something still refers to dup2
    $m2 = userMergeInto($conn, $admin, $dup2, $jane);
    $row = $conn->query("SELECT email, is_active FROM users WHERE id = $dup2")->fetch(PDO::FETCH_ASSOC);
    ok('a duplicate something still refers to is switched off, with no address (one owner)', $m2['outcome'] === 'switched_off' && $row['email'] === null && (int)$row['is_active'] === 0 && userIdForEmail($conn, "jane.third@$tag-mail.example") === $jane, json_encode($row));
    $signsIn = $mkUser("signs.in@$tag-mail.example", "Signs in", $tA, ['password_hash' => password_hash('x', PASSWORD_DEFAULT)]);
    ok('someone who signs in is never merged', userMergePreview($conn, $admin, $signsIn, $jane)['reason'] === 'has_login');
    $other = $mkUser("other@$tag-b.example", "Other company", $tB);
    ok('people of different companies are not merged', userMergePreview($conn, $admin, $other, $jane)['reason'] === 'other_company');

    echo "Changing a ticket's requester\n";
    $t2 = $mkTicket($bob, $tA, 'bob logged it');
    TicketsService::updateTicket($conn, $ctx, $t2, ['user_id' => $jane], false);
    ok('the requester changes - written to the history even with audit off', (int)$conn->query("SELECT user_id FROM tickets WHERE id = $t2")->fetchColumn() === $jane
       && (int)$conn->query("SELECT COUNT(*) FROM ticket_audit WHERE ticket_id = $t2 AND field_name = 'Requester'")->fetchColumn() === 1);
    $refused = '';
    try { TicketsService::updateTicket($conn, $ctx, $t2, ['user_id' => $other], true); } catch (ServiceError $e) { $refused = $e->getMessage(); }
    ok('a person of another company is refused', str_contains($refused, 'another company'), $refused);

    echo "The directory wins\n";
    userLinkEmail($conn, $admin, $bob, "shared@$tag.example", 'analyst');
    userReleaseEmailForMain($conn, "shared@$tag.example", 'directory');
    ok('a directory taking the address removes the link and says so in Bob\'s history', userIdForEmail($conn, "shared@$tag.example") === null
       && (bool)array_filter(userHistory($conn, $bob), fn($h) => $h['field_name'] === 'email_released'));

    echo "Over HTTP\n";
    $csrf = bin2hex(random_bytes(32));
    $sess = function (string $data) use ($sessDir, &$sessions, $csrf): string {
        $sid = 'zz308' . bin2hex(random_bytes(5));
        file_put_contents("$sessDir/sess_$sid", $data . 'csrf_token|s:64:"' . $csrf . '";');
        return $sessions[] = $sid;
    };
    $http = function (string $sid, string $path, ?array $post = null) use ($base, $csrf): array {
        $ch = curl_init("$base/$path");
        $o = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_COOKIE => "PHPSESSID=$sid"];
        if ($post !== null) $o += [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($post), CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-CSRF-Token: ' . $csrf]];
        curl_setopt_array($ch, $o);
        $raw = (string)curl_exec($ch);
        return json_decode($raw, true) ?: ['raw' => substr(strip_tags($raw), 0, 200)];
    };
    $an = $sess('analyst_id|i:' . $admin . ';analyst_name|s:5:"zztst";is_admin|i:1;');
    $r = $http($an, "api/tickets/get_users.php?limit=8&search=" . rawurlencode("jane.private@$tag"));
    ok('the requester search finds her by a linked address', in_array($jane, array_map('intval', array_column($r['users'] ?? [], 'id')), true), json_encode($r));
    $r = $http($an, "api/people/emails.php?user_id=$jane");
    ok('people/emails.php lists her main and linked addresses and history', ($r['main'] ?? '') === "jane@$tag.example" && count($r['linked'] ?? []) === 3 && count($r['history'] ?? []) >= 3 && ($r['can_edit'] ?? false), json_encode(array_keys($r)));
    $dup3 = $mkUser("jane.fourth@$tag-mail.example", "Jane fourth", $tA);
    $mkTicket($dup3, $tA, 'dup three');
    $r = $http($an, 'api/people/emails.php', ['action' => 'link', 'user_id' => $jane, 'email' => "jane.fourth@$tag-mail.example"]);
    ok('linking another person\'s main address answers needs_merge with the preview', !empty($r['needs_merge']['can']) && ($r['needs_merge']['tickets'] ?? 0) === 1, json_encode($r));
    $r = $http($an, 'api/people/emails.php', ['action' => 'merge', 'user_id' => $jane, 'from_user_id' => $dup3]);
    ok('...and merge does it', !empty($r['success']) && ($r['moved'] ?? 0) === 1, json_encode($r));
    $dup4 = $mkUser("jane.fifth@$tag-mail.example", "Jane fifth", $tA);
    $t5 = $mkTicket($dup4, $tA, 'from fifth'); $mkTicket($dup4, $tA, 'fifth again');
    $r = $http($an, "api/tickets/change_requester.php?ticket_id=$t5&user_id=$jane");
    ok('change_requester GET: the current requester, and the merge on offer', ($r['current']['id'] ?? 0) === $dup4 && !empty($r['merge']['can']) && ($r['merge']['tickets'] ?? 0) === 2, json_encode($r));
    $r = $http($an, 'api/tickets/change_requester.php', ['ticket_id' => $t5, 'user_id' => $jane, 'merge_old' => true]);
    ok('...POST with merge: this ticket and the other move, the duplicate goes', !empty($r['success']) && ($r['merged']['moved'] ?? 0) === 1
       && (int)$conn->query("SELECT COUNT(*) FROM tickets WHERE user_id = $dup4")->fetchColumn() === 0, json_encode($r));

    echo "The portal\n";
    $pt = $sess('ss_user_id|i:' . $jane . ';ss_user_email|s:' . strlen("jane@$tag.example") . ':"' . "jane@$tag.example" . '";');
    $r = $http($pt, 'api/self-service/addresses.php');
    ok('the portal lists her addresses (session only - no id parameter)', ($r['main'] ?? '') === "jane@$tag.example" && count($r['linked'] ?? []) >= 3, json_encode($r));
    $r = $http($pt, 'api/self-service/addresses.php', ['action' => 'add', 'email' => "jane.private@$tag-mail.example"]);
    ok('adding one of her own says so', ($r['error'] ?? '') === 'addr_err_already_yours', json_encode($r));
    $r = $http($pt, 'api/self-service/addresses.php', ['action' => 'add', 'email' => "bob@$tag.example"]);
    $pend = $http($pt, 'api/self-service/addresses.php')['pending'] ?? [];
    ok('someone else\'s address: the same answer as a free one, and listed as waiting - nothing given away', !empty($r['success']) && !empty($r['pending']) && in_array("bob@$tag.example", array_column($pend, 'email'), true), json_encode($r));
    // Confirming: a claim with a known token (the email itself cannot be read here).
    $raw = bin2hex(random_bytes(32));
    $conn->prepare("INSERT INTO user_email_claims (user_id, email, token_hash, expires_at) VALUES (?, ?, ?, UTC_TIMESTAMP() + INTERVAL 1 HOUR)")->execute([$jane, "jane.new@$tag-mail.example", hash('sha256', $raw)]);
    $page = (string)file_get_contents("$base/self-service/confirm-address.php?token=$raw");
    ok('opening the link confirms it: linked, in her history as "portal"', str_contains($page, 'Address confirmed') && userIdForEmail($conn, "jane.new@$tag-mail.example") === $jane);
    $page2 = (string)file_get_contents("$base/self-service/confirm-address.php?token=$raw");
    ok('the link works once', str_contains($page2, 'no longer valid'));
    $raw2 = bin2hex(random_bytes(32));
    $conn->prepare("INSERT INTO user_email_claims (user_id, email, token_hash, expires_at) VALUES (?, ?, ?, UTC_TIMESTAMP() + INTERVAL 1 HOUR)")->execute([$jane, "bob@$tag.example", hash('sha256', $raw2)]);
    $page3 = (string)file_get_contents("$base/self-service/confirm-address.php?token=$raw2");
    ok('a claim on someone else\'s address is never linked', str_contains($page3, 'could not be added') && userIdForEmail($conn, "bob@$tag.example") === $bob);
    $raw3 = bin2hex(random_bytes(32));
    $conn->prepare("INSERT INTO user_email_claims (user_id, email, token_hash, expires_at) VALUES (?, ?, ?, UTC_TIMESTAMP() - INTERVAL 1 MINUTE)")->execute([$jane, "late@$tag-mail.example", hash('sha256', $raw3)]);
    ok('an expired link does nothing', str_contains((string)file_get_contents("$base/self-service/confirm-address.php?token=$raw3"), 'no longer valid') && userIdForEmail($conn, "late@$tag-mail.example") === null);
    $r = $http($pt, 'api/self-service/addresses.php', ['action' => 'remove', 'email' => "jane.new@$tag-mail.example"]);
    ok('she can remove a linked address', !empty($r['success']) && userIdForEmail($conn, "jane.new@$tag-mail.example") === null);
    $r = $http($an, 'api/tickets/save_user.php', ['id' => $bob, 'email' => $priv, 'display_name' => "$tag Bob"]);
    ok('an analyst cannot make a linked address another person\'s main one', empty($r['success']) && str_contains((string)($r['error'] ?? ''), 'linked to another person'), json_encode($r));
} catch (Throwable $e) {
    ok('no exception', false, get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    foreach ($sessions as $sid) @unlink("$sessDir/sess_$sid");
    $ids = array_values(array_filter(array_map('intval', $made['tickets'])));
    $ids = array_merge($ids, $conn->query("SELECT id FROM tickets WHERE subject LIKE " . $conn->quote("ZZ $tag%"))->fetchAll(PDO::FETCH_COLUMN));
    if ($ids) {
        $in = implode(',', array_unique(array_map('intval', $ids)));
        $conn->exec("DELETE FROM email_attachments WHERE email_id IN (SELECT id FROM emails WHERE ticket_id IN ($in))");
        foreach ($conn->query("SELECT TABLE_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME = 'ticket_id' AND TABLE_NAME <> 'tickets'")->fetchAll(PDO::FETCH_COLUMN) as $tbl) {
            try { $conn->exec("DELETE FROM `$tbl` WHERE ticket_id IN ($in)"); } catch (Throwable $x) {}
        }
        $conn->exec("DELETE FROM tickets WHERE id IN ($in)");
    }
    $users = $made['users'];
    if ($users) {
        $in = implode(',', array_map('intval', $users));
        $conn->exec("UPDATE users SET manager_id = NULL WHERE manager_id IN ($in)");
        $conn->exec("DELETE FROM user_email_claims WHERE user_id IN ($in)");
        $conn->exec("DELETE FROM user_email_addresses WHERE user_id IN ($in)");
        $conn->exec("DELETE FROM user_audit WHERE user_id IN ($in)");
        $conn->exec("DELETE FROM users WHERE id IN ($in)");
    }
    if ($made['tenant']) $conn->exec("DELETE FROM tenants WHERE id = " . (int)$made['tenant']);
    $left = (int)$conn->query("SELECT COUNT(*) FROM users WHERE email LIKE " . $conn->quote("%$tag%") . " OR display_name LIKE " . $conn->quote("%$tag%"))->fetchColumn()
          + (int)$conn->query("SELECT COUNT(*) FROM user_email_addresses WHERE email LIKE " . $conn->quote("%$tag%"))->fetchColumn();
    ok('cleaned up', $left === 0, (string)$left);
}
echo str_repeat('-', 72) . "\n{$pass} passed, {$fail} failed\n";
exit($fail ? 1 : 0);
