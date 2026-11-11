<?php
/**
 * Watching a ticket (GitHub #277) - being told about a ticket you do not own.
 *
 * Tasks have "Involved"; tickets had nobody but the assignee (and a team for a
 * queue). A watcher gets the ticket's notifications as if it were theirs, through
 * their own notification preferences, for the events Tickets -> Settings ->
 * General -> "What watchers are told about" ticks (all of them by default).
 *
 * The rules:
 *   - Anyone who can SEE a ticket can watch it - read-only Tickets access too:
 *     following a ticket changes nothing on it.
 *   - Putting someone ELSE on it, or taking them off, needs write access to
 *     Tickets; anyone can always take themselves off.
 *   - Only an analyst who can see the ticket can be put on it, and the router
 *     checks again at every notification (a ticket moved to another company, an
 *     analyst whose access changed): a watcher who cannot see it hears nothing.
 *   - The assignee may watch too (to keep following a ticket after handing it on);
 *     nobody is ever notified twice about one event.
 *
 * Merge moves watchers to the surviving ticket without duplicates (ticket_merge.php,
 * MERGE_MOVE_DEDUPE); split leaves them on the original; trash keeps them; a
 * permanent delete removes them.
 *
 * Follows the service rules (wiki: Service-Layer-Architecture): no superglobals,
 * no transport, typed ServiceError.
 */

require_once __DIR__ . '/../service_context.php';
require_once __DIR__ . '/../tenancy.php';

class TicketWatchers
{
    /** The ticket events a watcher can be told about - the ticket ones the owner gets, less "a new ticket" and "your team's queue". */
    public const EVENTS = [
        'ticket.reply_received', 'ticket.note_added', 'ticket.status_changed', 'ticket.priority_changed',
        'ticket.assigned', 'sla.warning', 'sla.breached',
    ];

    /** Is the table there yet (before Database Verify it is not)? Cached per request. */
    public static function ready(PDO $conn): bool
    {
        static $ok = null;
        if ($ok !== null) return $ok;
        try { $conn->query("SELECT 1 FROM ticket_watchers LIMIT 0"); return $ok = true; }
        catch (Throwable) { return $ok = false; }
    }

    /**
     * The events watchers are told about on this install (Tickets -> Settings -> General).
     * Never saved = all of them.
     *
     * @return list<string>
     */
    public static function events(PDO $conn): array
    {
        try {
            $s = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'ticket_watch_events'");
            $s->execute();
            $v = $s->fetchColumn();
        } catch (Throwable) {
            $v = false;
        }
        if ($v === false || $v === null) return self::EVENTS;
        return array_values(array_intersect(self::EVENTS, array_map('trim', explode(',', (string)$v))));
    }

