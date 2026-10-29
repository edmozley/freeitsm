<?php
/**
 * TasksService — the shared write rules for the kanban Tasks module: task
 * create/update, the kanban move (re-pack a column), delete (subtask tree) and
 * comment create.
 *
 * Shared by the UI endpoints (api/tasks/*.php) and the REST API
 * (api/v1/resources/tasks.php). Each caller passes an ActorContext + canonical
 * input; this layer validates + writes and returns the affected id(s) or throws
 * ServiceError. It never emits HTTP.
 *
 * SCOPE: covers the cleanly-duplicated overlaps. The UI's drag endpoint
 * (reorder.php) sends client-computed positions whereas the API's /move
 * re-packs server-side — a structural mismatch — so reorder.php stays on its own
 * code; the subtask-toggle convenience and the lookup SETTINGS (statuses /
 * priorities / tags) are UI-only (no API twin) and stay put too.
 *
 * Canonical behaviour = the API resource's: only title is required; status /
 * priority resolve by name or id (strict 422 on unknown, else the To Do /
 * Medium default); analyst / team / tags / links are validated; a task's
 * completed_datetime is stamped on entering a closed status and cleared on
 * reopening; a PATCH (not a move) that closes an open task fires task.completed.
 *
 * 🔒 Company scope: tasks now carry their own `tenant_id` (GH #83 groundwork).
 * A task is SCOPED DATA like a ticket or an asset, so `NULL` means "the Default
 * company's", NOT "shared with everyone" — see the Multi-Tenancy Developer Guide
 * on the three meanings of NULL, because two of them are opposites.
 *
 * Enforcement sits in loadTaskRow(), which every by-id path (update, move,
 * delete, comment) already funnelled through, so the gate is in one place rather
 * than four. Ticket links are still validated separately: a task and the ticket
 * it points at must both be reachable by the actor.
 *
 * Before this, tasks had no tenant_id at all and the only isolation was that
 * ticket-link check — a workaround for the missing column rather than scope.
 *
 * Canonical input keys: title, description, status / status_id, priority /
 * priority_id, assigned_analyst_id, assigned_team_id, start_date, due_date,
 * parent_task_id, ticket_id, change_id, contract_id, board_position, tags[],
 * estimate_hours (3.3.0; hours, up to two decimals, NULL = not estimated).
 */

require_once __DIR__ . '/../service_context.php';
require_once __DIR__ . '/../tenancy.php';                       // isMultiTenant / getDefaultTenantId for ticket scope
require_once __DIR__ . '/checklists.php';                        // the checklist completion gate (discussion #138)
require_once dirname(__DIR__, 2) . '/workflow/includes/engine.php';
require_once __DIR__ . '/task_history.php';   // the record a task keeps (#181) - and THE status hook

class TasksService
{
    // ======================================================================
    //  Tasks
    // ======================================================================

    /**
     * Create (no id) or update (id present) a task. Returns ['id','created'].
     *
     * @param array $in  the canonical task fields (see the file comment); with id it updates that task, without one it creates
     */
    public static function saveTask(PDO $conn, ActorContext $ctx, array $in): array
    {
        self::assertCanWrite($conn, $ctx);
        if (!empty($in['id'])) {
            return ['id' => self::updateTask($conn, $ctx, (int)$in['id'], $in), 'created' => false];
        }
        return ['id' => self::createTask($conn, $ctx, $in), 'created' => true];
    }

