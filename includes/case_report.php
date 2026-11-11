<?php
/**
 * The case report (GH #204): one ticket and the tasks linked to it - or one task on its
 * own - as a single printable document for a reviewer or an auditor.
 *
 * WHAT IT IS: the stored record, read and laid out. Nothing is rewritten, summarised or
 * generated; the ticket and its tasks stay the authority and this is a copy of them as
 * they stand. It reads only what FreeITSM keeps - a history entry that was never
 * recorded is not invented, and a section that could not be read says so rather than
 * looking empty.
 *
 * WHO SEES WHAT: every part is checked separately, with the same rule the screen it
 * comes from uses - the ticket by its company, each task by Tasks access and its company,
 * linked assets and configuration items by their modules, documents by
 * documentCanViewParent(), form answers by TaskForms::mayReadAnswers(), follow-ups by
 * TaskFollowUps. What the reader may not see is COUNTED, never named: a title, a file
 * name or a link can say as much as the record it points at.
 *
 * Internal notes are left out unless the exporting analyst ticks them in, because the
 * report usually leaves the building.
 *
 * Times are stored in UTC and shown in the install's time zone, which the report states
 * once - the same rule as the repeating-task evidence report (TaskEvidence::when), so a
 * printed page means the same to every reader. A due date is a calendar day and is not
 * converted; a scheduled work window is wall-clock time as it was entered.
 *
 * Laid out by case-report/index.php; this file only gathers.
 *
 * @wiki Case-Report-Developer-Guide
 */

require_once __DIR__ . '/tenancy.php';
require_once __DIR__ . '/html_sanitise.php';
require_once __DIR__ . '/ticket_sensitivity.php';
require_once __DIR__ . '/documents.php';
require_once __DIR__ . '/task_evidence.php';
require_once __DIR__ . '/services/ticket_fields.php';
require_once __DIR__ . '/services/task_forms.php';
require_once __DIR__ . '/services/task_procedures.php';
require_once __DIR__ . '/services/task_follow_ups.php';

final class CaseReport
{
    /** The sections a reader can choose, in the order they print. Overview always prints. */
    public const SECTIONS = ['conversation', 'notes', 'checklists', 'tasks', 'history', 'evidence'];

    /** How deep subtasks are followed under a task. Deeper ones are counted, not lost silently. */
    private const SUBTASK_DEPTH = 3;

    /**
     * The reader's choices from the query string: which sections, and whether internal notes go in.
     * Nothing chosen (a first visit) means every section, internal notes out.
     *
     * @param array $q  $_GET
     * @return array{sections:string[], internal:bool}
     */
    public static function options(array $q): array
    {
        $chosen = isset($q['s']) && is_array($q['s'])
            ? array_values(array_intersect(self::SECTIONS, array_map('strval', $q['s'])))
            : (isset($q['set']) ? [] : self::SECTIONS);
        // Layout (borrowed from Report Packs): a first visit gets a cover, contents and page
        // numbers; once the reader has made choices, an unticked box means off.
        $layout = fn(string $k, bool $default) => isset($q['set']) ? !empty($q[$k]) : $default;
        return ['sections' => $chosen, 'internal' => !empty($q['internal']) && in_array('notes', $chosen, true),
                'cover' => $layout('cover', true), 'contents' => $layout('contents', true),
                'breaks' => $layout('breaks', false), 'pagenums' => $layout('pagenums', true)];
    }

