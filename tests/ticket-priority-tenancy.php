<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * Ticket priorities and SLA calendars per company (GH #320).
 *
 * PART 1 - one company (only when the database has just the one):
 *          every active priority offered, the default priority and calendar, and no calendar when none is the default.
 * PART 2 - three companies, in a child process (the company count is cached per process):
 *          a company's own rows and the shared ones it has not hidden,
 *          triage on the shared list, the default fallbacks, the same name in two companies, and calendars.
 *          New tickets (including a real web chat), company moves, the SLA engine's calendar,
 *          who may change shared rows, and the ticket service resolving a priority by id or name.
 *
 * PART 3 - workflows, in a child process: "Set ticket priority" refuses another company's priority or a hidden one,
 *          a company's own priority is labelled with its company in the editor's lists, and its events are filed under it.
 *
 * Parts 1 and 2 run inside a transaction that is always rolled back, so nothing they make - companies included - is ever committed.
 * Part 3 cannot: a workflow action opens its own connection, which would not see uncommitted rows.
 * So it commits, names everything ZZ320 WF, and removes it all at the end, pass or fail.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/tenancy.php';
require_once __DIR__ . '/../includes/ticket_priorities.php';

$pass = 0; $fail = 0;
function ok(string $what, bool $cond, string $detail = ''): void
{
    global $pass, $fail;
    if ($cond) { $pass++; echo "  PASS  $what\n"; }
    else       { $fail++; echo "  FAIL  $what" . ($detail !== '' ? "  <- $detail" : '') . "\n"; }
}
function done(): never { global $pass, $fail; echo "\n$pass passed, $fail failed\n"; exit($fail ? 1 : 0); }

$conn = connectToDatabase();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$expected = getenv('FREEITSM_TEST_DATABASE');
if (!$expected || $conn->query('SELECT DATABASE()')->fetchColumn() !== $expected) {
    exit("Set FREEITSM_TEST_DATABASE to the throwaway database config.php points at - this test writes priorities, calendars and companies.\n");
}
try { $conn->query("SELECT tenant_id FROM ticket_priorities LIMIT 0"); $conn->query("SELECT tenant_id FROM sla_calendars LIMIT 0"); }
catch (PDOException) { echo "  SKIP  run Database Verification first: no tenant_id on ticket_priorities / sla_calendars\n"; exit(0); }

function zzPriority(PDO $c, string $name, ?int $tenant, int $default = 0, int $order = 900, int $active = 1): int
{
    $c->prepare("INSERT INTO ticket_priorities (name, tenant_id, is_default, display_order, is_active) VALUES (?, ?, ?, ?, ?)")
      ->execute([$name, $tenant, $default, $order, $active]);
    return (int)$c->lastInsertId();
}
function zzCalendar(PDO $c, string $name, ?int $tenant, int $default = 0): int
{
    $c->prepare("INSERT INTO sla_calendars (name, tenant_id, is_default) VALUES (?, ?, ?)")->execute([$name, $tenant, $default]);
    return (int)$c->lastInsertId();
}
function zzHide(PDO $c, int $tenant, string $type, int $id): void
{
    $c->prepare("INSERT INTO tenant_config_hidden (tenant_id, entity_type, entity_id) VALUES (?, ?, ?)")->execute([$tenant, $type, $id]);
}
function ids(array $rows): array { return array_map(fn($r) => (int)$r['id'], $rows); }

// ---------------------------------------------------------------------------
// Child: PART 2. Its companies are made before anything counts them.
// ---------------------------------------------------------------------------
if (($argv[1] ?? '') === '--multi') {
    echo "\nPART 2 - three companies\n";
    $conn->beginTransaction();
    try {
        $ins = $conn->prepare("INSERT INTO tenants (name, is_default, is_active) VALUES (?, 0, 1)");
        $ins->execute(['ZZ320 A']); $A = (int)$conn->lastInsertId();
        $ins->execute(['ZZ320 B']); $B = (int)$conn->lastInsertId();
        ok('control: this process sees more than one company', isMultiTenant($conn));

        $conn->exec("UPDATE ticket_priorities SET is_default = 0 WHERE tenant_id IS NULL");
        $sharedDef = zzPriority($conn, 'ZZ320 shared default', null, 1, 1);
        $sharedHid = zzPriority($conn, 'ZZ320 hidden by B', null, 0, 2);
        $aOwn      = zzPriority($conn, 'ZZ320 P1', $A, 1, 950);
        $aOff      = zzPriority($conn, 'ZZ320 A inactive', $A, 0, 951, 0);
        $bOwn      = zzPriority($conn, 'ZZ320 P1', $B, 0, 950);
        zzHide($conn, $B, 'ticket_priority', $sharedHid);

        $a = ids(ticketPrioritiesFor($conn, $A));
        $b = ids(ticketPrioritiesFor($conn, $B));
        $t = ids(ticketPrioritiesFor($conn, null));
        ok('a company sees its own priorities and the shared ones', in_array($aOwn, $a, true) && in_array($sharedDef, $a, true) && in_array($sharedHid, $a, true));
        ok('...never another company\'s', !in_array($bOwn, $a, true) && !in_array($aOwn, $b, true));
        ok('...nor a shared one it hid', !in_array($sharedHid, $b, true));
        ok('...nor its own inactive one, unless asked for', !in_array($aOff, $a, true) && in_array($aOff, ids(ticketPrioritiesFor($conn, $A, false)), true));
        ok('triage gets the shared priorities only', in_array($sharedDef, $t, true) && !in_array($aOwn, $t, true) && !in_array($bOwn, $t, true));
        ok('two companies can each have a priority of the same name', $aOwn > 0 && $bOwn > 0);
        $dupe = false;
        try { zzPriority($conn, 'ZZ320 P1', $A); } catch (PDOException) { $dupe = true; }
        ok('...but one company cannot have two', $dupe);

        ok('a company\'s own default wins', ticketPriorityDefaultId($conn, $A) === $aOwn);
        ok('a company without one gets the shared default', ticketPriorityDefaultId($conn, $B) === $sharedDef);
        ok('triage gets the shared default', ticketPriorityDefaultId($conn, null) === $sharedDef);
        zzHide($conn, $B, 'ticket_priority', $sharedDef);
        $first = ids(ticketPrioritiesFor($conn, $B))[0] ?? null;
        ok('a company that hid the shared default gets its first priority', $first !== null && ticketPriorityDefaultId($conn, $B) === $first);

        ok('a new ticket with no priority asked for gets its company\'s default', ticketPriorityForNewTicket($conn, $A) === $aOwn);
        ok('...the one asked for when its company can use it', ticketPriorityForNewTicket($conn, $A, $sharedHid) === $sharedHid);
        ok('...and its company\'s default when it cannot', ticketPriorityForNewTicket($conn, $A, $bOwn) === $aOwn
            && ticketPriorityForNewTicket($conn, $B, $sharedHid) === ticketPriorityDefaultId($conn, $B));

        // A real channel: web chat creates its ticket without a transaction of its own.
        require_once __DIR__ . '/../includes/webchat/webchat.php';
        $chatPriority = function (?int $tenant) use ($conn): int {
            $id = webchatOpenTicket($conn, ['id' => 0, 'visitor_email' => 'zz320chat@example.invalid', 'visitor_name' => 'ZZ320 chat'], ['tenant_id' => $tenant], 'ZZ320 chat');
            return (int)$conn->query("SELECT priority_id FROM tickets WHERE id = $id")->fetchColumn();
        };
        ok('a web chat ticket starts on its company\'s default priority', $chatPriority($A) === $aOwn);
        ok('...and one with no company on the shared default', $chatPriority(null) === ticketPriorityDefaultId($conn, null));

        ok('a company may use its own priority', ticketPriorityAllowed($conn, $aOwn, $A));
        ok('...not another company\'s', !ticketPriorityAllowed($conn, $aOwn, $B) && !ticketPriorityAllowed($conn, $aOwn, null));
        ok('...not a shared one it hid', !ticketPriorityAllowed($conn, $sharedHid, $B) && ticketPriorityAllowed($conn, $sharedHid, $A));
        ok('...not an inactive one', !ticketPriorityAllowed($conn, $aOff, $A));

        $conn->exec("UPDATE sla_calendars SET is_default = 0 WHERE tenant_id IS NULL");
        $calShared = zzCalendar($conn, 'ZZ320 shared hours', null, 1);
        $calA      = zzCalendar($conn, 'ZZ320 zz hours', $A, 1);   // named to sort after the shared one
        zzCalendar($conn, 'ZZ320 zz hours', $B);
        ok('a company\'s own default calendar wins', slaDefaultCalendarId($conn, $A) === $calA);
        ok('a company without one gets the shared default calendar', slaDefaultCalendarId($conn, $B) === $calShared);
        ok('triage gets the shared default calendar', slaDefaultCalendarId($conn, null) === $calShared);

        // Moving tickets: the same call every move path makes (move, triage, the ticket service).
        $everyone = zzPriority($conn, 'ZZ320 everyone', null);
        $retired  = zzPriority($conn, 'ZZ320 retired', null, 0, 900, 0);
        $status = (int)$conn->query("SELECT id FROM ticket_statuses ORDER BY id LIMIT 1")->fetchColumn();
        $ticket = function (int $tenant, int $priority) use ($conn, $status): int {
            $conn->prepare("INSERT INTO tickets (ticket_number, subject, tenant_id, priority_id, status_id, created_datetime) VALUES (?, 'ZZ320 move', ?, ?, ?, UTC_TIMESTAMP())")
                 ->execute(['ZZ320-' . bin2hex(random_bytes(4)), $tenant, $priority, $status]);
            return (int)$conn->lastInsertId();
        };
        $move = function (int $id, int $to) use ($conn): int {
            $conn->prepare("UPDATE tickets SET tenant_id = ? WHERE id = ?")->execute([$to, $id]);
            ticketsFollowCompanyMove($conn, [$id], $to);
            return (int)$conn->query("SELECT priority_id FROM tickets WHERE id = $id")->fetchColumn();
        };
        $t = $ticket($B, $bOwn);
        ok('a ticket moved with its old company\'s own priority gets the new company\'s default', $move($t, $A) === $aOwn);
        $audit = $conn->query("SELECT old_value, new_value FROM ticket_audit WHERE ticket_id = $t AND field_name = 'Priority'")->fetch(PDO::FETCH_ASSOC);
        ok('...and the change is on its history', $audit !== false && $audit['old_value'] === 'ZZ320 P1' && $audit['new_value'] === 'ZZ320 P1');
        ok('a ticket moved with a shared priority the new company hid gets its default', $move($ticket($A, $sharedHid), $B) === ticketPriorityDefaultId($conn, $B));
        ok('a ticket moved with a shared priority both can use keeps it', $move($ticket($A, $everyone), $B) === $everyone);
        ok('a ticket keeps an inactive shared priority the new company can see', $move($ticket($A, $retired), $B) === $retired);

        // The SLA engine: a priority with no calendar of its own runs on the ticket's company's default.
        require_once __DIR__ . '/../includes/sla.php';
        $conn->exec("INSERT INTO system_settings (setting_key, setting_value) VALUES ('sla_enforce_from', '2000-01-01 00:00:00')
                     ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
        $conn->prepare("UPDATE ticket_priorities SET sla_response_minutes = 60, sla_calendar_id = NULL WHERE id = ?")->execute([$everyone]);
        $slaCalendar = fn(int $ticketId): ?int => sla_get_state($conn, $ticketId)['calendar']['id'] ?? null;
        $conn->prepare("INSERT INTO tickets (ticket_number, subject, tenant_id, priority_id, status_id, created_datetime) VALUES ('ZZ320-triage', 'ZZ320 move', NULL, ?, ?, UTC_TIMESTAMP())")
             ->execute([$everyone, $status]);
        $triage = (int)$conn->lastInsertId();
        ok('a ticket in a company with its own default calendar runs on it', $slaCalendar($ticket($A, $everyone)) === $calA);
        ok('a ticket in a company without one runs on the shared default', $slaCalendar($ticket($B, $everyone)) === $calShared);
        ok('a triage ticket runs on the shared default', $slaCalendar($triage) === $calShared);
        $conn->prepare("UPDATE ticket_priorities SET sla_calendar_id = ? WHERE id = ?")->execute([$calShared, $everyone]);
        ok('a priority\'s own calendar wins over the company\'s default', $slaCalendar($ticket($A, $everyone)) === $calShared);
        ok('a shared priority may only use shared calendars', !in_array($calA, slaCalendarOptionIds($conn, null), true) && in_array($calShared, slaCalendarOptionIds($conn, null), true));
        ok('...a company\'s own, its own and the shared ones', in_array($calA, slaCalendarOptionIds($conn, $A), true) && in_array($calShared, slaCalendarOptionIds($conn, $A), true)
            && !in_array($calA, slaCalendarOptionIds($conn, $B), true));

        // Who may change a priority or calendar: shared needs every company, a company's own needs that company.
        $refused = function (array $ctx, ?int $tenant): bool {
            try { tenantScopeRequireAccess($ctx, $tenant, 'tickets.settings.priorities'); return false; } catch (Exception) { return true; }
        };
        $someone = ['multi' => true, 'can_share' => false, 'companies' => [['id' => $A, 'name' => 'A']]];
        $everyCompany = ['multi' => true, 'can_share' => true, 'companies' => [['id' => $A, 'name' => 'A'], ['id' => $B, 'name' => 'B']]];
        ok('changing a shared priority needs access to every company', $refused($someone, null) && !$refused($everyCompany, null));
        ok('...a company\'s own, access to that company', !$refused($someone, $A) && $refused($someone, $B));

        // The ticket service: a priority is resolved among the ticket's company's, by id or name.
        require_once __DIR__ . '/../includes/services/tickets.php';
        $actor = new ActorContext(0, null, 'api', 'en', 'ZZ320');
        $tA = $ticket($A, $everyone);
        $priorityOf = fn(int $id): int => (int)$conn->query("SELECT priority_id FROM tickets WHERE id = $id")->fetchColumn();
        TicketsService::updateTicket($conn, $actor, $tA, ['priority' => 'zz320 p1'], false);
        ok('a priority named on a ticket is that company\'s, though another has the same name', $priorityOf($tA) === $aOwn);
        $unknown = false;
        try { TicketsService::updateTicket($conn, $actor, $tA, ['priority_id' => $bOwn], false); } catch (ServiceError $e) { $unknown = $e->getMessage(); }
        ok('...another company\'s is refused as unknown', $unknown !== false && str_starts_with($unknown, 'Unknown priority') && $priorityOf($tA) === $aOwn);
        TicketsService::updateTicket($conn, $actor, $tA, ['company_id' => $B, 'priority_id' => $bOwn], false);
        ok('...and moving it checks the priority against the new company', $priorityOf($tA) === $bOwn);
    } finally {
        $conn->rollBack();
    }
    done();
}

// ---------------------------------------------------------------------------
// Child: PART 3. Committed, then removed in the finally - see the header.
// ---------------------------------------------------------------------------
if (($argv[1] ?? '') === '--workflow') {
    echo "\nPART 3 - workflows\n";
    require_once __DIR__ . '/../workflow/includes/engine.php';
    $ins = $conn->prepare("INSERT INTO tenants (name, is_default, is_active) VALUES (?, 0, 1)");
    $ins->execute(['ZZ320 WF A']); $A = (int)$conn->lastInsertId();
    $ins->execute(['ZZ320 WF B']); $B = (int)$conn->lastInsertId();
    try {
        ok('control: this process sees more than one company', isMultiTenant($conn));
        $aOwn    = zzPriority($conn, 'ZZ320 WF P1', $A);
        $bOwn    = zzPriority($conn, 'ZZ320 WF P1', $B);
        $shared  = zzPriority($conn, 'ZZ320 WF shared', null);
        $aHidden = zzPriority($conn, 'ZZ320 WF hidden by A', null);
        zzHide($conn, $A, 'ticket_priority', $aHidden);
        $status = (int)$conn->query("SELECT id FROM ticket_statuses ORDER BY id LIMIT 1")->fetchColumn();
        $conn->prepare("INSERT INTO tickets (ticket_number, subject, tenant_id, priority_id, status_id, created_datetime) VALUES ('ZZ320-WF', 'ZZ320 WF', ?, NULL, ?, UTC_TIMESTAMP())")
             ->execute([$A, $status]);
        $t = (int)$conn->lastInsertId();
        $set = function (int $priority) use ($conn, $t): ?int {
            WorkflowEngine::runActionList('ZZ320 WF', 'zz.test', [['type' => 'set_ticket_priority', 'args' => ['ticket_id' => (string)$t, 'priority_id' => (string)$priority]]], ['ticket' => ['id' => $t]]);
            $now = $conn->query("SELECT priority_id FROM tickets WHERE id = $t")->fetchColumn();
            return $now === null ? null : (int)$now;
        };
        ok('"Set ticket priority" sets the ticket\'s company\'s own priority', $set($aOwn) === $aOwn);
        ok('...and a shared one it can see', $set($shared) === $shared);
        ok('...but not another company\'s', $set($bOwn) === $shared);
        ok('...nor a shared one the company hides', $set($aHidden) === $shared);

        $labels = array_column(WorkflowEngine::availableValuesForField('ticket.priority_id'), 'label', 'id');
        ok('the condition list names a company\'s own priority\'s company', ($labels[(string)$aOwn] ?? '') === 'ZZ320 WF P1 (ZZ320 WF A)' && ($labels[(string)$bOwn] ?? '') === 'ZZ320 WF P1 (ZZ320 WF B)');
        ok('...and leaves a shared one as it is', ($labels[(string)$shared] ?? '') === 'ZZ320 WF shared');
        $actionLabels = array_column(WorkflowEngine::availableActionLookup('ticket_priority'), 'label', 'id');
        ok('the action\'s list does the same', ($actionLabels[(string)$aOwn] ?? '') === 'ZZ320 WF P1 (ZZ320 WF A)');

        ok('a company\'s own priority\'s events are filed under it', workflowEventTenantId($conn, ['ticket_priority' => ['id' => $aOwn]]) === $A);
        ok('...and a shared one\'s under no company', workflowEventTenantId($conn, ['ticket_priority' => ['id' => $shared]]) === null);
    } finally {
        $conn->exec("DELETE FROM workflow_executions WHERE workflow_name = 'ZZ320 WF'");
        $conn->exec("DELETE FROM tickets WHERE ticket_number = 'ZZ320-WF'");
        $conn->exec("DELETE FROM tenant_config_hidden WHERE tenant_id IN ($A, $B)");
        $conn->exec("DELETE FROM ticket_priorities WHERE name LIKE 'ZZ320 WF%'");
        $conn->exec("DELETE FROM tenants WHERE id IN ($A, $B)");
    }
    done();
}

// ---------------------------------------------------------------------------
echo "PART 1 - one company\n";
if ((int)$conn->query("SELECT COUNT(*) FROM tenants")->fetchColumn() > 1) {
    echo "  SKIP  the database has more than one company\n";
} else {
    $conn->beginTransaction();
    try {
        $active = array_map('intval', $conn->query("SELECT id FROM ticket_priorities WHERE is_active = 1")->fetchAll(PDO::FETCH_COLUMN));
        $got = ids(ticketPrioritiesFor($conn, null));
        sort($active); sort($got);
        ok('every active priority is offered', $got === $active, json_encode($got));
        $default = $conn->query("SELECT id FROM ticket_priorities WHERE is_active = 1 AND is_default = 1 LIMIT 1")->fetchColumn();
        ok('the default is the priority marked default', $default !== false && ticketPriorityDefaultId($conn, null) === (int)$default);
        $conn->exec("UPDATE ticket_priorities SET is_default = 0");
        $first = $conn->query("SELECT id FROM ticket_priorities WHERE is_active = 1 ORDER BY display_order, name LIMIT 1")->fetchColumn();
        ok('...and with no default, the first priority', ticketPriorityDefaultId($conn, null) === (int)$first);
        $cal = $conn->query("SELECT id FROM sla_calendars WHERE is_default = 1 AND is_active = 1 LIMIT 1")->fetchColumn();
        ok('the default calendar is the calendar marked default', $cal !== false && slaDefaultCalendarId($conn, null) === (int)$cal);
        $conn->exec("UPDATE sla_calendars SET is_default = 0");
        ok('...and with no default calendar, none', slaDefaultCalendarId($conn, null) === null);
    } finally {
        $conn->rollBack();
    }
}

passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' --multi', $childExit);
if ($childExit !== 0) $fail++;
passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' --workflow', $childExit);
if ($childExit !== 0) $fail++;
done();
