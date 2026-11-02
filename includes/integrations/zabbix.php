<?php
/**
 * Zabbix → FreeITSM: monitoring problems become incidents, by themselves.
 *
 * Zabbix calls api/external/zabbix/alert.php through a webhook "media type" whose
 * script the settings page (System → Integrations → Zabbix) hands out ready to
 * paste. Each call is one event:
 *
 *   PROBLEM   (event_value 1, a problem nobody has a ticket for)  → a new incident:
 *             priority from the severity map, the CMDB item named like the host
 *             linked, and the company taken from that item where there is one.
 *   REPEAT    (event_value 1, same problem_id, ticket still open)  → a message on
 *             that ticket's thread. No second ticket.
 *   RECOVERY  (event_value 0)  → a "recovered" message, and the ticket moved to the
 *             status chosen in settings (Resolved by default).
 *
 * Every event received is written to `zabbix_events`, which the settings page
 * lists, so an administrator can see what arrived and what was done with it.
 *
 * Contributed by Carl Steynvaard (Open Resilience), October 2026, and rebuilt
 * here to FreeITSM's own conventions: settings in system_settings with the token
 * encrypted, tickets through TicketsService, the company from the CMDB item, and
 * a named "Zabbix" actor rather than borrowing an analyst's account.
 *
 * ⚠️ Zabbix sends the ORIGINAL problem's {EVENT.ID} with its recovery, which is
 * what lets a recovery find the ticket its problem opened. That is the pairing
 * tests/zabbix/run.php proves against a real Zabbix.
 */

require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/../encryption.php';
require_once __DIR__ . '/../tenancy.php';
require_once __DIR__ . '/../service_context.php';
require_once __DIR__ . '/../services/tickets.php';

/** Zabbix's own severity names, by number ({EVENT.NSEVERITY}). */
const ZABBIX_SEVERITIES = [0 => 'Not classified', 1 => 'Information', 2 => 'Warning', 3 => 'Average', 4 => 'High', 5 => 'Disaster'];

/** Who a ticket is "from" when nobody has said: an address that can never receive mail. */
const ZABBIX_DEFAULT_REQUESTER = 'zabbix@monitoring.invalid';

/** Is the event log there? (Database Verification creates it.) */
function zabbixReady(PDO $conn): bool
{
    try { $conn->query("SELECT 1 FROM zabbix_events LIMIT 1"); return true; }
    catch (Exception) { return false; }
}

/** One system_settings value, or $default when it was never saved. */
function zabbixSetting(PDO $conn, string $key, string $default = ''): string
{
    $s = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ?");
    $s->execute([$key]);
    $v = $s->fetchColumn();
    return ($v === false || $v === null) ? $default : (string)$v;
}

function zabbixPutSetting(PDO $conn, string $key, string $value): void
{
    $conn->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?)
                    ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")->execute([$key, $value]);
}

/** The shared secret Zabbix sends, decrypted; '' when none has been made yet. */
function zabbixToken(PDO $conn): string
{
    $v = zabbixSetting($conn, 'zabbix_token');
    return $v === '' ? '' : (string)decryptValue($v);
}

/** Makes a new token (the old one stops working at once) and returns it. */
function zabbixRotateToken(PDO $conn): string
{
    $token = bin2hex(random_bytes(24));
    zabbixPutSetting($conn, 'zabbix_token', encryptValue($token));
    return $token;
}

/** Configured = a token exists. Read by the Integrations index card. */
function zabbixConfigured(PDO $conn): bool
{
    return zabbixToken($conn) !== '';
}

/**
 * The starting severity → priority map, from THIS install's priorities.
 *
 * Priorities are ordered by display_order (a higher number is more urgent - the
 * same reading as Watchtower's "needs attention"). Disaster gets the most urgent,
 * High the next, Average the default priority, and Warning, Information and Not
 * classified the least urgent. No priority NAME is assumed: the contributed
 * version looked for "P1 - Critical", which no stock install has.
 *
 * @return array<int,int> severity (0-5) => ticket_priorities.id
 */
