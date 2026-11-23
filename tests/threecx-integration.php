<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * The 3CX integration (System → Integrations → 3CX), driven through the real
 * endpoint with requests shaped exactly as the downloaded CRM template sends them.
 *
 *   php tests/threecx-integration.php
 *
 * Proves: the token is required, and works as the template sends it (a Bearer
 * header); a lookup finds the one person with the number however it is written,
 * and nobody when two people share it; a lookup says nothing about tickets; a
 * call in from someone with no ticket opens one, assigned to the analyst who took
 * it; the next call is noted on it, written by that analyst; the same report
 * twice is noted once; a call out never opens a ticket; a 3CX user who is not an
 * analyst falls back to the chosen analyst, or only lists the call; the "only
 * one" and "none" rules; unknown numbers; text from 3CX stays text; the template
 * is well-formed XML with the token in a header; click-to-call links; "Try a
 * call" writes nothing; and the settings API is administrators-only.
 *
 * There is no 3CX here (it does not run in Docker the way Zabbix does), so this is
 * the whole proof FreeITSM can give: the requests are the template's own.
 *
 * Runs against the real database. Everything is named ZZ and removed at the end:
 * the people, the two analysts the calls are taken by (made here, with addresses
 * that can never receive mail, so no real analyst is assigned or notified), the
 * tickets with every row that points at them, and the call log rows. The 3CX
 * settings, the ticket counters and the auto-acknowledge workflows (paused, so
 * nobody is emailed) are put back. Needs the app at http://localhost/<folder>/
 * (or FREEITSM_URL).
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/integrations/threecx.php';

$pass = 0; $fail = 0;
function ok(string $what, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  PASS  {$what}\n"; }
    else       { $fail++; echo "  FAIL  {$what}" . ($detail !== '' ? "  <- {$detail}" : '') . "\n"; }
}

$conn = connectToDatabase();
if (!threecxReady($conn)) { echo "Run Database Verification first (threecx_calls is missing).\n"; exit(1); }
$base = rtrim(getenv('FREEITSM_URL') ?: 'http://localhost/' . basename(dirname(__DIR__)) . '/', '/') . '/';
$endpoint = $base . 'api/external/3cx/crm.php';
$tag = 'zz' . bin2hex(random_bytes(3));

/** POST to the endpoint as 3CX does: JSON, the token as a Bearer header (or none / another). */
function send(string $url, ?array $body, ?string $bearer, string $method = 'POST'): array {
    $ch = curl_init($url);
    $h = ['Content-Type: application/json'];
    if ($bearer !== null) $h[] = 'Authorization: Bearer ' . $bearer;
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $h]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    $raw = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    return [$code, json_decode((string)$raw, true) ?: ['raw' => (string)$raw]];
}

// Ten random digits after a country code that does not exist: no real person's
// number can end the same way, so every match below is one of ours.
$num = fn() => '+999 ' . random_int(1000, 9999) . ' ' . random_int(100000, 999999);

// --- Snapshots of everything this touches that is not its own -------------
$settingKeys = ['threecx_token', 'threecx_ticket_rule', 'threecx_create_ticket', 'threecx_create_unknown', 'threecx_fallback_analyst_id',
                'threecx_tenant_id', 'threecx_team_id', 'threecx_origin_id', 'phone_dial_url'];
$savedSettings = [];
foreach ($settingKeys as $k) {
    $s = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ?"); $s->execute([$k]);
    $savedSettings[$k] = $s->fetchColumn();
}
$counters = $conn->query("SELECT counter_key, next_value FROM ticket_number_counters")->fetchAll(PDO::FETCH_KEY_PAIR);
$paused = $conn->query("SELECT id FROM workflows WHERE is_active = 1 AND trigger_event = 'ticket.created'")->fetchAll(PDO::FETCH_COLUMN);
$tickets = []; $users = []; $analysts = []; $numbers = [];

