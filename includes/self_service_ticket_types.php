<?php
/**
 * The ticket types a portal requester may choose from, and the company their
 * ticket belongs to (#179). Shared by the form (api/self-service/get_ticket_fields.php)
 * and the create (api/self-service/create_ticket.php), so the list offered and
 * the list accepted can never differ - a narrowed dropdown is not a check.
 */

require_once __DIR__ . '/tenancy.php';
require_once __DIR__ . '/tenant_support.php';

/**
 * Which company a requester's ticket belongs to: their own, NULL (triage) when
 * they have none on a multi-company install, the Default company otherwise.
 * The same rule api/self-service/create_ticket.php has always used.
 *
 * GH #228: $wanted is the portal's "Raise with" choice. It is honoured only when
 * it is one selfServiceRaiseWithCompanies() offers this requester - a company
 * that supports theirs from the portal - and otherwise ignored, so a hand-posted
 * id can never file a ticket into a company the requester was not offered.
 *
 * @param ?int $wanted  the company chosen on New ticket; null = their own
 */
function selfServiceTicketTenant(PDO $conn, int $userId, ?int $wanted = null): ?int
{
    $stmt = $conn->prepare("SELECT tenant_id FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $t = $stmt->fetchColumn();
    if ($t !== false && $t !== null) {
        $own = (int)$t;
        if ($wanted !== null && $wanted !== $own) {
            foreach (selfServiceRaiseWithCompanies($conn, $own) as $c) {
                if ($c['id'] === $wanted) return $wanted;
            }
        }
        return $own;
    }
    return isMultiTenant($conn) ? null : getDefaultTenantId($conn);
}

/**
 * The active ticket types that company can see: [id => {id, name}].
 *
 * @param ?int $tenantId  the requester's company; null (triage) sees the shared types
 */
function selfServiceTicketTypes(PDO $conn, ?int $tenantId): array
{
    $rows = getTenantConfigRows($conn, 'ticket_types', 'ticket_type', $tenantId ?? (int)getDefaultTenantId($conn),
        'id, name', 'is_active = 1', 'display_order, name');
    $out = [];
    foreach ($rows as $r) $out[(int)$r['id']] = ['id' => (int)$r['id'], 'name' => (string)$r['name']];
    return $out;
}

/**
 * Does choosing a type change what the requester is asked? True when some field
 * they are asked is on particular types only.
 *
 * @param ?int $tenantId  the requester's company
 */
function selfServiceTicketTypeMatters(PDO $conn, ?int $tenantId): bool
{
    require_once __DIR__ . '/services/ticket_fields.php';
    if (!TicketFieldsService::schemaReady($conn)) return false;
    $stmt = $conn->prepare(
        "SELECT 1 FROM ticket_fields WHERE is_deleted = 0 AND ask_requester = 1 AND all_types = 0
            AND (tenant_id IS NULL" . ($tenantId !== null ? " OR tenant_id = ?" : "") . ") LIMIT 1"
    );
    $stmt->execute($tenantId !== null ? [$tenantId] : []);
    return $stmt->fetchColumn() !== false;
}
