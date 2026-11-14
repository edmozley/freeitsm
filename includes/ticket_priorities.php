<?php
/**
 * Ticket priorities and SLA calendars per company
 *
 * tenant_id NULL = shared
 * tenant_id set = that company
 * 
 * A company sees the shared rows it has not hidden plus its own.
 * A ticket with no company yet (triage) gets the Default company's list - the shared rows.
 */

require_once __DIR__ . '/tenancy.php';

const TICKET_PRIORITY_COLUMNS = 'id, name, colour, is_default, display_order, is_active, sla_response_minutes, sla_resolution_minutes, sla_calendar_id, tenant_id';

/**
 * The priorities a company's tickets may have, in display order.
 *
 * @param ?int $tenantId  the ticket's company; null = triage
 * @return list<array{id:int, name:string, colour:?string, is_default:bool, display_order:int, is_active:bool,
 *                    sla_response_minutes:?int, sla_resolution_minutes:?int, sla_calendar_id:?int, tenant_id:?int}>
 */
function ticketPrioritiesFor(PDO $conn, ?int $tenantId, bool $activeOnly = true): array
{
    $rows = getTenantConfigRows(
        $conn,
        'ticket_priorities', // table
        'ticket_priority', // entity type
        $tenantId ?? getDefaultTenantId($conn), // tenant
        TICKET_PRIORITY_COLUMNS, // columns
        $activeOnly ? 'is_active = 1' : '', // where
        'display_order, name', // order
    );

    return array_map('ticketPriorityRow', $rows);
}

/**
 * Every shared priority, hidden or not, in display order.
 *
 * @return list<array{id:int, name:string, colour:?string, is_default:bool, display_order:int, is_active:bool,
 *                    sla_response_minutes:?int, sla_resolution_minutes:?int, sla_calendar_id:?int, tenant_id:?int}>
 */
function ticketPrioritiesShared(PDO $conn): array
{
    $rows = $conn->query(
        "SELECT " . TICKET_PRIORITY_COLUMNS . " FROM ticket_priorities WHERE tenant_id IS NULL ORDER BY display_order, name"
    )->fetchAll(PDO::FETCH_ASSOC);

    return array_map('ticketPriorityRow', $rows);
}

/**
 * A company's own priorities, in display order; not the shared ones.
 *
 * @return list<array{id:int, name:string, colour:?string, is_default:bool, display_order:int, is_active:bool,
 *                    sla_response_minutes:?int, sla_resolution_minutes:?int, sla_calendar_id:?int, tenant_id:?int}>
 */
function ticketPrioritiesOwn(PDO $conn, int $tenantId): array
{
    $stmt = $conn->prepare("SELECT " . TICKET_PRIORITY_COLUMNS . " FROM ticket_priorities WHERE tenant_id = ? ORDER BY display_order, name");
    $stmt->execute([$tenantId]);

    return array_map('ticketPriorityRow', $stmt->fetchAll(PDO::FETCH_ASSOC));
}

/**
 * After tickets change company, a priority the new company cannot use becomes that company's default.
 * Recorded on the ticket's history and raised as ticket.priority_changed, like any other priority change.
 * A ticket keeps a priority the new company can see, even an inactive one.
 *
 * @param int[] $ticketIds  tickets whose company has just changed
 * @param ?int $tenantId  their new company; null = triage
 */
function ticketPriorityFollowCompanyMove(PDO $conn, array $ticketIds, ?int $tenantId): void
{
    if (!isMultiTenant($conn) || !$ticketIds) return;

    $visible = array_column(ticketPrioritiesFor($conn, $tenantId, false), 'name', 'id');
    $defaultId = ticketPriorityDefaultId($conn, $tenantId);
    if ($defaultId === null) return;

    $ph = implode(',', array_fill(0, count($ticketIds), '?'));
    $stmt = $conn->prepare(
        "SELECT t.id, t.priority_id, tp.name FROM tickets t
           LEFT JOIN ticket_priorities tp ON tp.id = t.priority_id
          WHERE t.id IN ($ph) AND t.priority_id IS NOT NULL"
    );
    $stmt->execute(array_values($ticketIds));

    require_once __DIR__ . '/services/tickets.php';
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $t) {
        $oldId = (int)$t['priority_id'];
        if (isset($visible[$oldId])) continue;

        $conn->prepare("UPDATE tickets SET priority_id = ? WHERE id = ?")->execute([$defaultId, (int)$t['id']]);
        $conn->prepare(
            "INSERT INTO ticket_audit (ticket_id, analyst_id, field_name, old_value, new_value, created_datetime)
             VALUES (?, ?, 'Priority', ?, ?, UTC_TIMESTAMP())"
        )->execute([(int)$t['id'], $_SESSION['analyst_id'] ?? null, $t['name'], $visible[$defaultId] ?? null]);

        WorkflowEngine::dispatch('ticket.priority_changed', [
            'ticket'          => TicketsService::workflowPayload($conn, (int)$t['id']),
            'old_priority_id' => $oldId,
            'new_priority_id' => $defaultId,
        ]);
    }
}

