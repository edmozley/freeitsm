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
    if (!$ctx['multi']) return;
    if ($tenantId === null && !$ctx['can_share']) {
        throw new Exception(t('tickets.settings.priorities.shared_needs_all'));
    }
    if ($tenantId !== null && !in_array($tenantId, array_column($ctx['companies'], 'id'), true)) {
        throw new Exception(t('tickets.settings.priorities.company_not_allowed'));
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

/**
 * The calendar a ticket's SLA runs on when its priority names none:
 *   the company's own default calendar, else the shared default. Null when neither,
 *   which turns the SLA off
 *
 * @param ?int $tenantId  the ticket's company; null = triage
 */
function slaDefaultCalendarId(PDO $conn, ?int $tenantId): ?int
{
    $rows = getTenantConfigRows(
        $conn,
        'sla_calendars', // table
        'sla_calendar', // entity type
        $tenantId ?? getDefaultTenantId($conn), // tenant
        'id, is_default, tenant_id', // columns
        'is_active = 1', // where
        'name', // order
    );

    return tenantConfigDefaultId(
        array_map(fn(array $r): array => [
            'id'         => (int)$r['id'],
            'is_default' => (bool)$r['is_default'],
            'tenant_id'  => nullableInt($r['tenant_id']),
        ], $rows),
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
