<?php
/**
 * ChecklistsService — the single home for the SOP-checklist rules that other
 * modules have to obey.
 *
 * Contributed as PR #141 by Santhosh Srinivasan; this layer was added during
 * integration so the one rule that reaches outside the module — "a ticket with
 * outstanding mandatory steps does not close" — is written once instead of
 * being re-stated by every caller that can close a ticket.
 *
 * Follows the three service rules (wiki: Service-Layer-Architecture):
 *   1. method(PDO, ActorContext, …) — no superglobals
 *   2. no transport — returns data or throws ServiceError, never echoes
 *   3. typed errors — ServiceError(kind, code, message)
 *
 * ──────────────────────────────────────────────────────────────────────────
 * 🔴 WHY THE GATE IS HERE AND NOT IN THE BROWSER
 *
 * As contributed, the gate was an alert() in assets/js/inbox.js and nothing
 * else. The UI refused to close the ticket; the v1 REST API, bulk actions, the
 * workflow engine and one line of curl all closed it with every mandatory step
 * outstanding. For a feature whose entire purpose is ISO/SOP compliance, an
 * unenforced gate is worse than no gate — it reports control that isn't there.
 *
 * ⚠️ AND WHY IT CAN ALWAYS BE OVERRIDDEN.
 *
 * assets/js/inbox.js already carries the house position, written for #83 and
 * sitting three lines below where the checklist gate was inserted:
 *
 *     "closing a ticket that still has unfinished tasks WARNS, it never blocks
 *      … a warning that cannot be shown must not become a block that cannot be
 *      cleared."
 *
 * That is right, and a hard block breaks it: the analyst who owned step 4 has
 * left, the step is obsolete, and the ticket is stuck forever with no way out
 * but SQL. So the rule is not "you cannot close this". It is:
 *
 *     close it with the steps outstanding and the override is RECORDED —
 *     who closed it, when, and which steps were skipped.
 *
 * Which is what a compliance feature should produce anyway. An auditor does not
 * want a system where the bad outcome was impossible; they want one where it is
 * attributable. Blocking hides the exception, recording it surfaces it.
 * ──────────────────────────────────────────────────────────────────────────
 */

require_once __DIR__ . '/../service_context.php';
require_once __DIR__ . '/../tenant_settings.php';   // ticketChecklistClosureMode()

