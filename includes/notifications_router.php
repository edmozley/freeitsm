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

        $conn       = connectToDatabase();
        $recipients = notificationsAudienceFor($conn, $event, $payload);
        if (!$recipients) {
            return;                       // unassigned, or we cannot tell who
        }

        $entityType = $types[$event]['entity'];
        $entity     = notificationsEntityFor($event, $payload, $entityType);
        if ($entity === null) {
            return;
        }

        // The assignment email (2.10.0) - its own personal switch, separate from
        // the bell's, because somebody can want the email and not the bell or
        // the other way round.
        if ($event === 'task.assigned') {
            notificationsTaskAssignedEmail($conn, $payload, $actorId, $actorName);
        }

        foreach ($recipients as $recipientId) {
            // Rule 1 — your own action, applied PER PERSON. This is what makes a
            // multi-recipient event behave: comment on a task and the other four
            // people on it are told while you are not, rather than the whole
            // event being dropped because the actor happened to be in the list.
            // notify() enforces it authoritatively; this is the cheap pre-check.
            if ($actorId > 0 && $actorId === $recipientId) {
                continue;
            }
            // Each person's OWN toggles decide. Somebody who has turned status
            // changes off still gets the comments they left on.
            if (!NotificationsService::typeEnabled($conn, $recipientId, $event)) {
                continue;
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
        }
    } catch (Throwable $e) {
        error_log('[notificationsHandleEvent] ' . $e->getMessage());
    }
}

/**
 * EVERYBODY who gets told about this event.
 *
 * ⭐ THE "WATCH/FOLLOW TABLE" THIS FILE PREDICTED NOW EXISTS. The note below used
 * to say the assignee was the only recipient, that "everyone who ever touched it"
 * was rejected because it is impossible to switch off, and that a watch/follow
 * table would be the answer — with this function the only place needing to
 * change. GH #89 built exactly that table for tasks: `task_collaborators` is a
 * DELIBERATE list of people, put there by hand, and removable. So the task events
 * that describe the task itself now reach the owner AND everybody involved.
 *
 * 🔴 THE DISTINCTION THAT KEEPS IT FROM BECOMING A FIREHOSE: an event about ONE
 * PERSON'S place on a task goes to that person alone. Being handed a task, or
 * being put on one, is news for the individual — sending "a task was assigned to
 * me" to four other people would be both noisy and false. Events about the TASK —
 * a comment, a status move, the due date, completion — go to everyone on it.
 *
 * Tickets are unchanged and still notify the assignee alone: there is no
 * equivalent list of people on a ticket, and inventing one from "who has touched
 * it" is the firehose that was rejected.
 *
 * @return array<int> analyst ids, de-duplicated. Empty when nobody can be named.
 */
function notificationsAudienceFor(PDO $conn, string $event, array $payload): array
{
    // Project proposals (3.3.0): the dispatcher works out who - the approvers, or
    // whoever proposed it - because "who approves" is a Projects setting.
    if (strpos($event, 'project.') === 0 && array_key_exists('notify_ids', $payload)) {
        return array_values(array_unique(array_filter(array_map('intval', (array)($payload['notify_ids'] ?? [])))));
    }

    // Everyone on the task: the owner, plus the people listed as Involved.
    $taskWide = [
        'task.completed', 'task.comment_added',
        'task.status_changed', 'task.due_date_changed',
    ];

    if (in_array($event, $taskWide, true)) {
        $taskId = isset($payload['task']['id']) ? (int)$payload['task']['id'] : 0;
        $ids    = [];
        $owner  = isset($payload['task']['assignee_id']) ? (int)$payload['task']['assignee_id'] : 0;
        if ($owner > 0) {
            $ids[] = $owner;
        }
        if ($taskId > 0) {
            try {
                $stmt = $conn->prepare("SELECT analyst_id FROM task_collaborators WHERE task_id = ?");
                $stmt->execute([$taskId]);
                foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
                    $ids[] = (int)$id;
                }
            } catch (Exception $e) {
                // An install that has not run Database Verification since
                // upgrading has no table. The owner still gets told — a missing
                // table must narrow the audience, never empty it.
            }
        }
        return array_values(array_unique(array_filter($ids)));
    }

    // A ticket landing in a team's queue goes to EVERY MEMBER of that team
    // (#1566). That is the whole point of the feature: the service desk does not
    // know who in Infrastructure handles what, so the team is told and whoever
    // picks it up assigns themselves.
    //
    // 🔑 Rule 1 is applied per person by the caller, so the analyst who did the
    // escalating is not told about their own action even when they are a member
    // of the receiving team.
    //
    // ⚠️ An empty team — or a missing table on an install that has not run
    // Database Verification — returns nobody, and nobody is notified. A missing
    // table must narrow the audience, never widen it.
    if ($event === 'ticket.team_assigned') {
        $teamId = isset($payload['team_id']) ? (int)$payload['team_id'] : 0;
        if ($teamId <= 0) {
            return [];
        }
        try {
            $stmt = $conn->prepare("SELECT analyst_id FROM analyst_teams WHERE team_id = ?");
            $stmt->execute([$teamId]);
            $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        } catch (Exception $e) {
            return [];
        }
        return array_values(array_unique(array_filter($ids)));
    }

    $one = notificationsRecipientFor($event, $payload);
    return $one > 0 ? [$one] : [];
}

