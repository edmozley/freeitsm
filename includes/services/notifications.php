<?php
/**
 * In-app notifications (discussion #55).
 *
 * A bell in the header telling an analyst when something they care about
 * happened. It is a SUBSCRIBER to the existing workflow event bus rather than a
 * second instrumentation layer — WorkflowEngine::dispatch() already fires ~32
 * event types from 48 call sites, so almost everything worth telling somebody
 * about is already being announced.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 *  THE POINT OF THIS FILE IS THE NOISE RULES.
 * ─────────────────────────────────────────────────────────────────────────────
 * An analyst carrying forty tickets will see the bell fill up faster than they
 * can read it, and a bell nobody can leave switched on has failed regardless of
 * how correct it is. Four rules, all enforced here rather than at the call site:
 *
 *   1. Never notify you about your own action. You changed the status; you do
 *      not need telling. Analysts make most of the changes on their own tickets,
 *      so this removes more noise than everything else combined.
 *   2. Bulk operations produce at most one notification, not one per record.
 *   3. Repeat events about the same object COALESCE into one row while unread.
 *   4. Only types that earn a bell are on by default, and each is switchable
 *      per analyst.
 *
 * Anything added here later must go through notify() so it inherits all four.
 *
 * TWO BELLS, ONE SERVICE (discussion #62)
 * ----------------------------------------
 * The self-service portal has a bell too - a manager hears about a new ticket
 * from their team there. Its rows live in `portal_notifications` (keyed on
 * users.id), a sibling of `notifications` (keyed on analysts.id), and every
 * method here serves both: pass an analyst id as before, or
 * NotificationsService::portalUser($userId) for a portal user. Coalescing,
 * reading and clearing are the same code either way; only the per-analyst type
 * preferences (rule 4) are analyst-only, because a portal user has none.
 */

require_once __DIR__ . '/../service_context.php';
require_once __DIR__ . '/../entity_links.php';   // entityLink() — the one record→URL map

class NotificationsService
{
    /**
     * How long a repeat event folds into the existing row rather than making a
     * new one. Long enough to absorb "status, then priority, then a note" as one
     * visit to a ticket; short enough that this morning and this afternoon read
     * as separate news.
     */
    const COALESCE_WINDOW_MINUTES = 30;

    /** Newest first, and the bell never renders more than this. */
    const LIST_LIMIT = 50;

    /**
     * Set while a bulk operation is running (noise rule 2).
     *
     * ⚠️ Bulk endpoints LOOP the service one record at a time — deliberately, so
     * every record gets the same validation, audit and workflow dispatch a single
     * edit would. That means a bulk status change of 50 tickets fires 50 events,
     * and without this flag it would put 50 rows in somebody's bell.
     *
     * Use the helper pair below; do not set this directly, or an exception
     * mid-loop leaves the flag stuck on for the rest of the request.
     */
    /**
     * A portal user as a recipient. Anything else passed where a recipient is
     * expected is an analyst id, so every existing caller is unchanged.
     */
    public static function portalUser(int $userId): array
    {
        return ['portal_user' => $userId];
    }

    /** [table, id column, id, is portal] for a recipient. The ONLY place that choice is made. */
    private static function who($recipient): array
    {
        if (is_array($recipient) && isset($recipient['portal_user'])) {
            return ['portal_notifications', 'user_id', (int)$recipient['portal_user'], true];
        }
        return ['notifications', 'analyst_id', (int)$recipient, false];
    }

    private static bool $bulkMode = false;
    private static int $bulkSuppressed = 0;

    /** Run $fn with bulk suppression on, restoring the previous state whatever happens. */
    public static function duringBulk(callable $fn)
    {
        $prevMode = self::$bulkMode;
        $prevCount = self::$bulkSuppressed;
        self::$bulkMode = true;
        self::$bulkSuppressed = 0;
        try {
            return $fn();
        } finally {
            self::$bulkMode = $prevMode;
            self::$bulkSuppressed = $prevCount;
        }
    }

    public static function inBulk(): bool
    {
        return self::$bulkMode;
    }