    /**
     * The analysts watching a ticket, oldest first, with their names.
     *
     * @return list<array{analyst_id:int, name:string, added_by:?int, added_by_name:?string, added_at:string}>
     */
    public static function list(PDO $conn, int $ticketId): array
    {
        if (!self::ready($conn)) return [];
        $st = $conn->prepare(
            "SELECT w.analyst_id, a.full_name AS name, w.added_by_id, b.full_name AS added_by_name, w.created_datetime
               FROM ticket_watchers w
               JOIN analysts a ON a.id = w.analyst_id
               LEFT JOIN analysts b ON b.id = w.added_by_id
              WHERE w.ticket_id = ?
              ORDER BY w.created_datetime ASC, w.id ASC");
        $st->execute([$ticketId]);
        return array_map(fn($r) => [
            'analyst_id'    => (int)$r['analyst_id'],
            'name'          => (string)$r['name'],
            'added_by'      => $r['added_by_id'] !== null ? (int)$r['added_by_id'] : null,
            'added_by_name' => $r['added_by_name'] !== null ? (string)$r['added_by_name'] : null,
            'added_at'      => (string)$r['created_datetime'],
        ], $st->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Put an analyst on a ticket's watch list. Idempotent.
     *
     * @param int $analystId  who watches; the actor themselves, or - with write access - a colleague
     * @param bool $canWrite  whether the actor may change the ticket (write access to Tickets)
     * @return array{added:bool}
     */
    public static function add(PDO $conn, ActorContext $ctx, int $ticketId, int $analystId, bool $canWrite): array
    {
        self::assertReady($conn);
        self::assertActorSees($conn, $ctx, $ticketId);
        if ($analystId !== $ctx->actorId && !$canWrite) {
            throw new ServiceError('forbidden', 'read_only', 'Adding a colleague needs write access to Tickets. You can still watch it yourself.');
        }
        $a = $conn->prepare("SELECT is_active FROM analysts WHERE id = ?");
        $a->execute([$analystId]);
        $active = $a->fetchColumn();
        if ($active === false || (int)$active !== 1) {
            throw new ServiceError('validation', 'invalid_field', 'That analyst does not exist or is inactive.');
        }
        if (!self::canSee($conn, $analystId, $ticketId)) {
            throw new ServiceError('validation', 'invalid_field', 'That analyst cannot see this ticket, so cannot watch it.');
        }
        $ex = $conn->prepare("SELECT 1 FROM ticket_watchers WHERE ticket_id = ? AND analyst_id = ?");
        $ex->execute([$ticketId, $analystId]);
        if ($ex->fetchColumn()) return ['added' => false];
        $conn->prepare("INSERT INTO ticket_watchers (ticket_id, analyst_id, added_by_id, created_datetime) VALUES (?, ?, ?, UTC_TIMESTAMP())")
             ->execute([$ticketId, $analystId, $ctx->actorId > 0 ? $ctx->actorId : null]);
        return ['added' => true];
    }

    /**
     * Take an analyst off a ticket's watch list - yourself always; anyone else with write access.
     *
     * @return array{removed:bool}
     */
    public static function remove(PDO $conn, ActorContext $ctx, int $ticketId, int $analystId, bool $canWrite): array
    {
        self::assertReady($conn);
        self::assertActorSees($conn, $ctx, $ticketId);
        if ($analystId !== $ctx->actorId && !$canWrite) {
            throw new ServiceError('forbidden', 'read_only', 'Taking a colleague off needs write access to Tickets.');
        }
        $st = $conn->prepare("DELETE FROM ticket_watchers WHERE ticket_id = ? AND analyst_id = ?");
        $st->execute([$ticketId, $analystId]);
        return ['removed' => $st->rowCount() > 0];
    }

    /**
     * Who of a ticket's watchers should hear about this event: none unless watchers
     * are told about it here, and only those who can still see the ticket.
     * For the notifications router. Never throws.
     *
     * @return list<int>
     */
    public static function audience(PDO $conn, string $event, int $ticketId): array
    {
        if ($ticketId <= 0 || !in_array($event, self::events($conn), true)) return [];
        try {
            $st = $conn->prepare("SELECT analyst_id FROM ticket_watchers WHERE ticket_id = ?");
            $st->execute([$ticketId]);
            $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
        } catch (Throwable) {
            return [];   // before Database Verify: nobody watches yet
        }
        return array_values(array_filter($ids, fn($id) => analystCanAccessTicket($conn, $id, $ticketId)));
    }

    /** Remove every watcher from these tickets - for a permanent delete. Never throws. */
    public static function deleteForTickets(PDO $conn, array $ticketIds): void
    {
        $ids = array_values(array_filter(array_map('intval', $ticketIds)));
        if (!$ids) return;
        try {
            $conn->prepare("DELETE FROM ticket_watchers WHERE ticket_id IN (" . implode(',', array_fill(0, count($ids), '?')) . ")")->execute($ids);
        } catch (Throwable) { /* no table yet */ }
    }

    private static function assertReady(PDO $conn): void
    {
        if (!self::ready($conn)) {
            throw new ServiceError('validation', 'not_ready', 'Run Database Verify to switch on watching tickets.');
        }
    }

    /**
     * Can this analyst see this ticket - and does it exist?
     *
     * TRAP: analystCanAccessTicket() is a COMPANY check. On a single-company install
     * it is true for any id at all, so on its own it let a watch be stored against a
     * ticket that does not exist (found by tests/ticket-watchers.php).
     */
    public static function canSee(PDO $conn, int $analystId, int $ticketId): bool
    {
        if ($ticketId <= 0 || $analystId <= 0) return false;
        $s = $conn->prepare("SELECT 1 FROM tickets WHERE id = ?");
        $s->execute([$ticketId]);
        return (bool)$s->fetchColumn() && analystCanAccessTicket($conn, $analystId, $ticketId);
    }

    /** 404 for a ticket the actor cannot see - "not found", never "not yours". */
    private static function assertActorSees(PDO $conn, ActorContext $ctx, int $ticketId): void
    {
        if (!self::canSee($conn, $ctx->actorId, $ticketId)) {
            throw new ServiceError('not_found', 'not_found', 'Ticket not found.');
        }
    }
}