$mkUser = function (string $name, ?string $phone, ?string $mobile = null) use ($conn, $tag, &$users): int {
    $conn->prepare("INSERT INTO users (email, display_name, phone, mobile, tenant_id, created_at) VALUES (?, ?, ?, ?, NULL, UTC_TIMESTAMP())")
         ->execute(["$tag-" . count($users) . '@example.invalid', "ZZ $name", $phone, $mobile]);
    return $users[] = (int)$conn->lastInsertId();
};
$mkAnalyst = function (string $name) use ($conn, $tag, &$analysts): array {
    $email = "$tag-agent" . count($analysts) . '@example.invalid';
    $conn->prepare("INSERT INTO analysts (username, password_hash, full_name, email, is_active) VALUES (?, '!', ?, ?, 1)")
         ->execute(["$tag-a" . count($analysts), "ZZ $name", $email]);
    $id = (int)$conn->lastInsertId();
    $analysts[] = $id;
    return ['id' => $id, 'email' => $email];
};
$ticketsOf = function (int $userId) use ($conn): array {
    $s = $conn->prepare("SELECT id FROM tickets WHERE user_id = ? ORDER BY id"); $s->execute([$userId]);
    return array_map('intval', $s->fetchAll(PDO::FETCH_COLUMN));
};
$notesOn = function (int $ticketId) use ($conn): array {
    $s = $conn->prepare("SELECT analyst_id, note_text, is_internal FROM ticket_notes WHERE ticket_id = ? ORDER BY id"); $s->execute([$ticketId]);
    return $s->fetchAll(PDO::FETCH_ASSOC);
};