function zabbixDefaultPriorityMap(PDO $conn): array
{
    $rows = $conn->query("SELECT id, is_default FROM ticket_priorities WHERE is_active = 1 ORDER BY display_order, id")->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) return [];
    $ids = array_map(fn($r) => (int)$r['id'], $rows);
    $d = 0;
    foreach ($rows as $i => $r) if ((int)$r['is_default'] === 1) { $d = $i; break; }
    $last = count($ids) - 1;
    return [
        0 => $ids[0], 1 => $ids[0], 2 => $ids[0],
        3 => $ids[$d],
        4 => $ids[max($d, $last - 1)],
        5 => $ids[$last],
    ];
}

/** The status a recovery moves a ticket to by default: one called Resolved, else the first closed one. */
function zabbixDefaultResolveStatusId(PDO $conn): ?int
{
    $id = $conn->query("SELECT id FROM ticket_statuses WHERE is_active = 1 AND LOWER(name) = 'resolved' ORDER BY id LIMIT 1")->fetchColumn();
    if (!$id) $id = $conn->query("SELECT id FROM ticket_statuses WHERE is_active = 1 AND is_closed = 1 ORDER BY display_order, id LIMIT 1")->fetchColumn();
    return $id ? (int)$id : null;
}

/**
 * Every setting, with its default filled in where nothing was saved. The token is
 * NOT included - the API returns it separately, to administrators.
 */
function zabbixSettings(PDO $conn): array
{
    $map = json_decode(zabbixSetting($conn, 'zabbix_priority_map'), true);
    $def = zabbixDefaultPriorityMap($conn);
    $priorityMap = [];
    foreach (array_keys(ZABBIX_SEVERITIES) as $sev) {
        $priorityMap[$sev] = isset($map[$sev]) && (int)$map[$sev] > 0 ? (int)$map[$sev] : ($def[$sev] ?? null);
    }
    $resolve = zabbixSetting($conn, 'zabbix_resolve_status_id');
    $tenant  = zabbixSetting($conn, 'zabbix_tenant_id');
    return [
        'requester_email'   => zabbixSetting($conn, 'zabbix_requester_email', ZABBIX_DEFAULT_REQUESTER),
        'requester_name'    => zabbixSetting($conn, 'zabbix_requester_name', 'Zabbix monitoring'),
        'tenant_id'         => $tenant !== '' ? (int)$tenant : getDefaultTenantId($conn),
        'priority_map'      => $priorityMap,
        'resolve_status_id' => $resolve === '' ? zabbixDefaultResolveStatusId($conn) : ((int)$resolve ?: null),   // 0 = leave the status alone
        'team_id'           => ($t = (int)zabbixSetting($conn, 'zabbix_team_id')) > 0 ? $t : null,
        'origin_id'         => ($o = (int)zabbixSetting($conn, 'zabbix_origin_id')) > 0 ? $o : null,
        'link_cmdb'         => zabbixSetting($conn, 'zabbix_link_cmdb', '1') === '1',
    ];
}

/**
 * Saves the settings an administrator sent. Every id is checked to exist, so a
 * stale form cannot point new incidents at a deleted priority or team.
 *
 * @param array $in  requester_email, requester_name, tenant_id, priority_map {0..5: id}, resolve_status_id (0 = leave alone), team_id, origin_id, link_cmdb
 * @throws InvalidArgumentException  with a sentence to show
 */
