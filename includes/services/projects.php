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
            'priority'         => ['type' => 'enum',   'values' => projectPriorities()],
            'owner_analyst_id' => ['type' => 'analyst'],
            'start_date'       => ['type' => 'date'],
            'target_end_date'  => ['type' => 'date'],
            'actual_end_date'  => ['type' => 'date'],
            'colour'           => ['type' => 'enum',   'values' => array_keys(projectColours())],
            'icon'             => ['type' => 'enum',   'values' => projectIcons()],
            'business_case'    => ['type' => 'text',   'max' => 50000],
            'tailoring'        => ['type' => 'tailoring'],
            // Intake (3.3.0): a proposal's own figures - includes/projects/intake.php.
            'estimated_cost'   => ['type' => 'money'],
            'estimated_benefit'=> ['type' => 'text',   'max' => 5000],
            // Members-only (3.3.0): includes/projects/visibility.php.
            'visibility'       => ['type' => 'enum',   'values' => ['everyone', 'members']],
        ];
    }

    // ======================================================================
    //  Projects
    // ======================================================================

    /**
     * Create (no id) or update (id present). Returns ['id', 'created'].
     *
     * @param array $in  the project fields from fieldMap(); with id it updates that project, without one it creates
     */
    public static function saveProject(PDO $conn, ActorContext $ctx, array $in, ?int $tenantId = null): array
    {
        if (!empty($in['id'])) {
            return ['id' => self::updateProject($conn, $ctx, (int)$in['id'], $in), 'created' => false];
        }
        return ['id' => self::createProject($conn, $ctx, $in, $tenantId), 'created' => true];
    }

    /**
     * Creates a project for a company and returns its id.
     *
     * Fills in the default method, visibility and project manager from Projects -> Settings
     * and the creator, starts it as proposed when proposals need approval, records it in
     * the history and fires project.created.
     *
     * @param array $in  the project fields from fieldMap() (name required); methodology, visibility and owner_analyst_id
     *     default from settings and the creator; _from_form marks a proposal made on a form
     */
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
        // Who can see it (3.3.0): the setting's default unless told otherwise.
        require_once __DIR__ . '/../projects/visibility.php';
        if (!array_key_exists('visibility', $in) && projectVisibilityReady($conn)) $in['visibility'] = projectSetting($conn, 'project_default_visibility');
        // A new project is led by whoever created it unless told otherwise.
        if (!array_key_exists('owner_analyst_id', $in) && $ctx->actorId > 0) $in['owner_analyst_id'] = $ctx->actorId;

        // Intake (3.3.0): a new project that needs approval starts proposed and
        // waits (includes/projects/intake.php). A form says so with _from_form.
        require_once __DIR__ . '/../projects/intake.php';
        $pending = projectProposalNeedsApproval($conn, !empty($in['_from_form']));
        if ($pending) $in['status'] = 'proposed';

        // GH #210: the project manager must be able to see the project's company.
        if (!empty($in['owner_analyst_id']) && (int)$in['owner_analyst_id'] > 0
            && !projectAnalystReachesCompany($conn, (int)$in['owner_analyst_id'], $store)) {
            throw new ServiceError('validation', 'invalid_field', 'That project manager cannot see this project\'s company. Choose somebody who can, or give them access to the company first.');
        }

        $cols = ['tenant_id', 'created_by_id'];
        $vals = [$store, $ctx->actorId > 0 ? $ctx->actorId : null];
        if ($pending) { $cols[] = 'approval_status'; $vals[] = 'pending'; }
        foreach (self::fieldMap() as $field => $def) {
            if (!array_key_exists($field, $in)) continue;
            if (str_starts_with($field, 'estimated_') && !projectIntakeReady($conn)) continue;   // before Verification
            if ($field === 'visibility' && !projectVisibilityReady($conn)) continue;
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
        // Its budget currency, fixed now (includes/projects/budget.php).
        require_once __DIR__ . '/../projects/budget.php';
        projectStampCurrency($conn, $id);
        self::syncCalendar($conn);
        self::dispatch($conn, 'project.created', $id);
        // A proposal from a form is announced by intake.php once its proposer is stamped.
        if ($pending && empty($in['_from_form'])) self::proposalEvent($conn, $id, 'project.proposal_submitted');
        self::afterChange($conn, $id);
        return $id;
    }

    /**
     * Changes a project's fields, records each change in its history, refreshes the
     * calendar, fires project.updated and checks health and tolerances.
     *
     * Only the team or a project manager may change who can see it; a proposal waiting for
     * approval cannot be started.
     *
     * @param int $id  the project to change
     * @param array $in  only the fields to change, keyed as in fieldMap(); unchanged values are skipped
     */
    public static function updateProject(PDO $conn, ActorContext $ctx, int $id, array $in): int
    {
        $cur = self::loadForActor($conn, $ctx, $id);
        self::assertCanChange($conn, $ctx, $cur);
        $sets = []; $args = []; $changes = [];
        foreach (self::fieldMap() as $field => $def) {
            if (!array_key_exists($field, $in)) continue;
            if (!array_key_exists($field, $cur)) continue;   // a column Database Verification has not added yet
            $v = self::validateField($conn, $field, $in[$field], $def);
            if (self::same($cur[$field], $v)) continue;
            $sets[] = "$field = ?"; $args[] = $v;
            $changes[$field] = [$cur[$field], $v];
        }
        if (!$sets) return $id;
        // GH #210: a NEW project manager must be able to see the project's company.
        // Only when it changes, so one appointed before the rule never blocks an
        // unrelated edit.
        if (isset($changes['owner_analyst_id']) && $changes['owner_analyst_id'][1] !== null
            && !projectAnalystReachesCompany($conn, (int)$changes['owner_analyst_id'][1], $cur['tenant_id'])) {
            throw new ServiceError('validation', 'invalid_field', 'That project manager cannot see this project\'s company. Choose somebody who can, or give them access to the company first.');
        }
        // Members-only (3.3.0): only the team or Manage Projects say who can see it -
        // under "anyone may change", an outsider must not be able to shut the door.
        if (isset($changes['visibility']) && $ctx->actorId > 0
            && !projectIsTeam($conn, $ctx->actorId, $cur) && !projectIsManager($conn, $ctx->actorId)) {
            throw new ServiceError('forbidden', 'forbidden', 'Only this project\'s team, or someone who manages Projects, can change who can see it.');
        }
        // Intake (3.3.0): a proposal waiting for approval may stay proposed or be withdrawn, nothing else.
        require_once __DIR__ . '/../projects/intake.php';
        if (isset($changes['status']) && projectProposalBlocksStatus($cur, $changes['status'][1])) {
            throw new ServiceError('validation', 'invalid_field', 'This proposal is waiting for approval. It can start once it is approved.');
        }

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
        // Going active is when the plan is agreed: change control (3.3.0) baselines it, if the setting says so.
        if (isset($changes['status']) && $changes['status'][1] === 'active') {
            require_once __DIR__ . '/../projects/control.php';
            projectBaselineAuto($conn, $id, $ctx->actorId, 'start');
        }
        // A name, a status, a date or a method (which renames its stages) can all move an entry.
        self::syncCalendar($conn);
        self::dispatch($conn, 'project.updated', $id, ['changed' => implode(',', array_keys($changes))]);
        self::afterChange($conn, $id);
        return $id;
    }

    /**
     * Delete a project. Its tasks are DETACHED, never deleted; its stages and
     * history go with it. Returns ['id', 'tasks_detached'].
     *
     * @param int $id  the project to delete
     */
    public static function deleteProject(PDO $conn, ActorContext $ctx, int $id): array
    {
        $cur = self::loadForActor($conn, $ctx, $id);
        if ($ctx->actorId > 0 && !projectCanDelete($conn, $ctx->actorId, $cur)) {
            throw new ServiceError('forbidden', 'forbidden', 'Only the project manager, the person who created it or someone who manages Projects can delete a project.');
        }
        $event = self::eventFor($conn, $id);   // read now: afterwards there is nothing to read
        $conn->beginTransaction();
        try {
            $st = $conn->prepare("UPDATE tasks SET project_id = NULL, project_stage_id = NULL WHERE project_id = ?");
            $st->execute([$id]);
            $detached = $st->rowCount();
            // Phase 2's records, by hand too - each on its own, because before
            // Database Verification a table may not exist, and that must never
            // stop a project being deleted.
            // Benefit measurements (3.3.0), then their benefits below.
            try { $conn->prepare("DELETE m FROM project_benefit_measures m JOIN project_benefits b ON b.id = m.benefit_id WHERE b.project_id = ?")->execute([$id]); } catch (Throwable) { /* not created yet */ }
            // RAID actions first (3.3.0): joined to the entries about to go; the tasks stay.
            try { $conn->prepare("DELETE rt FROM project_raid_tasks rt JOIN project_raid r ON r.id = rt.raid_id WHERE r.project_id = ?")->execute([$id]); } catch (Throwable) { /* not created yet */ }
            // A budget line's accounts (#282), before the lines below.
            try { $conn->prepare("DELETE la FROM project_budget_line_accounts la JOIN project_budget_lines l ON l.id = la.line_id WHERE l.project_id = ?")->execute([$id]); } catch (Throwable) { /* not created yet */ }
            foreach (['project_raci', 'project_members', 'project_items', 'project_raid', 'project_tolerances', 'project_budget_lines', 'project_income_lines', 'project_reports', 'project_milestones', 'project_change_requests', 'project_baselines', 'project_benefits', 'project_gate_items', 'project_task_flow'] as $t) {
                try { $conn->prepare("DELETE FROM `$t` WHERE project_id = ?")->execute([$id]); } catch (Throwable) { /* not created yet */ }
            }
            foreach (['project_stages', 'project_audit'] as $t) {
                $conn->prepare("DELETE FROM `$t` WHERE project_id = ?")->execute([$id]);
            }
            // Its links too, by hand - an install whose FKs failed to add has no
            // cascade to rely on. Each table on its own: before Verification it
            // may not exist, and that must never stop a project being deleted.
            require_once __DIR__ . '/../projects/links.php';
            projectLinksDeleteAll($conn, $id);
            // A project's own hourly rate (3.3.0 budget): project_labour_rates has no
            // foreign key - scope + ref_id point at different tables - so by hand.
            try { $conn->prepare("DELETE FROM project_labour_rates WHERE scope = 'project' AND ref_id = ?")->execute([$id]); } catch (Throwable) { /* not created yet */ }
            // Disruption it announced stays on Service Status - it is real work on
            // real services - but no longer names a project that is gone. By hand:
            // a table made by Database Verification has no FK to do it.
            try { $conn->prepare("UPDATE status_planned SET project_id = NULL WHERE project_id = ?")->execute([$id]); } catch (Throwable) { /* not created yet */ }
            $conn->prepare("DELETE FROM projects WHERE id = ?")->execute([$id]);
            $conn->commit();
        } catch (Throwable $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            throw $e;
        }
        self::syncCalendar($conn);
        if ($event) {
            require_once __DIR__ . '/../projects/alerts.php';
            projectDispatch('project.deleted', ['project' => $event]);
        }
        return ['id' => $id, 'tasks_detached' => $detached];
    }

    /**
     * Relabel the OPEN time boxes to the preset's kind. Closed ones are history
     * and keep the name they were run under.
     *
     * @param string $method  the new methodology key from projectMethodologies(); an unknown key does nothing
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

    /**
     * Create or update a time box. Returns its id.
     *
     * @param array $in  id (a stage to change; absent for new), name (up to 150), goal, start_date, end_date, status -
     *     fields left out keep their value
     */
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
                if ($status === 'closed') self::stageClosed($conn, $projectId, $stageId, null, null);
                // A stage starting baselines the plan (change control, 3.3.0), if the setting says so.
                if ($status === 'active') { require_once __DIR__ . '/../projects/control.php'; projectBaselineAuto($conn, $projectId, $ctx->actorId, 'stage', $stageId); }
            }
            self::syncCalendar($conn);
            self::afterChange($conn, $projectId);
            return $stageId;
        }

        $pos = (int)$conn->query("SELECT COALESCE(MAX(position), 0) + 1 FROM project_stages WHERE project_id = " . $projectId)->fetchColumn();
        $conn->prepare("INSERT INTO project_stages (project_id, kind, name, goal, start_date, end_date, position, status, created_datetime, updated_datetime)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())")
             ->execute([$projectId, $preset['timebox'], $name, $goal, $start, $end, $pos, $status]);
        $newId = (int)$conn->lastInsertId();
        self::audit($conn, $projectId, $ctx->actorId, 'stage_added', null, $name, self::source($ctx));
        if ($status === 'active') { require_once __DIR__ . '/../projects/control.php'; projectBaselineAuto($conn, $projectId, $ctx->actorId, 'stage', $newId); }
        self::touch($conn, $projectId);
        self::syncCalendar($conn);
        self::afterChange($conn, $projectId);
        return $newId;
    }

    /**
     * Delete a time box. Its tasks stay in the project, unassigned to any stage.
     *
     * @param int $stageId  the stage (project_stages.id) to delete; must belong to the project
     */
    public static function deleteStage(PDO $conn, ActorContext $ctx, int $projectId, int $stageId): void
    {
        self::assertCanChange($conn, $ctx, self::loadForActor($conn, $ctx, $projectId));
        $stage = self::loadStage($conn, $stageId);
        if ((int)$stage['project_id'] !== $projectId) throw new ServiceError('not_found', 'not_found', 'Stage not found.');
        $conn->beginTransaction();
        try {
            $conn->prepare("UPDATE tasks SET project_stage_id = NULL" . self::clearPlanPosition($conn) . " WHERE project_stage_id = ?")->execute([$stageId]);
            // Its milestones stay, as the whole project's - by hand, for an install whose FK failed to add.
            try { $conn->prepare("UPDATE project_milestones SET stage_id = NULL WHERE stage_id = ?")->execute([$stageId]); } catch (Throwable) { /* not created yet */ }
            // Its gate's checklist goes with the gate (3.3.0) - by hand, for an install whose FK failed to add.
            try { $conn->prepare("DELETE FROM project_gate_items WHERE stage_id = ?")->execute([$stageId]); } catch (Throwable) { /* not created yet */ }
            $conn->prepare("DELETE FROM project_stages WHERE id = ?")->execute([$stageId]);
            $conn->commit();
        } catch (Throwable $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            throw $e;
        }
        self::audit($conn, $projectId, $ctx->actorId, 'stage_removed', $stage['name'], null, self::source($ctx));
        self::touch($conn, $projectId);
        self::syncCalendar($conn);
        self::afterChange($conn, $projectId);
    }

    /**
     * Put the project's time boxes in the given order (ids not listed keep their place after).
     *
     * @param array $ids  stage ids in their new order; positions are renumbered from 1, ids of other projects match nothing
     */
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
     *
     * @param ?int $projectId  the project to put the task in; null or 0 takes it out of its project
     * @param ?int $stageId  a stage of that project to put it in; null or 0 for none
     */
    public static function assignTask(PDO $conn, ActorContext $ctx, int $taskId, ?int $projectId, ?int $stageId = null): void
    {
        $t = $conn->prepare("SELECT id, tenant_id, project_id, parent_task_id FROM tasks WHERE id = ?");
        $t->execute([$taskId]);
        $task = $t->fetch(PDO::FETCH_ASSOC);
        if (!$task) throw new ServiceError('not_found', 'not_found', 'Task not found.');
        // A subtask belongs to its parent's project through its parent (3.3.0: the UI
        // only offered top-level tasks; the API did not refuse).
        if (!empty($task['parent_task_id']) && $projectId !== null && $projectId > 0) {
            throw new ServiceError('validation', 'invalid_field', 'A subtask goes with its parent task - put the parent in the project.');
        }
        self::assertScope($conn, $ctx, $task, 'Task not found.');

        // Taking a task OUT of a project is a change to that project.
        if (!empty($task['project_id']) && (int)$task['project_id'] !== (int)$projectId) {
            try { self::assertCanChange($conn, $ctx, self::loadRow($conn, (int)$task['project_id'])); } catch (ServiceError $e) { if ($e->kind === 'forbidden') throw $e; }
        }
        if ($projectId === null || $projectId <= 0) {
            $conn->prepare("UPDATE tasks SET project_id = NULL, project_stage_id = NULL" . self::clearPlanPosition($conn) . ", updated_datetime = UTC_TIMESTAMP() WHERE id = ?")->execute([$taskId]);
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
        // A task arriving in a stage goes to its end (GH #184) - the drag that moved
        // it then sends the order it was dropped in.
        $conn->prepare("UPDATE tasks SET project_id = ?, project_stage_id = ?" . self::clearPlanPosition($conn) . ", updated_datetime = UTC_TIMESTAMP() WHERE id = ?")
             ->execute([$projectId, $stageId, $taskId]);
        self::touch($conn, $projectId);
    }

    /**
     * ", project_position = NULL" once Database Verification has added the column
     * (GH #184), else nothing - so moving a task works before and after it.
     */
    private static function clearPlanPosition(PDO $conn): string
    {
        require_once __DIR__ . '/../projects/read.php';
        return projectPlanOrderReady($conn) ? ', project_position = NULL' : '';
    }

    /**
     * Put the tasks of one Plan lane (a stage, or "not in a stage") in the order
     * given (GH #184). The whole lane is renumbered 1..n, so the order is exact and
     * a task missing from the list - one added a moment ago by somebody else - just
     * keeps no number and sorts after the numbered ones.
     *
     * Every update is pinned to this project AND this lane: an id from elsewhere is
     * not an error, it simply changes nothing.
     *
     * @param ?int  $stageId  the lane; null or 0 for the tasks not in a stage
     * @param int[] $ids      the lane's task ids, top first
     */
    public static function orderTasks(PDO $conn, ActorContext $ctx, int $projectId, ?int $stageId, array $ids): void
    {
        require_once __DIR__ . '/../projects/read.php';
        $project = self::loadForActor($conn, $ctx, $projectId);
        self::assertCanChange($conn, $ctx, $project);
        if (!projectPlanOrderReady($conn)) {
            throw new ServiceError('validation', 'not_ready', 'Run System - Database Verification to turn on ordering tasks within a stage.');
        }
        if ($stageId !== null && $stageId > 0) {
            $st = self::loadStage($conn, $stageId);
            if ((int)$st['project_id'] !== $projectId) throw new ServiceError('validation', 'invalid_field', 'That stage is not part of this project.');
        } else {
            $stageId = null;
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($i) => $i > 0)));
        if (count($ids) > 2000) throw new ServiceError('validation', 'invalid_field', 'Too many tasks.');
        $up = $conn->prepare(
            "UPDATE tasks SET project_position = ?
              WHERE id = ? AND project_id = ? AND parent_task_id IS NULL AND project_stage_id <=> ?"
        );
        $conn->beginTransaction();
        try {
            // Clear the lane first: a task left out of the list must lose its old
            // number, or it ties with a new one and sorts into the middle (the test
            // caught exactly that).
            $conn->prepare("UPDATE tasks SET project_position = NULL WHERE project_id = ? AND parent_task_id IS NULL AND project_stage_id <=> ?")
                 ->execute([$projectId, $stageId]);
            foreach ($ids as $i => $id) $up->execute([$i + 1, $id, $projectId, $stageId]);
            $conn->commit();
        } catch (Throwable $e) {
            $conn->rollBack();
            throw $e;
        }
        self::touch($conn, $projectId);
    }

    /**
     * Create a task straight into a project (and optionally a stage).
     *
     * 🔑 The task is made by TasksService - the ONE create path - so the assigned
     * email, the bell, workflows and everything else that happens for a new task
     * happens here too. Only then is it filed under the project, and it takes the
     * project's company: a project is one company's work.
     *
     * @param ?int $stageId  a stage of the project to put the task in; null or 0 for none
     * @param array $in  the task fields as TasksService::saveTask() takes them; id, ticket_id, parent_task_id and tenant_id are ignored
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
        // A contractor (3.3.0) is set after the task is in the project, so the
        // contact's email (when Projects sends them) names the project.
        $contractor = array_intersect_key($in, ['assigned_supplier_id' => 1, 'assigned_contact_id' => 1]);
        unset($in['assigned_supplier_id'], $in['assigned_contact_id']);
        $in['tenant_id'] = isMultiTenant($conn)
            ? ($project['tenant_id'] === null ? (int)getDefaultTenantId($conn) : (int)$project['tenant_id'])
            : null;
        $res = TasksService::saveTask($conn, $ctx, $in);
        $taskId = (int)$res['id'];
        $conn->prepare("UPDATE tasks SET tenant_id = ?, project_id = ?, project_stage_id = ?" . self::clearPlanPosition($conn) . " WHERE id = ?")
             ->execute([$project['tenant_id'], $projectId, $stageId, $taskId]);
        if (array_filter($contractor)) TasksService::saveTask($conn, $ctx, ['id' => $taskId] + $contractor);
        self::touch($conn, $projectId);
        return $taskId;
    }

    // ======================================================================
    //  Loading and scope
    // ======================================================================

    /**
     * Loads one project; throws not found when it does not exist.
     *
     * @param int $id  the project to load
     */
    public static function loadRow(PDO $conn, int $id): array
    {
        $st = $conn->prepare("SELECT * FROM projects WHERE id = ?");
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new ServiceError('not_found', 'not_found', 'Project not found.');
        return $row;
    }

    /**
     * Load a project the caller may touch - out of scope reads as not found.
     *
     * @param int $id  the project to load
     */
    public static function loadForActor(PDO $conn, ActorContext $ctx, int $id): array
    {
        $row = self::loadRow($conn, $id);
        self::assertScope($conn, $ctx, $row, 'Project not found.');
        // Members-only (3.3.0): not found, never "hidden".
        require_once __DIR__ . '/../projects/visibility.php';
        if (!projectVisibleTo($conn, $ctx->actorId, $row)) throw new ServiceError('not_found', 'not_found', 'Project not found.');
        return $row;
    }

    /**
     * Loads one project stage; throws not found when it does not exist.
     *
     * @param int $id  the stage (project_stages.id) to load
     */
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
     *
     * @param array $project  the projects row, as loaded by loadForActor()
     */
    public static function assertCanChange(PDO $conn, ActorContext $ctx, array $project): void
    {
        if ($ctx->actorId <= 0) return;
        if (!projectCanChange($conn, $ctx->actorId, $project)) {
            throw new ServiceError('forbidden', 'forbidden', 'Only this project\'s team, or someone who manages Projects, can change it.');
        }
    }

    /**
     * Throws not found with $notFound when the row's company is outside the actor's companies.
     *
     * @param array $row  any row with a tenant_id column (a project or a task)
     * @param string $notFound  the not-found message to throw, e.g. 'Project not found.'
     */
    private static function assertScope(PDO $conn, ActorContext $ctx, array $row, string $notFound): void
    {
        if ($ctx->companyScope === null || !isMultiTenant($conn)) return;
        $tid = ($row['tenant_id'] === null) ? getDefaultTenantId($conn) : (int)$row['tenant_id'];
        if (!in_array($tid, $ctx->companyScope, true)) {
            throw new ServiceError('not_found', 'not_found', $notFound);
        }
    }

    /**
     * True when two company ids are the same company, treating NULL as the Default company.
     *
     * @param mixed $a  a tenant_id as stored (null = the Default company)
     * @param mixed $b  the other tenant_id as stored (null = the Default company)
     */
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

    /**
     * Validates and cleans one project field according to its type in fieldMap(), and
     * returns the value to store.
     *
     * @param string $field  the project column, a key of fieldMap()
     * @param mixed $v  the value as sent
     * @param array $def  that field's rule from fieldMap(): type, plus max, values or required
     */
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
            case 'money':
                // Stored as DECIMAL(18,2); the same two-decimal string the database hands back, so an unchanged value is not a change.
                if ($blank) return null;
                $s = str_replace([',', ' '], '', trim((string)$v));
                if (!preg_match('/^\d{1,15}(\.\d{1,2})?$/', $s)) throw new ServiceError('validation', 'invalid_field', 'Enter an amount like 12500 or 12500.50.');
                return sprintf('%.2f', (float)$s);
            case 'analyst':
                if ($blank || (int)$v <= 0) return null;
                $st = $conn->prepare("SELECT id FROM analysts WHERE id = ? AND is_active = 1");
                $st->execute([(int)$v]);
                if ($st->fetchColumn() === false) throw new ServiceError('validation', 'invalid_field', 'That analyst does not exist or is inactive.');
                return (int)$v;
        }
        return $v;
    }

    /**
     * Validates an optional YYYY-MM-DD date; blank gives null.
     *
     * @param mixed $v  the date as typed (YYYY-MM-DD); null or blank gives null, anything else invalid throws
     */
    private static function date($v): ?string
    {
        if ($v === null || trim((string)$v) === '') return null;
        $s = trim((string)$v);
        $d = DateTime::createFromFormat('!Y-m-d', $s);
        if (!$d || $d->format('Y-m-d') !== $s) throw new ServiceError('bad_request', 'invalid_field', "\"$s\" is not a date.");
        return $s;
    }

    /**
     * Throws when the end date is before the start date.
     *
     * @param ?string $start  the start date (YYYY-MM-DD), or null
     * @param ?string $end  the end date (YYYY-MM-DD), or null
     */
    private static function assertDates(?string $start, ?string $end): void
    {
        if ($start && $end && $end < $start) {
            throw new ServiceError('validation', 'invalid_field', 'The end date is before the start date.');
        }
    }

    /**
     * Trimmed text cut to $max characters, or null when blank.
     *
     * @param mixed $v  the text as sent; null or blank gives null
     * @param int $max  the most characters to keep
     */
    private static function str($v, int $max): ?string
    {
        if ($v === null) return null;
        $s = trim((string)$v);
        if ($s === '') return null;
        return mb_substr($s, 0, $max);
    }

    /**
     * True when two values are equal as strings; null only equals null.
     *
     * @param mixed $a  the stored value
     * @param mixed $b  the new value
     */
    private static function same($a, $b): bool
    {
        if ($a === null || $b === null) return $a === $b;
        return (string)$a === (string)$b;
    }

    /** The history source for the actor: 'api' or 'app'. */
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

    /**
     * After a change to one project: fire what its health and tolerances now say
     * (includes/projects/alerts.php). Runs as the person who made the change, so
     * the bell does not tell them about it. Never throws.
     */
    public static function afterChange(PDO $conn, int $projectId): void
    {
        try {
            require_once __DIR__ . '/../projects/alerts.php';
            projectAlertsScan($conn, $projectId);
        } catch (Throwable $e) {
            error_log('projects alerts: ' . $e->getMessage());
        }
    }

    /**
     * project.stage_closed - by its gate ($decision) or by hand (null).
     *
     * @param int $stageId  the stage (project_stages.id) that closed
     * @param ?string $decision  the gate decision that closed it ('go', 'go_with_conditions'), or null when closed by hand
     * @param ?string $next  the name of the stage the gate started, or null
     */
    public static function stageClosed(PDO $conn, int $projectId, int $stageId, ?string $decision, ?string $next): void
    {
        try {
            $p = self::eventFor($conn, $projectId);
            $s = self::loadStage($conn, $stageId);
            if (!$p) return;
            projectDispatch('project.stage_closed', [
                'project'       => $p,
                'stage'         => ['id' => (int)$s['id'], 'name' => $s['name'], 'kind' => $s['kind'], 'end_date' => $s['end_date'], 'status' => $s['status']],
                'gate_decision' => $decision,
                'next_stage'    => $next,
                // Linked changes still not approved when the stage closed - so a
                // workflow can say "closed with 2 unapproved changes".
                'unapproved_changes' => (function () use ($conn, $projectId) {
                    require_once __DIR__ . '/../projects/links.php';
                    return count(projectUnapprovedChanges($conn, $projectId));
                })(),
            ]);
        } catch (Throwable $e) {
            error_log('projects stage_closed: ' . $e->getMessage());
        }
    }

    /**
     * project.raid_escalated - a RAID entry needs somebody above the project manager (3.3.0).
     *
     * @param array $r  the project_raid row just escalated, including escalation_note
     */
    public static function raidEscalated(PDO $conn, int $projectId, array $r): void
    {
        try {
            $p = self::eventFor($conn, $projectId);
            if (!$p) return;
            require_once __DIR__ . '/../projects/alerts.php';
            projectDispatch('project.raid_escalated', [
                'project' => $p,
                'raid'    => ['id' => (int)$r['id'], 'type' => $r['type'], 'title' => $r['title'], 'due_date' => $r['due_date'],
                              'owner_analyst_id' => $r['owner_analyst_id'] !== null ? (int)$r['owner_analyst_id'] : null],
                'note'    => $r['escalation_note'],
            ]);
        } catch (Throwable $e) {
            error_log('projects raid_escalated: ' . $e->getMessage());
        }
    }

    /**
     * project.signoff_requested (3.3.0): somebody is named to sign off an item at a stage gate.
     *
     * @param int $itemId  the gate checklist item (project_gate_items.id) that needs signing; no event if nobody is named
     */
    public static function signoffEvent(PDO $conn, int $projectId, int $itemId): void
    {
        try {
            $p = self::eventFor($conn, $projectId);
            if (!$p) return;
            $st = $conn->prepare("SELECT i.id, i.title, i.analyst_id, s.id AS stage_id, s.name AS stage_name FROM project_gate_items i JOIN project_stages s ON s.id = i.stage_id WHERE i.id = ?");
            $st->execute([$itemId]);
            $i = $st->fetch(PDO::FETCH_ASSOC);
            if (!$i || !$i['analyst_id']) return;
            require_once __DIR__ . '/../projects/alerts.php';
            projectDispatch('project.signoff_requested', [
                'project' => $p,
                'item'    => ['id' => (int)$i['id'], 'title' => $i['title'], 'analyst_id' => (int)$i['analyst_id']],
                'stage'   => ['id' => (int)$i['stage_id'], 'name' => $i['stage_name']],
                'notify_ids' => [(int)$i['analyst_id']],
            ]);
        } catch (Throwable $e) {
            error_log('projects signoff_requested: ' . $e->getMessage());
        }
    }

    /**
     * Approve or reject a proposal (3.3.0, includes/projects/intake.php). Approving
     * records who and when and - when project_proposal_on_approve is 'active' -
     * starts the project; rejecting cancels it. Either way it is audited and the
     * proposer is told. Compare-and-set, so two approvers decide it once.
     *
     * @param string $decision  'approved' or 'rejected'
     * @param mixed $notes  the decision notes (cut to 20000 characters); required to reject
     */
    public static function decideProposal(PDO $conn, ActorContext $ctx, int $projectId, string $decision, $notes): array
    {
        require_once __DIR__ . '/../projects/intake.php';
        $p = self::loadForActor($conn, $ctx, $projectId);
        if (!in_array($decision, ['approved', 'rejected'], true)) throw new ServiceError('validation', 'invalid_field', 'Approve or reject.');
        if (($p['approval_status'] ?? null) !== 'pending') throw new ServiceError('validation', 'invalid_field', 'This project is not waiting for approval.');
        if (!projectCanDecideProposal($conn, $ctx->actorId)) throw new ServiceError('forbidden', 'forbidden', 'You may not approve or reject project proposals.');
        $notes = self::str($notes, 20000);
        if ($decision === 'rejected' && !$notes) throw new ServiceError('validation', 'missing_field', 'Say why it was rejected.');
        $start = $decision === 'approved' && projectSetting($conn, 'project_proposal_on_approve') === 'active';
        $newStatus = $decision === 'rejected' ? 'cancelled' : ($start ? 'active' : 'proposed');
        $u = $conn->prepare("UPDATE projects SET approval_status = ?, approval_by_id = ?, approval_datetime = UTC_TIMESTAMP(), approval_notes = ?, status = ?,
                                    closed_datetime = " . ($newStatus === 'cancelled' ? 'UTC_TIMESTAMP()' : 'closed_datetime') . ", updated_datetime = UTC_TIMESTAMP()
                              WHERE id = ? AND approval_status = 'pending'");
        $u->execute([$decision, $ctx->actorId > 0 ? $ctx->actorId : null, $notes, $newStatus, $projectId]);
        if ($u->rowCount() !== 1) throw new ServiceError('validation', 'invalid_field', 'This project is not waiting for approval.');
        self::audit($conn, $projectId, $ctx->actorId, $decision === 'approved' ? 'proposal_approved' : 'proposal_rejected', null, $notes, self::source($ctx));
        if ($newStatus !== 'proposed') self::audit($conn, $projectId, $ctx->actorId, 'status', 'proposed', $newStatus, self::source($ctx));
        if ($start) { require_once __DIR__ . '/../projects/control.php'; projectBaselineAuto($conn, $projectId, $ctx->actorId, 'start'); }
        self::syncCalendar($conn);
        self::proposalEvent($conn, $projectId, 'project.proposal_decided');
        self::afterChange($conn, $projectId);
        return ['status' => $newStatus];
    }

    /**
     * project.proposal_submitted (to the approvers) / project.proposal_decided
     * (to whoever proposed it, when that was an analyst) - 3.3.0. The payload
     * carries the proposer's email so a workflow can tell somebody who asked on
     * a form and has no bell.
     *
     * @param string $event  'project.proposal_submitted' or 'project.proposal_decided'
     */
    public static function proposalEvent(PDO $conn, int $projectId, string $event): void
    {
        try {
            $p = self::eventFor($conn, $projectId);
            if (!$p) return;
            require_once __DIR__ . '/../projects/intake.php';
            require_once __DIR__ . '/../projects/alerts.php';
            $st = $conn->prepare("SELECT p.estimated_cost, p.estimated_benefit, p.business_case, p.approval_status, p.approval_notes, p.approval_by_id,
                                         p.created_by_id, p.proposed_by_name, p.proposed_by_email, p.form_submission_id
                                    FROM projects p WHERE p.id = ?");
            $st->execute([$projectId]);
            $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
            projectDispatch($event, [
                'project'  => $p,
                'proposal' => [
                    'status'            => $r['approval_status'] ?? null,
                    'estimated_cost'    => isset($r['estimated_cost']) ? (float)$r['estimated_cost'] : null,
                    'estimated_benefit' => $r['estimated_benefit'] ?? null,
                    'business_case'     => $r['business_case'] ?? null,
                    'notes'             => $r['approval_notes'] ?? null,
                    'decided_by_id'     => isset($r['approval_by_id']) ? (int)$r['approval_by_id'] : null,
                    'proposed_by_name'  => $r['proposed_by_name'] ?? null,
                    'proposed_by_email' => $r['proposed_by_email'] ?? null,
                    'proposed_by_analyst_id' => isset($r['created_by_id']) ? (int)$r['created_by_id'] : null,
                    'submission_id'     => isset($r['form_submission_id']) ? (int)$r['form_submission_id'] : null,
                ],
                // Who the bell goes to (notifications_router.php reads this list).
                'notify_ids' => $event === 'project.proposal_submitted' ? projectProposalApprovers($conn)
                    : array_values(array_filter([isset($r['created_by_id']) ? (int)$r['created_by_id'] : 0])),
            ]);
        } catch (Throwable $e) {
            error_log('projects ' . $event . ': ' . $e->getMessage());
        }
    }

    /**
     * project.change_raised / project.change_decided (3.3.0) - a change request
     * was raised, or approved or rejected. includes/projects/control.php.
     *
     * @param string $event  'project.change_raised' or 'project.change_decided'
     * @param array $cr  the project_change_requests row
     */
    public static function changeEvent(PDO $conn, int $projectId, string $event, array $cr): void
    {
        try {
            $p = self::eventFor($conn, $projectId);
            if (!$p) return;
            require_once __DIR__ . '/../projects/alerts.php';
            projectDispatch($event, [
                'project' => $p,
                'change_request' => [
                    'id' => (int)$cr['id'], 'number' => (int)$cr['number'], 'reference' => 'CR-' . (int)$cr['number'], 'title' => $cr['title'],
                    'status' => $cr['status'], 'impact_days' => $cr['impact_days'] !== null ? (int)$cr['impact_days'] : null,
                    'impact_cost' => $cr['impact_cost'] !== null ? (float)$cr['impact_cost'] : null, 'impact_scope' => $cr['impact_scope'],
                    'raised_by_id' => $cr['raised_by_id'] !== null ? (int)$cr['raised_by_id'] : null,
                    'decided_by_id' => $cr['decided_by_id'] !== null ? (int)$cr['decided_by_id'] : null, 'decision_notes' => $cr['decision_notes'],
                ],
            ]);
        } catch (Throwable $e) {
            error_log('projects ' . $event . ': ' . $e->getMessage());
        }
    }

    /**
     * project.milestone_reached - somebody marked a milestone done (3.3.0).
     *
     * @param array $m  the project_milestones row, with done_date set
     */
    public static function milestoneReached(PDO $conn, int $projectId, array $m): void
    {
        try {
            $p = self::eventFor($conn, $projectId);
            if (!$p) return;
            require_once __DIR__ . '/../projects/alerts.php';
            projectDispatch('project.milestone_reached', [
                'project'   => $p,
                'milestone' => projectMilestoneEventFields($m),
                // Reached after its date counts as late, not missed: it happened.
                'late_days' => max(0, (int)floor((strtotime($m['done_date'] . ' 00:00:00 UTC') - strtotime($m['due_date'] . ' 00:00:00 UTC')) / 86400)),
            ]);
        } catch (Throwable $e) {
            error_log('projects milestone_reached: ' . $e->getMessage());
        }
    }

    /**
     * The project as its events carry it, with the health it shows; null if it is gone.
     *
     * @param int $id  the project
     */
    private static function eventFor(PDO $conn, int $id): ?array
    {
        try {
            require_once __DIR__ . '/../projects/alerts.php';
            $rows = projectAlertRows($conn, $id);
            return $rows ? projectEventPayload($rows[0]) : null;
        } catch (Throwable $e) {
            error_log('projects event payload: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Fires a project workflow event with the project's details.
     *
     * @param string $event  the workflow event name, e.g. 'project.created'
     * @param int $id  the project
     * @param array $extra  more payload keys added beside 'project', e.g. ['changed' => 'name,status']
     */
    private static function dispatch(PDO $conn, string $event, int $id, array $extra = []): void
    {
        $p = self::eventFor($conn, $id);
        if ($p) projectDispatch($event, ['project' => $p] + $extra);
    }

    /** Sets the project's updated time to now, for the other project services. */
    public static function touchProject(PDO $conn, int $projectId): void
    {
        self::touch($conn, $projectId);
    }

    /** Sets the project's updated time to now. */
    private static function touch(PDO $conn, int $projectId): void
    {
        $conn->prepare("UPDATE projects SET updated_datetime = UTC_TIMESTAMP() WHERE id = ?")->execute([$projectId]);
    }

    /**
     * Records one change in a project's history; a failure is logged, never thrown.
     *
     * @param string $field  what changed: a project column or an action key such as 'stage_added'
     * @param mixed $old  the value before (cut to 1000 characters), or null
     * @param mixed $new  the value after (cut to 1000 characters), or null
     * @param string $source  where it came from: 'app', 'api' or 'system'
     * @writes project_audit - the project's history
     */
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

    /**
     * What the history shows for a value: names, not ids; a line, not a paragraph.
     *
     * @param string $field  the project column the value belongs to
     * @param mixed $v  the stored value
     */
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
