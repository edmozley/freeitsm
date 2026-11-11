<?php
/**
 * Forms a task occurrence needs (GH #190) - the requirement, its shared draft,
 * and the submission that met it. And, since GH #272, forms a TICKET needs:
 * the same engine, with the ticket as the host (TicketForms, below).
 *
 * #181 is the record and #190 is the workbench: the submission is an ordinary
 * Forms submission, made through FormsService::submitForm() with the same
 * validation as the Forms page, and linked to its host IN THE SAME TRANSACTION.
 * Completion is checked by the host's one gate (ChecklistsService::taskCompletionCheck()
 * for a task, ChecklistsService::assertClosureAllowed() for a ticket), which asks this
 * class what is outstanding.
 *
 * HOST-AWARE (GH #272): everything that differs between a task and a ticket is a
 * constant or a protected static hook - the table, the host column, the column on
 * form_submissions, the on/off setting, who may read the answers, the write gate,
 * where history goes, the host's company. Every method uses static::, so
 * TicketForms inherits the whole engine and overrides only those. Never copy a
 * method into TicketForms: change it here, for both.
 *
 * Design: wiki Task-Work-View-Design. Developer guide: Task-Forms-Developer-Guide.
 */

require_once __DIR__ . '/../service_context.php';
require_once __DIR__ . '/task_history.php';

/** A save or submit that lost a race with somebody else's (GH #190). Carries what they saved. */
class TaskFormConflict extends ServiceError
{
    /** @var array who saved, when, the revision now current, and their draft answers */
    public array $detail;

    public function __construct(string $message, array $detail)
    {
        parent::__construct('conflict', 'task_form_conflict', $message);
        $this->detail = $detail;
    }
}

class TaskForms
{
    // ── The host (GH #272) ──────────────────────────────────────────────────
    /** The requirement table. */
    protected const TABLE = 'task_forms';
    /** Its column naming the host. */
    protected const FK = 'task_id';
    /** The column on form_submissions linking a submission to its host. */
    protected const SUB_COL = 'task_id';
    /** The system_settings switch. */
    protected const SETTING = 'tasks_forms_enabled';
    /** The host's name in messages. */
    protected const NOUN = 'task';

    /** The everyday permission that reads answers without Forms access. */
    protected static function answersCap(): ?Cap
    {
        return class_exists('Cap') ? Cap::TASKS_FORM_ANSWERS : null;
    }

    /** The host's company (tenant_id), or null. */
    protected static function hostTenant(PDO $conn, int $hostId): ?int
    {
        $t = $conn->prepare("SELECT tenant_id FROM tasks WHERE id = ?");
        $t->execute([$hostId]);
        $v = $t->fetchColumn();
        return $v !== false && $v !== null ? (int)$v : null;
    }

    /** What the fill page shows about the host. */
    protected static function hostSummary(PDO $conn, int $hostId): array
    {
        $t = $conn->prepare("SELECT id, title, scheduled_date, due_date FROM tasks WHERE id = ?");
        $t->execute([$hostId]);
        $task = $t->fetch(PDO::FETCH_ASSOC) ?: [];
        return ['id' => (int)($task['id'] ?? 0), 'title' => (string)($task['title'] ?? ''),
                'scheduled_date' => $task['scheduled_date'] ?? null, 'due_date' => $task['due_date'] ?? null];
    }

    /** One line of the host's history. */
    protected static function record(PDO $conn, int $hostId, string $type, ActorContext $ctx, ?string $from, ?string $to, array $detail): void
    {
        TaskHistory::record($conn, $hostId, $type, $ctx, $from, $to, $detail);
    }

    /** After a submission: what else the host does with it. */
    protected static function afterSubmit(PDO $conn, ActorContext $ctx, int $hostId, int $submissionId): void
    {
        // Starters & leavers (4.6.0): a case's questions are answered on its task -
        // the case keeps the answers and adds the steps that waited for them.
        require_once __DIR__ . '/../starters/service.php';
        try { StartersService::taskFormSubmitted($conn, $ctx, $hostId, $submissionId); }
        catch (Throwable $e) { error_log('starters: answers arrived for task ' . $hostId . ': ' . $e->getMessage()); }
    }

