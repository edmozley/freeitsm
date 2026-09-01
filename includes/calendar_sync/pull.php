<?php
/**
 * Reading changes back OUT of an analyst's calendar (GH #75, bi-directional).
 *
 * The case this exists for, in Ed's words: you are on the train, you look at
 * your phone, and the work in your calendar turns out not to be needed. You
 * delete it there, and by the time you sit down at your desk FreeITSM is
 * already clean. Moving something you have been asked to do later works the
 * same way.
 *
 * ── THE THREE GUARDS, AND WHY EACH ONE EXISTS ───────────────────────────────
 *
 * 1. 🔴 NOTHING IS APPLIED ON A BASELINE. A provider that has lost its place —
 *    an expired delta token, a moved mailbox, a revoked permission — answers
 *    with everything or with nothing. Code that read "absent" as "deleted"
 *    would unschedule an entire service desk because a token expired. Absence
 *    of history is not evidence of deletion.
 *
 * 2. 🔴 A CAP ON DELETIONS PER RUN. More than a handful at once is a symptom,
 *    not an instruction. We stop, change nothing, and record it — because the
 *    difference between "somebody cleared their week" and "something has gone
 *    wrong" cannot be told apart from here, and only one of those is safe to
 *    act on.
 *
 * 3. 🔴 EVERY CHANGE IS AUDITED. An unschedule that arrived from a phone on a
 *    train is otherwise a ticket that changed with no record of why. The audit
 *    row names the calendar it came from.
 *
 * ⚠️ ECHO SUPPRESSION IS BY COMPARISON, NOT BY MARKER. Our own push updates the
 * event, which comes back in the next delta as a change. Rather than tagging
 * our writes and hoping the tag survives, we simply compare what the calendar
 * says to what the ticket already says: equal means nothing to do. That is
 * immune to a lost marker, and it also means a change we somehow missed gets
 * picked up rather than suppressed.
 */

require_once __DIR__ . '/push.php';

/** system_settings key: does deleting the event unschedule the ticket? */
const CALENDAR_ACCEPT_DELETES = 'tickets_calendar_accept_deletes';

/**
 * Deletions honoured in one run, per analyst, before we assume something is
 * wrong rather than deliberate.
 */
const CALENDAR_DELETE_CAP = 5;

/** Off by default: whether a personal tidy-up may reach shared data is an
 *  organisation's call, and the safe answer is the one that changes nothing. */
