<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * Microsoft Teams channel alerts (System → Integrations → Microsoft Teams) and
 * the "Microsoft Teams (Workflows card)" webhook format underneath them.
 *
 *   php tests/msteams-alerts.php
 *
 * Proves: the card format renders an Adaptive Card whose button is the ticket's
 * address ({{link}}), falls back to FreeITSM's front page when there is no
 * ticket, and stays valid JSON whatever the message holds; the new merge codes
 * are offered where they resolve; saving a channel makes one workflow per ticked
 * alert, with the right trigger and conditions and the address encrypted; it
 * updates them in place, removes unticked ones, recreates one deleted by hand
 * and never touches a workflow it did not make; the alerts fire on exactly what
 * they should (run through the real engine and webhook queue); "Test" posts a
 * real request to a local stand-in for Teams; and the settings API is
 * administrators-only and never returns an address.
 *
 * Runs against the real database. Everything is named ZZ and removed at the end:
 * the channels, their workflows, the runs and queued deliveries those workflows
 * made. No event is dispatched (that would notify real people): alerts are run
 * one workflow at a time with WorkflowEngine::manualFire(), which applies the
 * conditions and queues the card exactly as a real event does. Queued cards go
 * to an address that can never resolve (.invalid) and are deleted. Needs the app
 * at http://localhost/<folder>/ (or FREEITSM_URL) for the API section.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/integrations/msteams.php';

$pass = 0; $fail = 0;
function ok(string $what, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  PASS  {$what}\n"; }
    else       { $fail++; echo "  FAIL  {$what}" . ($detail !== '' ? "  <- {$detail}" : '') . "\n"; }
}

$conn = connectToDatabase();
if (!msteamsReady($conn)) { echo "Run Database Verification first (msteams_channels is missing).\n"; exit(1); }
$base = rtrim(getenv('FREEITSM_URL') ?: 'http://localhost/' . basename(dirname(__DIR__)) . '/', '/') . '/';
$tag = 'zz' . bin2hex(random_bytes(3));
$channels = []; $server = null; $serverLog = null; $handMade = null;
$admin = (int)$conn->query("SELECT id FROM analysts WHERE is_active = 1 AND is_admin = 1 ORDER BY id LIMIT 1")->fetchColumn();
$hook = "https://$tag.invalid/workflows/abc?sig=SECRET";

/** The workflows a channel made, keyed by alert. */
$wfs = function (int $channelId) use ($conn): array {
    $s = $conn->prepare("SELECT m.alert_key, w.* FROM msteams_channel_workflows m JOIN workflows w ON w.id = m.workflow_id WHERE m.channel_id = ?");
    $s->execute([$channelId]);
    $out = [];
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) $out[$r['alert_key']] = $r;
    return $out;
};
$card = fn(string $body) => json_decode($body, true)['attachments'][0]['content'] ?? [];