function zabbixSaveSettings(PDO $conn, array $in): void
{
    $exists = function (string $table, $id) use ($conn): bool {
        $s = $conn->prepare("SELECT 1 FROM {$table} WHERE id = ?");
        $s->execute([(int)$id]);
        return (bool)$s->fetchColumn();
    };
    $email = strtolower(trim((string)($in['requester_email'] ?? '')));
    if ($email === '') $email = ZABBIX_DEFAULT_REQUESTER;
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('The requester must be an email address.');
    $name = trim((string)($in['requester_name'] ?? '')) ?: 'Zabbix monitoring';

    $tenant = (int)($in['tenant_id'] ?? 0);
    if ($tenant > 0 && !$exists('tenants', $tenant)) throw new InvalidArgumentException('That company no longer exists.');

    $map = [];
    foreach (array_keys(ZABBIX_SEVERITIES) as $sev) {
        $pid = (int)($in['priority_map'][$sev] ?? 0);
        if ($pid > 0 && !$exists('ticket_priorities', $pid)) throw new InvalidArgumentException('A chosen priority no longer exists.');
        if ($pid > 0) $map[$sev] = $pid;
    }
    $status = (int)($in['resolve_status_id'] ?? 0);
    if ($status > 0 && !$exists('ticket_statuses', $status)) throw new InvalidArgumentException('That status no longer exists.');
    $team = (int)($in['team_id'] ?? 0);
    if ($team > 0 && !$exists('teams', $team)) throw new InvalidArgumentException('That team no longer exists.');
    $origin = (int)($in['origin_id'] ?? 0);
    if ($origin > 0 && !$exists('ticket_origins', $origin)) throw new InvalidArgumentException('That origin no longer exists.');

    zabbixPutSetting($conn, 'zabbix_requester_email', $email);
    zabbixPutSetting($conn, 'zabbix_requester_name', mb_substr($name, 0, 100));
    zabbixPutSetting($conn, 'zabbix_tenant_id', $tenant > 0 ? (string)$tenant : '');
    zabbixPutSetting($conn, 'zabbix_priority_map', json_encode($map));
    zabbixPutSetting($conn, 'zabbix_resolve_status_id', (string)$status);
    zabbixPutSetting($conn, 'zabbix_team_id', $team > 0 ? (string)$team : '');
    zabbixPutSetting($conn, 'zabbix_origin_id', $origin > 0 ? (string)$origin : '');
    zabbixPutSetting($conn, 'zabbix_link_cmdb', !empty($in['link_cmdb']) ? '1' : '0');
}

/** A severity sent as a number (0-5) or as Zabbix's name for it; anything else reads as Average. */
function zabbixSeverity($raw): int
{
    if (is_numeric($raw)) return max(0, min(5, (int)$raw));
    $i = array_search(strtolower(trim((string)$raw)), array_map('strtolower', ZABBIX_SEVERITIES), true);
    return $i === false ? 3 : (int)$i;
}

/**
 * The CMDB item a Zabbix host is, by name: the full name, then the short name
 * (web01 for web01.example.com). Case does not matter.
 *
 * TRAP: never guess between companies. On a multi-company install two companies
 *   may each have a "web01"; linking either would put one company's alert on the
 *   other's server. Several matches → the one in the configured company if there
 *   is exactly one, otherwise none.
 *
 * @return ?array{id:int, tenant_id:?int}
 */
function zabbixFindCmdbItem(PDO $conn, string $host, ?int $preferTenant): ?array
{
    $short = strtolower(explode('.', $host)[0]);
    $s = $conn->prepare("SELECT id, tenant_id FROM cmdb_objects WHERE LOWER(name) IN (?, ?) AND COALESCE(is_planned, 0) = 0");
    $s->execute([strtolower($host), $short]);
    $rows = $s->fetchAll(PDO::FETCH_ASSOC);
    if (count($rows) > 1 && $preferTenant !== null) {
        $rows = array_values(array_filter($rows, fn($r) => $r['tenant_id'] !== null && (int)$r['tenant_id'] === $preferTenant));
    }
    if (count($rows) !== 1) return null;
    return ['id' => (int)$rows[0]['id'], 'tenant_id' => $rows[0]['tenant_id'] !== null ? (int)$rows[0]['tenant_id'] : null];
}