function calendarAcceptDeletes(PDO $conn): bool
{
    try {
        $st = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ?");
        $st->execute([CALENDAR_ACCEPT_DELETES]);
        return (string)$st->fetchColumn() === '1';
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Poll one analyst's calendar and apply what came back.
 *
 * @return array a small report, for the cron's output and for tests
 */
function calendarSyncPullForAnalyst(PDO $conn, int $analystId): array
{
    $report = ['analyst_id' => $analystId, 'baseline' => false,
               'moved' => 0, 'unscheduled' => 0, 'skipped' => 0, 'error' => null];

    $enrolment = calendarSyncEnrolment($conn, $analystId);
    if (($enrolment['mode'] ?? '') !== CALENDAR_MODE_PUSH) return $report;

    $connection = calendarSyncActiveConnection($conn);
    if (!$connection) return $report;

    try {
        $provider = calendarSyncProviderFor($connection);
        $provider->conn = $conn;
        $result = $provider->pollChanges($enrolment['calendar_address'], $enrolment['delta_token'] ?: null);
    } catch (Exception $e) {
        calendarSyncRecordError($conn, $analystId, $e->getMessage());
        $report['error'] = $e->getMessage();
        return $report;
    }

    // Always store the new token, even on a baseline — that IS the baseline.
    $conn->prepare(
        "UPDATE calendar_enrolments SET delta_token = ?, delta_synced_datetime = UTC_TIMESTAMP() WHERE analyst_id = ?"
    )->execute([$result['token'], $analystId]);

    if (!empty($result['baseline'])) {
        // GUARD 1. We have just learned where we are; we have learned nothing
        // about what changed. Apply nothing.
        $report['baseline'] = true;
        return $report;
    }

    // Which of our events these ids belong to. Anything we did not create is
    // somebody's own appointment and none of our business.
    $ids = array_merge(
        array_column($result['changed'], 'remote_event_id'),
        $result['removed']
    );
    if (!$ids) return $report;

    $in = implode(',', array_fill(0, count($ids), '?'));
    $mine = [];

    // ⚠️ TWO lookups, not one join. A row belongs to a ticket OR a task, so a
    // single query would need an OUTER join to both tables and a pile of
    // COALESCE to work out which columns meant anything. `_entity` carries the
    // answer explicitly instead — the alternative is every branch below
    // re-deriving it from which column happens to be null.
    $st = $conn->prepare(
        "SELECT s.*, 'ticket' AS _entity, t.ticket_number AS _ref, NULL AS due_date,
                t.work_start_datetime, t.work_end_datetime, t.work_all_day
           FROM calendar_sync_events s
           JOIN tickets t ON t.id = s.ticket_id
          WHERE s.analyst_id = ? AND s.remote_event_id IN ($in)"
    );
    $st->execute(array_merge([$analystId], $ids));
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) $mine[$row['remote_event_id']] = $row;

    $st = $conn->prepare(
        "SELECT s.*, 'task' AS _entity, tk.title AS _ref, tk.due_date,
                tk.work_start_datetime, tk.work_end_datetime, tk.work_all_day
           FROM calendar_sync_events s
           JOIN tasks tk ON tk.id = s.task_id
          WHERE s.analyst_id = ? AND s.remote_event_id IN ($in)"
    );
    $st->execute(array_merge([$analystId], $ids));
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) $mine[$row['remote_event_id']] = $row;

    // ── Moves ───────────────────────────────────────────────────────────────
    foreach ($result['changed'] as $change) {
        $row = $mine[$change['remote_event_id']] ?? null;
        if (!$row) { $report['skipped']++; continue; }

        // A DUE-DATE event is a different comparison: it is an all-day banner,
        // so only the date matters, and the thing it maps to is a date column
        // rather than a start/end pair. Comparing it like a work slot would call
        // every refresh a move and rewrite the due date on every poll.
        if ($row['_entity'] === 'task' && (string)$row['kind'] === 'due') {
            $wasDay = substr((string)$row['due_date'], 0, 10);
            $newDay = substr((string)$change['start'], 0, 10);
            if ($wasDay === $newDay) { $report['skipped']++; continue; }   // echo
            calendarPullApplyTaskDue($conn, $row, $newDay, $enrolment['calendar_address']);
            $report['moved']++;
            continue;
        }

        $sameStart = substr((string)$row['work_start_datetime'], 0, 16) === substr($change['start'], 0, 16);
        $sameEnd   = substr((string)$row['work_end_datetime'], 0, 16)   === substr($change['end'], 0, 16);
        $sameAllDay = ((int)$row['work_all_day'] === 1) === (bool)$change['all_day'];
        if ($sameStart && $sameEnd && $sameAllDay) {
            // ECHO — this is our own push coming back. Nothing to do.
            $report['skipped']++;
            continue;
        }

        if ($row['_entity'] === 'task') {
            calendarPullApplyTaskWork($conn, $row, $change, $enrolment['calendar_address']);
        } else {
            calendarPullApply($conn, $row, $change, $enrolment['calendar_address']);
        }
        $report['moved']++;
    }

    // ── Deletions ───────────────────────────────────────────────────────────
    $removedMine = array_values(array_filter($result['removed'], fn($id) => isset($mine[$id])));
    if (!$removedMine) return $report;

    if (!calendarAcceptDeletes($conn)) {
        // Switched off. The event is gone from their calendar but the ticket is
        // still scheduled, so the next change to it will put a fresh event back
        // — which is the documented behaviour, not a bug.
        $report['skipped'] += count($removedMine);
        return $report;
    }

    // GUARD 2. A handful is somebody clearing their afternoon. Thirty is a
    // symptom. Refuse the lot rather than guessing which.
    if (count($removedMine) > CALENDAR_DELETE_CAP) {
        $msg = 'Ignored ' . count($removedMine) . ' calendar deletions in one run — more than the safety limit of '
             . CALENDAR_DELETE_CAP . '. Nothing was unscheduled. If this was deliberate, unschedule them in FreeITSM.';
        calendarSyncRecordError($conn, $analystId, $msg);
        $report['error'] = $msg;
        $report['skipped'] += count($removedMine);
        return $report;
    }

    foreach ($removedMine as $id) {
        $row = $mine[$id];
        // Drop our map row FIRST so the reconcile that follows the update does
        // not try to delete an event that is already gone, and does not put a
        // replacement back.
        $conn->prepare("DELETE FROM calendar_sync_events WHERE id = ?")->execute([(int)$row['id']]);
        if ($row['_entity'] === 'task') {
            calendarPullUnscheduleTask($conn, $row, $analystId, $enrolment['calendar_address']);
        } else {
            calendarPullUnschedule($conn, (int)$row['ticket_id'], $analystId, $enrolment['calendar_address']);
        }
        $report['unscheduled']++;
    }
    return $report;
}

