<?php
/**
 * Ticket lifecycle events — the one place `ticket.created` is announced from.
 *
 * WHY THIS FILE EXISTS
 * --------------------
 * `ticket.created` used to be dispatched from exactly one place,
 * TicketsService::create(), which is the ANALYST path. The other two ways a
 * ticket comes into existence both write their own raw INSERT and never went
 * near that service:
 *
 *   - api/self-service/create_ticket.php   (the self-service portal)
 *   - api/tickets/check_mailbox_email.php  (inbound email)
 *
 * So on a real service desk, where most tickets arrive by email or through the
 * portal, the event fired for a minority of tickets. Everything downstream
 * inherited that blind spot: workflows with a `ticket.created` trigger never
 * ran for those channels, and the notification bell never saw them either.
 *
 * Rather than add a third and fourth copy of the payload — which would drift —
 * the payload is built here, once, from the stored row. Every caller gets the
 * same shape, so a workflow condition on `ticket.department_id` means the same
 * thing regardless of how the ticket arrived.
 *
 * ⚠️ Two fields cannot come from the row, because they are facts about the ACT
 * of creating rather than about the ticket, and they legitimately differ per
 * channel. They are passed in:
 *
 *   $createdBy       analyst id, portal user id, or null for inbound email
 *                    (nobody signed in created it)
 *   $requesterEmail  supplied where the caller already knows it; otherwise
 *                    resolved from the linked user
 */

require_once __DIR__ . '/../workflow/includes/engine.php';

/**
 * Announce that a ticket has been created.
 *
 * Safe to call from anywhere: it never throws. A failure to announce must not
 * cost somebody their ticket, which is the same rule every existing dispatch
 * call site already applies with its own try/catch.
 *
 * @param int         $ticketId       the ticket that now exists
 * @param int|null    $createdBy      who performed the creation, if anyone
 * @param string|null $requesterEmail overrides the address resolved from user_id
 */
function ticketDispatchCreated(PDO $conn, int $ticketId, ?int $createdBy = null, ?string $requesterEmail = null): void
{
    try {
        // Read the ticket back rather than trusting what the caller had in
        // scope: three call sites with three sets of local variables is exactly
        // how the payload would start to differ between channels.
        $stmt = $conn->prepare(
            "SELECT t.id, t.subject, t.status_id, t.priority_id, t.department_id,
                    t.ticket_type_id, t.assigned_analyst_id, t.assigned_team_id, t.user_id, t.category_id,
                    u.email AS user_email
               FROM tickets t
          LEFT JOIN users u ON u.id = t.user_id
              WHERE t.id = ?"
        );
        $stmt->execute([$ticketId]);
        $t = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$t) return;   // deleted between insert and announce; nothing to say

        WorkflowEngine::dispatch('ticket.created', [
            'ticket' => [
                'id'                  => (int)$t['id'],
                'subject'             => (string)$t['subject'],
                'priority_id'         => $t['priority_id']         !== null ? (int)$t['priority_id']         : null,
                'status_id'           => $t['status_id']           !== null ? (int)$t['status_id']           : null,
                'department_id'       => $t['department_id']       !== null ? (int)$t['department_id']       : null,
                // The payload has always called this type_id; the column is
                // ticket_type_id. Keep the payload name — workflow conditions
                // in the wild are written against it.
                'type_id'             => $t['ticket_type_id']      !== null ? (int)$t['ticket_type_id']      : null,
                'assigned_analyst_id' => $t['assigned_analyst_id'] !== null ? (int)$t['assigned_analyst_id'] : null,
                // The team the ticket arrived with - a routing rule or the New
                // ticket form. Without it, "new tickets for the L2 team" could
                // not be a workflow condition at all.
                'assigned_team_id'    => $t['assigned_team_id']    !== null ? (int)$t['assigned_team_id']    : null,
                // GH #298: route a new ticket by its category.
                'category_id'         => $t['category_id']         !== null ? (int)$t['category_id']         : null,
                'created_by'          => $createdBy,
                'requester_email'     => $requesterEmail !== null && $requesterEmail !== ''
                                            ? $requesterEmail
                                            : ($t['user_email'] ?? null),
            ],
        ]);
    } catch (Throwable $e) {
        error_log('[ticketDispatchCreated] ' . $e->getMessage());
    }

    // The requester's managers, if System -> Managers says to tell them
    // (discussion #62). Here, after every caller has applied the confidential
    // defaults (mailbox, department), so a ticket that is confidential the moment
    // it lands is never announced to a manager first. Its own try: a manager's
    // email must never cost the workflow above, or the ticket itself.
    try {
        require_once __DIR__ . '/managers.php';
        managersNotifyNewTicket($conn, $ticketId);
    } catch (Throwable $e) {
        error_log('[ticketDispatchCreated managers] ' . $e->getMessage());
    }
}