/**
 * The ONE person an event is about, for events that are about one person.
 *
 * Kept separate from the audience above because these two answer different
 * questions: this one is "whose news is this?", and it is also what the
 * ticket events — which have no list of involved people — still use.
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
    // Domains (#154): whoever owns the domain. An ownerless domain has no bell
    // to ring — the alert e-mail list is what covers those.
    if (isset($payload['domain']['owner_analyst_id'])) {
        return (int)$payload['domain']['owner_analyst_id'];
    }
    // Projects (3.2.0): the project manager. A project with none has nobody to tell.
    if (isset($payload['project']['owner_analyst_id'])) {
        return (int)$payload['project']['owner_analyst_id'];
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

    if ($entityType === 'domain') {
        $id = isset($payload['domain']['id']) ? (int)$payload['domain']['id'] : 0;
        if ($id <= 0) return null;
        // The title says what happened, because the domain name alone does not.
        $name = (string)($payload['domain']['name'] ?? '');
        if ($event === 'domain.changed') {
            $title = $name . ': ' . mb_substr((string)($payload['summary'] ?? 'changed'), 0, 200);
        } else {
            $d = (int)($payload['days_remaining'] ?? 0);
            $what = $event === 'domain.ssl_expiring' ? 'certificate' : 'registration';
            $title = $name . ' - ' . $what . ($d < 0 ? ' expired ' . (-$d) . ' day(s) ago' : ($d === 0 ? ' expires today' : ' expires in ' . $d . ' day(s)'));
        }
        return ['id' => $id, 'ref' => mb_substr($name, 0, 64), 'title' => $title];
    }

    if ($entityType === 'project') {
        $id = isset($payload['project']['id']) ? (int)$payload['project']['id'] : 0;
        if ($id <= 0) return null;
        // As with domains, the title says what happened - the name alone does not.
        // English, like the domain titles: it is stored.
        $name = (string)($payload['project']['name'] ?? '');
        $stage = (string)($payload['stage']['name'] ?? '');
        switch ($event) {
            case 'project.health_changed':
                $title = $name . ': ' . ($payload['from'] ?? '?') . ' to ' . ($payload['to'] ?? '?');
                break;
            case 'project.tolerance_breached':
                $title = $payload['kind'] === 'cost'
                    ? $name . ': ' . (($payload['basis'] ?? '') === 'forecast' ? 'forecast ' : '') . (int)$payload['over_pct'] . '% over budget (allowed ' . (int)$payload['allowed'] . '%)'
                    : ($payload['kind'] === 'risk'
                    ? $name . ': a risk scores ' . (int)$payload['score'] . ' (allowed ' . (int)$payload['allowed'] . ')'
                    : $name . ': ' . (int)$payload['late_days'] . ' day(s) late' . ($payload['kind'] === 'stage_time' ? ' on the current stage' : '') . ' (allowed ' . (int)$payload['allowed'] . ')');
                break;
            case 'project.stage_due':
                $d = (int)($payload['days_remaining'] ?? 0);
                $title = $name . ': ' . $stage . ($d === 0 ? ' ends today' : ($d === 1 ? ' ends tomorrow' : ' ends in ' . $d . ' days'));
                break;
            case 'project.stage_closed':
                $title = $name . ': ' . $stage . ' closed';
                break;
            case 'project.milestone_due':
                $d = (int)($payload['days_remaining'] ?? 0);
                $title = $name . ': ' . (string)($payload['milestone']['name'] ?? '') . ($d === 0 ? ' is today' : ($d === 1 ? ' is tomorrow' : ' is in ' . $d . ' days'));
                break;
            case 'project.milestone_missed':
                $title = $name . ': ' . (string)($payload['milestone']['name'] ?? '') . ' was missed';
                break;
            case 'project.milestone_reached':
                $title = $name . ': ' . (string)($payload['milestone']['name'] ?? '') . ' reached';
                break;
            case 'project.signoff_requested':
                $title = $name . ': your sign-off is needed - ' . (string)($payload['item']['title'] ?? '') . ' (' . (string)($payload['stage']['name'] ?? '') . ')';
                break;
            case 'project.tasks_overdue':
                $n = (int)($payload['count'] ?? 0);
                $title = $name . ': ' . $n . ($n === 1 ? ' task is overdue' : ' tasks are overdue');
                break;
            case 'project.approval_stalled':
                $what = ['proposal' => 'the proposal', 'change_request' => 'change request', 'signoff' => 'your sign-off', 'report' => 'report draft'][$payload['kind'] ?? ''] ?? 'a decision';
                $title = $name . ': still waiting on ' . $what . ($payload['kind'] === 'proposal' ? '' : ' - ' . (string)($payload['item']['title'] ?? '')) . ' (' . (int)($payload['waiting_days'] ?? 0) . ' days)';
                break;
            case 'project.benefit_review_due':
                $title = $name . ': review the benefit - ' . (string)($payload['benefit']['title'] ?? '');
                break;
            case 'project.proposal_submitted':
                $title = $name . ': proposed, waiting for approval';
                break;
            case 'project.proposal_decided':
                $title = $name . ': proposal ' . (string)($payload['proposal']['status'] ?? 'decided');
                break;
            case 'project.change_raised':
                $title = $name . ': ' . (string)($payload['change_request']['reference'] ?? '') . ' raised - ' . (string)($payload['change_request']['title'] ?? '');
                break;
            case 'project.change_decided':
                $title = $name . ': ' . (string)($payload['change_request']['reference'] ?? '') . ' ' . (string)($payload['change_request']['status'] ?? '') . ' - ' . (string)($payload['change_request']['title'] ?? '');
                break;
            case 'project.raid_escalated':
                $title = $name . ': ' . (string)($payload['raid']['type'] ?? '') . ' escalated - ' . (string)($payload['raid']['title'] ?? '');
                break;
            default:
                $title = $name;
        }
        return ['id' => $id, 'ref' => (string)($payload['project']['code'] ?? ''), 'title' => mb_substr($title, 0, 255)];
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

/**
 * "A task has been assigned to you", by email - for an analyst who has turned on
 * Preferences -> Notifications -> "Email me when a task is assigned to me"
 * (user_preferences.task_assigned_email = 'on'; off unless they ask).
 *
 * Rides on task.assigned, which fires on creation with an assignee and on every
 * reassignment, from every path that assigns a task (the board, the API, a
 * workflow, a repeat). The same rules as the bell where they apply: never for
 * your own action, and not during a bulk run (the caller returns before here).
 *
 * Sent through ssSendSystemEmail() - the first mailbox able to send, the same
 * route the portal and the training reminders use - and logged in the send log
 * under 'task'. Written in the RECIPIENT's interface language, not the language
 * of whoever did the assigning. Never throws.
 */