/** The open ticket this problem already has, or null (none, or it was closed, deleted or merged since). */
function zabbixOpenTicketFor(PDO $conn, string $problemId): ?int
{
    $s = $conn->prepare("SELECT ticket_id FROM zabbix_events WHERE problem_id = ? AND ticket_id IS NOT NULL ORDER BY id DESC LIMIT 1");
    $s->execute([$problemId]);
    $tid = $s->fetchColumn();
    if (!$tid) return null;
    $t = $conn->prepare("SELECT t.id FROM tickets t LEFT JOIN ticket_statuses s ON s.id = t.status_id
                          WHERE t.id = ? AND t.deleted_datetime IS NULL AND t.merged_into_id IS NULL AND COALESCE(s.is_closed, 0) = 0");
    $t->execute([(int)$tid]);
    return $t->fetchColumn() ? (int)$tid : null;
}

/**
 * Adds an inbound message to a ticket's thread - the way a repeat or a recovery is
 * shown, like a monitoring system that emails the desk. Stored as escaped text,
 * because everything in it came from outside.
 */
function zabbixAddThreadMessage(PDO $conn, int $ticketId, string $subject, string $text, string $fromEmail, string $fromName): void
{
    $conn->prepare(
        "INSERT INTO emails (subject, from_address, from_name, to_recipients, received_datetime,
                             body_preview, body_content, body_type, has_attachments, importance,
                             is_read, ticket_id, is_initial, direction)
         VALUES (?, ?, ?, '', UTC_TIMESTAMP(), ?, ?, 'html', 0, 'normal', 0, ?, 0, 'Inbound')"
    )->execute([mb_substr($subject, 0, 500), $fromEmail, $fromName, mb_substr($text, 0, 200), nl2br(htmlspecialchars($text)), $ticketId]);
    $conn->prepare("UPDATE tickets SET updated_datetime = UTC_TIMESTAMP() WHERE id = ?")->execute([$ticketId]);
    try {
        require_once __DIR__ . '/../search/indexer.php';
        searchIndexTicket($conn, $ticketId);
    } catch (Throwable) { /* search catches up on the next re-index */ }
}

function zabbixLog(PDO $conn, array $e, ?int $ticketId, string $action, string $detail = ''): void
{
    $conn->prepare("INSERT INTO zabbix_events (problem_id, event_value, host, trigger_name, severity, ticket_id, action, detail, received_datetime)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())")
         ->execute([$e['problem_id'], $e['value'], mb_substr($e['host'], 0, 255), mb_substr($e['trigger'], 0, 500),
                    $e['severity'], $ticketId, $action, mb_substr($detail, 0, 255)]);
}

/**
 * Handles one event from Zabbix. The caller has already checked the token.
 *
 * @param array $p  problem_id (or event_id), event_value (1 problem / 0 recovery), severity, host, trigger, message, url
 * @return array{ok:bool, action:string, ticket_id:?int, error?:string}
 */