    /**
     * The report for one ticket, or null when the analyst may not see it (callers show "not found",
     * so a ticket in another company is not confirmed to exist).
     *
     * @param int $viewer  the analyst producing the report
     * @param array $opt  from options()
     */
    public static function forTicket(PDO $conn, int $ticketId, int $viewer, array $opt): ?array
    {
        if ($ticketId <= 0 || !analystCanAccessTicket($conn, $viewer, $ticketId)) return null;
        $t = self::ticketRow($conn, $ticketId);
        if (!$t) return null;

        $r = self::shell('ticket', (string)$t['ticket_number'], (string)$t['subject'], $viewer, $opt);
        $r['ticket'] = $t;
        $r['multi'] = isMultiTenant($conn);
        $on = fn(string $s) => in_array($s, $opt['sections'], true);

        // TRAP: the reads that COUNT what they leave out take $r by reference. An arrow fn
        // (fn() =>) captures it by value, so every "not shown" count made inside one was
        // silently thrown away - and the report would claim nothing was left out.
        $r['fields'] = self::attempt($r, 'overview', fn() => self::customFields($conn, $t), []);
        $r['assets'] = self::attempt($r, 'overview', function () use ($conn, $ticketId, $viewer, &$r) { return self::linkedAssets($conn, $ticketId, $viewer, $r); }, []);
        $r['cis']    = self::attempt($r, 'overview', function () use ($conn, $ticketId, $viewer, &$r) { return self::linkedCis($conn, $ticketId, $viewer, $r); }, []);
        $r['links']  = self::attempt($r, 'overview', function () use ($conn, $ticketId, $viewer, &$r) { return self::linkedTickets($conn, $ticketId, $viewer, $r); }, []);

        if ($on('conversation')) $r['conversation'] = self::attempt($r, 'conversation', fn() => self::conversation($conn, $ticketId), []);
        if ($on('notes'))        $r['notes'] = self::attempt($r, 'notes', function () use ($conn, $ticketId, $opt, &$r) { return self::notes($conn, $ticketId, $opt['internal'], $r); }, []);
        if ($on('checklists'))   $r['checklists'] = self::attempt($r, 'checklists', fn() => self::ticketChecklists($conn, $ticketId), []);
        // GH #272: forms on the ticket, beside its checklists. Answers only where TicketForms::mayReadAnswers() says.
        $r['ticket_forms'] = $on('checklists') && TicketForms::columnsReady($conn)
            ? self::attempt($r, 'checklists', fn() => TicketForms::forTicket($conn, $ticketId, $viewer, true), []) : [];
        if ($on('history'))      $r['history'] = self::attempt($r, 'history', fn() => self::ticketHistory($conn, $ticketId), []);

        // Tasks are read for the evidence index too (their documents), but only shown when chosen.
        $mayTasks = analystCanAccessModule($conn, $viewer, 'tasks');
        $taskIds = self::attempt($r, 'tasks', fn() => self::ticketTaskIds($conn, $ticketId), []);
        if ($on('tasks') || $on('evidence')) {
            foreach ($taskIds as $tid) {
                if (!$mayTasks || !analystCanAccessTask($conn, $viewer, $tid)) { $r['hidden']['tasks']++; continue; }
                $r['tasks'][] = self::taskBlock($conn, $tid, $viewer, $opt, $r, 0);
            }
            if (!$mayTasks && $taskIds) $r['notes_scope'][] = 'tasks_no_module';
        }

        if ($on('evidence')) {
            $r['evidence']['attachments'] = self::attempt($r, 'evidence', fn() => self::emailAttachments($conn, $ticketId), []);
            $r['evidence']['documents'] = self::attempt($r, 'evidence', function () use ($conn, $ticketId, $viewer, &$r) { return self::documentsFor($conn, $viewer, 'ticket', $ticketId, 'ticket', $r); }, []);
            foreach ($r['notes'] as $n) {
                $r['evidence']['documents'] = array_merge($r['evidence']['documents'],
                    self::attempt($r, 'evidence', function () use ($conn, $viewer, $n, &$r) { return self::documentsFor($conn, $viewer, 'ticket_note', (int)$n['id'], 'note', $r); }, []));
            }
            self::collectTaskDocuments($r['tasks'], $r);
        }
        if (!$on('tasks')) $r['tasks'] = [];
        return $r;
    }