function notificationsTaskAssignedEmail(PDO $conn, array $payload, int $actorId, ?string $actorName): void
{
    try {
        $taskId   = isset($payload['task']['id']) ? (int)$payload['task']['id'] : 0;
        $assignee = isset($payload['task']['assignee_id']) ? (int)$payload['task']['assignee_id'] : 0;
        if ($taskId <= 0 || $assignee <= 0 || $assignee === $actorId) {
            return;
        }

        $prefs = $conn->prepare("SELECT preference_key, preference_value FROM user_preferences
                                  WHERE analyst_id = ? AND preference_key IN ('task_assigned_email', 'interface_language')");
        $prefs->execute([$assignee]);
        $p = $prefs->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
        if (($p['task_assigned_email'] ?? '') !== 'on') {
            return;
        }

        $a = $conn->prepare("SELECT email, full_name FROM analysts WHERE id = ? AND is_active = 1");
        $a->execute([$assignee]);
        $analyst = $a->fetch(PDO::FETCH_ASSOC);
        $to = $analyst ? trim((string)$analyst['email']) : '';
        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return;
        }

        $t = $conn->prepare("SELECT t.title, t.start_date, t.due_date, tp.name AS priority, pt.title AS parent_title
                               FROM tasks t
                               LEFT JOIN task_priorities tp ON tp.id = t.priority_id
                               LEFT JOIN tasks pt ON pt.id = t.parent_task_id
                              WHERE t.id = ?");
        $t->execute([$taskId]);
        $task = $t->fetch(PDO::FETCH_ASSOC);
        if (!$task) {
            return;
        }

        require_once __DIR__ . '/i18n.php';
        require_once __DIR__ . '/public_url.php';
        require_once __DIR__ . '/self_service_email.php';
        $loc = (string)($p['interface_language'] ?? 'en');
        $tr  = fn(string $k, array $args = []) => I18n::tFor($loc, 'tasks.email.' . $k, $args);
        $h   = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
        $day = fn($d) => $d ? date('j M Y', strtotime((string)$d)) : '';

        $title   = (string)$task['title'];
        $subject = $tr('assigned_subject', ['title' => $title]);
        $heading = $actorName
            ? $tr('assigned_by', ['actor' => $actorName])
            : $tr('assigned_plain');
        $url = publicAbsoluteUrl($conn, NotificationsService::linkFor('task', $taskId) ?? ('tasks/?task=' . $taskId));

        $rows = '';
        foreach ([
            [$tr('subtask_of'), $task['parent_title'] ?? ''],
            [$tr('priority'),   $task['priority'] ?? ''],
            [$tr('start'),      $day($task['start_date'] ?? null)],
            [$tr('due'),        $day($task['due_date'] ?? null)],
        ] as [$label, $value]) {
            if ((string)$value === '') continue;
            $rows .= '<tr><td style="padding:3px 16px 3px 0;color:#6b7280;">' . $h($label) . '</td>'
                   . '<td style="padding:3px 0;color:#111827;">' . $h($value) . '</td></tr>';
        }

        $html = '<div style="font-family:Segoe UI,Arial,sans-serif;font-size:14px;line-height:1.5;color:#111827;max-width:560px;">'
              . '<p style="margin:0 0 6px;color:#6b7280;">' . $h($heading) . '</p>'
              . '<p style="margin:0 0 14px;font-size:18px;font-weight:600;">' . $h($title) . '</p>'
              . ($rows !== '' ? '<table style="border-collapse:collapse;margin:0 0 18px;font-size:14px;">' . $rows . '</table>' : '')
              . '<p style="margin:0 0 22px;"><a href="' . $h($url) . '" style="display:inline-block;padding:9px 18px;background:#7c3aed;color:#ffffff;text-decoration:none;border-radius:6px;font-weight:600;">'
              . $h($tr('open')) . '</a></p>'
              . '<p style="margin:0;font-size:12px;color:#9ca3af;">' . $h($tr('why')) . '</p>'
              . '</div>';

        ssSendSystemEmail($conn, $to, $subject, $html, 'task');
    } catch (Throwable $e) {
        error_log('[notificationsTaskAssignedEmail] ' . $e->getMessage());
    }
}
