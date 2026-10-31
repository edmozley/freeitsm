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
require_once __DIR__ . '/../i18n.php';
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
        $st = $conn->prepare("SELECT t.*, f.title AS form_title FROM jml_templates t LEFT JOIN forms f ON f.id = t.form_id
                               WHERE " . implode(' AND ', $where) . " ORDER BY t.kind, t.display_order, t.name");
        $st->execute($args);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $t) {
            $t['id'] = (int)$t['id'];
            $t['is_active'] = (bool)(int)$t['is_active'];
            $t['form_id'] = $t['form_id'] !== null ? (int)$t['form_id'] : null;
            $t['match_rule'] = $t['match_rule'] ? json_decode($t['match_rule'], true) : null;
            $t['steps'] = self::steps($conn, $t['id']);
            $out[] = $t;
        }
        return $out;
    }

    /** A template's steps, in order, with the team, analyst and checklist names and the "only if" rule decoded. */
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
            $s['condition_rule'] = !empty($s['condition_rule']) ? json_decode($s['condition_rule'], true) : null;
            return $s;
        }, $st->fetchAll(PDO::FETCH_ASSOC));
    }

    /** The operators a rule may use - the Forms show/hide ones, plus "any of". */
    const RULE_OPS = ['equals', 'not_equals', 'any_of', 'contains', 'is_not_empty', 'is_empty'];

    /**
     * A rule as stored: {match, rules:[{question, op, value}]} with blank rows
     * dropped, or null for "always". Questions are labels (see rawAnswers()).
     *
     * @param mixed $rule  as sent by the editor: array or JSON
     */
    public static function cleanRule($rule): ?string
    {
        if (is_string($rule)) $rule = json_decode($rule, true);
        if (!is_array($rule)) return null;
        $rows = [];
        foreach ((array)($rule['rules'] ?? []) as $r) {
            $q = trim((string)($r['question'] ?? ''));
            if ($q === '') continue;
            $op = in_array($r['op'] ?? '', self::RULE_OPS, true) ? $r['op'] : 'equals';
            $rows[] = ['question' => mb_substr($q, 0, 255), 'op' => $op, 'value' => mb_substr(trim((string)($r['value'] ?? '')), 0, 500)];
        }
        if (!$rows) return null;
        return json_encode(['match' => ($rule['match'] ?? '') === 'any' ? 'any' : 'all', 'rules' => $rows]);
    }

    /**
     * Create or update a template and replace its steps. Changing a template
     * never touches cases already made from it - their tasks are already set.
     *
     * @param array $in  id?, kind, name, description, is_active, form_id?, match_rule?,
     *                   steps:[{title, description, assigned_team_id, assigned_analyst_id,
     *                   due_offset_days, checklist_template_id, condition_rule?}]
     * @return int  the template's id
     */
    public static function saveTemplate(PDO $conn, ActorContext $ctx, array $in): int
    {
        $kind = JmlKind::tryFrom((string)($in['kind'] ?? ''));
        if (!$kind) throw new ServiceError('validation', 'invalid_field', 'Choose whether this template is for starters, movers or leavers.');
        $name = trim((string)($in['name'] ?? ''));
        if ($name === '') throw new ServiceError('validation', 'missing_field', 'Give the template a name.');
        $formId = !empty($in['form_id']) ? (int)$in['form_id'] : null;
        if ($formId) {
            $f = $conn->prepare("SELECT 1 FROM forms WHERE id = ?");
            $f->execute([$formId]);
            if (!$f->fetchColumn()) throw new ServiceError('validation', 'invalid_field', 'That form no longer exists.');
        }
        // Rules and "only if" need answers to test - without a form they are dropped.
        $match = $formId ? self::cleanRule($in['match_rule'] ?? null) : null;
        $steps = is_array($in['steps'] ?? null) ? $in['steps'] : [];
        $clean = [];
        foreach ($steps as $s) {
            $title = trim((string)($s['title'] ?? ''));
            if ($title === '') continue;
            $clean[] = [
                'title'   => mb_substr($title, 0, 255),
                'desc'    => trim((string)($s['description'] ?? '')) ?: null,
                'team'    => !empty($s['assigned_team_id']) ? (int)$s['assigned_team_id'] : null,
                'analyst' => !empty($s['assigned_analyst_id']) ? (int)$s['assigned_analyst_id'] : null,
                'offset'  => max(-365, min(365, (int)($s['due_offset_days'] ?? 0))),
                'list'    => !empty($s['checklist_template_id']) ? (int)$s['checklist_template_id'] : null,
                'rule'    => $formId ? self::cleanRule($s['condition_rule'] ?? null) : null,
            ];
        }

        $own = !$conn->inTransaction();
        if ($own) $conn->beginTransaction();
        try {
            $id = !empty($in['id']) ? (int)$in['id'] : 0;
            $desc = trim((string)($in['description'] ?? '')) ?: null;
            if ($id) {
                $conn->prepare("UPDATE jml_templates SET kind = ?, name = ?, description = ?, is_active = ?, form_id = ?, match_rule = ? WHERE id = ?")
                     ->execute([$kind->value, mb_substr($name, 0, 150), $desc, !empty($in['is_active']) ? 1 : 0, $formId, $match, $id]);
                $conn->prepare("DELETE FROM jml_template_steps WHERE template_id = ?")->execute([$id]);
            } else {
                $conn->prepare("INSERT INTO jml_templates (kind, name, description, is_active, form_id, match_rule) VALUES (?, ?, ?, ?, ?, ?)")
                     ->execute([$kind->value, mb_substr($name, 0, 150), $desc, array_key_exists('is_active', $in) && empty($in['is_active']) ? 0 : 1, $formId, $match]);
                $id = (int)$conn->lastInsertId();
            }
            $ins = $conn->prepare("INSERT INTO jml_template_steps (template_id, title, description, assigned_team_id, assigned_analyst_id, due_offset_days, checklist_template_id, condition_rule, display_order)
                                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            foreach ($clean as $i => $s) {
                $ins->execute([$id, $s['title'], $s['desc'], $s['team'], $s['analyst'], $s['offset'], $s['list'], $s['rule'], $i]);
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
        } elseif ($kind !== JmlKind::Starter && empty($in['_from_form'])) {
            // From a form the person may not be found by their email yet: the
            // case opens with the name typed, and Link joins it up later.
            throw new ServiceError('validation', 'missing_field', 'Choose the person - a mover or a leaver is someone already in Users.');
        }
        $name = $user ? trim((string)($user['display_name'] ?: $user['email'])) : trim((string)($in['person_name'] ?? ''));
        if ($name === '') throw new ServiceError('validation', 'missing_field', 'Give the person\'s name.');

        // The company: the person's own, the one the analyst is working in, or -
        // from a form - the requester's (passed in by openFromSubmission()).
        $tenantId = $user ? ($user['tenant_id'] !== null ? (int)$user['tenant_id'] : null)
                          : ($ctx->actorId > 0 ? getActiveTenantId($conn, $ctx->actorId)
                                               : (isset($in['_tenant_id']) ? (int)$in['_tenant_id'] : null));
        $submissionId = !empty($in['form_submission_id']) ? (int)$in['form_submission_id'] : null;

        $template = null;
        if (!empty($in['template_id'])) {
            $st = $conn->prepare("SELECT * FROM jml_templates WHERE id = ? AND kind = ?");
            $st->execute([(int)$in['template_id'], $kind->value]);
            $template = $st->fetch(PDO::FETCH_ASSOC) ?: null;
            if (!$template) throw new ServiceError('validation', 'invalid_field', 'That template is not for this kind of case.');
        }

        $conn->prepare(
            "INSERT INTO jml_cases (tenant_id, kind, user_id, person_name, person_email, job_title, manager_user_id,
                                    effective_date, status, template_id, notes, owner_analyst_id, created_by" . ($submissionId ? ', form_submission_id' : '') . ")
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'open', ?, ?, ?, ?" . ($submissionId ? ', ?' : '') . ")"
        )->execute(array_merge([
            $tenantId, $kind->value, $userId, mb_substr($name, 0, 255),
            $user ? ($user['email'] ?: null) : (trim((string)($in['person_email'] ?? '')) ?: null),
            mb_substr(trim((string)($in['job_title'] ?? ($user['job_title'] ?? ''))), 0, 150) ?: null,
            !empty($in['manager_user_id']) ? (int)$in['manager_user_id'] : ($user && $user['manager_id'] ? (int)$user['manager_id'] : null),
            $date, $template ? (int)$template['id'] : null,
            trim((string)($in['notes'] ?? '')) ?: null,
            !empty($in['owner_analyst_id']) ? (int)$in['owner_analyst_id'] : ($ctx->actorId > 0 ? $ctx->actorId : null),
            $ctx->actorId > 0 ? $ctx->actorId : null,
        ], $submissionId ? [$submissionId] : []));
        $caseId = (int)$conn->lastInsertId();
        self::audit($conn, $caseId, $ctx, 'case_created', null, $kind->value . ' ' . $date);

        // The work, in Tasks. The parent is due on the case's date; each step is
        // due its offset from it (negative = before).
        $parent = TasksService::saveTask($conn, $ctx, [
            'title'       => self::taskTitle($kind, $name, $date, $loc = self::writeLocale($conn, $ctx, !empty($in['owner_analyst_id']) ? (int)$in['owner_analyst_id'] : null)),
            'description' => self::taskDescription($caseId, [], $loc),
            'due_date'    => $date,
            'tenant_id'   => $tenantId,
            'assigned_analyst_id' => !empty($in['owner_analyst_id']) ? (int)$in['owner_analyst_id'] : ($ctx->actorId > 0 ? $ctx->actorId : null),
        ]);
        $parentId = (int)$parent['id'];
        $conn->prepare("UPDATE jml_cases SET parent_task_id = ? WHERE id = ?")->execute([$parentId, $caseId]);

        if ($template) self::applyTemplate($conn, $ctx, $caseId, $warnings);

        WorkflowEngine::dispatch('jml.case_created', ['jml_case' => self::eventPayload($conn, $caseId)]);
        return $caseId;
    }

    /**
     * Add the case's template steps as subtasks - or, when the template asks
     * questions that have not been answered yet, put its form on the case's task
     * and wait (steps_pending) until they are; taskFormSubmitted() comes back here.
     *
     * Each step: skipped when its "only if" is not met by the answers; its title
     * and description have {Question} filled from them; due its offset from the
     * date; its checklist attached. The parent task's description gains the
     * answers, so whoever works in Tasks has them to hand.
     *
     * @param array $warnings  filled with one line per step that could not be added
     */
    public static function applyTemplate(PDO $conn, ActorContext $ctx, int $caseId, array &$warnings = []): void
    {
        $c = self::loadForActor($conn, $ctx, $caseId);
        if (!$c['template_id'] || !$c['parent_task_id']) return;
        $st = $conn->prepare("SELECT * FROM jml_templates WHERE id = ?");
        $st->execute([$c['template_id']]);
        $template = $st->fetch(PDO::FETCH_ASSOC);
        if (!$template) return;

        $submissionId = $c['form_submission_id'] ? (int)$c['form_submission_id'] : null;
        if (!empty($template['form_id']) && !$submissionId) {
            // The questions come first. Forms on tasks (Tasks -> Settings) lets the
            // analyst answer them on the case's own task; without it the case page
            // says how to switch it on.
            require_once __DIR__ . '/../services/task_forms.php';
            $conn->prepare("UPDATE jml_cases SET steps_pending = 1 WHERE id = ?")->execute([$caseId]);
            if (TaskForms::enabled($conn) && TaskForms::columnsReady($conn)) {
                try {
                    TaskForms::add($conn, $ctx, (int)$c['parent_task_id'], (int)$template['form_id'], true, 'block');
                } catch (ServiceError $e) {
                    if ($e->kind !== 'conflict') $warnings[] = 'The questions could not be put on the task: ' . $e->getMessage();
                }
            }
            self::audit($conn, $caseId, $ctx, 'steps_pending', null, null);
            return;
        }

        $answers = $submissionId ? self::answers($conn, $submissionId) : [];
        $raw = $submissionId ? self::rawAnswers($conn, $submissionId) : [];
        $fill = fn(?string $text) => $text === null ? null : self::fillAnswers($text, $answers, $c);
        $added = 0; $skipped = 0;
        foreach (self::steps($conn, (int)$template['id']) as $step) {
            if (!self::ruleMet($step['condition_rule'], $raw)) { $skipped++; continue; }
            try {
                $due = (new DateTimeImmutable($c['effective_date']))->modify(sprintf('%+d days', $step['due_offset_days']))->format('Y-m-d');
                $sub = TasksService::saveTask($conn, $ctx, array_filter([
                    'title'               => mb_substr((string)$fill($step['title']), 0, 255),
                    'description'         => $fill($step['description']),
                    'parent_task_id'      => (int)$c['parent_task_id'],
                    'due_date'            => $due,
                    'assigned_team_id'    => $step['assigned_team_id'],
                    'assigned_analyst_id' => $step['assigned_analyst_id'],
                ], fn($v) => $v !== null));
                if ($step['checklist_template_id']) {
                    ChecklistsService::attachTemplateToTask($conn, $ctx, (int)$sub['id'], $step['checklist_template_id']);
                }
                $added++;
            } catch (ServiceError $e) {
                self::audit($conn, $caseId, $ctx, 'step_failed', $step['title'], $e->getMessage());
                $warnings[] = I18n::tFor($ctx->locale, 'starters.written.step_failed', ['step' => $step['title'], 'error' => $e->getMessage()]);
            }
        }
        $conn->prepare("UPDATE jml_cases SET steps_pending = 0 WHERE id = ?")->execute([$caseId]);
        if ($answers) {
            TasksService::saveTask($conn, $ctx, ['id' => (int)$c['parent_task_id'], 'description' => self::taskDescription($caseId, $answers, self::writeLocale($conn, $ctx, $c['owner_analyst_id'] ? (int)$c['owner_analyst_id'] : null))]);
        }
        self::audit($conn, $caseId, $ctx, 'steps_added', $skipped ? (string)$skipped : null, (string)$added);
    }

    /**
     * Use a template on a case that has none yet (opened without one, or from a
     * form no template matched) - then add its steps.
     */
    public static function useTemplate(PDO $conn, ActorContext $ctx, int $caseId, int $templateId, array &$warnings = []): void
    {
        $c = self::loadForActor($conn, $ctx, $caseId);
        if ($c['template_id']) throw new ServiceError('conflict', 'conflict', 'This case already has a template.');
        $st = $conn->prepare("SELECT id FROM jml_templates WHERE id = ? AND kind = ?");
        $st->execute([$templateId, $c['kind']]);
        if (!$st->fetchColumn()) throw new ServiceError('validation', 'invalid_field', 'That template is not for this kind of case.');
        $conn->prepare("UPDATE jml_cases SET template_id = ? WHERE id = ?")->execute([$templateId, $caseId]);
        self::audit($conn, $caseId, $ctx, 'template_id', null, (string)$templateId);
        self::applyTemplate($conn, $ctx, $caseId, $warnings);
    }

    // ======================================================================
    //  Answers - from Forms
    // ======================================================================

    /** A submission's answers as label + readable text, in the form's order (the Forms module's own reader). */
    public static function answers(PDO $conn, int $submissionId): array
    {
        require_once __DIR__ . '/../catalogue_approvals.php';
        return catalogueSubmissionAnswers($conn, $submissionId);
    }

    /**
     * The stored answers keyed by lower-cased question label, with each field's
     * type and lookup source - what rules are tested against. By LABEL, not field
     * id: every form version has new field ids, and a rule written against
     * "DMS access?" must still work after somebody edits the form.
     *
     * @return array<string,array{raw:string,type:string,config:mixed}>
     */
    public static function rawAnswers(PDO $conn, int $submissionId): array
    {
        $st = $conn->prepare("SELECT ff.label, ff.field_type, ff.config, d.field_value
                                FROM form_submission_data d JOIN form_fields ff ON ff.id = d.field_id
                               WHERE d.submission_id = ? ORDER BY ff.sort_order, ff.id");
        $st->execute([$submissionId]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $k = mb_strtolower(trim((string)$r['label']));
            if (!isset($out[$k])) $out[$k] = ['raw' => (string)$r['field_value'], 'type' => (string)$r['field_type'], 'config' => $r['config']];
        }
        return $out;
    }

    /**
     * Is a rule met by the answers? NULL / no rules = always. Uses the Forms
     * module's own operator code (formLogicTestRule), so "is", "is not",
     * "contains", "is filled in" mean exactly what they mean on a form; "any of"
     * is "is" for each comma-separated value.
     *
     * @param mixed $rule  {match: all|any, rules: [{question, op, value}]} as array or JSON
     * @param array $raw  rawAnswers()
     */
    public static function ruleMet($rule, array $raw): bool
    {
        if (is_string($rule)) $rule = json_decode($rule, true);
        if (!is_array($rule) || empty($rule['rules'])) return true;
        require_once __DIR__ . '/../form_logic.php';
        $results = [];
        foreach ($rule['rules'] as $r) {
            $answer = $raw[mb_strtolower(trim((string)($r['question'] ?? '')))]['raw'] ?? '';
            $op = (string)($r['op'] ?? 'equals');
            if ($op === 'any_of') {
                $hit = false;
                foreach (array_map('trim', explode(',', (string)($r['value'] ?? ''))) as $v) {
                    if ($v !== '' && formLogicTestRule(['field' => 1, 'op' => 'equals', 'value' => $v], [1 => $answer])) { $hit = true; break; }
                }
                $results[] = $hit;
            } else {
                $results[] = formLogicTestRule(['field' => 1, 'op' => $op, 'value' => (string)($r['value'] ?? '')], [1 => $answer]);
            }
        }
        return ($rule['match'] ?? 'all') === 'any' ? in_array(true, $results, true) : !in_array(false, $results, true);
    }

    /**
     * Fill {Question} in a step's words from the answers (case-insensitive), then
     * {name}, {email}, {job title} and {date} from the case. An unknown name is
     * left as written, so a typo shows rather than vanishing.
     */
    public static function fillAnswers(string $text, array $answers, array $case): string
    {
        if (!str_contains($text, '{')) return $text;
        $map = [];
        foreach ($answers as $a) $map[mb_strtolower(trim((string)$a['label']))] = (string)$a['value'];
        $map += ['name' => (string)$case['person_name'], 'email' => (string)($case['person_email'] ?? ''),
                 'job title' => (string)($case['job_title'] ?? ''), 'date' => date('j M Y', strtotime((string)$case['effective_date']))];
        return preg_replace_callback('/\{([^{}]{1,120})\}/u', function ($m) use ($map) {
            $k = mb_strtolower(trim($m[1]));
            return array_key_exists($k, $map) ? $map[$k] : $m[0];
        }, $text);
    }

    /**
     * The forms a template can ask - the newest version of each active form -
     * or none while the Forms module is switched off.
     *
     * @return list<array{id:int,name:string}>
     */
    public static function formChoices(PDO $conn): array
    {
        if (!moduleIsEnabled($conn, 'forms')) return [];
        require_once __DIR__ . '/../services/forms.php';
        $out = [];
        foreach (FormsService::formsForPicker($conn) as $f) {
            if ((int)$f['is_active'] === 1) $out[] = ['id' => (int)$f['id'], 'name' => (string)$f['title']];
        }
        return $out;
    }

    /** The first form in a chain of versions - what "the same form" means across versions. */
    public static function formRoot(PDO $conn, int $formId): int
    {
        $q = $conn->prepare("SELECT parent_form_id FROM forms WHERE id = ?");
        for ($hops = 0; $hops < 500; $hops++) {
            $q->execute([$formId]);
            $p = $q->fetchColumn();
            if (!$p) break;
            $formId = (int)$p;
        }
        return $formId;
    }

    /**
     * Which template a form's answers ask for: the active templates of this kind
     * that use this form (any version), in order - the first whose rule the
     * answers meet; failing that, the first with no rule. Null when none fits.
     */
    public static function chooseTemplate(PDO $conn, string $kind, int $submissionId): ?int
    {
        $st = $conn->prepare("SELECT form_id FROM form_submissions WHERE id = ?");
        $st->execute([$submissionId]);
        $formId = (int)$st->fetchColumn();
        if (!$formId) return null;
        $root = self::formRoot($conn, $formId);
        $raw = self::rawAnswers($conn, $submissionId);
        $fallback = null;
        foreach (self::templates($conn, $kind, true) as $t) {
            if (!$t['form_id'] || self::formRoot($conn, (int)$t['form_id']) !== $root) continue;
            if (empty($t['match_rule'])) { $fallback ??= $t['id']; continue; }
            if (self::ruleMet($t['match_rule'], $raw)) return $t['id'];
        }
        return $fallback;
    }

    /**
     * The workflow action "Open a Starters & leavers case" - from a form's own
     * action list (on submit or on approval) or any workflow with a submission.
     * Whatever $a leaves blank is read from the answers: the name from a question
     * called something like "Name", the email from the first email question, the
     * date from the first date question, the job title from "Job title" / "Role".
     * The template is $a['template_id'], else chooseTemplate().
     *
     * @param array $a  kind, template_id?, person_name?, person_email?, effective_date?, job_title?, owner_analyst_id?
     * @param ?int $forTenant  a company's own workflow acts only for that company
     * @return array{case_id:int, template_id:?int, warnings:array}
     */
    public static function openFromSubmission(PDO $conn, array $a, array $payload, ?int $forTenant = null): array
    {
        if (!self::ready($conn)) throw new Exception('Starters & leavers is not set up - run Database Verification.');
        $kind = JmlKind::tryFrom((string)($a['kind'] ?? '')) ?? JmlKind::Starter;
        $submissionId = isset($payload['submission']['id']) ? (int)$payload['submission']['id'] : 0;
        $raw = $submissionId ? self::rawAnswers($conn, $submissionId) : [];
        $answers = $submissionId ? self::answers($conn, $submissionId) : [];
        $text = [];
        foreach ($answers as $x) $text[mb_strtolower(trim((string)$x['label']))] = trim((string)$x['value']);

        $pick = function (callable $test) use ($raw, $text): string {
            foreach ($raw as $label => $f) if ($test($label, $f) && ($text[$label] ?? '') !== '') return $text[$label];
            return '';
        };
        $name  = trim((string)($a['person_name'] ?? '')) ?: $pick(fn($l, $f) => preg_match('/\bname\b/u', $l) && !preg_match('/manager|requester|your name|company|department/u', $l));
        $email = trim((string)($a['person_email'] ?? '')) ?: $pick(fn($l, $f) => $f['type'] === 'email' && !preg_match('/manager|your|requester/u', $l));
        // A date question is a Forms 'datetime' in date mode: the one that sounds
        // like the day ("Start date", "Last day"), else the first.
        $date  = trim((string)($a['effective_date'] ?? ''))
              ?: ($pick(fn($l, $f) => $f['type'] === 'datetime' && preg_match('/start|first|last|leav|move|date|day/u', $l))
              ?: $pick(fn($l, $f) => $f['type'] === 'datetime'));
        $job   = trim((string)($a['job_title'] ?? '')) ?: $pick(fn($l, $f) => (bool)preg_match('/job|role|position/u', $l));
        $date = substr($date, 0, 10);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            throw new Exception('The date was not found in the answers - add a date question, or fill in the action\'s date (for example {{submission.fields.Start date}}).');
        }

        // Who asked decides the company; a mover or leaver is matched by email.
        $tenantId = null;
        if ($submissionId) {
            $s = $conn->prepare("SELECT u.tenant_id FROM form_submissions s JOIN users u ON u.id = s.submitted_by_user_id WHERE s.id = ?");
            $s->execute([$submissionId]);
            $v = $s->fetchColumn();
            if ($v !== false && $v !== null) $tenantId = (int)$v;
        }
        if ($forTenant !== null) {
            if ($tenantId !== null && $tenantId !== $forTenant) throw new Exception('The requester belongs to another company. This workflow only acts for its own company.');
            $tenantId = $forTenant;
        }
        $userId = null;
        if ($kind !== JmlKind::Starter && $email !== '') {
            $u = $conn->prepare("SELECT id FROM users WHERE email = ?" . ($tenantId !== null ? " AND (tenant_id = ? OR tenant_id IS NULL)" : '') . " ORDER BY is_active DESC, id LIMIT 1");
            $u->execute($tenantId !== null ? [$email, $tenantId] : [$email]);
            $userId = ($id = $u->fetchColumn()) ? (int)$id : null;
        }
        $templateId = !empty($a['template_id']) ? (int)$a['template_id'] : ($submissionId ? self::chooseTemplate($conn, $kind->value, $submissionId) : null);

        $ctx = new ActorContext(0, null, 'ui', 'en', 'Workflow');
        $warnings = [];
        $id = self::createCase($conn, $ctx, [
            'kind' => $kind->value, 'effective_date' => $date, 'template_id' => $templateId,
            'user_id' => $userId, 'person_name' => $name ?: ($email ?: 'Not given'), 'person_email' => $email ?: null,
            'job_title' => $job ?: null, 'owner_analyst_id' => !empty($a['owner_analyst_id']) ? (int)$a['owner_analyst_id'] : null,
            'form_submission_id' => $submissionId ?: null, '_from_form' => true, '_tenant_id' => $tenantId,
        ], $warnings);
        if (!$templateId) self::audit($conn, $id, $ctx, 'no_template', null, null);
        return ['case_id' => $id, 'template_id' => $templateId, 'warnings' => $warnings];
    }

    /**
     * Tasks tells us a form on a task was answered (TaskForms::submit). If that
     * task is a case's and the case was waiting for its answers, keep them and
     * add the steps now. A later re-answer only replaces the answers kept.
     */
    public static function taskFormSubmitted(PDO $conn, ActorContext $ctx, int $taskId, int $submissionId): void
    {
        if (!self::ready($conn)) return;
        $st = $conn->prepare("SELECT id, steps_pending FROM jml_cases WHERE parent_task_id = ?");
        $st->execute([$taskId]);
        $c = $st->fetch(PDO::FETCH_ASSOC);
        if (!$c) return;
        $conn->prepare("UPDATE jml_cases SET form_submission_id = ? WHERE id = ?")->execute([$submissionId, (int)$c['id']]);
        if ((int)$c['steps_pending']) {
            $sys = $ctx->actorId > 0 ? $ctx : new ActorContext(0, null, 'ui', 'en', 'Workflow');
            self::applyTemplate($conn, $sys, (int)$c['id']);
        }
    }

    /**
     * The language for text this module WRITES into other modules - the case's task, a
     * custody note. It is saved once and read by everybody, so it follows the case, not
     * the request: the owner's chosen language, else the acting analyst's, else English.
     * A workflow or portal opening (actor 0) has no language of its own.
     */
    private static function writeLocale(PDO $conn, ActorContext $ctx, ?int $ownerId): string
    {
        $ownerId = $ownerId ?: ($ctx->actorId > 0 ? $ctx->actorId : null);
        if ($ownerId) {
            $st = $conn->prepare("SELECT preference_value FROM user_preferences WHERE analyst_id = ? AND preference_key = 'interface_language' LIMIT 1");
            $st->execute([$ownerId]);
            if ($v = $st->fetchColumn()) return (string)$v;
        }
        return $ctx->actorId > 0 ? $ctx->locale : I18n::DEFAULT_LOCALE;
    }

    /**
     * Rewrites a case's parent task title and description from the case as it stands -
     * kind, name, date, answers - exactly as opening it would have written them. The demo
     * import uses it (includes/demo_data.php): demo tasks are inserted as plain rows, so
     * their titles cannot carry a date relative to the day of the import.
     */
    public static function refreshTaskText(PDO $conn, int $caseId): void
    {
        $st = $conn->prepare("SELECT kind, person_name, effective_date, parent_task_id, form_submission_id, owner_analyst_id FROM jml_cases WHERE id = ?");
        $st->execute([$caseId]);
        $c = $st->fetch(PDO::FETCH_ASSOC);
        $kind = $c ? JmlKind::tryFrom((string)$c['kind']) : null;
        if (!$kind || !$c['parent_task_id']) return;
        $loc = self::writeLocale($conn, new ActorContext(0, null, 'ui', 'en', 'Workflow'), $c['owner_analyst_id'] ? (int)$c['owner_analyst_id'] : null);
        $answers = $c['form_submission_id'] ? self::answers($conn, (int)$c['form_submission_id']) : [];
        $conn->prepare("UPDATE tasks SET title = ?, description = ? WHERE id = ?")->execute([
            self::taskTitle($kind, (string)$c['person_name'], (string)$c['effective_date'], $loc),
            self::taskDescription($caseId, $answers, $loc),
            (int)$c['parent_task_id'],
        ]);
    }

    /** The task title a case's work is filed under - "Leaver: Jane Smith (31 Oct 2026)", in the case's language. */
    private static function taskTitle(JmlKind $kind, string $name, string $date, string $locale): string
    {
        $ts = strtotime($date);
        // TRAP: intl is not on every install (slim Docker builds) - an ISO date reads the same in every language.
        $shown = class_exists('IntlDateFormatter')
            ? (string)(new IntlDateFormatter($locale === 'en' ? 'en_GB' : str_replace('-', '_', $locale), IntlDateFormatter::MEDIUM, IntlDateFormatter::NONE, date_default_timezone_get()))->format($ts)
            : date('Y-m-d', $ts);
        $title = I18n::tFor($locale, 'starters.written.task_title', [
            'kind' => I18n::tFor($locale, 'starters.kind.' . $kind->value), 'name' => $name, 'date' => $shown ?: date('Y-m-d', $ts),
        ]);
        return mb_substr($title, 0, 255);
    }

    /** The parent task's description - a way back to the case. */
    private static function taskDescription(int $caseId, array $answers, string $locale): string
    {
        require_once __DIR__ . '/../entity_links.php';
        $url = (defined('BASE_URL') ? BASE_URL : '') . entityLink('jml_case', $caseId);
        $h = '<p>' . htmlspecialchars(I18n::tFor($locale, 'starters.written.task_intro')) . '</p>'
           . '<p><a href="' . htmlspecialchars($url) . '">' . htmlspecialchars(I18n::tFor($locale, 'starters.written.task_open')) . '</a></p>';
        // The answers, where the work is done - so nobody goes looking for the form.
        $rows = array_filter($answers, fn($a) => trim((string)$a['value']) !== '');
        if ($rows) {
            $h .= '<table>';
            foreach ($rows as $a) $h .= '<tr><td><strong>' . htmlspecialchars((string)$a['label']) . '</strong></td><td>' . nl2br(htmlspecialchars((string)$a['value'])) . '</td></tr>';
            $h .= '</table>';
        }
        return $h;
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
        // The answers (Forms) and, while the steps wait for them, where to give them.
        $c['answers'] = $c['form_submission_id'] ? self::answers($conn, (int)$c['form_submission_id']) : [];
        $c['steps_pending'] = (bool)(int)($c['steps_pending'] ?? 0);
        $c['form_title'] = null;
        $c['task_forms_on'] = true;
        if ($c['template_id']) {
            $f = $conn->prepare("SELECT f.title FROM jml_templates t JOIN forms f ON f.id = t.form_id WHERE t.id = ?");
            $f->execute([$c['template_id']]);
            $c['form_title'] = $f->fetchColumn() ?: null;
            if ($c['steps_pending']) {
                require_once __DIR__ . '/../services/task_forms.php';
                $c['task_forms_on'] = TaskForms::enabled($conn);
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
        AssetsService::assignUser($conn, $ctx, $assetId, ['user_id' => (int)$c['user_id'], 'notes' => I18n::tFor(self::writeLocale($conn, $ctx, $c['owner_analyst_id'] ? (int)$c['owner_analyst_id'] : null), 'starters.written.kit_note', ['id' => $caseId])]);
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
        foreach (['id', 'tenant_id', 'user_id', 'manager_user_id', 'template_id', 'parent_task_id', 'owner_analyst_id', 'created_by', 'form_submission_id'] as $k) {
            $c[$k] = ($c[$k] ?? null) === null ? null : (int)$c[$k];
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