/** Apply a moved event to its ticket. */
function calendarPullApply(PDO $conn, array $row, array $change, string $address): void
{
    $conn->prepare(
        "UPDATE tickets SET work_start_datetime = ?, work_end_datetime = ?, work_all_day = ? WHERE id = ?"
    )->execute([$change['start'], $change['end'], $change['all_day'] ? 1 : 0, (int)$row['ticket_id']]);

    // GUARD 3. Otherwise this is a ticket that moved with no record of why.
    calendarPullAudit($conn, (int)$row['ticket_id'], (int)$row['analyst_id'], 'Scheduled',
        (string)$row['work_start_datetime'], $change['start'] . ' (moved in ' . $address . ')');

    $conn->prepare("UPDATE calendar_sync_events SET updated_datetime = UTC_TIMESTAMP() WHERE id = ?")
         ->execute([(int)$row['id']]);
}

/** Clear a ticket's schedule because its event was deleted. */
function calendarPullUnschedule(PDO $conn, int $ticketId, int $analystId, string $address): void
{
    $before = $conn->query("SELECT work_start_datetime FROM tickets WHERE id = " . (int)$ticketId)->fetchColumn();
    $conn->prepare(
        "UPDATE tickets SET work_start_datetime = NULL, work_end_datetime = NULL, work_all_day = 0 WHERE id = ?"
    )->execute([$ticketId]);
    calendarPullAudit($conn, $ticketId, $analystId, 'Scheduled', (string)$before,
        'cleared (removed from ' . $address . ')');
}

/**
 * A ticket_audit row, best effort.
 *
 * Written directly rather than through TicketsService, because the service's
 * update would trigger a reconcile that pushes straight back to the calendar we
 * are reading from — the loop this whole file is careful to avoid.
 */
function calendarPullAudit(PDO $conn, int $ticketId, int $analystId, string $field, string $old, string $new): void
{
    try {
        $conn->prepare(
            "INSERT INTO ticket_audit (ticket_id, analyst_id, field_name, old_value, new_value, created_datetime)
             VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP())"
        )->execute([$ticketId, $analystId, $field, substr($old, 0, 500), substr($new, 0, 500)]);
    } catch (Exception $e) {
        // An audit we cannot write must not stop the change the analyst asked for.
    }
}

// ── Tasks (#75) ─────────────────────────────────────────────────────────────
//
// 🔴 A CHANGE MADE OUTSIDE FREEITSM. Everything below writes to a task because
// somebody dragged something in Outlook, so every one of them leaves an audit
// row marked `calendar`. Tasks had NO history at all before this, which is
// exactly why it had to be added: a due date that moves with no record of who
// moved it or where is worse than one that cannot move.

/** A task's work window, moved in the calendar. */
function calendarPullApplyTaskWork(PDO $conn, array $row, array $change, string $address): void
{
    $conn->prepare(
        "UPDATE tasks SET work_start_datetime = ?, work_end_datetime = ?, work_all_day = ?,
                          updated_datetime = UTC_TIMESTAMP()
          WHERE id = ?"
    )->execute([$change['start'], $change['end'], $change['all_day'] ? 1 : 0, (int)$row['task_id']]);

    calendarPullTaskAudit($conn, (int)$row['task_id'], (int)$row['analyst_id'], 'Scheduled',
        (string)$row['work_start_datetime'], $change['start'] . ' (moved in ' . $address . ')');
}

/**
 * A task's DUE DATE, moved in the calendar.
 *
 * ⚠️ A due date is a commitment rather than a plan — it is often a promise to
 * somebody else. Ed asked for it to be editable from Outlook anyway, so it is;
 * the audit row is what makes that safe to live with, because otherwise a
 * deadline could move and nobody would ever know where it went.
 */
