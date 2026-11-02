<?php
/**
 * 3CX → FreeITSM: the phone system knows who is calling, and every call is kept
 * on the caller's ticket.
 *
 * 3CX links to a CRM through an XML "template" (3CX Admin Console → Integrations
 * → CRM). The settings page hands out one ready to upload (threecxTemplateXml()),
 * and 3CX then calls api/external/3cx/crm.php for two things:
 *
 *   LOOKUP  while a call rings: who is this number? → the one active requester
 *           with that phone or mobile, and a FreeITSM address 3CX opens for the
 *           analyst (tickets/caller.php: their open ticket, or their record).
 *   REPORT  when a call ends: an internal note on the caller's ticket, written
 *           as the analyst who took the call (matched on 3CX's agent email), or a
 *           new ticket when the caller has none and the settings allow it.
 *
 * Every report received is written to `threecx_calls`, which the settings page
 * lists, so an administrator can see what arrived and what was done with it.
 *
 * Contributed by Carl Steynvaard (Open Resilience), October 2026, and rebuilt
 * here to FreeITSM's own conventions: settings in system_settings with the token
 * encrypted, the token in a header rather than the address, tickets and notes
 * through TicketsService, and never a guess between two people or two tickets.
 *
 * TRAP: a call note needs a real analyst. ticket_notes.analyst_id is a foreign key
 *   to analysts, so the system actor (id 0) cannot write one - the contributed
 *   version did. On an install with the key (every fresh one) the note is
 *   refused and the call fails; on an older install that never got the key it
 *   is saved with no author at all. Hence the agent email match, and the
 *   fallback analyst for a 3CX user who is not one.
 */

require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/../encryption.php';
require_once __DIR__ . '/../tenancy.php';
require_once __DIR__ . '/../service_context.php';
require_once __DIR__ . '/../services/tickets.php';
require_once __DIR__ . '/../public_url.php';
require_once __DIR__ . '/../phone_dial.php';   // click-to-call: the dial address is saved with these settings

/** 3CX's own words for a call (the template's [CallType]). */
const THREECX_CALL_TYPES = ['Inbound', 'Outbound', 'Missed', 'Notanswered'];

/** Which ticket a call goes on: the setting's choices. */
const THREECX_TICKET_RULES = ['latest_open', 'only_one', 'log_only'];

/** A caller nobody knows is filed as "<digits>@phone.invalid" - an address that can never receive mail. */
const THREECX_PLACEHOLDER_DOMAIN = 'phone.invalid';

/** Is the call log there? (Database Verification creates it.) */
function threecxReady(PDO $conn): bool
{
    try { $conn->query("SELECT 1 FROM threecx_calls LIMIT 1"); return true; }
    catch (Exception) { return false; }
}

/** One system_settings value, or $default when it was never saved. */
function threecxSetting(PDO $conn, string $key, string $default = ''): string
{
    $s = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ?");
    $s->execute([$key]);
    $v = $s->fetchColumn();
    return ($v === false || $v === null) ? $default : (string)$v;
}

function threecxPutSetting(PDO $conn, string $key, string $value): void
{
    $conn->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?)
                    ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")->execute([$key, $value]);
}

/** The shared secret 3CX sends, decrypted; '' when none has been made yet. */
function threecxToken(PDO $conn): string
{
    $v = threecxSetting($conn, 'threecx_token');
    return $v === '' ? '' : (string)decryptValue($v);
}

/** Makes a new token (the old one stops working at once) and returns it. */
function threecxRotateToken(PDO $conn): string
{
    $token = bin2hex(random_bytes(24));
    threecxPutSetting($conn, 'threecx_token', encryptValue($token));
    return $token;
}

/** Configured = a token exists. Read by the Integrations index card. */
function threecxConfigured(PDO $conn): bool
{
    return threecxToken($conn) !== '';
}