    /**
     * Which event types put something in the bell, and whether they do so by
     * default. The value is the DEFAULT — every one of these is switchable per
     * analyst under Preferences.
     *
     * On by default only where the answer to "would you want to be interrupted
     * for this?" is plainly yes. Everything else is available but quiet, because
     * a bell that is right 60% of the time gets ignored 100% of the time.
     */
    public static function types(): array
    {
        return [
            // The ones people actually asked for.
            'ticket.assigned'         => ['default' => true,  'entity' => 'ticket'],
            /**
             * #1566 — a ticket lands in your team's queue.
             *
             * On by default for the same reason ticket.assigned is: work arriving
             * is the one thing you cannot afford to miss, and it is always caused
             * by somebody else, so it can never be self-inflicted noise.
             *
             * ⚠️ The audience is EVERY MEMBER of the receiving team, not one
             * person — see notificationsAudienceFor(). That is the point: the
             * helpdesk does not know who in Infrastructure does what, so the team
             * is told and whoever picks it up assigns themselves.
             *
             * Dormant on an install with no teams, which is every fresh install.
             */
            'ticket.team_assigned'    => ['default' => true,  'entity' => 'ticket'],
            'ticket.reply_received'   => ['default' => true,  'entity' => 'ticket'],
            'ticket.note_added'       => ['default' => true,  'entity' => 'ticket'],
            'ticket.status_changed'   => ['default' => true,  'entity' => 'ticket'],
            'ticket.priority_changed' => ['default' => true,  'entity' => 'ticket'],
            'sla.warning'             => ['default' => true,  'entity' => 'ticket'],
            'sla.breached'            => ['default' => true,  'entity' => 'ticket'],
            // On by default for the same reason ticket.assigned is: being handed
            // work is the one event you cannot afford to miss, and it is caused by
            // somebody else, so it cannot be self-inflicted noise (GH #110).
            'task.assigned'           => ['default' => true,  'entity' => 'task'],
            // On for the same reason task.assigned is: being put on a piece of
            // work is caused by somebody else and cannot be self-inflicted noise.
            // ⚠️ The recipient is the person ADDED, not the owner — the router
            // names this event explicitly to get that right (GH #89).
            'task.collaborator_added' => ['default' => true,  'entity' => 'task'],
            /**
             * GH #89 — "once somebody is on a task, are they told about
             * EVERYTHING on it, or only being added?" dschipfel's question,
             * answered as: everything, and each part switchable on its own.
             *
             * These three go to the OWNER AND EVERYONE INVOLVED (see
             * notificationsAudienceFor). Their defaults mirror the ticket
             * equivalents, because the same reasoning applies — somebody else
             * saying something, or moving the state, is news you cannot get any
             * other way, and rule 1 means your own changes never reach you.
             */
            'task.comment_added'      => ['default' => true,  'entity' => 'task'],
            'task.status_changed'     => ['default' => true,  'entity' => 'task'],
            // ⚠️ The one exception, OFF by default: a due date is moved during
            // planning far more often than the other two, frequently in a run of
            // several, and it is the least likely to need acting on the moment it
            // happens. Anybody who does want it is one switch away.
            'task.due_date_changed'   => ['default' => false, 'entity' => 'task'],
            // Off by default: being taken off a task is worth being able to hear
            // about, and is not something most people need a bell for.
            'task.collaborator_removed' => ['default' => false, 'entity' => 'task'],
            // Off by default: the inbox already shows you these, and a bell for
            // every ticket created on a busy desk is the definition of noise.
            'ticket.created'          => ['default' => false, 'entity' => 'ticket'],
            'task.created'            => ['default' => false, 'entity' => 'task'],
            'task.completed'          => ['default' => false, 'entity' => 'task'],
            // Domains (#154) — to the domain's OWNER. On by default: a domain
            // about to lapse, a certificate about to expire, or a name-server /
            // registrar / lock change nobody made is exactly what a bell is for,
            // and all three are caused by time or by somebody else, never by you.
            'domain.expiring'         => ['default' => true,  'entity' => 'domain'],
            'domain.ssl_expiring'     => ['default' => true,  'entity' => 'domain'],
            'domain.changed'          => ['default' => true,  'entity' => 'domain'],
            // Projects (3.2.0) - to the PROJECT MANAGER (projects.owner_analyst_id).
            // Health and tolerances fire on a change only (includes/projects/alerts.php),
            // so a project that stays red is not a bell every hour. On by default:
            // each is something going wrong, or a date arriving, never your own doing.
            'project.health_changed'     => ['default' => true,  'entity' => 'project'],
            'project.tolerance_breached' => ['default' => true,  'entity' => 'project'],
            'project.stage_due'          => ['default' => true,  'entity' => 'project'],
            // Off: the project manager usually closed it themselves.
            'project.stage_closed'       => ['default' => false, 'entity' => 'project'],
            // Milestones (3.3.0). Due and missed are dates arriving; reached is off
            // by default for the same reason as stage_closed.
            'project.milestone_due'      => ['default' => true,  'entity' => 'project'],
            'project.milestone_missed'   => ['default' => true,  'entity' => 'project'],
            'project.milestone_reached'  => ['default' => false, 'entity' => 'project'],
            // 3.3.0: somebody escalated a RAID entry - never your own (rule 1).
            'project.raid_escalated'     => ['default' => true,  'entity' => 'project'],
            // 3.3.0 change control: a request raised is something to decide; one
            // decided is news to the project manager only when somebody else decided it.
            'project.change_raised'      => ['default' => true,  'entity' => 'project'],
            'project.change_decided'     => ['default' => true,  'entity' => 'project'],
            // 3.3.0 intake: approvers hear about a proposal, the analyst who proposed one about the decision.
            'project.proposal_submitted' => ['default' => true,  'entity' => 'project'],
            'project.proposal_decided'   => ['default' => true,  'entity' => 'project'],
            // 3.3.0 benefits: a review date has arrived - to the owner and the project manager.
            'project.benefit_review_due' => ['default' => true,  'entity' => 'project'],
            // 3.3.0 gate checklists: you are named to sign something off.
            'project.signoff_requested'  => ['default' => true,  'entity' => 'project'],
            // 3.3.0 reminders (includes/projects/nudges.php): the overdue digest to the
            // project manager; a nudge to whoever has left something waiting.
            'project.tasks_overdue'      => ['default' => true,  'entity' => 'project'],
            'project.approval_stalled'   => ['default' => true,  'entity' => 'project'],
            // 3.3.0: a scheduled report draft is waiting for the project manager.
            'project.report_drafted'     => ['default' => true,  'entity' => 'project'],
        ];
    }