function calendarPullApplyTaskDue(PDO $conn, array $row, string $newDay, string $address): void
{
    $conn->prepare("UPDATE tasks SET due_date = ?, updated_datetime = UTC_TIMESTAMP() WHERE id = ?")
         ->execute([$newDay, (int)$row['task_id']]);

    calendarPullTaskAudit($conn, (int)$row['task_id'], (int)$row['analyst_id'], 'Due date',
        substr((string)$row['due_date'], 0, 10), $newDay . ' (moved in ' . $address . ')');
}

/** The event was deleted from the calendar: take the matching field away. */
function calendarPullUnscheduleTask(PDO $conn, array $row, int $analystId, string $address): void
{
    $taskId = (int)$row['task_id'];
    if ((string)$row['kind'] === 'due') {
        $conn->prepare("UPDATE tasks SET due_date = NULL, updated_datetime = UTC_TIMESTAMP() WHERE id = ?")
             ->execute([$taskId]);
        calendarPullTaskAudit($conn, $taskId, $analystId, 'Due date',
            substr((string)$row['due_date'], 0, 10), 'cleared (deleted in ' . $address . ')');
        return;
    }
    $conn->prepare(
        "UPDATE tasks SET work_start_datetime = NULL, work_end_datetime = NULL,
                          updated_datetime = UTC_TIMESTAMP()
          WHERE id = ?"
    )->execute([$taskId]);
    calendarPullTaskAudit($conn, $taskId, $analystId, 'Scheduled',
        (string)$row['work_start_datetime'], 'cleared (deleted in ' . $address . ')');
}

/** One line of task history, marked as having come from a calendar. */
function calendarPullTaskAudit(PDO $conn, int $taskId, int $analystId, string $field, string $old, string $new): void
{
    try {
        $conn->prepare(
            "INSERT INTO task_audit (task_id, analyst_id, field_name, old_value, new_value, source, created_datetime)
             VALUES (?, ?, ?, ?, ?, 'calendar', UTC_TIMESTAMP())"
        )->execute([$taskId, $analystId, $field, substr($old, 0, 500), substr($new, 0, 500)]);
    } catch (Exception $e) {
        // An audit we cannot write must not stop the change — but this is the
        // one place where losing it matters most, so it is also logged.
        error_log('Task audit failed for task ' . $taskId . ': ' . $e->getMessage());
    }
}

/** Every enrolled analyst. Returns one report per person. */
function calendarSyncPullAll(PDO $conn): array
{
    if (!calendarSyncSchemaReady($conn)) return [];
    $ids = $conn->query("SELECT analyst_id FROM calendar_enrolments WHERE mode = 'push'")
                ->fetchAll(PDO::FETCH_COLUMN);
    $out = [];
    foreach ($ids as $id) $out[] = calendarSyncPullForAnalyst($conn, (int)$id);
    return $out;
}

// ─── Change notifications (optional accelerator) ────────────────────────────
//
// ⚠️ NOT AN ALTERNATIVE TO POLLING. A notification says only that something
// changed — what changed is still read with a delta query. And notifications go
// missing: the provider drops them, the endpoint is down for a deploy, a
// subscription lapses. A silent gap then looks exactly like "nothing changed",
// so the cron keeps polling as a backstop and the notifications simply make the
// common case near-instant.

/** system_settings key: the public HTTPS URL Graph should call. */
const CALENDAR_NOTIFY_URL = 'tickets_calendar_notify_url';

/**
 * The configured notification endpoint, or '' when notifications are off.
 *
 * 🔑 AN ADMIN HAS TO TYPE IT. FreeITSM cannot work out what URL the outside
 * world can reach it on — HTTP_HOST is whatever the last request happened to
 * use, and behind a proxy or a tunnel it is routinely wrong. Guessing here would
 * produce a subscription that silently never fires.
 */
function calendarNotifyUrl(PDO $conn): string
{
    try {
        $st = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ?");
        $st->execute([CALENDAR_NOTIFY_URL]);
        $v = trim((string)$st->fetchColumn());
    } catch (Exception $e) {
        return '';
    }
    // Graph refuses anything that is not HTTPS, so an http:// value here would
    // fail at subscription time with a message about validation rather than
    // about the scheme. Reject it where it can be explained.
    return (stripos($v, 'https://') === 0) ? $v : '';
}