echo "\nMicrosoft Teams alerts\n" . str_repeat('=', 72) . "\n";
try {
    echo "\nThe card format\n";
    ok('it is a built-in format, next to the old Teams one (which is unchanged)',
       isset(WorkflowEngine::webhookFormats()['teams_workflows']) && str_contains(WorkflowEngine::webhookFormats()['teams']['body_template'], 'MessageCard'));
    $msg = "Line \"one\" with a \\ backslash\n\n**two** <b>three</b>";
    $req = WorkflowEngine::buildWebhookRequest(['preset' => 'teams_workflows', 'url' => 'https://x.invalid/', 'message' => $msg],
                                               WorkflowEngine::enrichWithLinks($conn, ['ticket' => ['id' => 4242]]));
    $c = $card($req['body']);
    ok('it renders an Adaptive Card - valid JSON whatever the message holds (quotes, backslashes, newlines)',
       ($c['type'] ?? '') === 'AdaptiveCard' && ($c['body'][0]['text'] ?? '') === $msg, $req['body']);
    ok('...with an "Open in FreeITSM" button on the ticket\'s full address',
       ($c['actions'][0]['type'] ?? '') === 'Action.OpenUrl' && preg_match('#^https?://.+/tickets/\?ticket_id=4242$#', (string)($c['actions'][0]['url'] ?? '')), (string)($c['actions'][0]['url'] ?? ''));
    $req2 = WorkflowEngine::buildWebhookRequest(['preset' => 'teams_workflows', 'url' => 'https://x.invalid/', 'message' => 'x'],
                                                WorkflowEngine::enrichWithLinks($conn, ['incident' => ['id' => 7, 'title' => 'Mail down']]));
    $u2 = (string)($card($req2['body'])['actions'][0]['url'] ?? '');
    ok('with no ticket, the button is FreeITSM\'s front page - never empty (Teams refuses an empty button)',
       $u2 !== '' && preg_match('#^https?://#', $u2) && !str_contains($u2, 'ticket_id'), $u2);
    $vars = array_column(WorkflowEngine::availableVariables('ticket.created'), 'path');
    ok('{{link}}, {{app.url}}, {{ticket.url}} and the team are offered on "A ticket is created"',
       !array_diff(['link', 'app.url', 'ticket.url', 'ticket.assigned_team_id'], $vars), implode(',', $vars));
    $vars2 = array_column(WorkflowEngine::availableVariables('ticket.team_assigned'), 'path');
    ok('...and "A ticket is assigned to a team" offers the team it went to', in_array('team_id', $vars2, true) && in_array('team_name', $vars2, true), implode(',', $vars2));
    ok('a new ticket\'s event now carries its team (ticketDispatchCreated)', str_contains((string)file_get_contents(__DIR__ . '/../includes/ticket_events.php'), "'assigned_team_id'    => \$t['assigned_team_id']"));

    echo "\nSaving a channel makes its workflows\n";
    $prios = $conn->query("SELECT id FROM ticket_priorities WHERE is_active = 1 ORDER BY display_order DESC, id")->fetchAll(PDO::FETCH_COLUMN);
    $top = (int)$prios[0]; $second = (int)($prios[1] ?? $prios[0]); $low = (int)end($prios);
    $teams = array_map('intval', $conn->query("SELECT id FROM teams ORDER BY id LIMIT 2")->fetchAll(PDO::FETCH_COLUMN));
    if (count($teams) < 2) {
        foreach ([1, 2] as $i) { $conn->prepare("INSERT INTO teams (name) VALUES (?)")->execute(["$tag team $i"]); $teams[] = (int)$conn->lastInsertId(); }
        $teams = array_slice($teams, -2);
    }
    $all = array_keys(MSTEAMS_ALERTS);
    $r = msteamsSaveChannel($conn, $admin, ['name' => "$tag L2", 'webhook_url' => $hook, 'alerts' => $all,
                                            'priority_threshold_id' => $second, 'team_ids' => [$teams[0]]]);
    $ch = $channels[] = $r['id'];
    $w = $wfs($ch);
    ok('one workflow per ticked alert (9)', $r['alerts_on'] === 9 && count($w) === 9, (string)count($w));
    $cond = fn(string $k) => json_decode($w[$k]['conditions'] ?? '[]', true);
    $band = msteamsPrioritiesAtOrAbove($conn, $second);
    ok('"at or above" a priority is that one and every more urgent one, by order - not by name',
       in_array((string)$top, $band, true) && in_array((string)$second, $band, true) && ($low === $second || !in_array((string)$low, $band, true)), implode(',', $band));
    ok('new urgent ticket: ticket.created, priority in the band', ($w['priority_new']['trigger_event'] ?? '') === 'ticket.created'
       && $cond('priority_new') === [['field' => 'ticket.priority_id', 'op' => 'in', 'value' => $band]], json_encode($cond('priority_new')));
    ok('raised: into the band from outside it', $cond('priority_raised') === [['field' => 'new_priority_id', 'op' => 'in', 'value' => $band],
                                                                              ['field' => 'old_priority_id', 'op' => 'not_in', 'value' => $band]]);
    ok('team, new ticket: on the team the created event carries (ticket.assigned_team_id)',
       $cond('team_new') === [['field' => 'ticket.assigned_team_id', 'op' => 'in', 'value' => [(string)$teams[0]]]]);
    ok('team, hand-over: on the team_assigned event\'s own team_id', ($w['team_handover']['trigger_event'] ?? '') === 'ticket.team_assigned'
       && $cond('team_handover') === [['field' => 'team_id', 'op' => 'in', 'value' => [(string)$teams[0]]]]);
    $act = json_decode($w['sla_breached']['actions'] ?? '[]', true)[0] ?? [];
    ok('each sends the Teams card, to the channel\'s address - stored encrypted, never readable',
       ($act['type'] ?? '') === 'send_webhook' && ($act['args']['preset'] ?? '') === 'teams_workflows'
       && !str_contains((string)$w['sla_breached']['actions'], 'SECRET') && webhookDecrypt($act['args']['url'] ?? '') === $hook);
    $raw = (string)$conn->query("SELECT webhook_url FROM msteams_channels WHERE id = $ch")->fetchColumn();
    ok('the channel\'s own copy of the address is encrypted too', $raw !== '' && !str_contains($raw, 'SECRET') && decryptValue($raw) === $hook);
    ok('...and the page\'s list says only that there is one', !array_key_exists('webhook_url', msteamsChannels($conn)[array_search($ch, array_column(msteamsChannels($conn), 'id'))] ?? ['webhook_url' => 1]));

    $ids = array_map(fn($x) => (int)$x['id'], $w);
    $conn->prepare("INSERT INTO workflows (name, description, trigger_event, conditions, actions, is_active) VALUES (?, ?, 'ticket.created', '[]', '[]', 0)")
         ->execute(["$tag hand-made", "Posts a card to the Microsoft Teams channel \"$tag L2\"."]);
    $handMade = (int)$conn->lastInsertId();
    $conn->exec("DELETE FROM workflows WHERE id = " . $ids['sla_warning']);
    $r = msteamsSaveChannel($conn, $admin, ['id' => $ch, 'name' => "$tag L2", 'webhook_url' => '',
                                            'alerts' => ['priority_new', 'team_handover', 'sla_warning'], 'priority_threshold_id' => $top, 'team_ids' => $teams]);
    $w2 = $wfs($ch);
    ok('saving again keeps the ticked ones IN PLACE (same workflow ids), with the new settings',
       ($w2['priority_new']['id'] ?? 0) == $ids['priority_new'] && ($w2['team_handover']['id'] ?? 0) == $ids['team_handover']
       && json_decode($w2['priority_new']['conditions'], true)[0]['value'] === msteamsPrioritiesAtOrAbove($conn, $top));
    ok('...removes the unticked ones', count($w2) === 3 && !(int)$conn->query("SELECT COUNT(*) FROM workflows WHERE id IN (" . $ids['every_new'] . ',' . $ids['sla_breached'] . ")")->fetchColumn());
    ok('...recreates one somebody deleted by hand', isset($w2['sla_warning']) && (int)$w2['sla_warning']['id'] !== $ids['sla_warning']);
    ok('...and never touches a workflow it did not make, even one that reads like its own',
       (int)$conn->query("SELECT COUNT(*) FROM workflows WHERE id = $handMade")->fetchColumn() === 1);
    ok('leaving the address blank kept the saved one', decryptValue((string)$conn->query("SELECT webhook_url FROM msteams_channels WHERE id = $ch")->fetchColumn()) === $hook);

    $bad = function (array $in) use ($conn, $admin, $tag): string {
        try { msteamsSaveChannel($conn, $admin, $in + ['name' => "$tag bad"]); return 'saved'; } catch (InvalidArgumentException $e) { return $e->getMessage(); }
    };
    ok('an urgent-ticket alert with no priority is refused, saying so', str_contains($bad(['alerts' => ['priority_new']]), 'priority'));
    ok('a team alert with no team is refused', str_contains($bad(['alerts' => ['team_new']]), 'team'));
    ok('an address that is not https:// is refused', str_contains($bad(['webhook_url' => 'http://x.invalid/']), 'https://'));
    ok('a channel with no name is refused', str_contains($bad(['name' => '']), 'name'));
    $leftBad = (int)$conn->query("SELECT COUNT(*) FROM msteams_channels WHERE name = '$tag bad'")->fetchColumn();
    ok('...and none of them left anything behind', $leftBad === 0, (string)$leftBad);

    echo "\nThe alerts fire on what they should (real engine, real queue)\n";
    $w2 = $wfs($ch);
    $fire = function (int $wid, array $payload) use ($conn): array {
        $e = WorkflowEngine::manualFire($wid, $payload);
        $d = $conn->prepare("SELECT request_body FROM webhook_deliveries WHERE execution_id = ?");
        $d->execute([(int)($e['id'] ?? $e['execution_id'] ?? 0)]);
        return [$e['status'] ?? '?', (string)$d->fetchColumn()];
    };
    $tk = fn(array $x) => ['ticket' => $x + ['id' => 990000001, 'subject' => "$tag subject", 'requester_email' => 'zz@example.invalid']];
    [$st, $body] = $fire((int)$w2['priority_new']['id'], $tk(['priority_id' => $top]));
    $c = $card($body);
    ok('a new ticket at the top priority: sent, as a card with the ticket\'s button', $st === 'success' && str_contains((string)($c['body'][0]['text'] ?? ''), "$tag subject")
       && str_ends_with((string)($c['actions'][0]['url'] ?? ''), 'tickets/?ticket_id=990000001'), "$st " . substr($body, 0, 200));
    [$st] = $fire((int)$w2['priority_new']['id'], $tk(['priority_id' => $low]));
    ok('a new ticket below it: not sent', $top === $low || $st === 'skipped', $st);
    [$st] = $fire((int)$w2['team_handover']['id'], $tk(['priority_id' => $low]) + ['team_id' => $teams[1]]);
    ok('handed to one of the channel\'s teams: sent', $st === 'success', $st);
    [$st] = $fire((int)$w2['team_handover']['id'], $tk(['priority_id' => $low]) + ['team_id' => 987654]);
    ok('handed to another team: not sent', $st === 'skipped', $st);

    echo "\nTest\n";
    $port = random_int(18100, 18900);
    $serverLog = sys_get_temp_dir() . "/$tag-teams.log";
    $router = sys_get_temp_dir() . "/$tag-teams-router.php";
    file_put_contents($router, '<?php $b = file_get_contents("php://input"); file_put_contents(' . var_export($serverLog, true) . ', $b);'
        . ' if (strpos($_SERVER["REQUEST_URI"], "refuse") !== false) { http_response_code(400); echo "BadRequest: no attachments"; exit; }'
        . ' http_response_code(202);');
    $devNull = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
    $server = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", $router], [['pipe', 'r'], ['file', $devNull, 'w'], ['file', $devNull, 'w']], $pipes);
    usleep(600000);
    $conn->prepare("UPDATE msteams_channels SET webhook_url = ? WHERE id = ?")->execute([encryptValue("http://127.0.0.1:$port/hook"), $ch]);
    $t = msteamsSendTest($conn, $ch, 'ZZ tester');
    $got = json_decode((string)@file_get_contents($serverLog), true);
    ok('Test posts a real request, and says Teams accepted it', $t['ok'] && $t['status'] === 202, json_encode($t));
    ok('...a card saying the channel is connected, and who sent it', str_contains((string)($got['attachments'][0]['content']['body'][0]['text'] ?? ''), 'FreeITSM is connected')
       && str_contains((string)$got['attachments'][0]['content']['body'][0]['text'], 'ZZ tester'));
    $conn->prepare("UPDATE msteams_channels SET webhook_url = ? WHERE id = ?")->execute([encryptValue("http://127.0.0.1:$port/refuse"), $ch]);
    $t = msteamsSendTest($conn, $ch, 'ZZ tester');
    ok('a refusal is reported with Teams\' own words', !$t['ok'] && str_contains($t['detail'], '400') && str_contains($t['detail'], 'BadRequest'), json_encode($t));

    echo "\nThe settings API\n";
    $dir = rtrim(ini_get('session.save_path') ?: 'c:/wamp64/tmp', '/\\');
    $csrf = bin2hex(random_bytes(32));
    $sess = function (int $analystId) use ($dir, $csrf): string {
        $sid = 'zzmst' . bin2hex(random_bytes(5));
        file_put_contents("$dir/sess_$sid", 'analyst_id|i:' . $analystId . ';analyst_name|s:7:"ZZ test";csrf_token|s:64:"' . $csrf . '";');
        return $sid;
    };
    $api = function (string $sid, string $method, ?array $body, string $qs = '') use ($base, $csrf): array {
        $c = curl_init($base . 'api/system/msteams_settings.php' . $qs);
        curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_COOKIE => 'PHPSESSID=' . $sid,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-CSRF-Token: ' . $csrf]]);
        if ($body !== null) curl_setopt($c, CURLOPT_POSTFIELDS, json_encode($body));
        $raw = curl_exec($c); $code = (int)curl_getinfo($c, CURLINFO_HTTP_CODE); curl_close($c);
        return [$code, json_decode((string)$raw, true) ?: [], (string)$raw];
    };
    $plain = (int)$conn->query("SELECT id FROM analysts WHERE COALESCE(is_active, 1) = 1 AND COALESCE(is_admin, 0) = 0 ORDER BY id LIMIT 1")->fetchColumn();
    $sA = $sess($admin); $sP = $sess($plain);
    [$c, $d, $raw] = $api($sA, 'GET', null, '?action=get');
    ok('an administrator reads the channels', $c === 200 && !empty($d['success']) && in_array($ch, array_column($d['channels'] ?? [], 'id'), true), "HTTP $c");
    ok('...and the answer holds no address at all', !str_contains($raw, '127.0.0.1') && !str_contains($raw, 'webhook_url'));
    [$c, $d2] = $api($sP, 'GET', null, '?action=get');
    ok('a non-administrator is refused', $c === 403 || empty($d2['success']), "HTTP $c");
    [$c] = $api($sA, 'GET', null, '?action=delete&id=' . $ch);
    ok('a delete sent as a GET (a link) is refused, and the channel stays', $c === 405 && (int)$conn->query("SELECT COUNT(*) FROM msteams_channels WHERE id = $ch")->fetchColumn() === 1, "HTTP $c");
    [$c, $d3] = $api($sA, 'POST', ['action' => 'save', 'id' => 0, 'name' => "$tag via API", 'webhook_url' => "https://$tag.invalid/w2",
                                   'alerts' => ['every_new'], 'priority_threshold_id' => 0, 'team_ids' => []]);
    if (!empty($d3['id'])) $channels[] = (int)$d3['id'];
    ok('POSITIVE CONTROL: the page\'s save works', !empty($d3['success']) && ($d3['alerts_on'] ?? 0) === 1, json_encode($d3));
    $made = $wfs((int)($d3['id'] ?? 0));
    [$c, $d4] = $api($sA, 'POST', ['action' => 'delete', 'id' => (int)($d3['id'] ?? 0)]);
    ok('delete removes the channel and the workflows it made', !empty($d4['success'])
       && !(int)$conn->query("SELECT COUNT(*) FROM workflows WHERE id = " . (int)($made['every_new']['id'] ?? 0))->fetchColumn()
       && !(int)$conn->query("SELECT COUNT(*) FROM msteams_channels WHERE id = " . (int)($d3['id'] ?? 0))->fetchColumn());
    @unlink("$dir/sess_$sA"); @unlink("$dir/sess_$sP");
} finally {
    if ($server) proc_terminate($server);
    @unlink(sys_get_temp_dir() . "/$tag-teams-router.php");
    if ($serverLog) @unlink($serverLog);
    $wfIds = [];
    foreach ($channels as $id) {
        $s = $conn->prepare("SELECT workflow_id FROM msteams_channel_workflows WHERE channel_id = ?"); $s->execute([$id]);
        $wfIds = array_merge($wfIds, array_map('intval', $s->fetchAll(PDO::FETCH_COLUMN)));
    }
    // Runs and queued cards of every workflow these channels made, deleted or not.
    $names = $conn->prepare("SELECT id FROM workflow_executions WHERE workflow_name LIKE ?");
    $names->execute(["Teams: % → $tag%"]);
    $execIds = array_map('intval', $names->fetchAll(PDO::FETCH_COLUMN));
    if ($execIds) {
        $in = implode(',', $execIds);
        $conn->exec("DELETE FROM webhook_deliveries WHERE execution_id IN ($in)");
        $conn->exec("DELETE FROM workflow_executions WHERE id IN ($in)");
    }
    foreach ($channels as $id) { try { msteamsDeleteChannel($conn, $id); } catch (Throwable $e) {} }
    if ($handMade) $conn->exec("DELETE FROM workflows WHERE id = $handMade");
    $conn->prepare("DELETE FROM teams WHERE name LIKE ?")->execute(["$tag team %"]);
    $left = (int)$conn->query("SELECT (SELECT COUNT(*) FROM msteams_channels WHERE name LIKE '$tag%')
                                    + (SELECT COUNT(*) FROM workflows WHERE name LIKE 'Teams: % → $tag%' OR name LIKE '$tag%')
                                    + (SELECT COUNT(*) FROM workflow_executions WHERE workflow_name LIKE 'Teams: % → $tag%')
                                    + (SELECT COUNT(*) FROM webhook_deliveries WHERE url LIKE '%$tag%')
                                    + (SELECT COUNT(*) FROM teams WHERE name LIKE '$tag team %')")->fetchColumn();
    echo "\nCleanup: " . ($left === 0 ? 'everything this run made is gone' : "$left ROWS LEFT - tag $tag") . "\n";
}

echo "\n" . str_repeat('=', 72) . "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
