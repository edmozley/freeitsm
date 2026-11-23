<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * The Zabbix integration against a REAL Zabbix 7.0 (tests/zabbix/docker-compose.yml).
 *
 *   docker compose -f tests/zabbix/docker-compose.yml up -d     (once; give it a minute)
 *   php tests/zabbix/run.php
 *
 * tests/zabbix-integration.php proves the endpoint against events shaped as the
 * media type sends them. This proves the media type itself: that Zabbix's own
 * macros, its webhook runner and - the part nothing else can show - its pairing of
 * a recovery with its problem ({EVENT.ID}) all line up with FreeITSM.
 *
 * Through Zabbix's API it creates a host named like a temporary CMDB item, a
 * trapper item, a Disaster trigger on it, the "FreeITSM" webhook media type using
 * THE SCRIPT ON THE SETTINGS PAGE (read from system/integrations/zabbix.php, not
 * retyped), and an action for problems, updates and recoveries. Then it drives it:
 * a value of 97 raises the problem, an acknowledgement sends an update, a value of
 * 40 recovers it - and checks FreeITSM at each step.
 *
 * Everything it makes, on both sides, is removed at the end. FreeITSM's Zabbix
 * settings, ticket counter and auto-acknowledge workflows (paused, so nobody is
 * emailed) are put back.
 */

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/integrations/zabbix.php';

$ZBX_API  = getenv('ZABBIX_API') ?: 'http://localhost:8086/api_jsonrpc.php';
$CONTAINER = getenv('ZABBIX_SERVER_CONTAINER') ?: 'freeitsm-zabbix-test-zbx-server-1';
// How Zabbix, inside Docker, reaches this FreeITSM on the host.
$ENDPOINT = getenv('ZABBIX_TO_FREEITSM') ?: 'http://host.docker.internal/' . basename(dirname(__DIR__, 2)) . '/api/external/zabbix/alert.php';

$pass = 0; $fail = 0;
function ok(string $what, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  PASS  {$what}\n"; }
    else       { $fail++; echo "  FAIL  {$what}" . ($detail !== '' ? "  <- {$detail}" : '') . "\n"; }
}

$zbxAuth = null;
/** One Zabbix API call. Throws on an API error, with Zabbix's own words. */
function zbx(string $method, array $params) {
    global $ZBX_API, $zbxAuth;
    $ch = curl_init($ZBX_API);
    $h = ['Content-Type: application/json-rpc'];
    // Zabbix refuses the header on these two.
    if ($zbxAuth && !in_array($method, ['user.login', 'apiinfo.version'], true)) $h[] = 'Authorization: Bearer ' . $zbxAuth;
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_POST => true, CURLOPT_HTTPHEADER => $h,
        CURLOPT_POSTFIELDS => json_encode(['jsonrpc' => '2.0', 'method' => $method, 'params' => $params, 'id' => 1])]);
    $raw = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    $r = json_decode((string)$raw, true);
    if (!is_array($r)) throw new RuntimeException("Zabbix API did not answer ($method): HTTP $code $err " . substr((string)$raw, 0, 200));
    if (isset($r['error'])) throw new RuntimeException("$method: " . ($r['error']['data'] ?? $r['error']['message']));
    return $r['result'];
}
/** Pushes a value into the trapper item, from inside the server container. */
function push(string $host, int $value): void {
    global $CONTAINER;
    $out = shell_exec('docker exec ' . escapeshellarg($CONTAINER) . ' zabbix_sender -z 127.0.0.1 -s ' . escapeshellarg($host) . ' -k zz.disk -o ' . $value . ' 2>&1');
    if (!str_contains((string)$out, 'processed: 1')) throw new RuntimeException("zabbix_sender: $out");
}
/** Waits (up to $secs) for FreeITSM to log an event of this action for this host. */
function waitFor(PDO $conn, string $host, string $action, int $secs = 90): ?array {
    $deadline = time() + $secs;
    $q = $conn->prepare("SELECT * FROM zabbix_events WHERE host = ? AND action = ? ORDER BY id DESC LIMIT 1");
    do {
        $q->execute([$host, $action]);
        if ($row = $q->fetch(PDO::FETCH_ASSOC)) return $row;
        sleep(2);
    } while (time() < $deadline);
    return null;
}

$conn = connectToDatabase();
if (!zabbixReady($conn)) { echo "Run Database Verification first (zabbix_events is missing).\n"; exit(1); }
$tag  = 'zz' . bin2hex(random_bytes(3));
$host = "$tag-db01";
$BASE = rtrim(getenv('FREEITSM_URL') ?: 'http://localhost/' . basename(dirname(__DIR__, 2)) . '/', '/') . '/';