/**
 * Bring one analyst's subscription into line: create, renew or remove.
 *
 * Idempotent, and safe to call every cron run — it does nothing at all unless
 * something needs doing.
 *
 * @return string what happened, for the cron's output
 */
function calendarSyncEnsureSubscription(PDO $conn, int $analystId): string
{
    $notifyUrl  = calendarNotifyUrl($conn);
    $enrolment  = calendarSyncEnrolment($conn, $analystId);
    $subId      = $enrolment['subscription_id'] ?? null;
    $connection = calendarSyncActiveConnection($conn);

    $wanted = $notifyUrl !== ''
           && ($enrolment['mode'] ?? '') === CALENDAR_MODE_PUSH
           && !empty($enrolment['calendar_address'])
           && $connection;

    // Not wanted but present — an analyst opted out, or notifications were
    // switched off. Take it down rather than leaving Graph calling an endpoint
    // about somebody who is no longer syncing.
    if (!$wanted) {
        if ($subId && $connection) {
            try {
                $p = calendarSyncProviderFor($connection); $p->conn = $conn;
                $p->deleteSubscription($subId);
            } catch (Exception $e) {
                // Already gone, or unreachable. Either way we forget it below:
                // keeping a row we cannot act on means retrying for ever.
            }
            calendarClearSubscription($conn, $analystId);
            return 'removed';
        }
        return 'none';
    }

    // Renew a little early. Renewing at the last moment means a blip in the cron
    // costs you the subscription; six hours of slack costs nothing.
    $expires = $enrolment['subscription_expires'] ?? null;
    if ($subId && $expires && strtotime($expires) > time() + 6 * 3600) {
        return 'ok';
    }

    try {
        $p = calendarSyncProviderFor($connection); $p->conn = $conn;

        if ($subId) {
            try {
                $res = $p->renewSubscription($subId);
                calendarStoreSubscription($conn, $analystId, $res['id'], $res['expires'],
                                          $enrolment['subscription_secret']);
                return 'renewed';
            } catch (CalendarSubscriptionMissing $e) {
                $subId = null;                       // lapsed — fall through and create
            }
        }

        // A fresh secret each time: a subscription being recreated is the natural
        // moment to rotate the one thing protecting the public endpoint.
        $secret = bin2hex(random_bytes(24));
        $res = $p->createSubscription($enrolment['calendar_address'], $notifyUrl, $secret);
        calendarStoreSubscription($conn, $analystId, $res['id'], $res['expires'], $secret);
        return 'created';
    } catch (Exception $e) {
        calendarSyncRecordError($conn, $analystId, 'Notifications: ' . $e->getMessage());
        return 'failed';
    }
}

function calendarStoreSubscription(PDO $conn, int $analystId, string $id, string $expires, ?string $secret): void
{
    // ⚠️ CLEARING last_error IS PART OF SUCCEEDING. Subscribing writes the failure
    // to last_error, but this — the success path for both create and renew — used
    // to leave it standing, so a system that failed once and then recovered kept
    // showing a red "failed" pill until the next successful push happened to clear
    // it. A stale error is worse than none: it sends you looking for a fault that
    // has already been fixed.
    $conn->prepare(
        "UPDATE calendar_enrolments
            SET subscription_id = ?, subscription_expires = ?, subscription_secret = ?,
                last_error = NULL
          WHERE analyst_id = ?"
    )->execute([$id, $expires, $secret, $analystId]);
}

function calendarClearSubscription(PDO $conn, int $analystId): void
{
    $conn->prepare(
        "UPDATE calendar_enrolments
            SET subscription_id = NULL, subscription_expires = NULL, subscription_secret = NULL
          WHERE analyst_id = ?"
    )->execute([$analystId]);
}

/**
 * Every enrolled analyst's subscription, plus any left behind by someone who has
 * since opted out.
 */
function calendarSyncEnsureAllSubscriptions(PDO $conn): array
{
    if (!calendarSyncSchemaReady($conn)) return [];
    $ids = $conn->query(
        "SELECT analyst_id FROM calendar_enrolments
          WHERE mode = 'push' OR subscription_id IS NOT NULL"
    )->fetchAll(PDO::FETCH_COLUMN);
    $out = [];
    foreach ($ids as $id) $out[(int)$id] = calendarSyncEnsureSubscription($conn, (int)$id);
    return $out;
}
