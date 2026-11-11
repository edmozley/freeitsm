<?php
/**
 * Live updates (GitHub #267) - "has anything I am showing changed?", for every page at once.
 *
 * One heartbeat per page (assets/js/live-pulse.js) asks api/system/pulse.php for the
 * signatures of the things on screen - the ticket list, the open ticket, the tasks board,
 * the open task, the bell - and each part of the page repaints itself when its signature
 * moves. Before this every part polled in its own way, or not at all.
 *
 * A signature is a hash of a few "newest change" values, each one indexed query that
 * walks from the newest row and stops at the first visible one:
 *
 *   tickets        newest ticket update, message, note and history row the analyst can see
 *   ticket:N       that ticket's update time, deletion, newest message, note and history row
 *   tasks          newest task update and history row, and how many tasks there are (a delete)
 *   task:N         that task's update time, newest comment, history event and audit row
 *   notifications  the analyst's unread count and newest change
 *
 * 🔒 Only a hash goes back - never an id, a time or a count - so a signature says nothing
 * a refresh would not show. Every topic is gated by the module and scoped to the
 * analyst's companies exactly as the list it stands for; a ticket or task the analyst
 * cannot open answers null, the same as one that does not exist.
 *
 * TRAP: a write that changes a ticket or task without touching its updated_datetime, or
 * writing a message, note or history row, is invisible here. Every writer found does one
 * of them; a new writer that does neither will not refresh other people's screens.
 *
 * @wiki Live-Updates
 */

require_once __DIR__ . '/tenancy.php';

/** The most topics one heartbeat may ask about - a page shows a handful. */
const LIVE_PULSE_MAX_TOPICS = 12;

/**
 * Signatures for the topics asked about, keyed as asked. Unknown topics are left out;
 * a topic the analyst may not see is null.
 *
 * @param string[] $topics  e.g. ['tickets', 'ticket:42', 'notifications']
 */
function livePulseSignatures(PDO $conn, int $analystId, array $topics): array
{
    $out = [];
    foreach (array_slice(array_values(array_unique($topics)), 0, LIVE_PULSE_MAX_TOPICS) as $topic) {
        $topic = (string)$topic;
        try {
            if ($topic === 'tickets')            $out[$topic] = livePulseTickets($conn, $analystId);
            elseif ($topic === 'tasks')          $out[$topic] = livePulseTasks($conn, $analystId);
            elseif ($topic === 'notifications')  $out[$topic] = livePulseNotifications($conn, $analystId);
            elseif (preg_match('/^ticket:(\d+)$/', $topic, $m)) $out[$topic] = livePulseTicket($conn, $analystId, (int)$m[1]);
            elseif (preg_match('/^task:(\d+)$/', $topic, $m))   $out[$topic] = livePulseTask($conn, $analystId, (int)$m[1]);
        } catch (Throwable $e) {
            error_log('[live_pulse] ' . $topic . ': ' . $e->getMessage());
            $out[$topic] = null;   // a part-upgraded install: that part simply does not refresh
        }
    }
    return $out;
}

/** One value: the first column of the first row, or ''. */
function livePulseOne(PDO $conn, string $sql, array $params): string
{
    $st = $conn->prepare($sql);
    $st->execute($params);
    $v = $st->fetchColumn();
    return $v === false || $v === null ? '' : (string)$v;
}

/** The ticket list: what any change to a visible ticket moves. */
function livePulseTickets(PDO $conn, int $analystId): ?string
{
    if (!analystCanAccessModule($conn, $analystId, 'tickets')) return null;
    [$f, $p] = ticketTenantFilter($conn, $analystId, 't');
    $parts = [
        // Deleted rows count too: binning a ticket must take it off other lists.
        livePulseOne($conn, "SELECT t.updated_datetime FROM tickets t WHERE 1 = 1{$f} ORDER BY t.updated_datetime DESC LIMIT 1", $p),
        livePulseOne($conn, "SELECT t.id FROM tickets t WHERE 1 = 1{$f} ORDER BY t.id DESC LIMIT 1", $p),
        livePulseOne($conn, "SELECT e.id FROM emails e JOIN tickets t ON t.id = e.ticket_id WHERE 1 = 1{$f} ORDER BY e.id DESC LIMIT 1", $p),
        livePulseOne($conn, "SELECT n.id FROM ticket_notes n JOIN tickets t ON t.id = n.ticket_id WHERE 1 = 1{$f} ORDER BY n.id DESC LIMIT 1", $p),
        livePulseOne($conn, "SELECT a.id FROM ticket_audit a JOIN tickets t ON t.id = a.ticket_id WHERE 1 = 1{$f} ORDER BY a.id DESC LIMIT 1", $p),
    ];
    return md5('tickets|' . implode('|', $parts));
}

