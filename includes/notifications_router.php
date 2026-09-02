<?php
/**
 * Turns workflow events into notifications (discussion #55).
 *
 * Hooked into WorkflowEngine::dispatch(), which is the single funnel every
 * event in the product already passes through. That is the whole reason this
 * feature is small: the events exist, 48 call sites already fire them, and this
 * file only has to answer two questions per event —
 *
 *   1. who should be told?
 *   2. what does it say?
 *
 * ⚠️ ADDING A NEW NOTIFICATION TYPE SHOULD NOT MEAN EDITING CALL SITES. If an
 * event already dispatches, add it to NotificationsService::types() and give it
 * a case below. If it does not dispatch yet, add the dispatch — workflows get
 * the new trigger for free, which is the right trade either way.
 */

require_once __DIR__ . '/services/notifications.php';

/**
 * Who caused this? Resolved centrally from the session rather than threaded
 * through 48 dispatch payloads.
 *
 * This is what makes "never notify me about my own action" possible at all. A
 * web request is caused by whoever is signed in; a cron run is caused by nobody,
 * which is correct — a system-generated SLA breach SHOULD reach the assignee
 * even though the assignee owns the ticket.
 *
 * Safe after session_start(['read_and_close' => true]): the array stays in
 * memory for the rest of the request even though the file lock is gone.
 */
function notificationsCurrentActor(): array
{
    $id   = isset($_SESSION['analyst_id']) ? (int)$_SESSION['analyst_id'] : 0;
    $name = isset($_SESSION['analyst_name']) ? (string)$_SESSION['analyst_name'] : '';
    return [$id, $name !== '' ? $name : null];
}

/**
 * Entry point from WorkflowEngine::dispatch().
 *
 * Never throws: a notification must not be able to break the thing that caused
 * it. Never re-enters: writing a notification dispatches nothing.
 */
function notificationsHandleEvent(string $event, array $payload): void
{
    try {
        // Cheapest possible exit: most events are not notification types.
        $types = NotificationsService::types();
        if (!isset($types[$event])) {
            return;
        }
        // Rule 2 — bulk. Checked here as well as in notify() so a bulk run does
        // not pay for a recipient lookup per record.
        if (NotificationsService::inBulk()) {
            return;
        }

        [$actorId, $actorName] = notificationsCurrentActor();
        $recipientId = notificationsRecipientFor($event, $payload);

        if ($recipientId <= 0) {
            return;                       // unassigned, or we cannot tell who
        }
        // Rule 1 — your own action. Repeated here purely to avoid the display
        // lookup below; notify() enforces it authoritatively.
        if ($actorId > 0 && $actorId === $recipientId) {
            return;
        }

        $conn = connectToDatabase();
        if (!NotificationsService::typeEnabled($conn, $recipientId, $event)) {
            return;
        }

        $entityType = $types[$event]['entity'];
        $entity     = notificationsEntityFor($event, $payload, $entityType);
        if ($entity === null) {
            return;
        }

        NotificationsService::notify($conn, [
            'analyst_id'  => $recipientId,
            'event_type'  => $event,
            'entity_type' => $entityType,
            'entity_id'   => $entity['id'],
            'entity_ref'  => $entity['ref'],
            'title'       => $entity['title'],
            'body'        => notificationsBodyFor($event, $actorName),
            'actor_id'    => $actorId,
            'actor_name'  => $actorName,
        ]);
    } catch (Throwable $e) {
        error_log('[notificationsHandleEvent] ' . $e->getMessage());
    }
}

/**
 * Who gets told. Currently: the assignee, and only the assignee.
 *
 * ⚠️ Deliberate limitation, chosen over "everyone who ever touched it" because
 * the second is impossible to switch off and turns the bell into a firehose.
 * The known cost is that a ticket you worked all week goes quiet the moment it
 * is reassigned. A watch/follow table is the answer if that becomes a problem —
 * this function is the only place that would need to change.
 */
function notificationsRecipientFor(string $event, array $payload): int
{
    // On assignment the recipient is the NEW assignee, which is not yet the
    // value sitting in the ticket payload.
    if ($event === 'ticket.assigned') {
        return isset($payload['analyst_id']) ? (int)$payload['analyst_id'] : 0;
    }
    if (isset($payload['ticket']['assigned_analyst_id'])) {
        return (int)$payload['ticket']['assigned_analyst_id'];
    }

    /**
     * 🔴 COLLABORATORS MUST BE NAMED EXPLICITLY, BEFORE THE assignee_id FALLBACK
     * BELOW (GH #89). `task.collaborator_added` deliberately carries the OWNER in
     * `assignee_id` — that is what keeps stored workflows reading the field they
     * have always read — so without this branch the fallback would quietly send
     * "you were added to a task" to the owner instead of to the person who was
     * actually added. Both are real analysts and the notification would look
     * perfectly normal; only the recipient would be wrong.
     */
    if (($event === 'task.collaborator_added' || $event === 'task.collaborator_removed')
        && isset($payload['task']['collaborator_id'])) {
        return (int)$payload['task']['collaborator_id'];
    }

    if (isset($payload['task']['assignee_id'])) {
        return (int)$payload['task']['assignee_id'];
    }
    return 0;
}

/** Display data for the row: id, human reference, and what it is about. */
function notificationsEntityFor(string $event, array $payload, string $entityType): ?array
{
    if ($entityType === 'task') {
        $id = isset($payload['task']['id']) ? (int)$payload['task']['id'] : 0;
        if ($id <= 0) return null;
        return ['id' => $id, 'ref' => null, 'title' => $payload['task']['title'] ?? null];
    }

    $id = isset($payload['ticket']['id']) ? (int)$payload['ticket']['id'] : 0;
    if ($id <= 0) return null;

    // The ticket number is not in the dispatch payload, and it is what makes the
    // notification recognisable at a glance. One small read, and only once the
    // rules have already decided this notification is going to be written.
    $ref = null;
    try {
        $conn = connectToDatabase();
        $stmt = $conn->prepare("SELECT ticket_number FROM tickets WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        $found = $stmt->fetchColumn();
        if ($found !== false && $found !== null && $found !== '') {
            $ref = (string)$found;
        }
    } catch (Exception $e) {
        // A missing reference is cosmetic; the notification is still useful.
    }

    return ['id' => $id, 'ref' => $ref, 'title' => $payload['ticket']['subject'] ?? null];
}

/**
 * The one-line description.
 *
 * Kept as translatable keys resolved at render time rather than baked English in
 * the database — otherwise a row written today reads in whatever language the
 * writer happened to be using, forever.
 */
function notificationsBodyFor(string $event, ?string $actorName): string
{
    // The bell renders 'notifications.body.<event>' with {actor} substituted.
    // Stored as the raw actor so the string itself can be translated per reader.
    return $actorName !== null ? $actorName : '';
}