echo "\n3CX integration - through the real endpoint\n" . str_repeat('=', 72) . "\n";
try {
    if ($paused) $conn->exec("UPDATE workflows SET is_active = 0 WHERE id IN (" . implode(',', array_map('intval', $paused)) . ")");
    foreach ($settingKeys as $k) $conn->prepare("DELETE FROM system_settings WHERE setting_key = ?")->execute([$k]);

    $agent = $mkAnalyst('Agent');
    $back  = $mkAnalyst('Fallback');
    $aNum = $num(); $numbers[] = $aNum;
    $aDigits = preg_replace('/\D+/', '', $aNum);
    // Stored the way people type it: national-looking, spaced, with an extension after it.
    $alice = $mkUser('Alice', '0' . substr($aDigits, 3, 4) . ' ' . substr($aDigits, 7) . ' ext 21');
    $shared = $num(); $numbers[] = $shared;
    $mkUser('Bob', $shared);
    $mkUser('Bea', null, $shared);
    $cNum = $num();
    $carol = $mkUser('Carol', $cNum);

    echo "\nThe token\n";
    [$c] = send($endpoint, ['action' => 'lookup', 'number' => $aNum], 'anything');
    ok('with no token made yet, every call is refused (403) - the integration is off', $c === 403, "HTTP $c");
    $token = threecxRotateToken($conn);
    [$c] = send($endpoint, ['action' => 'lookup', 'number' => $aNum], 'wrong-' . $token);
    ok('a wrong token is refused (403)', $c === 403, "HTTP $c");
    [$c] = send($endpoint, ['action' => 'lookup', 'number' => $aNum], null);
    ok('no token at all is refused (403)', $c === 403, "HTTP $c");
    [$c] = send($endpoint . '?action=lookup&number=' . urlencode($aNum) . '&token=' . $token, null, null, 'GET');
    ok('a GET - the token in the address, as the contributed version took it - is refused (405)', $c === 405, "HTTP $c");
    [$c, $r] = send($endpoint, ['action' => 'lookup', 'number' => $aNum], $token);
    ok('the token as a Bearer header - how the template sends it - is accepted', $c === 200 && isset($r['contacts']), "HTTP $c " . json_encode($r));
    if (!webhookEncryptionAvailableSafe()) echo "  (no encryption key on this install: the token is stored readable, by design)\n";
    else {
        $raw = (string)$conn->query("SELECT setting_value FROM system_settings WHERE setting_key = 'threecx_token'")->fetchColumn();
        ok('the token is encrypted at rest', $raw !== '' && $raw !== $token);
    }

    echo "\nWho is calling (lookup)\n";
    [$c, $r] = send($endpoint, ['action' => 'lookup', 'number' => $aNum], $token);
    $ct = $r['contacts'][0] ?? [];
    ok('an international number finds the person stored with a national-looking number and an extension',
       count($r['contacts'] ?? []) === 1 && ($ct['id'] ?? '') === (string)$alice && ($ct['first_name'] ?? '') === 'ZZ Alice', json_encode($r));
    ok('...with the address 3CX opens: the caller page, by person', str_contains((string)($ct['url'] ?? ''), 'tickets/caller.php?user_id=' . $alice), (string)($ct['url'] ?? ''));
    ok('...and the phone 3CX searched for handed back, as its template rules require', ($ct['phone'] ?? '') === $aNum);
    ok('a lookup says nothing about tickets - not even that there are any', !preg_grep('/ticket|subject/i', array_keys($ct)), json_encode(array_keys($ct)));
    [$c, $r] = send($endpoint, ['action' => 'lookup', 'number' => substr($aDigits, -9)], $token);
    ok('the last 9 digits alone (3CX set to match 9) find the same person', ($r['contacts'][0]['id'] ?? '') === (string)$alice, json_encode($r));
    [$c, $r] = send($endpoint, ['action' => 'lookup', 'number' => $shared], $token);
    ok('a number two people share finds NOBODY - no guessing who it is', ($r['contacts'] ?? null) === [], json_encode($r));
    [$c, $r] = send($endpoint, ['action' => 'lookup', 'number' => '201'], $token);
    ok('an extension (3 digits) matches nobody', ($r['contacts'] ?? null) === [], json_encode($r));

    $call = fn(string $number, array $extra = []) => array_merge([
        'action' => 'report', 'call_type' => 'Inbound', 'number' => $number, 'agent' => '201', 'agent_email' => strtoupper($agent['email']),
        'agent_name' => 'ZZ Agent', 'duration' => '245', 'start' => gmdate('Y-m-d\TH:i:s\Z', time() - random_int(100, 99999)),
        'recording_url' => '[RecordingUrl]', 'summary' => '[Summary]',
    ], $extra);

    echo "\nCalls on tickets (report)\n";
    [$c, $r] = send($endpoint, $call($aNum), $token);
    $aT = $ticketsOf($alice); $tickets = array_merge($tickets, $aT);
    $tk = $conn->prepare("SELECT t.user_id, t.assigned_analyst_id, t.origin_id, o.name AS origin, t.subject FROM tickets t LEFT JOIN ticket_origins o ON o.id = t.origin_id WHERE t.id = ?");
    $tk->execute([$aT[0] ?? 0]); $row = $tk->fetch(PDO::FETCH_ASSOC) ?: [];
    ok('a call in from someone with no open ticket opens one, for them', $c === 200 && ($r['action'] ?? '') === 'created' && count($aT) === 1 && (int)($row['user_id'] ?? 0) === $alice, json_encode($r));
    ok('...assigned to the analyst who took it (matched on 3CX\'s agent email, any case)', (int)($row['assigned_analyst_id'] ?? 0) === $agent['id'], json_encode($row));
    ok('...from the Phone origin, when the install has one', threecxDefaultOriginId($conn) === null || strtolower((string)($row['origin'] ?? '')) === 'phone', json_encode($row));
    $second = $call($aNum, ['call_type' => 'Inbound', 'duration' => '00:04:05', 'recording_url' => 'https://pbx.example.invalid/rec/1.wav', 'summary' => 'Printer <b>jammed</b> again']);
    [$c, $r] = send($endpoint, $second, $token);
    $notes = $notesOn($aT[0] ?? 0);
    $n = end($notes) ?: [];
    ok('the next call is noted on that ticket - not a second ticket', ($r['action'] ?? '') === 'noted' && (int)($r['ticket_id'] ?? 0) === ($aT[0] ?? -1) && count($ticketsOf($alice)) === 1, json_encode($r));
    ok('...an internal note, written by the analyst who took the call', (int)($n['analyst_id'] ?? 0) === $agent['id'] && (int)($n['is_internal'] ?? 0) === 1, json_encode($n));
    ok('...saying how long (hh:mm:ss read), with the recording link and 3CX\'s summary',
       str_contains((string)($n['note_text'] ?? ''), 'Duration: 4m 05s') && str_contains((string)$n['note_text'], 'https://pbx.example.invalid/rec/1.wav')
       && str_contains((string)$n['note_text'], 'Printer'), (string)($n['note_text'] ?? ''));
    ok('a variable 3CX had no value for ("[Summary]") is treated as not sent, never stored', !preg_grep('/\[(Summary|RecordingUrl)\]/', array_column($notes, 'note_text')));
    [$c, $r] = send($endpoint, $second, $token);
    ok('the same report twice (3CX retrying) is noted once', ($r['action'] ?? '') === 'duplicate' && count($notesOn($aT[0] ?? 0)) === count($notes), json_encode($r));
    [$c, $r] = send($endpoint, $call($aNum, ['recording_url' => 'javascript:alert(1)']), $token);
    $last = $notesOn($aT[0] ?? 0); $last = (string)(end($last)['note_text'] ?? '');
    ok('a recording "link" that is not a web link is dropped', ($r['action'] ?? '') === 'noted' && !str_contains($last, 'javascript:'), $last);

    [$c, $r] = send($endpoint, $call($cNum, ['call_type' => 'Outbound']), $token);
    $tickets = array_merge($tickets, $ticketsOf($carol));
    ok('a call OUT to someone with no ticket never opens one - only listed', ($r['action'] ?? '') === 'logged' && $ticketsOf($carol) === [], json_encode($r));

    [$c, $r] = send($endpoint, $call($aNum, ['agent_email' => 'reception@example.invalid', 'agent_name' => 'Front Desk']), $token);
    $cnt = count($notesOn($aT[0] ?? 0));
    ok('a 3CX user who is not an analyst, with no fallback chosen: only listed', ($r['action'] ?? '') === 'logged', json_encode($r));
    threecxPutSetting($conn, 'threecx_fallback_analyst_id', (string)$back['id']);
    [$c, $r] = send($endpoint, $call($aNum, ['agent_email' => 'reception@example.invalid', 'agent_name' => 'Front Desk']), $token);
    $fb = $notesOn($aT[0] ?? 0); $fb = end($fb) ?: [];
    ok('...with a fallback: noted, written by the fallback analyst, saying who took it',
       ($r['action'] ?? '') === 'noted' && (int)($fb['analyst_id'] ?? 0) === $back['id'] && str_contains((string)$fb['note_text'], 'Taken in 3CX by Front Desk (ext 201)')
       && count($notesOn($aT[0] ?? 0)) === $cnt + 1, json_encode($fb));

    // A second open ticket for Alice, then the rules.
    [$c, $r] = send($endpoint, $call($cNum), $token);   // Carol: a ticket of her own via a call in
    $tickets = array_merge($tickets, $ticketsOf($carol));
    $tid2 = TicketsService::createTicket($conn, ActorContext::system('ZZ test'), getDefaultTenantId($conn),
                                         ['subject' => "$tag second", 'user_id' => $alice], null, 'ZZ test');
    $tickets[] = $tid2;
    threecxPutSetting($conn, 'threecx_ticket_rule', 'only_one');
    [$c, $r] = send($endpoint, $call($aNum), $token);
    ok('rule "only one": a caller with two open tickets - only listed, saying why', ($r['action'] ?? '') === 'logged'
       && str_contains((string)$conn->query("SELECT detail FROM threecx_calls ORDER BY id DESC LIMIT 1")->fetchColumn(), '2 open tickets'), json_encode($r));
    threecxPutSetting($conn, 'threecx_ticket_rule', 'latest_open');
    [$c, $r] = send($endpoint, $call($aNum), $token);
    ok('rule "latest open": the most recently updated one', ($r['action'] ?? '') === 'noted' && (int)($r['ticket_id'] ?? 0) === $tid2, json_encode($r));
    [$tf] = threecxTicketFor($conn, $alice, 'latest_open');
    ok('...and the page 3CX opens chooses the same ticket (tickets/caller.php reads threecxTicketFor)', $tf === $tid2);
    threecxPutSetting($conn, 'threecx_ticket_rule', 'log_only');
    [$c, $r] = send($endpoint, $call($aNum), $token);
    ok('rule "none": only listed', ($r['action'] ?? '') === 'logged', json_encode($r));
    threecxPutSetting($conn, 'threecx_ticket_rule', 'latest_open');

    [$c, $r] = send($endpoint, $call($shared), $token);
    ok('a call from a shared number is only listed - "2 people have this number"', ($r['action'] ?? '') === 'logged'
       && str_contains((string)$conn->query("SELECT detail FROM threecx_calls ORDER BY id DESC LIMIT 1")->fetchColumn(), '2 people'), json_encode($r));

    $uNum = $num(); $uDigits = preg_replace('/\D+/', '', $uNum);
    [$c, $r] = send($endpoint, $call($uNum), $token);
    ok('an unknown number, by default: only listed', ($r['action'] ?? '') === 'logged', json_encode($r));
    threecxPutSetting($conn, 'threecx_create_unknown', '1');
    [$c, $r] = send($endpoint, $call($uNum), $token);
    if (!empty($r['ticket_id'])) $tickets[] = (int)$r['ticket_id'];
    $ph = $conn->prepare("SELECT u.id, u.email, u.display_name FROM tickets t JOIN users u ON u.id = t.user_id WHERE t.id = ?");
    $ph->execute([(int)($r['ticket_id'] ?? 0)]); $ph = $ph->fetch(PDO::FETCH_ASSOC) ?: [];
    if (!empty($ph['id'])) $users[] = (int)$ph['id'];
    ok('...ticked: a ticket for "Caller <number>", an address that can never receive mail',
       ($r['action'] ?? '') === 'created' && ($ph['email'] ?? '') === $uDigits . '@phone.invalid' && ($ph['display_name'] ?? '') === "Caller $uNum", json_encode($ph));
    [$c, $r] = send($endpoint, ['action' => 'lookup', 'number' => $uNum], $token);
    ok('...and that placeholder is never offered to 3CX as the caller', ($r['contacts'] ?? null) === [], json_encode($r));

    [$c] = send($endpoint, ['action' => 'report', 'call_type' => 'Inbound'], $token);
    ok('a report with no number is refused (400)', $c === 400, "HTTP $c");
    [$c] = send($endpoint, ['action' => 'drop_tables'], $token);
    ok('an action that is not lookup or report is refused (400)', $c === 400, "HTTP $c");
    $logged = (int)$conn->query("SELECT COUNT(*) FROM threecx_calls WHERE number IN (" . implode(',', array_map([$conn, 'quote'], [$aNum, $cNum, $shared, $uNum])) . ")")->fetchColumn();
    ok('every report processed is in the log the settings page shows (13)', $logged === 13, (string)$logged);

    echo "\nThe template\n";
    $xml = threecxTemplateXml('https://desk.example.invalid/api/external/3cx/crm.php', $token);
    libxml_use_internal_errors(true);
    $doc = simplexml_load_string($xml);
    ok('it is well-formed XML', $doc !== false, implode('; ', array_map(fn($e) => trim($e->message), libxml_get_errors())));
    if ($doc !== false) {
        $param = fn(string $n) => (string)($doc->xpath("//Parameter[@Name='$n']/@Default")[0] ?? '');
        ok('...named FreeITSM, with this install\'s address and token as its parameters', (string)$doc['Name'] === 'FreeITSM'
           && $param('Url') === 'https://desk.example.invalid/api/external/3cx/crm.php' && $param('Token') === $token);
        $urls = array_map('strval', $doc->xpath('//Request/@Url'));
        ok('...the token is in a header on both requests, never in an address', $urls === ['[Url]', '[Url]']
           && count($doc->xpath("//Headers/Value[@Key='Authorization']")) === 2 && !str_contains(implode('', $urls), 'Token'));
        ok('...a lookup scenario (empty Id) and a ReportCall scenario', count($doc->xpath("//Scenario[@Id='']")) === 1 && count($doc->xpath("//Scenario[@Id='ReportCall']")) === 1);
        $keys = array_map('strval', $doc->xpath("//Scenario[@Id='ReportCall']//PostValues/Value/@Key"));
        ok('...the report sends what the endpoint reads', !array_diff(['action', 'call_type', 'number', 'agent', 'agent_email', 'agent_name', 'duration', 'start', 'recording_url', 'summary'], $keys), implode(',', $keys));
        ok('...the lookup outputs 3CX requires: ContactUrl, a name, a phone', count($doc->xpath("//Output[@Type='ContactUrl']")) === 1
           && count($doc->xpath("//Output[@Type='FirstName']")) === 1 && count($doc->xpath("//Output[@Type='PhoneBusiness']")) === 1);
    }
    $nasty = threecxTemplateXml('https://x.invalid/a?b=1&c="2"<z>', 'tok&"<>');
    ok('an address or token with XML characters in it is escaped, not breaking the file', simplexml_load_string($nasty) !== false);

    echo "\nClick-to-call\n";
    ok('tel: dials the digits, the + kept, the extension dropped', phoneDialHref('tel:{number}', '+44 (0)7700 900123 ext 12') === 'tel:%2B4407700900123');
    ok('the 3CX web client address takes the number', phoneDialHref('https://pbx.example.invalid/webclient/#/call?phone={number}', '07700 900123')
       === 'https://pbx.example.invalid/webclient/#/call?phone=07700900123');
    ok('a dial address with javascript: (or plain http) is not accepted', !phoneDialTemplateValid('javascript:alert({number})') && !phoneDialTemplateValid('http://x/{number}'));
    ok('one without {number} is not accepted', !phoneDialTemplateValid('tel:0123'));
    ok('no link for something that is not a number', phoneDialHref('tel:{number}', 'ask reception') === '');
    ok('click-to-call is off with no dial address', phoneDialTemplate($conn) === '');

    echo "\nThe settings page's API\n";
    $dir = rtrim(ini_get('session.save_path') ?: 'c:/wamp64/tmp', '/\\');
    $csrf = bin2hex(random_bytes(32));
    $sess = function (int $analystId) use ($dir, $csrf): string {
        $sid = 'zz3cx' . bin2hex(random_bytes(5));
        file_put_contents("$dir/sess_$sid", 'analyst_id|i:' . $analystId . ';analyst_name|s:7:"ZZ test";csrf_token|s:64:"' . $csrf . '";');
        return $sid;
    };
    $api = function (string $sid, string $method, ?array $body, string $qs = '') use ($base, $csrf): array {
        $ch = curl_init($base . 'api/system/threecx_settings.php' . $qs);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_COOKIE => 'PHPSESSID=' . $sid,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-CSRF-Token: ' . $csrf]]);
        if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        $raw = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $type = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        return [$code, json_decode((string)$raw, true) ?: [], (string)$raw, $type];
    };
    $admin = (int)$conn->query("SELECT id FROM analysts WHERE is_active = 1 AND is_admin = 1 ORDER BY id LIMIT 1")->fetchColumn();
    $sA = $sess($admin); $sP = $sess($agent['id']);
    [$c, $d] = $api($sA, 'GET', null, '?action=get');
    ok('an administrator reads the settings, with the token and the address to give 3CX',
       $c === 200 && !empty($d['success']) && ($d['token'] ?? '') === $token && str_contains((string)($d['endpoint'] ?? ''), 'api/external/3cx/crm.php'), "HTTP $c");
    [$c, $d2] = $api($sP, 'GET', null, '?action=get');
    ok('a non-administrator is refused - and never sees the token', ($c === 403 || empty($d2['success'])) && !isset($d2['token']), "HTTP $c");
    [$c, , $raw] = $api($sP, 'GET', null, '?action=export');
    ok('...nor downloads the template that carries it', !str_contains($raw, $token), "HTTP $c");
    [$c, , $raw, $type] = $api($sA, 'GET', null, '?action=export&endpoint=' . urlencode('https://desk.example.invalid/api/external/3cx/crm.php'));
    ok('an administrator downloads the template, as XML, with the address typed on the page', str_contains($type, 'xml')
       && str_contains($raw, 'https://desk.example.invalid/api/external/3cx/crm.php') && simplexml_load_string($raw) !== false, "HTTP $c $type");
    [$c] = $api($sA, 'GET', null, '?action=rotate');
    ok('a rotate sent as a GET (a link) is refused, and the token is unchanged', $c === 405 && threecxToken($conn) === $token, "HTTP $c");
    $set = $d['settings'] ?? [];
    [$c, $d3] = $api($sA, 'POST', ['action' => 'save', 'ticket_rule' => 'only_one', 'create_ticket' => true, 'create_unknown' => false,
                                   'fallback_analyst_id' => $back['id'], 'tenant_id' => $set['tenant_id'] ?? 0, 'team_id' => 0,
                                   'origin_id' => (int)($set['origin_id'] ?? 0), 'dial_url' => 'tel:{number}']);
    $now = threecxSettings($conn);
    ok('POSITIVE CONTROL: the page\'s save works', !empty($d3['success']) && $now['ticket_rule'] === 'only_one' && $now['dial_url'] === 'tel:{number}'
       && $now['fallback_analyst_id'] === $back['id'] && phoneDialTemplate($conn) === 'tel:{number}', json_encode($d3));
    [$c, $d4] = $api($sA, 'POST', ['action' => 'save', 'ticket_rule' => 'latest_open', 'dial_url' => 'javascript:alert({number})']);
    ok('...and a dial address that is not a phone link is refused with a sentence', empty($d4['success']) && str_contains((string)($d4['error'] ?? ''), '{number}'), json_encode($d4));

    echo "\nTry a call (the page's button)\n";
    $before = (int)$conn->query("SELECT (SELECT COUNT(*) FROM threecx_calls) + (SELECT COUNT(*) FROM ticket_notes) + (SELECT COUNT(*) FROM tickets)")->fetchColumn();
    [$c, $t1] = $api($sA, 'POST', ['action' => 'try', 'number' => $aNum, 'call_type' => 'Inbound', 'agent_email' => $agent['email']]);
    ok('it describes the real decision: the caller, and why it would only be listed ("only one", two open tickets)',
       !empty($t1['success']) && ($t1['caller']['id'] ?? 0) === $alice && ($t1['action'] ?? '') === 'logged' && str_contains((string)$t1['detail'], '2 open'), json_encode($t1));
    [$c, $t2] = $api($sA, 'POST', ['action' => 'try', 'number' => $cNum, 'call_type' => 'Inbound', 'agent_email' => 'nobody@example.invalid']);
    ok('...and for a caller with one ticket: noted on it, by the fallback analyst', ($t2['action'] ?? '') === 'noted' && ($t2['author'] ?? '') === 'ZZ Fallback'
       && !empty($t2['ticket_number']), json_encode($t2));
    $after = (int)$conn->query("SELECT (SELECT COUNT(*) FROM threecx_calls) + (SELECT COUNT(*) FROM ticket_notes) + (SELECT COUNT(*) FROM tickets)")->fetchColumn();
    ok('...and writes NOTHING - no call row, no note, no ticket', $after === $before, "$before -> $after");
    [$c] = $api($sA, 'GET', null, '?action=try&number=1');
    ok('a try sent as a GET is refused (405)', $c === 405, "HTTP $c");
    @unlink("$dir/sess_$sA"); @unlink("$dir/sess_$sP");
} finally {
    $tickets = array_values(array_unique(array_filter($tickets)));
    if ($tickets) {
        // Every row in any table that points at one of these tickets - notes,
        // audit, the opening message, search, notifications - then the tickets.
        $in = implode(',', array_map('intval', $tickets));
        $tables = $conn->query("SELECT TABLE_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
                                  AND COLUMN_NAME = 'ticket_id' AND TABLE_NAME <> 'tickets'")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($tables as $tbl) { try { $conn->exec("DELETE FROM `$tbl` WHERE ticket_id IN ($in)"); } catch (Throwable $e) {} }
        $conn->exec("DELETE FROM tickets WHERE id IN ($in)");
    }
    $conn->prepare("DELETE FROM threecx_calls WHERE agent_email LIKE ? OR number IN (?, ?, ?, ?)")
         ->execute(["$tag-%", $numbers[0] ?? '-', $numbers[1] ?? '-', $cNum ?? '-', $uNum ?? '-']);
    if ($analysts) {
        $in = implode(',', $analysts);
        $conn->exec("DELETE FROM threecx_calls WHERE analyst_id IN ($in)");
        $tables = $conn->query("SELECT TABLE_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
                                  AND COLUMN_NAME = 'analyst_id' AND TABLE_NAME <> 'analysts'")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($tables as $tbl) { try { $conn->exec("DELETE FROM `$tbl` WHERE analyst_id IN ($in)"); } catch (Throwable $e) {} }
        $conn->exec("DELETE FROM analysts WHERE id IN ($in) AND username LIKE '$tag-%'");
    }
    if ($users) {
        $in = implode(',', array_map('intval', $users));
        $conn->exec("DELETE FROM users WHERE id IN ($in) AND (email LIKE '$tag-%' OR email LIKE '%@phone.invalid')");
    }
    foreach ($savedSettings as $k => $v) {
        $conn->prepare("DELETE FROM system_settings WHERE setting_key = ?")->execute([$k]);
        if ($v !== false) $conn->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?)")->execute([$k, $v]);
    }
    foreach ($counters as $k => $v) $conn->prepare("UPDATE ticket_number_counters SET next_value = ? WHERE counter_key = ?")->execute([$v, $k]);
    if ($paused) $conn->exec("UPDATE workflows SET is_active = 1 WHERE id IN (" . implode(',', array_map('intval', $paused)) . ")");
    $backOn = $paused ? (int)$conn->query("SELECT COUNT(*) FROM workflows WHERE is_active = 1 AND id IN (" . implode(',', array_map('intval', $paused)) . ")")->fetchColumn() : 0;
    $left = (int)$conn->query("SELECT (SELECT COUNT(*) FROM users WHERE email LIKE '$tag-%') + (SELECT COUNT(*) FROM analysts WHERE username LIKE '$tag-%')
                                    + (SELECT COUNT(*) FROM tickets WHERE id IN (" . ($tickets ? implode(',', $tickets) : '0') . "))
                                    + (SELECT COUNT(*) FROM threecx_calls WHERE agent_email LIKE '$tag-%')")->fetchColumn();
    echo "\nCleanup: " . ($left === 0 ? 'everything this run made is gone' : "$left ROWS LEFT - tag $tag")
       . ($paused ? '; ' . $backOn . ' of ' . count($paused) . ' paused workflow(s) back on' : '') . "\n";
}

/** True when the install can encrypt (no key = values stored readable, by design). */
function webhookEncryptionAvailableSafe(): bool {
    try { getEncryptionKey(); return true; } catch (Throwable $e) { return false; }
}

echo "\n" . str_repeat('=', 72) . "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
