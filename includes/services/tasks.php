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
 * 🔒 Company scope: tasks carry no tenant_id, but a task can LINK to a ticket,
 * and tickets are tenant-scoped. Ticket links are validated against the actor's
 * companyScope (ActorContext) — so the UI now enforces the same isolation the
 * API always did (it previously stored any ticket id unchecked).
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
            ?? self::lookupDefault($conn, 'task_statuses', 'To Do', true);
        $priority = self::resolveLookup($conn, $in, 'priority', 'task_priorities')
            ?? self::lookupDefault($conn, 'task_priorities', 'Medium');

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

        $conn->prepare(
            "INSERT INTO tasks (title, description, status_id, priority_id, start_date, due_date,
                                assigned_analyst_id, assigned_team_id, parent_task_id,
                                ticket_id, change_id, contract_id, board_position, created_by_id,
                                completed_datetime, created_datetime, updated_datetime)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())"
        )->execute([
            $title, $description, $status[0], $priority[0], $startDate, $dueDate,
            $analystId, $teamId, $links['parent_task_id'],
            $links['ticket_id'], $links['change_id'], $links['contract_id'],
            $boardPosition, $ctx->actorId,
            !empty($status[2]) ? gmdate('Y-m-d H:i:s') : null,
        ]);
        $taskId = (int)$conn->lastInsertId();

        if ($tagIds !== null) {
            self::syncTags($conn, $taskId, $tagIds);
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
        return $taskId;
    }

    private static function updateTask(PDO $conn, ActorContext $ctx, int $taskId, array $in): int
    {
        $current = self::loadTaskRow($conn, $taskId);           // 404 if gone
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

        if (array_key_exists('assigned_analyst_id', $in)) {
            $newAnalyst = ($in['assigned_analyst_id'] === '' || $in['assigned_analyst_id'] === null) ? null : (int)$in['assigned_analyst_id'];
            if ($newAnalyst !== null) {
                self::resolveAnalyst($conn, $newAnalyst);
            }
            $updates[] = 'assigned_analyst_id = ?';
            $args[]    = $newAnalyst;
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
        }
        return $taskId;
    }

    /** Kanban move: change status (+ completed mechanics) and re-pack the target column. No workflow event. Returns the id. */
    public static function moveTask(PDO $conn, ActorContext $ctx, int $taskId, array $in): int
    {
        $current = self::loadTaskRow($conn, $taskId);

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
        return $taskId;
    }

    /** Hard-delete a task + its whole subtask tree (comments/tags too). Returns ['id','subtasks_deleted']. */
    public static function deleteTask(PDO $conn, ActorContext $ctx, int $taskId): array
    {
        self::loadTaskRow($conn, $taskId);

        $ids = [$taskId];
        $frontier = [$taskId];
        while ($frontier) {
            $ph = implode(',', array_fill(0, count($frontier), '?'));
            $kids = $conn->prepare("SELECT id FROM tasks WHERE parent_task_id IN ($ph)");
            $kids->execute($frontier);
            $frontier = array_map('intval', $kids->fetchAll(PDO::FETCH_COLUMN));
            $ids = array_merge($ids, $frontier);
        }
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $conn->prepare("DELETE FROM task_comments WHERE task_id IN ($ph)")->execute($ids);
        $conn->prepare("DELETE FROM task_tag_map WHERE task_id IN ($ph)")->execute($ids);
        foreach (array_reverse($ids) as $id) {
            $conn->prepare("DELETE FROM tasks WHERE id = ?")->execute([$id]);
        }
        return ['id' => $taskId, 'subtasks_deleted' => count($ids) - 1];
    }

    /** Add a comment to a task (create-only). Returns the comment id. */
    public static function createComment(PDO $conn, ActorContext $ctx, int $taskId, string $text): int
    {
        self::loadTaskRow($conn, $taskId);
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
    //  Internals
    // ======================================================================

    /** Load a task with its status is_closed flag, or throw 404. */
    private static function loadTaskRow(PDO $conn, int $id): array
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
        return $row;
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

    /** The default row of a lookup table: prefers the named seed, then is_default. */
    private static function lookupDefault(PDO $conn, string $table, string $preferName, bool $withClosed = false): array
    {
        $cols = 'id, name' . ($withClosed ? ', is_closed' : '');
        $stmt = $conn->prepare("SELECT $cols FROM `$table` WHERE name = ? LIMIT 1");
        $stmt->execute([$preferName]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            $row = $conn->query("SELECT $cols FROM `$table` WHERE is_default = 1 LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        }
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
            return true; // tenant_id column missing on a part-migrated install
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
}