/** One ticket - the open one. Null when it is gone or out of reach. */
function livePulseTicket(PDO $conn, int $analystId, int $ticketId): ?string
{
    if ($ticketId <= 0 || !analystCanAccessModule($conn, $analystId, 'tickets')) return null;
    $row = $conn->prepare("SELECT updated_datetime, deleted_datetime FROM tickets WHERE id = ?");
    $row->execute([$ticketId]);
    $t = $row->fetch(PDO::FETCH_ASSOC);
    // analystCanAccessTicket() is a company check, true for any id on a single-company
    // install - so the row is read first (found in #277).
    if (!$t || !analystCanAccessTicket($conn, $analystId, $ticketId)) return null;
    $parts = [
        (string)$t['updated_datetime'], (string)$t['deleted_datetime'],
        livePulseOne($conn, "SELECT MAX(id) FROM emails WHERE ticket_id = ?", [$ticketId]),
        livePulseOne($conn, "SELECT MAX(id) FROM ticket_notes WHERE ticket_id = ?", [$ticketId]),
        livePulseOne($conn, "SELECT MAX(id) FROM ticket_audit WHERE ticket_id = ?", [$ticketId]),
    ];
    return md5("ticket:$ticketId|" . implode('|', $parts));
}

/** The tasks board. */
function livePulseTasks(PDO $conn, int $analystId): ?string
{
    if (!analystCanAccessModule($conn, $analystId, 'tasks')) return null;
    [$f, $p] = activeTenantReadFilter($conn, $analystId, 't');
    $parts = [
        livePulseOne($conn, "SELECT t.updated_datetime FROM tasks t WHERE 1 = 1{$f} ORDER BY t.updated_datetime DESC LIMIT 1", $p),
        livePulseOne($conn, "SELECT COUNT(*) FROM tasks t WHERE 1 = 1{$f}", $p),
        livePulseOne($conn, "SELECT e.id FROM task_events e JOIN tasks t ON t.id = e.task_id WHERE 1 = 1{$f} ORDER BY e.id DESC LIMIT 1", $p),
    ];
    return md5('tasks|' . implode('|', $parts));
}

/** One task - the open one. */
function livePulseTask(PDO $conn, int $analystId, int $taskId): ?string
{
    if ($taskId <= 0 || !analystCanAccessModule($conn, $analystId, 'tasks')) return null;
    $row = $conn->prepare("SELECT updated_datetime FROM tasks WHERE id = ?");
    $row->execute([$taskId]);
    $updated = $row->fetchColumn();
    if ($updated === false || !analystCanAccessTask($conn, $analystId, $taskId)) return null;
    $parts = [
        (string)$updated,
        livePulseOne($conn, "SELECT MAX(id) FROM task_comments WHERE task_id = ?", [$taskId]),
        livePulseOne($conn, "SELECT MAX(id) FROM task_events WHERE task_id = ?", [$taskId]),
        livePulseOne($conn, "SELECT MAX(id) FROM task_audit WHERE task_id = ?", [$taskId]),
        livePulseOne($conn, "SELECT COUNT(*) FROM tasks WHERE parent_task_id = ?", [$taskId]),   // a subtask added or removed
    ];
    return md5("task:$taskId|" . implode('|', $parts));
}

/** The bell: the analyst's own notifications. */
function livePulseNotifications(PDO $conn, int $analystId): ?string
{
    $parts = [
        livePulseOne($conn, "SELECT COUNT(*) FROM notifications WHERE analyst_id = ? AND read_datetime IS NULL", [$analystId]),
        livePulseOne($conn, "SELECT MAX(updated_datetime) FROM notifications WHERE analyst_id = ? AND read_datetime IS NULL", [$analystId]),
    ];
    return md5("notifications|$analystId|" . implode('|', $parts));
}

/**
 * The analyst's heartbeat in seconds, from Preferences → Live updates: 15 (the default),
 * 30, 60 or 0 for off. Anything else reads as the default.
 */
function livePulseSeconds(PDO $conn, int $analystId): int
{
    try {
        $st = $conn->prepare("SELECT preference_value FROM user_preferences WHERE analyst_id = ? AND preference_key = 'live_updates'");
        $st->execute([$analystId]);
        $v = $st->fetchColumn();
    } catch (Throwable) {
        $v = false;
    }
    return in_array((string)$v, ['15', '30', '60', '0'], true) ? (int)$v : 15;
}