/** The seeded "Phone" ticket origin, if this install still has it. */
function threecxDefaultOriginId(PDO $conn): ?int
{
    $id = $conn->query("SELECT id FROM ticket_origins WHERE LOWER(name) = 'phone' AND COALESCE(is_active, 1) = 1
                         ORDER BY (tenant_id IS NULL) DESC, id LIMIT 1")->fetchColumn();
    return $id ? (int)$id : null;
}

/**
 * Every setting, with its default filled in where nothing was saved. The token is
 * NOT included - the API returns it separately, to administrators.
 */
function threecxSettings(PDO $conn): array
{
    $rule = threecxSetting($conn, 'threecx_ticket_rule', 'latest_open');
    $origin = threecxSetting($conn, 'threecx_origin_id');
    $tenant = threecxSetting($conn, 'threecx_tenant_id');
    return [
        'ticket_rule'         => in_array($rule, THREECX_TICKET_RULES, true) ? $rule : 'latest_open',
        'create_ticket'       => threecxSetting($conn, 'threecx_create_ticket', '1') === '1',
        'create_unknown'      => threecxSetting($conn, 'threecx_create_unknown', '0') === '1',
        'fallback_analyst_id' => ($a = (int)threecxSetting($conn, 'threecx_fallback_analyst_id')) > 0 ? $a : null,
        'tenant_id'           => $tenant !== '' ? (int)$tenant : getDefaultTenantId($conn),
        'team_id'             => ($t = (int)threecxSetting($conn, 'threecx_team_id')) > 0 ? $t : null,
        'origin_id'           => $origin === '' ? threecxDefaultOriginId($conn) : ((int)$origin ?: null),   // 0 = none
        'dial_url'            => phoneDialTemplate($conn),
    ];
}

/**
 * Saves the settings an administrator sent. Every id is checked to exist, so a
 * stale form cannot point calls at a deleted analyst or team.
 *
 * @param array $in  ticket_rule, create_ticket, create_unknown, fallback_analyst_id, tenant_id, team_id, origin_id (0 = none), dial_url
 * @throws InvalidArgumentException  with a sentence to show
 */
function threecxSaveSettings(PDO $conn, array $in): void
{
    $exists = function (string $table, $id, string $extra = '') use ($conn): bool {
        $s = $conn->prepare("SELECT 1 FROM {$table} WHERE id = ?{$extra}");
        $s->execute([(int)$id]);
        return (bool)$s->fetchColumn();
    };
    $rule = (string)($in['ticket_rule'] ?? 'latest_open');
    if (!in_array($rule, THREECX_TICKET_RULES, true)) throw new InvalidArgumentException('Choose which ticket a call goes on.');
    $analyst = (int)($in['fallback_analyst_id'] ?? 0);
    if ($analyst > 0 && !$exists('analysts', $analyst, ' AND is_active = 1')) throw new InvalidArgumentException('That analyst is no longer active.');
    $tenant = (int)($in['tenant_id'] ?? 0);
    if ($tenant > 0 && !$exists('tenants', $tenant)) throw new InvalidArgumentException('That company no longer exists.');
    $team = (int)($in['team_id'] ?? 0);
    if ($team > 0 && !$exists('teams', $team)) throw new InvalidArgumentException('That team no longer exists.');
    $origin = (int)($in['origin_id'] ?? 0);
    if ($origin > 0 && !$exists('ticket_origins', $origin)) throw new InvalidArgumentException('That origin no longer exists.');
    $dial = trim((string)($in['dial_url'] ?? ''));
    if ($dial !== '' && !phoneDialTemplateValid($dial)) {
        throw new InvalidArgumentException('The dial address must contain {number} and start with https://, tel:, callto: or sip:');
    }

    threecxPutSetting($conn, 'threecx_ticket_rule', $rule);
    threecxPutSetting($conn, 'threecx_create_ticket', !empty($in['create_ticket']) ? '1' : '0');
    threecxPutSetting($conn, 'threecx_create_unknown', !empty($in['create_unknown']) ? '1' : '0');
    threecxPutSetting($conn, 'threecx_fallback_analyst_id', $analyst > 0 ? (string)$analyst : '');
    threecxPutSetting($conn, 'threecx_tenant_id', $tenant > 0 ? (string)$tenant : '');
    threecxPutSetting($conn, 'threecx_team_id', $team > 0 ? (string)$team : '');
    threecxPutSetting($conn, 'threecx_origin_id', (string)$origin);
    threecxPutSetting($conn, 'phone_dial_url', $dial);
}

/* ── Who is calling ──────────────────────────────────────────────────────── */

/**
 * Do these two numbers belong to the same phone? The number part only (an "ext
 * 12" after it is not the number - phoneNumberPart()), digits only, leading
 * zeros dropped, and then one must END with the other, the shorter at least 7
 * digits. So "07700 900123", "+44 7700 900123" and a 3CX that sends only the
 * last 9 digits all agree, while an extension (3-4 digits) never matches anyone.
 *
 * ⚠️ Looser than messagingFindUsersByPhone(), which wants the digits EQUAL: a
 * phone system sends whatever its trunk gives it - national, international, or
 * cut to the number of digits set in 3CX - and equality would miss most real
 * callers. The cost is that one national number could, in principle, match the
 * same digits in another country; that comes out as two matches, and two
 * matches are never guessed between.
 */
function threecxNumbersMatch(string $a, string $b): bool
{
    $a = ltrim(preg_replace('/\D+/', '', phoneNumberPart($a)), '0');
    $b = ltrim(preg_replace('/\D+/', '', phoneNumberPart($b)), '0');
    if (strlen($a) > strlen($b)) [$a, $b] = [$b, $a];
    return strlen($a) >= 7 && str_ends_with($b, $a);
}

/**
 * Every active requester whose phone or mobile is this number. Across every
 * company: one phone system answers for all of them. Placeholders made for
 * unknown callers are never candidates.
 *
 * A full scan rather than an indexed lookup: phone numbers are free text and
 * rarely normalised at entry (the same reasoning as messagingFindUsersByPhone()).
 *
 * @return array<int,array{id:int,display_name:string,email:?string,phone:?string,mobile:?string,tenant_id:?int}>
 */
function threecxFindCallers(PDO $conn, string $number): array
{
    if (strlen(ltrim(preg_replace('/\D+/', '', phoneNumberPart($number)), '0')) < 7) return [];
    $s = $conn->prepare("SELECT id, COALESCE(NULLIF(display_name, ''), email) AS display_name, email, phone, mobile, tenant_id
                           FROM users
                          WHERE ((phone IS NOT NULL AND phone <> '') OR (mobile IS NOT NULL AND mobile <> ''))
                            AND (is_active = 1 OR is_active IS NULL)
                            AND (email IS NULL OR email NOT LIKE ?)");
    $s->execute(['%@' . THREECX_PLACEHOLDER_DOMAIN]);
    $found = [];
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if (threecxNumbersMatch((string)$r['phone'], $number) || threecxNumbersMatch((string)$r['mobile'], $number)) {
            $r['id'] = (int)$r['id'];
            $r['tenant_id'] = $r['tenant_id'] !== null ? (int)$r['tenant_id'] : null;
            $found[] = $r;
        }
    }
    return $found;
}

/**
 * The answer to 3CX's "who is this?": the caller as 3CX wants them, or none.
 * Two people with the number (a switchboard, a shared desk) is none - 3CX then
 * shows an unknown caller rather than the wrong name.
 *
 * Only what 3CX shows: name, email, company, phones and the address it opens.
 * No ticket subjects - anyone holding the token could otherwise read them.
 *
 * @return array{contacts: list<array<string,string>>}
 */
function threecxLookup(PDO $conn, string $number): array
{
    $callers = threecxFindCallers($conn, $number);
    if (count($callers) !== 1) return ['contacts' => []];
    $u = $callers[0];
    $company = '';
    if (isMultiTenant($conn)) {
        $c = $conn->prepare("SELECT name FROM tenants WHERE id = ?");
        $c->execute([$u['tenant_id'] ?? getDefaultTenantId($conn)]);
        $company = (string)($c->fetchColumn() ?: '');
    }
    return ['contacts' => [[
        'id'         => (string)$u['id'],
        'first_name' => (string)$u['display_name'],
        'last_name'  => '',
        'email'      => (string)$u['email'],
        'company'    => $company,
        // 3CX insists one phone it is given back IS the number it searched for.
        'phone'      => $number,
        'mobile'     => (string)$u['mobile'],
        'url'        => publicAbsoluteUrl($conn, 'tickets/caller.php?user_id=' . $u['id']),
    ]]];
}

/**
 * The caller's open tickets, most recently touched first. The ones they are the
 * requester of - not merged away, not deleted, not in a closed status.
 *
 * @return list<int>
 */
function threecxOpenTickets(PDO $conn, int $userId): array
{
    $s = $conn->prepare("SELECT t.id FROM tickets t LEFT JOIN ticket_statuses s ON s.id = t.status_id
                          WHERE t.user_id = ? AND t.deleted_datetime IS NULL AND t.merged_into_id IS NULL
                            AND COALESCE(s.is_closed, 0) = 0
                          ORDER BY t.updated_datetime DESC, t.id DESC LIMIT 20");
    $s->execute([$userId]);
    return array_map('intval', $s->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * The ticket a call from this person goes on, by the setting: their latest open
 * ticket; only one when they have exactly one; or none (calls are only logged).
 * Also what tickets/caller.php opens, so the screen-pop and the note agree.
 *
 * @return array{0:?int,1:string,2:int}  the ticket, why there is none, and how many are open
 */
function threecxTicketFor(PDO $conn, int $userId, string $rule): array
{
    $open = threecxOpenTickets($conn, $userId);
    if ($rule === 'log_only') return [null, 'calls are only logged', count($open)];
    if (!$open) return [null, 'no open ticket', 0];
    if ($rule === 'only_one' && count($open) > 1) return [null, count($open) . ' open tickets - none chosen', count($open)];
    return [$open[0], '', count($open)];
}

/** The active analyst with this email, or null. Case does not matter. */
function threecxAnalystByEmail(PDO $conn, string $email): ?array
{
    $email = strtolower(trim($email));
    if ($email === '') return null;
    $s = $conn->prepare("SELECT id, full_name FROM analysts WHERE LOWER(email) = ? AND is_active = 1 LIMIT 2");
    $s->execute([$email]);
    $rows = $s->fetchAll(PDO::FETCH_ASSOC);
    return count($rows) === 1 ? ['id' => (int)$rows[0]['id'], 'name' => (string)$rows[0]['full_name']] : null;
}

function threecxAnalystById(PDO $conn, ?int $id): ?array
{
    if (!$id) return null;
    $s = $conn->prepare("SELECT id, full_name FROM analysts WHERE id = ? AND is_active = 1");
    $s->execute([$id]);
    $r = $s->fetch(PDO::FETCH_ASSOC);
    return $r ? ['id' => (int)$r['id'], 'name' => (string)$r['full_name']] : null;
}

/** "hh:mm:ss", "mm:ss" or plain seconds → seconds; null when it is none of those. */
function threecxDurationSeconds($raw): ?int
{
    $raw = trim((string)$raw);
    if ($raw === '') return null;
    if (ctype_digit($raw)) return (int)$raw;
    if (preg_match('/^(?:(\d+):)?(\d{1,2}):(\d{2})(?:\.\d+)?$/', $raw, $m)) return (int)$m[1] * 3600 + (int)$m[2] * 60 + (int)$m[3];
    return null;
}

/** 4m 05s, 1h 02m - how long a call was, for the note. */
function threecxDurationText(?int $s): string
{
    if ($s === null) return '';
    if ($s >= 3600) return sprintf('%dh %02dm', intdiv($s, 3600), intdiv($s % 3600, 60));
    return sprintf('%dm %02ds', intdiv($s, 60), $s % 60);
}

/**
 * What 3CX sent, made safe and plain: the call type one of 3CX's four, the start
 * time as UTC, a recording link only if it is a web link.
 */
function threecxNormaliseCall(array $p): array
{
    $type = ucfirst(strtolower(trim((string)($p['call_type'] ?? ''))));
    $start = null;
    $raw = trim((string)($p['start'] ?? ''));
    if ($raw !== '' && ($ts = strtotime($raw)) !== false) $start = gmdate('Y-m-d H:i:s', $ts);
    $rec = trim((string)($p['recording_url'] ?? ''));
    return [
        'call_id'     => mb_substr(trim((string)($p['call_id'] ?? '')), 0, 64),
        'call_type'   => in_array($type, THREECX_CALL_TYPES, true) ? $type : 'Inbound',
        'number'      => mb_substr(trim((string)($p['number'] ?? '')), 0, 50),
        'agent'       => mb_substr(trim((string)($p['agent'] ?? '')), 0, 50),
        'agent_email' => mb_substr(strtolower(trim((string)($p['agent_email'] ?? ''))), 0, 255),
        'agent_name'  => mb_substr(trim((string)($p['agent_name'] ?? '')), 0, 100),
        'duration'    => threecxDurationSeconds($p['duration'] ?? ''),
        'start'       => $start,
        'recording'   => preg_match('#^https?://\S+$#i', $rec) ? mb_substr($rec, 0, 1000) : '',
        'summary'     => mb_substr(trim((string)($p['summary'] ?? '')), 0, 4000),
    ];
}

/** The note's text. Plain text: notes are shown escaped, and all of this came from outside. */
function threecxCallText(array $c, ?array $caller, bool $authorIsAgent): string
{
    $who = $caller ? "{$caller['display_name']} ({$c['number']})" : $c['number'];
    $head = [
        'Inbound'     => "Phone call from {$who}",
        'Outbound'    => "Phone call to {$who}",
        'Missed'      => "Missed call from {$who}",
        'Notanswered' => "Unanswered call to {$who}",
    ][$c['call_type']];
    $agent = trim($c['agent_name'] . ($c['agent'] !== '' ? " (ext {$c['agent']})" : ''));
    $lines = [$head];
    if ($c['duration'] !== null && $c['duration'] > 0) $lines[] = 'Duration: ' . threecxDurationText($c['duration']);
    if ($agent !== '' && !$authorIsAgent) $lines[] = "Taken in 3CX by {$agent}";
    elseif ($agent !== '') $lines[] = "Agent: {$agent}";
    if ($c['recording'] !== '') $lines[] = "Recording: {$c['recording']}";
    if ($c['summary'] !== '') $lines[] = "\nSummary from 3CX:\n{$c['summary']}";
    $lines[] = "\nLogged by 3CX.";
    return implode("\n", $lines);
}

/**
 * Decides what a call does, without doing it. The "Try a call" panel shows this
 * as it is; threecxHandleCall() carries it out - so the try is the real decision.
 *
 * @return array{caller:?array, callers:int, author:?array, author_is_agent:bool, ticket_id:?int,
 *               create:?string, action:string, detail:string, tenant_id:?int}
 *         create: 'known' | 'unknown' | null.  action: 'noted' | 'created' | 'logged'
 */
function threecxPlanCall(PDO $conn, array $c, array $cfg): array
{
    $callers = threecxFindCallers($conn, $c['number']);
    $caller = count($callers) === 1 ? $callers[0] : null;
    $agent = threecxAnalystByEmail($conn, $c['agent_email']);
    $author = $agent ?? threecxAnalystById($conn, $cfg['fallback_analyst_id']);
    $plan = ['caller' => $caller, 'callers' => count($callers), 'author' => $author, 'author_is_agent' => $agent !== null,
             'ticket_id' => null, 'create' => null, 'action' => 'logged', 'detail' => '', 'tenant_id' => null];

    $openCount = 0;
    if ($caller) {
        [$plan['ticket_id'], $why, $openCount] = threecxTicketFor($conn, $caller['id'], $cfg['ticket_rule']);
    } elseif (count($callers) > 1) {
        $why = count($callers) . ' people have this number - none chosen';
    } else {
        $why = 'nobody has this number';
    }

    // A new ticket only for a call IN (answered or missed) from someone with no
    // open ticket at all: calling somebody back, or a caller who already has
    // tickets the rule would not choose between, is not a new request.
    $inbound = in_array($c['call_type'], ['Inbound', 'Missed'], true);
    if (!$plan['ticket_id'] && $inbound && $cfg['ticket_rule'] !== 'log_only' && $openCount === 0) {
        if ($caller && $cfg['create_ticket']) {
            $plan['create'] = 'known';
            $plan['tenant_id'] = $caller['tenant_id'] ?? getDefaultTenantId($conn);
        } elseif (!$caller && count($callers) === 0 && $cfg['create_unknown'] && strlen(preg_replace('/\D+/', '', $c['number'])) >= 7) {
            $plan['create'] = 'unknown';
            $plan['tenant_id'] = (int)$cfg['tenant_id'];
        }
    }

    if ($plan['ticket_id'] && $author) {
        $plan['action'] = 'noted';
    } elseif ($plan['ticket_id']) {
        $plan['detail'] = '3CX agent is not an analyst, and no fallback analyst is chosen';
    } elseif ($plan['create']) {
        $plan['action'] = 'created';
    } else {
        $plan['detail'] = $why;
    }
    return $plan;
}

function threecxLog(PDO $conn, string $key, array $c, array $plan, ?int $ticketId, string $action, string $detail): void
{
    $conn->prepare("INSERT INTO threecx_calls (call_key, call_type, number, agent, agent_email, analyst_id, user_id, ticket_id,
                                               duration_seconds, started_datetime, action, detail, received_datetime)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())")
         ->execute([$key, $c['call_type'], $c['number'], $c['agent'], $c['agent_email'], $plan['author']['id'] ?? null,
                    $plan['caller']['id'] ?? null, $ticketId, $c['duration'], $c['start'], $action, mb_substr($detail, 0, 255)]);
}

/**
 * Handles one call report from 3CX. The caller has already checked the token.
 *
 * 3CX gives no call id to a template, so a call is known by its number, start,
 * agent, length and type; the same report twice (3CX retrying) is recorded once.
 *
 * @param array $p  call_type, number, agent, agent_email, agent_name, duration, start, recording_url, summary [, call_id]
 * @return array{ok:bool, action:string, ticket_id:?int, error?:string}
 */
function threecxHandleCall(PDO $conn, array $p): array
{
    $c = threecxNormaliseCall($p);
    if ($c['number'] === '') {
        return ['ok' => false, 'action' => 'rejected', 'ticket_id' => null, 'error' => 'number is required'];
    }
    $key = $c['call_id'] !== '' ? 'id:' . $c['call_id']
         : sha1(implode('|', [$c['number'], $c['start'] ?? '', $c['agent'], $c['duration'] ?? '', $c['call_type']]));

    $lock = 'freeitsm_3cx_' . md5($key);
    $conn->query("SELECT GET_LOCK(" . $conn->quote($lock) . ", 10)");
    $plan = ['author' => null, 'caller' => null];
    try {
        $seen = $conn->prepare("SELECT ticket_id FROM threecx_calls WHERE call_key = ? LIMIT 1");
        $seen->execute([$key]);
        if (($t = $seen->fetchColumn()) !== false) {
            return ['ok' => true, 'action' => 'duplicate', 'ticket_id' => $t !== null ? (int)$t : null];
        }

        $cfg = threecxSettings($conn);
        $plan = threecxPlanCall($conn, $c, $cfg);
        $text = threecxCallText($c, $plan['caller'], $plan['author_is_agent']);

        if ($plan['action'] === 'noted') {
            $ctx = new ActorContext(actorId: $plan['author']['id'], companyScope: null, source: 'system', actorName: $plan['author']['name']);
            TicketsService::createNote($conn, $ctx, $plan['ticket_id'], ['text' => $text, 'is_internal' => true]);
            threecxLog($conn, $key, $c, $plan, $plan['ticket_id'], 'noted', '');
            return ['ok' => true, 'action' => 'noted', 'ticket_id' => $plan['ticket_id']];
        }

        if ($plan['action'] === 'created') {
            $who = $plan['caller'] ? $plan['caller']['display_name'] : $c['number'];
            $in = ['subject' => mb_substr("Phone call from {$who}", 0, 500), 'description' => $text];
            if ($plan['create'] === 'known') {
                $in['user_id'] = $plan['caller']['id'];
            } else {
                $in['requester_email'] = preg_replace('/\D+/', '', $c['number']) . '@' . THREECX_PLACEHOLDER_DOMAIN;
                $in['requester_name'] = "Caller {$c['number']}";
            }
            if ($cfg['team_id'])   $in['assigned_team_id'] = $cfg['team_id'];
            if ($cfg['origin_id']) $in['origin_id'] = $cfg['origin_id'];
            // The analyst who took the call is the one it is assigned to - not the
            // fallback, who is only there to sign notes.
            $assignee = $plan['author_is_agent'] ? $plan['author']['id'] : null;
            $ctx = $plan['author_is_agent']
                ? new ActorContext(actorId: $plan['author']['id'], companyScope: null, source: 'system', actorName: $plan['author']['name'])
                : ActorContext::system('3CX');
            $ticketId = TicketsService::createTicket($conn, $ctx, (int)$plan['tenant_id'], $in, $assignee, 'Created from a 3CX call', 'phone');
            threecxLog($conn, $key, $c, $plan, $ticketId, 'created', '');
            return ['ok' => true, 'action' => 'created', 'ticket_id' => $ticketId];
        }

        threecxLog($conn, $key, $c, $plan, null, 'logged', $plan['detail']);
        return ['ok' => true, 'action' => 'logged', 'ticket_id' => null];
    } catch (Throwable $ex) {
        error_log('3CX call: ' . $ex->getMessage());
        try { threecxLog($conn, $key, $c, $plan + ['author' => null, 'caller' => null], null, 'error', $ex->getMessage()); } catch (Throwable) {}
        return ['ok' => false, 'action' => 'error', 'ticket_id' => null, 'error' => 'Could not log the call'];
    } finally {
        $conn->query("SELECT RELEASE_LOCK(" . $conn->quote($lock) . ")");
    }
}

/**
 * The CRM template 3CX uploads (Admin Console → Integrations → CRM): a lookup by
 * number and a call report, both POSTing JSON to $endpoint with the token as a
 * Bearer header - never in the address, where it would sit in every log.
 *
 * The shape follows 3CX's CRM template reference: the lookup scenario has an
 * empty Id and must return ContactUrl, a name and a phone equal to the number
 * searched; "ReportCall" runs when a call ends. <Number MaxLength="[MaxLength]">
 * hands over the number cut to the digits set in 3CX, which threecxNumbersMatch()
 * reads either way.
 *
 * ⚠️ It carries the token. It is only ever handed to an administrator.
 */
function threecxTemplateXml(string $endpoint, string $token): string
{
    $a = fn(string $s): string => htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    $headers = '        <Headers>
          <Value Key="Authorization" Passes="1" Type="String">Bearer [Token]</Value>
        </Headers>';
    $v = fn(string $k, string $val, int $passes = 1): string
        => "          <Value Key=\"{$k}\" Passes=\"{$passes}\" Type=\"String\">{$val}</Value>\n";
    $report = $v('action', 'report', 0) . $v('call_type', '[CallType]') . $v('number', '[Number]') . $v('agent', '[Agent]')
            . $v('agent_email', '[AgentEmail]') . $v('agent_name', '[AgentFirstName] [AgentLastName]')
            . $v('duration', $a('[[[DurationTimespan].get_TotalSeconds()].ToString("F0")]'))
            . $v('start', $a('[[CallStartTimeUTC].ToString("yyyy-MM-ddTHH:mm:ssZ")]'), 2)
            . $v('recording_url', '[RecordingUrl]') . $v('summary', '[Summary]');

    return '<?xml version="1.0" encoding="utf-8"?>
<Crm xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xsd="http://www.w3.org/2001/XMLSchema" Country="GB" Name="FreeITSM" Version="1" SupportsEmojis="true">
  <Number Prefix="AsIs" MaxLength="[MaxLength]" />
  <Connection MaxConcurrentRequests="4" />
  <Parameters>
    <Parameter Name="Url" Type="String" Parent="General Configuration" Editor="String" Title="FreeITSM address:" Default="' . $a($endpoint) . '" />
    <Parameter Name="Token" Type="Password" Parent="General Configuration" Editor="String" Title="Token:" Default="' . $a($token) . '" />
    <Parameter Name="ReportCallEnabled" Type="Boolean" Editor="String" Title="Log calls on FreeITSM tickets" Default="True" />
  </Parameters>
  <Authentication Type="No" />
  <Scenarios>
    <Scenario Id="" Type="REST">
      <Request Url="[Url]" MessagePasses="0" RequestContentType="application/json" RequestEncoding="Json" RequestType="Post" ResponseType="Json">
' . $headers . '
        <PostValues Key="">
' . $v('action', 'lookup', 0) . $v('number', '[Number]') . '        </PostValues>
      </Request>
      <Rules>
        <Rule Type="Any">contacts.id</Rule>
      </Rules>
      <Variables>
        <Variable Name="ContactID" Path="contacts.id"><Filter /></Variable>
        <Variable Name="FirstName" Path="contacts.first_name"><Filter /></Variable>
        <Variable Name="LastName" Path="contacts.last_name"><Filter /></Variable>
        <Variable Name="Email" Path="contacts.email"><Filter /></Variable>
        <Variable Name="CompanyName" Path="contacts.company"><Filter /></Variable>
        <Variable Name="PhoneBusiness" Path="contacts.phone"><Filter /></Variable>
        <Variable Name="PhoneMobile" Path="contacts.mobile"><Filter /></Variable>
        <Variable Name="ContactUrl" Path="contacts.url"><Filter /></Variable>
      </Variables>
      <Outputs AllowEmpty="false">
        <Output Type="ContactID" Passes="0" Value="[ContactID]" />
        <Output Type="FirstName" Passes="0" Value="[FirstName]" />
        <Output Type="LastName" Passes="0" Value="[LastName]" />
        <Output Type="Email" Passes="0" Value="[Email]" />
        <Output Type="CompanyName" Passes="0" Value="[CompanyName]" />
        <Output Type="PhoneBusiness" Passes="0" Value="[PhoneBusiness]" />
        <Output Type="PhoneMobile" Passes="0" Value="[PhoneMobile]" />
        <Output Type="ContactUrl" Passes="0" Value="[ContactUrl]" />
        <Output Type="EntityId" Passes="0" Value="[ContactID]" />
        <Output Type="EntityType" Passes="0" Value="Contacts" />
      </Outputs>
    </Scenario>
    <Scenario Id="ReportCall" Type="REST">
      <Request SkipIf="[ReportCallEnabled]!=True" Url="[Url]" MessagePasses="0" RequestContentType="application/json" RequestEncoding="Json" RequestType="Post" ResponseType="Json">
' . $headers . '
        <PostValues Key="">
' . $report . '        </PostValues>
      </Request>
      <Variables />
      <Outputs AllowEmpty="false" />
    </Scenario>
  </Scenarios>
</Crm>
';
}