class ChecklistsService
{
    /**
     * Check if a ticket has at least one checklist / SOP attached.
     */
    public static function hasAttachedChecklists(PDO $conn, int $ticketId): bool
    {
        if ($ticketId <= 0) return false;
        try {
            $stmt = $conn->prepare("SELECT 1 FROM ticket_checklists WHERE ticket_id = ? LIMIT 1");
            $stmt->execute([$ticketId]);
            return (bool)$stmt->fetchColumn();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Mandatory steps still outstanding on a ticket.
     *
     * @return array<int, array{checklist: string, step: string, item_id: int}>
     */
    public static function outstandingMandatorySteps(PDO $conn, int $ticketId): array
    {
        if ($ticketId <= 0) return [];

        // Tolerate the tables not existing yet: a site that has never opened the
        // Checklists module must not have its ticket closures start failing.
        try {
            $stmt = $conn->prepare(
                "SELECT i.id AS item_id, i.title AS step, c.title AS checklist, c.closure_mode
                   FROM ticket_checklist_items i
                   JOIN ticket_checklists c ON c.id = i.ticket_checklist_id
                  WHERE c.ticket_id = ?
                    AND i.is_mandatory = 1
                    AND i.is_completed = 0
                  ORDER BY c.id ASC, i.sort_order ASC, i.id ASC"
            );
            $stmt->execute([$ticketId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * What this one checklist's gate actually is, once the company setting has
     * had its say.
     *
     * Two dials, and they only ever tighten:
     *
     *   the template   Standard (warn and record) or Critical (block), chosen
     *                  by whoever wrote the procedure, because they are the one
     *                  who knows whether skipping a step is a nuisance or a
     *                  compliance breach.
     *   the company    'per_template' honours that choice; 'block_all' overrides
     *                  every template and blocks the lot.
     *
     * There is deliberately no company setting that loosens a Critical template
     * to a warning. An operator who wants that should edit the template, where
     * the change is visible to the people following the procedure.
     *
     * 🔑 Strictest wins, and a ticket carrying several checklists is resolved a
     * checklist at a time by assertClosureAllowed() — so one Critical checklist
     * blocks the close even if three Standard ones would have warned.
     *
     * @param string $mode  the checklist's own closure_mode, 'block' (Critical) or 'warn' (Standard); anything else reads as warn
     */
    public static function effectiveClosureMode(PDO $conn, string $mode, ?int $tenantId = null): string
    {
        if (function_exists('ticketChecklistClosureMode')
            && ticketChecklistClosureMode($conn, $tenantId) === 'block_all') {
            return 'block';
        }

        return $mode === 'block' ? 'block' : 'warn';
    }

    /**
     * Refuse the closure outright where the gate is a block.
     *
     * 🔴 MUST be called BEFORE the status is written. Called after, a blocked
     * close would throw having already closed the ticket - the caller sees an
     * error and the database disagrees with it.
     *
     * Enforced here rather than in the browser so the REST API, bulk actions and
     * automation obey whichever the operator chose; that is the whole reason the
     * rule left inbox.js.
     *
     * Two separate refusals live here, and they answer different questions:
     * whether this ticket may close with steps outstanding, and whether it may
     * close with no checklist at all.
     */
    public static function assertClosureAllowed(PDO $conn, int $ticketId, ?int $tenantId = null): void
    {
        // GH #217. Checklists switched off for the install hold up nothing: nobody
        // can attach the checklist a "block" rule asks for, or tick its steps, so
        // honouring the rule would leave tickets that can never be closed.
        if (function_exists('moduleIsEnabled') && !moduleIsEnabled($conn, 'checklists')) return;

        // Nothing attached: the only question is whether this install insists
        // on a checklist. 'warn' is not decided here - a warning is the absence
        // of a refusal, and it is recorded by recordClosureOverride() after the
        // close, exactly as an outstanding-steps override is.
        if (!self::hasAttachedChecklists($conn, $ticketId)) {
            if (function_exists('ticketChecklistEmptyClosureMode')
                && ticketChecklistEmptyClosureMode($conn, $tenantId) === 'block') {
                throw new ServiceError(
                    'validation',
                    'no_checklist_attached',
                    'This ticket cannot be closed until a checklist is attached to it.'
                );
            }
            return;
        }

        $outstanding = self::outstandingMandatorySteps($conn, $ticketId);
        if (!$outstanding) return;

        // Resolve per checklist, not per step: the mode lives on the checklist,
        // so resolving it once per step would ask the same question repeatedly
        // and read the company setting once per outstanding step.
        $blocking  = [];
        $modeCache = [];
        foreach ($outstanding as $row) {
            $checklist = $row['checklist'] !== '' ? $row['checklist'] : 'Checklist';
            if (!isset($modeCache[$checklist])) {
                $modeCache[$checklist] = self::effectiveClosureMode(
                    $conn, (string)($row['closure_mode'] ?? 'warn'), $tenantId
                );
            }
            if ($modeCache[$checklist] === 'block') {
                $blocking[$checklist][] = $row['step'];
            }
        }
        if (!$blocking) return;

        $parts = [];
        foreach ($blocking as $checklist => $steps) {
            $parts[] = $checklist . ' (' . implode(', ', $steps) . ')';
        }

        throw new ServiceError(
            'validation',
            'mandatory_steps_outstanding',
            'This ticket cannot be closed until its mandatory checklist steps are complete: '
                . implode('; ', $parts)
        );
    }

    /**
     * Called by TicketsService when a ticket moves to a closed status.
     *
     * Records what was skipped rather than refusing it — see the header. Returns
     * the steps that were outstanding so the caller can tell the analyst what was
     * skipped; empty array = a clean close, or a close this install does not care
     * to record.
     *
     * @return array<int, array{checklist: string, step: string, item_id: int}>
     */

    public static function recordClosureOverride(
        PDO $conn,
        ActorContext $ctx,
        int $ticketId,
        ?int $tenantId = null
    ): array {
        // Closed with nothing attached. Only worth a note where the operator
        // asked to be told about it - on an install that allows it, a note on
        // every close is noise that hides the ones that matter.
        //
        // ⚠️ $tenantId, not null. assertClosureAllowed() resolves this setting
        // per company, so reading it install-wide here would let the two halves
        // of one rule disagree: company A blocks while the audit trail is
        // written against the default.
        if (!self::hasAttachedChecklists($conn, $ticketId)) {
            if (function_exists('ticketChecklistEmptyClosureMode')
                && ticketChecklistEmptyClosureMode($conn, $tenantId) === 'warn') {
                self::writeClosureNote(
                    $conn,
                    $ctx,
                    $ticketId,
                    "⚠️ Ticket closed with no checklist attached.\n" . self::closedBy($ctx)
                );
            }
            return [];
        }

        $outstanding = self::outstandingMandatorySteps($conn, $ticketId);
        if (!$outstanding) return [];

        $list = implode("\n", array_map(
            fn($r) => '  • ' . $r['checklist'] . ' — ' . $r['step'],
            $outstanding
        ));
        self::writeClosureNote(
            $conn,
            $ctx,
            $ticketId,
            "⚠️ Ticket closed with " . count($outstanding) . " mandatory checklist step(s) outstanding.\n"
                . self::closedBy($ctx) . "\n\n" . $list
        );

        return $outstanding;
    }

    /** "Closed by Jane Smith via the web interface." — one phrasing, used by both notes. */
    private static function closedBy(ActorContext $ctx): string
    {
        if ($ctx->source === 'workflow') return 'Closed by a workflow.';

        $who = $ctx->actorName !== '' ? $ctx->actorName : ('analyst #' . $ctx->actorId);
        $via = $ctx->source === 'api' ? 'the API' : 'the web interface';
        return "Closed by {$who} via {$via}.";
    }

    /**
     * Write one closure-audit entry, wherever it belongs.
     *
     * 🔴 A WORKFLOW MUST NOT NAME AN ANALYST. `analyst_id` has a foreign key to
     * `analysts` (and was NOT NULL until GH #281 made NULL mean System), and a
     * workflow has no analyst behind it. Naming any id there does one of two bad things: on an install where that
     * analyst does not exist the insert throws and the audit entry is lost, and
     * on an install where they do exist it puts a real person's name against an
     * action they did not take. So an automated close is recorded in
     * `ticket_audit` with a NULL analyst, which is where the workflow engine's
     * own notes go and where the timeline already knows to look for them.
     *
     * Both notes go through here so that reasoning cannot be missed by the next
     * person adding a third kind of closure note.
     *
     * UTC at rest (GH #126).
     *
     * @param string $note  the note text to record against the ticket
     */
    private static function writeClosureNote(PDO $conn, ActorContext $ctx, int $ticketId, string $note): void
    {
        try {
            if ($ctx->actorId > 0) {
                $conn->prepare(
                    "INSERT INTO ticket_notes (ticket_id, analyst_id, note_text, is_internal, created_datetime)
                     VALUES (?, ?, ?, 1, UTC_TIMESTAMP())"
                )->execute([$ticketId, $ctx->actorId, $note]);
            } else {
                $conn->prepare(
                    "INSERT INTO ticket_audit (ticket_id, analyst_id, field_name, old_value, new_value, created_datetime)
                     VALUES (?, NULL, 'Workflow Note', NULL, ?, UTC_TIMESTAMP())"
                )->execute([$ticketId, $note]);
            }
        } catch (Throwable $e) {
            // A failed audit note must not block the close it is describing, but
            // it must not pass silently either.
            error_log('[checklists] could not record closure override for ticket '
                . $ticketId . ': ' . $e->getMessage());
        }
    }

    // ======================================================================
    //  Lookup lists — checklist categories and suggested roles
    //
    //  Two tables with identical shape (id, name) and identical rules, so one
    //  pair of methods serves both rather than four near-identical copies. The
    //  table is chosen from a whitelist, never from caller input, so `$kind`
    //  can never reach SQL.
    // ======================================================================

    private const LOOKUPS = [
        'category' => ['table' => 'checklist_categories', 'label' => 'Category'],
        'role'     => ['table' => 'checklist_roles',      'label' => 'Role'],
    ];

    /**
     * The table and label of a checklist settings list; throws for an unknown list.
     *
     * @param string $kind  'category' or 'role'
     */
    private static function lookup(string $kind): array
    {
        if (!isset(self::LOOKUPS[$kind])) {
            throw new ServiceError('validation', 'invalid_field', 'Unknown list: ' . $kind);
        }
        return self::LOOKUPS[$kind];
    }

    /**
     * Create (no id) or rename (id present) a category or role. Returns the id.
     *
     * Renaming matters more than it looks: templates store their category as
     * TEXT (`checklist_templates.category`) and steps store their suggested role
     * the same way, so the rows have to be carried across with the rename or the
     * list silently orphans every template that used the old name.
     *
     * @param string $kind  'category' or 'role'
     * @param array $in  id (absent or 0 = new) and name (up to 100 characters)
     */
    public static function saveLookup(PDO $conn, ActorContext $ctx, string $kind, array $in): int
    {
        ['table' => $table, 'label' => $label] = self::lookup($kind);

        $name = trim((string)($in['name'] ?? ''));
        if ($name === '')            throw new ServiceError('validation', 'missing_field', $label . ' name is required.');
        if (mb_strlen($name) > 100)  throw new ServiceError('validation', 'invalid_field', $label . ' name is too long (100 characters maximum).');

        $id = isset($in['id']) ? (int)$in['id'] : 0;

        // Names are UNIQUE in both tables; catch the clash here so the caller
        // gets a sentence rather than a driver error.
        $clash = $conn->prepare("SELECT id FROM `$table` WHERE name = ? AND id <> ? LIMIT 1");
        $clash->execute([$name, $id]);
        if ($clash->fetchColumn() !== false) {
            throw new ServiceError('conflict', 'duplicate', $label . ' "' . $name . '" already exists.');
        }

        if ($id > 0) {
            $cur = $conn->prepare("SELECT name FROM `$table` WHERE id = ? LIMIT 1");
            $cur->execute([$id]);
            $oldName = $cur->fetchColumn();
            if ($oldName === false) throw new ServiceError('not_found', 'not_found', $label . ' not found.');

            if ($oldName !== $name) {
                $conn->beginTransaction();
                try {
                    $conn->prepare("UPDATE `$table` SET name = ? WHERE id = ?")->execute([$name, $id]);
                    // Carry the rows that reference it by name.
                    if ($kind === 'category') {
                        $conn->prepare("UPDATE checklist_templates SET category = ? WHERE category = ?")->execute([$name, $oldName]);
                    } else {
                        $conn->prepare("UPDATE checklist_template_items SET suggested_role = ? WHERE suggested_role = ?")->execute([$name, $oldName]);
                        $conn->prepare("UPDATE ticket_checklist_items  SET suggested_role = ? WHERE suggested_role = ?")->execute([$name, $oldName]);
                    }
                    $conn->commit();
                } catch (Throwable $e) {
                    $conn->rollBack();
                    throw $e;
                }
            }
            return $id;
        }

        $conn->prepare("INSERT INTO `$table` (name, created_datetime) VALUES (?, UTC_TIMESTAMP())")->execute([$name]);
        return (int)$conn->lastInsertId();
    }

    /**
     * Delete a category or role.
     *
     * The rows that reference it by name are left alone deliberately: a template
     * filed under a category you retire keeps saying what it said. Losing the
     * list entry should not silently re-file somebody's SOP.
     *
     * @param string $kind  'category' or 'role'
     * @param int $id  the checklist_categories or checklist_roles row to delete
     */
    public static function deleteLookup(PDO $conn, ActorContext $ctx, string $kind, int $id): void
    {
        ['table' => $table, 'label' => $label] = self::lookup($kind);
        if ($id <= 0) throw new ServiceError('validation', 'invalid_field', 'A valid id is required.');

        $stmt = $conn->prepare("DELETE FROM `$table` WHERE id = ?");
        $stmt->execute([$id]);
        if ($stmt->rowCount() === 0) {
            throw new ServiceError('not_found', 'not_found', $label . ' not found.');
        }
    }

    // ======================================================================
    //  Checklists on TASKS (discussion #138, round three)
    //
    //  Sandy asked whether checklists should reach Tasks, and thought probably
    //  not: subtasks already split work between people with due dates, and two
    //  similar things side by side invite teams to use them interchangeably.
    //  Ed's answer was to build it behind a switch - Tasks → Settings →
    //  Checklists, OFF by default, the same shape as time recording - so a team
    //  that would be confused by both simply never sees it.
    //
    //  🔑 The rules are the TICKET rules, not new ones, so the two cannot drift:
    //   - a checklist is a COPY of the template's steps, taken when attached;
    //   - Standard (warn) records who completed the task with steps outstanding,
    //     Critical (block) refuses - resolved by effectiveClosureMode(), so the
    //     company's "block all" setting reaches tasks too (a zero-tolerance
    //     company that blocked tickets but let tasks through would have a gap);
    //   - the gate is enforced HERE, called from every path that completes a
    //     task (TasksService::updateTask / moveTask, reorder.php,
    //     toggle_subtask.php), never in the browser alone.
    //
    //  ⚠️ What tasks deliberately do NOT get: the "no checklist attached" rule.
    //  On tickets it is a default-off switch for procedure-led desks; on tasks it
    //  would gate every to-do on the board, which nobody would want.
    //
    //  🔴 Switched OFF means OFF: nothing shown and NOTHING ENFORCED, even where
    //  checklists were attached while it was on. A rule the admin has turned off
    //  must not keep refusing completions from behind a section nobody can see.
    // ======================================================================

    /** Is Tasks → Settings → Checklists switched on? Off by default; safe before DB Verification. */
    public static function tasksEnabled(PDO $conn): bool
    {
        try {
            $st = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'tasks_checklists_enabled'");
            $st->execute();
            return (string)$st->fetchColumn() === '1';
        } catch (Throwable) {
            return false;
        }
    }

    /** The task's company for the gate (NULL = the Default company, read as the install default). */
    public static function taskTenantId(PDO $conn, int $taskId): ?int
    {
        try {
            $st = $conn->prepare("SELECT tenant_id FROM tasks WHERE id = ?");
            $st->execute([$taskId]);
            $v = $st->fetchColumn();
            return ($v === false || $v === null) ? null : (int)$v;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The task's checklists with their steps and progress, each carrying the
     * gate it will ACTUALLY apply (template mode + company setting), resolved
     * here so the padlock in the panel is the lock the server enforces.
     */
    public static function taskChecklists(PDO $conn, int $taskId): array
    {
        $tenantId = self::taskTenantId($conn, $taskId);
        $st = $conn->prepare("SELECT id, template_id, title, closure_mode, sort_order, created_datetime
                                FROM task_checklists WHERE task_id = ? ORDER BY sort_order ASC, id ASC");
        $st->execute([$taskId]);
        $lists = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

        try {
            $conn->query("SELECT result_mode FROM task_checklist_items LIMIT 0");
            $resultCols = ', result_mode, result, exception_note';
        } catch (Throwable) {
            $resultCols = '';   // before Database Verification has added #181's columns
        }
        if (self::hasStepDescription($conn)) $resultCols .= ', description';   // GH #190
        $items = $conn->prepare("SELECT id, title, suggested_role, is_mandatory, requires_input, input_placeholder,
                                        response_value, is_completed, completed_by_id, completed_by_name, completed_datetime{$resultCols}
                                   FROM task_checklist_items WHERE task_checklist_id = ?
                                  ORDER BY sort_order ASC, id ASC");
        foreach ($lists as &$l) {
            $l['closure_mode'] = $l['closure_mode'] ?: 'warn';
            $l['effective_closure_mode'] = self::effectiveClosureMode($conn, $l['closure_mode'], $tenantId);
            $items->execute([(int)$l['id']]);
            $l['items'] = $items->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $done = count(array_filter($l['items'], fn($i) => !empty($i['is_completed'])));
            $l['total_items'] = count($l['items']);
            $l['completed_items'] = $done;
            $l['percent'] = $l['total_items'] > 0 ? (int)round($done / $l['total_items'] * 100) : 0;
        }
        unset($l);
        return $lists;
    }

    /** Templates offered for a task: scope task or both, active, with the gate each would apply. */
    public static function templatesForTask(PDO $conn, int $taskId): array
    {
        $tenantId = self::taskTenantId($conn, $taskId);
        $rows = $conn->query("SELECT id, title, category, description, closure_mode FROM checklist_templates
                               WHERE scope IN ('task','both') AND (is_active = 1 OR is_active IS NULL)
                               ORDER BY category ASC, title ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as &$r) {
            $r['closure_mode'] = $r['closure_mode'] ?: 'warn';
            $r['effective_closure_mode'] = self::effectiveClosureMode($conn, $r['closure_mode'], $tenantId);
        }
        unset($r);
        return $rows;
    }

    /**
     * Attach a template to a task: copy its title, gate and steps. Returns the new id.
     *
     * The copy is the point, exactly as on a ticket: rewriting a procedure must
     * not change a task already half-way through it.
     *
     * @param int $templateId  the checklist_templates row to copy; its scope must be 'task' or 'both'
     */
    public static function attachTemplateToTask(PDO $conn, ActorContext $ctx, int $taskId, int $templateId): int
    {
        self::assertTaskChecklistChangeAllowed($conn, $taskId);
        $tpl = $conn->prepare("SELECT title, closure_mode, scope FROM checklist_templates WHERE id = ?");
        $tpl->execute([$templateId]);
        $t = $tpl->fetch(PDO::FETCH_ASSOC);
        if (!$t) {
            throw new ServiceError('not_found', 'not_found', 'Checklist not found.');
        }
        if (!in_array((string)$t['scope'], ['task', 'both'], true)) {
            throw new ServiceError('validation', 'invalid_field', 'That checklist is for tickets only.');
        }
        $id = self::copyTemplateToTask($conn, $taskId, $templateId, (string)$t['title'],
            (string)$t['closure_mode'], $ctx->actorId > 0 ? $ctx->actorId : null);
        require_once __DIR__ . '/task_history.php';
        TaskHistory::record($conn, $taskId, 'checklist_attached', $ctx, null, (string)$t['title'],
            ['checklist_id' => $id, 'template_id' => $templateId, 'template_version' => self::templateVersionNo($conn, $templateId),
             'after_completion' => self::taskIsClosed($conn, $taskId)]);
        return $id;
    }

    /**
     * The one place a task checklist is written: parent, then its steps, in one transaction.
     *
     * @param int $templateId  the checklist_templates row whose steps are copied
     * @param string $title  the checklist's title as shown on the task
     * @param string $closureMode  'block' or anything else for warn
     * @param ?int $createdBy  the analyst who attached it, or null for none
     */
    private static function copyTemplateToTask(PDO $conn, int $taskId, int $templateId, string $title,
                                               string $closureMode, ?int $createdBy): int
    {
        $own = !$conn->inTransaction();
        if ($own) $conn->beginTransaction();
        try {
            $conn->prepare("INSERT INTO task_checklists (task_id, template_id, title, closure_mode, sort_order, created_by_id, created_datetime)
                            VALUES (?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())")
                 ->execute([$taskId, $templateId, $title, $closureMode === 'block' ? 'block' : 'warn',
                            self::nextTaskChecklistOrder($conn, $taskId), $createdBy]);
            $chkId = (int)$conn->lastInsertId();
            // #181: which version of the procedure this copy is, so the report shows
            // the procedure as it stood for this occurrence.
            $ver = self::templateVersionNo($conn, $templateId);
            if ($ver !== null) {
                $conn->prepare("UPDATE task_checklists SET template_version = ? WHERE id = ?")->execute([$ver, $chkId]);
            }

            $hasResult = self::hasResultColumns($conn);
            // GH #190: the step's description comes along, so a task's step reads in place.
            $hasDesc = self::hasStepDescription($conn);
            $steps = $conn->prepare("SELECT title, description, suggested_role, is_mandatory, requires_input, input_placeholder, sort_order"
                                    . ($hasResult ? ', result_mode' : '') . "
                                       FROM checklist_template_items WHERE template_id = ? ORDER BY sort_order ASC, id ASC");
            $steps->execute([$templateId]);
            $ins = $conn->prepare("INSERT INTO task_checklist_items
                                     (task_checklist_id, title, suggested_role, is_mandatory, requires_input, input_placeholder, sort_order"
                                   . ($hasResult ? ', result_mode' : '') . ($hasDesc ? ', description' : '') . ")
                                   VALUES (?, ?, ?, ?, ?, ?, ?" . ($hasResult ? ', ?' : '') . ($hasDesc ? ', ?' : '') . ")");
            foreach ($steps->fetchAll(PDO::FETCH_ASSOC) as $i => $s) {
                $vals = [$chkId, $s['title'], $s['suggested_role'] ?: null, !empty($s['is_mandatory']) ? 1 : 0,
                         !empty($s['requires_input']) ? 1 : 0, $s['input_placeholder'] ?: null,
                         $s['sort_order'] ?? ($i + 1)];
                if ($hasResult) $vals[] = ($s['result_mode'] ?? 'none') === 'pass_fail' ? 'pass_fail' : 'none';
                if ($hasDesc) $vals[] = ($s['description'] ?? '') !== '' ? $s['description'] : null;
                $ins->execute($vals);
            }
            if ($own) $conn->commit();
            return $chkId;
        } catch (Throwable $e) {
            if ($own && $conn->inTransaction()) $conn->rollBack();
            throw $e;
        }
    }

    /** A newly attached checklist goes below the ones already on the task. */
    private static function nextTaskChecklistOrder(PDO $conn, int $taskId): int
    {
        $st = $conn->prepare("SELECT COALESCE(MAX(sort_order), 0) + 1 FROM task_checklists WHERE task_id = ?");
        $st->execute([$taskId]);
        return (int)$st->fetchColumn();
    }

    /**
     * Put a task's checklists in a new order. $ids is the whole list, top first.
     * Each UPDATE is pinned to the task, so an id from another task matches
     * nothing - the caller gates on $taskId and that gate then covers every row.
     *
     * @param array $ids  every task_checklists id on the task, top first
     */
    public static function reorderTaskChecklists(PDO $conn, int $taskId, array $ids): void
    {
        $st = $conn->prepare("UPDATE task_checklists SET sort_order = ? WHERE id = ? AND task_id = ?");
        foreach (array_values($ids) as $i => $id) {
            $st->execute([$i + 1, (int)$id, $taskId]);
        }
    }

    /**
     * The same for the steps inside one checklist, pinned to that checklist.
     *
     * @param int $checklistId  the task_checklists row whose steps are reordered
     * @param array $ids  every task_checklist_items id in it, top first
     */
    public static function reorderTaskChecklistItems(PDO $conn, int $checklistId, array $ids): void
    {
        $st = $conn->prepare("UPDATE task_checklist_items SET sort_order = ? WHERE id = ? AND task_checklist_id = ?");
        foreach (array_values($ids) as $i => $id) {
            $st->execute([$i + 1, (int)$id, $checklistId]);
        }
    }

    /**
     * Which task does this step / checklist belong to? 0 if neither exists. Callers gate on the task.
     *
     * @param int $itemId  a task_checklist_items id
     */
    public static function taskIdForItem(PDO $conn, int $itemId): int
    {
        $st = $conn->prepare("SELECT c.task_id FROM task_checklist_items i
                                JOIN task_checklists c ON c.id = i.task_checklist_id WHERE i.id = ?");
        $st->execute([$itemId]);
        return (int)($st->fetchColumn() ?: 0);
    }
    /**
     * The task a checklist is attached to, or 0.
     *
     * @param int $checklistId  a task_checklists id
     */
    public static function taskIdForChecklist(PDO $conn, int $checklistId): int
    {
        $st = $conn->prepare("SELECT task_id FROM task_checklists WHERE id = ?");
        $st->execute([$checklistId]);
        return (int)($st->fetchColumn() ?: 0);
    }

    /**
     * Tick or untick a step. A step that asks for a value cannot be ticked
     * without one - enforced here, not only in the panel, because a tick with an
     * empty answer is a record that says something was checked when it was not.
     *
     * UTC at rest (GH #126). Returns the stored row.
     *
     * @param int $itemId  the task_checklist_items row to tick or untick
     * @param bool $completed  true to tick, false to untick (which clears the answer)
     * @param ?string $value  the answer for a step that asks for one; ignored on other steps
     */
    public static function toggleTaskItem(PDO $conn, ActorContext $ctx, int $itemId, bool $completed, ?string $value): array
    {
        $row = self::taskItemRow($conn, $itemId);
        if (!$row) {
            throw new ServiceError('not_found', 'not_found', 'Step not found.');
        }
        self::assertTaskChecklistChangeAllowed($conn, (int)$row['task_id']);
        // #181: a step that asks for a RESULT is ticked by recording one (Pass,
        // Fail or Not applicable) - see setTaskItemResult(). Unticking it clears
        // the result too, and both go on the step's history.
        if ($completed && ($row['result_mode'] ?? 'none') === 'pass_fail' && empty($row['result'])) {
            throw new ServiceError('validation', 'missing_field', 'Record a result for this step - Pass, Fail or Not applicable.');
        }
        // A value is only kept on a step that asks for one - a stray value on a
        // plain tick would show up as an answer to a question nobody asked.
        $value = ((int)$row['requires_input'] === 1 && $value !== null) ? trim($value) : null;
        if ($completed && (int)$row['requires_input'] === 1 && ($value === null || $value === '')) {
            throw new ServiceError('validation', 'missing_field', 'This step needs a value before it can be ticked.');
        }
        if ($completed) {
            $conn->prepare("UPDATE task_checklist_items
                               SET is_completed = 1, response_value = ?, completed_by_id = ?,
                                   completed_by_name = ?, completed_datetime = UTC_TIMESTAMP()
                             WHERE id = ?")
                 ->execute([$value !== '' ? $value : null, $ctx->actorId > 0 ? $ctx->actorId : null,
                            $ctx->actorName !== '' ? $ctx->actorName : null, $itemId]);
        } else {
            // Unticking clears the step - but no longer erases WHO ticked it and
            // when: the step's history below keeps that (#181).
            $conn->prepare("UPDATE task_checklist_items
                               SET is_completed = 0, response_value = NULL, completed_by_id = NULL,
                                   completed_by_name = NULL, completed_datetime = NULL
                             WHERE id = ?")
                 ->execute([$itemId]);
            if (isset($row['result_mode'])) {
                $conn->prepare("UPDATE task_checklist_items SET result = NULL, exception_note = NULL WHERE id = ?")->execute([$itemId]);
            }
        }
        $after = self::taskItemRow($conn, $itemId) ?: [];
        $action = $completed ? (!empty($row['is_completed']) ? 'value' : 'ticked') : 'unticked';
        self::recordStepEvent($conn, $ctx, $row, $after, $action);
        return array_intersect_key($after, array_flip(['id', 'is_completed', 'response_value', 'completed_by_id',
            'completed_by_name', 'completed_datetime', 'result', 'exception_note']));
    }

    /**
     * Record a step's RESULT (#181): Pass, Fail or Not applicable, on a step whose template asks for
     * one. Recording a result ticks the step; Fail needs an exception note, because a failed control
     * with no word on what went wrong is not evidence of anything. The task can still be completed
     * with a failed step - that is the point: the report shows it as an exception, it does not hide it.
     *
     * @param string $result  pass, fail or na
     * @param ?string $note  the exception note - required for fail, kept for the others if given
     */
    public static function setTaskItemResult(PDO $conn, ActorContext $ctx, int $itemId, string $result, ?string $note): array
    {
        $row = self::taskItemRow($conn, $itemId);
        if (!$row) {
            throw new ServiceError('not_found', 'not_found', 'Step not found.');
        }
        if (($row['result_mode'] ?? 'none') !== 'pass_fail') {
            throw new ServiceError('validation', 'invalid_field', 'This step does not ask for a result.');
        }
        if (!in_array($result, ['pass', 'fail', 'na'], true)) {
            throw new ServiceError('validation', 'invalid_field', 'A result is pass, fail or na.');
        }
        $note = $note !== null ? trim($note) : null;
        if ($result === 'fail' && ($note === null || $note === '')) {
            throw new ServiceError('validation', 'missing_field', 'Say what failed - a failed step needs an exception note.');
        }
        self::assertTaskChecklistChangeAllowed($conn, (int)$row['task_id']);
        $conn->prepare("UPDATE task_checklist_items
                           SET result = ?, exception_note = ?, is_completed = 1,
                               completed_by_id = ?, completed_by_name = ?, completed_datetime = UTC_TIMESTAMP()
                         WHERE id = ?")
             ->execute([$result, $note !== '' ? $note : null, $ctx->actorId > 0 ? $ctx->actorId : null,
                        $ctx->actorName !== '' ? $ctx->actorName : null, $itemId]);
        $after = self::taskItemRow($conn, $itemId) ?: [];
        self::recordStepEvent($conn, $ctx, $row, $after, 'result');
        return $after;
    }

    /** A task checklist step with its checklist, task and the task's closed state - or null. */
    private static function taskItemRow(PDO $conn, int $itemId): ?array
    {
        $extra = self::hasResultColumns($conn) ? ', i.result_mode, i.result, i.exception_note' : '';
        $st = $conn->prepare("SELECT i.id, i.title, i.requires_input, i.response_value, i.is_completed, i.completed_by_id,
                                     i.completed_by_name, i.completed_datetime{$extra},
                                     c.id AS checklist_id, c.title AS checklist_title, c.task_id
                                FROM task_checklist_items i JOIN task_checklists c ON c.id = i.task_checklist_id
                               WHERE i.id = ?");
        $st->execute([$itemId]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * One row of a step's history (#181): who, when, before and after, and whether the task was
     * already complete. Never throws.
     *
     * @param array $before  taskItemRow() before the change
     * @param array $after  taskItemRow() after it
     * @param string $action  ticked, unticked, value or result
     */
    private static function recordStepEvent(PDO $conn, ActorContext $ctx, array $before, array $after, string $action): void
    {
        $pick = fn(array $r) => array_intersect_key($r, array_flip(['is_completed', 'response_value', 'completed_by_name',
            'completed_datetime', 'result', 'exception_note']));
        $afterJson = $pick($after);
        if ($ctx->source === 'api') $afterJson['via'] = 'api';   // GH #273: the history line says "via the API"
        try {
            $conn->prepare("INSERT INTO task_checklist_item_events (task_id, task_checklist_id, item_id, checklist_title, item_title,
                                   action, before_json, after_json, after_completion, analyst_id, actor_name, created_datetime)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())")
                 ->execute([(int)$before['task_id'], (int)$before['checklist_id'], (int)$before['id'], $before['checklist_title'],
                            $before['title'], $action, json_encode($pick($before)), json_encode($afterJson),
                            self::taskIsClosed($conn, (int)$before['task_id']) ? 1 : 0,
                            $ctx->actorId > 0 ? $ctx->actorId : null, $ctx->actorName !== '' ? $ctx->actorName : null]);
        } catch (Throwable $e) {
            error_log('[checklists] could not record a step change on item ' . ($before['id'] ?? '?') . ': ' . $e->getMessage());
        }
    }

    /**
     * Tasks -> Settings -> Evidence: "Changing a completed task's checklist". By default a change is
     * allowed and recorded (flagged in the report as made after completion); set to refuse, it is
     * refused until the task is reopened (#181 decision 1).
     *
     * @param int $taskId  the task whose checklist is about to change
     */
    public static function assertTaskChecklistChangeAllowed(PDO $conn, int $taskId): void
    {
        if (!self::taskIsClosed($conn, $taskId)) return;
        try {
            $s = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'tasks_evidence_completed_checklist'");
            $s->execute();
            if ((string)$s->fetchColumn() !== 'refuse') return;
        } catch (Throwable) {
            return;
        }
        throw new ServiceError('validation', 'checklist_locked',
            function_exists('t') ? t('tasks.evidence.checklist_locked') : 'This task is complete, so its checklist is locked. Reopen the task to change it.');
    }

    /** Is the task in a closed status? */
    private static function taskIsClosed(PDO $conn, int $taskId): bool
    {
        try {
            $s = $conn->prepare("SELECT ts.is_closed FROM tasks t JOIN task_statuses ts ON ts.id = t.status_id WHERE t.id = ?");
            $s->execute([$taskId]);
            return (bool)$s->fetchColumn();
        } catch (Throwable) {
            return false;
        }
    }

    /** Has GH #190's step description column been added yet? Cached per request. */
    private static function hasStepDescription(PDO $conn): bool
    {
        static $has = null;
        if ($has !== null) return $has;
        try {
            $conn->query("SELECT description FROM task_checklist_items LIMIT 0");
            return $has = true;
        } catch (Throwable) {
            return $has = false;
        }
    }

    /** Have #181's result columns been added yet? Cached per request. */
    private static function hasResultColumns(PDO $conn): bool
    {
        static $has = null;
        if ($has !== null) return $has;
        try {
            $conn->query("SELECT result_mode FROM task_checklist_items LIMIT 0");
            $conn->query("SELECT result_mode FROM checklist_template_items LIMIT 0");
            return $has = true;
        } catch (Throwable) {
            return $has = false;
        }
    }

    /** A template's current version number, or null (never versioned, or before Database Verification). */
    private static function templateVersionNo(PDO $conn, int $templateId): ?int
    {
        try {
            $s = $conn->prepare("SELECT version_no FROM checklist_templates WHERE id = ?");
            $s->execute([$templateId]);
            $v = (int)$s->fetchColumn();
            return $v > 0 ? $v : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Save a version of a checklist template (#181): a snapshot of the template and its steps as they now
     * stand, numbered one after the last. Called after every save of a template, and once by Database
     * Verification for templates that existed before. The version outlives the template.
     *
     * @param ?int $analystId  who saved it; null for Database Verification's baseline
     */
    public static function recordTemplateVersion(PDO $conn, int $templateId, ?int $analystId): ?int
    {
        try {
            $t = $conn->prepare("SELECT id, title, category, scope, closure_mode, description FROM checklist_templates WHERE id = ?");
            $t->execute([$templateId]);
            $tpl = $t->fetch(PDO::FETCH_ASSOC);
            if (!$tpl) return null;
            $resCol = self::hasResultColumns($conn) ? ', result_mode' : '';
            // description: the version keeps what each step said (#271 made it editable).
            $i = $conn->prepare("SELECT title, description, suggested_role, is_mandatory, requires_input, input_placeholder, sort_order{$resCol}
                                   FROM checklist_template_items WHERE template_id = ? ORDER BY sort_order, id");
            $i->execute([$templateId]);
            $tpl['items'] = $i->fetchAll(PDO::FETCH_ASSOC);
            $n = $conn->prepare("SELECT COALESCE(MAX(version_no), 0) + 1 FROM checklist_template_versions WHERE template_id = ?");
            $n->execute([$templateId]);
            $ver = (int)$n->fetchColumn();
            $conn->prepare("INSERT INTO checklist_template_versions (template_id, version_no, snapshot_json, analyst_id, created_datetime)
                            VALUES (?, ?, ?, ?, UTC_TIMESTAMP())")
                 ->execute([$templateId, $ver, json_encode($tpl, JSON_UNESCAPED_UNICODE), $analystId]);
            $conn->prepare("UPDATE checklist_templates SET version_no = ? WHERE id = ?")->execute([$ver, $templateId]);
            return $ver;
        } catch (Throwable $e) {
            error_log('[checklists] could not version template ' . $templateId . ': ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Remove one checklist from a task: its steps first (no FK to rely on), then it.
     *
     * @param int $checklistId  the task_checklists row to remove, with its steps
     */
    public static function removeTaskChecklist(PDO $conn, int $checklistId, ?ActorContext $ctx = null): void
    {
        // #181: a checklist removed from a task takes its ticks, values and results
        // with it, so the removal is recorded WITH them, on the task's history.
        $taskId = self::taskIdForChecklist($conn, $checklistId);
        if ($taskId > 0) {
            self::assertTaskChecklistChangeAllowed($conn, $taskId);
            try {
                $c = $conn->prepare("SELECT * FROM task_checklists WHERE id = ?");
                $c->execute([$checklistId]);
                $list = $c->fetch(PDO::FETCH_ASSOC) ?: [];
                $i = $conn->prepare("SELECT * FROM task_checklist_items WHERE task_checklist_id = ? ORDER BY sort_order, id");
                $i->execute([$checklistId]);
                $list['items'] = $i->fetchAll(PDO::FETCH_ASSOC);
                require_once __DIR__ . '/task_history.php';
                TaskHistory::record($conn, $taskId, 'checklist_removed', $ctx, $list['title'] ?? null, null,
                    ['checklist' => $list, 'after_completion' => self::taskIsClosed($conn, $taskId)]);
            } catch (Throwable $e) {
                error_log('[checklists] could not record removing checklist ' . $checklistId . ': ' . $e->getMessage());
            }
        }
        $own = !$conn->inTransaction();
        if ($own) $conn->beginTransaction();
        try {
            $conn->prepare("DELETE FROM task_checklist_items WHERE task_checklist_id = ?")->execute([$checklistId]);
            $conn->prepare("DELETE FROM task_checklists WHERE id = ?")->execute([$checklistId]);
            if ($own) $conn->commit();
        } catch (Throwable $e) {
            if ($own && $conn->inTransaction()) $conn->rollBack();
            throw $e;
        }
    }

    /**
     * Remove every checklist from these tasks - for TasksService::deleteTask,
     * which deletes a whole subtask tree. Children explicitly, then parents;
     * tolerant of the tables not existing yet.
     *
     * @param int[] $taskIds  the tasks being deleted; non-integers and zeros are dropped
     */
    public static function deleteForTasks(PDO $conn, array $taskIds): void
    {
        $taskIds = array_values(array_filter(array_map('intval', $taskIds)));
        if (!$taskIds) return;
        $in = implode(',', array_fill(0, count($taskIds), '?'));
        try {
            $conn->prepare("DELETE i FROM task_checklist_items i
                              JOIN task_checklists c ON c.id = i.task_checklist_id
                             WHERE c.task_id IN ($in)")->execute($taskIds);
            $conn->prepare("DELETE FROM task_checklists WHERE task_id IN ($in)")->execute($taskIds);
        } catch (Throwable) {
            // tables not there yet on a part-upgraded install: nothing to remove
        }
    }

    /** Mandatory steps still outstanding on a task (same shape as the ticket version). */
    public static function taskOutstandingMandatorySteps(PDO $conn, int $taskId): array
    {
        if ($taskId <= 0) return [];
        try {
            $st = $conn->prepare(
                "SELECT i.id AS item_id, i.title AS step, c.title AS checklist, c.closure_mode
                   FROM task_checklist_items i
                   JOIN task_checklists c ON c.id = i.task_checklist_id
                  WHERE c.task_id = ? AND i.is_mandatory = 1 AND i.is_completed = 0
                  ORDER BY c.id ASC, i.sort_order ASC, i.id ASC"
            );
            $st->execute([$taskId]);
            return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable) {
            return [];   // tables not created yet: completing a task must not start failing
        }
    }

    /**
     * What completing this task now would run into - for the panel and the
     * board to warn BEFORE they post. Read-only; the server still decides.
     *
     * @return array{blocking: array<string,string[]>, warning: array<string,string[]>}
     */
    public static function taskCompletionCheck(PDO $conn, int $taskId): array
    {
        $out = ['blocking' => [], 'warning' => []];
        $tenantId = self::taskTenantId($conn, $taskId);
        if (self::tasksEnabled($conn)) {
            foreach (self::taskOutstandingMandatorySteps($conn, $taskId) as $r) {
                $name = $r['checklist'] !== '' ? $r['checklist'] : 'Checklist';
                $mode = self::effectiveClosureMode($conn, (string)($r['closure_mode'] ?? 'warn'), $tenantId);
                $out[$mode === 'block' ? 'blocking' : 'warning'][$name][] = $r['step'];
            }
        }
        /* GH #190: required forms with no submission - the SAME gate, not a second
           one, so the board, the window, a subtask tick and the REST API all ask it.
           Warn / block per form, with the company's "block all" floor as for steps. */
        require_once __DIR__ . '/task_forms.php';
        foreach (TaskForms::outstanding($conn, $taskId) as $f) {
            $mode = self::effectiveClosureMode($conn, $f['mode'], $tenantId);
            $out[$mode === 'block' ? 'blocking' : 'warning'][(function_exists('t') ? t('tasks.forms.gate_name') : 'Forms')][] = $f['title'];
        }
        // GH #190 phase 3: open follow-ups - only when Settings -> Evidence says they matter
        // (allowed by default: the review can be complete while the remediation is open).
        require_once __DIR__ . '/task_follow_ups.php';
        foreach (TaskFollowUps::outstanding($conn, $taskId) as $f) {
            $out[$f['mode'] === 'block' ? 'blocking' : 'warning'][(function_exists('t') ? t('tasks.followups.gate_name') : 'Open follow-ups')][] = $f['label'];
        }
        return $out;
    }

    /**
     * Refuse completing the task where a Critical checklist has steps outstanding.
     *
     * 🔴 MUST be called BEFORE the status is written - see assertClosureAllowed().
     * Called from every path that completes a task; a new one must call it too.
     */
    public static function assertTaskCompletionAllowed(PDO $conn, int $taskId): void
    {
        $check = self::taskCompletionCheck($conn, $taskId);
        if (!$check['blocking']) return;
        $parts = [];
        foreach ($check['blocking'] as $checklist => $steps) {
            $parts[] = $checklist . ' (' . implode(', ', $steps) . ')';
        }
        throw new ServiceError('validation', 'mandatory_steps_outstanding',
            'This task cannot be completed until its required work is done: ' . implode('; ', $parts));
    }

    /**
     * Called AFTER a task has moved to a closed status: record any Standard
     * checklist steps it was completed without, on the task's own audit trail.
     *
     * task_audit, not a comment: its analyst_id is nullable, so a workflow or
     * an API key with no analyst behind it is recorded honestly as nobody,
     * instead of being stamped with a colleague's name (the round-two lesson,
     * see writeClosureNote()).
     */
    public static function recordTaskCompletionOverride(PDO $conn, ActorContext $ctx, int $taskId, bool $strict = false): void
    {
        $outstanding = self::tasksEnabled($conn) ? self::taskOutstandingMandatorySteps($conn, $taskId) : [];
        // GH #190: required forms it was completed without are recorded the same way.
        require_once __DIR__ . '/task_forms.php';
        $forms = TaskForms::outstanding($conn, $taskId);
        if (!$outstanding && !$forms) return;
        $parts = [];
        if ($outstanding) {
            $parts[] = count($outstanding) . ' mandatory checklist step(s) outstanding: '
                     . implode('; ', array_map(fn($r) => $r['checklist'] . ' - ' . $r['step'], $outstanding));
        }
        if ($forms) {
            $parts[] = count($forms) . ' required form(s) not submitted: ' . implode('; ', array_column($forms, 'title'));
        }
        $note = implode('. ', $parts) . '. ' . self::closedBy($ctx);
        try {
            $conn->prepare("INSERT INTO task_audit (task_id, analyst_id, field_name, old_value, new_value, source, created_datetime)
                            VALUES (?, ?, 'Completed with checklist steps outstanding', NULL, ?, ?, UTC_TIMESTAMP())")
                 ->execute([$taskId, $ctx->actorId > 0 ? $ctx->actorId : null, mb_substr($note, 0, 500),
                            // 'app' for the web interface, as the rest of task_audit writes it.
                            $ctx->source === 'ui' ? 'app' : substr($ctx->source, 0, 20)]);
        } catch (Throwable $e) {
            // Strict (inside the completion's transaction, GH #181): completing with work
            // outstanding is an exception the report must show - no completion without it.
            if ($strict) {
                require_once __DIR__ . '/task_history.php';
                throw TaskHistory::notRecorded('completion_override', $e);
            }
            error_log('[checklists] could not record task completion override for task ' . $taskId . ': ' . $e->getMessage());
        }
    }

    /**
     * Give a new occurrence of a recurring task fresh, unticked copies of the
     * checklists the finished one had (TaskRecurrence::createOccurrence).
     *
     * Re-copied from the TEMPLATE where it still exists, so a monthly check
     * picks up this month's version of the procedure; a checklist whose template
     * has since been deleted is copied from the finished task's own steps
     * instead, rather than silently vanishing from the series.
     *
     * @param int $fromTaskId  the finished occurrence whose checklists are copied
     * @param int $toTaskId  the new occurrence that receives fresh, unticked copies
     */
    public static function copyTaskChecklists(PDO $conn, int $fromTaskId, int $toTaskId): void
    {
        if (!self::tasksEnabled($conn)) return;
        try {
            $src = $conn->prepare("SELECT c.id, c.template_id, c.title, c.closure_mode, c.created_by_id,
                                          t.id AS tpl_exists, t.title AS tpl_title, t.closure_mode AS tpl_mode
                                     FROM task_checklists c
                                     LEFT JOIN checklist_templates t ON t.id = c.template_id
                                    WHERE c.task_id = ? ORDER BY c.sort_order ASC, c.id ASC");
            $src->execute([$fromTaskId]);
            foreach ($src->fetchAll(PDO::FETCH_ASSOC) as $c) {
                if ($c['tpl_exists']) {
                    self::copyTemplateToTask($conn, $toTaskId, (int)$c['template_id'], (string)$c['tpl_title'],
                        (string)$c['tpl_mode'], $c['created_by_id'] !== null ? (int)$c['created_by_id'] : null);
                    continue;
                }
                $conn->prepare("INSERT INTO task_checklists (task_id, template_id, title, closure_mode, sort_order, created_by_id, created_datetime)
                                VALUES (?, NULL, ?, ?, ?, ?, UTC_TIMESTAMP())")
                     ->execute([$toTaskId, $c['title'], $c['closure_mode'] ?: 'warn',
                                self::nextTaskChecklistOrder($conn, $toTaskId), $c['created_by_id']]);
                $newId = (int)$conn->lastInsertId();
                $rm = (self::hasResultColumns($conn) ? ', result_mode' : '') . (self::hasStepDescription($conn) ? ', description' : '');
                $conn->prepare("INSERT INTO task_checklist_items
                                  (task_checklist_id, title, suggested_role, is_mandatory, requires_input, input_placeholder, sort_order{$rm})
                                SELECT ?, title, suggested_role, is_mandatory, requires_input, input_placeholder, sort_order{$rm}
                                  FROM task_checklist_items WHERE task_checklist_id = ?")
                     ->execute([$newId, (int)$c['id']]);
            }
        } catch (Throwable $e) {
            // A recurrence must still be created if this fails.
            error_log('[checklists] could not copy checklists to recurring task ' . $toTaskId . ': ' . $e->getMessage());
        }
    }

    // ======================================================================
    //  Tickets (GH #271)
    //
    //  Ticket checklists used to live in api/tickets/ticket_checklists.php and,
    //  separately, in the workflow engine's attach_checklist action - two more
    //  copies of "copy a template", neither with step descriptions (#190) or
    //  results (#181), and a tick with no server-side check of a required value.
    //  They now share this engine with tasks. What stays ticket-shaped:
    //
    //   - History is a NOTE on the ticket ("✅ Checklist step completed: ..."),
    //     as it always was - but written HERE, not by the browser after the
    //     fact, so a tick from the REST API or anywhere else is recorded too.
    //     A person's note goes through TicketsService::createNote, exactly as
    //     the browser's did (it fires ticket.note_added); automation, with no
    //     analyst, writes a System note (analyst_id NULL, GH #281) and no event,
    //     so a workflow cannot loop on its own notes.
    //   - The closure gate is unchanged: a result step is outstanding until it
    //     has a result, and a Fail counts as answered - it is recorded and shown,
    //     never a reason the ticket cannot close (as on tasks).
    //  Callers gate on the TICKET (analystCanAccessTicket) before calling.
    // ======================================================================

    /**
     * Which optional columns ticket checklists have yet. Before Database
     * Verification adds #271's columns the ticket side keeps working as plain
     * ticks. Cached per request.
     *
     * @return array{result: bool, description: bool, version: bool}
     */
    private static function ticketCols(PDO $conn): array
    {
        static $cols = null;
        if ($cols !== null) return $cols;
        $has = function (string $sql) use ($conn): bool {
            try { $conn->query($sql); return true; } catch (Throwable) { return false; }
        };
        return $cols = [
            'result'      => $has("SELECT result_mode, result, exception_note FROM ticket_checklist_items LIMIT 0")
                             && self::hasResultColumns($conn),
            'description' => $has("SELECT description FROM ticket_checklist_items LIMIT 0"),
            'version'     => $has("SELECT template_version FROM ticket_checklists LIMIT 0"),
        ];
    }

    /**
     * A ticket's checklists with their steps and progress, each carrying the
     * gate it will actually apply - the same shape taskChecklists() returns.
     */
    public static function ticketChecklists(PDO $conn, int $ticketId): array
    {
        $tenantId = function_exists('ticketTenantId') ? ticketTenantId($conn, $ticketId) : null;
        $cols = self::ticketCols($conn);
        $st = $conn->prepare("SELECT id, template_id, title, closure_mode, created_datetime"
                             . ($cols['version'] ? ', template_version' : '') . "
                                FROM ticket_checklists WHERE ticket_id = ? ORDER BY id ASC");
        $st->execute([$ticketId]);
        $lists = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $extra = ($cols['result'] ? ', result_mode, result, exception_note' : '') . ($cols['description'] ? ', description' : '');
        $items = $conn->prepare("SELECT id, title, suggested_role, is_mandatory, requires_input, input_placeholder,
                                        response_value, is_completed, completed_by_id, completed_by_name, completed_datetime{$extra}
                                   FROM ticket_checklist_items WHERE ticket_checklist_id = ?
                                  ORDER BY sort_order ASC, id ASC");
        foreach ($lists as &$l) {
            $l['closure_mode'] = $l['closure_mode'] ?: 'warn';
            $l['effective_closure_mode'] = self::effectiveClosureMode($conn, $l['closure_mode'], $tenantId);
            $items->execute([(int)$l['id']]);
            $l['items'] = $items->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $done = count(array_filter($l['items'], fn($i) => !empty($i['is_completed'])));
            $l['total_items'] = count($l['items']);
            $l['completed_items'] = $done;
            $l['percent'] = $l['total_items'] > 0 ? (int)round($done / $l['total_items'] * 100) : 0;
        }
        unset($l);
        return $lists;
    }

    /**
     * Attach a template to a ticket: copy its title, gate, steps, their
     * descriptions and whether each asks for a result. Returns the new id, or
     * null when $skipIfAttached and that template is already on the ticket
     * (the workflow action's rule).
     *
     * @param int $templateId  the checklist_templates row; its scope must be 'ticket' or 'both'
     * @param bool $skipIfAttached  true: attach nothing when the ticket already has this template
     */
    public static function attachTemplateToTicket(PDO $conn, ActorContext $ctx, int $ticketId, int $templateId, bool $skipIfAttached = false): ?int
    {
        $tpl = $conn->prepare("SELECT title, closure_mode, scope FROM checklist_templates WHERE id = ?");
        $tpl->execute([$templateId]);
        $t = $tpl->fetch(PDO::FETCH_ASSOC);
        if (!$t) {
            throw new ServiceError('not_found', 'not_found', 'Checklist not found.');
        }
        // NULL scope is an old row from before scopes - it was always offered for tickets.
        if (!in_array((string)($t['scope'] ?? 'ticket'), ['ticket', 'both', ''], true)) {
            throw new ServiceError('validation', 'invalid_field', 'That checklist is for tasks only.');
        }
        if ($skipIfAttached) {
            $ex = $conn->prepare("SELECT 1 FROM ticket_checklists WHERE ticket_id = ? AND template_id = ? LIMIT 1");
            $ex->execute([$ticketId, $templateId]);
            if ($ex->fetchColumn()) return null;
        }
        $cols = self::ticketCols($conn);
        $own = !$conn->inTransaction();
        if ($own) $conn->beginTransaction();
        try {
            // created_by_id: the analyst, or NULL for automation. The workflow action
            // wrote 1 here - naming analyst #1, on every install, for every attach.
            $conn->prepare("INSERT INTO ticket_checklists (ticket_id, template_id, title, closure_mode, created_by_id, created_datetime)
                            VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP())")
                 ->execute([$ticketId, $templateId, (string)$t['title'], ($t['closure_mode'] ?? '') === 'block' ? 'block' : 'warn',
                            $ctx->actorId > 0 ? $ctx->actorId : null]);
            $chkId = (int)$conn->lastInsertId();
            $ver = $cols['version'] ? self::templateVersionNo($conn, $templateId) : null;
            if ($ver !== null) {
                $conn->prepare("UPDATE ticket_checklists SET template_version = ? WHERE id = ?")->execute([$ver, $chkId]);
            }
            $steps = $conn->prepare("SELECT title, description, suggested_role, is_mandatory, requires_input, input_placeholder, sort_order"
                                    . ($cols['result'] ? ', result_mode' : '') . "
                                       FROM checklist_template_items WHERE template_id = ? ORDER BY sort_order ASC, id ASC");
            $steps->execute([$templateId]);
            $ins = $conn->prepare("INSERT INTO ticket_checklist_items
                                     (ticket_checklist_id, title, suggested_role, is_mandatory, requires_input, input_placeholder, sort_order"
                                   . ($cols['result'] ? ', result_mode' : '') . ($cols['description'] ? ', description' : '') . ")
                                   VALUES (?, ?, ?, ?, ?, ?, ?" . ($cols['result'] ? ', ?' : '') . ($cols['description'] ? ', ?' : '') . ")");
            foreach ($steps->fetchAll(PDO::FETCH_ASSOC) as $i => $s) {
                $vals = [$chkId, $s['title'], $s['suggested_role'] ?: null, !empty($s['is_mandatory']) ? 1 : 0,
                         !empty($s['requires_input']) ? 1 : 0, $s['input_placeholder'] ?: null, $s['sort_order'] ?? ($i + 1)];
                if ($cols['result']) $vals[] = ($s['result_mode'] ?? 'none') === 'pass_fail' ? 'pass_fail' : 'none';
                if ($cols['description']) $vals[] = ($s['description'] ?? '') !== '' ? $s['description'] : null;
                $ins->execute($vals);
            }
            if ($own) $conn->commit();
            return $chkId;
        } catch (Throwable $e) {
            if ($own && $conn->inTransaction()) $conn->rollBack();
            throw $e;
        }
    }

    /** The ticket a step belongs to, or 0. Callers gate on the ticket. */
    public static function ticketIdForItem(PDO $conn, int $itemId): int
    {
        $st = $conn->prepare("SELECT c.ticket_id FROM ticket_checklist_items i
                                JOIN ticket_checklists c ON c.id = i.ticket_checklist_id WHERE i.id = ?");
        $st->execute([$itemId]);
        return (int)($st->fetchColumn() ?: 0);
    }

    /** The ticket a checklist is attached to, or 0. */
    public static function ticketIdForChecklist(PDO $conn, int $checklistId): int
    {
        $st = $conn->prepare("SELECT ticket_id FROM ticket_checklists WHERE id = ?");
        $st->execute([$checklistId]);
        return (int)($st->fetchColumn() ?: 0);
    }

    /** A ticket checklist step with its checklist and ticket - or null. */
    private static function ticketItemRow(PDO $conn, int $itemId): ?array
    {
        $extra = self::ticketCols($conn)['result'] ? ', i.result_mode, i.result, i.exception_note' : '';
        $st = $conn->prepare("SELECT i.id, i.title, i.requires_input, i.response_value, i.is_completed, i.completed_by_id,
                                     i.completed_by_name, i.completed_datetime{$extra},
                                     c.id AS checklist_id, c.title AS checklist_title, c.ticket_id
                                FROM ticket_checklist_items i JOIN ticket_checklists c ON c.id = i.ticket_checklist_id
                               WHERE i.id = ?");
        $st->execute([$itemId]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Tick or untick a ticket step - the same rules as toggleTaskItem(): a step
     * that asks for a value cannot be ticked without one, and a step that asks
     * for a result is ticked by recording one. Returns the stored row.
     *
     * @param bool $completed  true to tick, false to untick (which clears the answer and any result)
     * @param ?string $value  the answer for a step that asks for one; ignored on other steps
     */
    public static function toggleTicketItem(PDO $conn, ActorContext $ctx, int $itemId, bool $completed, ?string $value): array
    {
        $row = self::ticketItemRow($conn, $itemId);
        if (!$row) {
            throw new ServiceError('not_found', 'not_found', 'Step not found.');
        }
        if ($completed && ($row['result_mode'] ?? 'none') === 'pass_fail' && empty($row['result'])) {
            throw new ServiceError('validation', 'missing_field', 'Record a result for this step - Pass, Fail or Not applicable.');
        }
        $value = ((int)$row['requires_input'] === 1 && $value !== null) ? trim($value) : null;
        if ($completed && (int)$row['requires_input'] === 1 && ($value === null || $value === '')) {
            throw new ServiceError('validation', 'missing_field', 'This step needs a value before it can be ticked.');
        }
        if ($completed) {
            $conn->prepare("UPDATE ticket_checklist_items
                               SET is_completed = 1, response_value = ?, completed_by_id = ?,
                                   completed_by_name = ?, completed_datetime = UTC_TIMESTAMP()
                             WHERE id = ?")
                 ->execute([$value !== '' ? $value : null, $ctx->actorId > 0 ? $ctx->actorId : null,
                            $ctx->actorName !== '' ? $ctx->actorName : null, $itemId]);
        } else {
            $conn->prepare("UPDATE ticket_checklist_items
                               SET is_completed = 0, response_value = NULL, completed_by_id = NULL,
                                   completed_by_name = NULL, completed_datetime = NULL
                             WHERE id = ?")
                 ->execute([$itemId]);
            if (array_key_exists('result_mode', $row)) {
                $conn->prepare("UPDATE ticket_checklist_items SET result = NULL, exception_note = NULL WHERE id = ?")->execute([$itemId]);
            }
        }
        $label = '[' . $row['checklist_title'] . '] "' . $row['title'] . '"';
        self::writeStepNote($conn, $ctx, (int)$row['ticket_id'], $completed
            ? '✅ Checklist step completed: ' . $label . ($value !== null && $value !== '' ? ' — Response: ' . $value : '')
            : '↩️ Checklist step reopened: ' . $label . ' was marked incomplete');
        return self::ticketItemRow($conn, $itemId) ?: [];
    }

    /**
     * Record a ticket step's result - Pass, Fail or Not applicable - exactly as
     * setTaskItemResult() does: it ticks the step, and Fail needs a note on what
     * went wrong. A failed step does not stop the ticket closing; it is on the
     * record instead.
     *
     * @param string $result  pass, fail or na
     * @param ?string $note  the exception note - required for fail, kept for the others if given
     */
    public static function setTicketItemResult(PDO $conn, ActorContext $ctx, int $itemId, string $result, ?string $note): array
    {
        $row = self::ticketItemRow($conn, $itemId);
        if (!$row) {
            throw new ServiceError('not_found', 'not_found', 'Step not found.');
        }
        if (($row['result_mode'] ?? 'none') !== 'pass_fail') {
            throw new ServiceError('validation', 'invalid_field', 'This step does not ask for a result.');
        }
        if (!in_array($result, ['pass', 'fail', 'na'], true)) {
            throw new ServiceError('validation', 'invalid_field', 'A result is pass, fail or na.');
        }
        $note = $note !== null ? trim($note) : null;
        if ($result === 'fail' && ($note === null || $note === '')) {
            throw new ServiceError('validation', 'missing_field', 'Say what failed - a failed step needs an exception note.');
        }
        $conn->prepare("UPDATE ticket_checklist_items
                           SET result = ?, exception_note = ?, is_completed = 1,
                               completed_by_id = ?, completed_by_name = ?, completed_datetime = UTC_TIMESTAMP()
                         WHERE id = ?")
             ->execute([$result, $note !== '' ? $note : null, $ctx->actorId > 0 ? $ctx->actorId : null,
                        $ctx->actorName !== '' ? $ctx->actorName : null, $itemId]);
        $label = '[' . $row['checklist_title'] . '] "' . $row['title'] . '"';
        self::writeStepNote($conn, $ctx, (int)$row['ticket_id'], match ($result) {
            'pass'  => '✅ Checklist step passed: ' . $label,
            'fail'  => '❌ Checklist step failed: ' . $label . ' — Exception: ' . $note,
            default => '➖ Checklist step not applicable: ' . $label,
        } . ($result !== 'fail' && $note !== null && $note !== '' ? ' — Note: ' . $note : ''));
        return self::ticketItemRow($conn, $itemId) ?: [];
    }

    /**
     * Remove one checklist from a ticket: its steps first (no foreign key to
     * rely on), then it, in one transaction.
     */
    public static function removeTicketChecklist(PDO $conn, int $checklistId): void
    {
        $own = !$conn->inTransaction();
        if ($own) $conn->beginTransaction();
        try {
            $conn->prepare("DELETE FROM ticket_checklist_items WHERE ticket_checklist_id = ?")->execute([$checklistId]);
            $conn->prepare("DELETE FROM ticket_checklists WHERE id = ?")->execute([$checklistId]);
            if ($own) $conn->commit();
        } catch (Throwable $e) {
            if ($own && $conn->inTransaction()) $conn->rollBack();
            throw $e;
        }
    }

    /**
     * A step's history line, as an internal note on the ticket. Never throws:
     * the step is saved whether or not its note is.
     *
     * A person: TicketsService::createNote, as the browser's own note did -
     * so ticket.note_added still fires for it. No person (a workflow, the
     * system): a System note, analyst_id NULL (GH #281), with no event.
     *
     * @param string $text  the note, e.g. ✅ Checklist step completed: [Onboarding] "Create account"
     */
    private static function writeStepNote(PDO $conn, ActorContext $ctx, int $ticketId, string $text): void
    {
        // GH #273: a step changed through the REST API says so, as a person reading
        // the ticket would otherwise take the key's analyst to have done it by hand.
        if ($ctx->source === 'api') $text .= ' (via the API)';
        try {
            if ($ctx->actorId > 0) {
                require_once __DIR__ . '/tickets.php';
                TicketsService::createNote($conn, $ctx, $ticketId, ['text' => $text, 'is_internal' => true]);
                return;
            }
            $conn->prepare("INSERT INTO ticket_notes (ticket_id, analyst_id, note_text, is_internal, created_datetime)
                            VALUES (?, NULL, ?, 1, UTC_TIMESTAMP())")->execute([$ticketId, $text]);
        } catch (Throwable $e) {
            error_log('[checklists] could not record a step change on ticket ' . $ticketId . ': ' . $e->getMessage());
        }
    }
}
