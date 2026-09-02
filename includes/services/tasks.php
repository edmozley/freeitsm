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
 * parent_task_id, ticket_id, change_id, contract_id, board_position, tags[].
 */

require_once __DIR__ . '/../service_context.php';
require_once __DIR__ . '/../tenancy.php';                       // isMultiTenant / getDefaultTenantId for ticket scope
require_once dirname(__DIR__, 2) . '/workflow/includes/engine.php';

class TasksService
{
    // ======================================================================
    //  Tasks
    // ======================================================================

    /** Create (no id) or update (id present) a task. Returns ['id','created']. */
    public static function saveTask(PDO $conn, ActorContext $ctx, array $in): array
    {
        if (!empty($in['id'])) {
            return ['id' => self::updateTask($conn, $ctx, (int)$in['id'], $in), 'created' => false];
        }
        return ['id' => self::createTask($conn, $ctx, $in), 'created' => true];
    }

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

        $links = [];
        foreach (['parent_task_id', 'ticket_id', 'change_id', 'contract_id'] as $field) {
            $links[$field] = self::validateLink($conn, $ctx, $field, $in[$field] ?? null);
        }

        $startDate = self::parseDateOnly($in['start_date'] ?? null, 'start_date');
        $dueDate   = self::parseDateOnly($in['due_date'] ?? null, 'due_date');
        $description = trim((string)($in['description'] ?? '')) ?: null;

        $tagIds = null;
        if (isset($in['tags']) && is_array($in['tags'])) {
            $tagIds = self::resolveTags($conn, $in['tags']);
        }

