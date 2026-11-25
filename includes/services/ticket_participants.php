<?php
/**
 * Ticket participants (GitHub #432, answering discussion #389) - end users copied
 * on a ticket's emails, so an analyst does not have to type them into Cc every time.
 *
 * A ticket has one requester; colleagues hit by the same problem, a manager, or
 * someone supplying information often need every email too. Participants are:
 *   - pre-filled in Cc when an analyst clicks Reply (not Forward);
 *   - copied on the automatic emails an install chooses in Tickets -> Settings ->
 *     General -> "Participants are copied on" (nothing until someone ticks it).
 * Watch (#277, ticket_watchers) is the analysts' equivalent, through notifications.
 *
 * The rules:
 *   - Adding or removing needs write access to Tickets, on a ticket the actor can see.
 *     Every add and removal is written to the ticket's history.
 *   - Never the requester (nobody twice), never someone who has left, never one of the
 *     install's own mailbox addresses or an active analyst's address (staff are not end
 *     users - they follow a ticket with Watch), never on a trashed ticket.
 *   - No participants at all on a confidential ticket (discussion #62): adding is
 *     refused, and whenever a ticket becomes confidential its participants are removed
 *     (clearForConfidential(), called from every path that raises sensitivity).
 *   - A person who leaves later stays on the list, shown as left, and is not copied.
 *   - Someone in another company than the ticket's is allowed (as Cc is today); the
 *     add dialog says so first.
 *
 * Merge moves participants to the surviving ticket without duplicates
 * (ticket_merge.php, MERGE_MOVE_DEDUPE); split leaves them on the original; trash
 * keeps them; a permanent delete removes them. Merging two people moves the
 * duplicate's participations (moveForUserMerge()); deleting a person removes theirs.
 *
 * Follows the service rules (wiki: Service-Layer-Architecture): no superglobals,
 * no transport, typed ServiceError.
 */

require_once __DIR__ . '/../service_context.php';
require_once __DIR__ . '/../tenancy.php';
require_once __DIR__ . '/../user_emails.php';
require_once __DIR__ . '/../ticket_sensitivity.php';

class TicketParticipants
{
    /**
     * The automatic emails that can copy participants (Tickets -> Settings -> General ->
     * "Participants are copied on"). Not the acknowledgement of a new ticket (nobody can
     * be a participant yet), the CSAT survey or its reminder (the requester's), or the
     * email to the assigned analyst.
     */
    public const EVENTS = ['ticket_assigned', 'ticket_closed', 'note_shared'];

    /**
     * The events this install copies participants on. Never saved = none: copying people
     * on automatic emails is something an install chooses, so an upgrade emails nobody new.
     *
     * @return list<string>
     */
    public static function events(PDO $conn): array
    {
        try {
            $s = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'ticket_participant_events'");
            $s->execute();
            $v = $s->fetchColumn();
        } catch (Throwable) {
            return [];
        }
        if ($v === false || $v === null || trim((string)$v) === '') return [];
        $on = array_map('trim', explode(',', (string)$v));
        return array_values(array_filter(self::EVENTS, fn($e) => in_array($e, $on, true)));
    }

    /** Is the table there yet (before Database Verify it is not)? Cached per request. */
    public static function ready(PDO $conn): bool
    {
        static $ok = null;
        if ($ok !== null) return $ok;
        try { $conn->query("SELECT 1 FROM ticket_participants LIMIT 0"); return $ok = true; }
        catch (Throwable) { return $ok = false; }
    }

