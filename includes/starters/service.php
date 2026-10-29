<?php
/**
 * Starters & leavers - the service (4.6.0).
 *
 * Everything that has to happen when someone joins, moves or leaves: accounts,
 * kit, access, the jobs each team does, on the right days.
 *
 * 🔑 THIS MODULE IS BUILT FROM OTHER MODULES. It owns only what nothing else
 * holds - who, which kind, which date, which template - and drives the rest
 * through their own services, so every rule those modules enforce still applies
 * and nothing is written twice:
 *
 *   the work        TasksService::saveTask - one parent task for the case, one
 *                   subtask per template step, due relative to the case's date.
 *                   Progress is READ from those tasks, never stored here twice.
 *   checklists      ChecklistsService::attachTemplateToTask, per step
 *   kit             AssetsService::assignUser / unassignUser (custody, with its
 *                   history and asset.assigned / asset.unassigned events), and
 *                   AssetsService::updateFields for a status - Retired included
 *   leaving         userActiveSets() (includes/users.php), shared with the
 *                   person screen
 *   automation      WorkflowEngine::dispatch - jml.case_created and
 *                   jml.case_completed, so a workflow can email, raise a ticket
 *                   or post to chat without any code here
 *   the person      People shows a section from casesForPerson()
 *
 * The tables are in database/freeitsm.sql (jml_*). Pages and endpoints are thin:
 * they authorise and call this class.
 */

require_once __DIR__ . '/../service_context.php';
require_once __DIR__ . '/../tenancy.php';
require_once __DIR__ . '/../users.php';
require_once __DIR__ . '/../services/tasks.php';
require_once __DIR__ . '/../services/assets.php';
require_once __DIR__ . '/../services/checklists.php';
require_once dirname(__DIR__, 2) . '/workflow/includes/engine.php';

/** The three kinds of case. */
enum JmlKind: string
{
    case Starter = 'starter';
    case Mover   = 'mover';
    case Leaver  = 'leaver';
}

/** Where a case is: open while the work goes on; done; or cancelled. */
enum JmlStatus: string
{
    case Open      = 'open';
    case Done      = 'done';
    case Cancelled = 'cancelled';
}

final class StartersService
{
    /** Has Database Verification created the tables yet? */
    public static function ready(PDO $conn): bool
    {
        static $ok = null;
        if ($ok !== null) return $ok;
        try { $conn->query("SELECT 1 FROM jml_cases LIMIT 0"); $conn->query("SELECT 1 FROM jml_template_steps LIMIT 0"); return $ok = true; }
        catch (Throwable) { return $ok = false; }
    }

    // ======================================================================
    //  Templates - what happens for a kind of change
    // ======================================================================