function zabbixHandleEvent(PDO $conn, array $p): array
{
    $e = [
        'problem_id' => mb_substr(trim((string)($p['problem_id'] ?? $p['event_id'] ?? '')), 0, 64),
        'value'      => (string)($p['event_value'] ?? '1') === '0' ? 0 : 1,
        'host'       => trim((string)($p['host'] ?? '')),
        'trigger'    => trim((string)($p['trigger'] ?? '')) ?: 'Zabbix problem',
        'severity'   => zabbixSeverity($p['severity'] ?? ''),
        'message'    => mb_substr(trim((string)($p['message'] ?? '')), 0, 8000),
        'url'        => trim((string)($p['url'] ?? '')),
    ];
    if ($e['problem_id'] === '' || $e['host'] === '') {
        return ['ok' => false, 'action' => 'rejected', 'ticket_id' => null, 'error' => 'problem_id and host are required'];
    }
    // Only a web link is shown as one; anything else is dropped rather than echoed.
    if ($e['url'] !== '' && !preg_match('#^https?://#i', $e['url'])) $e['url'] = '';

    $cfg = zabbixSettings($conn);
    $ctx = ActorContext::system('Zabbix');
    $sevName = ZABBIX_SEVERITIES[$e['severity']];
    $text = ($e['value'] === 0 ? 'RECOVERED' : 'PROBLEM') . ": {$e['trigger']}\n"
          . "Host: {$e['host']}\nSeverity: {$sevName}"
          . ($e['message'] !== '' ? "\n\n{$e['message']}" : '')
          . ($e['url'] !== '' ? "\n\nIn Zabbix: {$e['url']}" : '')
          . "\nZabbix problem ID: {$e['problem_id']}";

    // One problem at a time: Zabbix can send a problem and its first repeat close
    // together, and two requests racing here would open two tickets.
    $lock = 'freeitsm_zabbix_' . md5($e['problem_id']);
    $conn->query("SELECT GET_LOCK(" . $conn->quote($lock) . ", 10)");
    try {
        $ticketId = zabbixOpenTicketFor($conn, $e['problem_id']);

        if ($e['value'] === 0) {
            if (!$ticketId) {
                zabbixLog($conn, $e, null, 'recovery_unmatched');
                return ['ok' => true, 'action' => 'recovery_unmatched', 'ticket_id' => null];
            }
            zabbixAddThreadMessage($conn, $ticketId, "[Zabbix] Recovered: {$e['host']}: {$e['trigger']}", $text, $cfg['requester_email'], 'Zabbix');
            $detail = '';
            if ($cfg['resolve_status_id']) {
                try {
                    TicketsService::updateTicket($conn, $ctx, $ticketId, ['status_id' => $cfg['resolve_status_id']], true);
                } catch (Throwable $ex) {
                    // A closure rule (mandatory fields, a checklist) can refuse - the
                    // ticket stays open and the log says why.
                    $detail = 'status not changed: ' . $ex->getMessage();
                }
            }
            zabbixLog($conn, $e, $ticketId, 'resolved', $detail);
            return ['ok' => true, 'action' => 'resolved', 'ticket_id' => $ticketId];
        }

        if ($ticketId) {
            zabbixAddThreadMessage($conn, $ticketId, "[Zabbix] {$e['host']}: {$e['trigger']}", $text, $cfg['requester_email'], 'Zabbix');
            zabbixLog($conn, $e, $ticketId, 'updated');
            return ['ok' => true, 'action' => 'updated', 'ticket_id' => $ticketId];
        }

        // GH #217: no CMDB lookup or link while the CMDB is switched off.
        $ci = ($cfg['link_cmdb'] && moduleIsEnabled($conn, 'cmdb')) ? zabbixFindCmdbItem($conn, $e['host'], $cfg['tenant_id']) : null;
        // The company: the server's own, when the CMDB knows it - an alert about a
        // client's server belongs in that client's queue. Otherwise the setting.
        $tenantId = ($ci && $ci['tenant_id'] !== null) ? $ci['tenant_id'] : (int)$cfg['tenant_id'];
        $in = [
            'subject'         => mb_substr("[Zabbix] {$e['host']}: {$e['trigger']}", 0, 500),
            'description'     => $text,
            'requester_email' => $cfg['requester_email'],
            'requester_name'  => $cfg['requester_name'],
        ];
        if (!empty($cfg['priority_map'][$e['severity']])) $in['priority_id'] = $cfg['priority_map'][$e['severity']];
        if ($cfg['team_id'])   $in['assigned_team_id'] = $cfg['team_id'];
        if ($cfg['origin_id']) $in['origin_id'] = $cfg['origin_id'];
        $ticketId = TicketsService::createTicket($conn, $ctx, $tenantId, $in, null, 'Created from a Zabbix alert', 'monitoring');

        if ($ci) {
            $conn->prepare("INSERT INTO ticket_cmdb_objects (ticket_id, cmdb_object_id, created_datetime, created_by_analyst_id) VALUES (?, ?, UTC_TIMESTAMP(), NULL)")
                 ->execute([$ticketId, $ci['id']]);
        }
        zabbixLog($conn, $e, $ticketId, 'created', $ci ? 'linked to CMDB item ' . $ci['id'] : '');
        return ['ok' => true, 'action' => 'created', 'ticket_id' => $ticketId];
    } catch (Throwable $ex) {
        error_log('Zabbix alert: ' . $ex->getMessage());
        try { zabbixLog($conn, $e, $ticketId ?? null, 'error', $ex->getMessage()); } catch (Throwable) {}
        return ['ok' => false, 'action' => 'error', 'ticket_id' => null, 'error' => 'Could not process the alert'];
    } finally {
        $conn->query("SELECT RELEASE_LOCK(" . $conn->quote($lock) . ")");
    }
}

/**
 * The webhook media type's script, as Zabbix runs it. ONE copy: the settings page
 * shows it, the import file carries it, and tests/zabbix/run.php gives it to a
 * real Zabbix - so what is tested is what is handed out.
 */