    /**
     * Creates a task with its status, priority, assignee, team or contractor, links, dates
     * and tags, and fires task.created. Returns the new id.
     *
     * @param array $in  the canonical task fields (title required), plus tenant_id, assigned_supplier_id, assigned_contact_id
     *     and work_start_at / work_end_at / work_all_day
     */
    private static function createTask(PDO $conn, ActorContext $ctx, array $in): int
    {
        $title = trim((string)($in['title'] ?? ''));
        if ($title === '') {
            throw new ServiceError('validation', 'missing_field', "'title' is required.");
        }

        $status   = self::resolveLookup($conn, $in, 'status', 'task_statuses', true)
            ?? self::lookupDefault($conn, 'task_statuses', true);
        $priority = self::resolveLookup($conn, $in, 'priority', 'task_priorities')
            ?? self::lookupDefault($conn, 'task_priorities');

        $analystId = null;
        if (isset($in['assigned_analyst_id']) && $in['assigned_analyst_id'] !== '') {
            $analystId = (int)$in['assigned_analyst_id'];
            self::resolveAnalyst($conn, $analystId);
        }
        $teamId = self::validateTeam($conn, $in['assigned_team_id'] ?? null);
        // A contractor (3.3.0) - checked before anything is written.
        $contractor = [null, null];
        if (!empty($in['assigned_supplier_id']) || !empty($in['assigned_contact_id'])) {
            require_once __DIR__ . '/../task_contractors.php';
            if (tasksContractorReady($conn)) {
                self::contractorAllowed($conn, $ctx);
                $contractor = tasksContractorValidate($conn, $in['assigned_supplier_id'] ?? null, $in['assigned_contact_id'] ?? null);
            }
        }

        $links = [];
        foreach (['parent_task_id', 'ticket_id', 'change_id', 'contract_id'] as $field) {
            $links[$field] = self::validateLink($conn, $ctx, $field, $in[$field] ?? null);
        }

        $startDate = self::parseDateOnly($in['start_date'] ?? null, 'start_date');
        $dueDate   = self::parseDateOnly($in['due_date'] ?? null, 'due_date');
        $description = trim((string)($in['description'] ?? '')) ?: null;
        $estimate  = self::parseEstimate($in['estimate_hours'] ?? null);

        $tagIds = null;
        if (isset($in['tags']) && is_array($in['tags'])) {
            $tagIds = self::resolveTags($conn, $in['tags']);
        }

        // Append to the end of the target status column (top-level tasks only).
        $posStmt = $conn->prepare("SELECT COALESCE(MAX(board_position), -1) + 1 FROM tasks WHERE status_id = ? AND parent_task_id IS NULL");
        $posStmt->execute([$status[0]]);
        $boardPosition = (int)$posStmt->fetchColumn();
        // A subtask goes to the end of ITS PARENT'S list instead - the order
        // dragged in the task window is stored in the same column.
        if ($links['parent_task_id']) {
            $posStmt = $conn->prepare("SELECT COALESCE(MAX(board_position), -1) + 1 FROM tasks WHERE parent_task_id = ?");
            $posStmt->execute([(int) $links['parent_task_id']]);
            $boardPosition = (int)$posStmt->fetchColumn();
        }

        // A subtask always belongs wherever its parent does — it is not an
        // independent piece of work and must never be reachable from a company
        // its parent is not.
        $tenantId = $links['parent_task_id']
            ? self::parentTaskTenant($conn, (int) $links['parent_task_id'])
            : self::resolveNewTaskTenant($conn, $ctx, $in, $links['ticket_id'] ? (int) $links['ticket_id'] : null);

        $conn->prepare(
            "INSERT INTO tasks (title, description, status_id, priority_id, start_date, due_date,
                                assigned_analyst_id, assigned_team_id, parent_task_id,
                                ticket_id, change_id, contract_id, tenant_id, board_position, created_by_id,
                                completed_datetime, created_datetime, updated_datetime)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())"
        )->execute([
            $title, $description, $status[0], $priority[0], $startDate, $dueDate,
            $analystId, $teamId, $links['parent_task_id'],
            $links['ticket_id'], $links['change_id'], $links['contract_id'], $tenantId,
            $boardPosition, $ctx->actorId > 0 ? $ctx->actorId : self::systemCreatorId($conn),
            !empty($status[2]) ? gmdate('Y-m-d H:i:s') : null,
        ]);
        $taskId = (int)$conn->lastInsertId();
        // Its own statement, so an install that has not run Database Verification
        // (no estimate_hours column yet) can still create tasks.
        if ($estimate !== null) $conn->prepare("UPDATE tasks SET estimate_hours = ? WHERE id = ?")->execute([$estimate, $taskId]);
        // Likewise the contractor (3.3.0), and its contact hears about it if Projects says so.
        if ($contractor[0] !== null) {
            $conn->prepare("UPDATE tasks SET assigned_supplier_id = ?, assigned_contact_id = ? WHERE id = ?")->execute([$contractor[0], $contractor[1], $taskId]);
            if ($contractor[1] !== null) tasksContractorEmail($conn, $taskId, 'assigned');
        }

        if ($tagIds !== null) {
            self::syncTags($conn, $taskId, $tagIds);
        }

        // A task can be created already scheduled (the REST API does this; the UI
        // sets it afterwards from the detail panel). Routed through updateTask so
        // there is ONE copy of the "an end needs a start, and cannot precede it"
        // rules rather than a second, subtly different one on the create path.
        $work = array_intersect_key($in, array_flip(['work_start_at', 'work_end_at', 'work_all_day']));
        if ($work) {
            self::updateTask($conn, $ctx, $taskId, $work);
        }

        // #181: the task's history starts here. created_via says a PERSON made it,
        // as opposed to the repeat - TaskRecurrence::createOccurrence() writes its own.
        try { $conn->prepare("UPDATE tasks SET created_via = 'manual' WHERE id = ?")->execute([$taskId]); } catch (Throwable) { /* before Database Verification */ }
        TaskHistory::record($conn, $taskId, 'created', $ctx, null, $dueDate, ['via' => 'manual', 'status' => $status[1] ?? null]);

        try {
            WorkflowEngine::dispatch('task.created', [
                'task' => [
                    'id'          => $taskId,
                    'title'       => $title,
                    'status_id'   => $status[0],
                    'priority_id' => $priority[0],
                    'assignee_id' => $analystId,
                ],
            ]);
        } catch (Exception $wfEx) {
            error_log('Workflow dispatch error in task service (created): ' . $wfEx->getMessage());
        }
        // Handing work to somebody as you create it is still assigning it to them.
        if ($analystId !== null) {
            self::assignedDispatch($conn, $taskId, $analystId);
        }
        self::calendarOnTaskChanged($conn, $taskId);
        return $taskId;
    }

    /**
     * Changes the fields sent for a task and fires the workflow and notification events the
     * changes call for.
     *
     * Closing a task stamps its completed time and lets a recurring task schedule its next
     * occurrence.
     *
     * @param array $in  only the fields to change - the canonical keys, plus assigned_supplier_id, assigned_contact_id,
     *     work_start_at, work_end_at, work_all_day; id is ignored
     */
    private static function updateTask(PDO $conn, ActorContext $ctx, int $taskId, array $in): int
    {
        $current = self::loadTaskRow($conn, $ctx, $taskId);           // 404 if gone
        $before  = TaskHistory::before($conn, $taskId);                // #181: for the status hook and the history
        if (!array_diff_key($in, ['id' => true])) {
            throw new ServiceError('validation', 'missing_field', 'No fields to update.');
        }

        $updates = [];
        $args    = [];

        if (array_key_exists('title', $in)) {
            $title = trim((string)$in['title']);
            if ($title === '') {
                throw new ServiceError('validation', 'invalid_field', "'title' cannot be empty.");
            }
            $updates[] = 'title = ?';
            $args[]    = $title;
        }
        if (array_key_exists('description', $in)) {
            $updates[] = 'description = ?';
            $args[]    = trim((string)$in['description']) ?: null;
        }
        if (array_key_exists('estimate_hours', $in)) {
            $updates[] = 'estimate_hours = ?';
            $args[]    = self::parseEstimate($in['estimate_hours']);
        }

        // Status — completed_datetime mechanics + workflow dispatch.
        $wasClosed = (bool)($current['status_is_closed'] ?? false);
        $firesCompleted = false;
        $statusChanged  = false;            // GH #89 — see the dispatches below
        $status = self::resolveLookup($conn, $in, 'status', 'task_statuses', true);
        if ($status !== null && $status[0] !== (int)$current['status_id']) {
            $updates[] = 'status_id = ?';
            $args[]    = $status[0];
            $statusChanged = true;
            if ($status[2]) {
                // 🔴 Checklist gate BEFORE the write (discussion #138): a Critical
                // checklist with steps outstanding refuses; a Standard one is
                // recorded after the write, below.
                if (!$wasClosed) {
                    ChecklistsService::assertTaskCompletionAllowed($conn, $taskId);
                }
                $updates[] = 'completed_datetime = COALESCE(completed_datetime, UTC_TIMESTAMP())';
                $firesCompleted = !$wasClosed;
            } else {
                $updates[] = 'completed_datetime = NULL';
            }
        }
        $priority = self::resolveLookup($conn, $in, 'priority', 'task_priorities');
        if ($priority !== null && $priority[0] !== ($current['priority_id'] !== null ? (int)$current['priority_id'] : null)) {
            $updates[] = 'priority_id = ?';
            $args[]    = $priority[0];
        }

        $assignedTo = null;                 // set only when it actually CHANGES
        if (array_key_exists('assigned_analyst_id', $in)) {
            $newAnalyst = ($in['assigned_analyst_id'] === '' || $in['assigned_analyst_id'] === null) ? null : (int)$in['assigned_analyst_id'];
            if ($newAnalyst !== null) {
                self::resolveAnalyst($conn, $newAnalyst);
            }
            $updates[] = 'assigned_analyst_id = ?';
            $args[]    = $newAnalyst;
            // Saving the same task twice must not tell the assignee twice, and
            // clearing the assignee is not an assignment — there is nobody to
            // tell. Both cases leave $assignedTo null.
            $oldAnalyst = $current['assigned_analyst_id'] !== null ? (int)$current['assigned_analyst_id'] : null;
            if ($newAnalyst !== null && $newAnalyst !== $oldAnalyst) {
                $assignedTo = $newAnalyst;
            }
        }
        if (array_key_exists('assigned_team_id', $in)) {
            $newTeam = self::validateTeam($conn, $in['assigned_team_id']);
            $updates[] = 'assigned_team_id = ?';
            $args[]    = $newTeam;
        }
        // A contractor (3.3.0) - includes/task_contractors.php. Changing the supplier
        // drops a contact who works for the old one; a new contact may be emailed.
        $emailContractor = false;
        if ((array_key_exists('assigned_supplier_id', $in) || array_key_exists('assigned_contact_id', $in)) && array_key_exists('assigned_supplier_id', $current)) {
            require_once __DIR__ . '/../task_contractors.php';
            $curS = $current['assigned_supplier_id'] !== null ? (int)$current['assigned_supplier_id'] : null;
            $curC = $current['assigned_contact_id'] !== null ? (int)$current['assigned_contact_id'] : null;
            $sIn = array_key_exists('assigned_supplier_id', $in) ? $in['assigned_supplier_id'] : $curS;
            $cIn = array_key_exists('assigned_contact_id', $in) ? $in['assigned_contact_id']
                  : (($sIn === '' || $sIn === null || (int)$sIn !== $curS) ? null : $curC);
            if ($sIn === '' || $sIn === null) $cIn = array_key_exists('assigned_contact_id', $in) ? $cIn : null;
            [$newS, $newC] = tasksContractorValidate($conn, $sIn, $cIn);
            if ($newS !== $curS || $newC !== $curC) {
                self::contractorAllowed($conn, $ctx);
                $updates[] = 'assigned_supplier_id = ?'; $args[] = $newS;
                $updates[] = 'assigned_contact_id = ?';  $args[] = $newC;
                $emailContractor = $newC !== null && $newC !== $curC;
            }
        }

        $dueChanged = false;                // GH #89 — see the dispatches below
        $dateChanges = [];                  // #181: field => [from, to]
        foreach (['start_date', 'due_date'] as $field) {
            if (array_key_exists($field, $in)) {
                $newDate   = self::parseDateOnly($in[$field], $field);
                $updates[] = "$field = ?";
                $args[]    = $newDate;
                // ⚠️ Compared, not merely PRESENT. The detail panel posts the due
                // date on every save of that field, so "it was in the payload"
                // would tell everybody on the task the date had moved each time
                // somebody re-picked the day it already was.
                if ($field === 'due_date' && $newDate !== ($current['due_date'] ?? null)) {
                    $dueChanged = true;
                }
                // #181: both dates go on the record. The report tells a deadline that
                // was MOVED from one that was always that date.
                if ($newDate !== ($current[$field] ?? null)) {
                    $dateChanges[$field] = [$current[$field] ?? null, $newDate];
                }
            }
        }

        // When the work is planned for (GH #112). The same three fields, the same
        // input names and the same rules a ticket's scheduled work uses, so the
        // two cannot drift into describing the same idea differently.
        $newWork = null;
        if (array_key_exists('work_start_at', $in)) {
            $newWork = ($in['work_start_at'] === null || $in['work_start_at'] === '')
                ? null : self::parseNaiveDateTime((string)$in['work_start_at'], 'work_start_at');
            $updates[] = 'work_start_datetime = ?';
            $args[]    = $newWork;
            // Clearing the start clears the whole slot. An end time with no start
            // describes a block of work that no longer exists, and the calendar
            // reads the end relative to the start, so a stale one is not merely
            // untidy.
            if ($newWork === null) {
                $updates[] = 'work_end_datetime = ?';
                $args[]    = null;
                $updates[] = 'work_all_day = ?';
                $args[]    = 0;
            }
        }
        if (array_key_exists('work_end_at', $in) && !in_array('work_end_datetime = ?', $updates, true)) {
            $newEnd = ($in['work_end_at'] === null || $in['work_end_at'] === '')
                ? null : self::parseNaiveDateTime((string)$in['work_end_at'], 'work_end_at');
            $effectiveStart = array_key_exists('work_start_at', $in)
                ? $newWork : ($current['work_start_datetime'] ?? null);
            if ($newEnd !== null && $effectiveStart === null) {
                throw new ServiceError('validation', 'invalid_field',
                    "'work_end_at' needs a 'work_start_at' — a task cannot finish work it is not scheduled for.");
            }
            if ($newEnd !== null && $newEnd < $effectiveStart) {
                throw new ServiceError('validation', 'invalid_field',
                    "'work_end_at' cannot be before 'work_start_at'.");
            }
            $updates[] = 'work_end_datetime = ?';
            $args[]    = $newEnd;
        }
        if (array_key_exists('work_all_day', $in) && !in_array('work_all_day = ?', $updates, true)) {
            $updates[] = 'work_all_day = ?';
            $args[]    = (int)(bool)$in['work_all_day'];
        }

        foreach (['parent_task_id', 'ticket_id', 'change_id', 'contract_id'] as $field) {
            if (!array_key_exists($field, $in)) {
                continue;
            }
            if ($field === 'parent_task_id' && (int)$in[$field] === $taskId) {
                throw new ServiceError('validation', 'invalid_field', 'A task cannot be its own parent.');
            }
            $updates[] = "$field = ?";
            $args[]    = self::validateLink($conn, $ctx, $field, $in[$field]);
        }

        if (array_key_exists('board_position', $in) && $in['board_position'] !== '' && $in['board_position'] !== null) {
            $updates[] = 'board_position = ?';
            $args[]    = max(0, (int)$in['board_position']);
        }

        $tagIds = null;
        if (isset($in['tags']) && is_array($in['tags'])) {
            $tagIds = self::resolveTags($conn, $in['tags']);
        }

        /* #181 (Dr4inder's review): the change and its history are ONE transaction. Every
           evidence write below is strict - if one fails, the whole change rolls back and the
           caller is told, rather than the task changing with no record of it. Successor
           creation, workflow events and the calendar follow the commit. */
        $own = !$conn->inTransaction();
        if ($own) $conn->beginTransaction();
        $statusResult = null;
        try {
            if ($updates) {
                $updates[] = 'updated_datetime = UTC_TIMESTAMP()';
                $args[]    = $taskId;
                $conn->prepare('UPDATE tasks SET ' . implode(', ', $updates) . ' WHERE id = ?')->execute($args);
            }
            if ($tagIds !== null) {
                self::syncTags($conn, $taskId, $tagIds);
                $conn->prepare("UPDATE tasks SET updated_datetime = UTC_TIMESTAMP() WHERE id = ?")->execute([$taskId]);
            }
            if ($firesCompleted) {
                ChecklistsService::recordTaskCompletionOverride($conn, $ctx, $taskId, true);
            }
            // THE status hook's evidence half - the change, who completed it, and
            // whether a repeat's successor is now expected.
            if ($statusChanged) {
                $statusResult = TaskHistory::recordStatusChange($conn, $ctx, $taskId, $before, $status[0]);
            }
            foreach ($dateChanges as $field => [$from, $to]) {
                TaskHistory::recordStrict($conn, $taskId, $field . '_changed', $ctx, $from, $to,
                    $field === 'due_date' ? ['task_closed' => !empty($before['status_is_closed'])] : []);
            }
            if (array_key_exists('assigned_analyst_id', $in)
                && (int)($current['assigned_analyst_id'] ?? 0) !== (int)($in['assigned_analyst_id'] ?: 0)) {
                TaskHistory::recordStrict($conn, $taskId, 'assigned', $ctx,
                    self::analystLabel($conn, $current['assigned_analyst_id'] ?? null),
                    self::analystLabel($conn, $in['assigned_analyst_id'] ?: null));
            }
            if (array_key_exists('assigned_team_id', $in)
                && (int)($current['assigned_team_id'] ?? 0) !== (int)($in['assigned_team_id'] ?: 0)) {
                TaskHistory::recordStrict($conn, $taskId, 'team_assigned', $ctx,
                    self::teamLabel($conn, $current['assigned_team_id'] ?? null),
                    self::teamLabel($conn, $in['assigned_team_id'] ?: null));
            }
            if ($own) $conn->commit();
        } catch (Throwable $e) {
            if ($own && $conn->inTransaction()) $conn->rollBack();
            throw $e;
        }
        if ($firesCompleted) {
            self::completedDispatch($conn, $taskId);
        }
        // The successor, whose expectation is already on the record.
        TaskHistory::afterCommit($conn, $ctx, $statusResult);
        if ($assignedTo !== null) {
            self::assignedDispatch($conn, $taskId, $assignedTo);
        }
        if ($emailContractor) tasksContractorEmail($conn, $taskId, 'assigned');   // 3.3.0: only when project_contractor_email is on
        // GH #89. Once other people can be ON a task, "what happened to it" has an
        // audience beyond the owner — so the moments that matter have to announce
        // themselves. Each is separately switchable per analyst under
        // Preferences → Notifications; the workflow engine gains the triggers for
        // free, which is the right trade either way.
        //
        // ⚠️ status_changed fires on a CLOSING change too, alongside completed.
        // Suppressing it there would make the event lie to any workflow filtering
        // on "moved to Done". The two do not double up in the bell because
        // task.completed is OFF by default, and anybody who turns it on has asked
        // for both.
        if ($statusChanged) {
            self::taskEventDispatch($conn, 'task.status_changed', $taskId);
        }
        if ($dueChanged) {
            self::taskEventDispatch($conn, 'task.due_date_changed', $taskId);
        }
        self::calendarOnTaskChanged($conn, $taskId);
        return $taskId;
    }

    /**
     * Kanban move: change status (+ completed mechanics) and re-pack the target column. No workflow event. Returns the id.
     *
     * @param array $in  status_id or status (a name; neither keeps the current status), position (0-based place in the
     *     column; null or '' puts it at the end)
     */
    public static function moveTask(PDO $conn, ActorContext $ctx, int $taskId, array $in): int
    {
        self::assertCanWrite($conn, $ctx);
        $current = self::loadTaskRow($conn, $ctx, $taskId);
        $before  = TaskHistory::before($conn, $taskId);

        $status = self::resolveLookup($conn, $in, 'status', 'task_statuses', true);
        $targetStatusId = $status !== null ? $status[0] : (int)$current['status_id'];
        $targetIsClosed = $status !== null ? (bool)$status[2] : (bool)$current['status_is_closed'];

        $position = array_key_exists('position', $in) && $in['position'] !== null && $in['position'] !== ''
            ? max(0, (int)$in['position'])
            : null; // null = end of column

        // Checklist gate (discussion #138) - before the write, like updateTask.
        $completing = $targetIsClosed && !(bool)$current['status_is_closed'];
        if ($completing) {
            ChecklistsService::assertTaskCompletionAllowed($conn, $taskId);
        }

        $conn->beginTransaction();
        try {
            $conn->prepare(
                "UPDATE tasks SET status_id = ?,
                        completed_datetime = " . ($targetIsClosed ? "COALESCE(completed_datetime, UTC_TIMESTAMP())" : "NULL") . ",
                        updated_datetime = UTC_TIMESTAMP()
                 WHERE id = ?"
            )->execute([$targetStatusId, $taskId]);

            $colStmt = $conn->prepare(
                "SELECT id FROM tasks
                 WHERE status_id = ? AND parent_task_id IS NULL AND id != ?
                 ORDER BY board_position ASC, created_datetime ASC"
            );
            $colStmt->execute([$targetStatusId, $taskId]);
            $column = array_map('intval', $colStmt->fetchAll(PDO::FETCH_COLUMN));

            $insertAt = ($position === null || $position > count($column)) ? count($column) : $position;
            array_splice($column, $insertAt, 0, [$taskId]);

            $posUpd = $conn->prepare("UPDATE tasks SET board_position = ? WHERE id = ?");
            foreach ($column as $i => $id) {
                $posUpd->execute([$i, $id]);
            }
            // #181: the move's evidence, IN its transaction - a move whose history
            // cannot be written does not happen (Dr4inder's review).
            if ($completing) {
                ChecklistsService::recordTaskCompletionOverride($conn, $ctx, $taskId, true);
            }
            $statusResult = TaskHistory::recordStatusChange($conn, $ctx, $taskId, $before, $targetStatusId);
            $conn->commit();
        } catch (Throwable $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            throw $e;
        }

        // Recurrence fires from HERE TOO (#94). Dragging a card into a closed
        // column is the commonest way a task gets completed, and this method
        // deliberately dispatches no workflow event — so hooking only saveTask
        // would give a task that repeats when you tick it and silently does not
        // when you drag it. Outside the transaction: the move is committed and
        // must stand whatever the recurrence does.
        // #181: the successor, after the commit - its expectation is already recorded.
        TaskHistory::afterCommit($conn, $ctx, $statusResult);
        // Completing by DRAGGING must take the calendar entry away too, exactly
        // as ticking the box does. Same reason recurrence fires from here.
        self::calendarOnTaskChanged($conn, $taskId);
        return $taskId;
    }

    /**
     * Put this task into (or take it out of) its assignee's calendar (#75).
     *
     * ⚠️ CALL AFTER COMMIT, never inside a transaction — it makes a network
     * call, and holding row locks across one turns a slow third party into a
     * database problem. Same rule the ticket side documents.
     *
     * ⚠️ Wrapped, and swallowing Throwable rather than Exception: a task must
     * save even if the calendar file is missing on a part-upgraded install,
     * and a missing class raises an Error, which `catch (Exception)` misses.
     *
     * @param bool $gone  true when the task has been deleted, so its calendar entry is removed
     */
    private static function calendarOnTaskChanged(PDO $conn, int $taskId, bool $gone = false): void
    {
        try {
            require_once __DIR__ . '/../calendar_sync/push.php';
            calendarSyncReconcileTask($conn, $taskId, $gone);
        } catch (Throwable $e) {
            error_log('Calendar sync hook failed for task ' . $taskId . ': ' . $e->getMessage());
        }
    }

    /**
     * Hard-delete a task + its whole subtask tree (comments/tags too). Returns ['id','subtasks_deleted'].
     *
     * #181: an occurrence of a repeat is evidence. Tasks -> Settings -> Evidence decides
     * whether it may be deleted at all (refused: skip it instead); where it may, a frozen
     * copy of everything the evidence report reads is kept first, owned by the series.
     */
    public static function deleteTask(PDO $conn, ActorContext $ctx, int $taskId): array
    {
        self::assertCanWrite($conn, $ctx);
        $row = self::loadTaskRow($conn, $ctx, $taskId);

        $isOccurrence = !empty($row['recurrence_id']) && empty($row['parent_task_id']);
        if ($isOccurrence) {
            if (self::evidenceSetting($conn, 'tasks_evidence_delete_occurrence', 'allow') === 'refuse') {
                throw new ServiceError('validation', 'occurrence_delete_refused',
                    function_exists('t') ? t('tasks.evidence.delete_refused') : 'This task is an occurrence of a repeat, and repeats are kept as evidence here. Skip it instead.');
            }
            /* #181 (Dr4inder's review): build the complete retained record FIRST - a failed
               read throws instead of becoming an empty section - and write it in the same
               transaction as the delete below. No record, no delete. */
            try {
                $removalSnapshot = TaskHistory::ready($conn) ? TaskHistory::snapshot($conn, $taskId, true) : null;
            } catch (Throwable $e) {
                throw TaskHistory::notRecorded('removal', $e);
            }
        }

        $ids = [$taskId];
        $frontier = [$taskId];
        while ($frontier) {
            $ph = implode(',', array_fill(0, count($frontier), '?'));
            $kids = $conn->prepare("SELECT id FROM tasks WHERE parent_task_id IN ($ph)");
            $kids->execute($frontier);
            $frontier = array_map('intval', $kids->fetchAll(PDO::FETCH_COLUMN));
            $ids = array_merge($ids, $frontier);
        }

        // 🔴 BEFORE the delete, not after. calendar_sync_events.task_id is
        // ON DELETE CASCADE, so the moment the task row goes the mapping rows
        // go with it — and those rows are the ONLY record of which event, in
        // whose mailbox, to remove. Reconciling afterwards would find nothing
        // and leave an orphaned appointment in somebody's calendar that
        // FreeITSM can no longer update or take back.
        //
        // The whole subtask tree, because every one of them may have its own.
        foreach ($ids as $id) {
            self::calendarOnTaskChanged($conn, (int)$id, true);
        }

        $ph = implode(',', array_fill(0, count($ids), '?'));
        $own = !$conn->inTransaction();
        if ($own) $conn->beginTransaction();
        try {
        if ($isOccurrence) {
            TaskHistory::recordRemoval($conn, $ctx, $taskId, $removalSnapshot ?? null, true);
        }
        $conn->prepare("DELETE FROM task_comments WHERE task_id IN ($ph)")->execute($ids);
        $conn->prepare("DELETE FROM task_tag_map WHERE task_id IN ($ph)")->execute($ids);
        // The links to attached documents went nowhere once the task was gone
        // (#181 section 5). The documents themselves stay; the tidy-up job
        // collects any that nothing links to any more.
        try { $conn->prepare("DELETE FROM document_links WHERE parent_type = 'task' AND parent_id IN ($ph)")->execute($ids); }
        catch (Throwable) { /* an install without the documents tables */ }
        // Their checklists too (discussion #138) - whether or not the feature is
        // switched on now, or the rows are stranded pointing at deleted tasks.
        ChecklistsService::deleteForTasks($conn, $ids);
        // Their form requirements (GH #190). The submissions themselves stay - evidence.
        require_once __DIR__ . '/task_forms.php';
        TaskForms::deleteForTasks($conn, $ids);
        require_once __DIR__ . '/task_procedures.php';
        TaskProcedures::deleteForTasks($conn, $ids);
        require_once __DIR__ . '/task_follow_ups.php';
        TaskFollowUps::deleteForTasks($conn, $ids);
        foreach (array_reverse($ids) as $id) {
            $conn->prepare("DELETE FROM tasks WHERE id = ?")->execute([$id]);
        }
            if ($own) $conn->commit();
        } catch (Throwable $e) {
            if ($own && $conn->inTransaction()) $conn->rollBack();
            throw $e;
        }
        WorkflowEngine::dispatch('task.deleted', ['task' => [
            'id'          => $taskId,
            'title'       => $row['title'] ?? null,
            'priority_id' => isset($row['priority_id']) ? (int)$row['priority_id'] : null,
            'assignee_id' => isset($row['assigned_analyst_id']) ? (int)$row['assigned_analyst_id'] : null,
        ]]);
        return ['id' => $taskId, 'subtasks_deleted' => count($ids) - 1];
    }

    /**
     * Add a comment to a task (create-only). Returns the comment id.
     *
     * @param string $text  the comment text (trimmed; required)
     */
    public static function createComment(PDO $conn, ActorContext $ctx, int $taskId, string $text): int
    {
        self::assertCanWrite($conn, $ctx);
        self::loadTaskRow($conn, $ctx, $taskId);
        $text = trim($text);
        if ($text === '') {
            throw new ServiceError('validation', 'missing_field', "'text' is required.");
        }
        $conn->prepare("INSERT INTO task_comments (task_id, analyst_id, comment, created_datetime) VALUES (?, ?, ?, UTC_TIMESTAMP())")
             ->execute([$taskId, $ctx->actorId, $text]);
        $commentId = (int)$conn->lastInsertId();
        $conn->prepare("UPDATE tasks SET updated_datetime = UTC_TIMESTAMP() WHERE id = ?")->execute([$taskId]);
        // GH #89. Until now nothing announced that somebody had said something on
        // a task, so nobody could be told and no workflow could act on it — the
        // same gap ticket.note_added was added to close.
        self::taskEventDispatch($conn, 'task.comment_added', $taskId, ['comment_id' => $commentId]);
        return $commentId;
    }

    /**
     * One dispatch helper for the task events that describe a CHANGE TO THE TASK
     * rather than to one person's place on it.
     *
     * ⚠️ assignee_id carries the OWNER, as it does on every other task event, so
     * a workflow reading these the way it reads task.assigned finds the person
     * accountable. Who is TOLD is a separate question, answered by the router:
     * these events reach the owner AND everybody involved.
     *
     * @param string $event  the workflow event, e.g. 'task.comment_added', 'task.status_changed'
     * @param array $extra  more keys merged into the 'task' payload, e.g. ['comment_id' => 12]
     */
    private static function taskEventDispatch(PDO $conn, string $event, int $taskId, array $extra = []): void
    {
        try {
            $rb = $conn->prepare("SELECT title, status_id, priority_id, assigned_analyst_id, due_date FROM tasks WHERE id = ?");
            $rb->execute([$taskId]);
            $taskRow = $rb->fetch(PDO::FETCH_ASSOC) ?: [];
            WorkflowEngine::dispatch($event, [
                'task' => array_merge([
                    'id'          => $taskId,
                    'title'       => $taskRow['title'] ?? null,
                    'status_id'   => isset($taskRow['status_id']) ? (int)$taskRow['status_id'] : null,
                    'priority_id' => isset($taskRow['priority_id']) ? (int)$taskRow['priority_id'] : null,
                    'assignee_id' => isset($taskRow['assigned_analyst_id']) ? (int)$taskRow['assigned_analyst_id'] : null,
                    'due_date'    => $taskRow['due_date'] ?? null,
                ], $extra),
            ]);
        } catch (Exception $wfEx) {
            error_log('Workflow dispatch error in task service (' . $event . '): ' . $wfEx->getMessage());
        }
    }

    // ======================================================================
    //  Collaborators — the other people on a task (GH #89)
    //
    //  Shown as "Involved" everywhere a person reads it. The code, the table,
    //  the API field and the workflow events all say "collaborator" instead,
    //  deliberately: that word is precise for a developer and NEVER translated,
    //  whereas its cognate is a slur for a traitor in nine of the languages
    //  FreeITSM ships in — squarely so in German, Dutch and the Nordics, and in
    //  Ukrainian it is a live criminal charge rather than a historical one. The
    //  machine translation pipeline would have produced exactly that word,
    //  unprompted, in all nine. So the UI string is `tasks.involved.*` and the
    //  English label is "Involved", which translates to a neutral everyday word
    //  in every locale and leaves no trap for the next one added.
    //
    //  🔴 THE OWNER IS NEVER A COLLABORATOR. Accountability lives in
    //  tasks.assigned_analyst_id, alone. See the schema comment.
    // ======================================================================

    /**
     * Is per-person completion switched on? (Tasks → Settings)
     *
     * Off by default — the request was to SEE other people on a task, and a tick
     * box each is a heavier thing that most desks will not want. Naming and shape
     * follow timeScope() above.
     */
    public static function collaboratorCompletionEnabled(PDO $conn): bool
    {
        try {
            $stmt = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'tasks_collaborator_completion'");
            $stmt->execute();
            $v = $stmt->fetchColumn();
        } catch (Exception) {
            return false;                      // pre-upgrade DB: behave as shipped
        }
        return is_string($v) && in_array(strtolower(trim($v)), ['1', 'on', 'true', 'yes'], true);
    }

    /**
     * The task row, scoped — for a caller that needs the owner and company
     * before it can decide what to offer.
     *
     * ⚠️ It exists so the READ path goes through loadTaskRow() like every write
     * does. Letting the endpoint run its own `SELECT * FROM tasks` would be the
     * one by-id route not covered by the company check, which is exactly how a
     * child endpoint gets missed.
     */
    public static function taskForCollaborators(PDO $conn, ActorContext $ctx, int $taskId): array
    {
        return self::loadTaskRow($conn, $ctx, $taskId);
    }

    /**
     * The collaborators on one task, in the order they were added.
     *
     * ⚠️ INNER JOIN on analysts, so a row whose analyst has been deleted simply
     * does not come back. The FK cascades, so that should not happen — but a
     * half-migrated install can have rows the constraint never covered, and a
     * chip reading "undefined" is worse than a person quietly missing.
     */
    public static function collaboratorsFor(PDO $conn, int $taskId): array
    {
        $all = self::collaboratorsForMany($conn, [$taskId]);
        return $all[$taskId] ?? [];
    }

    /**
     * Collaborators for many tasks at once, as [task_id => [rows]].
     *
     * ⚠️ ONE QUERY, NOT ONE PER TASK. The board renders every task on the desk;
     * a per-task lookup here would add a query per card to the module's busiest
     * endpoint. Same reason list.php already batches its subtask and tag lookups.
     *
     * @param array $taskIds  task ids; non-numeric, zero and repeated values are dropped
     */
    public static function collaboratorsForMany(PDO $conn, array $taskIds): array
    {
        $taskIds = array_values(array_unique(array_filter(array_map('intval', $taskIds))));
        if (!$taskIds) {
            return [];
        }
        $in = implode(',', array_fill(0, count($taskIds), '?'));
        try {
            $stmt = $conn->prepare(
                "SELECT tc.task_id, tc.analyst_id, tc.is_completed, tc.completed_datetime,
                        a.full_name AS analyst_name
                   FROM task_collaborators tc
                   JOIN analysts a ON a.id = tc.analyst_id
                  WHERE tc.task_id IN ($in)
               ORDER BY tc.added_datetime, tc.id"
            );
            $stmt->execute($taskIds);
        } catch (Exception) {
            // An install that has not run Database Verification since upgrading
            // has no table yet. No collaborators is the correct answer there, and
            // it must not take the board down with it.
            return [];
        }

        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(int)$r['task_id']][] = [
                'analyst_id'         => (int)$r['analyst_id'],
                'analyst_name'       => $r['analyst_name'],
                'is_completed'       => (int)$r['is_completed'] === 1,
                'completed_datetime' => $r['completed_datetime'],
            ];
        }
        return $out;
    }

    /** Put somebody on a task. Idempotent — adding twice is not a second fact. */
    public static function addCollaborator(PDO $conn, ActorContext $ctx, int $taskId, int $analystId): array
    {
        self::assertCanWrite($conn, $ctx);
        $task = self::loadTaskRow($conn, $ctx, $taskId);          // 404 + company scope
        self::assertCollaboratorsAllowed($task);
        self::resolveAnalyst($conn, $analystId);                  // exists and is active

        // 🔴 The owner is not a collaborator. Allowing it would put one person in
        // two roles on the same task and make every count ambiguous.
        if ((int)($task['assigned_analyst_id'] ?? 0) === $analystId) {
            throw new ServiceError('validation', 'invalid_field', 'That analyst already owns this task.');
        }
        self::assertAnalystInTaskCompany($conn, $task, $analystId);

        // INSERT IGNORE would hide a genuine failure as a no-op, so ask first.
        $exists = $conn->prepare("SELECT id FROM task_collaborators WHERE task_id = ? AND analyst_id = ?");
        $exists->execute([$taskId, $analystId]);
        if ($exists->fetchColumn()) {
            return ['added' => false];
        }

        $conn->prepare(
            "INSERT INTO task_collaborators (task_id, analyst_id, added_by_id, added_datetime)
             VALUES (?, ?, ?, UTC_TIMESTAMP())"
        )->execute([$taskId, $analystId, $ctx->actorId]);
        $conn->prepare("UPDATE tasks SET updated_datetime = UTC_TIMESTAMP() WHERE id = ?")->execute([$taskId]);

        self::collaboratorDispatch($conn, 'task.collaborator_added', $taskId, $analystId);
        return ['added' => true];
    }

    /** Take somebody off a task. */
    public static function removeCollaborator(PDO $conn, ActorContext $ctx, int $taskId, int $analystId): array
    {
        self::assertCanWrite($conn, $ctx);
        self::loadTaskRow($conn, $ctx, $taskId);
        $stmt = $conn->prepare("DELETE FROM task_collaborators WHERE task_id = ? AND analyst_id = ?");
        $stmt->execute([$taskId, $analystId]);
        if ($stmt->rowCount() === 0) {
            return ['removed' => false];
        }
        $conn->prepare("UPDATE tasks SET updated_datetime = UTC_TIMESTAMP() WHERE id = ?")->execute([$taskId]);
        self::collaboratorDispatch($conn, 'task.collaborator_removed', $taskId, $analystId);
        return ['removed' => true];
    }

    /**
     * Tick or untick one person's part of the task.
     *
     * 🔴 A TICK IS PROGRESS, NOT A GATE. The owner still closes the task, whether
     * or not everybody has ticked. Making completion conditional on the ticks
     * would mean one person leaving makes a task permanently uncloseable, and
     * would quietly hand every collaborator a veto — which is co-ownership again,
     * the thing owner+collaborators exists to avoid.
     *
     * ⚠️ Only the person themselves, or the task's owner, may move a tick. Anyone
     * else marking your work done is a statement about you that you did not make.
     *
     * @param int $analystId  the collaborator whose tick to change - not the actor; only they or the task's owner may
     * @param bool $done  true ticks their part, false unticks it
     */
    public static function setCollaboratorDone(PDO $conn, ActorContext $ctx, int $taskId, int $analystId, bool $done): array
    {
        self::assertCanWrite($conn, $ctx);
        $task = self::loadTaskRow($conn, $ctx, $taskId);
        $isSelf  = $ctx->actorId === $analystId;
        $isOwner = (int)($task['assigned_analyst_id'] ?? 0) === (int)$ctx->actorId;
        if (!$isSelf && !$isOwner) {
            throw new ServiceError('forbidden', 'forbidden', 'Only that analyst or the task owner can change this.');
        }

        $stmt = $conn->prepare(
            "UPDATE task_collaborators
                SET is_completed = ?, completed_datetime = " . ($done ? 'UTC_TIMESTAMP()' : 'NULL') . "
              WHERE task_id = ? AND analyst_id = ?"
        );
        $stmt->execute([$done ? 1 : 0, $taskId, $analystId]);
        if ($stmt->rowCount() === 0) {
            // Either not a collaborator, or already in that state. Re-read rather
            // than guessing which, so the caller gets the truth.
            $probe = $conn->prepare("SELECT id FROM task_collaborators WHERE task_id = ? AND analyst_id = ?");
            $probe->execute([$taskId, $analystId]);
            if (!$probe->fetchColumn()) {
                throw new ServiceError('not_found', 'not_found', 'That analyst is not on this task.');
            }
        }
        $conn->prepare("UPDATE tasks SET updated_datetime = UTC_TIMESTAMP() WHERE id = ?")->execute([$taskId]);
        return ['is_completed' => $done];
    }

    /** How many collaborators have yet to tick — for the closing warning. */
    public static function collaboratorsOutstanding(PDO $conn, int $taskId): int
    {
        try {
            $stmt = $conn->prepare("SELECT COUNT(*) FROM task_collaborators WHERE task_id = ? AND is_completed = 0");
            $stmt->execute([$taskId]);
            return (int)$stmt->fetchColumn();
        } catch (Exception) {
            return 0;
        }
    }

    /**
     * Collaborators are for TOP-LEVEL tasks only — Ed's call, and a sound one.
     * A subtask already takes an assignee of its own, so people on a subtask is
     * how you say "these four are each doing a piece"; collaborators on top of
     * that would give two overlapping ways to express the same thing and no rule
     * for which wins.
     *
     * @param array $taskRow  the tasks row, as loaded by loadTaskRow()
     */
    private static function assertCollaboratorsAllowed(array $taskRow): void
    {
        if (!empty($taskRow['parent_task_id'])) {
            throw new ServiceError('validation', 'invalid_field',
                'Collaborators are only available on top-level tasks. A subtask carries its own assignee.');
        }
    }

    /**
     * 🔒 The analyst being added must be able to reach the task's company.
     *
     * ⚠️ THE PICKER IS THE SAME QUESTION AND MUST USE THE SAME ANSWER. A list
     * offering analysts from other companies is a disclosure on its own, before
     * anybody is added — so api/tasks/collaborators.php filters its candidate
     * list through this too, rather than validating only on save.
     *
     * Permissive on a single-company install, where the question does not arise.
     *
     * @param array $taskRow  the tasks row, as loaded by loadTaskRow()
     * @param int $analystId  the analyst being added as a collaborator - not the actor
     */
    private static function assertAnalystInTaskCompany(PDO $conn, array $taskRow, int $analystId): void
    {
        require_once __DIR__ . '/../tenancy.php';
        if (!isMultiTenant($conn)) {
            return;
        }
        $tenantId = $taskRow['tenant_id'] ?? null;
        // A task with no company is the Default company's — the same rule
        // activeTenantFilter() applies, and the reason every task created before
        // multi-tenancy existed is still reachable.
        $tenantId = ($tenantId === null) ? getDefaultTenantId($conn) : (int)$tenantId;
        if (!analystCanAccessTenant($conn, $analystId, (int)$tenantId)) {
            throw new ServiceError('validation', 'invalid_field',
                'That analyst does not work for the company this task belongs to.');
        }
    }

    /**
     * A NEW workflow event, never a widened `task.assigned`.
     *
     * 🔴 THIS IS THE ONE THAT WOULD HAVE BROKEN EXISTING INSTALLS. `task.assigned`
     * carries a SCALAR `assignee_id`, and the notification router reads the
     * recipient from exactly that field (GH #110). Turning it into an array would
     * leave every stored workflow running and quietly matching nothing; firing
     * `task.assigned` once per collaborator instead would make workflows that
     * fired once fire four times. Both failures are silent. A separate event
     * cannot do either.
     *
     * @param string $event  'task.collaborator_added' or 'task.collaborator_removed'
     * @param int $analystId  the collaborator added or removed - sent as collaborator_id, not the actor
     */
    private static function collaboratorDispatch(PDO $conn, string $event, int $taskId, int $analystId): void
    {
        try {
            $rb = $conn->prepare("SELECT title, status_id, priority_id, assigned_analyst_id FROM tasks WHERE id = ?");
            $rb->execute([$taskId]);
            $taskRow = $rb->fetch(PDO::FETCH_ASSOC) ?: [];
            WorkflowEngine::dispatch($event, [
                'task' => [
                    'id'          => $taskId,
                    'title'       => $taskRow['title'] ?? null,
                    'status_id'   => isset($taskRow['status_id']) ? (int)$taskRow['status_id'] : null,
                    'priority_id' => isset($taskRow['priority_id']) ? (int)$taskRow['priority_id'] : null,
                    // The OWNER stays in assignee_id, so anything reading this
                    // event the way it reads task.assigned still finds the person
                    // accountable rather than the person just added.
                    'assignee_id'     => isset($taskRow['assigned_analyst_id']) ? (int)$taskRow['assigned_analyst_id'] : null,
                    'collaborator_id' => $analystId,
                ],
            ]);
        } catch (Exception $wfEx) {
            error_log('Workflow dispatch error in task service (collaborator): ' . $wfEx->getMessage());
        }
    }

    // ======================================================================
    //  Internals
    // ======================================================================

    /**
     * Load a task with its status is_closed flag, or throw 404.
     *
     * @param int $id  the task to load
     */
    private static function loadTaskRow(PDO $conn, ActorContext $ctx, int $id): array
    {
        $stmt = $conn->prepare(
            "SELECT t.*, ts.is_closed AS status_is_closed
             FROM tasks t LEFT JOIN task_statuses ts ON ts.id = t.status_id WHERE t.id = ?"
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new ServiceError('not_found', 'not_found', 'Task not found.');
        }

        // 🔒 The ONE place every by-id path passes through — update, move, delete
        // and comment all load here first. Guarding the choke point means a future
        // by-id operation is scoped by default instead of being the one that was
        // forgotten, which is how child endpoints get missed.
        //
        // Framed as not_found, never forbidden: "you may not touch this" confirms
        // another company's task exists.
        if (!self::taskAccessible($conn, $ctx, $row)) {
            throw new ServiceError('not_found', 'not_found', 'Task not found.');
        }
        return $row;
    }

    /**
     * Move a task to another company.
     *
     * Asked for in the 2.5.0 request list. Tasks could be CREATED in a company
     * but never moved between them, so a task raised against the wrong client
     * could only be deleted and retyped - losing its comments, its time entries
     * and its history along with the mistake.
     *
     * The twin of api/change-management/move_to_company.php, gated the same way:
     * the actor must be able to reach the task where it currently sits AND the
     * company it is going to. Two things make this harder than the Changes
     * version, and both are refusals rather than quiet fixes:
     *
     *  1. A SUBTASK inherits its parent's company, always (see
     *     parentTaskTenant). Moving one on its own would break that invariant
     *     silently - the subtask would sit in a company its parent is not in,
     *     and the board would show a parent with a child nobody else can see.
     *     So it is refused, naming the parent to move instead.
     *
     *  2. A task LINKED to a ticket, change or contract belongs where that
     *     record belongs; createTask already enforces exactly that and refuses a
     *     tenant_id that disagrees with the linked ticket. Moving the task alone
     *     would leave the two disagreeing, so this refuses too and says which
     *     link is in the way. Unlinking first is a decision for the person, not
     *     something to do on their behalf while they are looking elsewhere.
     *
     * SUBTASKS MOVE WITH THE PARENT. That is not a convenience - leaving them
     * behind is the same broken state as (1), reached from the other direction.
     *
     * @param int $targetTenantId  the company (tenants.id) to move the task and its subtasks to; the actor must reach it
     * @return array{moved:int, from:string, to:string}
     */
    public static function moveTaskToCompany(PDO $conn, ActorContext $ctx, int $taskId, int $targetTenantId): array
    {
        self::assertCanWrite($conn, $ctx);
        $row = self::loadTaskRow($conn, $ctx, $taskId);          // 404 if gone or out of scope

        if (!isMultiTenant($conn)) {
            throw new ServiceError('validation', 'invalid_field',
                'This install has only one company, so there is nowhere to move a task to.');
        }

        if (!empty($row['parent_task_id'])) {
            throw new ServiceError('validation', 'invalid_field',
                'A subtask always belongs to the same company as its parent. Move the parent task instead.');
        }

        // 🔴 NOT EVERY LINKED TABLE HAS A COMPANY. `contracts` has no tenant_id
        // column at all - contracts are deliberately not company-scoped - so
        // asking one which company it is in throws "Unknown column 'tenant_id'
        // in 'field list'" and the move fails outright.
        //
        // That is not hypothetical: on a real install 19 of the top-level tasks
        // are linked to a contract and only 2 to a ticket, so the contract path
        // is the COMMON one and the ticket path is the exception. The first test
        // of this picked a ticket-linked task and passed.
        //
        // Checked at runtime rather than hardcoding "contracts has no company":
        // if contracts ever gain one, this starts enforcing it without anybody
        // having to remember this function exists.
        //
        // A record with no company cannot constrain the move, so the link is
        // simply not a reason to refuse.
        foreach ([['ticket_id', 'tickets', 'ticket'], ['change_id', 'changes', 'change'], ['contract_id', 'contracts', 'contract']] as [$col, $table, $label]) {
            if (empty($row[$col])) { continue; }
            if (!self::tableHasTenantColumn($conn, $table)) { continue; }
            $s = $conn->prepare("SELECT tenant_id FROM `{$table}` WHERE id = ?");
            $s->execute([(int)$row[$col]]);
            $linked = $s->fetchColumn();
            if ($linked === false) { continue; }                 // link points at nothing; not this method's problem
            $linkedTenant = ($linked === null) ? getDefaultTenantId($conn) : (int)$linked;
            if ($linkedTenant !== $targetTenantId) {
                throw new ServiceError('validation', 'invalid_field',
                    "This task is linked to a {$label} in another company. Remove the link first, or move the {$label} too.");
            }
        }

        // BOTH checks, and the order matters.
        //
        // 🔴 companyScope is not the same question as analystCanAccessTenant.
        // The scope is what THIS caller is allowed to touch; the analyst check
        // is what the person behind it could reach in general. For a session
        // they agree, because fromSession() builds the scope from that same
        // access - so testing only the second one looks fine for ever. They
        // come apart for an API key issued for one company: the key's holder
        // may well have wider access, and honouring that would let a key move
        // work into a company it was deliberately not given. createTask already
        // checks the scope this way (resolveNewTaskTenant); leaving move to
        // check only the other would have made the two disagree about the same
        // question.
        if ($ctx->companyScope !== null && !in_array($targetTenantId, $ctx->companyScope, true)) {
            throw new ServiceError('validation', 'invalid_field', 'You do not have access to that company.');
        }
        if (!analystCanAccessTenant($conn, $ctx->actorId, $targetTenantId)) {
            throw new ServiceError('validation', 'invalid_field', 'You do not have access to that company.');
        }
        $target = getTenantById($conn, $targetTenantId);
        if (!$target) {
            // Rarely reached: an id naming no company fails the access check
            // above first, and says "no access" rather than "no such company".
            // That is deliberate - one message for both cases means probing ids
            // cannot be used to learn which companies exist. This branch covers
            // the narrow case of a company removed between the two checks.
            throw new ServiceError('validation', 'invalid_field', 'That company does not exist.');
        }

        // NULL means the Default company, NOT "shared" - so it is resolved to a
        // real id before comparing, or moving a Default-company task into the
        // Default company would look like a real move and write a nonsense
        // audit line saying it came from nowhere.
        $oldTenantId = ($row['tenant_id'] === null) ? getDefaultTenantId($conn) : (int)$row['tenant_id'];
        if ($oldTenantId === $targetTenantId) {
            $name = $target['name'] ?? '';
            return ['moved' => 0, 'from' => $name, 'to' => $name];
        }
        $oldTenant = getTenantById($conn, $oldTenantId);
        $oldName   = $oldTenant['name'] ?? 'Unknown';

        // The task and everything filed beneath it, in one transaction: a move
        // that got halfway would leave a parent and its children in different
        // companies, which is the exact state the refusals above exist to stop.
        $ownTransaction = !$conn->inTransaction();
        if ($ownTransaction) { $conn->beginTransaction(); }
        try {
            $ids = self::taskAndDescendantIds($conn, $taskId);
            $in  = implode(',', array_fill(0, count($ids), '?'));
            $conn->prepare("UPDATE tasks SET tenant_id = ?, updated_datetime = UTC_TIMESTAMP() WHERE id IN ($in)")
                 ->execute(array_merge([$targetTenantId], $ids));

            // Audited on every row that moved, not just the one that was asked
            // for: somebody opening a subtask later needs to see why its company
            // changed without having to know the parent was the thing moved.
            $aud = $conn->prepare(
                "INSERT INTO task_audit (task_id, analyst_id, field_name, old_value, new_value, source, created_datetime)
                 VALUES (?, ?, 'Company', ?, ?, 'app', UTC_TIMESTAMP())"
            );
            foreach ($ids as $id) {
                try { $aud->execute([$id, $ctx->actorId ?: null, $oldName, $target['name']]); }
                catch (Exception) { /* audit is best-effort, as elsewhere */ }
            }

            if ($ownTransaction) { $conn->commit(); }
            return ['moved' => count($ids), 'from' => $oldName, 'to' => $target['name']];
        } catch (Throwable $t) {
            if ($ownTransaction && $conn->inTransaction()) { $conn->rollBack(); }
            throw $t;
        }
    }

    /**
     * Does this table carry a `tenant_id` column?
     *
     * Asked of information_schema rather than assumed, because the answer
     * differs per table and changes over time: tickets and changes have one,
     * contracts do not. Cached per request - the move loop asks about the same
     * three tables, and this is called on a path a person is waiting on.
     *
     * @param string $table  a table name - a developer literal such as 'tickets', 'changes' or 'contracts'
     */
    private static function tableHasTenantColumn(PDO $conn, string $table): bool
    {
        static $cache = [];
        if (array_key_exists($table, $cache)) { return $cache[$table]; }
        try {
            $s = $conn->prepare(
                "SELECT COUNT(*) FROM information_schema.columns
                 WHERE table_schema = DATABASE() AND table_name = ? AND column_name = 'tenant_id'"
            );
            $s->execute([$table]);
            return $cache[$table] = ((int)$s->fetchColumn() > 0);
        } catch (Exception) {
            // Cannot tell: treat the table as having no company rather than
            // blocking the move on a question that could not be asked.
            return $cache[$table] = false;
        }
    }

    /**
     * A task and every task beneath it, however deep.
     *
     * Iterative rather than recursive SQL: MySQL 5.7 has no recursive CTE and
     * this product still supports it. The visited set is not decoration - a
     * parent chain that somehow loops would otherwise spin here for ever, and a
     * bad row in the database should not be able to hang a request.
     *
     * @param int $rootId  the task to start from; it is included in the result
     */
    private static function taskAndDescendantIds(PDO $conn, int $rootId): array
    {
        $all     = [$rootId];
        $seen    = [$rootId => true];
        $frontier = [$rootId];

        while ($frontier) {
            $in = implode(',', array_fill(0, count($frontier), '?'));
            $stmt = $conn->prepare("SELECT id FROM tasks WHERE parent_task_id IN ($in)");
            $stmt->execute($frontier);
            $next = [];
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $childId) {
                $childId = (int)$childId;
                if (isset($seen[$childId])) { continue; }
                $seen[$childId] = true;
                $all[]  = $childId;
                $next[] = $childId;
            }
            $frontier = $next;
        }
        return $all;
    }

    /**
     * A subtask inherits its parent's company, always.
     *
     * @param int $parentId  the parent task whose company a new subtask takes
     */
    private static function parentTaskTenant(PDO $conn, int $parentId): ?int
    {
        $stmt = $conn->prepare("SELECT tenant_id FROM tasks WHERE id = ?");
        $stmt->execute([$parentId]);
        $v = $stmt->fetchColumn();
        return ($v === false || $v === null) ? null : (int) $v;
    }

    /**
     * Is an already-loaded task row inside the actor's company scope?
     *
     * @param array $row  a tasks row (SELECT t.*); no tenant_id key means the column is not there yet, and allows
     */
    private static function taskAccessible(PDO $conn, ActorContext $ctx, array $row): bool
    {
        // Column absent = an install that has not run Database Verification since
        // this shipped. `SELECT t.*` simply returns no such key, so this costs
        // nothing and never throws — the alternative is every task operation
        // failing until Verify is run.
        if (!array_key_exists('tenant_id', $row)) {
            return true;
        }
        try {
            if (!isMultiTenant($conn) || $ctx->companyScope === null) {
                return true;
            }
            $tid = ($row['tenant_id'] === null) ? getDefaultTenantId($conn) : (int) $row['tenant_id'];
            return in_array($tid, $ctx->companyScope, true);
        } catch (Exception $e) {
            // Same rule as ticketAccessible(): genuinely missing schema degrades to
            // allow, a lock-wait or dropped connection denies. Never fail open on
            // "the database was briefly busy".
            return tenancyDegradeAllowed($e);
        }
    }

    /**
     * Which company should a NEW task belong to?
     *
     * 1. Linked to a ticket → the ticket's company. The task is work arising from
     *    that ticket, so it belongs where the ticket belongs. This is the GH #83
     *    path and the one that matters most.
     * 2. Explicitly supplied → honoured only if the actor may reach it. Refused
     *    outright rather than quietly downgraded to NULL, which would silently
     *    move someone's task to the Default company.
     * 3. From the UI → the analyst's active company, so a task created while
     *    working on a client does not vanish from the board the moment it is made.
     * 4. Otherwise → NULL, i.e. the Default company. The state every existing task
     *    is already in.
     *
     * @param array $in  the create input; only tenant_id is read (an explicit company, checked against the actor's scope)
     */
    private static function resolveNewTaskTenant(PDO $conn, ActorContext $ctx, array $in, ?int $ticketId): ?int
    {
        $explicit = array_key_exists('tenant_id', $in) && $in['tenant_id'] !== null && $in['tenant_id'] !== ''
            ? (int) $in['tenant_id']
            : null;

        if ($ticketId) {
            $stmt = $conn->prepare("SELECT tenant_id FROM tickets WHERE id = ?");
            $stmt->execute([$ticketId]);
            $ticketTenant = $stmt->fetchColumn();
            $inherited = ($ticketTenant === false || $ticketTenant === null) ? null : (int) $ticketTenant;
            if ($explicit !== null && $explicit !== $inherited) {
                throw new ServiceError('validation', 'invalid_field',
                    "'tenant_id' does not match the company of the ticket this task is linked to.");
            }
            return $inherited;
        }

        if ($explicit !== null) {
            if (isMultiTenant($conn) && $ctx->companyScope !== null
                && !in_array($explicit, $ctx->companyScope, true)) {
                throw new ServiceError('validation', 'invalid_field', "Unknown tenant_id: {$explicit}");
            }
            return $explicit;
        }

        if ($ctx->source === 'ui' && isMultiTenant($conn)) {
            return getActiveTenantId($conn, $ctx->actorId);
        }
        return null;
    }

    /**
     * Resolve a status/priority lookup by name or id — strict 422 on unknown. Returns [id, name(, is_closed)] or null.
     *
     * @param array $in  the request; {$key}_id wins over $key (a name), neither gives null
     * @param string $key  'status' or 'priority'
     * @param string $table  the lookup table: 'task_statuses' or 'task_priorities' - a developer literal
     * @param bool $withClosed  true to read and return is_closed as a third element (statuses only)
     */
    private static function resolveLookup(PDO $conn, array $in, string $key, string $table, bool $withClosed = false): ?array
    {
        $cols = 'id, name' . ($withClosed ? ', is_closed' : '');
        if (isset($in[$key . '_id']) && $in[$key . '_id'] !== '' && $in[$key . '_id'] !== null) {
            $stmt = $conn->prepare("SELECT $cols FROM `$table` WHERE id = ? LIMIT 1");
            $stmt->execute([(int)$in[$key . '_id']]);
        } elseif (isset($in[$key]) && trim((string)$in[$key]) !== '') {
            $stmt = $conn->prepare("SELECT $cols FROM `$table` WHERE name = ? LIMIT 1");
            $stmt->execute([trim((string)$in[$key])]);
        } else {
            return null;
        }
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new ServiceError('validation', 'invalid_field', "Unknown task $key: " . ($in[$key] ?? $in[$key . '_id']));
        }
        $out = [(int)$row['id'], $row['name']];
        if ($withClosed) {
            $out[] = (int)$row['is_closed'];
        }
        return $out;
    }

    /**
     * The default row of a lookup table — the CONFIGURED default, never a name.
     *
     * This used to try `WHERE name = 'To Do'` / `'Medium'` first and fall back to
     * is_default. Two faults in one line. It ignored an admin who had made a
     * different status or priority the default, because the English seed name
     * won; and on a site that had renamed or translated them the name matched
     * nothing, which is the shape of GH #79 — every ticket intake path looked the
     * starting status up by the word 'Open', and a German install that renamed it
     * to Offen got tickets with no status and no error.
     *
     * Same ordering as the fix there: is_active filters rather than sorts, since
     * a deactivated default is absent from the dropdown and choosing it would
     * reproduce the symptom by another route; is_closed is deliberately not
     * filtered, because an admin who makes a closed status the default has said
     * what they meant.
     *
     * @param string $table  the lookup table: 'task_statuses' or 'task_priorities' - a developer literal
     * @param bool $withClosed  true to read and return is_closed as a third element (statuses only)
     */
    private static function lookupDefault(PDO $conn, string $table, bool $withClosed = false): array
    {
        $cols = 'id, name' . ($withClosed ? ', is_closed' : '');
        $row = $conn->query(
            "SELECT $cols FROM `$table` WHERE is_active = 1
             ORDER BY is_default DESC, display_order, id LIMIT 1"
        )->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return $withClosed ? [null, null, 0] : [null, null];
        }
        $out = [(int)$row['id'], $row['name']];
        if ($withClosed) {
            $out[] = (int)$row['is_closed'];
        }
        return $out;
    }

    /**
     * Contractors (3.3.0): suppliers live in Contracts, so CHOOSING one needs it -
     * from the board, the REST API or Ask AI alike. The system (actorId 0) may.
     */
    private static function contractorAllowed(PDO $conn, ActorContext $ctx): void
    {
        if ($ctx->actorId > 0 && !analystCanAccessModule($conn, $ctx->actorId, 'contracts')) {
            throw new ServiceError('forbidden', 'forbidden', 'Choosing a contractor needs access to Contracts.');
        }
    }

    /**
     * Validate an optional team id (422 on unknown).
     *
     * @param mixed $value  a team id as sent; null or '' means no team
     */
    private static function validateTeam(PDO $conn, $value): ?int
    {
        if ($value === '' || $value === null) {
            return null;
        }
        $teamId = (int)$value;
        $stmt = $conn->prepare("SELECT id FROM teams WHERE id = ?");
        $stmt->execute([$teamId]);
        if (!$stmt->fetchColumn()) {
            throw new ServiceError('validation', 'invalid_field', "Unknown team id: {$teamId}");
        }
        return $teamId;
    }

    /**
     * Validate a link column + parent task. Ticket links are checked against the
     * actor's company scope (tickets are tenant-scoped; tasks aren't).
     *
     * @param string $field  which link: 'ticket_id', 'change_id', 'contract_id' or 'parent_task_id'; anything else gives null
     * @param mixed $value  the linked record's id as sent; null or '' means no link
     */
    private static function validateLink(PDO $conn, ActorContext $ctx, string $field, $value): ?int
    {
        if ($value === '' || $value === null) {
            return null;
        }
        $id = (int)$value;
        switch ($field) {
            case 'ticket_id':
                if (!self::ticketAccessible($conn, $ctx, $id)) {
                    throw new ServiceError('validation', 'invalid_field', "Unknown ticket id: {$id}");
                }
                return $id;
            case 'change_id':
                $stmt = $conn->prepare("SELECT id FROM changes WHERE id = ?");
                break;
            case 'contract_id':
                // A customer contract outside the actor's companies is unknown (#153).
                require_once __DIR__ . '/../contract_party.php';
                [$kVis, $extra] = contractVisibilitySqlForScope($conn, $ctx->companyScope, 'k');
                $stmt = $conn->prepare("SELECT k.id FROM contracts k WHERE k.id = ?$kVis");
                break;
            case 'parent_task_id':
                $stmt = $conn->prepare("SELECT id FROM tasks WHERE id = ?");
                break;
            default:
                return null;
        }
        try {
            $stmt->execute(array_merge([$id], $extra ?? []));
            if (!$stmt->fetchColumn()) {
                throw new ServiceError('validation', 'invalid_field', "Unknown " . str_replace('_id', '', $field) . " id: {$id}");
            }
        } catch (PDOException) {
            throw new ServiceError('validation', 'invalid_field', ucfirst(str_replace('_id', ' ', $field)) . "links are not available on this install.");
        }
        return $id;
    }

    /** Can the actor's company scope reach this ticket? Mirrors apiKeyCanAccessTicket via ActorContext. */
    private static function ticketAccessible(PDO $conn, ActorContext $ctx, int $ticketId): bool
    {
        if ($ticketId <= 0) {
            return false;
        }
        try {
            $stmt = $conn->prepare("SELECT tenant_id FROM tickets WHERE id = ?");
            $stmt->execute([$ticketId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                return false;
            }
            if (!isMultiTenant($conn) || $ctx->companyScope === null) {
                return true;
            }
            $tid = ($row['tenant_id'] === null) ? getDefaultTenantId($conn) : (int)$row['tenant_id'];
            return in_array($tid, $ctx->companyScope, true);
        } catch (Exception $e) {
            // ⚠️ This was `return true` for ANY exception, with the comment "tenant_id
            // column missing on a part-migrated install". That is the right intention
            // and the wrong implementation, and it is the fail-open pattern F9 and S3
            // removed from every guard in includes/tenancy.php: a missing column is one
            // reason a query throws, but so are a lock-wait timeout, a dropped
            // connection and a permissions error — and none of those is evidence that
            // the caller may reach this ticket. Under load, "the database was briefly
            // busy" meant "yes, you may read another company's tasks".
            //
            // tenancyDegradeAllowed() is the helper that draws that line: genuinely
            // missing schema still degrades to allow, everything else denies and logs.
            // Erlend Volden listed this class as "S3's cousins"; this was the last one.
            return tenancyDegradeAllowed($e);
        }
    }

    /**
     * Resolve a tags array (names or ids) to ids — strict 422 on unknown (curated list).
     *
     * @param array $tags  tag ids or names, mixed; each must already exist in task_tags
     */
    private static function resolveTags(PDO $conn, array $tags): array
    {
        $ids = [];
        foreach ($tags as $t) {
            if (is_numeric($t)) {
                $stmt = $conn->prepare("SELECT id FROM task_tags WHERE id = ?");
                $stmt->execute([(int)$t]);
            } else {
                $stmt = $conn->prepare("SELECT id FROM task_tags WHERE name = ?");
                $stmt->execute([trim((string)$t)]);
            }
            $id = $stmt->fetchColumn();
            if ($id === false) {
                throw new ServiceError('validation', 'invalid_field', "Unknown tag: {$t}. Tags are managed in Tasks > Settings.");
            }
            $ids[(int)$id] = true;
        }
        return array_keys($ids);
    }

    /**
     * Replaces a task's tags with $tagIds.
     *
     * @param array $tagIds  the task_tags ids the task should end up with; an empty array removes them all
     */
    private static function syncTags(PDO $conn, int $taskId, array $tagIds): void
    {
        $conn->prepare("DELETE FROM task_tag_map WHERE task_id = ?")->execute([$taskId]);
        $ins = $conn->prepare("INSERT IGNORE INTO task_tag_map (task_id, tag_id) VALUES (?, ?)");
        foreach ($tagIds as $tid) {
            $ins->execute([$taskId, $tid]);
        }
    }

    // ======================================================================
    //  Time on tasks (GH #112)
    // ======================================================================

    /** Where the time features are offered: 'both', 'tasks', 'subtasks' or 'off'. */
    public const TIME_SCOPE_DEFAULT = 'both';

    /**
     * The administrator's choice of where time appears, from Tasks → Settings.
     *
     * A top-level task and a subtask are the SAME record, told apart only by
     * parent_task_id, so this setting is the only thing that distinguishes them
     * here — which is why the rule lives in one function rather than being
     * re-derived at each call site.
     */
    public static function timeScope(PDO $conn): string
    {
        try {
            $stmt = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'tasks_time_scope'");
            $stmt->execute();
            $v = $stmt->fetchColumn();
        } catch (Exception) {
            return self::TIME_SCOPE_DEFAULT;      // pre-upgrade DB: behave as shipped
        }
        $v = is_string($v) ? strtolower(trim($v)) : '';
        return in_array($v, ['both', 'tasks', 'subtasks', 'off'], true) ? $v : self::TIME_SCOPE_DEFAULT;
    }

    /**
     * May THIS task carry scheduled work and time entries?
     *
     * ⚠️ Existing entries are never deleted when the setting narrows, only
     * hidden — an administrator changing a display rule must not destroy hours
     * somebody recorded. The same applies when a task changes level: drag a
     * top-level task under a parent and its time is still there, shown again the
     * moment the setting allows it.
     *
     * @param ?int $parentTaskId  the task's parent, or null for a top-level task
     */
    public static function timeAllowedFor(PDO $conn, ?int $parentTaskId): bool
    {
        $scope = self::timeScope($conn);
        if ($scope === 'off')      return false;
        if ($scope === 'both')     return true;
        $isSubtask = $parentTaskId !== null;
        return $scope === 'subtasks' ? $isSubtask : !$isSubtask;
    }

    /**
     * Log time against a task. Returns the entry id.
     *
     * @param array $in  minutes (required, above 0), notes, entry_at (an ISO 8601 instant; default now in UTC)
     */
    public static function createTimeEntry(PDO $conn, ActorContext $ctx, int $taskId, array $in): int
    {
        self::assertCanWrite($conn, $ctx);
        $task = self::loadTaskRow($conn, $ctx, $taskId);          // 404 + company scope
        self::assertTimeAllowed($conn, $task);
        $minutes = isset($in['minutes']) ? (int)$in['minutes'] : 0;
        if ($minutes <= 0) {
            throw new ServiceError('validation', 'missing_field', "'minutes' is required and must be a positive integer.");
        }
        $notes = trim((string)($in['notes'] ?? ''));
        // Server-stamped UTC when not given, exactly like a ticket's time entry —
        // this is an INSTANT, not a naive wall clock, and the browser sends it as
        // ISO-8601 UTC. See GH #116 for what happens when that is got wrong.
        $entryAt = isset($in['entry_at']) && $in['entry_at'] !== ''
            ? self::parseInstant((string)$in['entry_at'], 'entry_at')
            : gmdate('Y-m-d H:i:s');
        $conn->prepare(
            "INSERT INTO task_time_entries (task_id, analyst_id, notes, time_spent_minutes, entry_datetime)
             VALUES (?, ?, ?, ?, ?)"
        )->execute([$taskId, $ctx->actorId, $notes !== '' ? $notes : null, $minutes, $entryAt]);
        return (int)$conn->lastInsertId();
    }

    /**
     * Soft-delete a time entry. Only the analyst who logged it may remove it.
     *
     * @param int $entryId  the time entry (task_time_entries.id) to remove
     */
    public static function deleteTimeEntry(PDO $conn, ActorContext $ctx, int $entryId): void
    {
        self::assertCanWrite($conn, $ctx);
        $stmt = $conn->prepare("SELECT task_id, analyst_id FROM task_time_entries WHERE id = ? AND is_active = 1");
        $stmt->execute([$entryId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new ServiceError('not_found', 'not_found', 'Time entry not found.');
        }
        self::loadTaskRow($conn, $ctx, (int)$row['task_id']);      // company scope
        if ((int)$row['analyst_id'] !== $ctx->actorId) {
            throw new ServiceError('forbidden', 'forbidden', 'You can only delete your own time entries.');
        }
        $conn->prepare("UPDATE task_time_entries SET is_active = 0 WHERE id = ?")->execute([$entryId]);
    }

    /**
     * The entries on a task, plus two totals.
     *
     * `total_minutes` is this task alone. `total_with_subtasks_minutes` adds every
     * subtask's time — which is the number mbsouth asked for, and the one that
     * answers "how long did this piece of work take" once it has been broken up.
     *
     * One level deep, deliberately: FreeITSM only lets a task have subtasks, not
     * sub-subtasks, so a recursive walk would be answering a question the data
     * model cannot ask.
     */
    public static function timeEntriesFor(PDO $conn, ActorContext $ctx, int $taskId): array
    {
        self::loadTaskRow($conn, $ctx, $taskId);                   // 404 + company scope

        $stmt = $conn->prepare(
            "SELECT e.id, e.task_id, e.analyst_id, e.notes, e.time_spent_minutes, e.entry_datetime,
                    a.full_name AS analyst_name
             FROM task_time_entries e
             JOIN analysts a ON a.id = e.analyst_id
             WHERE e.task_id = ? AND e.is_active = 1
             ORDER BY e.entry_datetime DESC, e.id DESC"
        );
        $stmt->execute([$taskId]);
        $entries = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $own = 0;
        foreach ($entries as $e) {
            $own += (int)$e['time_spent_minutes'];
        }

        $sub = $conn->prepare(
            "SELECT COALESCE(SUM(e.time_spent_minutes), 0)
             FROM task_time_entries e
             JOIN tasks t ON t.id = e.task_id
             WHERE t.parent_task_id = ? AND e.is_active = 1"
        );
        $sub->execute([$taskId]);
        $subtotal = (int)$sub->fetchColumn();

        return [
            'entries'                     => $entries,
            'total_minutes'               => $own,
            'subtask_minutes'             => $subtotal,
            'total_with_subtasks_minutes' => $own + $subtotal,
        ];
    }

    /**
     * Refuse the WRITE too, not only the display — a stale tab must not slip past a setting.
     *
     * @param array $taskRow  the tasks row, as loaded by loadTaskRow()
     */
    private static function assertTimeAllowed(PDO $conn, array $taskRow): void
    {
        $parent = isset($taskRow['parent_task_id']) && $taskRow['parent_task_id'] !== null
            ? (int)$taskRow['parent_task_id'] : null;
        if (!self::timeAllowedFor($conn, $parent)) {
            throw new ServiceError('validation', 'not_allowed',
                'Time is not being recorded against this kind of task. See Tasks → Settings.');
        }
    }

    /**
     * task.assigned — fired whenever a task comes to rest with a NEW assignee,
     * from creation and from reassignment alike (GH #110).
     *
     * ⚠️ Both paths matter, and only one of them looks like "assigning". A task
     * created with an assignee is the ordinary way work is handed out here, so
     * firing only on reassignment would leave the commonest case silent — which
     * is the half a fix like this is most likely to miss.
     *
     * The row is read back AFTER the write so the payload carries what was
     * actually stored, rather than a mixture of new input and stale columns when
     * the title changed in the same request.
     *
     * Deliberately NOT filtered for self-assignment. Whether to suppress "you
     * assigned it to yourself" is the notification layer's rule, enforced in
     * includes/notifications_router.php; a workflow may legitimately want the
     * event either way, and duplicating the rule here would let the two drift.
     *
     * @param int $assigneeId  the analyst the task is now assigned to
     */
    private static function assignedDispatch(PDO $conn, int $taskId, int $assigneeId): void
    {
        try {
            $rb = $conn->prepare("SELECT title, status_id, priority_id FROM tasks WHERE id = ?");
            $rb->execute([$taskId]);
            $taskRow = $rb->fetch(PDO::FETCH_ASSOC) ?: [];
            WorkflowEngine::dispatch('task.assigned', [
                'task' => [
                    'id'          => $taskId,
                    'title'       => $taskRow['title'] ?? null,
                    'status_id'   => isset($taskRow['status_id']) ? (int)$taskRow['status_id'] : null,
                    'priority_id' => isset($taskRow['priority_id']) ? (int)$taskRow['priority_id'] : null,
                    // The NEW assignee. The notification router reads the
                    // recipient from exactly this field.
                    'assignee_id' => $assigneeId,
                ],
            ]);
        } catch (Exception $wfEx) {
            error_log('Workflow dispatch error in task service (assigned): ' . $wfEx->getMessage());
        }
    }

    /** save.php's exact task.completed dispatch (open -> closed via PATCH only). */
    private static function completedDispatch(PDO $conn, int $taskId): void
    {
        try {
            $rb = $conn->prepare("SELECT title, priority_id, assigned_analyst_id FROM tasks WHERE id = ?");
            $rb->execute([$taskId]);
            $taskRow = $rb->fetch(PDO::FETCH_ASSOC) ?: [];
            WorkflowEngine::dispatch('task.completed', [
                'task' => [
                    'id'          => $taskId,
                    'title'       => $taskRow['title'] ?? null,
                    'priority_id' => isset($taskRow['priority_id']) ? (int)$taskRow['priority_id'] : null,
                    'assignee_id' => isset($taskRow['assigned_analyst_id']) ? (int)$taskRow['assigned_analyst_id'] : null,
                ],
            ]);
        } catch (Exception $wfEx) {
            error_log('Workflow dispatch error in task service: ' . $wfEx->getMessage());
        }
    }

    /** Throws unless the id is an active analyst. */
    private static function resolveAnalyst(PDO $conn, int $analystId): void
    {
        $stmt = $conn->prepare("SELECT full_name FROM analysts WHERE id = ? AND is_active = 1");
        $stmt->execute([$analystId]);
        if ($stmt->fetchColumn() === false) {
            throw new ServiceError('validation', 'invalid_field', "Unknown or inactive analyst id: {$analystId}");
        }
    }

    /**
     * An estimate in hours (3.3.0): empty = not estimated; otherwise more than 0
     * and at most 9999, to two decimals ("1.5" = an hour and a half).
     *
     * @param mixed $v  the estimate in hours as sent; null or blank means not estimated
     */
    private static function parseEstimate($v): ?float
    {
        if ($v === null || (is_string($v) && trim($v) === '')) return null;
        if (!is_numeric($v) || (float)$v <= 0 || (float)$v > 9999) {
            throw new ServiceError('validation', 'invalid_field', "'estimate_hours' must be a number of hours above 0 and at most 9999.");
        }
        return round((float)$v, 2);
    }

    /**
     * Validates an optional YYYY-MM-DD date for $field; blank gives null.
     *
     * @param mixed $value  the date as sent (YYYY-MM-DD); null or '' gives null
     * @param string $field  the field name, used in the error message
     */
    private static function parseDateOnly($value, string $field): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $d = DateTimeImmutable::createFromFormat('Y-m-d', (string)$value);
        if (!$d || $d->format('Y-m-d') !== (string)$value) {
            throw new ServiceError('validation', 'invalid_field', "'{$field}' must be a date in YYYY-MM-DD format.");
        }
        return (string)$value;
    }

    /**
     * A NAIVE wall clock — scheduled work, stored and shown exactly as typed.
     *
     * ⚠️ Deliberately NOT parseInstant() below. A 2pm slot means 2pm to everybody
     * who reads it, so this value is never zone-converted in either direction; a
     * zone offset in the input is therefore refused rather than quietly dropped,
     * because accepting it would imply a conversion that will not happen.
     *
     * @param mixed $value  a wall-clock time as sent (YYYY-MM-DD HH:MM[:SS], a T allowed); null or '' gives null, an offset is refused
     * @param string $field  the field name, used in the error message
     */
    private static function parseNaiveDateTime($value, string $field): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $v = trim(str_replace('T', ' ', (string)$value));
        if (preg_match('/(Z|[+\-]\d{2}:?\d{2})$/i', $v)) {
            throw new ServiceError('validation', 'invalid_field',
                "'{$field}' is a wall-clock time and must not carry a timezone offset — send it as YYYY-MM-DD HH:MM.");
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $v)) {
            $v .= ':00';
        }
        $d = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $v);
        if (!$d || $d->format('Y-m-d H:i:s') !== $v) {
            throw new ServiceError('validation', 'invalid_field',
                "'{$field}' must be a date and time in YYYY-MM-DD HH:MM format.");
        }
        return $v;
    }

    /**
     * An absolute INSTANT — when a piece of work actually happened.
     *
     * The opposite kind of value to the one above, and the distinction that GH
     * #116 turned on: this one IS zone-converted for each reader, so a zone-less
     * string can only be read as UTC. The browser sends ISO-8601 with a Z.
     *
     * @param string $value  a date and time, ISO 8601; with no zone it is read as UTC
     * @param string $field  the field name, used in the error message
     */
    private static function parseInstant(string $value, string $field): string
    {
        try {
            $dt = new DateTimeImmutable(trim($value), new DateTimeZone('UTC'));
            return $dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        } catch (Exception) {
            throw new ServiceError('bad_request', 'invalid_parameter',
                "'{$field}' is not a valid date/time. Use ISO 8601, e.g. 2026-07-02T09:00:00Z.");
        }
    }

    // ======================================================================
    //  Repeating task evidence (#181) and Read only access
    // ======================================================================

    /**
     * Refuse a write to an analyst with Read only access to Tasks (#181 Part G) - the same rule
     * Change Management and Forms enforce. In the service, so the board, the task window, the
     * REST API and anything added later all refuse alike; the endpoints check it as well.
     */
    /**
     * Who a task made by nobody (a system or workflow caller, actor 0) is
     * credited to. tasks.created_by_id is NOT NULL, so - as the workflow
     * "Create a task" action does - the first active administrator.
     */
    private static function systemCreatorId(PDO $conn): int
    {
        $id = $conn->query('SELECT id FROM analysts WHERE is_admin = 1 AND COALESCE(is_active, 1) = 1 ORDER BY id LIMIT 1')->fetchColumn();
        if (!$id) throw new ServiceError('validation', 'no_admin', 'There is no active administrator to credit the task to.');
        return (int)$id;
    }

    public static function assertCanWrite(PDO $conn, ActorContext $ctx): void
    {
        if ($ctx->actorId > 0 && function_exists('analystModuleLevel') && analystModuleLevel($conn, $ctx->actorId, 'tasks') === 'read') {
            throw new ServiceError('forbidden', 'read_only',
                function_exists('t') ? t('common.module_read_only') : 'You have read-only access to this module.');
        }
    }

    /**
     * One of the Tasks -> Settings -> Evidence settings, or its default.
     *
     * @param string $key  tasks_evidence_completed_checklist, tasks_evidence_delete_occurrence,
     *     tasks_evidence_skip_status_id or tasks_evidence_report_access
     * @param string $default  the value when unset - always the forgiving choice
     */
    public static function evidenceSetting(PDO $conn, string $key, string $default): string
    {
        try {
            $s = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ?");
            $s->execute([$key]);
            $v = $s->fetchColumn();
            return ($v === false || $v === null || $v === '') ? $default : (string)$v;
        } catch (Throwable) {
            return $default;
        }
    }

    /**
     * The closed status a skipped occurrence moves to: the one chosen in Tasks -> Settings -> Evidence
     * if it is still an active closed status, otherwise the LAST closed status in the admin's order -
     * "Cancelled" on a standard install, and never the first, which is "Done".
     */
    public static function skipStatusId(PDO $conn): ?int
    {
        $chosen = (int)self::evidenceSetting($conn, 'tasks_evidence_skip_status_id', '0');
        if ($chosen > 0) {
            $s = $conn->prepare("SELECT id FROM task_statuses WHERE id = ? AND is_closed = 1 AND is_active = 1");
            $s->execute([$chosen]);
            if ($s->fetchColumn()) return $chosen;
        }
        $id = $conn->query("SELECT id FROM task_statuses WHERE is_closed = 1 AND is_active = 1
                             ORDER BY display_order DESC, id DESC LIMIT 1")->fetchColumn();
        return $id ? (int)$id : null;
    }

    /**
     * Skip an occurrence of a repeat, with a reason (#181 Part D): it closes, recorded as SKIPPED -
     * not done, not merely cancelled - and in a repeat set to "after completion" the next one is
     * made, just as completing it would. Returns the status it moved to.
     *
     * @param string $reason  why it was skipped (required)
     */
    public static function skipOccurrence(PDO $conn, ActorContext $ctx, int $taskId, string $reason): array
    {
        self::assertCanWrite($conn, $ctx);
        $row = self::loadTaskRow($conn, $ctx, $taskId);
        $reason = trim($reason);
        if ($reason === '') {
            throw new ServiceError('validation', 'missing_field',
                function_exists('t') ? t('tasks.evidence.skip_reason_required') : 'Say why it is being skipped.');
        }
        if (empty($row['recurrence_id']) || !empty($row['parent_task_id'])) {
            throw new ServiceError('validation', 'invalid_field', 'Only an occurrence of a repeating task can be skipped.');
        }
        if (!empty($row['status_is_closed'])) {
            throw new ServiceError('validation', 'invalid_field', 'This occurrence is already closed.');
        }
        $statusId = self::skipStatusId($conn);
        if ($statusId === null) {
            throw new ServiceError('validation', 'invalid_field', 'No active closed status is configured for tasks.');
        }
        $before = TaskHistory::before($conn, $taskId);
        // No checklist gate: skipping is the explicit decision NOT to do the work,
        // so outstanding steps are the point, not an obstacle.
        // The skip and its record, together (#181).
        $conn->beginTransaction();
        try {
            $conn->prepare("UPDATE tasks SET status_id = ?, completed_datetime = COALESCE(completed_datetime, UTC_TIMESTAMP()),
                                   updated_datetime = UTC_TIMESTAMP() WHERE id = ?")
                 ->execute([$statusId, $taskId]);
            $statusResult = TaskHistory::recordStatusChange($conn, $ctx, $taskId, $before, $statusId, mb_substr($reason, 0, 255));
            $conn->commit();
        } catch (Throwable $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            throw $e;
        }
        TaskHistory::afterCommit($conn, $ctx, $statusResult);
        self::taskEventDispatch($conn, 'task.status_changed', $taskId);
        self::calendarOnTaskChanged($conn, $taskId);
        return ['id' => $taskId, 'status_id' => $statusId];
    }

    /**
     * An analyst's name for the history, or null for nobody.
     *
     * @param mixed $id  an analyst id, or null / '' for nobody
     */
    private static function analystLabel(PDO $conn, $id): ?string
    {
        if (!$id) return null;
        $s = $conn->prepare("SELECT full_name FROM analysts WHERE id = ?");
        $s->execute([(int)$id]);
        return (string)($s->fetchColumn() ?: ('#' . (int)$id));
    }

    /**
     * A team's name for the history, or null for none.
     *
     * @param mixed $id  a team id, or null / '' for none
     */
    private static function teamLabel(PDO $conn, $id): ?string
    {
        if (!$id) return null;
        $s = $conn->prepare("SELECT name FROM teams WHERE id = ?");
        $s->execute([(int)$id]);
        return (string)($s->fetchColumn() ?: ('#' . (int)$id));
    }
}