    /** Can this actor change this task's forms: Tasks write, the task visible, and the #181 lock. */
    protected static function guardWrite(PDO $conn, ActorContext $ctx, int $taskId): void
    {
        require_once __DIR__ . '/tasks.php';
        require_once __DIR__ . '/checklists.php';
        TasksService::assertCanWrite($conn, $ctx);
        if ($ctx->actorId > 0 && function_exists('analystCanAccessTask') && !analystCanAccessTask($conn, $ctx->actorId, $taskId)) {
            throw new ServiceError('not_found', 'not_found', 'Task not found.');
        }
        // A finished task's working material follows "Changing a completed task's checklist" (#181).
        ChecklistsService::assertTaskChecklistChangeAllowed($conn, $taskId);
    }

    // ── On or off ───────────────────────────────────────────────────────────

    /**
     * The host's "Forms on ..." switch (tasks: Tasks -> Settings -> Checklists). Off by default.
     * GH #217: also off while the Forms module is switched off - no section, no picker,
     * and (outstanding() asks this) no form holding a host open that nobody can fill.
     */
    public static function enabled(PDO $conn): bool
    {
        if (!moduleIsEnabled($conn, 'forms')) return false;
        try {
            $s = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ?");
            $s->execute([static::SETTING]);
            return (string)$s->fetchColumn() === '1';
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Have the host's columns been added yet? Cached per request, per host.
     *
     * TRAP: every reader of form_submissions that names task_id / for_ticket_id must ask
     * this first - an upgraded install has none of these columns until somebody runs
     * Database Verification, and a list query that fails reads as "no submissions".
     */
    public static function columnsReady(PDO $conn): bool
    {
        static $ready = [];
        if (isset($ready[static::class])) return $ready[static::class];
        try {
            $conn->query("SELECT " . static::SUB_COL . ", tenant_id, client_reference, superseded_by_id FROM form_submissions LIMIT 0");
            $conn->query("SELECT id FROM " . static::TABLE . " LIMIT 0");
            return $ready[static::class] = true;
        } catch (Throwable) {
            return $ready[static::class] = false;
        }
    }

    // ── Who may do what ─────────────────────────────────────────────────────

    /**
     * May this analyst READ a host's form answers? The one rule (decision 4),
     * used by the task window, the History tab, the evidence report and its CSV -
     * and the ticket's Forms panel and case report.
     *
     * You must already be able to see the host - every caller checks that first.
     * Then EITHER access to Forms (as reading the same submission there needs) OR
     * the everyday permission "Read form answers on tasks" / "... on tickets", off
     * unless a role grants it. Host access alone is never enough: it must not quietly
     * override Forms access.
     */
    public static function mayReadAnswers(PDO $conn, int $analystId): bool
    {
        if ($analystId <= 0) return false;
        if (function_exists('analystModuleLevel') && analystModuleLevel($conn, $analystId, 'forms') !== null) return true;
        $cap = static::answersCap();
        return $cap !== null && function_exists('analystMayAct') && analystMayAct($conn, $analystId, $cap);
    }

    /** May this analyst FILL a form in a task? What filling it on the Forms page needs: Forms, not Read only. */
    public static function mayFill(PDO $conn, int $analystId): bool
    {
        return $analystId > 0 && function_exists('analystCanWriteModule') && analystCanWriteModule($conn, $analystId, 'forms');
    }

    // ── Reading ─────────────────────────────────────────────────────────────

    /**
     * Every form a host needs, with its state, for the task window / the ticket's Forms panel.
     *
     * @param int $viewerId  the analyst looking; decides may_read / may_fill and whether answers are included
     * @param bool $withAnswers  include the current submission's answers where the viewer may read them
     */
    public static function forHost(PDO $conn, int $hostId, int $viewerId, bool $withAnswers = false): array
    {
        if (!static::columnsReady($conn)) return [];
        $st = $conn->prepare(
            "SELECT tf.*, f.title AS form_title_now, f.is_active AS form_active,
                    s.submitted_date, s.submitted_by, a.full_name AS submitted_by_name
               FROM " . static::TABLE . " tf
               LEFT JOIN forms f ON f.id = tf.form_id
               LEFT JOIN form_submissions s ON s.id = tf.submission_id
               LEFT JOIN analysts a ON a.id = s.submitted_by
              WHERE tf." . static::FK . " = ?
              ORDER BY tf.sort_order, tf.id");
        $st->execute([$hostId]);
        $mayRead = static::mayReadAnswers($conn, $viewerId);
        $mayFill = static::mayFill($conn, $viewerId);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $row = [
                'id'               => (int)$r['id'],
                'form_id'          => (int)$r['form_id'],
                'title'            => (string)$r['title'],
                'form_exists'      => $r['form_title_now'] !== null,
                'form_active'      => (int)($r['form_active'] ?? 0) === 1,
                'is_required'      => (int)$r['is_required'] === 1,
                'requirement_mode' => (string)$r['requirement_mode'],
                'submission_id'    => $r['submission_id'] !== null ? (int)$r['submission_id'] : null,
                'submitted_utc'    => $r['submitted_date'],
                'submitted_by'     => $r['submitted_by_name'],
                'has_draft'        => $r['draft_json'] !== null && $r['draft_json'] !== '',
                'draft_by'         => $r['draft_by_name'],
                'draft_utc'        => $r['draft_datetime'],
                'revision'         => (int)$r['revision'],
                'may_read'         => $mayRead,
                'may_fill'         => $mayFill,
            ];
            if ($withAnswers && $mayRead && $row['submission_id']) {
                $row['answers'] = self::answers($conn, $row['submission_id']);
            }
            $out[] = $row;
        }
        return $out;
    }

    /** Every form a task needs (forHost(), named for the task callers). */
    public static function forTask(PDO $conn, int $taskId, int $viewerId, bool $withAnswers = false): array
    {
        return static::forHost($conn, $taskId, $viewerId, $withAnswers);
    }

    /**
     * A submission's answers as label + readable text, in the form's order. Labels come from
     * the form version it was made on, so a later version never relabels an old answer.
     */
    public static function answers(PDO $conn, int $submissionId): array
    {
        $st = $conn->prepare(
            "SELECT ff.id, ff.label, ff.field_type, ff.options, ff.config, d.field_value
               FROM form_submission_data d
               JOIN form_fields ff ON ff.id = d.field_id
              WHERE d.submission_id = ?
              ORDER BY ff.sort_order, ff.id");
        $st->execute([$submissionId]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $f) {
            $out[] = ['field_id' => (int)$f['id'], 'label' => (string)$f['label'], 'type' => (string)$f['field_type'],
                      'value' => self::displayValue($f, (string)$f['field_value']),
                      'exception' => self::isException($f, (string)$f['field_value'])];
        }
        return $out;
    }

    /**
     * Does this answer COUNT AS AN EXCEPTION (GH #190 phase 4)? The form version's
     * config.exception_values against the answer - any of a checkbox list's ticks.
     */
    public static function isException(array $field, string $raw): bool
    {
        $cfg = is_array($field['config'] ?? null) ? $field['config'] : (json_decode((string)($field['config'] ?? ''), true) ?: []);
        $ev = $cfg['exception_values'] ?? [];
        if (!is_array($ev) || !$ev) return false;
        $d = json_decode($raw, true);
        $vals = is_array($d) ? array_map('strval', $d) : [$raw];
        return (bool)array_intersect($vals, array_map('strval', $ev));
    }

    /** One stored answer as text a person reads - lists joined, a lookup's label, a grid's rows. */
    public static function displayValue(array $field, string $raw): string
    {
        $type = (string)($field['field_type'] ?? '');
        if ($type === 'grid' && class_exists('FormsService')) {
            return FormsService::gridToText($field, $raw);
        }
        if ($type === 'checkbox') return $raw === '1' ? 'Yes' : 'No';
        $d = json_decode($raw, true);
        if (is_array($d)) {
            if (isset($d['label'])) return (string)$d['label'];               // a lookup: {id, label}
            return implode(', ', array_map(function ($v) { return is_scalar($v) ? (string)$v : json_encode($v); }, $d));
        }
        return $raw;
    }

    /** One requirement row, with the host it belongs to; 404 if gone. */
    public static function row(PDO $conn, int $hostFormId): array
    {
        $st = $conn->prepare("SELECT * FROM " . static::TABLE . " WHERE id = ?");
        $st->execute([$hostFormId]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r) throw new ServiceError('not_found', 'not_found', 'That form is no longer on this ' . static::NOUN . '.');
        return $r;
    }

    /** The host id a requirement row belongs to. */
    public static function hostOf(array $row): int
    {
        return (int)$row[static::FK];
    }

    /** What the fill page needs: the form version, the shared draft, its revision, the host. */
    public static function forFill(PDO $conn, int $hostFormId): array
    {
        $r = static::row($conn, $hostFormId);
        $prefill = null;
        // Correcting a submission starts from what was submitted, when nobody has a draft going.
        if (($r['draft_json'] === null || $r['draft_json'] === '') && $r['submission_id']) {
            $d = $conn->prepare("SELECT field_id, field_value FROM form_submission_data WHERE submission_id = ?");
            $d->execute([(int)$r['submission_id']]);
            $prefill = [];
            foreach ($d->fetchAll(PDO::FETCH_ASSOC) as $x) $prefill[(string)$x['field_id']] = $x['field_value'];
        }
        return [
            'task_form_id'  => (int)$r['id'],     // the requirement's id - named for tasks, used for both (the fill page)
            'host'          => static::NOUN,
            'form_id'       => (int)$r['form_id'],
            'title'         => (string)$r['title'],
            'revision'      => (int)$r['revision'],
            'draft'         => $r['draft_json'] !== null && $r['draft_json'] !== '' ? (json_decode($r['draft_json'], true) ?: []) : null,
            'draft_by'      => $r['draft_by_name'],
            'draft_utc'     => $r['draft_datetime'],
            'submission_id' => $r['submission_id'] !== null ? (int)$r['submission_id'] : null,
            'prefill'       => $prefill,
            'task'          => static::hostSummary($conn, static::hostOf($r)),   // the host, whichever it is
        ];
    }

    // ── Changing what a host needs ──────────────────────────────────────────

    /**
     * Add a form to a host. Uses the form's NEWEST version, so an older version
     * that is still active is never chosen by accident.
     */
    public static function add(PDO $conn, ActorContext $ctx, int $hostId, int $formId, bool $required = true, string $mode = 'block'): int
    {
        static::guardWrite($conn, $ctx, $hostId);
        $formId = self::newestVersion($conn, $formId);
        $f = $conn->prepare("SELECT title, is_active FROM forms WHERE id = ?");
        $f->execute([$formId]);
        $form = $f->fetch(PDO::FETCH_ASSOC);
        if (!$form) throw new ServiceError('not_found', 'not_found', 'Form not found.');
        if (!(int)$form['is_active']) throw new ServiceError('validation', 'invalid_field', 'That form is inactive.');
        $dup = $conn->prepare("SELECT 1 FROM " . static::TABLE . " WHERE " . static::FK . " = ? AND form_id = ?");
        $dup->execute([$hostId, $formId]);
        if ($dup->fetchColumn()) throw new ServiceError('conflict', 'conflict', 'That form is already on this ' . static::NOUN . '.');
        $sort = $conn->prepare("SELECT COALESCE(MAX(sort_order), 0) + 1 FROM " . static::TABLE . " WHERE " . static::FK . " = ?");
        $sort->execute([$hostId]);
        $conn->prepare("INSERT INTO " . static::TABLE . " (" . static::FK . ", form_id, title, is_required, requirement_mode, sort_order, added_by_id, added_datetime)
                        VALUES (?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())")
             ->execute([$hostId, $formId, (string)$form['title'], $required ? 1 : 0, $mode === 'warn' ? 'warn' : 'block',
                        (int)$sort->fetchColumn(), $ctx->actorId > 0 ? $ctx->actorId : null]);
        $id = (int)$conn->lastInsertId();
        static::record($conn, $hostId, 'form_added', $ctx, null, (string)$form['title'],
            ['task_form_id' => $id, 'form_id' => $formId, 'required' => $required, 'mode' => $mode]);
        return $id;
    }

    /** Required or optional, warn or block. */
    public static function update(PDO $conn, ActorContext $ctx, int $hostFormId, bool $required, string $mode): void
    {
        $r = static::row($conn, $hostFormId);
        static::guardWrite($conn, $ctx, static::hostOf($r));
        $conn->prepare("UPDATE " . static::TABLE . " SET is_required = ?, requirement_mode = ? WHERE id = ?")
             ->execute([$required ? 1 : 0, $mode === 'warn' ? 'warn' : 'block', $hostFormId]);
    }

    /**
     * Take a form off a host. A submission it already has stays - linked to the
     * host and in its history - because it is still evidence of work done.
     */
    public static function remove(PDO $conn, ActorContext $ctx, int $hostFormId): int
    {
        $r = static::row($conn, $hostFormId);
        $hostId = static::hostOf($r);
        static::guardWrite($conn, $ctx, $hostId);
        $conn->prepare("DELETE FROM " . static::TABLE . " WHERE id = ?")->execute([$hostFormId]);
        static::record($conn, $hostId, 'form_removed', $ctx, (string)$r['title'], null,
            ['task_form_id' => $hostFormId, 'form_id' => (int)$r['form_id'],
             'submission_id' => $r['submission_id'] !== null ? (int)$r['submission_id'] : null]);
        return $hostId;
    }

    // ── Drafts and submitting ───────────────────────────────────────────────

    /**
     * Save the shared draft - only if nobody else has saved since $revision (GH #190).
     *
     * 🔴 The UPDATE carries `WHERE revision = ?`. Checking first and writing second
     * would let two saves both pass the check; the conditional write is the check.
     *
     * @return array the new revision, and who/when
     * @throws TaskFormConflict with the other person's draft when it lost the race
     */
    public static function saveDraft(PDO $conn, ActorContext $ctx, int $hostFormId, int $revision, array $answers): array
    {
        $r = static::row($conn, $hostFormId);
        static::guardWrite($conn, $ctx, static::hostOf($r));
        static::guardFill($conn, $ctx);
        $up = $conn->prepare("UPDATE " . static::TABLE . "
                                 SET draft_json = ?, draft_by_id = ?, draft_by_name = ?, draft_datetime = UTC_TIMESTAMP(),
                                     revision = revision + 1
                               WHERE id = ? AND revision = ?");
        $up->execute([json_encode((object)$answers), $ctx->actorId ?: null, mb_substr($ctx->actorName, 0, 150), $hostFormId, $revision]);
        if ($up->rowCount() !== 1) {
            throw static::conflict($conn, $hostFormId);
        }
        return ['revision' => $revision + 1, 'draft_by' => $ctx->actorName];
    }

    /**
     * Submit the form for this host, or correct an earlier submission.
     *
     * - Validation is FormsService::submitForm()'s, unchanged.
     * - The link to the host is written inside submitForm's own transaction, and is
     *   conditional on $revision - so a lost race rolls the submission back too.
     * - $clientRef makes a retry return the submission it already made.
     *
     * @return array{submission_id:int, revision:int, replayed:bool}
     */
    public static function submit(PDO $conn, ActorContext $ctx, int $hostFormId, int $revision, string $clientRef, array $data): array
    {
        require_once __DIR__ . '/forms.php';
        $r = static::row($conn, $hostFormId);
        $hostId = static::hostOf($r);
        $clientRef = substr(preg_replace('/[^A-Za-z0-9_.:-]/', '', $clientRef), 0, 64);

        // A retry of a submit that DID go through: hand back what it made.
        if ($clientRef !== '') {
            $q = $conn->prepare("SELECT id FROM form_submissions WHERE " . static::SUB_COL . " = ? AND client_reference = ?");
            $q->execute([$hostId, $clientRef]);
            $existing = $q->fetchColumn();
            if ($existing) {
                return ['submission_id' => (int)$existing, 'revision' => (int)$r['revision'], 'replayed' => true];
            }
        }

        static::guardWrite($conn, $ctx, $hostId);
        static::guardFill($conn, $ctx);
        $previous = $r['submission_id'] !== null ? (int)$r['submission_id'] : null;
        $table = static::TABLE;

        $within = function (PDO $conn, int $submissionId) use ($hostFormId, $revision, $previous, $table) {
            $up = $conn->prepare("UPDATE $table
                                     SET submission_id = ?, draft_json = NULL, draft_by_id = NULL, draft_by_name = NULL,
                                         draft_datetime = NULL, revision = revision + 1
                                   WHERE id = ? AND revision = ?");
            $up->execute([$submissionId, $hostFormId, $revision]);
            if ($up->rowCount() !== 1) {
                throw static::conflict($conn, $hostFormId);   // rolls the submission back with it
            }
            if ($previous) {
                $conn->prepare("UPDATE form_submissions SET superseded_by_id = ? WHERE id = ?")->execute([$submissionId, $previous]);
            }
        };

        $submissionId = FormsService::submitForm($conn, $ctx, (int)$r['form_id'], $data, null, [
            static::SUB_COL    => $hostId,
            'tenant_id'        => static::hostTenant($conn, $hostId),
            'client_reference' => $clientRef !== '' ? $clientRef : null,
            'within'           => $within,
        ]);

        static::record($conn, $hostId, $previous ? 'form_replaced' : 'form_submitted', $ctx,
            $previous ? (string)$previous : null, (string)$r['title'],
            ['task_form_id' => $hostFormId, 'form_id' => (int)$r['form_id'], 'submission_id' => $submissionId,
             'replaced_submission_id' => $previous]);
        static::afterSubmit($conn, $ctx, $hostId, $submissionId);
        return ['submission_id' => $submissionId, 'revision' => $revision + 1, 'replayed' => false];
    }

    /** The conflict a lost race reports: who saved, when, and what they saved. */
    protected static function conflict(PDO $conn, int $hostFormId): TaskFormConflict
    {
        $st = $conn->prepare("SELECT tf.revision, tf.draft_json, tf.draft_by_name, tf.draft_datetime, tf.submission_id,
                                     s.submitted_date, a.full_name AS submitted_by
                                FROM " . static::TABLE . " tf
                                LEFT JOIN form_submissions s ON s.id = tf.submission_id
                                LEFT JOIN analysts a ON a.id = s.submitted_by
                               WHERE tf.id = ?");
        $st->execute([$hostFormId]);
        $now = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        // Whichever happened last is the change this analyst did not see.
        $byDraft = $now && $now['draft_datetime'] !== null
            && ($now['submitted_date'] === null || $now['draft_datetime'] >= $now['submitted_date']);
        $who  = $byDraft ? (string)$now['draft_by_name'] : (string)($now['submitted_by'] ?? '');
        $when = $byDraft ? $now['draft_datetime'] : ($now['submitted_date'] ?? null);
        return new TaskFormConflict(
            ($who !== '' ? $who : 'Somebody') . ' changed this form while you had it open. Your answers are still on screen - choose whether to keep yours or use theirs.',
            ['revision' => (int)($now['revision'] ?? 0), 'by' => $who, 'at_utc' => $when,
             'kind' => $byDraft ? 'draft' : 'submitted',
             'draft' => $byDraft && $now['draft_json'] ? (json_decode($now['draft_json'], true) ?: []) : null,
             'submission_id' => isset($now['submission_id']) ? (int)$now['submission_id'] : null]);
    }

    // ── Completion, repeats, deleting, the frozen record ────────────────────

    /**
     * Required forms with no submission, for the host's one gate (a task's completion
     * check, a ticket's closure). [['title' => ..., 'mode' => warn|block], ...]
     */
    public static function outstanding(PDO $conn, int $hostId): array
    {
        if (!static::enabled($conn) || !static::columnsReady($conn)) return [];
        try {
            $st = $conn->prepare("SELECT title, requirement_mode FROM " . static::TABLE . "
                                   WHERE " . static::FK . " = ? AND is_required = 1 AND submission_id IS NULL
                                   ORDER BY sort_order, id");
            $st->execute([$hostId]);
            return array_map(function ($r) { return ['title' => (string)$r['title'], 'mode' => (string)$r['requirement_mode']]; },
                             $st->fetchAll(PDO::FETCH_ASSOC));
        } catch (Throwable) {
            return [];   // completing / closing must not start failing on a part-upgraded install
        }
    }

    /**
     * The repeat's copy: the next occurrence needs the same forms, unanswered (criterion 8),
     * each on its form's newest version at that moment. Tasks only.
     */
    public static function copyForOccurrence(PDO $conn, int $fromTaskId, int $toTaskId): void
    {
        if (!self::enabled($conn) || !self::columnsReady($conn)) return;
        try {
            $st = $conn->prepare("SELECT form_id, title, is_required, requirement_mode, sort_order FROM task_forms WHERE task_id = ? ORDER BY sort_order, id");
            $st->execute([$fromTaskId]);
            $ins = $conn->prepare("INSERT INTO task_forms (task_id, form_id, title, is_required, requirement_mode, sort_order, added_datetime)
                                   VALUES (?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())");
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $formId = self::newestVersion($conn, (int)$r['form_id']);
                $t = $conn->prepare("SELECT title FROM forms WHERE id = ?");
                $t->execute([$formId]);
                $title = $t->fetchColumn();
                $ins->execute([$toTaskId, $formId, $title !== false ? (string)$title : (string)$r['title'],
                               (int)$r['is_required'], (string)$r['requirement_mode'], (int)$r['sort_order']]);
            }
        } catch (Throwable $e) {
            error_log('[task forms] copy to occurrence ' . $toTaskId . ' failed: ' . $e->getMessage());
        }
    }

    /** Remove the requirements of deleted hosts. Their submissions stay: they are evidence. */
    public static function deleteForHosts(PDO $conn, array $hostIds): void
    {
        $hostIds = array_values(array_filter(array_map('intval', $hostIds)));
        if (!$hostIds) return;
        try {
            $place = implode(',', array_fill(0, count($hostIds), '?'));
            $conn->prepare("DELETE FROM " . static::TABLE . " WHERE " . static::FK . " IN ($place)")->execute($hostIds);
        } catch (Throwable) {
            // the table does not exist yet on a part-upgraded install: nothing to remove
        }
    }

    /** Remove the requirements of deleted tasks (deleteForHosts(), named for the task callers). */
    public static function deleteForTasks(PDO $conn, array $taskIds): void
    {
        self::deleteForHosts($conn, $taskIds);
    }

    /**
     * Everything about a task's forms that the #181 frozen record keeps when an occurrence is
     * removed: each requirement, and every submission made for it - superseded ones included -
     * with its answers and their labels, so the record reads even after the form is gone.
     */
    public static function snapshot(PDO $conn, int $taskId, bool $strict = false): array
    {
        if (!self::columnsReady($conn)) return [];
        try {
            $req = $conn->prepare("SELECT id, form_id, title, is_required, requirement_mode, submission_id FROM task_forms WHERE task_id = ?");
            $req->execute([$taskId]);
            $sub = $conn->prepare("SELECT s.id, s.form_id, s.submitted_date, s.superseded_by_id, a.full_name AS submitted_by
                                     FROM form_submissions s LEFT JOIN analysts a ON a.id = s.submitted_by
                                    WHERE s.task_id = ? ORDER BY s.id");
            $sub->execute([$taskId]);
            $subs = [];
            foreach ($sub->fetchAll(PDO::FETCH_ASSOC) as $s) {
                $s['answers'] = self::answers($conn, (int)$s['id']);
                $subs[] = $s;
            }
            return ['requirements' => $req->fetchAll(PDO::FETCH_ASSOC), 'submissions' => $subs];
        } catch (Throwable $e) {
            if ($strict) throw $e;   // a removal is not made with its forms silently missing
            return [];
        }
    }

    /**
     * The SQL that keeps a task's - or a ticket's (GH #272) - submissions to people who
     * can see that host's company, for every Forms list. [sql, params]; '1=1' on a
     * single-company install or before Database Verification.
     *
     * @param ?array $companyScope  ActorContext::$companyScope - null means every company (or a single-company install)
     * @param string $alias  the form_submissions alias in the calling query
     */
    public static function submissionScopeSql(PDO $conn, ?array $companyScope, string $alias = 's'): array
    {
        if ($companyScope === null || !TaskForms::columnsReady($conn)) return ['1=1', []];
        // Install-wide unless made inside a task or (once its column exists) for a ticket.
        $hosted = "$alias.task_id IS NOT NULL" . (TicketForms::columnsReady($conn) ? " OR $alias.for_ticket_id IS NOT NULL" : '');
        $ids = array_values(array_map('intval', $companyScope));
        $default = function_exists('getDefaultTenantId') ? (int)getDefaultTenantId($conn) : 0;
        if (!$ids) return ["NOT ($hosted)", []];
        $place = implode(',', array_fill(0, count($ids), '?'));
        return ["(NOT ($hosted) OR COALESCE($alias.tenant_id, ?) IN ($place))", array_merge([$default], $ids)];
    }

    // ── Internals ───────────────────────────────────────────────────────────

    /** The newest version in a form's chain (the leaf), from any version of it. */
    public static function newestVersion(PDO $conn, int $formId): int
    {
        $q = $conn->prepare("SELECT id FROM forms WHERE parent_form_id = ? ORDER BY id DESC LIMIT 1");
        for ($hops = 0; $hops < 500; $hops++) {
            $q->execute([$formId]);
            $child = $q->fetchColumn();
            if (!$child) break;
            $formId = (int)$child;
        }
        return $formId;
    }

    /** Filling a form needs what the Forms page needs - said plainly, because embedding grants nothing. */
    protected static function guardFill(PDO $conn, ActorContext $ctx): void
    {
        if ($ctx->actorId > 0 && !self::mayFill($conn, $ctx->actorId)) {
            throw new ServiceError('forbidden', 'forms_access_needed',
                function_exists('t') ? t('tasks.forms.need_forms_access') : 'You need access to Forms to fill this in.');
        }
    }
}

/**
 * Forms a TICKET needs (GH #272) - TaskForms' engine, with the ticket as the host.
 *
 * Only the host differs: ticket_forms, form_submissions.for_ticket_id (never ticket_id,
 * which means "raised from this submission"), Tickets -> Settings -> Checklists ->
 * "Forms on tickets", the "Read form answers on tickets" permission, the ticket's
 * write gate and company, and history in ticket_audit. Everything else is inherited.
 */
class TicketForms extends TaskForms
{
    protected const TABLE   = 'ticket_forms';
    protected const FK      = 'ticket_id';
    protected const SUB_COL = 'for_ticket_id';
    protected const SETTING = 'tickets_forms_enabled';
    protected const NOUN    = 'ticket';

    protected static function answersCap(): ?Cap
    {
        return class_exists('Cap') && defined('Cap::TICKETS_FORM_ANSWERS') ? Cap::TICKETS_FORM_ANSWERS : null;
    }

    protected static function hostTenant(PDO $conn, int $hostId): ?int
    {
        $t = $conn->prepare("SELECT tenant_id FROM tickets WHERE id = ?");
        $t->execute([$hostId]);
        $v = $t->fetchColumn();
        return $v !== false && $v !== null ? (int)$v : null;
    }

    protected static function hostSummary(PDO $conn, int $hostId): array
    {
        $t = $conn->prepare("SELECT id, ticket_number, subject FROM tickets WHERE id = ?");
        $t->execute([$hostId]);
        $r = $t->fetch(PDO::FETCH_ASSOC) ?: [];
        $num = (string)($r['ticket_number'] ?? '');
        return ['id' => (int)($r['id'] ?? 0), 'title' => trim(($num !== '' ? $num . ' ' : '') . (string)($r['subject'] ?? '')),
                'scheduled_date' => null, 'due_date' => null];
    }

    /** A ticket's history is ticket_audit: "Form added: Laptop handover". NULL analyst = no person. */
    protected static function record(PDO $conn, int $hostId, string $type, ActorContext $ctx, ?string $from, ?string $to, array $detail): void
    {
        $label = ['form_added' => 'Form added', 'form_removed' => 'Form removed',
                  'form_submitted' => 'Form submitted', 'form_replaced' => 'Form resubmitted'][$type] ?? 'Form';
        try {
            $conn->prepare("INSERT INTO ticket_audit (ticket_id, analyst_id, field_name, old_value, new_value, created_datetime)
                            VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP())")
                 ->execute([$hostId, $ctx->actorId > 0 ? $ctx->actorId : null, $label,
                            $type === 'form_removed' ? $from : null, $type === 'form_removed' ? null : $to]);
        } catch (Throwable $e) {
            error_log('[ticket forms] history for ticket ' . $hostId . ': ' . $e->getMessage());
        }
    }

    protected static function afterSubmit(PDO $conn, ActorContext $ctx, int $hostId, int $submissionId): void
    {
        try { $conn->prepare("UPDATE tickets SET updated_datetime = UTC_TIMESTAMP() WHERE id = ?")->execute([$hostId]); }
        catch (Throwable) { /* nothing else waits on it */ }
    }

    /** Tickets write access and the ticket visible (and there). */
    protected static function guardWrite(PDO $conn, ActorContext $ctx, int $ticketId): void
    {
        if ($ctx->actorId > 0 && function_exists('analystCanWriteModule') && !analystCanWriteModule($conn, $ctx->actorId, 'tickets')) {
            throw new ServiceError('forbidden', 'read_only', 'Read only access to Tickets cannot change a ticket\'s forms.');
        }
        $s = $conn->prepare("SELECT 1 FROM tickets WHERE id = ?");
        $s->execute([$ticketId]);
        if (!$s->fetchColumn() || ($ctx->actorId > 0 && function_exists('analystCanAccessTicket') && !analystCanAccessTicket($conn, $ctx->actorId, $ticketId))) {
            throw new ServiceError('not_found', 'not_found', 'Ticket not found.');
        }
    }

    /** Every form a ticket needs. */
    public static function forTicket(PDO $conn, int $ticketId, int $viewerId, bool $withAnswers = false): array
    {
        return static::forHost($conn, $ticketId, $viewerId, $withAnswers);
    }
}
