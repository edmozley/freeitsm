<?php
/**
 * ProjectsService - the write rules for the Projects module (3.2.0), ONCE.
 *
 * Shared by the UI endpoints (api/projects/*.php) and, later, the REST API and
 * the MCP server. Each caller passes an ActorContext + canonical input; this
 * layer validates, writes, records history and returns ids or throws
 * ServiceError. It never emits HTTP.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * TASKS ARE THE WORK
 *
 * A project's work items are ordinary tasks carrying tasks.project_id (and
 * optionally project_stage_id). This service never owns task rows: deleting a
 * project or a stage DETACHES its tasks, it never deletes them - the work people
 * did must survive a project being tidied away. The FKs are SET NULL as well;
 * the hand-written detach is for installs whose FKs failed to add.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * COMPANIES
 *
 * A project is scoped data: it belongs to exactly one company, NULL = the
 * Default company. Every by-id method starts with loadForActor(), which reports
 * a project in a company the caller cannot reach as not found - never forbidden,
 * so a company's projects cannot be probed by guessing ids.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * METHODOLOGY
 *
 * A lens, not a schema (includes/projects/methodologies.php). Switching method
 * relabels the OPEN time boxes to the new preset's kind and leaves closed ones
 * exactly as they were. Nothing is converted or deleted, and the switch is
 * written to the project's history.
 */

require_once __DIR__ . '/../service_context.php';
require_once __DIR__ . '/../tenancy.php';
require_once __DIR__ . '/../projects/methodologies.php';
require_once __DIR__ . '/../projects/settings.php';

class ProjectsService
{
    /** The fields a person (or an API key) may set, and how each is validated. */
    public static function fieldMap(): array
    {
        return [
            'name'             => ['type' => 'string', 'max' => 200, 'required' => true],
            'summary'          => ['type' => 'text',   'max' => 20000],
            'goal'             => ['type' => 'string', 'max' => 500],
            'methodology'      => ['type' => 'enum',   'values' => array_keys(projectMethodologies())],
            'status'           => ['type' => 'enum',   'values' => projectStatuses()],
            'health'           => ['type' => 'enum',   'values' => projectHealthValues()],
            'health_note'      => ['type' => 'string', 'max' => 500],
            'owner_analyst_id' => ['type' => 'analyst'],
            'start_date'       => ['type' => 'date'],
            'target_end_date'  => ['type' => 'date'],
            'actual_end_date'  => ['type' => 'date'],
            'colour'           => ['type' => 'enum',   'values' => array_keys(projectColours())],
            'icon'             => ['type' => 'enum',   'values' => projectIcons()],
            'business_case'    => ['type' => 'text',   'max' => 50000],
            'tailoring'        => ['type' => 'tailoring'],
        ];
    }

    // ======================================================================
    //  Projects
    // ======================================================================

    /** Create (no id) or update (id present). Returns ['id', 'created']. */
    public static function saveProject(PDO $conn, ActorContext $ctx, array $in, ?int $tenantId = null): array
    {
        if (!empty($in['id'])) {
            return ['id' => self::updateProject($conn, $ctx, (int)$in['id'], $in), 'created' => false];
        }
        return ['id' => self::createProject($conn, $ctx, $in, $tenantId), 'created' => true];
    }

