<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * The fixes for a private security report, October 2026, each proved against the
 * real code. tests/get-must-not-write.php is the static half of the first one.
 *
 *   php tests/security-report-2026-10.php
 *
 *   1. A GET cannot change data - a link on another site can send one.
 *   2. sessionIsAdmin() asks the database, not a copy cached at sign-in.
 *   3. A deactivated or deleted analyst's open session is ended.
 *   4. The software API keys need the API keys permission, not just the module.
 *   5. POST /api/v1/users checks the company it infers from the address.
 *   6. The portal ticket lists apply the third-party privacy rule to their preview.
 *   7. A channel message only threads into an open ticket from the same channel.
 *   8. A mailbox OAuth callback only accepts a state this session was issued.
 *
 * Runs against the real database. What it makes is named ZZ and is removed in the
 * finally block (a ticket, its messages, a requester, two company domains, an API
 * key); a setting it switches is put back. 🔴 Nothing destructive is ever sent as a
 * signed-in GET: the GET checks run signed OUT (a request that cannot write even if
 * the fix were missing), except one reversible setting that is snapshotted first.
 *
 * The HTTP checks need the app at http://localhost/<folder>/ (override with
 * FREEITSM_URL); they are skipped, and say so, when it does not answer.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../includes/portal_visibility.php';
require_once __DIR__ . '/../includes/messaging/messaging.php';
require_once __DIR__ . '/../includes/messaging/ingest.php';
require_once __DIR__ . '/../includes/mailbox_oauth_state.php';

$pass = 0; $fail = 0; $skip = 0;
function ok(string $what, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  PASS  {$what}\n"; }
    else       { $fail++; echo "  FAIL  {$what}" . ($detail !== '' ? "  <- {$detail}" : '') . "\n"; }
}
function skip(string $what, string $why): void {
    global $skip; $skip++; echo "  SKIP  {$what}  ({$why})\n";
}

$conn = connectToDatabase();
$saved = [];
/** Sets one setting, remembering what was there (absent included). */
function zzSet(PDO $conn, string $key, string $value): void {
    global $saved;
    if (!array_key_exists($key, $saved)) {
        $s = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ?");
        $s->execute([$key]);
        $v = $s->fetchColumn();
        $saved[$key] = $v === false ? null : $v;
    }
    $conn->prepare("DELETE FROM system_settings WHERE setting_key = ?")->execute([$key]);
    $conn->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?)")->execute([$key, $value]);
}
function zzRestore(PDO $conn): void {
    global $saved;
    foreach ($saved as $key => $old) {
        $conn->prepare("DELETE FROM system_settings WHERE setting_key = ?")->execute([$key]);
        if ($old !== null) $conn->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?)")->execute([$key, $old]);
    }
    $saved = [];
}
function setting(PDO $conn, string $key): ?string {
    $s = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ?");
    $s->execute([$key]);
    $v = $s->fetchColumn();
    return $v === false ? null : (string)$v;
}

$admin    = (int)$conn->query("SELECT id FROM analysts WHERE is_active = 1 AND is_admin = 1 ORDER BY id LIMIT 1")->fetchColumn();
$nonAdmin = (int)$conn->query("SELECT id FROM analysts WHERE COALESCE(is_active, 1) = 1 AND COALESCE(is_admin, 0) = 0 ORDER BY id LIMIT 1")->fetchColumn();
if (!$admin || !$nonAdmin) { echo "Needs an active administrator and an active non-administrator.\n"; exit(1); }

$tag  = 'zz' . bin2hex(random_bytes(3));
$made = ['tickets' => [], 'users' => [], 'domains' => [], 'keys' => [], 'sessions' => []];