// FreeITSM snapshots.
$settingKeys = ['zabbix_token', 'zabbix_requester_email', 'zabbix_requester_name', 'zabbix_tenant_id', 'zabbix_priority_map',
                'zabbix_resolve_status_id', 'zabbix_team_id', 'zabbix_origin_id', 'zabbix_link_cmdb'];
$savedSettings = [];
foreach ($settingKeys as $k) { $s = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ?"); $s->execute([$k]); $savedSettings[$k] = $s->fetchColumn(); }
$counters = $conn->query("SELECT counter_key, next_value FROM ticket_number_counters")->fetchAll(PDO::FETCH_KEY_PAIR);
$paused = $conn->query("SELECT id FROM workflows WHERE is_active = 1 AND trigger_event = 'ticket.created'")->fetchAll(PDO::FETCH_COLUMN);
$made = ['group' => null, 'host' => null, 'mediatype' => null, 'action' => null, 'user' => null, 'cmdb' => null, 'ticket' => null];

echo "\nThe Zabbix integration against a real Zabbix\n" . str_repeat('=', 72) . "\n";
try {
    $zbxAuth = zbx('user.login', ['username' => 'Admin', 'password' => 'zabbix']);
    $ver = zbx('apiinfo.version', []);
    echo "(Zabbix $ver at $ZBX_API; it reaches FreeITSM at $ENDPOINT)\n";

    if ($paused) $conn->exec("UPDATE workflows SET is_active = 0 WHERE id IN (" . implode(',', array_map('intval', $paused)) . ")");
    $token = zabbixRotateToken($conn);
    zabbixSaveSettings($conn, ['requester_email' => "$tag@monitoring.invalid", 'requester_name' => 'ZZ Zabbix', 'link_cmdb' => true,
                               'resolve_status_id' => zabbixDefaultResolveStatusId($conn), 'priority_map' => zabbixDefaultPriorityMap($conn)]);
    $class = (int)$conn->query("SELECT MIN(id) FROM cmdb_classes")->fetchColumn();
    $conn->prepare("INSERT INTO cmdb_objects (class_id, name, created_datetime, updated_datetime) VALUES (?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())")->execute([$class, $host]);
    $made['cmdb'] = (int)$conn->lastInsertId();

    echo "\nSetting Zabbix up, through its API\n";
    $made['group'] = zbx('hostgroup.create', ['name' => "FreeITSM test $tag"])['groupids'][0];
    $made['host'] = zbx('host.create', ['host' => $host, 'groups' => [['groupid' => $made['group']]]])['hostids'][0];
    zbx('item.create', ['hostid' => $made['host'], 'name' => 'ZZ disk used %', 'key_' => 'zz.disk', 'type' => 2, 'value_type' => 3]);
    zbx('trigger.create', ['description' => 'Disk almost full on {HOST.NAME}', 'expression' => "last(/$host/zz.disk)>90", 'priority' => 5]);
    // The media type is IMPORTED from the file the settings page's "Download for
    // Zabbix import" button gives an administrator - fetched from the real API, so
    // the whole run goes through exactly what a person would import. Renamed for
    // this run, so it can never touch a "FreeITSM" media type somebody made.
    $dir = rtrim(ini_get('session.save_path') ?: 'c:/wamp64/tmp', '/\\');
    $sid = 'zzzbx' . bin2hex(random_bytes(5));
    $admin = (int)$conn->query("SELECT id FROM analysts WHERE is_active = 1 AND is_admin = 1 ORDER BY id LIMIT 1")->fetchColumn();
    file_put_contents("$dir/sess_$sid", 'analyst_id|i:' . $admin . ';analyst_name|s:7:"ZZ test";');
    $ch = curl_init($BASE . 'api/system/zabbix_settings.php?action=export&endpoint=' . rawurlencode($ENDPOINT));
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIE => 'PHPSESSID=' . $sid, CURLOPT_HEADER => true]);
    $resp = (string)curl_exec($ch); $hsz = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    @unlink("$dir/sess_$sid");
    $headers = substr($resp, 0, $hsz); $yaml = substr($resp, $hsz);
    ok('the settings page\'s download is a Zabbix import file, offered as a download',
       str_starts_with($yaml, "zabbix_export:\n  version: '7.0'") && stripos($headers, 'attachment; filename="freeitsm_zabbix_media_type.yaml"') !== false, substr($yaml, 0, 80));
    ok('...carrying this install\'s token and the address given', str_contains($yaml, "value: '$token'") && str_contains($yaml, "value: '$ENDPOINT'"));
    $yaml = str_replace("    - name: FreeITSM\n", "    - name: 'FreeITSM $tag'\n", $yaml);
    zbx('configuration.import', ['format' => 'yaml', 'source' => $yaml, 'rules' => ['mediaTypes' => ['createMissing' => true, 'updateExisting' => false]]]);
    $mt = zbx('mediatype.get', ['filter' => ['name' => "FreeITSM $tag"], 'output' => ['mediatypeid', 'status', 'type', 'parameters'],   // 7.0: parameters is a field, not a select
                                'selectMessageTemplates' => 'extend']);
    $made['mediatype'] = $mt[0]['mediatypeid'] ?? null;
    ok('Zabbix imported it: a webhook, ENABLED, with all ten parameters and the three message templates',
       $made['mediatype'] && (int)$mt[0]['type'] === 4 && (int)$mt[0]['status'] === 0
       && count($mt[0]['parameters']) === 10 && count($mt[0]['message_templates']) === 3, json_encode($mt[0] ?? null));
    // The alerts go to a user of their own, NOT Admin: Zabbix never sends an update
    // notification to the user who made the update, and the run acknowledges as Admin.
    $superAdmins = zbx('usergroup.get', ['output' => ['usrgrpid'], 'filter' => ['name' => 'Zabbix administrators']]);
    $made['user'] = zbx('user.create', [
        'username' => "zz-freeitsm-$tag", 'passwd' => 'Zz-' . bin2hex(random_bytes(12)) . '!', 'roleid' => '3',
        'usrgrps' => [['usrgrpid' => $superAdmins[0]['usrgrpid']]],
        'medias' => [['mediatypeid' => $made['mediatype'], 'sendto' => 'freeitsm', 'active' => 0, 'severity' => 63, 'period' => '1-7,00:00-24:00']],
    ])['userids'][0];
    $send = ['operationtype' => 0, 'opmessage' => ['default_msg' => 1, 'mediatypeid' => $made['mediatype']], 'opmessage_usr' => [['userid' => $made['user']]]];
    $made['action'] = zbx('action.create', [
        'name' => "FreeITSM test $tag", 'eventsource' => 0, 'status' => 0, 'esc_period' => '1h',
        'filter' => ['evaltype' => 0, 'conditions' => [['conditiontype' => 1, 'operator' => 0, 'value' => $made['host']]]],
        'operations' => [$send], 'recovery_operations' => [$send], 'update_operations' => [$send],
    ])['actionids'][0];
    ok('Zabbix accepted the host, the trigger, the alert user and the action', true);
    // The server loads new configuration on a timer; ask it to now.
    shell_exec('docker exec ' . escapeshellarg($CONTAINER) . ' zabbix_server -R config_cache_reload 2>&1');
    sleep(5);

    echo "\nA real problem\n";
    push($host, 97);
    $ev = waitFor($conn, $host, 'created');
    if (!$ev) {
        // Say WHY, from Zabbix's side: did the trigger fire, did the action run, what did the webhook say.
        $items = zbx('item.get', ['hostids' => $made['host'], 'output' => ['key_', 'lastvalue', 'state', 'error']]);
        $probs = zbx('problem.get', ['hostids' => $made['host'], 'output' => ['eventid', 'name', 'severity']]);
        $alerts = zbx('alert.get', ['actionids' => [$made['action']], 'output' => ['status', 'error', 'retries', 'message']]);
        echo "  item:    " . json_encode($items) . "\n  problem: " . json_encode($probs) . "\n  alerts:  " . json_encode($alerts) . "\n";
    }
    ok('Zabbix\'s alert reached FreeITSM and raised an incident', $ev !== null && !empty($ev['ticket_id']), 'nothing arrived in 90 seconds - see the Zabbix state above');
    if ($ev) {
        $made['ticket'] = (int)$ev['ticket_id'];
        ok('...with Zabbix\'s own host name, problem name and severity', $ev['host'] === $host && $ev['trigger_name'] === "Disk almost full on $host" && (int)$ev['severity'] === 5,
           json_encode([$ev['host'], $ev['trigger_name'], $ev['severity']]));
        $t = $conn->prepare("SELECT t.priority_id, (SELECT COUNT(*) FROM ticket_cmdb_objects l WHERE l.ticket_id = t.id AND l.cmdb_object_id = ?) AS linked FROM tickets t WHERE t.id = ?");
        $t->execute([$made['cmdb'], $made['ticket']]); $row = $t->fetch(PDO::FETCH_ASSOC);
        ok('...at the Disaster priority, linked to the CMDB item', (int)$row['priority_id'] === (int)zabbixSettings($conn)['priority_map'][5] && (int)$row['linked'] === 1, json_encode($row));
        $b = $conn->prepare("SELECT body_content FROM emails WHERE ticket_id = ? ORDER BY id LIMIT 1"); $b->execute([$made['ticket']]);
        ok('...carrying the media type\'s message (the {ALERT.MESSAGE} macro resolved)', str_contains((string)$b->fetchColumn(), 'Problem started at'));   // the imported problem template

        echo "\nAn update (an acknowledgement in Zabbix)\n";
        zbx('event.acknowledge', ['eventids' => [$ev['problem_id']], 'action' => 4, 'message' => "ZZ looking at it $tag"]);
        $up = waitFor($conn, $host, 'updated', 60);
        if (!$up) {
            echo "  alerts:  " . json_encode(zbx('alert.get', ['actionids' => [$made['action']], 'output' => ['status', 'error', 'message', 'p_eventid']])) . "\n";
            echo "  freeitsm: " . json_encode($conn->query("SELECT action, event_value, detail FROM zabbix_events WHERE host = " . $conn->quote($host))->fetchAll(PDO::FETCH_ASSOC)) . "\n";
        }
        ok('the update went on the same ticket', $up !== null && (int)$up['ticket_id'] === $made['ticket'], json_encode($up));

        echo "\nA real recovery\n";
        push($host, 40);
        $rec = waitFor($conn, $host, 'resolved');
        ok('Zabbix\'s recovery found the ticket its problem opened ({EVENT.ID} pairs them)', $rec !== null && (int)$rec['ticket_id'] === $made['ticket'] && $rec['problem_id'] === $ev['problem_id'],
           json_encode($rec));
        $s = $conn->prepare("SELECT status_id FROM tickets WHERE id = ?"); $s->execute([$made['ticket']]);
        ok('...and resolved it', (int)$s->fetchColumn() === (int)zabbixDefaultResolveStatusId($conn));
        $n = $conn->prepare("SELECT COUNT(*) FROM tickets WHERE subject LIKE ? AND deleted_datetime IS NULL"); $n->execute(["[Zabbix] $host:%"]);
        ok('...and only one ticket was ever raised', (int)$n->fetchColumn() === 1);
    }
} catch (Throwable $e) {
    ok('the run completed', false, $e->getMessage());
} finally {
    // Zabbix: everything it was given.
    try {
        if ($made['action'])    zbx('action.delete', [$made['action']]);
        if ($made['user'])      zbx('user.delete', [$made['user']]);
        if ($made['mediatype']) zbx('mediatype.delete', [$made['mediatype']]);
        if ($made['host'])      zbx('host.delete', [$made['host']]);
        if ($made['group'])     zbx('hostgroup.delete', [$made['group']]);
    } catch (Throwable $e) { echo "  (Zabbix cleanup: {$e->getMessage()})\n"; }
    // FreeITSM.
    $ids = $conn->prepare("SELECT DISTINCT ticket_id FROM zabbix_events WHERE host = ? AND ticket_id IS NOT NULL"); $ids->execute([$host]);
    foreach ($ids->fetchAll(PDO::FETCH_COLUMN) as $id) {
        foreach (['ticket_cmdb_objects', 'emails', 'ticket_audit', 'search_documents'] as $tbl) $conn->prepare("DELETE FROM $tbl WHERE ticket_id = ?")->execute([$id]);
        $conn->prepare("DELETE FROM tickets WHERE id = ? AND subject LIKE '[Zabbix] %'")->execute([$id]);
    }
    $conn->prepare("DELETE FROM zabbix_events WHERE host = ?")->execute([$host]);
    if ($made['cmdb']) $conn->prepare("DELETE FROM cmdb_objects WHERE id = ? AND name LIKE 'zz%'")->execute([$made['cmdb']]);
    $conn->prepare("DELETE FROM users WHERE email = ?")->execute(["$tag@monitoring.invalid"]);
    foreach ($savedSettings as $k => $v) {
        $conn->prepare("DELETE FROM system_settings WHERE setting_key = ?")->execute([$k]);
        if ($v !== false) $conn->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?)")->execute([$k, $v]);
    }
    foreach ($counters as $k => $v) $conn->prepare("UPDATE ticket_number_counters SET next_value = ? WHERE counter_key = ?")->execute([$v, $k]);
    if ($paused) $conn->exec("UPDATE workflows SET is_active = 1 WHERE id IN (" . implode(',', array_map('intval', $paused)) . ")");
    $left = (int)$conn->query("SELECT (SELECT COUNT(*) FROM zabbix_events WHERE host = " . $conn->quote($host) . ") + (SELECT COUNT(*) FROM tickets WHERE subject LIKE " . $conn->quote("[Zabbix] $host:%") . ")")->fetchColumn();
    echo "\nCleanup: " . ($left === 0 ? 'everything this run made is gone, in Zabbix and in FreeITSM' : "$left ROWS LEFT - tag $tag") . "\n";
}

echo "\n" . str_repeat('=', 72) . "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
