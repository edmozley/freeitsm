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
     * $in: analyst_id, event_type, entity_type, entity_id, entity_ref, title,
     *      body, actor_id, actor_name
     */
    public static function notify(PDO $conn, array $in): ?int
    {
        $analystId = (int)($in['analyst_id'] ?? 0);
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
        $actorId = isset($in['actor_id']) ? (int)$in['actor_id'] : 0;
        if ($actorId > 0 && $actorId === $analystId) {
            return null;
        }

        // ── Rule 4: is this type wanted at all? ───────────────────────────────
        if (!self::typeEnabled($conn, $analystId, $eventType)) {
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
        try {
            $find = $conn->prepare(
                "SELECT id FROM notifications
                 WHERE analyst_id = ? AND entity_type = ? AND entity_id = ?
                   AND read_datetime IS NULL
                   AND updated_datetime >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? MINUTE)
                 ORDER BY id DESC LIMIT 1"
            );
            $find->execute([$analystId, $entityType, $entityId, self::COALESCE_WINDOW_MINUTES]);
            $existingId = $find->fetchColumn();

            if ($existingId !== false) {
                $conn->prepare(
                    "UPDATE notifications
                     SET event_count = event_count + 1,
                         event_type  = ?,
                         body        = ?,
                         actor_name  = ?,
                         updated_datetime = UTC_TIMESTAMP()
                     WHERE id = ?"
                )->execute([$eventType, $body, $actorName, (int)$existingId]);
                return (int)$existingId;
            }

            $conn->prepare(
                "INSERT INTO notifications
                    (analyst_id, event_type, entity_type, entity_id, entity_ref,
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
    public static function unreadCount(PDO $conn, int $analystId): int
    {
        try {
            $stmt = $conn->prepare(
                "SELECT COUNT(*) FROM notifications WHERE analyst_id = ? AND read_datetime IS NULL"
            );
            $stmt->execute([$analystId]);
            return (int)$stmt->fetchColumn();
        } catch (Exception $e) {
            return 0;
        }
    }

    /** Newest first. Unread first is deliberately NOT done — recency is the useful order. */
    public static function listFor(PDO $conn, int $analystId, int $limit = self::LIST_LIMIT): array
    {
        $limit = max(1, min($limit, self::LIST_LIMIT));
        $stmt = $conn->prepare(
            "SELECT id, event_type, entity_type, entity_id, entity_ref, title, body,
                    actor_name, event_count, created_datetime, updated_datetime, read_datetime
             FROM notifications
             WHERE analyst_id = ?
             ORDER BY updated_datetime DESC, id DESC
             LIMIT $limit"
        );
        $stmt->execute([$analystId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as &$r) {
            $r['id']          = (int)$r['id'];
            $r['entity_id']   = (int)$r['entity_id'];
            $r['event_count'] = (int)$r['event_count'];
            $r['is_read']     = $r['read_datetime'] !== null;
            $r['link']        = self::linkFor($r['entity_type'], (int)$r['entity_id']);
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

    /** Mark specific ids read. Scoped to the analyst, so ids from elsewhere do nothing. */
    public static function markRead(PDO $conn, int $analystId, array $ids): int
    {
        $ids = array_values(array_filter(array_map('intval', $ids), fn($i) => $i > 0));
        if (!$ids) {
            return 0;
        }
        $place = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $conn->prepare(
            "UPDATE notifications SET read_datetime = UTC_TIMESTAMP()
             WHERE analyst_id = ? AND read_datetime IS NULL AND id IN ($place)"
        );
        $stmt->execute(array_merge([$analystId], $ids));
        return $stmt->rowCount();
    }

    public static function markAllRead(PDO $conn, int $analystId): int
    {
        $stmt = $conn->prepare(
            "UPDATE notifications SET read_datetime = UTC_TIMESTAMP()
             WHERE analyst_id = ? AND read_datetime IS NULL"
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
    public static function clear(PDO $conn, int $analystId, array $ids): int
    {
        $ids = array_values(array_filter(array_map('intval', $ids), fn($i) => $i > 0));
        if (!$ids) {
            return 0;
        }
        $place = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $conn->prepare(
            "DELETE FROM notifications WHERE analyst_id = ? AND id IN ($place)"
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
    public static function clearAll(PDO $conn, int $analystId, bool $includeUnread = false): int
    {
        $sql = "DELETE FROM notifications WHERE analyst_id = ?";
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