$base = rtrim(getenv('FREEITSM_URL') ?: 'http://localhost/' . basename(dirname(__DIR__)) . '/', '/') . '/';
$sessDir = rtrim(ini_get('session.save_path') ?: 'c:/wamp64/tmp', '/\\');
/** Writes a forged session file and returns its id. */
function forgeSession(string $body): string {
    global $sessDir, $made;
    $sid = 'zzsec' . bin2hex(random_bytes(5));
    file_put_contents("$sessDir/sess_$sid", $body);
    $made['sessions'][] = $sid;
    return $sid;
}
function sessionFor(int $analystId, string $token = ''): string {
    $name = 'ZZ test';
    return forgeSession('analyst_id|i:' . $analystId . ';analyst_name|s:' . strlen($name) . ':"' . $name . '";'
        . ($token !== '' ? 'csrf_token|s:' . strlen($token) . ':"' . $token . '";' : ''));
}
/** One HTTP request: [status, body]. $sid null = no cookie at all. */
function http(string $method, string $path, ?string $sid = null, ?string $body = null, array $headers = []): array {
    global $base;
    $ch = curl_init($base . $path);
    $opts = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_FOLLOWLOCATION => false,
             CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers];
    if ($sid !== null) $opts[CURLOPT_COOKIE] = 'PHPSESSID=' . $sid;
    if ($body !== null) $opts[CURLOPT_POSTFIELDS] = $body;
    curl_setopt_array($ch, $opts);
    $raw = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    return [$code, $raw === false ? '' : (string)$raw];
}
$online = (function () use ($base) {
    $ch = curl_init($base . 'auth/login.php');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5, CURLOPT_NOBODY => true]);
    curl_exec($ch);
    $c = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    return $c > 0 && $c < 500;
})();
$noFatal = fn(string $b) => !preg_match('/Fatal error|Uncaught|Parse error/', $b);

echo "\nThe October 2026 security report - every fix, against the real code\n" . str_repeat('=', 72) . "\n";
echo $online ? "(HTTP checks against $base)\n" : "(HTTP checks SKIPPED: nothing answers at $base)\n";