/**
 * Where a priority saved from Tickets -> Settings goes, for this analyst.
 *
 * `company` is the active client company, or null in the default company's context and in the All companies view, where the screen manages the shared priorities.
 * Changing a shared priority affects every company, so it needs access to all of them (`can_share`).
 * `companies` are the client companies the analyst may add a priority for; the default company's priorities are the shared ones.
 *
 * @return array{multi:bool, company:?int, can_share:bool, companies:list<array{id:int, name:string}>}
 */
function ticketPriorityScopeContext(PDO $conn, int $analystId): array
{
    $multi    = isMultiTenant($conn);
    $activeId = getActiveTenantId($conn, $analystId);
    $company  = (!$multi || isActiveTenantAll($conn) || $activeId === getDefaultTenantId($conn)) ? null : $activeId;

    $accessible = getAccessibleTenantIds($conn, $analystId);
    $companies  = [];
    foreach (getAllTenants($conn, true) as $t) {
        if (!$t['is_default'] && in_array($t['id'], $accessible, true)) {
            $companies[] = ['id' => $t['id'], 'name' => (string)$t['name']];
        }
    }

    return [
        'multi'     => $multi,
        'company'   => $company,
        'can_share' => analystHasAllTenantAccess($conn, $analystId),
        'companies' => $multi ? $companies : [],
    ];
}

/**
 * Refuse unless the analyst may add or change a priority in this scope: shared needs access to every company, a company's own needs access to that company.
 *
 * @param array{multi:bool, can_share:bool, companies:list<array{id:int, name:string}>} $ctx  from ticketPriorityScopeContext()
 * @param ?int $tenantId  the priority's company; null = shared
 */
function ticketPriorityRequireAccess(array $ctx, ?int $tenantId): void
{
    tenantScopeRequireAccess($ctx, $tenantId, 'tickets.settings.priorities');
}

/**
 * The same rule for any per-company settings row, with the refusals from `$langPrefix`.shared_needs_all and .company_not_allowed.
 *
 * @param array{multi:bool, can_share:bool, companies:list<array{id:int, name:string}>} $ctx  from ticketPriorityScopeContext()
 * @param ?int $tenantId  the row's company; null = shared
 */
function tenantScopeRequireAccess(array $ctx, ?int $tenantId, string $langPrefix): void
{
    if (!$ctx['multi']) return;
    if ($tenantId === null && !$ctx['can_share']) {
        throw new Exception(t($langPrefix . '.shared_needs_all'));
    }
    if ($tenantId !== null && !in_array($tenantId, array_column($ctx['companies'], 'id'), true)) {
        throw new Exception(t($langPrefix . '.company_not_allowed'));
    }
}

/** A ticket_priorities row with its values typed. */
function ticketPriorityRow(array $r): array
{
    return [
        'id'                     => (int)$r['id'],
        'name'                   => (string)$r['name'],
        'colour'                 => $r['colour'],
        'is_default'             => (bool)$r['is_default'],
        'display_order'          => (int)$r['display_order'],
        'is_active'              => (bool)$r['is_active'],
        'sla_response_minutes'   => nullableInt($r['sla_response_minutes']),
        'sla_resolution_minutes' => nullableInt($r['sla_resolution_minutes']),
        'sla_calendar_id'        => nullableInt($r['sla_calendar_id']),
        'tenant_id'              => nullableInt($r['tenant_id']),
    ];
}

/**
 * The priority a new ticket in this company gets: the company's own default,
 *  OR the shared default if it has not hidden it, else its first priority.
 *
 * @param ?int $tenantId  the ticket's company; null = triage
 */
function ticketPriorityDefaultId(PDO $conn, ?int $tenantId): ?int
{
    return tenantConfigDefaultId(
        ticketPrioritiesFor($conn, $tenantId),
        true, // or the first priority
    );
}