    /** True when this analyst wants this type in their bell. */
    public static function typeEnabled(PDO $conn, int $analystId, string $eventType): bool
    {
        $types = self::types();
        if (!isset($types[$eventType])) {
            return false;                      // not a notification type at all
        }
        $prefs = self::preferences($conn, $analystId);
        return array_key_exists($eventType, $prefs)
            ? (bool)$prefs[$eventType]
            : (bool)$types[$eventType]['default'];
    }

    /**
     * The analyst's per-type overrides, as [event_type => bool].
     *
     * Stored as one JSON preference rather than one row per type: it is read on
     * every event written, and a single row keeps that to one lookup.
     */
    public static function preferences(PDO $conn, int $analystId): array
    {
        try {
            $stmt = $conn->prepare(
                "SELECT preference_value FROM user_preferences
                 WHERE analyst_id = ? AND preference_key = 'notification_types'"
            );
            $stmt->execute([$analystId]);
            $raw = $stmt->fetchColumn();
        } catch (Exception $e) {
            return [];
        }
        if (!is_string($raw) || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    /** The full effective map for the settings UI: every type with its current state. */
    public static function effectivePreferences(PDO $conn, int $analystId): array
    {
        $overrides = self::preferences($conn, $analystId);
        $out = [];
        foreach (self::types() as $key => $meta) {
            $out[$key] = array_key_exists($key, $overrides)
                ? (bool)$overrides[$key]
                : (bool)$meta['default'];
        }
        return $out;
    }

    /**
     * Write a notification, applying every noise rule.
     *
     * Returns the notification id, or null when the rules said no — which is the
     * common case and is not an error.
     *
     * $in: analyst_id (or portal_user_id), event_type, entity_type, entity_id,
     *      entity_ref, title, body, actor_id, actor_name
     */
    public static function notify(PDO $conn, array $in): ?int
    {
        // Who it is for: an analyst, or (portal_user_id) a self-service portal user.
        $recipient = !empty($in['portal_user_id']) ? self::portalUser((int)$in['portal_user_id']) : (int)($in['analyst_id'] ?? 0);
        [$table, $col, $analystId, $isPortal] = self::who($recipient);
        $eventType = (string)($in['event_type'] ?? '');
        $entityId  = (int)($in['entity_id'] ?? 0);

        if ($analystId <= 0 || $eventType === '' || $entityId <= 0) {
            return null;
        }

        // ── Rule 2: bulk ──────────────────────────────────────────────────────
        // Counted rather than silently dropped, so the caller can say "12 of your
        // tickets were updated" once at the end if it wants to.
        if (self::$bulkMode) {
            self::$bulkSuppressed++;
            return null;
        }

        // ── Rule 1: never tell you about your own action ──────────────────────
        // The single biggest source of noise. An analyst working their own queue
        // generates most of the events on their own tickets.
        // actor_id is an analyst id and actor_user_id a portal user's, so an
        // analyst and a portal user who share a number are never confused.
        $actorId = $isPortal ? (int)($in['actor_user_id'] ?? 0) : (isset($in['actor_id']) ? (int)$in['actor_id'] : 0);
        if ($actorId > 0 && $actorId === $analystId) {
            return null;
        }

        // ── Rule 4: is this type wanted at all? ───────────────────────────────
        if (!$isPortal && !self::typeEnabled($conn, $analystId, $eventType)) {
            return null;
        }

        $entityType = (string)($in['entity_type'] ?? 'ticket');
        $entityRef  = self::clip($in['entity_ref'] ?? null, 64);
        $title      = self::clip($in['title'] ?? null, 255);
        $body       = self::clip($in['body'] ?? null, 500);
        $actorName  = self::clip($in['actor_name'] ?? null, 100);

        // ── Rule 3: coalesce ──────────────────────────────────────────────────
        // Same analyst, same object, still unread, within the window → bump the
        // existing row instead of adding another. Deliberately NOT keyed on
        // event_type: the point is "this ticket moved 3 times", not three
        // separate rows that happen to be about one ticket.
        //
        // The title follows the newest event too. For a ticket or task it is the
        // subject, so that only keeps it current; for a domain or a project it is
        // WHAT HAPPENED ("Bradford move: amber to red"), and keeping the first one
        // would show yesterday's news under today's count.
        try {
            $find = $conn->prepare(
                "SELECT id FROM $table
                 WHERE $col = ? AND entity_type = ? AND entity_id = ?
                   AND read_datetime IS NULL
                   AND updated_datetime >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? MINUTE)
                 ORDER BY id DESC LIMIT 1"
            );
            $find->execute([$analystId, $entityType, $entityId, self::COALESCE_WINDOW_MINUTES]);
            $existingId = $find->fetchColumn();

            if ($existingId !== false) {
                $conn->prepare(
                    "UPDATE $table
                     SET event_count = event_count + 1,
                         event_type  = ?,
                         title       = COALESCE(?, title),
                         body        = ?,
                         actor_name  = ?,
                         updated_datetime = UTC_TIMESTAMP()
                     WHERE id = ?"
                )->execute([$eventType, $title, $body, $actorName, (int)$existingId]);
                return (int)$existingId;
            }

            $conn->prepare(
                "INSERT INTO $table
                    ($col, event_type, entity_type, entity_id, entity_ref,
                     title, body, actor_name, event_count, created_datetime, updated_datetime)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP())"
            )->execute([$analystId, $eventType, $entityType, $entityId, $entityRef, $title, $body, $actorName]);

            return (int)$conn->lastInsertId();
        } catch (Exception $e) {
            // A notification is never worth failing the thing that caused it.
            error_log('[NotificationsService::notify] ' . $e->getMessage());
            return null;
        }
    }

