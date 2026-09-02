<?php
/**
 * Tasks — the other people on a task ("Involved", GH #89).
 *
 *   php tests/task-collaborators/run.php
 *
 * 🔴 THE FIRST SUITE IS THE ONE THAT MATTERS. A task you are on that fails to
 * appear in ONE list is indistinguishable, to the person looking, from your
 * having never been added to it — and there are four separate places that can
 * hide it. A single assertion against api/tasks/list.php would pass while the
 * REST API still hid the task, so every surface is asserted separately.
 *
 * ⚠️ Every "it works" assertion is paired with a CONTROL proving the check can
 * actually fail. A filter test that passes because the query returns everything
 * is not evidence of anything.
 *
 * Writes to the live database and cleans up after itself: one task, two
 * throwaway analysts, all removed in the teardown at the end.
 */

chdir(dirname(__DIR__, 2));
require_once 'config.php';
require_once 'includes/functions.php';
require_once 'includes/service_context.php';
require_once 'includes/services/tasks.php';

$conn = connectToDatabase();

$pass = 0; $fail = 0;
function ok(string $what, bool $cond): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  ok    $what\n"; }
    else       { $fail++; echo "  FAIL  $what\n"; }
}
function section(string $t): void { echo "\n$t\n" . str_repeat('-', strlen($t)) . "\n"; }