        // Append to the end of the target status column (top-level tasks only).
        $posStmt = $conn->prepare("SELECT COALESCE(MAX(board_position), -1) + 1 FROM tasks WHERE status_id = ? AND parent_task_id IS NULL");
        $posStmt->execute([$status[0]]);
        $boardPosition = (int)$posStmt->fetchColumn();

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
            $boardPosition, $ctx->actorId,
            !empty($status[2]) ? gmdate('Y-m-d H:i:s') : null,
        ]);
        $taskId = (int)$conn->lastInsertId();

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

    private static function updateTask(PDO $conn, ActorContext $ctx, int $taskId, array $in): int
    {
        $current = self::loadTaskRow($conn, $ctx, $taskId);           // 404 if gone
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

        // Status — completed_datetime mechanics + workflow dispatch.
        $wasClosed = (bool)($current['status_is_closed'] ?? false);
        $firesCompleted = false;
        $status = self::resolveLookup($conn, $in, 'status', 'task_statuses', true);
        if ($status !== null && $status[0] !== (int)$current['status_id']) {
            $updates[] = 'status_id = ?';
            $args[]    = $status[0];
            if ($status[2]) {
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

        foreach (['start_date', 'due_date'] as $field) {
            if (array_key_exists($field, $in)) {
                $updates[] = "$field = ?";
                $args[]    = self::parseDateOnly($in[$field], $field);
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
            self::completedDispatch($conn, $taskId);
            self::recurrenceOnClosed($conn, $taskId);
        }
        if ($assignedTo !== null) {
            self::assignedDispatch($conn, $taskId, $assignedTo);
        }
        self::calendarOnTaskChanged($conn, $taskId);
        return $taskId;
    }

    /** Kanban move: change status (+ completed mechanics) and re-pack the target column. No workflow event. Returns the id. */
    public static function moveTask(PDO $conn, ActorContext $ctx, int $taskId, array $in): int
    {
        $current = self::loadTaskRow($conn, $ctx, $taskId);

        $status = self::resolveLookup($conn, $in, 'status', 'task_statuses', true);
        $targetStatusId = $status !== null ? $status[0] : (int)$current['status_id'];
        $targetIsClosed = $status !== null ? (bool)$status[2] : (bool)$current['status_is_closed'];

        $position = array_key_exists('position', $in) && $in['position'] !== null && $in['position'] !== ''
            ? max(0, (int)$in['position'])
            : null; // null = end of column

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
            $conn->commit();
        } catch (Exception $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            throw $e;
        }

        // Recurrence fires from HERE TOO (#94). Dragging a card into a closed
        // column is the commonest way a task gets completed, and this method
        // deliberately dispatches no workflow event — so hooking only saveTask
        // would give a task that repeats when you tick it and silently does not
        // when you drag it. Outside the transaction: the move is committed and
        // must stand whatever the recurrence does.
        if ($targetIsClosed && !(bool)$current['status_is_closed']) {
            self::recurrenceOnClosed($conn, $taskId);
        }
        // Completing by DRAGGING must take the calendar entry away too, exactly
        // as ticking the box does. Same reason recurrence fires from here.
        self::calendarOnTaskChanged($conn, $taskId);
        return $taskId;
    }

    /**
     * Make the next occurrence, if this task repeats on completion (#94).
     *
     * Wrapped rather than called directly so both completion paths go through
     * one place, and so a missing recurrence service — an install part-way
     * through an upgrade — cannot break completing a task.
     */
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

    private static function recurrenceOnClosed(PDO $conn, int $taskId): void
    {
        try {
            require_once __DIR__ . '/task_recurrence.php';
            TaskRecurrence::onTaskClosed($conn, $taskId);
        } catch (Throwable $e) {
            error_log('Task recurrence hook failed for task ' . $taskId . ': ' . $e->getMessage());
        }
    }

    /** Hard-delete a task + its whole subtask tree (comments/tags too). Returns ['id','subtasks_deleted']. */
    public static function deleteTask(PDO $conn, ActorContext $ctx, int $taskId): array
    {
        $row = self::loadTaskRow($conn, $ctx, $taskId);

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
        $conn->prepare("DELETE FROM task_comments WHERE task_id IN ($ph)")->execute($ids);
        $conn->prepare("DELETE FROM task_tag_map WHERE task_id IN ($ph)")->execute($ids);
        foreach (array_reverse($ids) as $id) {
            $conn->prepare("DELETE FROM tasks WHERE id = ?")->execute([$id]);
        }
        WorkflowEngine::dispatch('task.deleted', ['task' => [
            'id'          => $taskId,
            'title'       => $row['title'] ?? null,
            'priority_id' => isset($row['priority_id']) ? (int)$row['priority_id'] : null,
            'assignee_id' => isset($row['assigned_analyst_id']) ? (int)$row['assigned_analyst_id'] : null,
        ]]);
        return ['id' => $taskId, 'subtasks_deleted' => count($ids) - 1];
    }

    /** Add a comment to a task (create-only). Returns the comment id. */
    public static function createComment(PDO $conn, ActorContext $ctx, int $taskId, string $text): int
    {
        self::loadTaskRow($conn, $ctx, $taskId);
        $text = trim($text);
        if ($text === '') {
            throw new ServiceError('validation', 'missing_field', "'text' is required.");
        }
        $conn->prepare("INSERT INTO task_comments (task_id, analyst_id, comment, created_datetime) VALUES (?, ?, ?, UTC_TIMESTAMP())")
             ->execute([$taskId, $ctx->actorId, $text]);
        $commentId = (int)$conn->lastInsertId();
        $conn->prepare("UPDATE tasks SET updated_datetime = UTC_TIMESTAMP() WHERE id = ?")->execute([$taskId]);
        return $commentId;
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
        } catch (Exception $e) {
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
        } catch (Exception $e) {
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
     */
    public static function setCollaboratorDone(PDO $conn, ActorContext $ctx, int $taskId, int $analystId, bool $done): array
    {
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
        } catch (Exception $e) {
            return 0;
        }
    }

    /**
     * Collaborators are for TOP-LEVEL tasks only — Ed's call, and a sound one.
     * A subtask already takes an assignee of its own, so people on a subtask is
     * how you say "these four are each doing a piece"; collaborators on top of
     * that would give two overlapping ways to express the same thing and no rule
     * for which wins.
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

    /** Load a task with its status is_closed flag, or throw 404. */
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

    /** A subtask inherits its parent's company, always. */
    private static function parentTaskTenant(PDO $conn, int $parentId): ?int
    {
        $stmt = $conn->prepare("SELECT tenant_id FROM tasks WHERE id = ?");
        $stmt->execute([$parentId]);
        $v = $stmt->fetchColumn();
        return ($v === false || $v === null) ? null : (int) $v;
    }

    /** Is an already-loaded task row inside the actor's company scope? */
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

    /** Resolve a status/priority lookup by name or id — strict 422 on unknown. Returns [id, name(, is_closed)] or null. */
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

    /** Validate an optional team id (422 on unknown). */
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
                $stmt = $conn->prepare("SELECT id FROM contracts WHERE id = ?");
                break;
            case 'parent_task_id':
                $stmt = $conn->prepare("SELECT id FROM tasks WHERE id = ?");
                break;
            default:
                return null;
        }
        try {
            $stmt->execute([$id]);
            if (!$stmt->fetchColumn()) {
                throw new ServiceError('validation', 'invalid_field', "Unknown " . str_replace('_id', '', $field) . " id: {$id}");
            }
        } catch (PDOException $e) {
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

    /** Resolve a tags array (names or ids) to ids — strict 422 on unknown (curated list). */
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
        } catch (Exception $e) {
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
     */
    public static function timeAllowedFor(PDO $conn, ?int $parentTaskId): bool
    {
        $scope = self::timeScope($conn);
        if ($scope === 'off')      return false;
        if ($scope === 'both')     return true;
        $isSubtask = $parentTaskId !== null;
        return $scope === 'subtasks' ? $isSubtask : !$isSubtask;
    }

    /** Log time against a task. Returns the entry id. */
    public static function createTimeEntry(PDO $conn, ActorContext $ctx, int $taskId, array $in): int
    {
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

    /** Soft-delete a time entry. Only the analyst who logged it may remove it. */
    public static function deleteTimeEntry(PDO $conn, ActorContext $ctx, int $entryId): void
    {
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

    /** Refuse the WRITE too, not only the display — a stale tab must not slip past a setting. */
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

    private static function resolveAnalyst(PDO $conn, int $analystId): void
    {
        $stmt = $conn->prepare("SELECT full_name FROM analysts WHERE id = ? AND is_active = 1");
        $stmt->execute([$analystId]);
        if ($stmt->fetchColumn() === false) {
            throw new ServiceError('validation', 'invalid_field', "Unknown or inactive analyst id: {$analystId}");
        }
    }

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
     */
    private static function parseInstant(string $value, string $field): string
    {
        try {
            $dt = new DateTimeImmutable(trim($value), new DateTimeZone('UTC'));
            return $dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        } catch (Exception $e) {
            throw new ServiceError('bad_request', 'invalid_parameter',
                "'{$field}' is not a valid date/time. Use ISO 8601, e.g. 2026-07-02T09:00:00Z.");
        }
    }
}