    public static function createProject(PDO $conn, ActorContext $ctx, array $in, ?int $tenantId): int
    {
        $store = self::storeTenant($conn, $tenantId);
        if ($ctx->companyScope !== null) {
            $effective = $store === null ? getDefaultTenantId($conn) : $store;
            if (!in_array($effective, $ctx->companyScope, true)) {
                throw new ServiceError('forbidden', 'forbidden', 'You cannot add projects for that company.');
            }
        }
        if ($ctx->actorId > 0 && !projectCanCreate($conn, $ctx->actorId)) {
            throw new ServiceError('forbidden', 'forbidden', 'Only people who manage Projects can create projects here.');
        }
        if (trim((string)($in['name'] ?? '')) === '') {
            throw new ServiceError('validation', 'missing_field', 'Give the project a name.');
        }
        // A new project starts the way Projects -> Settings says, unless told otherwise.
        if (!array_key_exists('methodology', $in)) $in['methodology'] = projectSetting($conn, 'project_default_method');
        // A new project is led by whoever created it unless told otherwise.
        if (!array_key_exists('owner_analyst_id', $in) && $ctx->actorId > 0) $in['owner_analyst_id'] = $ctx->actorId;

        $cols = ['tenant_id', 'created_by_id'];
        $vals = [$store, $ctx->actorId > 0 ? $ctx->actorId : null];
        foreach (self::fieldMap() as $field => $def) {
            if (!array_key_exists($field, $in)) continue;
            $cols[] = $field;
            $vals[] = self::validateField($conn, $field, $in[$field], $def);
        }
        self::assertDates($in['start_date'] ?? null, $in['target_end_date'] ?? null);
        $status = $in['status'] ?? 'proposed';
        if (in_array($status, projectFinishedStatuses(), true)) {
            $cols[] = 'closed_datetime'; $vals[] = gmdate('Y-m-d H:i:s');
        }

        $ph = implode(', ', array_fill(0, count($cols), '?'));
        $conn->prepare("INSERT INTO projects (" . implode(', ', $cols) . ", created_datetime, updated_datetime)
                        VALUES ($ph, UTC_TIMESTAMP(), UTC_TIMESTAMP())")->execute($vals);
        $id = (int)$conn->lastInsertId();
        self::audit($conn, $id, $ctx->actorId, 'project_created', null, trim((string)$in['name']), self::source($ctx));
        self::syncCalendar($conn);
        return $id;
    }

    public static function updateProject(PDO $conn, ActorContext $ctx, int $id, array $in): int
    {
        $cur = self::loadForActor($conn, $ctx, $id);
        self::assertCanChange($conn, $ctx, $cur);
        $sets = []; $args = []; $changes = [];
        foreach (self::fieldMap() as $field => $def) {
            if (!array_key_exists($field, $in)) continue;
            $v = self::validateField($conn, $field, $in[$field], $def);
            if (self::same($cur[$field], $v)) continue;
            $sets[] = "$field = ?"; $args[] = $v;
            $changes[$field] = [$cur[$field], $v];
        }
        if (!$sets) return $id;

        $start  = array_key_exists('start_date', $changes) ? $changes['start_date'][1] : $cur['start_date'];
        $target = array_key_exists('target_end_date', $changes) ? $changes['target_end_date'][1] : $cur['target_end_date'];
        self::assertDates($start, $target);

        // Finishing a project stamps when; reopening one clears it. The end date
        // is filled in for you if nobody has set it.
        if (isset($changes['status'])) {
            $wasDone = in_array($changes['status'][0], projectFinishedStatuses(), true);
            $isDone  = in_array($changes['status'][1], projectFinishedStatuses(), true);
            if ($isDone && !$wasDone) {
                $sets[] = 'closed_datetime = UTC_TIMESTAMP()';
                if ($changes['status'][1] === 'closed' && empty($cur['actual_end_date']) && !isset($changes['actual_end_date'])) {
                    $sets[] = 'actual_end_date = UTC_DATE()';
                }
            } elseif ($wasDone && !$isDone) {
                $sets[] = 'closed_datetime = NULL';
            }
        }

        $conn->beginTransaction();
        try {
            $args[] = $id;
            $conn->prepare("UPDATE projects SET " . implode(', ', $sets) . ", updated_datetime = UTC_TIMESTAMP() WHERE id = ?")->execute($args);
            if (isset($changes['methodology'])) {
                self::applyMethodology($conn, $id, $changes['methodology'][1]);
            }
            $conn->commit();
        } catch (Throwable $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            throw $e;
        }

        foreach ($changes as $f => [$o, $nv]) {
            self::audit($conn, $id, $ctx->actorId, $f, self::auditDisplay($conn, $f, $o), self::auditDisplay($conn, $f, $nv), self::source($ctx));
        }
        // A name, a status, a date or a method (which renames its stages) can all move an entry.
        self::syncCalendar($conn);
        return $id;
    }

    /**
     * Delete a project. Its tasks are DETACHED, never deleted; its stages and
     * history go with it. Returns ['id', 'tasks_detached'].
     */
    public static function deleteProject(PDO $conn, ActorContext $ctx, int $id): array
    {
        $cur = self::loadForActor($conn, $ctx, $id);
        if ($ctx->actorId > 0 && !projectCanDelete($conn, $ctx->actorId, $cur)) {
            throw new ServiceError('forbidden', 'forbidden', 'Only the project manager, the person who created it or someone who manages Projects can delete a project.');
        }
        $conn->beginTransaction();
        try {
            $st = $conn->prepare("UPDATE tasks SET project_id = NULL, project_stage_id = NULL WHERE project_id = ?");
            $st->execute([$id]);
            $detached = $st->rowCount();
            // Phase 2's records, by hand too - each on its own, because before
            // Database Verification a table may not exist, and that must never
            // stop a project being deleted.
            foreach (['project_raci', 'project_members', 'project_items', 'project_raid', 'project_tolerances'] as $t) {
                try { $conn->prepare("DELETE FROM `$t` WHERE project_id = ?")->execute([$id]); } catch (Throwable $e) { /* not created yet */ }
            }
            foreach (['project_stages', 'project_audit'] as $t) {
                $conn->prepare("DELETE FROM `$t` WHERE project_id = ?")->execute([$id]);
            }
            // Its links too, by hand - an install whose FKs failed to add has no
            // cascade to rely on. Each table on its own: before Verification it
            // may not exist, and that must never stop a project being deleted.
            require_once __DIR__ . '/../projects/links.php';
            projectLinksDeleteAll($conn, $id);
            $conn->prepare("DELETE FROM projects WHERE id = ?")->execute([$id]);
            $conn->commit();
        } catch (Throwable $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            throw $e;
        }
        self::syncCalendar($conn);
        return ['id' => $id, 'tasks_detached' => $detached];
    }

    /**
     * Relabel the OPEN time boxes to the preset's kind. Closed ones are history
     * and keep the name they were run under.
     */
    private static function applyMethodology(PDO $conn, int $projectId, string $method): void
    {
        $preset = projectMethodologies()[$method] ?? null;
        if (!$preset) return;
        $conn->prepare("UPDATE project_stages SET kind = ?, updated_datetime = UTC_TIMESTAMP()
                         WHERE project_id = ? AND status <> 'closed'")->execute([$preset['timebox'], $projectId]);
        // A method that allows one active time box at a time keeps the earliest
        // active one and returns any others to planned, so the switch never
        // leaves the project in a state its new method forbids.
        if ($preset['single_active']) {
            $st = $conn->prepare("SELECT id FROM project_stages WHERE project_id = ? AND status = 'active' ORDER BY position, id");
            $st->execute([$projectId]);
            $ids = $st->fetchAll(PDO::FETCH_COLUMN);
            if (count($ids) > 1) {
                array_shift($ids);
                $ph = implode(',', array_fill(0, count($ids), '?'));
                $conn->prepare("UPDATE project_stages SET status = 'planned' WHERE id IN ($ph)")->execute(array_map('intval', $ids));
            }
        }
    }

    // ======================================================================
    //  Stages (phases / stages / sprints)
    // ======================================================================

    /** Create or update a time box. Returns its id. */
    public static function saveStage(PDO $conn, ActorContext $ctx, int $projectId, array $in): int
    {
        $project = self::loadForActor($conn, $ctx, $projectId);
        self::assertCanChange($conn, $ctx, $project);
        $preset  = projectMethodologies()[$project['methodology']] ?? projectMethodologies()['simple'];
        $stageId = (int)($in['id'] ?? 0);
        $cur = null;
        if ($stageId > 0) {
            $cur = self::loadStage($conn, $stageId);
            if ((int)$cur['project_id'] !== $projectId) throw new ServiceError('not_found', 'not_found', 'Stage not found.');
        }

        $name = trim((string)($in['name'] ?? ($cur['name'] ?? '')));
        if ($name === '') throw new ServiceError('validation', 'missing_field', 'Give it a name.');
        if (mb_strlen($name) > 150) throw new ServiceError('validation', 'invalid_field', 'The name is too long.');
        $goal   = array_key_exists('goal', $in) ? self::str($in['goal'], 500) : ($cur['goal'] ?? null);
        $start  = array_key_exists('start_date', $in) ? self::date($in['start_date']) : ($cur['start_date'] ?? null);
        $end    = array_key_exists('end_date', $in) ? self::date($in['end_date']) : ($cur['end_date'] ?? null);
        self::assertDates($start, $end);
        $status = array_key_exists('status', $in) ? (string)$in['status'] : ($cur['status'] ?? 'planned');
        if (!in_array($status, projectStageStatuses(), true)) throw new ServiceError('validation', 'invalid_field', 'Unknown status.');

        if ($status === 'active' && $preset['single_active']) {
            $q = $conn->prepare("SELECT name FROM project_stages WHERE project_id = ? AND status = 'active' AND id <> ? LIMIT 1");
            $q->execute([$projectId, $stageId]);
            if (($other = $q->fetchColumn()) !== false) {
                throw new ServiceError('validation', 'invalid_field', "\"$other\" is still active. Close it before starting another.");
            }
        }

        if ($cur) {
            $conn->prepare("UPDATE project_stages SET name = ?, goal = ?, start_date = ?, end_date = ?, status = ?, updated_datetime = UTC_TIMESTAMP() WHERE id = ?")
                 ->execute([$name, $goal, $start, $end, $status, $stageId]);
            if ($cur['status'] !== $status) {
                self::audit($conn, $projectId, $ctx->actorId, 'stage_status', $cur['name'] . ': ' . $cur['status'], $name . ': ' . $status, self::source($ctx));
            }
            self::syncCalendar($conn);
            return $stageId;
        }

        $pos = (int)$conn->query("SELECT COALESCE(MAX(position), 0) + 1 FROM project_stages WHERE project_id = " . $projectId)->fetchColumn();
        $conn->prepare("INSERT INTO project_stages (project_id, kind, name, goal, start_date, end_date, position, status, created_datetime, updated_datetime)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())")
             ->execute([$projectId, $preset['timebox'], $name, $goal, $start, $end, $pos, $status]);
        $newId = (int)$conn->lastInsertId();
        self::audit($conn, $projectId, $ctx->actorId, 'stage_added', null, $name, self::source($ctx));
        self::touch($conn, $projectId);
        self::syncCalendar($conn);
        return $newId;
    }

    /** Delete a time box. Its tasks stay in the project, unassigned to any stage. */
    public static function deleteStage(PDO $conn, ActorContext $ctx, int $projectId, int $stageId): void
    {
        self::assertCanChange($conn, $ctx, self::loadForActor($conn, $ctx, $projectId));
        $stage = self::loadStage($conn, $stageId);
        if ((int)$stage['project_id'] !== $projectId) throw new ServiceError('not_found', 'not_found', 'Stage not found.');
        $conn->beginTransaction();
        try {
            $conn->prepare("UPDATE tasks SET project_stage_id = NULL WHERE project_stage_id = ?")->execute([$stageId]);
            $conn->prepare("DELETE FROM project_stages WHERE id = ?")->execute([$stageId]);
            $conn->commit();
        } catch (Throwable $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            throw $e;
        }
        self::audit($conn, $projectId, $ctx->actorId, 'stage_removed', $stage['name'], null, self::source($ctx));
        self::touch($conn, $projectId);
        self::syncCalendar($conn);
    }

    /** Put the project's time boxes in the given order (ids not listed keep their place after). */
    public static function reorderStages(PDO $conn, ActorContext $ctx, int $projectId, array $ids): void
    {
        self::assertCanChange($conn, $ctx, self::loadForActor($conn, $ctx, $projectId));
        $st = $conn->prepare("UPDATE project_stages SET position = ? WHERE id = ? AND project_id = ?");
        $pos = 1;
        foreach ($ids as $sid) {
            $st->execute([$pos++, (int)$sid, $projectId]);
        }
        self::touch($conn, $projectId);
    }

    // ======================================================================
    //  Tasks in a project
    // ======================================================================

    /**
     * Put a task in a project (and optionally a stage), or take it out (null).
     * The task and the project must belong to the same company - a project is
     * one company's work, and a task cannot carry another company's plan.
     */
    public static function assignTask(PDO $conn, ActorContext $ctx, int $taskId, ?int $projectId, ?int $stageId = null): void
    {
        $t = $conn->prepare("SELECT id, tenant_id, project_id FROM tasks WHERE id = ?");
        $t->execute([$taskId]);
        $task = $t->fetch(PDO::FETCH_ASSOC);
        if (!$task) throw new ServiceError('not_found', 'not_found', 'Task not found.');
        self::assertScope($conn, $ctx, $task, 'Task not found.');

        // Taking a task OUT of a project is a change to that project.
        if (!empty($task['project_id']) && (int)$task['project_id'] !== (int)$projectId) {
            try { self::assertCanChange($conn, $ctx, self::loadRow($conn, (int)$task['project_id'])); } catch (ServiceError $e) { if ($e->kind === 'forbidden') throw $e; }
        }
        if ($projectId === null || $projectId <= 0) {
            $conn->prepare("UPDATE tasks SET project_id = NULL, project_stage_id = NULL, updated_datetime = UTC_TIMESTAMP() WHERE id = ?")->execute([$taskId]);
            if (!empty($task['project_id'])) self::touch($conn, (int)$task['project_id']);
            return;
        }
        $project = self::loadForActor($conn, $ctx, $projectId);
        self::assertCanChange($conn, $ctx, $project);
        if (!self::sameTenant($conn, $task['tenant_id'], $project['tenant_id'])) {
            throw new ServiceError('validation', 'invalid_field', 'That task belongs to a different company from the project.');
        }
        if ($stageId !== null && $stageId > 0) {
            $s = self::loadStage($conn, $stageId);
            if ((int)$s['project_id'] !== $projectId) throw new ServiceError('validation', 'invalid_field', 'That stage is not part of this project.');
        } else {
            $stageId = null;
        }
        $conn->prepare("UPDATE tasks SET project_id = ?, project_stage_id = ?, updated_datetime = UTC_TIMESTAMP() WHERE id = ?")
             ->execute([$projectId, $stageId, $taskId]);
        self::touch($conn, $projectId);
    }

    /**
     * Create a task straight into a project (and optionally a stage).
     *
     * 🔑 The task is made by TasksService - the ONE create path - so the assigned
     * email, the bell, workflows and everything else that happens for a new task
     * happens here too. Only then is it filed under the project, and it takes the
     * project's company: a project is one company's work.
     */
    public static function createTaskInProject(PDO $conn, ActorContext $ctx, int $projectId, ?int $stageId, array $in): int
    {
        $project = self::loadForActor($conn, $ctx, $projectId);
        self::assertCanChange($conn, $ctx, $project);
        if ($stageId !== null && $stageId > 0) {
            $s = self::loadStage($conn, $stageId);
            if ((int)$s['project_id'] !== $projectId) throw new ServiceError('validation', 'invalid_field', 'That stage is not part of this project.');
        } else {
            $stageId = null;
        }
        require_once __DIR__ . '/tasks.php';
        // No ticket / parent links from here, and no company of its own: both are
        // the project's to decide. The project's company is passed EXPLICITLY, so
        // the task is created there and the task.created / task.assigned events
        // and notifications carry the right company - not created in the
        // analyst's active company and moved afterwards.
        unset($in['id'], $in['ticket_id'], $in['parent_task_id']);
        $in['tenant_id'] = isMultiTenant($conn)
            ? ($project['tenant_id'] === null ? (int)getDefaultTenantId($conn) : (int)$project['tenant_id'])
            : null;
        $res = TasksService::saveTask($conn, $ctx, $in);
        $taskId = (int)$res['id'];
        $conn->prepare("UPDATE tasks SET tenant_id = ?, project_id = ?, project_stage_id = ? WHERE id = ?")
             ->execute([$project['tenant_id'], $projectId, $stageId, $taskId]);
        self::touch($conn, $projectId);
        return $taskId;
    }

    // ======================================================================
    //  Loading and scope
    // ======================================================================

    public static function loadRow(PDO $conn, int $id): array
    {
        $st = $conn->prepare("SELECT * FROM projects WHERE id = ?");
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new ServiceError('not_found', 'not_found', 'Project not found.');
        return $row;
    }

    /** Load a project the caller may touch - out of scope reads as not found. */
    public static function loadForActor(PDO $conn, ActorContext $ctx, int $id): array
    {
        $row = self::loadRow($conn, $id);
        self::assertScope($conn, $ctx, $row, 'Project not found.');
        return $row;
    }

    public static function loadStage(PDO $conn, int $id): array
    {
        $st = $conn->prepare("SELECT * FROM project_stages WHERE id = ?");
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new ServiceError('not_found', 'not_found', 'Stage not found.');
        return $row;
    }

    /**
     * May this caller change this project? Projects -> Settings -> General decides
     * (its team, or everyone with the module); people who manage Projects always
     * may. A system actor (actorId 0 - demo import, scheduled work) is not a person
     * and is not asked.
     */
    public static function assertCanChange(PDO $conn, ActorContext $ctx, array $project): void
    {
        if ($ctx->actorId <= 0) return;
        if (!projectCanChange($conn, $ctx->actorId, $project)) {
            throw new ServiceError('forbidden', 'forbidden', 'Only this project\'s team, or someone who manages Projects, can change it.');
        }
    }

    private static function assertScope(PDO $conn, ActorContext $ctx, array $row, string $notFound): void
    {
        if ($ctx->companyScope === null || !isMultiTenant($conn)) return;
        $tid = ($row['tenant_id'] === null) ? getDefaultTenantId($conn) : (int)$row['tenant_id'];
        if (!in_array($tid, $ctx->companyScope, true)) {
            throw new ServiceError('not_found', 'not_found', $notFound);
        }
    }

    private static function sameTenant(PDO $conn, $a, $b): bool
    {
        if (!isMultiTenant($conn)) return true;
        $def = getDefaultTenantId($conn);
        $a = $a === null ? $def : (int)$a;
        $b = $b === null ? $def : (int)$b;
        return $a === $b;
    }

    /** The Default company is stored as NULL, the way every scoped table does. */
    private static function storeTenant(PDO $conn, ?int $tenantId): ?int
    {
        if ($tenantId === null || $tenantId <= 0) return null;
        return $tenantId === getDefaultTenantId($conn) ? null : $tenantId;
    }

    // ======================================================================
    //  Validation and history
    // ======================================================================

    private static function validateField(PDO $conn, string $field, $v, array $def)
    {
        $blank = $v === null || (is_string($v) && trim($v) === '');
        switch ($def['type']) {
            case 'string':
                if ($blank) {
                    if (!empty($def['required'])) throw new ServiceError('validation', 'missing_field', 'Give the project a name.');
                    return null;
                }
                $s = trim((string)$v);
                if (mb_strlen($s) > $def['max']) throw new ServiceError('validation', 'invalid_field', ucfirst(str_replace('_', ' ', $field)) . ' is too long.');
                return $s;
            case 'text':
                if ($blank) return null;
                $s = trim((string)$v);
                if (mb_strlen($s) > $def['max']) throw new ServiceError('validation', 'invalid_field', ucfirst($field) . ' is too long.');
                return $s;
            case 'enum':
                $s = (string)$v;
                if (!in_array($s, $def['values'], true)) throw new ServiceError('validation', 'invalid_field', 'Unknown ' . str_replace('_', ' ', $field) . '.');
                return $s;
            case 'date':
                return self::date($v);
            case 'tailoring':
                // {tool: bool}, only known tools; stored as JSON, NULL = the method's defaults.
                if ($blank) return null;
                $arr = is_array($v) ? $v : json_decode((string)$v, true);
                if (!is_array($arr)) throw new ServiceError('validation', 'invalid_field', 'Tailoring must be a list of tools.');
                $clean = [];
                foreach ($arr as $k => $on) if (isset(projectToolDefinitions()[$k])) $clean[$k] = (bool)$on;
                return $clean ? json_encode($clean) : null;
            case 'analyst':
                if ($blank || (int)$v <= 0) return null;
                $st = $conn->prepare("SELECT id FROM analysts WHERE id = ? AND is_active = 1");
                $st->execute([(int)$v]);
                if ($st->fetchColumn() === false) throw new ServiceError('validation', 'invalid_field', 'That analyst does not exist or is inactive.');
                return (int)$v;
        }
        return $v;
    }

    private static function date($v): ?string
    {
        if ($v === null || trim((string)$v) === '') return null;
        $s = trim((string)$v);
        $d = DateTime::createFromFormat('!Y-m-d', $s);
        if (!$d || $d->format('Y-m-d') !== $s) throw new ServiceError('bad_request', 'invalid_field', "\"$s\" is not a date.");
        return $s;
    }

    private static function assertDates(?string $start, ?string $end): void
    {
        if ($start && $end && $end < $start) {
            throw new ServiceError('validation', 'invalid_field', 'The end date is before the start date.');
        }
    }

    private static function str($v, int $max): ?string
    {
        if ($v === null) return null;
        $s = trim((string)$v);
        if ($s === '') return null;
        return mb_substr($s, 0, $max);
    }

    private static function same($a, $b): bool
    {
        if ($a === null || $b === null) return $a === $b;
        return (string)$a === (string)$b;
    }

    private static function source(ActorContext $ctx): string
    {
        return $ctx->source === 'api' ? 'api' : 'app';
    }

    /**
     * Keep the shared Calendar in step (includes/projects/calendar.php). Call it
     * AFTER the write, outside any transaction; it never throws.
     */
    public static function syncCalendar(PDO $conn): void
    {
        try {
            require_once __DIR__ . '/../projects/calendar.php';
            projectSyncCalendar($conn);
        } catch (Throwable $e) {
            error_log('projects calendar sync: ' . $e->getMessage());
        }
    }

    public static function touchProject(PDO $conn, int $projectId): void
    {
        self::touch($conn, $projectId);
    }

    private static function touch(PDO $conn, int $projectId): void
    {
        $conn->prepare("UPDATE projects SET updated_datetime = UTC_TIMESTAMP() WHERE id = ?")->execute([$projectId]);
    }

    public static function audit(PDO $conn, int $projectId, ?int $analystId, string $field, $old, $new, string $source = 'app'): void
    {
        try {
            $conn->prepare("INSERT INTO project_audit (project_id, analyst_id, field_name, old_value, new_value, source, created_datetime)
                            VALUES (?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())")
                 ->execute([$projectId, ($analystId ?? 0) > 0 ? $analystId : null, $field,
                            $old === null ? null : mb_substr((string)$old, 0, 1000),
                            $new === null ? null : mb_substr((string)$new, 0, 1000), $source]);
        } catch (Throwable $e) {
            error_log('projects audit: ' . $e->getMessage());
        }
    }

    /** What the history shows for a value: names, not ids; a line, not a paragraph. */
    private static function auditDisplay(PDO $conn, string $field, $v): ?string
    {
        if ($v === null || $v === '') return null;
        if ($field === 'owner_analyst_id') {
            $st = $conn->prepare("SELECT full_name FROM analysts WHERE id = ?");
            $st->execute([(int)$v]);
            return ($n = $st->fetchColumn()) !== false ? (string)$n : '#' . $v;
        }
        if ($field === 'tailoring') return null;
        if ($field === 'summary' || $field === 'business_case') return mb_strlen((string)$v) > 120 ? mb_substr((string)$v, 0, 117) . '...' : (string)$v;
        return (string)$v;
    }
}