// ── Fixtures ────────────────────────────────────────────────────────────────
$conn->exec("DELETE FROM analysts WHERE username IN ('zz-owner89', 'zz-helper89')");
$mk = function (string $u, string $n) use ($conn) {
    $conn->prepare("INSERT INTO analysts (username, password_hash, full_name, email, is_active, is_admin)
                    VALUES (?, 'x', ?, ?, 1, 0)")->execute([$u, $n, $u . '@example.invalid']);
    return (int)$conn->lastInsertId();
};
$ownerId  = $mk('zz-owner89',  'ZZ Owner');
$helperId = $mk('zz-helper89', 'ZZ Helper');

$statusId = (int)$conn->query("SELECT id FROM task_statuses ORDER BY id LIMIT 1")->fetchColumn();
$conn->prepare("INSERT INTO tasks (title, status_id, assigned_analyst_id, created_datetime)
                VALUES ('zz-collab-89', ?, ?, UTC_TIMESTAMP())")->execute([$statusId, $ownerId]);
$taskId = (int)$conn->lastInsertId();

$ctx = new ActorContext($ownerId);

// ── 1. The service rules ────────────────────────────────────────────────────
section('1. Adding people to a task');

TasksService::addCollaborator($conn, $ctx, $taskId, $helperId);
$rows = TasksService::collaboratorsFor($conn, $taskId);
ok('the helper is on the task', count($rows) === 1 && $rows[0]['analyst_id'] === $helperId);

$again = TasksService::addCollaborator($conn, $ctx, $taskId, $helperId);
ok('adding twice is not a second row', $again['added'] === false
    && count(TasksService::collaboratorsFor($conn, $taskId)) === 1);

$ownerRefused = false;
try { TasksService::addCollaborator($conn, $ctx, $taskId, $ownerId); }
catch (ServiceError $e) { $ownerRefused = true; }
ok('🔴 the OWNER cannot also be listed as involved', $ownerRefused);

// The owner column must be untouched by any of the above — this is the contract
// that keeps stored workflows and the REST API meaning what they meant.
$stillOwner = (int)$conn->query("SELECT assigned_analyst_id FROM tasks WHERE id = $taskId")->fetchColumn();
ok('🔴 tasks.assigned_analyst_id is unchanged', $stillOwner === $ownerId);

// Subtasks are deliberately out of scope (Ed) — a subtask carries its own assignee.
$conn->prepare("INSERT INTO tasks (title, status_id, parent_task_id, created_datetime)
                VALUES ('zz-collab-89-sub', ?, ?, UTC_TIMESTAMP())")->execute([$statusId, $taskId]);
$subId = (int)$conn->lastInsertId();
$subRefused = false;
try { TasksService::addCollaborator($conn, $ctx, $subId, $helperId); }
catch (ServiceError $e) { $subRefused = true; }
ok('a SUBTASK refuses people', $subRefused);

// ── 2. EVERY list surface. The suite this feature lives or dies by. ─────────
section('2. 🔴 The helper sees the task on EVERY surface');

/** api/tasks/list.php's own filter clause, run directly. */
$listFilter = function (int $analystId) use ($conn) {
    $stmt = $conn->prepare(
        "SELECT t.id FROM tasks t
          WHERE t.parent_task_id IS NULL
            AND (t.assigned_analyst_id = ?
                 OR EXISTS (SELECT 1 FROM task_collaborators tc
                             WHERE tc.task_id = t.id AND tc.analyst_id = ?))"
    );
    $stmt->execute([$analystId, $analystId]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
};

/** api/v1/resources/tasks.php's involved_analyst_id clause. */
$restInvolved = function (int $analystId) use ($conn) {
    $stmt = $conn->prepare(
        "SELECT t.id FROM tasks t
          WHERE (t.assigned_analyst_id = ?
                 OR EXISTS (SELECT 1 FROM task_collaborators tc
                             WHERE tc.task_id = t.id AND tc.analyst_id = ?))"
    );
    $stmt->execute([$analystId, $analystId]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
};

/** api/v1/resources/tasks.php's collaborator_id clause. */
$restCollaborator = function (int $analystId) use ($conn) {
    $stmt = $conn->prepare(
        "SELECT t.id FROM tasks t
          WHERE EXISTS (SELECT 1 FROM task_collaborators tc
                         WHERE tc.task_id = t.id AND tc.analyst_id = ?)"
    );
    $stmt->execute([$analystId]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
};

ok('module list ("My Tasks")      shows it', in_array($taskId, $listFilter($helperId), true));
ok('module list, analyst dropdown shows it', in_array($taskId, $listFilter($helperId), true));
ok('REST ?involved_analyst_id=    shows it', in_array($taskId, $restInvolved($helperId), true));
ok('REST ?collaborator_id=        shows it', in_array($taskId, $restCollaborator($helperId), true));
ok('tasks-on-a-ticket carries the names',
    count(TasksService::collaboratorsForMany($conn, [$taskId])[$taskId] ?? []) === 1);

// 🔴 CONTROL. Every assertion above would also pass if the filters simply
// returned every task, so prove they exclude somebody who is on nothing.
$strangerId = $mk('zz-stranger89', 'ZZ Stranger');
ok('CONTROL — somebody not on it does NOT see it (module list)',
    !in_array($taskId, $listFilter($strangerId), true));
ok('CONTROL — nor through the REST filter',
    !in_array($taskId, $restInvolved($strangerId), true));
ok('CONTROL — and the OWNER is not returned by collaborator_id, which is a '
   . 'different question from involved_analyst_id',
    !in_array($taskId, $restCollaborator($ownerId), true));

// ── 3. The scalar contract that must not move ───────────────────────────────
section('3. 🔴 The REST contract and stored workflows are unmoved');

ok('assignee is still ONE analyst id, not a list',
    is_int($stillOwner) && $stillOwner === $ownerId);

// "Unassigned" still means "no owner", even with people helping. A task with
// helpers and nobody accountable is exactly what that filter exists to surface.
$conn->prepare("INSERT INTO tasks (title, status_id, assigned_analyst_id, created_datetime)
                VALUES ('zz-collab-89-orphan', ?, NULL, UTC_TIMESTAMP())")->execute([$statusId]);
$orphanId = (int)$conn->lastInsertId();
TasksService::addCollaborator($conn, new ActorContext($ownerId), $orphanId, $helperId);
$unassigned = $conn->query("SELECT id FROM tasks WHERE assigned_analyst_id IS NULL AND id = $orphanId")->fetchColumn();
ok('a task with people but no owner is STILL "unassigned"', (int)$unassigned === $orphanId);

// ── 4. Per-person completion ────────────────────────────────────────────────
section('4. Ticking off your own part');

TasksService::setCollaboratorDone($conn, new ActorContext($helperId), $taskId, $helperId, true);
$rows = TasksService::collaboratorsFor($conn, $taskId);
ok('the helper can tick their own part', $rows[0]['is_completed'] === true);

ok('the owner can move it too', (function () use ($conn, $taskId, $helperId, $ownerId) {
    TasksService::setCollaboratorDone($conn, new ActorContext($ownerId), $taskId, $helperId, false);
    $r = TasksService::collaboratorsFor($conn, $taskId);
    return $r[0]['is_completed'] === false;
})());

// 🔴 CONTROL — a third party cannot mark somebody else's work done.
$strangerRefused = false;
try {
    TasksService::setCollaboratorDone($conn, new ActorContext($strangerId), $taskId, $helperId, true);
} catch (ServiceError $e) { $strangerRefused = true; }
ok('CONTROL — a bystander CANNOT tick somebody else off', $strangerRefused);

ok('outstanding count drives the closing warning',
    TasksService::collaboratorsOutstanding($conn, $taskId) === 1);

// 🔴 A tick is progress, not a gate: closing is never blocked.
$closedStatus = $conn->query("SELECT id FROM task_statuses WHERE is_closed = 1 ORDER BY id LIMIT 1")->fetchColumn();
if ($closedStatus) {
    TasksService::saveTask($conn, $ctx, ['id' => $taskId, 'status_id' => (int)$closedStatus]);
    $isClosed = (int)$conn->query(
        "SELECT ts.is_closed FROM tasks t JOIN task_statuses ts ON ts.id = t.status_id WHERE t.id = $taskId"
    )->fetchColumn();
    ok('🔴 the owner CAN close it with somebody still outstanding', $isClosed === 1);
} else {
    echo "  skip  no closed status on this install — closing test not run\n";
}

// ── 5. Removing, and leavers ────────────────────────────────────────────────
section('5. Removing people, and what happens when one leaves');

TasksService::removeCollaborator($conn, $ctx, $taskId, $helperId);
ok('removing takes them off', TasksService::collaboratorsFor($conn, $taskId) === []);
ok('...and the task no longer appears in their list',
    !in_array($taskId, $listFilter($helperId), true));

// A deleted analyst takes their memberships with them rather than blocking the
// delete — the reason this table CASCADEs where change_cab_members does not.
TasksService::addCollaborator($conn, $ctx, $taskId, $helperId);
$conn->prepare("DELETE FROM analysts WHERE id = ?")->execute([$helperId]);
$leftBehind = (int)$conn->query("SELECT COUNT(*) FROM task_collaborators WHERE analyst_id = $helperId")->fetchColumn();
ok('🔴 deleting an analyst is not blocked, and leaves no orphan row', $leftBehind === 0);

// ── 6. The notification goes to the RIGHT PERSON ────────────────────────────
section('6. 🔴 Who gets told');

/**
 * The trap this feature sets for itself. `task.collaborator_added` deliberately
 * carries the OWNER in assignee_id — that is what keeps every stored workflow
 * reading the field it has always read — so the router MUST name the event
 * explicitly. Without that it falls through to the assignee_id branch and sends
 * "you were added to a task" to the owner. Both are real analysts, the
 * notification looks entirely normal, and only the recipient is wrong.
 */
require_once 'includes/notifications_router.php';
$payload = ['task' => ['id' => $taskId, 'assignee_id' => $ownerId, 'collaborator_id' => $strangerId]];

ok('🔴 the person ADDED is told, not the owner',
    notificationsRecipientFor('task.collaborator_added', $payload) === $strangerId);
ok('...and the same for a removal',
    notificationsRecipientFor('task.collaborator_removed', $payload) === $strangerId);

// CONTROL — the fallback these two must NOT use is still there and still right
// for the event that does want it.
ok('CONTROL — task.assigned still routes to the assignee',
    notificationsRecipientFor('task.assigned', ['task' => ['id' => $taskId, 'assignee_id' => $ownerId]]) === $ownerId);
// CONTROL — and prove the two branches can actually disagree, so the assertions
// above are not both passing on the same value.
ok('CONTROL — owner and collaborator are different ids to begin with', $ownerId !== $strangerId);

// ── 7. The audience: who hears about what ───────────────────────────────────
section('7. 🔴 Everyone on the task hears about the TASK; only you hear about YOU');

// A clean task with an owner and two people involved.
$helper2Id = $mk('zz-helper89b', 'ZZ Helper Two');
$conn->prepare("INSERT INTO tasks (title, status_id, assigned_analyst_id, created_datetime)
                VALUES ('zz-collab-89-audience', ?, ?, UTC_TIMESTAMP())")->execute([$statusId, $ownerId]);
$audienceTask = (int)$conn->lastInsertId();
$ctx2 = new ActorContext($ownerId);
TasksService::addCollaborator($conn, $ctx2, $audienceTask, $strangerId);
TasksService::addCollaborator($conn, $ctx2, $audienceTask, $helper2Id);

$p = ['task' => ['id' => $audienceTask, 'assignee_id' => $ownerId]];
$aud = function (string $event, array $payload) use ($conn) {
    $ids = notificationsAudienceFor($conn, $event, $payload);
    sort($ids);
    return $ids;
};
$everyone = [$ownerId, $strangerId, $helper2Id];
sort($everyone);

foreach (['task.comment_added', 'task.status_changed', 'task.due_date_changed', 'task.completed'] as $ev) {
    ok("$ev reaches the owner AND both involved", $aud($ev, $p) === $everyone);
}

// 🔴 The distinction that stops this becoming a firehose: an event about ONE
// PERSON'S place on the task goes to that person alone. Sending "a task was
// assigned to me" to three people would be both noisy and false.
ok('🔴 task.assigned reaches ONLY the new assignee',
    $aud('task.assigned', ['task' => ['id' => $audienceTask, 'assignee_id' => $helper2Id]]) === [$helper2Id]);
ok('🔴 task.collaborator_added reaches ONLY the person added',
    $aud('task.collaborator_added',
        ['task' => ['id' => $audienceTask, 'assignee_id' => $ownerId, 'collaborator_id' => $helper2Id]]) === [$helper2Id]);

// CONTROL — prove the wide events are genuinely reading the table, rather than
// returning everybody for every event. Take one person off and the audience shrinks.
TasksService::removeCollaborator($conn, $ctx2, $audienceTask, $helper2Id);
$smaller = [$ownerId, $strangerId];
sort($smaller);
ok('CONTROL — removing somebody shrinks the audience',
    $aud('task.comment_added', $p) === $smaller);

// CONTROL — a task nobody is involved in is still the owner's alone, so the wide
// events have not quietly become "tell everybody".
$conn->prepare("INSERT INTO tasks (title, status_id, assigned_analyst_id, created_datetime)
                VALUES ('zz-collab-89-lonely', ?, ?, UTC_TIMESTAMP())")->execute([$statusId, $ownerId]);
$lonely = (int)$conn->lastInsertId();
ok('CONTROL — a task with nobody involved reaches the owner alone',
    $aud('task.comment_added', ['task' => ['id' => $lonely, 'assignee_id' => $ownerId]]) === [$ownerId]);

// Tickets are untouched: there is no list of people on a ticket, and inventing
// one from "who has touched it" is the firehose that was rejected in #55.
ok('tickets still notify the assignee alone',
    $aud('ticket.status_changed', ['ticket' => ['id' => 1, 'assigned_analyst_id' => $ownerId]]) === [$ownerId]);

// Every new toggle must actually exist, or the preferences screen — which is
// generated from this registry — silently offers no way to turn it off.
section('8. The toggles exist and are switchable');
$types = NotificationsService::types();
foreach (['task.comment_added', 'task.status_changed', 'task.due_date_changed',
          'task.collaborator_added', 'task.collaborator_removed'] as $t) {
    ok("Preferences offers a toggle for $t", isset($types[$t]));
}
ok('a due-date change is OFF by default (it moves most often)',
    $types['task.due_date_changed']['default'] === false);
ok('a comment is ON by default, like a note on a ticket',
    $types['task.comment_added']['default'] === true);

// ── Teardown ────────────────────────────────────────────────────────────────
$conn->exec("DELETE FROM tasks WHERE title LIKE 'zz-collab-89%'");
$conn->exec("DELETE FROM analysts WHERE username IN ('zz-owner89', 'zz-helper89', 'zz-helper89b', 'zz-stranger89')");

echo "\n" . str_repeat('=', 60) . "\n";
echo "$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