try {
    // ------------------------------------------------------------------ 1
    echo "\n1. A GET cannot change data\n";
    if ($online) {
        // Signed OUT on purpose: if a fix were missing, these still could not write.
        $writers = [
            'api/tickets/empty_trash.php', 'api/system/db_verify.php',
            'api/system/debug-tools/D002_delete_ticket.php?ref=ZZ-NONE',
            'api/messaging/test_channel.php?id=1&mode=simulate',
            'api/system/save_module_permission_mode.php', 'api/system/save_branding.php',
            'api/tickets/save_sla_global_settings.php', 'api/tickets/save_public_base_url.php',
            'api/myaccount/save_profile.php', 'api/myaccount/toggle_trust_device.php',
            'api/software/generate_apikey.php', 'api/assets/get_vcenter.php',
            'api/system/tika_settings.php?action=save', 'api/knowledge/permission_model.php?action=set',
            'api/tickets/check_mailbox_email.php?mailbox_id=1',
        ];
        foreach ($writers as $p) {
            [$c, $b] = http('GET', $p, forgeSession(''));
            // Routed endpoints decide the action before the gate, so a signed-out GET may
            // stop at the sign-in check instead - also a refusal. Only "reached" is a fail.
            $refused = $c === 405 || ($c === 401 || str_contains($b, 'Not authenticated') || str_contains($b, 'Administrator access required')
                                      || str_contains($b, 'FAIL: not logged in'));   // the debug tools' own words
            ok("GET $p is refused", $refused && $noFatal($b), "HTTP $c: " . substr(trim(strip_tags($b)), 0, 120));
        }
        [$c, $b] = http('GET', 'api/tickets/empty_trash.php', forgeSession(''));
        ok('...and the refusal is the method check itself (405, before the sign-in check)', $c === 405 && str_contains($b, 'must be sent as a POST'), "HTTP $c $b");

        // Signed in as an administrator - only on a setting we can snapshot and put back.
        $tok = bin2hex(random_bytes(32));
        $sid = sessionFor($admin, $tok);
        $before = setting($conn, 'module_permission_mode');
        [$c, $b] = http('GET', 'api/system/save_module_permission_mode.php', $sid);
        $after = setting($conn, 'module_permission_mode');
        if ($after !== $before) { $conn->prepare("UPDATE system_settings SET setting_value = ? WHERE setting_key = 'module_permission_mode'")->execute([$before]); }
        ok('an ADMINISTRATOR\'s GET to the module permission mode is refused (405)', $c === 405, "HTTP $c $b");
        ok('...and the mode is unchanged', $after === $before, "was '$before', became '$after' (put back)");
        $same = $before === 'least' ? 'least' : 'most';
        [$c, $b] = http('POST', 'api/system/save_module_permission_mode.php', $sid, json_encode(['mode' => $same]),
                        ['Content-Type: application/json', 'X-CSRF-Token: ' . $tok]);
        ok('POSITIVE CONTROL: the same save as a POST with the token still works', $c === 200 && str_contains($b, '"success":true'), "HTTP $c $b");
        ok('...and saving the value it already had changed nothing', setting($conn, 'module_permission_mode') === $before);
        [$c, $b] = http('POST', 'api/system/save_module_permission_mode.php', $sid, json_encode(['mode' => $same]), ['Content-Type: application/json']);
        ok('NEGATIVE CONTROL: a POST without the token is still refused by csrf.php', $c === 403 && str_contains($b, 'csrf_token'), "HTTP $c $b");

        [$c, $b] = http('GET', 'api/system/tika_settings.php?action=get', $sid);
        ok('a READ on GET still works (Tika settings, action=get)', $c === 200 && str_contains($b, '"success":true'), "HTTP $c " . substr($b, 0, 120));
        [$c, $b] = http('GET', 'api/system/debug-tools/D007_search_corpus.php', $sid);
        ok('debug tool D007 refuses a GET', $c === 405, "HTTP $c");
        // Safe as an administrator: no ticket has this reference, so even a missing fix deletes nothing.
        [$c, $b] = http('GET', 'api/system/debug-tools/D002_delete_ticket.php?ref=ZZ-NO-SUCH-TICKET', $sid);
        ok("debug tool D002 refuses an administrator's GET", $c === 405, "HTTP $c " . substr($b, 0, 120));
        [$c, $b] = http('POST', 'api/system/debug-tools/D007_search_corpus.php', $sid, '', ['X-CSRF-Token: ' . $tok]);
        ok('POSITIVE CONTROL: D007 runs as the POST the tool page now sends', $c === 200 && str_contains($b, '=== ') && $noFatal($b), "HTTP $c " . substr($b, 0, 160));
    } else {
        skip('the GET refusals', 'no web server');
    }

    // ------------------------------------------------------------------ 2
    echo "\n2. sessionIsAdmin() asks the database\n";
    $_SESSION = ['analyst_id' => $nonAdmin, 'is_admin' => 1];   // a stale "admin" flag
    ok("a non-admin whose session still says is_admin=1 is NOT an admin (analyst $nonAdmin)", sessionIsAdmin() === false);
    $_SESSION = ['analyst_id' => $admin, 'is_admin' => 0];
    ok('POSITIVE CONTROL: an administrator whose session says 0 IS one', sessionIsAdmin() === true);
    $_SESSION = [];
    ok('nobody signed in is not an admin', sessionIsAdmin() === false);

    // ------------------------------------------------------------------ 3
    echo "\n3. A deactivated or deleted analyst's session is ended\n";
    if ($online) {
        $ghost = (int)$conn->query("SELECT COALESCE(MAX(id), 0) + 100000 FROM analysts")->fetchColumn();
        $sid = sessionFor($ghost);
        [$c, $b] = http('GET', 'api/myaccount/get_mfa_status.php', $sid);
        ok("a session for analyst $ghost, who does not exist, is refused", $c === 401 && str_contains($b, 'Not authenticated'), "HTTP $c $b");
        ok('...and its session file is destroyed, not just refused once', !is_file("$sessDir/sess_$sid"));
        [$c, $b] = http('GET', 'api/myaccount/get_mfa_status.php', sessionFor($nonAdmin));
        ok('POSITIVE CONTROL: an active analyst on the same endpoint is answered', $c === 200 && !str_contains($b, 'Not authenticated') && $noFatal($b), "HTTP $c " . substr($b, 0, 120));
    } else {
        skip('the deactivated-session checks', 'no web server');
    }

    // ------------------------------------------------------------------ 4
    echo "\n4. Software API keys need the API keys permission\n";
    $candidate = null;
    foreach ($conn->query("SELECT id FROM analysts WHERE COALESCE(is_active, 1) = 1 AND COALESCE(is_admin, 0) = 0 ORDER BY id")->fetchAll(PDO::FETCH_COLUMN) as $aid) {
        if (analystCanAccessModule($conn, (int)$aid, 'software') && !analystHasCapability($conn, (int)$aid, Cap::SOFTWARE_API_KEYS)) { $candidate = (int)$aid; break; }
    }
    if ($online && $candidate) {
        foreach (['api/software/get_apikeys.php' => 'GET', 'api/software/toggle_apikey.php' => 'POST'] as $p => $m) {
            $tok = bin2hex(random_bytes(32));
            [$c, $b] = http($m, $p, sessionFor($candidate, $tok), $m === 'POST' ? json_encode(['id' => 0]) : null,
                            ['Content-Type: application/json', 'X-CSRF-Token: ' . $tok]);
            ok("$p refuses analyst $candidate (Software module, no API keys permission)", !str_contains($b, '"success":true') && !str_contains($b, 'apikey') && $c === 403, "HTTP $c " . substr($b, 0, 120));
        }
    } else {
        skip('the API key permission checks', $online ? 'no active non-admin has Software without the API keys permission' : 'no web server');
    }

    // ------------------------------------------------------------------ 5
    echo "\n5. POST /api/v1/users checks the company it infers\n";
    $tenants = $conn->query("SELECT id FROM tenants ORDER BY id LIMIT 2")->fetchAll(PDO::FETCH_COLUMN);
    if ($online && count($tenants) === 2 && isMultiTenant($conn)) {
        [$tA, $tB] = array_map('intval', $tenants);
        foreach (['a' => $tA, 'b' => $tB] as $k => $tid) {
            $conn->prepare("INSERT INTO tenant_domains (tenant_id, domain) VALUES (?, ?)")->execute([$tid, "$tag-$k.test"]);
            $made['domains'][] = (int)$conn->lastInsertId();
        }
        $key = 'fitsm_' . bin2hex(random_bytes(24));
        $conn->prepare("INSERT INTO api_keys (name, key_prefix, key_hash, analyst_id, permissions, company_ids, created_datetime)
                        VALUES (?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())")
             ->execute(["ZZ $tag", substr($key, 0, 12), hash('sha256', $key), $admin, json_encode(['users' => ['read', 'create']]), json_encode([$tA])]);
        $made['keys'][] = (int)$conn->lastInsertId();
        $api = fn(array $body) => http('POST', 'api/v1/index.php?path=/users', null, json_encode($body),
                                       ['Content-Type: application/json', 'Authorization: Bearer ' . $key]);
        [$c, $b] = $api(['email' => "$tag-1@$tag-b.test", 'display_name' => 'ZZ inferred other']);
        ok("a key limited to company $tA cannot create a requester on company $tB's domain", $c === 403, "HTTP $c $b");
        $n = $conn->prepare("SELECT COUNT(*) FROM users WHERE email = ?"); $n->execute(["$tag-1@$tag-b.test"]);
        ok('...and no requester was created', (int)$n->fetchColumn() === 0);
        [$c, $b] = $api(['email' => "$tag-2@$tag-b.test", 'tenant_id' => $tB]);
        ok('CONTROL: naming that company outright was already refused', $c === 403, "HTTP $c");
        [$c, $b] = $api(['email' => "$tag-3@$tag-a.test", 'display_name' => 'ZZ inferred own']);
        $d = json_decode($b, true);
        $newId = (int)($d['data']['id'] ?? $d['id'] ?? 0);
        if ($newId) $made['users'][] = $newId;
        ok("POSITIVE CONTROL: its own company's domain still works (201)", $c === 201 && $newId > 0, "HTTP $c $b");
        if ($newId) {
            $t = $conn->prepare("SELECT tenant_id FROM users WHERE id = ?"); $t->execute([$newId]);
            ok("...and the requester landed in company $tA", (int)$t->fetchColumn() === $tA);
        }
    } else {
        skip('the REST company checks', $online ? 'needs two companies' : 'no web server');
    }

    // ------------------------------------------------------------------ 6
    echo "\n6. Portal list previews follow the privacy rule\n";
    zzSet($conn, 'portal_third_party_visibility', PORTAL_THIRD_PARTY_HIDE);   // before the first (cached) read
    $reqEmail = "$tag-req@example.test";
    $conn->prepare("INSERT INTO users (email, display_name, created_at) VALUES (?, 'ZZ requester', UTC_TIMESTAMP())")->execute([$reqEmail]);
    $made['users'][] = $uid = (int)$conn->lastInsertId();
    $mkTicket = function (string $subject) use ($conn, $uid, $tag, &$made): int {
        $conn->prepare("INSERT INTO tickets (ticket_number, subject, user_id, created_datetime, updated_datetime) VALUES (?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())")
             ->execute(['ZZ-' . $tag . '-' . count($made['tickets']), $subject, $uid]);
        return $made['tickets'][] = (int)$conn->lastInsertId();
    };
    $mkMail = function (int $tid, string $from, string $to, string $preview, string $when, string $channel = 'email', ?int $channelId = null) use ($conn): void {
        $conn->prepare("INSERT INTO emails (subject, from_address, to_recipients, received_datetime, body_preview, body_content, ticket_id, direction, channel, channel_id)
                        VALUES ('ZZ', ?, ?, ?, ?, ?, ?, 'Inbound', ?, ?)")
             ->execute([$from, $to, $when, $preview, $preview, $tid, $channel, $channelId]);
    };
    $t1 = $mkTicket('ZZ preview - supplier replied last');
    $mkMail($t1, $reqEmail, 'desk@example.test', 'ZZ the requester wrote this', '2026-10-01 09:00:00');
    $mkMail($t1, 'supplier@zz-supplier.test', 'desk@example.test', 'ZZ PRIVATE supplier pricing', '2026-10-01 10:00:00');
    $t2 = $mkTicket('ZZ preview - requester replied last');
    $mkMail($t2, 'supplier@zz-supplier.test', 'desk@example.test', 'ZZ PRIVATE older supplier note', '2026-10-01 09:00:00');
    $mkMail($t2, $reqEmail, 'desk@example.test', 'ZZ requester follow-up', '2026-10-01 10:00:00');
    $t3 = $mkTicket('ZZ preview - only third parties');
    $mkMail($t3, 'supplier@zz-supplier.test', 'desk@example.test', 'ZZ PRIVATE only supplier', '2026-10-01 09:00:00');
    $p = portalVisiblePreviews($conn, [$t1, $t2, $t3]);
    ok('with "hide" on, a supplier\'s newer reply is NOT the preview', ($p[$t1] ?? '') === 'ZZ the requester wrote this', json_encode($p[$t1] ?? null));
    ok('POSITIVE CONTROL: the requester\'s own newest message is', ($p[$t2] ?? '') === 'ZZ requester follow-up', json_encode($p[$t2] ?? null));
    ok('a ticket with only third-party mail shows no preview at all', !isset($p[$t3]), json_encode($p[$t3] ?? null));
    ok('nothing marked PRIVATE leaked into any preview', !str_contains(json_encode($p), 'PRIVATE'));
    $src = file_get_contents(__DIR__ . '/../api/self-service/get_tickets.php') . file_get_contents(__DIR__ . '/../api/self-service/get_team_tickets.php');
    ok('both portal lists use it, and neither selects body_preview itself', substr_count($src, 'portalVisiblePreviews(') === 2 && stripos($src, 'body_preview') === false);

    // ------------------------------------------------------------------ 7
    echo "\n7. A channel message threads only within its own channel\n";
    $number = '+1555' . random_int(1000000, 9999999);
    [$chA, $chB] = [2000000000 + random_int(0, 99999), 2100000000 + random_int(0, 99999)];   // ids nothing else uses
    $t4 = $mkTicket('ZZ WhatsApp conversation');
    $mkMail($t4, $number, 'whatsapp', 'ZZ hi', '2026-10-01 09:00:00', 'whatsapp', $chA);
    ok('a WhatsApp message on the same channel finds the open ticket', findOpenChannelTicket($conn, $number, 'whatsapp', '', $chA) === $t4);
    ok("the same number on ANOTHER company's WhatsApp channel does not", findOpenChannelTicket($conn, $number, 'whatsapp', '', $chB) === null);
    ok('CONTROL: Telegram was already scoped, and still is', findOpenChannelTicket($conn, $number, 'telegram', '', $chA) === null);

    // ------------------------------------------------------------------ 8
    echo "\n8. A mailbox OAuth callback only accepts a state this session was issued\n";
    $_SESSION = ['analyst_id' => $admin, 'mailbox_oauth_states' => [
        'mailbox_7_good'    => ['mailbox_id' => 7, 'analyst_id' => $admin, 'expires' => time() + 600],
        'mailbox_8_old'     => ['mailbox_id' => 8, 'analyst_id' => $admin, 'expires' => time() - 1],
        'mailbox_9_someone' => ['mailbox_id' => 9, 'analyst_id' => $admin + 1, 'expires' => time() + 600],
    ]];
    ok('an issued, in-date state gives back its mailbox', mailboxOAuthConsumeState('mailbox_7_good') === 7);
    ok('...once: the second use is refused', mailboxOAuthConsumeState('mailbox_7_good') === null);
    ok('an expired state is refused', mailboxOAuthConsumeState('mailbox_8_old') === null);
    ok("a state issued to a different analyst is refused", mailboxOAuthConsumeState('mailbox_9_someone') === null);
    ok('a state that was never issued is refused', mailboxOAuthConsumeState('mailbox_1_' . bin2hex(random_bytes(8))) === null);
    $_SESSION = [];
    if ($online) {
        $tokBefore = $conn->query("SELECT MD5(COALESCE(token_data, '')) FROM target_mailboxes WHERE id = (SELECT MIN(id) FROM target_mailboxes)")->fetchColumn();
        foreach (['auth/oauth_callback.php' => 'mailbox_', 'auth/google_oauth_callback.php' => 'google_mailbox_'] as $p => $pre) {
            [$c, $b] = http('GET', "$p?code=zz-not-a-real-code&state={$pre}1_" . bin2hex(random_bytes(8)), forgeSession(''));
            ok("$p refuses a state nobody issued, before any token exchange", str_contains($b, 'Security check failed') && $noFatal($b), substr(trim(strip_tags($b)), 0, 140));
        }
        $tokAfter = $conn->query("SELECT MD5(COALESCE(token_data, '')) FROM target_mailboxes WHERE id = (SELECT MIN(id) FROM target_mailboxes)")->fetchColumn();
        ok('...and the mailbox tokens are untouched', $tokBefore === $tokAfter);
        $mbId = (int)$conn->query("SELECT MIN(id) FROM target_mailboxes WHERE provider = 'microsoft'")->fetchColumn();
        if ($mbId) {
            $tok = bin2hex(random_bytes(32));
            $sid = sessionFor($admin, $tok);
            [$c, $b] = http('POST', 'api/tickets/mailbox_oauth_start.php', $sid, json_encode(['mailbox_id' => $mbId]),
                            ['Content-Type: application/json', 'X-CSRF-Token: ' . $tok]);
            $state = (string)(json_decode($b, true)['state'] ?? '');
            ok('the settings page can get a state from the server', (bool)preg_match('/^mailbox_' . $mbId . '_[0-9a-f]{32}$/', $state), "HTTP $c $b");
            [$c, $b] = http('GET', 'auth/oauth_callback.php?code=zz-not-a-real-code&state=' . rawurlencode($state), sessionFor($nonAdmin));
            ok("that state is refused in somebody else's session", str_contains($b, 'Security check failed'));
            [$c, $b] = http('GET', 'auth/oauth_callback.php?code=zz-not-a-real-code&state=' . rawurlencode($state), $sid);
            ok('POSITIVE CONTROL: in its own session it passes the check (the fake code then fails at Microsoft)',
               !str_contains($b, 'Security check failed') && str_contains($b, 'Error getting tokens'), substr(trim(strip_tags($b)), 0, 140));
            ok('...and the mailbox tokens are still untouched', $conn->query("SELECT MD5(COALESCE(token_data, '')) FROM target_mailboxes WHERE id = (SELECT MIN(id) FROM target_mailboxes)")->fetchColumn() === $tokAfter);
        }
    } else {
        skip('the OAuth callback HTTP checks', 'no web server');
    }
} finally {
    zzRestore($conn);
    foreach ($made['tickets'] as $tid) {
        $conn->prepare("DELETE FROM emails WHERE ticket_id = ? AND subject = 'ZZ'")->execute([$tid]);
        $conn->prepare("DELETE FROM tickets WHERE id = ? AND ticket_number LIKE 'ZZ-%'")->execute([$tid]);
    }
    foreach ($made['users'] as $id)   $conn->prepare("DELETE FROM users WHERE id = ? AND (email LIKE 'zz%' OR display_name LIKE 'ZZ%')")->execute([$id]);
    foreach ($made['domains'] as $id) $conn->prepare("DELETE FROM tenant_domains WHERE id = ? AND domain LIKE 'zz%'")->execute([$id]);
    foreach ($made['keys'] as $id)    $conn->prepare("DELETE FROM api_keys WHERE id = ? AND name LIKE 'ZZ %'")->execute([$id]);
    foreach ($made['sessions'] as $sid) @unlink("$sessDir/sess_$sid");
    $left = (int)$conn->query("SELECT (SELECT COUNT(*) FROM tickets WHERE ticket_number LIKE 'ZZ-$tag-%')
                                    + (SELECT COUNT(*) FROM users WHERE email LIKE '$tag%')
                                    + (SELECT COUNT(*) FROM tenant_domains WHERE domain LIKE '$tag%')
                                    + (SELECT COUNT(*) FROM api_keys WHERE name = 'ZZ $tag')")->fetchColumn();
    echo "\nCleanup: " . ($left === 0 ? 'everything this run made is gone' : "$left ROWS LEFT BEHIND - tag $tag") . "\n";
}

echo "\n" . str_repeat('=', 72) . "\n$pass passed, $fail failed" . ($skip ? ", $skip skipped" : '') . "\n";
exit($fail ? 1 : 0);