    /** Unread count for the badge. Cheap — this is polled by every open tab. */
    public static function unreadCount(PDO $conn, $recipient): int
    {
        [$table, $col, $id] = self::who($recipient);
        try {
            $stmt = $conn->prepare(
                "SELECT COUNT(*) FROM $table WHERE $col = ? AND read_datetime IS NULL"
            );
            $stmt->execute([$id]);
            return (int)$stmt->fetchColumn();
        } catch (Exception $e) {
            return 0;
        }
    }

    /** Newest first. Unread first is deliberately NOT done — recency is the useful order. */
    public static function listFor(PDO $conn, $recipient, int $limit = self::LIST_LIMIT): array
    {
        [$table, $col, $id, $isPortal] = self::who($recipient);
        $limit = max(1, min($limit, self::LIST_LIMIT));
        $stmt = $conn->prepare(
            "SELECT id, event_type, entity_type, entity_id, entity_ref, title, body,
                    actor_name, event_count, created_datetime, updated_datetime, read_datetime
             FROM $table
             WHERE $col = ?
             ORDER BY updated_datetime DESC, id DESC
             LIMIT $limit"
        );
        $stmt->execute([$id]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as &$r) {
            $r['id']          = (int)$r['id'];
            $r['entity_id']   = (int)$r['entity_id'];
            $r['event_count'] = (int)$r['event_count'];
            $r['is_read']     = $r['read_datetime'] !== null;
            // A portal user opens a ticket in the portal, never the analyst inbox.
            $r['link']        = $isPortal ? self::portalLinkFor($r['entity_type'], (int)$r['entity_id'])
                                          : self::linkFor($r['entity_type'], (int)$r['entity_id']);
        }
        return $rows;
    }