/**
 * May a ticket in this company be given this priority? Active ones only.
 *
 * @param ?int $tenantId  the ticket's company; null = triage
 */
function ticketPriorityAllowed(PDO $conn, int $priorityId, ?int $tenantId): bool
{
    return in_array($priorityId, array_column(ticketPrioritiesFor($conn, $tenantId), 'id'), true);
}

const SLA_CALENDAR_COLUMNS = 'id, name, timezone, is_default, is_active, tenant_id';

/**
 * The calendars a company's priorities may use: the shared ones and its own.
 * On a single-company install, every calendar.
 *
 * @param ?int $tenantId  the company; null = triage, which sees the shared ones
 * @return list<array{id:int, name:string, timezone:string, is_default:bool, is_active:bool, tenant_id:?int}>
 */
function slaCalendarsFor(PDO $conn, ?int $tenantId, bool $activeOnly = true): array
{
    $where = isMultiTenant($conn) ? "(tenant_id IS NULL OR tenant_id = " . (int)($tenantId ?? getDefaultTenantId($conn)) . ")" : "1 = 1";
    $rows = $conn->query(
        "SELECT " . SLA_CALENDAR_COLUMNS . " FROM sla_calendars WHERE $where" . ($activeOnly ? " AND is_active = 1" : "") . " ORDER BY name"
    )->fetchAll(PDO::FETCH_ASSOC);

    return array_map('slaCalendarRow', $rows);
}

/**
 * Calendars by owner: the shared ones (null) or one company's own, in name order.
 *
 * @param ?int $tenantId  the company; null = the shared calendars, hidden or not
 * @return list<array{id:int, name:string, timezone:string, is_default:bool, is_active:bool, tenant_id:?int}>
 */
function slaCalendarsOwnedBy(PDO $conn, ?int $tenantId, bool $activeOnly = true): array
{
    $stmt = $conn->prepare(
        "SELECT " . SLA_CALENDAR_COLUMNS . " FROM sla_calendars WHERE tenant_id <=> ?" . ($activeOnly ? " AND is_active = 1" : "") . " ORDER BY name"
    );
    $stmt->execute([$tenantId]);

    return array_map('slaCalendarRow', $stmt->fetchAll(PDO::FETCH_ASSOC));
}

/** An sla_calendars row with its values typed. */
function slaCalendarRow(array $r): array
{
    return [
        'id'         => (int)$r['id'],
        'name'       => (string)$r['name'],
        'timezone'   => (string)$r['timezone'],
        'is_default' => (bool)$r['is_default'],
        'is_active'  => (bool)$r['is_active'],
        'tenant_id'  => nullableInt($r['tenant_id']),
    ];
}

/**
 * The calendars a priority may run on: a shared priority applies to every company, so only shared calendars; a company's own priority, the calendars that company can use.
 *
 * @param ?int $priorityTenantId  the priority's company; null = shared
 * @return int[]
 */
function slaCalendarOptionIds(PDO $conn, ?int $priorityTenantId): array
{
    $rows = $priorityTenantId === null ? slaCalendarsOwnedBy($conn, null) : slaCalendarsFor($conn, $priorityTenantId);

    return array_column($rows, 'id');
}

/**
 * The calendar a ticket's SLA runs on when its priority names none:
 *   the company's own default calendar, else the shared default. Null when neither,
 *   which turns the SLA off
 *
 * @param ?int $tenantId  the ticket's company; null = triage
 */
function slaDefaultCalendarId(PDO $conn, ?int $tenantId): ?int
{
    return tenantConfigDefaultId(
        slaCalendarsFor($conn, $tenantId),
        false, // no default calendar means no SLA, never the first one
    );
}

/**
 * Own default, then shared default, then (if $orFirst) the first row.
 *
 * @param list<array{id:int, is_default:bool, tenant_id:?int}> $rows
 */
function tenantConfigDefaultId(array $rows, bool $orFirst): ?int
{
    $shared = null;
    foreach ($rows as $r) {
        if (!$r['is_default']) continue;
        if ($r['tenant_id'] !== null) return $r['id'];
        $shared ??= $r['id'];
    }
    return $shared ?? ($orFirst ? ($rows[0]['id'] ?? null) : null);
}

function nullableInt(mixed $v): ?int
{
    return $v === null ? null : (int)$v;
}