    /**
     * A ticket's participants, oldest first - people who have left too, so they show as left.
     *
     * addresses: every address of the person (main and linked #308), lower case - so Reply
     * can tell they are already in To under another of their addresses.
     *
     * @return list<array{user_id:int, name:string, email:?string, addresses:list<string>, is_active:bool, company_id:?int,
     *                    company_name:?string, added_by:?int, added_by_name:?string, added_at:string, source:string}>
     */
    public static function list(PDO $conn, ActorContext $ctx, int $ticketId): array
    {
        self::assertReady($conn);
        self::assertActorSees($conn, $ctx, $ticketId);
        $st = $conn->prepare(
            "SELECT p.user_id, u.display_name, u.email, u.is_active, u.tenant_id, tn.name AS company_name,
                    p.added_by_id, a.full_name AS added_by_name, p.added_datetime, p.source
               FROM ticket_participants p
               JOIN users u ON u.id = p.user_id
               LEFT JOIN tenants tn ON tn.id = u.tenant_id
               LEFT JOIN analysts a ON a.id = p.added_by_id
              WHERE p.ticket_id = ?
              ORDER BY p.added_datetime ASC, p.id ASC");
        $st->execute([$ticketId]);
        return array_map(fn($r) => [
            'user_id'       => (int)$r['user_id'],
            'name'          => (string)($r['display_name'] ?: $r['email']),
            'email'         => $r['email'] !== null && $r['email'] !== '' ? (string)$r['email'] : null,
            'addresses'     => self::personAddresses($conn, (int)$r['user_id'], $r['email']),
            'is_active'     => (int)$r['is_active'] === 1,
            'company_id'    => $r['tenant_id'] !== null ? (int)$r['tenant_id'] : null,
            'company_name'  => $r['company_name'] !== null ? (string)$r['company_name'] : null,
            'added_by'      => $r['added_by_id'] !== null ? (int)$r['added_by_id'] : null,
            'added_by_name' => $r['added_by_name'] !== null ? (string)$r['added_by_name'] : null,
            'added_at'      => (string)$r['added_datetime'],
            'source'        => (string)$r['source'],
        ], $st->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Put a person on a ticket's participants, by id or by address. Idempotent.
     * An address matches a person by their main or a linked address (#308), or creates
     * the person as a new requester is created.
     *
     * @param ?int    $userId   the person; or null and give $email
     * @param ?string $email    an address; any case, any spaces
     * @param bool    $canWrite whether the actor may change the ticket (write access to Tickets)
     * @param string  $source   'analyst' (browser) or 'api' (REST)
     * @return array{added:bool, user_id:int, other_company:bool}
     */
    public static function add(PDO $conn, ActorContext $ctx, int $ticketId, ?int $userId, ?string $email, bool $canWrite, string $source = 'analyst'): array
    {
        self::assertReady($conn);
        self::assertActorSees($conn, $ctx, $ticketId);
        if (!$canWrite) {
            throw new ServiceError('forbidden', 'read_only', 'Adding a participant needs write access to Tickets.');
        }
        $ticket = self::ticketRow($conn, $ticketId);
        if ($ticket['deleted_datetime'] !== null) {
            throw new ServiceError('validation', 'trashed', 'This ticket is in the trash.');
        }
        if (ticketIsConfidential($conn, $ticketId)) {
            throw new ServiceError('validation', 'confidential', 'A confidential ticket has no participants.');
        }

        $own = !$conn->inTransaction();
        if ($own) $conn->beginTransaction();
        try {
            $userId = self::resolvePerson($conn, $ctx, $userId, $email);
            $person = self::personRow($conn, $userId);
            if ($ticket['user_id'] !== null && (int)$ticket['user_id'] === $userId) {
                throw new ServiceError('validation', 'is_requester', 'The requester already gets every email on this ticket.');
            }
            if ((int)$person['is_active'] !== 1) {
                throw new ServiceError('validation', 'inactive', 'This person has left, so cannot be added.');
            }
            foreach (self::personAddresses($conn, $userId, $person['email']) as $a) {
                if (self::isMailboxAddress($conn, $a)) {
                    throw new ServiceError('validation', 'mailbox_address', 'That is one of this service desk\'s own mailbox addresses.');
                }
            }
            if ($person['email'] !== null && self::isAnalystAddress($conn, (string)$person['email'])) {
                throw new ServiceError('validation', 'analyst_address', 'That is an analyst\'s address. Analysts follow a ticket with Watch.');
            }
            $otherCompany = self::otherCompany($conn, $person, $ticket);

            $ex = $conn->prepare("SELECT 1 FROM ticket_participants WHERE ticket_id = ? AND user_id = ?");
            $ex->execute([$ticketId, $userId]);
            if ($ex->fetchColumn()) {
                if ($own) $conn->commit();
                return ['added' => false, 'user_id' => $userId, 'other_company' => $otherCompany];
            }
            $conn->prepare("INSERT INTO ticket_participants (ticket_id, user_id, added_by_id, source, added_datetime) VALUES (?, ?, ?, ?, UTC_TIMESTAMP())")
                 ->execute([$ticketId, $userId, $ctx->actorId > 0 ? $ctx->actorId : null, $source === 'api' ? 'api' : 'analyst']);
            self::audit($conn, $ticketId, $ctx->actorId, '', self::label($person) . ' added');
            if ($own) $conn->commit();
            return ['added' => true, 'user_id' => $userId, 'other_company' => $otherCompany];
        } catch (Throwable $e) {
            if ($own && $conn->inTransaction()) $conn->rollBack();
            throw $e;
        }
    }

    /**
     * Who an Add would put on the ticket, without changing anything - for the Add dialog,
     * so it can name the person behind a typed address (main or linked, #308) instead of
     * promising a new one, and warn before Add when they are in another company. Refuses
     * an address exactly as add() would (invalid, a mailbox's, an analyst's).
     *
     * @return array{user_id:?int, name:?string, email:?string, new:bool, other_company:bool, company_name:?string}
     */
    public static function preview(PDO $conn, ActorContext $ctx, int $ticketId, ?int $userId, ?string $email): array
    {
        self::assertReady($conn);
        self::assertActorSees($conn, $ctx, $ticketId);
        $ticket = self::ticketRow($conn, $ticketId);
        $found = self::resolvePerson($conn, $ctx, $userId, $email, false);
        if ($found === null) {
            // Nobody has it: the company a new person would get, as add() would create them.
            $norm = userEmailNorm($email);
            $tenant = resolveTenantForNewUser($conn, $norm);
            return ['user_id' => null, 'name' => null, 'email' => $norm, 'new' => true,
                    'other_company' => self::otherCompany($conn, ['tenant_id' => $tenant], $ticket),
                    'company_name' => self::companyName($conn, $tenant)];
        }
        $person = self::personRow($conn, $found);
        return ['user_id' => $found, 'name' => (string)($person['display_name'] ?: $person['email']), 'email' => $person['email'],
                'new' => false, 'other_company' => self::otherCompany($conn, $person, $ticket),
                'company_name' => self::companyName($conn, $person['tenant_id'] !== null ? (int)$person['tenant_id'] : null)];
    }

    /**
     * Take a person off a ticket's participants. Works on a confidential ticket too
     * (removing must always be possible).
     *
     * @return array{removed:bool}
     */
    public static function remove(PDO $conn, ActorContext $ctx, int $ticketId, int $userId, bool $canWrite): array
    {
        self::assertReady($conn);
        self::assertActorSees($conn, $ctx, $ticketId);
        if (!$canWrite) {
            throw new ServiceError('forbidden', 'read_only', 'Removing a participant needs write access to Tickets.');
        }
        $own = !$conn->inTransaction();
        if ($own) $conn->beginTransaction();
        try {
            $st = $conn->prepare("DELETE FROM ticket_participants WHERE ticket_id = ? AND user_id = ?");
            $st->execute([$ticketId, $userId]);
            $removed = $st->rowCount() > 0;
            if ($removed) self::audit($conn, $ticketId, $ctx->actorId, self::label(self::personRow($conn, $userId, false)), 'removed');
            if ($own) $conn->commit();
            return ['removed' => $removed];
        } catch (Throwable $e) {
            if ($own && $conn->inTransaction()) $conn->rollBack();
            throw $e;
        }
    }

    /**
     * The addresses to copy: active participants' main addresses, lower case, once each,
     * never one of the requester's (main or linked). None on a confidential ticket, and
     * none before Database Verify.
     *
     * @return list<string>
     */
    public static function ccAddresses(PDO $conn, int $ticketId): array
    {
        if ($ticketId <= 0 || !self::ready($conn) || ticketIsConfidential($conn, $ticketId)) return [];
        $st = $conn->prepare(
            "SELECT LOWER(TRIM(u.email)) FROM ticket_participants p JOIN users u ON u.id = p.user_id
              WHERE p.ticket_id = ? AND u.is_active = 1 AND u.email IS NOT NULL AND TRIM(u.email) <> ''
              ORDER BY p.added_datetime ASC, p.id ASC");
        $st->execute([$ticketId]);
        $out = array_values(array_unique($st->fetchAll(PDO::FETCH_COLUMN)));
        $skip = self::requesterAddresses($conn, $ticketId);
        return array_values(array_filter($out, fn($a) => !in_array($a, $skip, true)));
    }

    /**
     * The ticket has become confidential: remove its participants, one history row each.
     * Joins the caller's transaction. Never throws for a missing table (returns 0).
     *
     * @param list<int> $ticketIds
     * @return int how many were removed
     */
    public static function clearForConfidential(PDO $conn, array $ticketIds, ?int $analystId): int
    {
        $ids = array_values(array_filter(array_map('intval', $ticketIds), fn($i) => $i > 0));
        if (!$ids || !self::ready($conn)) return 0;
        $st = $conn->prepare(
            "SELECT p.ticket_id, p.user_id, u.display_name, u.email FROM ticket_participants p
               LEFT JOIN users u ON u.id = p.user_id
              WHERE p.ticket_id IN (" . implode(',', array_fill(0, count($ids), '?')) . ") ORDER BY p.id");
        $st->execute($ids);
        $n = 0;
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $conn->prepare("DELETE FROM ticket_participants WHERE ticket_id = ? AND user_id = ?")->execute([$r['ticket_id'], $r['user_id']]);
            self::audit($conn, (int)$r['ticket_id'], $analystId, self::label($r), 'removed (ticket confidential)');
            $n++;
        }
        return $n;
    }

    /** The ticket's requester has changed: if the new one was a participant, take them off (history row). */
    public static function removeIfRequester(PDO $conn, int $ticketId, ?int $analystId): void
    {
        if ($ticketId <= 0 || !self::ready($conn)) return;
        $st = $conn->prepare(
            "SELECT p.user_id, u.display_name, u.email FROM tickets t
               JOIN ticket_participants p ON p.ticket_id = t.id AND p.user_id = t.user_id
               LEFT JOIN users u ON u.id = p.user_id
              WHERE t.id = ?");
        $st->execute([$ticketId]);
        if ($r = $st->fetch(PDO::FETCH_ASSOC)) {
            $conn->prepare("DELETE FROM ticket_participants WHERE ticket_id = ? AND user_id = ?")->execute([$ticketId, $r['user_id']]);
            self::audit($conn, $ticketId, $analystId, self::label($r), 'removed (now the requester)');
        }
    }

    /** Remove every participant from these tickets - for a permanent delete. Never throws. */
    public static function deleteForTickets(PDO $conn, array $ticketIds): void
    {
        $ids = array_values(array_filter(array_map('intval', $ticketIds)));
        if (!$ids) return;
        try {
            $conn->prepare("DELETE FROM ticket_participants WHERE ticket_id IN (" . implode(',', array_fill(0, count($ids), '?')) . ")")->execute($ids);
        } catch (Throwable) { /* no table yet */ }
    }

    /**
     * Two people merged: the duplicate's participations go to the survivor, except
     * where the survivor is already on that ticket or is its requester. Call it after
     * the tickets' requester has been re-pointed: a ticket the duplicate requested and
     * the survivor was copied on then loses the survivor as a participant (history row).
     */
    public static function moveForUserMerge(PDO $conn, int $fromUserId, int $toUserId): void
    {
        if ($fromUserId <= 0 || $toUserId <= 0 || $fromUserId === $toUserId || !self::ready($conn)) return;
        $conn->prepare(
            "DELETE p FROM ticket_participants p
              WHERE p.user_id = ?
                AND (EXISTS (SELECT 1 FROM (SELECT ticket_id FROM ticket_participants WHERE user_id = ?) s WHERE s.ticket_id = p.ticket_id)
                     OR EXISTS (SELECT 1 FROM tickets t WHERE t.id = p.ticket_id AND t.user_id = ?))")
             ->execute([$fromUserId, $toUserId, $toUserId]);
        $conn->prepare("UPDATE ticket_participants SET user_id = ? WHERE user_id = ?")->execute([$toUserId, $fromUserId]);
        $st = $conn->prepare("SELECT p.ticket_id FROM ticket_participants p JOIN tickets t ON t.id = p.ticket_id AND t.user_id = p.user_id WHERE p.user_id = ?");
        $st->execute([$toUserId]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $ticketId) self::removeIfRequester($conn, (int)$ticketId, null);
    }

    // ── internals ────────────────────────────────────────────────────────────

    private static function assertReady(PDO $conn): void
    {
        if (!self::ready($conn)) {
            throw new ServiceError('validation', 'not_ready', 'Run Database Verify to switch on ticket participants.');
        }
    }

    /**
     * 404 for a ticket the actor cannot see, or that does not exist - "not found",
     * never "not yours". (analystCanAccessTicket() alone is a company check, true for
     * any id on a single-company install.)
     */
    private static function assertActorSees(PDO $conn, ActorContext $ctx, int $ticketId): void
    {
        $ok = false;
        if ($ticketId > 0 && $ctx->actorId > 0) {
            $s = $conn->prepare("SELECT 1 FROM tickets WHERE id = ?");
            $s->execute([$ticketId]);
            $ok = (bool)$s->fetchColumn() && analystCanAccessTicket($conn, $ctx->actorId, $ticketId);
        }
        if (!$ok) throw new ServiceError('not_found', 'not_found', 'Ticket not found.');
    }

    /** @return array{user_id:?int, tenant_id:?int, deleted_datetime:?string} */
    private static function ticketRow(PDO $conn, int $ticketId): array
    {
        $s = $conn->prepare("SELECT user_id, tenant_id, deleted_datetime FROM tickets WHERE id = ?");
        $s->execute([$ticketId]);
        return $s->fetch(PDO::FETCH_ASSOC);
    }

    /** The person's row; ServiceError not_found when they do not exist (or an empty row when $must is false). */
    private static function personRow(PDO $conn, int $userId, bool $must = true): array
    {
        $s = $conn->prepare("SELECT id, display_name, email, is_active, tenant_id FROM users WHERE id = ?");
        $s->execute([$userId]);
        $r = $s->fetch(PDO::FETCH_ASSOC);
        if (!$r) {
            if ($must) throw new ServiceError('not_found', 'not_found', 'Person not found.');
            return ['id' => $userId, 'display_name' => null, 'email' => null, 'is_active' => 0, 'tenant_id' => null];
        }
        return $r;
    }

    /**
     * The person to add: by id (must exist and be visible to the actor), or by address
     * (found, or created when $create - otherwise null for "nobody has it yet").
     */
    private static function resolvePerson(PDO $conn, ActorContext $ctx, ?int $userId, ?string $email, bool $create = true): ?int
    {
        if ($userId !== null && $userId > 0) {
            self::personRow($conn, $userId);
            if (!analystCanAccessUser($conn, $ctx->actorId, $userId)) {
                throw new ServiceError('not_found', 'not_found', 'Person not found.');
            }
            return $userId;
        }
        if ($email === null || trim($email) === '') {
            throw new ServiceError('validation', 'invalid_field', "Give either 'user_id' or 'email'.");
        }
        $norm = userEmailNorm($email);
        if (!filter_var($norm, FILTER_VALIDATE_EMAIL)) {
            throw new ServiceError('validation', 'invalid_email', 'That is not a valid email address.');
        }
        if (self::isMailboxAddress($conn, $norm)) {
            throw new ServiceError('validation', 'mailbox_address', 'That is one of this service desk\'s own mailbox addresses.');
        }
        if (self::isAnalystAddress($conn, $norm)) {
            throw new ServiceError('validation', 'analyst_address', 'That is an analyst\'s address. Analysts follow a ticket with Watch.');
        }
        $found = userIdForEmail($conn, $norm);
        if ($found !== null) {
            if (!analystCanAccessUser($conn, $ctx->actorId, $found)) {
                throw new ServiceError('not_found', 'not_found', 'Person not found.');
            }
            return $found;
        }
        if (!$create) return null;
        // A new person, exactly as a new requester is created (TicketsService::create).
        $conn->prepare("INSERT INTO users (email, display_name, tenant_id, created_at) VALUES (?, '', ?, UTC_TIMESTAMP())")
             ->execute([$norm, resolveTenantForNewUser($conn, $norm)]);
        return (int)$conn->lastInsertId();
    }

    /** Is this address one this install's mailboxes receive or send as? */
    private static function isMailboxAddress(PDO $conn, string $email): bool
    {
        $e = userEmailNorm($email);
        if ($e === '') return false;
        static $addresses = null;
        if ($addresses === null) {
            $addresses = [];
            try {
                foreach ($conn->query("SELECT target_mailbox, authenticated_addresses, imap_username, smtp_username FROM target_mailboxes")->fetchAll(PDO::FETCH_ASSOC) as $m) {
                    foreach ($m as $v) {
                        foreach (preg_split('/[\s,;]+/', (string)$v) as $a) {
                            $a = userEmailNorm($a);
                            if ($a !== '' && str_contains($a, '@')) $addresses[$a] = true;
                        }
                    }
                }
            } catch (Throwable) {
                // Not known: refuse rather than answer "not a mailbox".
                $addresses = null;
                throw new ServiceError('validation', 'invalid_field', 'Could not check the mailbox addresses. Try again.');
            }
        }
        return isset($addresses[$e]);
    }

    /** Is this address an active analyst's? (Compared case-insensitively by the column's collation.) */
    private static function isAnalystAddress(PDO $conn, string $email): bool
    {
        $e = userEmailNorm($email);
        if ($e === '') return false;
        $s = $conn->prepare("SELECT 1 FROM analysts WHERE email = ? AND is_active = 1 LIMIT 1");
        $s->execute([$e]);
        return (bool)$s->fetchColumn();
    }

    /** A company's name (the Default company's for null), or null when unknown / single-company. */
    private static function companyName(PDO $conn, ?int $tenantId): ?string
    {
        if (!isMultiTenant($conn)) return null;
        $s = $conn->prepare("SELECT name FROM tenants WHERE id = ?");
        $s->execute([$tenantId ?? getDefaultTenantId($conn)]);
        $n = $s->fetchColumn();
        return $n === false ? null : (string)$n;
    }

    /** Is the person in another company than the ticket's? Never on a single-company install. */
    private static function otherCompany(PDO $conn, array $person, array $ticket): bool
    {
        if (!isMultiTenant($conn)) return false;
        $default = getDefaultTenantId($conn);
        $p = $person['tenant_id'] !== null ? (int)$person['tenant_id'] : $default;
        $t = $ticket['tenant_id'] !== null ? (int)$ticket['tenant_id'] : null;
        return $t !== null && $p !== $t;
    }

    /** Every address of the ticket's requester, main and linked, lower case ([] when there is none). */
    public static function requesterAddresses(PDO $conn, int $ticketId): array
    {
        $s = $conn->prepare("SELECT u.id, u.email FROM tickets t JOIN users u ON u.id = t.user_id WHERE t.id = ?");
        $s->execute([$ticketId]);
        $r = $s->fetch(PDO::FETCH_ASSOC);
        return $r ? self::personAddresses($conn, (int)$r['id'], $r['email']) : [];
    }

    /** A person's main address and linked ones (#308), lower case, once each. */
    private static function personAddresses(PDO $conn, int $userId, ?string $main): array
    {
        $out = $main !== null && trim($main) !== '' ? [userEmailNorm($main)] : [];
        if (userEmailsReady($conn)) {
            $l = $conn->prepare("SELECT email FROM user_email_addresses WHERE user_id = ?");
            $l->execute([$userId]);
            foreach ($l->fetchAll(PDO::FETCH_COLUMN) as $a) $out[] = userEmailNorm($a);
        }
        return array_values(array_unique(array_filter($out, fn($a) => $a !== '')));
    }

    /** "Name <address>" for the history. */
    private static function label(array $p): string
    {
        $name = trim((string)($p['display_name'] ?? ''));
        $mail = trim((string)($p['email'] ?? ''));
        if ($name !== '' && $mail !== '') return "$name <$mail>";
        return $name !== '' ? $name : ($mail !== '' ? $mail : 'Person #' . (int)($p['id'] ?? $p['user_id'] ?? 0));
    }

    /** One line in the ticket's history. A missing analyst is stored as NULL, never 0. */
    private static function audit(PDO $conn, int $ticketId, ?int $analystId, string $old, string $new): void
    {
        $conn->prepare("INSERT INTO ticket_audit (ticket_id, analyst_id, field_name, old_value, new_value, created_datetime) VALUES (?, ?, 'Participants', ?, ?, UTC_TIMESTAMP())")
             ->execute([$ticketId, $analystId !== null && $analystId > 0 ? $analystId : null, $old, $new]);
    }
}