    /**
     * The templates on offer, with their steps.
     *
     * @param ?string $kind  only this kind (starter | mover | leaver)
     * @param bool $activeOnly  leave out templates switched off
     */
    public static function templates(PDO $conn, ?string $kind = null, bool $activeOnly = false): array
    {
        $where = ['1=1']; $args = [];
        if ($kind !== null) { $where[] = 't.kind = ?'; $args[] = $kind; }
        if ($activeOnly) $where[] = 't.is_active = 1';
        $st = $conn->prepare("SELECT t.* FROM jml_templates t WHERE " . implode(' AND ', $where) . " ORDER BY t.kind, t.display_order, t.name");
        $st->execute($args);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $t) {
            $t['id'] = (int)$t['id'];
            $t['is_active'] = (bool)(int)$t['is_active'];
            $t['steps'] = self::steps($conn, $t['id']);
            $out[] = $t;
        }
        return $out;
    }

    /** A template's steps, in order, with the team, analyst and checklist names. */
    public static function steps(PDO $conn, int $templateId): array
    {
        $st = $conn->prepare(
            "SELECT s.*, tm.name AS team_name, a.full_name AS analyst_name, ct.title AS checklist_name
               FROM jml_template_steps s
          LEFT JOIN teams tm ON tm.id = s.assigned_team_id
          LEFT JOIN analysts a ON a.id = s.assigned_analyst_id
          LEFT JOIN checklist_templates ct ON ct.id = s.checklist_template_id
              WHERE s.template_id = ? ORDER BY s.display_order, s.id"
        );
        $st->execute([$templateId]);
        return array_map(function ($s) {
            foreach (['id', 'template_id', 'assigned_team_id', 'assigned_analyst_id', 'due_offset_days', 'checklist_template_id', 'display_order'] as $k) {
                $s[$k] = $s[$k] === null ? null : (int)$s[$k];
            }
            return $s;
        }, $st->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Create or update a template and replace its steps. Changing a template
     * never touches cases already made from it - their tasks are already set.
     *
     * @param array $in  id?, kind, name, description, is_active, steps:[{title,
     *                   description, assigned_team_id, assigned_analyst_id,
     *                   due_offset_days, checklist_template_id}]
     * @return int  the template's id
     */
    public static function saveTemplate(PDO $conn, ActorContext $ctx, array $in): int
    {
        $kind = JmlKind::tryFrom((string)($in['kind'] ?? ''));
        if (!$kind) throw new ServiceError('validation', 'invalid_field', 'Choose whether this template is for starters, movers or leavers.');
        $name = trim((string)($in['name'] ?? ''));
        if ($name === '') throw new ServiceError('validation', 'missing_field', 'Give the template a name.');
        $steps = is_array($in['steps'] ?? null) ? $in['steps'] : [];
        $clean = [];
        foreach ($steps as $i => $s) {
            $title = trim((string)($s['title'] ?? ''));
            if ($title === '') continue;
            $clean[] = [
                'title'   => mb_substr($title, 0, 255),
                'desc'    => trim((string)($s['description'] ?? '')) ?: null,
                'team'    => !empty($s['assigned_team_id']) ? (int)$s['assigned_team_id'] : null,
                'analyst' => !empty($s['assigned_analyst_id']) ? (int)$s['assigned_analyst_id'] : null,
                'offset'  => max(-365, min(365, (int)($s['due_offset_days'] ?? 0))),
                'list'    => !empty($s['checklist_template_id']) ? (int)$s['checklist_template_id'] : null,
            ];
        }

        $own = !$conn->inTransaction();
        if ($own) $conn->beginTransaction();
        try {
            $id = !empty($in['id']) ? (int)$in['id'] : 0;
            if ($id) {
                $conn->prepare("UPDATE jml_templates SET kind = ?, name = ?, description = ?, is_active = ? WHERE id = ?")
                     ->execute([$kind->value, mb_substr($name, 0, 150), trim((string)($in['description'] ?? '')) ?: null, !empty($in['is_active']) ? 1 : 0, $id]);
                $conn->prepare("DELETE FROM jml_template_steps WHERE template_id = ?")->execute([$id]);
            } else {
                $conn->prepare("INSERT INTO jml_templates (kind, name, description, is_active) VALUES (?, ?, ?, ?)")
                     ->execute([$kind->value, mb_substr($name, 0, 150), trim((string)($in['description'] ?? '')) ?: null, array_key_exists('is_active', $in) && empty($in['is_active']) ? 0 : 1]);
                $id = (int)$conn->lastInsertId();
            }
            $ins = $conn->prepare("INSERT INTO jml_template_steps (template_id, title, description, assigned_team_id, assigned_analyst_id, due_offset_days, checklist_template_id, display_order)
                                   VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            foreach ($clean as $i => $s) {
                $ins->execute([$id, $s['title'], $s['desc'], $s['team'], $s['analyst'], $s['offset'], $s['list'], $i]);
            }
            if ($own) $conn->commit();
            return $id;
        } catch (Throwable $e) {
            if ($own && $conn->inTransaction()) $conn->rollBack();
            throw $e;
        }
    }

    /** Delete a template. Cases made from it keep their tasks; they just forget the template. */
    public static function deleteTemplate(PDO $conn, int $id): void
    {
        $conn->prepare("DELETE FROM jml_templates WHERE id = ?")->execute([$id]);
    }

    // ======================================================================
    //  Cases
    // ======================================================================

    /**
     * Open a case: record who and when, then make the work - a parent task and
     * a subtask per template step - through TasksService, attach each step's
     * checklist through ChecklistsService, and tell Workflows.
     *
     * 🔑 NO OUTER TRANSACTION, deliberately. The task and checklist services fire
     * their own workflow events as they go; wrapping them would let a workflow
     * act on a task that is then rolled back. The case row is written first, so
     * a failure part-way leaves a case that says what it has, never tasks that
     * belong to nothing. A step that cannot be added is written to the case's
     * history and reported in $warnings; the other steps still go in, and the
     * case is still returned, so nobody opens it twice.
     *
     * @param array $in  kind, effective_date (Y-m-d), template_id?, notes?, owner_analyst_id?,
     *                   and the person: user_id, OR person_name (+ person_email, job_title) for
     *                   a starter not yet in Users; manager_user_id?
     * @param array $warnings  filled with one line per step that could not be added
     * @return int  the case's id
     */
    public static function createCase(PDO $conn, ActorContext $ctx, array $in, array &$warnings = []): int
    {
        $kind = JmlKind::tryFrom((string)($in['kind'] ?? ''));
        if (!$kind) throw new ServiceError('validation', 'invalid_field', 'Choose a starter, a mover or a leaver.');
        $date = (string)($in['effective_date'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !strtotime($date)) {
            throw new ServiceError('validation', 'invalid_field', 'Choose the date - the first day, the move, or the last day.');
        }

        // The person: someone already in Users (movers and leavers always are),
        // or a new starter's name until they are added.
        $userId = !empty($in['user_id']) ? (int)$in['user_id'] : null;
        $user = null;
        if ($userId) {
            if ($ctx->actorId > 0 && !analystCanAccessUser($conn, $ctx->actorId, $userId)) {
                throw new ServiceError('not_found', 'not_found', 'Person not found.');
            }
            $st = $conn->prepare("SELECT id, display_name, email, job_title, tenant_id, manager_id FROM users WHERE id = ?");
            $st->execute([$userId]);
            $user = $st->fetch(PDO::FETCH_ASSOC);
            if (!$user) throw new ServiceError('not_found', 'not_found', 'Person not found.');
        } elseif ($kind !== JmlKind::Starter) {
            throw new ServiceError('validation', 'missing_field', 'Choose the person - a mover or a leaver is someone already in Users.');
        }
        $name = $user ? trim((string)($user['display_name'] ?: $user['email'])) : trim((string)($in['person_name'] ?? ''));
        if ($name === '') throw new ServiceError('validation', 'missing_field', 'Give the starter\'s name.');

        // The company: the person's own, or the one the analyst is working in.
        $tenantId = $user ? ($user['tenant_id'] !== null ? (int)$user['tenant_id'] : null)
                          : ($ctx->actorId > 0 ? getActiveTenantId($conn, $ctx->actorId) : null);

        $template = null;
        if (!empty($in['template_id'])) {
            $st = $conn->prepare("SELECT * FROM jml_templates WHERE id = ? AND kind = ?");
            $st->execute([(int)$in['template_id'], $kind->value]);
            $template = $st->fetch(PDO::FETCH_ASSOC) ?: null;
            if (!$template) throw new ServiceError('validation', 'invalid_field', 'That template is not for this kind of case.');
        }

        $conn->prepare(
            "INSERT INTO jml_cases (tenant_id, kind, user_id, person_name, person_email, job_title, manager_user_id,
                                    effective_date, status, template_id, notes, owner_analyst_id, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'open', ?, ?, ?, ?)"
        )->execute([
            $tenantId, $kind->value, $userId, mb_substr($name, 0, 255),
            $user ? ($user['email'] ?: null) : (trim((string)($in['person_email'] ?? '')) ?: null),
            mb_substr(trim((string)($in['job_title'] ?? ($user['job_title'] ?? ''))), 0, 150) ?: null,
            !empty($in['manager_user_id']) ? (int)$in['manager_user_id'] : ($user && $user['manager_id'] ? (int)$user['manager_id'] : null),
            $date, $template ? (int)$template['id'] : null,
            trim((string)($in['notes'] ?? '')) ?: null,
            !empty($in['owner_analyst_id']) ? (int)$in['owner_analyst_id'] : ($ctx->actorId > 0 ? $ctx->actorId : null),
            $ctx->actorId > 0 ? $ctx->actorId : null,
        ]);
        $caseId = (int)$conn->lastInsertId();
        self::audit($conn, $caseId, $ctx, 'case_created', null, $kind->value . ' ' . $date);

        // The work, in Tasks. The parent is due on the case's date; each step is
        // due its offset from it (negative = before).
        $parent = TasksService::saveTask($conn, $ctx, [
            'title'       => self::taskTitle($kind, $name, $date),
            'description' => self::taskDescription($caseId),
            'due_date'    => $date,
            'tenant_id'   => $tenantId,
            'assigned_analyst_id' => !empty($in['owner_analyst_id']) ? (int)$in['owner_analyst_id'] : ($ctx->actorId > 0 ? $ctx->actorId : null),
        ]);
        $parentId = (int)$parent['id'];
        $conn->prepare("UPDATE jml_cases SET parent_task_id = ? WHERE id = ?")->execute([$parentId, $caseId]);

        if ($template) {
            foreach (self::steps($conn, (int)$template['id']) as $step) {
                try {
                    $due = (new DateTimeImmutable($date))->modify(sprintf('%+d days', $step['due_offset_days']))->format('Y-m-d');
                    $sub = TasksService::saveTask($conn, $ctx, array_filter([
                        'title'               => $step['title'],
                        'description'         => $step['description'],
                        'parent_task_id'      => $parentId,
                        'due_date'            => $due,
                        'assigned_team_id'    => $step['assigned_team_id'],
                        'assigned_analyst_id' => $step['assigned_analyst_id'],
                    ], fn($v) => $v !== null));
                    if ($step['checklist_template_id']) {
                        ChecklistsService::attachTemplateToTask($conn, $ctx, (int)$sub['id'], $step['checklist_template_id']);
                    }
                } catch (ServiceError $e) {
                    self::audit($conn, $caseId, $ctx, 'step_failed', $step['title'], $e->getMessage());
                    $warnings[] = 'The step "' . $step['title'] . '" could not be added: ' . $e->getMessage();
                }
            }
        }

        WorkflowEngine::dispatch('jml.case_created', ['jml_case' => self::eventPayload($conn, $caseId)]);
        return $caseId;
    }

    /** The task title a case's work is filed under - "Leaver: Jane Smith (31 Oct 2026)". */
    private static function taskTitle(JmlKind $kind, string $name, string $date): string
    {
        $label = ['starter' => 'Starter', 'mover' => 'Mover', 'leaver' => 'Leaver'][$kind->value];
        return mb_substr("$label: $name (" . date('j M Y', strtotime($date)) . ')', 0, 255);
    }

    /** The parent task's description - a way back to the case. */
    private static function taskDescription(int $caseId): string
    {
        require_once __DIR__ . '/../entity_links.php';
        $url = (defined('BASE_URL') ? BASE_URL : '') . entityLink('jml_case', $caseId);
        return '<p>This task holds the work for a Starters &amp; leavers case. Each step is a subtask.</p>'
             . '<p><a href="' . htmlspecialchars($url) . '">Open the case</a></p>';
    }

    /**
     * One case for its page: the case, the person, its steps (read from Tasks),
     * progress, and - for a mover or leaver - the kit they hold (from Assets).
     */
    public static function getCase(PDO $conn, ActorContext $ctx, int $id): array
    {
        $c = self::loadForActor($conn, $ctx, $id);
        $c['steps'] = self::caseSteps($conn, $c['parent_task_id'] ? (int)$c['parent_task_id'] : 0);
        $c['progress'] = self::progressOf($c['steps']);
        $c['person'] = null;
        $c['kit'] = [];
        if ($c['user_id']) {
            $st = $conn->prepare("SELECT id, display_name, email, job_title, department, is_active, is_managed, deactivated_datetime FROM users WHERE id = ?");
            $st->execute([(int)$c['user_id']]);
            if ($p = $st->fetch(PDO::FETCH_ASSOC)) {
                $p['is_active'] = (bool)(int)$p['is_active'];
                $p['is_managed'] = (bool)(int)($p['is_managed'] ?? 0);
                $c['person'] = $p;
            }
            // The kit, as Assets knows it - null when the analyst may not see Assets.
            if (analystCanAccessModule($conn, $ctx->actorId, 'assets')) {
                $held = AssetsService::assetsForUser($conn, $ctx, (int)$c['user_id']);
                $c['kit'] = $held['assets'] ?? [];
            }
        }
        $h = $conn->prepare("SELECT a.*, an.full_name AS analyst_name FROM jml_audit a LEFT JOIN analysts an ON an.id = a.analyst_id WHERE a.case_id = ? ORDER BY a.created_datetime DESC, a.id DESC");
        $h->execute([$id]);
        $c['history'] = $h->fetchAll(PDO::FETCH_ASSOC);
        return $c;
    }

    /** The case's steps: the subtasks of its parent task, as Tasks holds them. */
    private static function caseSteps(PDO $conn, int $parentTaskId): array
    {
        if ($parentTaskId <= 0) return [];
        $st = $conn->prepare(
            "SELECT t.id, t.title, t.due_date, t.completed_datetime, ts.name AS status_name, ts.colour AS status_colour,
                    COALESCE(ts.is_closed, 0) AS is_closed, a.full_name AS analyst_name, tm.name AS team_name
               FROM tasks t
          LEFT JOIN task_statuses ts ON ts.id = t.status_id
          LEFT JOIN analysts a ON a.id = t.assigned_analyst_id
          LEFT JOIN teams tm ON tm.id = t.assigned_team_id
              WHERE t.parent_task_id = ?
           ORDER BY t.due_date IS NULL, t.due_date, t.id"
        );
        $st->execute([$parentTaskId]);
        return array_map(function ($r) { $r['id'] = (int)$r['id']; $r['is_closed'] = (bool)(int)$r['is_closed']; return $r; }, $st->fetchAll(PDO::FETCH_ASSOC));
    }

    /** Done and total, from the steps. */
    private static function progressOf(array $steps): array
    {
        $done = count(array_filter($steps, fn($s) => $s['is_closed']));
        return ['done' => $done, 'total' => count($steps)];
    }

    /**
     * The register: cases this analyst may see, newest date first, with progress
     * counted from Tasks in the same query.
     *
     * @param array $f  kind?, status? (open | done | cancelled | '' = all)
     */
    public static function listCases(PDO $conn, int $analystId, array $f = []): array
    {
        [$tSql, $tArgs] = activeTenantFilter($conn, $analystId, 'c');
        $where = ['1=1']; $args = [];
        if (!empty($f['kind']) && JmlKind::tryFrom($f['kind'])) { $where[] = 'c.kind = ?'; $args[] = $f['kind']; }
        if (!empty($f['status']) && JmlStatus::tryFrom($f['status'])) { $where[] = 'c.status = ?'; $args[] = $f['status']; }
        $st = $conn->prepare(
            "SELECT c.id, c.kind, c.person_name, c.person_email, c.user_id, c.effective_date, c.status, c.tenant_id,
                    tn.name AS company_name, tp.name AS template_name, own.full_name AS owner_name,
                    (SELECT COUNT(*) FROM tasks s WHERE s.parent_task_id = c.parent_task_id) AS steps_total,
                    (SELECT COUNT(*) FROM tasks s JOIN task_statuses ss ON ss.id = s.status_id
                      WHERE s.parent_task_id = c.parent_task_id AND ss.is_closed = 1) AS steps_done
               FROM jml_cases c
          LEFT JOIN tenants tn ON tn.id = c.tenant_id
          LEFT JOIN jml_templates tp ON tp.id = c.template_id
          LEFT JOIN analysts own ON own.id = c.owner_analyst_id
              WHERE " . implode(' AND ', $where) . $tSql . "
           ORDER BY c.status = 'open' DESC, c.effective_date ASC, c.id DESC
              LIMIT 1000"
        );
        $st->execute(array_merge($args, $tArgs));
        return array_map(function ($r) {
            foreach (['id', 'user_id', 'tenant_id', 'steps_total', 'steps_done'] as $k) $r[$k] = $r[$k] === null ? null : (int)$r[$k];
            return $r;
        }, $st->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Close a case as done, cancel it, or reopen it. Done fires
     * jml.case_completed. Neither done nor cancelled touches the tasks: a step
     * still open is somebody's work, and closing it silently would hide that.
     */
    public static function setStatus(PDO $conn, ActorContext $ctx, int $id, string $status): void
    {
        $to = JmlStatus::tryFrom($status);
        if (!$to) throw new ServiceError('validation', 'invalid_field', 'Unknown status.');
        $c = self::loadForActor($conn, $ctx, $id);
        if ($c['status'] === $to->value) return;
        $conn->prepare("UPDATE jml_cases SET status = ?, completed_datetime = " . ($to === JmlStatus::Done ? 'UTC_TIMESTAMP()' : 'NULL') . " WHERE id = ?")
             ->execute([$to->value, $id]);
        self::audit($conn, $id, $ctx, 'status', $c['status'], $to->value);
        if ($to === JmlStatus::Done) {
            WorkflowEngine::dispatch('jml.case_completed', ['jml_case' => self::eventPayload($conn, $id)]);
        }
    }

    /**
     * A starter added to Users after the case was opened: link the case to them,
     * so their kit and their People page join up.
     */
    public static function linkUser(PDO $conn, ActorContext $ctx, int $id, int $userId): void
    {
        $c = self::loadForActor($conn, $ctx, $id);
        if ($ctx->actorId > 0 && !analystCanAccessUser($conn, $ctx->actorId, $userId)) {
            throw new ServiceError('not_found', 'not_found', 'Person not found.');
        }
        $conn->prepare("UPDATE jml_cases SET user_id = ? WHERE id = ?")->execute([$userId, $id]);
        self::audit($conn, $id, $ctx, 'user_id', $c['user_id'], (string)$userId);
    }

    /**
     * Hand a case to another owner - the analyst-leaving tool's reassignment
     * (includes/analyst_lifecycle.php) moves open cases through here.
     *
     * @param ?int $toId  the new owner; null for nobody
     */
    public static function setOwner(PDO $conn, ActorContext $ctx, int $id, ?int $toId): void
    {
        $c = self::loadForActor($conn, $ctx, $id);
        if ($c['owner_analyst_id'] === $toId) return;
        $conn->prepare("UPDATE jml_cases SET owner_analyst_id = ? WHERE id = ?")->execute([$toId, $id]);
        self::audit($conn, $id, $ctx, 'owner_analyst_id', $c['owner_analyst_id'] !== null ? (string)$c['owner_analyst_id'] : null, $toId !== null ? (string)$toId : null);
    }

    // ======================================================================
    //  Kit and the account - through Assets and Users
    // ======================================================================

    /**
     * Give a starter (or mover) a piece of kit: Assets custody, with its history,
     * its event and its handover document - nothing re-implemented here.
     */
    public static function issueKit(PDO $conn, ActorContext $ctx, int $caseId, int $assetId): void
    {
        $c = self::loadForActor($conn, $ctx, $caseId);
        if (!$c['user_id']) throw new ServiceError('validation', 'invalid_field', 'Link this case to the person in Users first.');
        self::assertAsset($conn, $ctx, $assetId);
        AssetsService::assignUser($conn, $ctx, $assetId, ['user_id' => (int)$c['user_id'], 'notes' => 'Starters & leavers case #' . $caseId]);
        self::audit($conn, $caseId, $ctx, 'kit_issued', null, (string)$assetId);
    }

    /**
     * Take back a piece of kit from a leaver (or mover): Assets custody, then -
     * if a status is chosen - the asset's status through AssetsService, so a
     * status ticked Retired retires it, with the date, as anywhere else.
     *
     * @param ?int $statusId  the asset status to set after it is back (e.g. In stock, Retired)
     */
    public static function recoverKit(PDO $conn, ActorContext $ctx, int $caseId, int $assetId, ?int $statusId = null): void
    {
        $c = self::loadForActor($conn, $ctx, $caseId);
        if (!$c['user_id']) throw new ServiceError('validation', 'invalid_field', 'This case is not linked to a person.');
        self::assertAsset($conn, $ctx, $assetId);
        AssetsService::unassignUser($conn, $ctx, $assetId, (int)$c['user_id']);
        if ($statusId) {
            AssetsService::updateFields($conn, $ctx, $assetId, ['asset_status_id' => $statusId]);
        }
        self::audit($conn, $caseId, $ctx, 'kit_recovered', (string)$assetId, $statusId ? 'status ' . $statusId : null);
    }

    /**
     * Mark a leaver as left in Users - the same change the person screen makes
     * (userActiveSets). Refused for a person kept up to date from a directory:
     * the next sync would switch them back on, so it must be done there.
     */
    public static function markLeft(PDO $conn, ActorContext $ctx, int $caseId): void
    {
        $c = self::loadForActor($conn, $ctx, $caseId);
        if ($c['kind'] !== JmlKind::Leaver->value || !$c['user_id']) {
            throw new ServiceError('validation', 'invalid_field', 'Only a leaver linked to a person can be marked as left.');
        }
        $st = $conn->prepare("SELECT is_active, is_managed FROM users WHERE id = ?");
        $st->execute([(int)$c['user_id']]);
        $u = $st->fetch(PDO::FETCH_ASSOC);
        if (!$u) throw new ServiceError('not_found', 'not_found', 'Person not found.');
        if (!empty($u['is_managed'])) {
            throw new ServiceError('validation', 'invalid_field', 'This person is kept up to date from a directory. Disable the account there; the next sync marks them as left here.');
        }
        if (!(int)$u['is_active']) return;
        $conn->prepare("UPDATE users SET " . implode(', ', userActiveSets(false)) . " WHERE id = ?")->execute([(int)$c['user_id']]);
        self::audit($conn, $caseId, $ctx, 'person_left', null, (string)$c['user_id']);
    }

    /** The asset exists and the analyst may work on it (the custody service does not check scope itself). */
    private static function assertAsset(PDO $conn, ActorContext $ctx, int $assetId): void
    {
        if ($ctx->actorId > 0 && !analystCanAccessAsset($conn, $ctx->actorId, $assetId)) {
            throw new ServiceError('not_found', 'not_found', 'Asset not found.');
        }
    }

    // ======================================================================
    //  For other modules
    // ======================================================================

    /**
     * A person's cases, newest first - the section on their People page.
     *
     * @param int $analystId  the reader - only cases in their company scope
     * @return list<array{id:int,kind:string,effective_date:string,status:string,template:?string,steps_done:int,steps_total:int,url:string}>
     */
    public static function casesForPerson(PDO $conn, int $analystId, int $userId): array
    {
        if (!self::ready($conn)) return [];
        require_once __DIR__ . '/../entity_links.php';
        [$tSql, $tArgs] = activeTenantFilter($conn, $analystId, 'c');
        $st = $conn->prepare(
            "SELECT c.id, c.kind, c.effective_date, c.status, tp.name AS template,
                    (SELECT COUNT(*) FROM tasks s WHERE s.parent_task_id = c.parent_task_id) AS steps_total,
                    (SELECT COUNT(*) FROM tasks s JOIN task_statuses ss ON ss.id = s.status_id
                      WHERE s.parent_task_id = c.parent_task_id AND ss.is_closed = 1) AS steps_done
               FROM jml_cases c
          LEFT JOIN jml_templates tp ON tp.id = c.template_id
              WHERE c.user_id = ?" . $tSql . "
           ORDER BY c.effective_date DESC, c.id DESC LIMIT 50"
        );
        $st->execute(array_merge([$userId], $tArgs));
        return array_map(fn($r) => ['id' => (int)$r['id'], 'kind' => $r['kind'], 'effective_date' => $r['effective_date'],
                                     'status' => $r['status'], 'template' => $r['template'],
                                     'steps_done' => (int)$r['steps_done'], 'steps_total' => (int)$r['steps_total'],
                                     'url' => entityLink('jml_case', (int)$r['id'])],
                         $st->fetchAll(PDO::FETCH_ASSOC));
    }

    /** What a workflow sees about a case (jml.case_created / jml.case_completed). */
    public static function eventPayload(PDO $conn, int $id): array
    {
        $st = $conn->prepare("SELECT c.*, tp.name AS template_name FROM jml_cases c LEFT JOIN jml_templates tp ON tp.id = c.template_id WHERE c.id = ?");
        $st->execute([$id]);
        $c = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        return [
            'id'             => (int)($c['id'] ?? $id),
            'kind'           => $c['kind'] ?? null,
            'person_name'    => $c['person_name'] ?? null,
            'person_email'   => $c['person_email'] ?? null,
            'user_id'        => isset($c['user_id']) ? (int)$c['user_id'] : null,
            'effective_date' => $c['effective_date'] ?? null,
            'status'         => $c['status'] ?? null,
            'template_name'  => $c['template_name'] ?? null,
            'parent_task_id' => isset($c['parent_task_id']) ? (int)$c['parent_task_id'] : null,
            'tenant_id'      => isset($c['tenant_id']) ? (int)$c['tenant_id'] : null,
        ];
    }

    // ======================================================================
    //  Internals
    // ======================================================================

    /** The case row, if this analyst's company scope reaches it; 404 otherwise. */
    public static function loadForActor(PDO $conn, ActorContext $ctx, int $id): array
    {
        $st = $conn->prepare("SELECT c.*, tp.name AS template_name, tn.name AS company_name, own.full_name AS owner_name
                                FROM jml_cases c
                           LEFT JOIN jml_templates tp ON tp.id = c.template_id
                           LEFT JOIN tenants tn ON tn.id = c.tenant_id
                           LEFT JOIN analysts own ON own.id = c.owner_analyst_id
                               WHERE c.id = ?");
        $st->execute([$id]);
        $c = $st->fetch(PDO::FETCH_ASSOC);
        if (!$c) throw new ServiceError('not_found', 'not_found', 'Case not found.');
        if ($ctx->actorId > 0) {
            [$tSql, $tArgs] = activeTenantFilter($conn, $ctx->actorId, 'c');
            if ($tSql !== '') {
                $chk = $conn->prepare("SELECT 1 FROM jml_cases c WHERE c.id = ?" . $tSql);
                $chk->execute(array_merge([$id], $tArgs));
                if (!$chk->fetchColumn()) throw new ServiceError('not_found', 'not_found', 'Case not found.');
            }
        }
        foreach (['id', 'tenant_id', 'user_id', 'manager_user_id', 'template_id', 'parent_task_id', 'owner_analyst_id', 'created_by'] as $k) {
            $c[$k] = $c[$k] === null ? null : (int)$c[$k];
        }
        return $c;
    }

    /** One line of a case's history. */
    private static function audit(PDO $conn, int $caseId, ActorContext $ctx, string $field, ?string $old, ?string $new): void
    {
        $conn->prepare("INSERT INTO jml_audit (case_id, analyst_id, field_name, old_value, new_value) VALUES (?, ?, ?, ?, ?)")
             ->execute([$caseId, $ctx->actorId > 0 ? $ctx->actorId : null, $field,
                        $old !== null ? mb_substr($old, 0, 1000) : null, $new !== null ? mb_substr($new, 0, 1000) : null]);
    }
}