    /**
     * Where clicking a notification goes.
     *
     * Relative to the app root. Tickets deep-link to the ticket in the inbox;
     * anything without a known destination returns null and the bell renders it
     * as text rather than a dead link.
     */
    public static function linkFor(string $entityType, int $entityId): ?string
    {
        // Delegates to the one resolver (includes/entity_links.php). This used to
        // be its own switch knowing `ticket` and `task` only, so a notification
        // about a change, a problem or an asset rendered href="#" and went
        // nowhere — the module could not be reached because this map had never
        // been told it existed.
        //
        // NULL still happens and is still correct: a lookup row such as
        // `ticket_status` or `impact_level` has no page to open.
        return entityLink($entityType, $entityId);
    }

    /**
     * Where a portal user's notification goes. Only tickets so far; the ticket
     * page itself decides - through portalTicketAccess() - whether they may see
     * it, so a stale notification can never be a way in.
     */
    public static function portalLinkFor(string $entityType, int $entityId): ?string
    {
        return $entityType === 'ticket' ? 'self-service/tickets.php?view=team&id=' . $entityId : null;
    }

    /** Mark specific ids read. Scoped to the analyst, so ids from elsewhere do nothing. */
    public static function markRead(PDO $conn, $recipient, array $ids): int
    {
        [$table, $col, $analystId] = self::who($recipient);
        $ids = array_values(array_filter(array_map('intval', $ids), fn($i) => $i > 0));
        if (!$ids) {
            return 0;
        }
        $place = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $conn->prepare(
            "UPDATE $table SET read_datetime = UTC_TIMESTAMP()
             WHERE $col = ? AND read_datetime IS NULL AND id IN ($place)"
        );
        $stmt->execute(array_merge([$analystId], $ids));
        return $stmt->rowCount();
    }

    public static function markAllRead(PDO $conn, $recipient): int
    {
        [$table, $col, $analystId] = self::who($recipient);
        $stmt = $conn->prepare(
            "UPDATE $table SET read_datetime = UTC_TIMESTAMP()
             WHERE $col = ? AND read_datetime IS NULL"
        );
        $stmt->execute([$analystId]);
        return $stmt->rowCount();
    }

    /**
     * Remove specific notifications outright (discussion #111).
     *
     * A hard DELETE, deliberately. This table is a display cache for the bell and
     * nothing else in the app reads it — the durable record of what happened lives
     * in the workflow events and the entity's own audit trail. So a dismissed row
     * preserves nothing, and a soft-delete column would buy a migration plus a
     * filter on every read in exchange for that nothing.
     *
     * Scoped to the analyst for the same reason markRead() is: ids belonging to
     * somebody else match nothing rather than erroring, which keeps a stale open
     * tab harmless.
     */
    public static function clear(PDO $conn, $recipient, array $ids): int
    {
        [$table, $col, $analystId] = self::who($recipient);
        $ids = array_values(array_filter(array_map('intval', $ids), fn($i) => $i > 0));
        if (!$ids) {
            return 0;
        }
        $place = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $conn->prepare(
            "DELETE FROM $table WHERE $col = ? AND id IN ($place)"
        );
        $stmt->execute(array_merge([$analystId], $ids));
        return $stmt->rowCount();
    }

    /**
     * Empty the panel.
     *
     * ⚠️ $includeUnread defaults to FALSE, and that default IS the safety catch.
     * Clearing is irreversible, and the rows most worth keeping are precisely the
     * ones nobody has looked at yet — so unless the analyst deliberately ticks the
     * box in the confirmation, unread news survives a Clear all.
     */
    public static function clearAll(PDO $conn, $recipient, bool $includeUnread = false): int
    {
        [$table, $col, $analystId] = self::who($recipient);
        $sql = "DELETE FROM $table WHERE $col = ?";
        if (!$includeUnread) {
            $sql .= " AND read_datetime IS NOT NULL";
        }
        $stmt = $conn->prepare($sql);
        $stmt->execute([$analystId]);
        return $stmt->rowCount();
    }

    private static function clip($value, int $max): ?string
    {
        if ($value === null) {
            return null;
        }
        $s = trim((string)$value);
        if ($s === '') {
            return null;
        }
        return mb_substr($s, 0, $max);
    }
}