function zabbixMediaTypeScript(): string
{
    return <<<'JS'
var p = JSON.parse(value);
var req = new HttpRequest();
if (p.http_proxy) { req.setProxy(p.http_proxy); }
req.addHeader('Content-Type: application/json');
var resp = req.post(p.endpoint, JSON.stringify({
    token: p.token, problem_id: p.event_id, event_value: p.event_value,
    severity: p.severity, host: p.host, trigger: p.trigger,
    message: p.message, url: p.url
}));
if (req.getStatus() !== 200) {
    throw 'FreeITSM answered ' + req.getStatus() + ': ' + resp;
}
return 'OK';
JS;
}

/**
 * The media type's parameters, in order: [name, value]. The values in braces are
 * Zabbix macros, filled in for each alert.
 *
 * @return array<int,array{0:string,1:string}>
 */
function zabbixMediaTypeParameters(string $endpoint, string $token): array
{
    return [
        ['endpoint', $endpoint], ['token', $token], ['event_id', '{EVENT.ID}'], ['event_value', '{EVENT.VALUE}'],
        ['severity', '{EVENT.NSEVERITY}'], ['host', '{HOST.NAME}'], ['trigger', '{EVENT.NAME}'],
        ['message', '{ALERT.MESSAGE}'], ['url', '{$ZABBIX.URL}/tr_events.php?triggerid={TRIGGER.ID}&eventid={EVENT.ID}'],
        ['http_proxy', ''],
    ];
}

/**
 * The three message templates a webhook needs, or an action using it sends
 * nothing at all: problem, recovery, update. [operation_mode, subject, message].
 */
function zabbixMediaTypeTemplates(): array
{
    return [
        ['PROBLEM', 'Problem: {EVENT.NAME}',
         "Problem started at {EVENT.TIME} on {EVENT.DATE}\nProblem name: {EVENT.NAME}\nHost: {HOST.NAME}\nSeverity: {EVENT.SEVERITY}\nOperational data: {EVENT.OPDATA}"],
        ['RECOVERY', 'Resolved: {EVENT.NAME}',
         "Problem resolved at {EVENT.RECOVERY.TIME} on {EVENT.RECOVERY.DATE}\nProblem name: {EVENT.NAME}\nProblem duration: {EVENT.DURATION}\nHost: {HOST.NAME}"],
        ['UPDATE', 'Updated: {EVENT.NAME}',
         "{USER.FULLNAME} {EVENT.UPDATE.ACTION} the problem at {EVENT.UPDATE.DATE} {EVENT.UPDATE.TIME}.\n{EVENT.UPDATE.MESSAGE}"],
    ];
}

/**
 * The whole media type as a Zabbix 7.0 import file (Alerts → Media types → Import):
 * parameters with this install's token, the script, the three message templates,
 * enabled. The shape is Zabbix's own configuration.export output, and
 * tests/zabbix/run.php imports this file into a real Zabbix to prove it.
 *
 * ⚠️ It carries the token. It is only ever handed to an administrator.
 */
function zabbixMediaTypeYaml(string $endpoint, string $token): string
{
    $q = fn(string $s): string => "'" . str_replace("'", "''", $s) . "'";   // YAML single-quoted scalar
    $block = function (string $text, int $indent): string {                // YAML literal block
        $pad = str_repeat(' ', $indent);
        return "|\n" . implode("\n", array_map(fn($l) => $l === '' ? '' : $pad . $l, explode("\n", $text))) . "\n";
    };
    $y  = "zabbix_export:\n  version: '7.0'\n  media_types:\n";
    $y .= "    - name: FreeITSM\n      type: WEBHOOK\n      parameters:\n";
    foreach (zabbixMediaTypeParameters($endpoint, $token) as [$name, $value]) {
        $y .= "        - name: {$name}\n" . ($value !== '' ? "          value: " . $q($value) . "\n" : '');
    }
    $y .= "      script: " . $block(zabbixMediaTypeScript(), 8);
    $y .= "      description: " . $q('Sends Zabbix problems, updates and recoveries to FreeITSM (System > Integrations > Zabbix).') . "\n";
    $y .= "      message_templates:\n";
    foreach (zabbixMediaTypeTemplates() as [$mode, $subject, $message]) {
        $y .= "        - event_source: TRIGGERS\n          operation_mode: {$mode}\n          subject: " . $q($subject) . "\n"
            . "          message: " . $block($message, 12);
    }
    return $y;
}
