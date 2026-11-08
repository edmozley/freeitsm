<?php
/**
 * Which mailboxes a self-service user may raise a ticket through (GH #172).
 *
 * The same rule the analyst side's New Ticket already follows
 * (api/tickets/get_sendable_mailboxes.php): on a multi-company install, the
 * mailboxes pinned to the ticket's company plus shared intake (tenant_id IS
 * NULL, which serves everyone); on a single-company install, every active one.
 *
 * Before this the portal listed EVERY active mailbox to every user, so a
 * requester at one client saw - and could choose - another client's support
 * address, and their ticket was then answered from it.
 *
 * "The ticket's company" is the user's own, exactly as create_ticket.php
 * decides it. A user with NO company on a multi-company install raises a ticket
 * into triage, and sees only shared mailboxes - which covers both of the
 * requester's suggestions at once ("no company-specific mailboxes", "a general
 * mailbox if one is configured"), so it needs no setting.
 *
 * ⚠️ One function for the list AND the check. create_ticket.php re-checks the
 * posted id against this, because a narrowed dropdown has never been a check.
 */

require_once __DIR__ . '/tenancy.php';
require_once __DIR__ . '/encryption.php';
require_once __DIR__ . '/self_service_ticket_types.php';

/**
 * The active mailboxes a self-service user may raise a ticket through - their own company's
 * and the shared ones. Used for the dropdown and to re-check the posted choice.
 *
 * @param int $userId  the signed-in self-service user (users.id); their company decides the list
 * @param ?int $wanted  GH #228: the "Raise with" company, re-checked by
 *                      selfServiceTicketTenant(); null = their own
 * @return array<int,array{id:int,name:string,target_mailbox:string,shared:bool}>
 *         keyed by mailbox id, the user's own company's first, then shared.
 */
function selfServiceMailboxes(PDO $conn, int $userId, ?int $wanted = null): array
{
    $sql = "SELECT id, name, target_mailbox, tenant_id FROM target_mailboxes WHERE is_active = 1";
    $params = [];
    if (isMultiTenant($conn)) {
        // The ticket's company, exactly as create_ticket.php decides it.
        $tenantId = selfServiceTicketTenant($conn, $userId, $wanted);
        if ($tenantId !== null) {
            $sql .= " AND (tenant_id = ? OR tenant_id IS NULL)";
            $params[] = (int)$tenantId;
        } else {
            $sql .= " AND tenant_id IS NULL";
        }
        $sql .= " ORDER BY (tenant_id IS NULL), name";
    } else {
        $sql .= " ORDER BY name";
    }
    $st = $conn->prepare($sql);
    $st->execute($params);

    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[(int)$r['id']] = [
            'id'             => (int)$r['id'],
            'name'           => $r['name'],
            'target_mailbox' => decryptValue($r['target_mailbox']),
            'shared'         => $r['tenant_id'] === null,
        ];
    }
    return $out;
}