    /**
     * The report for one task on its own (the "case without a ticket"), with its subtasks. Null when
     * the analyst may not see it.
     *
     * @param int $viewer  the analyst producing the report
     * @param array $opt  from options(); conversation and notes do not apply to a task
     */
    public static function forTask(PDO $conn, int $taskId, int $viewer, array $opt): ?array
    {
        if ($taskId <= 0 || !analystCanAccessModule($conn, $viewer, 'tasks') || !analystCanAccessTask($conn, $viewer, $taskId)) return null;
        $st = $conn->prepare("SELECT id, title FROM tasks WHERE id = ?");
        $st->execute([$taskId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;

        $r = self::shell('task', '#' . (int)$row['id'], (string)$row['title'], $viewer, $opt);
        $r['tasks'][] = self::taskBlock($conn, $taskId, $viewer, $opt, $r, 0);
        if (in_array('evidence', $opt['sections'], true)) self::collectTaskDocuments($r['tasks'], $r);
        return $r;
    }

    /**
     * Writes the export into the record's own history - the ticket's audit trail, or the task's -
     * with who took it and what it covered. Called when the report is printed, not when it is
     * previewed, so changing the choices does not fill the history.
     *
     * @param array $r  the report as built
     */
    public static function recordExport(PDO $conn, array $r, int $viewer): void
    {
        $what = 'Exported by ' . $r['by'] . ': ' . ($r['options']['sections'] ? implode(', ', $r['options']['sections']) : 'overview only')
              . ($r['options']['internal'] ? ' (with internal notes)' : '');
        $what = mb_substr($what, 0, 500);
        try {
            if ($r['kind'] === 'ticket') {
                $conn->prepare("INSERT INTO ticket_audit (ticket_id, analyst_id, field_name, old_value, new_value, created_datetime)
                                VALUES (?, ?, 'Case report', NULL, ?, UTC_TIMESTAMP())")
                     ->execute([(int)$r['ticket']['id'], $viewer, $what]);
            } else {
                $conn->prepare("INSERT INTO task_audit (task_id, analyst_id, field_name, old_value, new_value, source, created_datetime)
                                VALUES (?, ?, 'Case report', NULL, ?, 'app', UTC_TIMESTAMP())")
                     ->execute([(int)$r['tasks'][0]['id'], $viewer, $what]);
            }
        } catch (Throwable $e) {
            // The report still prints; the reader is told the export could not be recorded.
            error_log('case report export record: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Customer-authored or analyst-written HTML for print: the allow-list cleaner, so a stored
     * message can never run script or load a remote image in the reader's browser. Plain text is
     * escaped and keeps its line breaks.
     *
     * @param ?string $body  as stored
     * @param string $type  'html' or 'text' (a chat channel stores the sender's words as text)
     */
    public static function body(?string $body, string $type = 'html'): string
    {
        $body = (string)$body;
        if (trim($body) === '') return '';
        if (strtolower($type) === 'text') return nl2br(htmlspecialchars($body, ENT_QUOTES, 'UTF-8'));
        return sanitiseUserHtml($body);
    }

    // ── Building blocks ────────────────────────────────────────────────────

    /** The empty report every kind starts from. */
    private static function shell(string $kind, string $ref, string $title, int $viewer, array $opt): array
    {
        return [
            'kind' => $kind, 'ref' => $ref, 'title' => $title,
            'produced_utc' => gmdate('Y-m-d H:i:s'), 'tz' => date_default_timezone_get(),
            'by' => (string)($_SESSION['analyst_name'] ?? ''), 'viewer' => $viewer, 'options' => $opt,
            'ticket' => null, 'fields' => [], 'assets' => [], 'cis' => [], 'links' => [],
            'conversation' => [], 'notes' => [], 'checklists' => [], 'history' => [], 'tasks' => [],
            'evidence' => ['attachments' => [], 'documents' => []],
            'hidden' => ['tasks' => 0, 'subtasks_deeper' => 0, 'assets' => 0, 'cis' => 0, 'links' => 0, 'documents' => 0, 'internal_notes' => 0],
            'notes_scope' => [],
            'unavailable' => [],
        ];
    }

    /**
     * Runs one read, and when it fails records the section as unavailable instead of letting it
     * look empty - "could not be read" and "there was nothing" must never print the same.
     *
     * @param array $r  the report, for its 'unavailable' list
     * @param string $section  the section the read belongs to
     * @param mixed $fallback  what to use after a failure
     */
    private static function attempt(array &$r, string $section, callable $fn, $fallback)
    {
        try {
            return $fn();
        } catch (Throwable $e) {
            error_log('case report ' . $section . ': ' . $e->getMessage());
            $r['unavailable'][$section] = true;
            return $fallback;
        }
    }

    /** The ticket row with every name the overview prints. */
    private static function ticketRow(PDO $conn, int $id): ?array
    {
        $sens = ticketSensitivityReady($conn) ? 't.sensitivity' : "'normal'";
        $st = $conn->prepare(
            "SELECT t.*, $sens AS sensitivity_now,
                    ts.name AS status_name, ts.is_closed AS status_closed, tp.name AS priority_name,
                    d.name AS department_name, tt.name AS type_name, tc.name AS category_name,
                    cc.name AS closure_category_name, rc.name AS resolution_name, tm.name AS team_name,
                    aa.full_name AS assignee_name, ow.full_name AS owner_name, o.name AS origin_name,
                    COALESCE(NULLIF(u.display_name, ''), u.email) AS requester_name, u.email AS requester_email,
                    tn.name AS company_name
               FROM tickets t
          LEFT JOIN ticket_statuses ts ON ts.id = t.status_id
          LEFT JOIN ticket_priorities tp ON tp.id = t.priority_id
          LEFT JOIN departments d ON d.id = t.department_id
          LEFT JOIN ticket_types tt ON tt.id = t.ticket_type_id
          LEFT JOIN ticket_categories tc ON tc.id = t.category_id
          LEFT JOIN ticket_categories cc ON cc.id = t.closure_category_id
          LEFT JOIN ticket_resolution_codes rc ON rc.id = t.resolution_code_id
          LEFT JOIN teams tm ON tm.id = t.assigned_team_id
          LEFT JOIN analysts aa ON aa.id = t.assigned_analyst_id
          LEFT JOIN analysts ow ON ow.id = t.owner_id
          LEFT JOIN ticket_origins o ON o.id = t.origin_id
          LEFT JOIN users u ON u.id = t.user_id
          LEFT JOIN tenants tn ON tn.id = t.tenant_id
              WHERE t.id = ?");
        $st->execute([$id]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** The ticket's custom fields that have a value, as the analyst sees them. */
    private static function customFields(PDO $conn, array $t): array
    {
        if (!TicketFieldsService::schemaReady($conn)) return [];
        $out = [];
        foreach (TicketFieldsService::forTicket($conn, $t, 'analyst') as $f) {
            if ($f['display'] === null || $f['display'] === '') continue;
            $out[] = ['label' => (string)$f['label'], 'value' => is_array($f['display']) ? implode(', ', $f['display']) : (string)$f['display']];
        }
        return $out;
    }

    /** Linked assets, named only for an analyst who may use Assets and see the asset's company. */
    private static function linkedAssets(PDO $conn, int $ticketId, int $viewer, array &$r): array
    {
        $st = $conn->prepare("SELECT a.id, a.hostname, a.asset_tag, a.tenant_id FROM ticket_assets ta JOIN assets a ON a.id = ta.asset_id
                               WHERE ta.ticket_id = ? ORDER BY a.hostname");
        $st->execute([$ticketId]);
        $may = analystCanAccessModule($conn, $viewer, 'assets');
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $a) {
            if (!$may || !self::tenantOk($conn, $viewer, $a['tenant_id'])) { $r['hidden']['assets']++; continue; }
            $out[] = trim((string)$a['hostname'] . ($a['asset_tag'] ? ' (' . $a['asset_tag'] . ')' : '')) ?: ('#' . (int)$a['id']);
        }
        return $out;
    }

    /** Linked configuration items, named only for an analyst who may use the CMDB. */
    private static function linkedCis(PDO $conn, int $ticketId, int $viewer, array &$r): array
    {
        $st = $conn->prepare("SELECT o.id, o.name FROM ticket_cmdb_objects tco JOIN cmdb_objects o ON o.id = tco.cmdb_object_id
                               WHERE tco.ticket_id = ? ORDER BY o.name");
        $st->execute([$ticketId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        if (!analystCanAccessModule($conn, $viewer, 'cmdb')) { $r['hidden']['cis'] += count($rows); return []; }
        return array_map(fn($o) => (string)$o['name'], $rows);
    }

    /** Related tickets, named only where the analyst may see them. */
    private static function linkedTickets(PDO $conn, int $ticketId, int $viewer, array &$r): array
    {
        $st = $conn->prepare(
            "SELECT tl.relation_type, IF(tl.source_ticket_id = ?, tl.target_ticket_id, tl.source_ticket_id) AS other_id
               FROM ticket_links tl WHERE tl.source_ticket_id = ? OR tl.target_ticket_id = ?");
        $st->execute([$ticketId, $ticketId, $ticketId]);
        $out = [];
        $one = $conn->prepare("SELECT ticket_number, subject FROM tickets WHERE id = ?");
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $l) {
            $oid = (int)$l['other_id'];
            if (!analystCanAccessTicket($conn, $viewer, $oid)) { $r['hidden']['links']++; continue; }
            $one->execute([$oid]);
            $o = $one->fetch(PDO::FETCH_ASSOC);
            if ($o) $out[] = ['relation' => (string)$l['relation_type'], 'number' => (string)$o['ticket_number'], 'subject' => (string)$o['subject']];
        }
        return $out;
    }

    /** Every message on the ticket, oldest first, as stored (quoted history included - nothing is trimmed). */
    private static function conversation(PDO $conn, int $ticketId): array
    {
        $st = $conn->prepare(
            "SELECT id, subject, from_name, from_address, to_recipients, cc_recipients, received_datetime,
                    body_content, body_type, direction, channel
               FROM emails WHERE ticket_id = ? ORDER BY received_datetime, id");
        $st->execute([$ticketId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /** The ticket's notes - shared ones always, internal ones only when chosen (and counted otherwise). */
    private static function notes(PDO $conn, int $ticketId, bool $internal, array &$r): array
    {
        $st = $conn->prepare(
            "SELECT n.id, n.note_text, n.is_internal, n.created_datetime, a.full_name AS author, (n.analyst_id IS NULL) AS by_system
               FROM ticket_notes n LEFT JOIN analysts a ON a.id = n.analyst_id
              WHERE n.ticket_id = ? ORDER BY n.created_datetime, n.id");
        $st->execute([$ticketId]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $n) {
            if ((int)$n['is_internal'] === 1 && !$internal) { $r['hidden']['internal_notes']++; continue; }
            $out[] = $n;
        }
        return $out;
    }

    /** The ticket's checklists with each step as it stands - ticket checklists keep no step history. */
    private static function ticketChecklists(PDO $conn, int $ticketId): array
    {
        // SELECT *: GH #271's result columns (and template_version) when Database
        // Verification has added them, and nothing missing when it has not.
        $st = $conn->prepare("SELECT * FROM ticket_checklists WHERE ticket_id = ? ORDER BY id");
        $st->execute([$ticketId]);
        $lists = $st->fetchAll(PDO::FETCH_ASSOC);
        $it = $conn->prepare("SELECT * FROM ticket_checklist_items WHERE ticket_checklist_id = ? ORDER BY sort_order, id");
        foreach ($lists as &$l) { $it->execute([(int)$l['id']]); $l['items'] = $it->fetchAll(PDO::FETCH_ASSOC); }
        unset($l);
        return $lists;
    }

    /** The ticket's audit trail, oldest first - what was recorded, nothing more. */
    private static function ticketHistory(PDO $conn, int $ticketId): array
    {
        $st = $conn->prepare(
            "SELECT ta.field_name, ta.old_value, ta.new_value, ta.created_datetime, a.full_name AS who
               FROM ticket_audit ta LEFT JOIN analysts a ON a.id = ta.analyst_id
              WHERE ta.ticket_id = ? ORDER BY ta.created_datetime, ta.id");
        $st->execute([$ticketId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /** The top-level tasks linked to the ticket (subtasks are reached from their parent). */
    private static function ticketTaskIds(PDO $conn, int $ticketId): array
    {
        $st = $conn->prepare("SELECT id FROM tasks WHERE ticket_id = ?
                                 AND (parent_task_id IS NULL OR parent_task_id NOT IN (SELECT id FROM (SELECT id FROM tasks WHERE ticket_id = ?) x))
                               ORDER BY created_datetime, id");
        $st->execute([$ticketId, $ticketId]);
        return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * One task as the report prints it: the record, its description, comments, checklists with each
     * step's recorded changes, forms (answers only where the reader may read them), procedures,
     * follow-ups, its history, its documents and its subtasks.
     *
     * @param array $r  the report, for hidden counts and unavailable sections
     * @param int $depth  how far down the subtask tree this one is
     */
    private static function taskBlock(PDO $conn, int $taskId, int $viewer, array $opt, array &$r, int $depth): array
    {
        $b = ['id' => $taskId, 'unavailable' => [], 'comments' => [], 'checklists' => [], 'steps' => [], 'forms' => [],
              'procedures' => [], 'follow_ups' => [], 'history' => [], 'documents' => [], 'subtasks' => []];
        $try = function (string $part, callable $fn, $fallback) use (&$b) {
            try { return $fn(); } catch (Throwable $e) { error_log('case report task part ' . $part . ': ' . $e->getMessage()); $b['unavailable'][$part] = true; return $fallback; }
        };

        $st = $conn->prepare(
            "SELECT t.*, ts.name AS status_name, ts.is_closed AS status_closed, tp.name AS priority_name,
                    a.full_name AS assignee_name, tm.name AS team_name, cb.full_name AS created_by_name,
                    db.full_name AS completed_by_name, COALESCE(NULLIF(s.trading_name, ''), s.legal_name) AS supplier_name
               FROM tasks t
          LEFT JOIN task_statuses ts ON ts.id = t.status_id
          LEFT JOIN task_priorities tp ON tp.id = t.priority_id
          LEFT JOIN analysts a ON a.id = t.assigned_analyst_id
          LEFT JOIN teams tm ON tm.id = t.assigned_team_id
          LEFT JOIN analysts cb ON cb.id = t.created_by_id
          LEFT JOIN analysts db ON db.id = t.completed_by_id
          LEFT JOIN suppliers s ON s.id = t.assigned_supplier_id
              WHERE t.id = ?");
        $st->execute([$taskId]);
        $b['task'] = $st->fetch(PDO::FETCH_ASSOC) ?: ['id' => $taskId, 'title' => '#' . $taskId];

        // The ticket a stand-alone task belongs to, named only where the reader may see tickets.
        $tk = (int)($b['task']['ticket_id'] ?? 0);
        $b['ticket_ref'] = null;
        if ($tk && $r['kind'] === 'task') {
            $b['ticket_ref'] = analystCanAccessModule($conn, $viewer, 'tickets') && analystCanAccessTicket($conn, $viewer, $tk)
                ? (string)$conn->query("SELECT ticket_number FROM tickets WHERE id = " . $tk)->fetchColumn()
                : false;
        }

        $b['comments'] = $try('comments', function () use ($conn, $taskId) {
            $s = $conn->prepare("SELECT c.comment, c.created_datetime, a.full_name AS author, (c.analyst_id IS NULL) AS by_system FROM task_comments c
                                   LEFT JOIN analysts a ON a.id = c.analyst_id WHERE c.task_id = ? ORDER BY c.created_datetime, c.id");
            $s->execute([$taskId]);
            return $s->fetchAll(PDO::FETCH_ASSOC);
        }, []);

        $b['checklists'] = $try('checklists', function () use ($conn, $taskId) {
            $s = $conn->prepare("SELECT * FROM task_checklists WHERE task_id = ? ORDER BY sort_order, id");
            $s->execute([$taskId]);
            $lists = $s->fetchAll(PDO::FETCH_ASSOC);
            $i = $conn->prepare("SELECT * FROM task_checklist_items WHERE task_checklist_id = ? ORDER BY sort_order, id");
            foreach ($lists as &$l) { $i->execute([(int)$l['id']]); $l['items'] = $i->fetchAll(PDO::FETCH_ASSOC); }
            unset($l);
            return $lists;
        }, []);

        $b['steps'] = $try('checklists', function () use ($conn, $taskId) {
            $s = $conn->prepare("SELECT * FROM task_checklist_item_events WHERE task_id = ? ORDER BY id");
            $s->execute([$taskId]);
            return $s->fetchAll(PDO::FETCH_ASSOC);
        }, []);

        $b['forms']      = $try('forms', fn() => TaskForms::forTask($conn, $taskId, $viewer, true), []);
        $b['procedures'] = $try('procedures', fn() => TaskProcedures::forTask($conn, $taskId, $viewer), []);
        $b['follow_ups'] = $try('follow_ups', fn() => TaskFollowUps::forTask($conn, $taskId, $viewer), []);

        if (in_array('history', $opt['sections'], true)) {
            $b['history'] = $try('history', function () use ($conn, $taskId) {
                $s = $conn->prepare("SELECT ta.field_name, ta.old_value, ta.new_value, ta.source, ta.created_datetime, a.full_name AS who
                                       FROM task_audit ta LEFT JOIN analysts a ON a.id = ta.analyst_id
                                      WHERE ta.task_id = ? ORDER BY ta.created_datetime, ta.id");
                $s->execute([$taskId]);
                $rows = array_map(fn($x) => ['kind' => 'audit'] + $x, $s->fetchAll(PDO::FETCH_ASSOC));
                try {
                    $e = $conn->prepare("SELECT event_type, actor_name, from_value, to_value, created_datetime FROM task_events WHERE task_id = ? ORDER BY id");
                    $e->execute([$taskId]);
                    foreach ($e->fetchAll(PDO::FETCH_ASSOC) as $x) $rows[] = ['kind' => 'event'] + $x;
                } catch (Throwable) { /* task_events only exists once #181's Database Verification has run */ }
                usort($rows, fn($a, $b) => strcmp((string)$a['created_datetime'], (string)$b['created_datetime']));
                return $rows;
            }, []);
        }

        $b['documents'] = $try('documents', function () use ($conn, $viewer, $taskId, &$r) {
            return self::documentsFor($conn, $viewer, 'task', $taskId, 'task:' . $taskId, $r);
        }, []);

        // Subtasks, each checked on its own - a subtask can sit in another company than its parent.
        $kids = $try('subtasks', function () use ($conn, $taskId) {
            $s = $conn->prepare("SELECT id FROM tasks WHERE parent_task_id = ? ORDER BY created_datetime, id");
            $s->execute([$taskId]);
            return array_map('intval', $s->fetchAll(PDO::FETCH_COLUMN));
        }, []);
        foreach ($kids as $kid) {
            if ($depth + 1 >= self::SUBTASK_DEPTH) { $r['hidden']['subtasks_deeper']++; continue; }
            if (!analystCanAccessTask($conn, $viewer, $kid)) { $r['hidden']['tasks']++; continue; }
            $b['subtasks'][] = self::taskBlock($conn, $kid, $viewer, $opt, $r, $depth + 1);
        }
        return $b;
    }

    /**
     * Documents attached to one record, for an analyst who may see that record's documents; counted
     * otherwise. Listed, never embedded - the index says the file was attached, not what it says.
     *
     * @param string $type  the documents entity type ('ticket', 'ticket_note', 'task')
     * @param string $where  how the index names the place it is attached
     */
    private static function documentsFor(PDO $conn, int $viewer, string $type, int $parentId, string $where, array &$r): array
    {
        $st = $conn->prepare(
            "SELECT d.id, d.kind, d.title, d.original_name, d.mime_type, d.size_bytes, d.content_hash, d.external_url,
                    d.created_datetime, a.full_name AS uploaded_by
               FROM document_links dl JOIN documents d ON d.id = dl.document_id
          LEFT JOIN analysts a ON a.id = d.uploaded_by_id
              WHERE dl.parent_type = ? AND dl.parent_id = ? AND d.deleted_datetime IS NULL
              ORDER BY d.created_datetime, d.id");
        $st->execute([$type, $parentId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) return [];
        $allowed = modulesWithoutSwitchedOff($conn, getAnalystAllowedModules($conn, $viewer));   // GH #217
        if (!documentCanViewParent($conn, $viewer, $allowed, $type, $parentId)) { $r['hidden']['documents'] += count($rows); return []; }
        return array_map(fn($d) => $d + ['where' => $where], $rows);
    }

    /** Files that came in with the ticket's emails - name, type, size and which message, not the content. */
    private static function emailAttachments(PDO $conn, int $ticketId): array
    {
        $st = $conn->prepare(
            "SELECT ea.filename, ea.content_type, ea.file_size, ea.is_inline, e.id AS email_id, e.received_datetime, e.from_name, e.from_address
               FROM email_attachments ea JOIN emails e ON e.id = ea.email_id
              WHERE e.ticket_id = ? ORDER BY e.received_datetime, ea.id");
        $st->execute([$ticketId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Lifts every task's (and subtask's) documents into the report's evidence index. */
    private static function collectTaskDocuments(array $blocks, array &$r): void
    {
        foreach ($blocks as $b) {
            foreach ($b['documents'] as $d) $r['evidence']['documents'][] = $d;
            self::collectTaskDocuments($b['subtasks'], $r);
        }
    }

    /** May the analyst see a row in this company? NULL is the Default company. */
    private static function tenantOk(PDO $conn, int $viewer, $tenantId): bool
    {
        if (!isMultiTenant($conn)) return true;
        $t = $tenantId === null ? getDefaultTenantId($conn) : (int)$tenantId;
        return analystCanAccessTenant($conn, $viewer, $t);
    }
}

