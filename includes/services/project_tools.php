<?php
/**
 * ProjectToolsService - the project-management tools on a project (3.2.0
 * phase 2): its people (members and their roles), its scope (deliverables and
 * requirements, prioritised with MoSCoW) and the RACI matrix that joins the two.
 *
 * Kept apart from ProjectsService so neither grows into a thousand-line file;
 * every method still starts from ProjectsService::loadForActor() (company scope
 * as not-found) and ProjectsService::assertCanChange() (Projects -> Settings ->
 * General), so the rules are the same ones.
 *
 * 🔑 RACI rule: at most ONE "A" (accountable) per deliverable. Setting A on a
 * cell demotes any other A in that row to R rather than refusing - the person
 * clicking has just said who is accountable, and the previous one is still doing
 * the work. A row with no A or no R is allowed (a draft) and the screen flags it.
 */

require_once __DIR__ . '/projects.php';

class ProjectToolsService
{
    const MOSCOW = ['must', 'should', 'could', 'wont'];
    const ITEM_STATUSES = ['proposed', 'agreed', 'in_progress', 'accepted', 'dropped'];
    const RACI = ['R', 'A', 'C', 'I'];

    // ======================================================================
    //  People
    // ======================================================================

    /** Add a member: exactly one of analyst_id / team_id / user_id. Returns its id. */
    public static function addMember(PDO $conn, ActorContext $ctx, int $projectId, array $in): int
    {
        $project = self::changeable($conn, $ctx, $projectId);
        $analyst = (int)($in['analyst_id'] ?? 0); $team = (int)($in['team_id'] ?? 0); $user = (int)($in['user_id'] ?? 0);
        if ((($analyst > 0) + ($team > 0) + ($user > 0)) !== 1) {
            throw new ServiceError('validation', 'invalid_field', 'Choose one analyst, team or person.');
        }
        if ($analyst > 0) self::mustExist($conn, "SELECT 1 FROM analysts WHERE id = ? AND is_active = 1", $analyst, 'That analyst does not exist or is inactive.');
        if ($team > 0)    self::mustExist($conn, "SELECT 1 FROM teams WHERE id = ?", $team, 'That team does not exist.');
        if ($user > 0) {
            // A person from People must be in the project's company - a project is
            // one company's work.
            $st = $conn->prepare("SELECT tenant_id FROM users WHERE id = ?");
            $st->execute([$user]);
            $t = $st->fetchColumn();
            if ($t === false) throw new ServiceError('validation', 'invalid_field', 'That person does not exist.');
            if (isMultiTenant($conn)) {
                $def = (int)getDefaultTenantId($conn);
                $pt = $project['tenant_id'] === null ? $def : (int)$project['tenant_id'];
                if (($t === null ? $def : (int)$t) !== $pt) throw new ServiceError('validation', 'invalid_field', 'That person belongs to a different company from the project.');
            }
        }
        $col = $analyst > 0 ? 'analyst_id' : ($team > 0 ? 'team_id' : 'user_id');
        $val = $analyst ?: ($team ?: $user);
        $dup = $conn->prepare("SELECT id FROM project_members WHERE project_id = ? AND $col = ?");
        $dup->execute([$projectId, $val]);
        if ($dup->fetchColumn()) throw new ServiceError('conflict', 'conflict', 'They are already on this project.');
        $role = self::roleId($conn, $in['role_id'] ?? null);
        $pos = (int)$conn->query("SELECT COALESCE(MAX(position), 0) + 1 FROM project_members WHERE project_id = " . $projectId)->fetchColumn();
        $conn->prepare("INSERT INTO project_members (project_id, $col, role_id, notes, position, created_by_analyst_id, created_datetime)
                        VALUES (?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())")
             ->execute([$projectId, $val, $role, self::str($in['notes'] ?? null, 255), $pos, $ctx->actorId > 0 ? $ctx->actorId : null]);
        $id = (int)$conn->lastInsertId();
        ProjectsService::audit($conn, $projectId, $ctx->actorId, 'member_added', null, self::memberName($conn, $id), self::src($ctx));
        return $id;
    }

    public static function updateMember(PDO $conn, ActorContext $ctx, int $projectId, int $memberId, array $in): void
    {
        self::changeable($conn, $ctx, $projectId);
        $m = self::member($conn, $projectId, $memberId);
        $sets = []; $args = [];
        if (array_key_exists('role_id', $in)) { $sets[] = 'role_id = ?'; $args[] = self::roleId($conn, $in['role_id']); }
        if (array_key_exists('notes', $in))   { $sets[] = 'notes = ?';   $args[] = self::str($in['notes'], 255); }
        if (!$sets) return;
        $args[] = $memberId;
        $conn->prepare("UPDATE project_members SET " . implode(', ', $sets) . " WHERE id = ?")->execute($args);
        if (array_key_exists('role_id', $in) && (string)$m['role_id'] !== (string)self::roleId($conn, $in['role_id'])) {
            ProjectsService::audit($conn, $projectId, $ctx->actorId, 'member_role', self::memberName($conn, $memberId), self::roleName($conn, self::roleId($conn, $in['role_id'])), self::src($ctx));
        }
    }

    /** Remove a member. Their RACI letters go with them (FK cascade, and by hand). */
    public static function removeMember(PDO $conn, ActorContext $ctx, int $projectId, int $memberId): void
    {
        self::changeable($conn, $ctx, $projectId);
        self::member($conn, $projectId, $memberId);
        $name = self::memberName($conn, $memberId);
        $conn->prepare("DELETE FROM project_raci WHERE member_id = ?")->execute([$memberId]);
        $conn->prepare("DELETE FROM project_members WHERE id = ?")->execute([$memberId]);
        ProjectsService::audit($conn, $projectId, $ctx->actorId, 'member_removed', $name, null, self::src($ctx));
    }

    // ======================================================================
    //  Scope (deliverables and requirements, MoSCoW)
    // ======================================================================

    public static function saveItem(PDO $conn, ActorContext $ctx, int $projectId, array $in): int
    {
        self::changeable($conn, $ctx, $projectId);
        $id = (int)($in['id'] ?? 0);
        $cur = $id > 0 ? self::item($conn, $projectId, $id) : null;
        $title = trim((string)($in['title'] ?? ($cur['title'] ?? '')));
        if ($title === '') throw new ServiceError('validation', 'missing_field', 'Give it a title.');
        if (mb_strlen($title) > 255) throw new ServiceError('validation', 'invalid_field', 'The title is too long.');
        $moscow = array_key_exists('moscow', $in) ? self::moscow($in['moscow']) : ($cur['moscow'] ?? null);
        $status = array_key_exists('status', $in) ? (string)$in['status'] : ($cur['status'] ?? 'proposed');
        if (!in_array($status, self::ITEM_STATUSES, true)) throw new ServiceError('validation', 'invalid_field', 'Unknown status.');
        $stage = array_key_exists('stage_id', $in) ? self::stageOf($conn, $projectId, $in['stage_id']) : ($cur['stage_id'] ?? null);
        $desc  = array_key_exists('description', $in) ? self::str($in['description'], 20000) : ($cur['description'] ?? null);
        $acc   = array_key_exists('acceptance_criteria', $in) ? self::str($in['acceptance_criteria'], 20000) : ($cur['acceptance_criteria'] ?? null);
        if ($cur) {
            $conn->prepare("UPDATE project_items SET title = ?, description = ?, acceptance_criteria = ?, moscow = ?, stage_id = ?, status = ?, updated_datetime = UTC_TIMESTAMP() WHERE id = ?")
                 ->execute([$title, $desc, $acc, $moscow, $stage, $status, $id]);
            if ((string)$cur['moscow'] !== (string)$moscow) {
                ProjectsService::audit($conn, $projectId, $ctx->actorId, 'item_moscow', $title . ': ' . ($cur['moscow'] ?: '-'), $title . ': ' . ($moscow ?: '-'), self::src($ctx));
            }
            return $id;
        }
        $pos = (int)$conn->query("SELECT COALESCE(MAX(position), 0) + 1 FROM project_items WHERE project_id = " . $projectId)->fetchColumn();
        $conn->prepare("INSERT INTO project_items (project_id, title, description, acceptance_criteria, moscow, stage_id, status, position, created_datetime, updated_datetime)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())")
             ->execute([$projectId, $title, $desc, $acc, $moscow, $stage, $status, $pos]);
        $newId = (int)$conn->lastInsertId();
        ProjectsService::audit($conn, $projectId, $ctx->actorId, 'item_added', null, $title, self::src($ctx));
        return $newId;
    }

    public static function deleteItem(PDO $conn, ActorContext $ctx, int $projectId, int $itemId): void
    {
        self::changeable($conn, $ctx, $projectId);
        $item = self::item($conn, $projectId, $itemId);
        $conn->prepare("DELETE FROM project_raci WHERE item_id = ?")->execute([$itemId]);
        $conn->prepare("UPDATE project_items SET parent_id = NULL WHERE parent_id = ?")->execute([$itemId]);
        $conn->prepare("DELETE FROM project_items WHERE id = ?")->execute([$itemId]);
        ProjectsService::audit($conn, $projectId, $ctx->actorId, 'item_removed', $item['title'], null, self::src($ctx));
    }

    /** Move an item to a MoSCoW column, in the given order within it (the drag on the board). */
    public static function moveItem(PDO $conn, ActorContext $ctx, int $projectId, int $itemId, $moscow, array $orderedIds): void
    {
        self::changeable($conn, $ctx, $projectId);
        $item = self::item($conn, $projectId, $itemId);
        $to = self::moscow($moscow);
        $conn->prepare("UPDATE project_items SET moscow = ?, updated_datetime = UTC_TIMESTAMP() WHERE id = ?")->execute([$to, $itemId]);
        $st = $conn->prepare("UPDATE project_items SET position = ? WHERE id = ? AND project_id = ?");
        $pos = 1;
        foreach ($orderedIds as $oid) $st->execute([$pos++, (int)$oid, $projectId]);
        if ((string)$item['moscow'] !== (string)$to) {
            ProjectsService::audit($conn, $projectId, $ctx->actorId, 'item_moscow', $item['title'] . ': ' . ($item['moscow'] ?: '-'), $item['title'] . ': ' . ($to ?: '-'), self::src($ctx));
        }
    }

    // ======================================================================
    //  RACI
    // ======================================================================

    /**
     * Set one cell: R, A, C, I or '' to clear. Returns the row's letters after
     * the change ({member_id: letter}), so the screen can redraw a demoted A.
     */
    public static function setRaci(PDO $conn, ActorContext $ctx, int $projectId, int $itemId, int $memberId, string $letter): array
    {
        self::changeable($conn, $ctx, $projectId);
        self::item($conn, $projectId, $itemId);
        self::member($conn, $projectId, $memberId);
        $letter = strtoupper(trim($letter));
        if ($letter !== '' && !in_array($letter, self::RACI, true)) throw new ServiceError('validation', 'invalid_field', 'Use R, A, C or I.');
        if ($letter === '') {
            $conn->prepare("DELETE FROM project_raci WHERE item_id = ? AND member_id = ?")->execute([$itemId, $memberId]);
        } else {
            if ($letter === 'A') {
                // One accountable person per deliverable: the previous A keeps doing the work, as R.
                $conn->prepare("UPDATE project_raci SET letter = 'R' WHERE item_id = ? AND letter = 'A' AND member_id <> ?")->execute([$itemId, $memberId]);
            }
            $conn->prepare("INSERT INTO project_raci (project_id, item_id, member_id, letter) VALUES (?, ?, ?, ?)
                            ON DUPLICATE KEY UPDATE letter = VALUES(letter)")->execute([$projectId, $itemId, $memberId, $letter]);
        }
        ProjectsService::touchProject($conn, $projectId);
        $st = $conn->prepare("SELECT member_id, letter FROM project_raci WHERE item_id = ?");
        $st->execute([$itemId]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $out[(int)$r['member_id']] = $r['letter'];
        return $out;
    }

    // ======================================================================
    //  RAID log
    // ======================================================================

    // dependency (3.3.0): something the project needs from outside it - the
    // landlord's fit-out, a supplier's delivery, another project. due_date is
    // when it is needed by; closed = it arrived.
    const RAID_TYPES = ['risk', 'assumption', 'issue', 'dependency', 'decision', 'lesson'];
    const RAID_RESPONSES = ['avoid', 'reduce', 'transfer', 'accept', 'share'];

    /**
     * Create or update a RAID entry. Probability is for risks only and impact for
     * risks and issues (both 1-5); a risk's score is probability x impact (1-25),
     * which is what the heat map and the risk tolerance read.
     *
     * Decisions (3.3.0) also carry the DECISION LOG: decided_by (a name - the
     * person deciding is often a sponsor with no analyst account), decided_date
     * and rationale. Closing a decision means it was made, so a decision closed
     * with no date is stamped today. A decision still open past its due date,
     * like a dependency not arrived by its due date, turns health amber
     * (projectAutoHealth).
     */
    public static function saveRaid(PDO $conn, ActorContext $ctx, int $projectId, array $in): int
    {
        $project = self::changeable($conn, $ctx, $projectId);
        $id = (int)($in['id'] ?? 0);
        $cur = null;
        if ($id > 0) {
            $st = $conn->prepare("SELECT * FROM project_raid WHERE id = ? AND project_id = ?");
            $st->execute([$id, $projectId]);
            $cur = $st->fetch(PDO::FETCH_ASSOC);
            if (!$cur) throw new ServiceError('not_found', 'not_found', 'That entry is not part of this project.');
        }
        $type = (string)($in['type'] ?? ($cur['type'] ?? ''));
        if (!in_array($type, self::RAID_TYPES, true)) throw new ServiceError('validation', 'invalid_field', 'Choose risk, assumption, issue, dependency, decision or lesson.');
        $title = trim((string)($in['title'] ?? ($cur['title'] ?? '')));
        if ($title === '') throw new ServiceError('validation', 'missing_field', 'Give it a title.');
        if (mb_strlen($title) > 255) throw new ServiceError('validation', 'invalid_field', 'The title is too long.');
        $scale = function ($v) { if ($v === null || $v === '') return null; $n = (int)$v; if ($n < 1 || $n > 5) throw new ServiceError('validation', 'invalid_field', 'Use a value from 1 to 5.'); return $n; };
        $prob   = $type === 'risk' ? $scale(array_key_exists('probability', $in) ? $in['probability'] : ($cur['probability'] ?? null)) : null;
        $impact = in_array($type, ['risk', 'issue'], true) ? $scale(array_key_exists('impact', $in) ? $in['impact'] : ($cur['impact'] ?? null)) : null;
        $resp = $type === 'risk' ? (array_key_exists('response', $in) ? ($in['response'] ?: null) : ($cur['response'] ?? null)) : null;
        if ($resp !== null && !in_array($resp, self::RAID_RESPONSES, true)) throw new ServiceError('validation', 'invalid_field', 'Unknown response.');
        $status = (string)($in['status'] ?? ($cur['status'] ?? 'open'));
        if (!in_array($status, ['open', 'closed'], true)) throw new ServiceError('validation', 'invalid_field', 'Unknown status.');
        $owner = array_key_exists('owner_analyst_id', $in) ? ((int)$in['owner_analyst_id'] ?: null) : ($cur['owner_analyst_id'] ?? null);
        if ($owner) self::mustExist($conn, "SELECT 1 FROM analysts WHERE id = ? AND is_active = 1", (int)$owner, 'That analyst does not exist or is inactive.');
        $due = array_key_exists('due_date', $in) ? self::date($in['due_date']) : ($cur['due_date'] ?? null);
        $ticket = array_key_exists('ticket_id', $in) ? ((int)$in['ticket_id'] ?: null) : ($cur['ticket_id'] ?? null);
        if ($ticket) {
            // A linked ticket must be one this analyst can open, in the project's company.
            require_once __DIR__ . '/../projects/links.php';
            $pt = $project['tenant_id'] === null ? (int)getDefaultTenantId($conn) : (int)$project['tenant_id'];
            if (!projectLinkTargetOk($conn, $ctx->actorId, 'ticket', (int)$ticket, $pt)) throw new ServiceError('validation', 'invalid_field', 'That ticket cannot be linked to this project.');
        }
        $desc = array_key_exists('description', $in) ? self::str($in['description'], 20000) : ($cur['description'] ?? null);
        $plan = array_key_exists('response_plan', $in) ? self::str($in['response_plan'], 20000) : ($cur['response_plan'] ?? null);
        $vals = [$type, $title, $desc, $prob, $impact, $resp, $plan, $owner, $status, $due, $ticket];
        // The decision log (3.3.0) - before Database Verification the columns are
        // not there, so they are written only when they exist.
        $logReady = self::raidLogReady($conn);
        $logSql = ''; $logVals = [];
        if ($logReady) {
            $isDecision = $type === 'decision';
            $by  = $isDecision ? (array_key_exists('decided_by', $in) ? self::str($in['decided_by'], 150) : ($cur['decided_by'] ?? null)) : null;
            $on  = $isDecision ? (array_key_exists('decided_date', $in) ? self::date($in['decided_date']) : ($cur['decided_date'] ?? null)) : null;
            $why = $isDecision ? (array_key_exists('rationale', $in) ? self::str($in['rationale'], 20000) : ($cur['rationale'] ?? null)) : null;
            if ($on !== null && $on > gmdate('Y-m-d')) throw new ServiceError('validation', 'invalid_field', 'A decision cannot have been made in the future.');
            if ($isDecision && $status === 'closed' && $on === null) $on = gmdate('Y-m-d');   // decided = closed; when, if nobody said
            // Closing an entry ends its escalation: there is nothing left to escalate.
            $logSql = ', decided_by = ?, decided_date = ?, rationale = ?' . ($status === 'closed' ? ', escalated_datetime = NULL, escalated_by_id = NULL, escalation_note = NULL' : '');
            $logVals = [$by, $on, $why];
        }
        if ($cur) {
            $closedSql = $status === 'closed' && $cur['status'] !== 'closed' ? ', closed_datetime = UTC_TIMESTAMP()' : ($status === 'open' ? ', closed_datetime = NULL' : '');
            $conn->prepare("UPDATE project_raid SET type = ?, title = ?, description = ?, probability = ?, impact = ?, response = ?, response_plan = ?,
                                   owner_analyst_id = ?, status = ?, due_date = ?, ticket_id = ?, updated_datetime = UTC_TIMESTAMP()$closedSql$logSql WHERE id = ?")
                 ->execute(array_merge($vals, $logVals, [$id]));
            if ($logReady && $type === 'decision' && $status === 'closed' && $cur['status'] !== 'closed') {
                ProjectsService::audit($conn, $projectId, $ctx->actorId, 'decision_made', null, $title . ($logVals[0] ? ' (' . $logVals[0] . ')' : ''), self::src($ctx));
            }
            if ($cur['status'] !== $status) ProjectsService::audit($conn, $projectId, $ctx->actorId, 'raid_' . $status, null, $type . ': ' . $title, self::src($ctx));
            ProjectsService::touchProject($conn, $projectId);
            ProjectsService::afterChange($conn, $projectId);   // a risk, tolerance or target can move health or breach a tolerance
            return $id;
        }
        $conn->prepare("INSERT INTO project_raid (type, title, description, probability, impact, response, response_plan, owner_analyst_id, status, due_date, ticket_id,
                                                  project_id, raised_by_id, raised_datetime, updated_datetime, closed_datetime)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP(), " . ($status === 'closed' ? 'UTC_TIMESTAMP()' : 'NULL') . ")")
             ->execute(array_merge($vals, [$projectId, $ctx->actorId > 0 ? $ctx->actorId : null]));
        $newId = (int)$conn->lastInsertId();
        if ($logReady) $conn->prepare("UPDATE project_raid SET decided_by = ?, decided_date = ?, rationale = ? WHERE id = ?")->execute(array_merge($logVals, [$newId]));
        ProjectsService::audit($conn, $projectId, $ctx->actorId, 'raid_added', null, $type . ': ' . $title, self::src($ctx));
        ProjectsService::touchProject($conn, $projectId);
        ProjectsService::afterChange($conn, $projectId);   // a risk, tolerance or target can move health or breach a tolerance
        return $newId;
    }

    public static function deleteRaid(PDO $conn, ActorContext $ctx, int $projectId, int $raidId): void
    {
        self::changeable($conn, $ctx, $projectId);
        $st = $conn->prepare("SELECT type, title FROM project_raid WHERE id = ? AND project_id = ?");
        $st->execute([$raidId, $projectId]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r) throw new ServiceError('not_found', 'not_found', 'That entry is not part of this project.');
        // Its actions are unlinked (the tasks stay) - by hand, for an install whose FK failed to add.
        if (self::raidActionsReady($conn)) $conn->prepare("DELETE FROM project_raid_tasks WHERE raid_id = ?")->execute([$raidId]);
        $conn->prepare("DELETE FROM project_raid WHERE id = ?")->execute([$raidId]);
        ProjectsService::audit($conn, $projectId, $ctx->actorId, 'raid_removed', $r['type'] . ': ' . $r['title'], null, self::src($ctx));
        ProjectsService::afterChange($conn, $projectId);
    }

    /** Has Database Verification added the 3.3.0 RAID columns (escalation, decision log)? */
    public static function raidLogReady(PDO $conn): bool
    {
        static $ready = null;
        if ($ready === null) {
            try { $conn->query("SELECT escalated_datetime, decided_by FROM project_raid LIMIT 0"); $ready = true; }
            catch (Throwable $e) { $ready = false; }
        }
        return $ready;
    }

    /**
     * Escalate an open entry (3.3.0): it needs somebody above the project
     * manager - a sponsor, the board - to act. Stamped with who and when and a
     * note saying what is needed; shown on the Overview until it is
     * de-escalated or the entry is closed; fires project.raid_escalated, so the
     * project manager's bell rings (unless they escalated it) and a workflow can
     * email the sponsor. Escalating again replaces the note and fires again.
     */
    public static function escalateRaid(PDO $conn, ActorContext $ctx, int $projectId, int $raidId, ?string $note): void
    {
        self::changeable($conn, $ctx, $projectId);
        if (!self::raidLogReady($conn)) throw new ServiceError('unavailable', 'not_ready', 'Run Database Verification first.');
        $r = self::raidRow($conn, $projectId, $raidId);
        if ($r['status'] !== 'open') throw new ServiceError('validation', 'invalid_field', 'Only an open entry can be escalated.');
        $note = self::str($note, 500);
        if ($note === null) throw new ServiceError('validation', 'missing_field', 'Say what is needed, and from whom.');
        $conn->prepare("UPDATE project_raid SET escalated_datetime = UTC_TIMESTAMP(), escalated_by_id = ?, escalation_note = ?, updated_datetime = UTC_TIMESTAMP() WHERE id = ?")
             ->execute([$ctx->actorId > 0 ? $ctx->actorId : null, $note, $raidId]);
        ProjectsService::audit($conn, $projectId, $ctx->actorId, 'raid_escalated', null, $r['type'] . ': ' . $r['title'], self::src($ctx));
        ProjectsService::touchProject($conn, $projectId);
        ProjectsService::raidEscalated($conn, $projectId, self::raidRow($conn, $projectId, $raidId));
    }

    public static function deescalateRaid(PDO $conn, ActorContext $ctx, int $projectId, int $raidId): void
    {
        self::changeable($conn, $ctx, $projectId);
        if (!self::raidLogReady($conn)) throw new ServiceError('unavailable', 'not_ready', 'Run Database Verification first.');
        $r = self::raidRow($conn, $projectId, $raidId);
        if (empty($r['escalated_datetime'])) return;
        $conn->prepare("UPDATE project_raid SET escalated_datetime = NULL, escalated_by_id = NULL, escalation_note = NULL, updated_datetime = UTC_TIMESTAMP() WHERE id = ?")->execute([$raidId]);
        ProjectsService::audit($conn, $projectId, $ctx->actorId, 'raid_deescalated', null, $r['type'] . ': ' . $r['title'], self::src($ctx));
        ProjectsService::touchProject($conn, $projectId);
    }

    /**
     * A follow-up action on a RAID entry (3.3.0): a NEW project task, made by
     * createTaskInProject() (TasksService - so the assigned email, the bell and
     * task events happen as for any task), then joined to the entry. It is put
     * in no stage - where it sits in the plan is the Plan's job. Returns the task id.
     */
    public static function addRaidAction(PDO $conn, ActorContext $ctx, int $projectId, int $raidId, array $in): int
    {
        self::changeable($conn, $ctx, $projectId);
        if (!self::raidActionsReady($conn)) throw new ServiceError('unavailable', 'not_ready', 'Run Database Verification first.');
        $r = self::raidRow($conn, $projectId, $raidId);
        $title = trim((string)($in['title'] ?? ''));
        if ($title === '') throw new ServiceError('validation', 'missing_field', 'Say what needs doing.');
        $taskId = ProjectsService::createTaskInProject($conn, $ctx, $projectId, null, array_filter([
            'title' => mb_substr($title, 0, 255),
            'assigned_analyst_id' => isset($in['assigned_analyst_id']) && (int)$in['assigned_analyst_id'] > 0 ? (int)$in['assigned_analyst_id'] : null,
            'due_date' => self::date($in['due_date'] ?? null),
            'description' => 'Follow-up to the ' . $r['type'] . ' "' . $r['title'] . '" in the project\'s RAID log.',
        ], fn($v) => $v !== null));
        $conn->prepare("INSERT INTO project_raid_tasks (raid_id, task_id, created_datetime) VALUES (?, ?, UTC_TIMESTAMP())")->execute([$raidId, $taskId]);
        ProjectsService::audit($conn, $projectId, $ctx->actorId, 'raid_action_added', null, $r['title'] . ': ' . $title, self::src($ctx));
        return $taskId;
    }

    /** Unlink an action. The task itself stays - it is somebody's work. */
    public static function removeRaidAction(PDO $conn, ActorContext $ctx, int $projectId, int $raidId, int $taskId): void
    {
        self::changeable($conn, $ctx, $projectId);
        if (!self::raidActionsReady($conn)) return;
        self::raidRow($conn, $projectId, $raidId);
        $conn->prepare("DELETE FROM project_raid_tasks WHERE raid_id = ? AND task_id = ?")->execute([$raidId, $taskId]);
    }

    public static function raidActionsReady(PDO $conn): bool
    {
        static $ready = null;
        if ($ready === null) {
            try { $conn->query("SELECT 1 FROM project_raid_tasks LIMIT 0"); $ready = true; }
            catch (Throwable $e) { $ready = false; }
        }
        return $ready;
    }

    /**
     * A lesson becomes a Knowledge article, in one click (3.2.0).
     *
     * Saved as a DRAFT through KnowledgeService, like the Knowledge assistant's
     * write-ups: somebody reads it before anyone else can, and it gets the same
     * validation, search entry and history as any article. It belongs to the
     * project's company (an MSP's lesson is that client's until somebody shares
     * it), is linked on the project's Connections tab, and remembered on the
     * lesson so it is made once. Needs Knowledge as well as being allowed to
     * change the project. Returns ['id', 'url'].
     */
    public static function lessonToKnowledge(PDO $conn, ActorContext $ctx, int $projectId, int $raidId): array
    {
        $project = self::changeable($conn, $ctx, $projectId);
        $r = self::raidRow($conn, $projectId, $raidId);
        if ($r['type'] !== 'lesson') throw new ServiceError('validation', 'invalid_field', 'Only a lesson can become a Knowledge article.');
        if (!array_key_exists('knowledge_article_id', $r)) throw new ServiceError('validation', 'not_ready', 'Run System → Database Verification first.');
        if ($r['knowledge_article_id']) throw new ServiceError('conflict', 'conflict', 'This lesson is already a Knowledge article.');
        if ($ctx->actorId > 0 && !analystCanAccessModule($conn, $ctx->actorId, 'knowledge')) {
            throw new ServiceError('forbidden', 'forbidden', 'Turning a lesson into an article needs access to Knowledge.');
        }
        require_once __DIR__ . '/knowledge.php';
        require_once __DIR__ . '/../public_url.php';
        require_once __DIR__ . '/../entity_links.php';
        require_once __DIR__ . '/../projects/read.php';
        $code = projectCode((int)$project['id']);
        $body = '';
        foreach (preg_split('/\n{2,}/', trim((string)$r['description'])) as $para) {
            if (trim($para) !== '') $body .= '<p>' . nl2br(htmlspecialchars(trim($para))) . '</p>';
        }
        $body .= '<p><em>Learned on the project <a href="' . htmlspecialchars(publicAbsoluteUrl($conn, entityLink('project', (int)$project['id']))) . '">'
               . htmlspecialchars($code . ' ' . $project['name']) . '</a>.</em></p>';
        $res = KnowledgeService::saveArticle($conn, $ctx, [
            'title'        => mb_substr($r['title'], 0, 255),
            'body_html'    => $body,
            'is_published' => false,
            'owner_id'     => $ctx->actorId > 0 ? $ctx->actorId : null,
            'tenant_id'    => isMultiTenant($conn) ? ($project['tenant_id'] === null ? (int)getDefaultTenantId($conn) : (int)$project['tenant_id']) : null,
        ]);
        $articleId = (int)$res['id'];
        $conn->prepare("UPDATE project_raid SET knowledge_article_id = ?, updated_datetime = UTC_TIMESTAMP() WHERE id = ?")->execute([$articleId, $raidId]);
        self::linkQuietly($conn, $ctx, $projectId, 'article', $articleId);
        ProjectsService::audit($conn, $projectId, $ctx->actorId, 'raid_to_knowledge', null, $r['title'], self::src($ctx));
        ProjectsService::touchProject($conn, $projectId);
        return ['id' => $articleId, 'url' => entityLink('knowledge_article', $articleId)];
    }

    /**
     * Raise a NEW ticket from an issue (3.2.0) - before, an issue could only be
     * linked to a ticket that already existed.
     *
     * Through TicketsService::createTicket(), so it numbers, routes, notifies and
     * fires ticket.created like any ticket. It is filed in the project's company,
     * raised by (requester) the analyst pressing the button - it is the IT team's
     * own work - and assigned to the issue's owner, else to them, as a ticket
     * made by hand is. The issue then points at it, and the project is linked to
     * it, so it shows on the ticket and on the Connections tab. Needs Tickets.
     * Returns ['id', 'number', 'url'].
     */
    public static function issueToTicket(PDO $conn, ActorContext $ctx, int $projectId, int $raidId): array
    {
        $project = self::changeable($conn, $ctx, $projectId);
        $r = self::raidRow($conn, $projectId, $raidId);
        if ($r['type'] !== 'issue') throw new ServiceError('validation', 'invalid_field', 'Only an issue can raise a ticket.');
        if ($r['ticket_id']) throw new ServiceError('conflict', 'conflict', 'This issue already has a ticket.');
        if ($ctx->actorId <= 0 || !analystCanAccessModule($conn, $ctx->actorId, 'tickets')) {
            throw new ServiceError('forbidden', 'forbidden', 'Raising a ticket needs access to Tickets.');
        }
        $st = $conn->prepare("SELECT full_name, email FROM analysts WHERE id = ?");
        $st->execute([$ctx->actorId]);
        $me = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $email = trim((string)($me['email'] ?? ''));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new ServiceError('validation', 'missing_field', 'Your account has no email address, so it cannot be the ticket\'s requester. Add one under your profile.');
        }
        require_once __DIR__ . '/tickets.php';
        require_once __DIR__ . '/../entity_links.php';
        require_once __DIR__ . '/../projects/read.php';
        $code = projectCode((int)$project['id']);
        $desc = trim((string)$r['description']);
        if ($r['impact']) {
            require_once __DIR__ . '/../projects/settings.php';
            $labels = projectScaleLabels($conn, 'impact');
            $desc .= ($desc !== '' ? "\n\n" : '') . 'Impact: ' . (int)$r['impact'] . ' - ' . ($labels[(int)$r['impact'] - 1] ?? '');
        }
        $desc .= ($desc !== '' ? "\n\n" : '') . 'Raised from the RAID log of project ' . $code . ' ' . $project['name'] . '.';
        $tenant = $project['tenant_id'] === null ? (int)getDefaultTenantId($conn) : (int)$project['tenant_id'];
        $ticketId = TicketsService::createTicket($conn, $ctx, $tenant, [
            'subject'         => mb_substr($r['title'], 0, 255),
            'description'     => $desc,
            'requester_email' => $email,
            'requester_name'  => (string)($me['full_name'] ?? ''),
        ], $r['owner_analyst_id'] ? (int)$r['owner_analyst_id'] : $ctx->actorId, 'Raised from project ' . $code . ' (RAID issue)');
        $conn->prepare("UPDATE project_raid SET ticket_id = ?, updated_datetime = UTC_TIMESTAMP() WHERE id = ?")->execute([$ticketId, $raidId]);
        self::linkQuietly($conn, $ctx, $projectId, 'ticket', $ticketId);
        $num = $conn->prepare("SELECT ticket_number FROM tickets WHERE id = ?");
        $num->execute([$ticketId]);
        $number = (string)$num->fetchColumn();
        ProjectsService::audit($conn, $projectId, $ctx->actorId, 'raid_ticket_raised', null, $number . ' ' . $r['title'], self::src($ctx));
        ProjectsService::touchProject($conn, $projectId);
        return ['id' => $ticketId, 'number' => $number, 'url' => entityLink('ticket', $ticketId)];
    }

    /** One RAID entry of this project, or not found. */
    private static function raidRow(PDO $conn, int $projectId, int $raidId): array
    {
        $st = $conn->prepare("SELECT * FROM project_raid WHERE id = ? AND project_id = ?");
        $st->execute([$raidId, $projectId]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r) throw new ServiceError('not_found', 'not_found', 'That entry is not part of this project.');
        return $r;
    }

    /**
     * Link the new record on the Connections tab too. The record is already
     * made and remembered on the entry, so a link that cannot be added (the
     * tables not verified yet) must not undo it.
     */
    private static function linkQuietly(PDO $conn, ActorContext $ctx, int $projectId, string $kind, int $id): void
    {
        try {
            require_once __DIR__ . '/../projects/links.php';
            projectLinkAdd($conn, $ctx, $projectId, $kind, $id);
        } catch (Throwable $e) {
            error_log('projects: could not link the new ' . $kind . ' ' . $id . ' - ' . $e->getMessage());
        }
    }

    // ======================================================================
    //  Gates: business case (a project field), tolerances, gate decisions
    // ======================================================================

    const GATE_DECISIONS = ['go', 'go_with_conditions', 'stop'];

    /**
     * The project's tolerances - how far it may drift before it is an exception
     * (PRINCE2's "manage by exception", in our own words). time = days past the
     * target finish (or the active stage's end) with work still open; risk = the
     * highest open risk score allowed (1-25). null removes one.
     */
    public static function saveTolerances(PDO $conn, ActorContext $ctx, int $projectId, array $in): void
    {
        self::changeable($conn, $ctx, $projectId);
        $rules = ['time' => [0, 365], 'risk' => [1, 25], 'cost' => [0, 500]];
        foreach ($rules as $dim => [$min, $max]) {
            if (!array_key_exists($dim, $in)) continue;
            $v = $in[$dim];
            if ($v === null || $v === '') {
                $conn->prepare("DELETE FROM project_tolerances WHERE project_id = ? AND stage_id IS NULL AND dimension = ?")->execute([$projectId, $dim]);
                continue;
            }
            if (!preg_match('/^\d+$/', (string)$v) || (int)$v < $min || (int)$v > $max) {
                throw new ServiceError('validation', 'invalid_field', $dim === 'time' ? 'Days late must be from 0 to 365.' : ($dim === 'cost' ? 'The overspend allowed must be from 0 to 500 percent.' : 'The risk score must be from 1 to 25.'));
            }
            $st = $conn->prepare("SELECT id FROM project_tolerances WHERE project_id = ? AND stage_id IS NULL AND dimension = ?");
            $st->execute([$projectId, $dim]);
            if ($tid = $st->fetchColumn()) {
                $conn->prepare("UPDATE project_tolerances SET value = ? WHERE id = ?")->execute([(int)$v, (int)$tid]);
            } else {
                $conn->prepare("INSERT INTO project_tolerances (project_id, stage_id, dimension, value) VALUES (?, NULL, ?, ?)")->execute([$projectId, $dim, (int)$v]);
            }
        }
        ProjectsService::audit($conn, $projectId, $ctx->actorId, 'tolerances', null, null, self::src($ctx));
        ProjectsService::touchProject($conn, $projectId);
        ProjectsService::afterChange($conn, $projectId);   // a risk, tolerance or target can move health or breach a tolerance
    }

    /** {time: int|null, risk: int|null} */
    public static function tolerances(PDO $conn, int $projectId): array
    {
        $out = ['time' => null, 'risk' => null, 'cost' => null];
        try {
            $st = $conn->prepare("SELECT dimension, value FROM project_tolerances WHERE project_id = ? AND stage_id IS NULL");
            $st->execute([$projectId]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $out[$r['dimension']] = (int)$r['value'];
        } catch (Throwable $e) { /* before Verification */ }
        return $out;
    }

    /**
     * Record the gate at the end of a stage. Go (or go with conditions) closes
     * the stage and starts the next planned one, so the decision IS the
     * hand-over; Stop records the decision and leaves the plan alone - what to do
     * next is the board's call, not the software's.
     *
     * @return array{closed:bool, next:?string}
     */
    public static function decideGate(PDO $conn, ActorContext $ctx, int $projectId, int $stageId, string $decision, ?string $notes): array
    {
        self::changeable($conn, $ctx, $projectId);
        if (!in_array($decision, self::GATE_DECISIONS, true)) throw new ServiceError('validation', 'invalid_field', 'Choose go, go with conditions or stop.');
        $st = $conn->prepare("SELECT * FROM project_stages WHERE id = ? AND project_id = ?");
        $st->execute([$stageId, $projectId]);
        $stage = $st->fetch(PDO::FETCH_ASSOC);
        if (!$stage) throw new ServiceError('not_found', 'not_found', 'Stage not found.');
        // A gate is the END of a stage: one that has not started has nothing to
        // decide, and closing it here would skip the one-active-stage rule.
        if ($stage['status'] === 'planned') throw new ServiceError('validation', 'invalid_field', 'That stage has not started yet.');
        $notes = self::str($notes, 20000);
        if ($decision === 'go_with_conditions' && !$notes) throw new ServiceError('validation', 'missing_field', 'Say what the conditions are.');
        // The gate's checklist (3.3.0): an open item blocks a go, or is written into the notes (project_gate_checklist).
        if ($decision !== 'stop') {
            require_once __DIR__ . '/../projects/gatecheck.php';
            $open = projectGateOpenItems($conn, $projectId, $stageId);
            if ($open) {
                $names = implode('; ', array_column($open, 'title'));
                if (projectSetting($conn, 'project_gate_checklist') !== 'warn') {
                    throw new ServiceError('validation', 'checklist_open', count($open) . ' checklist item(s) still open: ' . $names . '.');
                }
                $notes = trim(($notes ? $notes . "\n\n" : '') . 'Still open at the gate: ' . $names . '.');
            }
        }
        $conn->beginTransaction();
        try {
            $conn->prepare("UPDATE project_stages SET gate_decision = ?, gate_notes = ?, gate_decided_by = ?, gate_decided_datetime = UTC_TIMESTAMP(), updated_datetime = UTC_TIMESTAMP() WHERE id = ?")
                 ->execute([$decision, $notes, $ctx->actorId > 0 ? $ctx->actorId : null, $stageId]);
            $closed = false; $next = null; $nextId = null;
            // Only a decision that CLOSES the stage hands over to the next one: a go
            // recorded afterwards on a stage that is already finished must not start
            // another stage while a later one is in progress.
            if ($decision !== 'stop' && $stage['status'] !== 'closed') {
                $conn->prepare("UPDATE project_stages SET status = 'closed' WHERE id = ?")->execute([$stageId]);
                $closed = true;
                $n = $conn->prepare("SELECT id, name FROM project_stages WHERE project_id = ? AND status = 'planned' AND position > ? ORDER BY position, id LIMIT 1");
                $n->execute([$projectId, (int)$stage['position']]);
                if ($row = $n->fetch(PDO::FETCH_ASSOC)) {
                    $conn->prepare("UPDATE project_stages SET status = 'active', updated_datetime = UTC_TIMESTAMP() WHERE id = ?")->execute([(int)$row['id']]);
                    $next = $row['name'];
                    $nextId = (int)$row['id'];
                }
            }
            $conn->commit();
        } catch (Throwable $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            throw $e;
        }
        ProjectsService::audit($conn, $projectId, $ctx->actorId, 'gate', $stage['name'], $decision, self::src($ctx));
        ProjectsService::touchProject($conn, $projectId);
        // A closed stage leaves the Calendar, and is an event of its own.
        if ($closed) {
            ProjectsService::syncCalendar($conn);
            ProjectsService::stageClosed($conn, $projectId, $stageId, $decision, $next);
        }
        // The gate started the next stage: change control (3.3.0) baselines the plan, if the setting says so.
        if ($nextId) { require_once __DIR__ . '/../projects/control.php'; projectBaselineAuto($conn, $projectId, $ctx->actorId, 'stage', $nextId); }
        ProjectsService::afterChange($conn, $projectId);
        return ['closed' => $closed, 'next' => $next];
    }

    public static function raid(PDO $conn, int $projectId): array
    {
        try {
            $logReady = self::raidLogReady($conn);
            $st = $conn->prepare("SELECT r.*, a.full_name AS owner_name, t.ticket_number, t.subject AS ticket_subject,
                                         CASE WHEN r.type = 'risk' AND r.probability IS NOT NULL AND r.impact IS NOT NULL THEN r.probability * r.impact END AS score,
                                         " . ($logReady ? 'ea.full_name' : 'NULL') . " AS escalated_by_name
                                    FROM project_raid r
                               LEFT JOIN analysts a ON a.id = r.owner_analyst_id
                               " . ($logReady ? 'LEFT JOIN analysts ea ON ea.id = r.escalated_by_id' : '') . "
                               LEFT JOIN tickets t ON t.id = r.ticket_id
                                   WHERE r.project_id = ?
                                ORDER BY r.status = 'closed', score IS NULL, score DESC, r.raised_datetime DESC");
            $st->execute([$projectId]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            require_once __DIR__ . '/../entity_links.php';
            // A lesson's article: looked up separately rather than joined, so a
            // RAID log on an install that has not verified the new column still loads.
            $articleIds = array_filter(array_map(fn($r) => (int)($r['knowledge_article_id'] ?? 0), $rows));
            $titles = [];
            if ($articleIds) {
                $in = implode(',', array_unique($articleIds));
                foreach ($conn->query("SELECT id, title, is_published FROM knowledge_articles WHERE id IN ($in)")->fetchAll(PDO::FETCH_ASSOC) as $a) $titles[(int)$a['id']] = $a;
            }
            // Follow-up actions (3.3.0): the linked tasks, with whether each is done.
            $actions = [];
            if ($rows && self::raidActionsReady($conn)) {
                $ids = implode(',', array_map(fn($r) => (int)$r['id'], $rows));
                foreach ($conn->query("SELECT rt.raid_id, tk.id, tk.title, tk.due_date, ts.name AS status_name, ts.colour AS status_colour, COALESCE(ts.is_closed, 0) AS is_closed,
                                              an.full_name AS assignee_name
                                         FROM project_raid_tasks rt JOIN tasks tk ON tk.id = rt.task_id
                                    LEFT JOIN task_statuses ts ON ts.id = tk.status_id
                                    LEFT JOIN analysts an ON an.id = tk.assigned_analyst_id
                                        WHERE rt.raid_id IN ($ids) ORDER BY rt.id")->fetchAll(PDO::FETCH_ASSOC) as $a) {
                    $actions[(int)$a['raid_id']][] = ['id' => (int)$a['id'], 'title' => $a['title'], 'due_date' => $a['due_date'], 'status_name' => $a['status_name'],
                        'status_colour' => $a['status_colour'], 'is_closed' => (bool)(int)$a['is_closed'], 'assignee_name' => $a['assignee_name']];
                }
            }
            foreach ($rows as &$r) {
                $r['actions'] = $actions[(int)$r['id']] ?? [];
                $r['ticket_url'] = $r['ticket_id'] ? entityLink('ticket', (int)$r['ticket_id']) : null;
                $aid = (int)($r['knowledge_article_id'] ?? 0);
                $r['article_url'] = $aid && isset($titles[$aid]) ? entityLink('knowledge_article', $aid) : null;
                $r['article_published'] = $aid && isset($titles[$aid]) ? (bool)(int)$titles[$aid]['is_published'] : null;
            }
            unset($r);
            return $rows;
        } catch (Throwable $e) {
            return [];
        }
    }

    // ======================================================================
    //  Asset targets (3.2.0) - rules in includes/projects/targets.php
    // ======================================================================

    /**
     * Add or change an asset target. Needs the project to be changeable AND
     * Assets access: the rule names asset types, statuses and models, and the
     * dialog's live count would otherwise tell somebody without Assets what is
     * in the estate. Returns its id.
     */
    public static function saveTarget(PDO $conn, ActorContext $ctx, int $projectId, array $in): int
    {
        require_once __DIR__ . '/../projects/targets.php';
        $p = self::changeable($conn, $ctx, $projectId);
        self::assertAssets($conn, $ctx);
        if (!projectTargetsReady($conn)) throw new ServiceError('unavailable', 'not_ready', 'Run Database Verification first.');
        $t = projectTargetNormalise($in);
        if ($t['scope_type_id'] !== null) {
            $ok = in_array($t['scope_type_id'], array_map('intval', array_column(projectTargetOptions($conn, $p)['types'], 'id')), true);
            if (!$ok) throw new ServiceError('validation', 'invalid_field', 'That asset type is not available here.');
        }
        $id = (int)($in['id'] ?? 0);
        if ($id > 0) {
            self::target($conn, $projectId, $id);
            $conn->prepare("UPDATE project_asset_targets SET name = ?, scope = ?, scope_type_id = ?, scope_field = ?, scope_value = ?,
                                   done_field = ?, done_op = ?, done_value = ?, target_date = ?, updated_datetime = UTC_TIMESTAMP() WHERE id = ?")
                 ->execute([$t['name'], $t['scope'], $t['scope_type_id'], $t['scope_field'], $t['scope_value'], $t['done_field'], $t['done_op'], $t['done_value'], $t['target_date'], $id]);
            // The rule changed, so yesterday's points measured something else.
            $conn->prepare("DELETE FROM project_asset_target_snapshots WHERE target_id = ?")->execute([$id]);
        } else {
            $pos = $conn->prepare("SELECT COALESCE(MAX(position), 0) + 1 FROM project_asset_targets WHERE project_id = ?");
            $pos->execute([$projectId]);
            $conn->prepare("INSERT INTO project_asset_targets (project_id, name, scope, scope_type_id, scope_field, scope_value, done_field, done_op, done_value, target_date, position, created_by_analyst_id)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
                 ->execute([$projectId, $t['name'], $t['scope'], $t['scope_type_id'], $t['scope_field'], $t['scope_value'], $t['done_field'], $t['done_op'], $t['done_value'], $t['target_date'], (int)$pos->fetchColumn(), $ctx->actorId > 0 ? $ctx->actorId : null]);
            $id = (int)$conn->lastInsertId();
        }
        ProjectsService::audit($conn, $projectId, $ctx->actorId, 'target_saved', null, $t['name'], self::src($ctx));
        ProjectsService::touchProject($conn, $projectId);
        ProjectsService::afterChange($conn, $projectId);   // a risk, tolerance or target can move health or breach a tolerance
        return $id;
    }

    public static function deleteTarget(PDO $conn, ActorContext $ctx, int $projectId, int $targetId): void
    {
        self::changeable($conn, $ctx, $projectId);
        $t = self::target($conn, $projectId, $targetId);
        $conn->prepare("DELETE FROM project_asset_targets WHERE id = ?")->execute([$targetId]);
        ProjectsService::audit($conn, $projectId, $ctx->actorId, 'target_removed', $t['name'], null, self::src($ctx));
        ProjectsService::touchProject($conn, $projectId);
        ProjectsService::afterChange($conn, $projectId);   // a risk, tolerance or target can move health or breach a tolerance
    }

    /** Assets access, for anything that reads the estate through a target. */
    public static function assertAssets(PDO $conn, ActorContext $ctx): void
    {
        if ($ctx->actorId > 0 && !analystCanAccessModule($conn, $ctx->actorId, 'assets')) {
            throw new ServiceError('forbidden', 'forbidden', 'Asset targets need access to Assets.');
        }
    }

    public static function target(PDO $conn, int $projectId, int $targetId): array
    {
        $st = $conn->prepare("SELECT * FROM project_asset_targets WHERE id = ? AND project_id = ?");
        $st->execute([$targetId, $projectId]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r) throw new ServiceError('not_found', 'not_found', 'That target is not part of this project.');
        return $r;
    }


    private static function date($v): ?string
    {
        if ($v === null || trim((string)$v) === '') return null;
        $s = trim((string)$v);
        $d = DateTime::createFromFormat('!Y-m-d', $s);
        if (!$d || $d->format('Y-m-d') !== $s) throw new ServiceError('bad_request', 'invalid_field', "\"$s\" is not a date.");
        return $s;
    }

    // ======================================================================
    //  Milestones (3.3.0) - includes/projects/milestones.php
    // ======================================================================

    /**
     * Add or change a milestone: {id?, name, due_date, stage_id?, notes?, done?,
     * done_date?}. Any field left out keeps its value, so the Timeline can move
     * just the date. done = true stamps today (or done_date) and who; done =
     * false clears both. Reaching one fires project.milestone_reached.
     * Returns its id.
     */
    public static function saveMilestone(PDO $conn, ActorContext $ctx, int $projectId, array $in): int
    {
        require_once __DIR__ . '/../projects/milestones.php';
        self::changeable($conn, $ctx, $projectId);
        if (!projectMilestonesReady($conn)) throw new ServiceError('unavailable', 'not_ready', 'Run Database Verification first.');
        $id = (int)($in['id'] ?? 0);
        $cur = $id > 0 ? self::milestone($conn, $projectId, $id) : null;

        $name = array_key_exists('name', $in) ? trim((string)$in['name']) : (string)($cur['name'] ?? '');
        if ($name === '') throw new ServiceError('validation', 'missing_field', 'Give the milestone a name.');
        if (mb_strlen($name) > 150) throw new ServiceError('validation', 'invalid_field', 'The name is too long (150 characters at most).');
        $due = array_key_exists('due_date', $in) ? self::date($in['due_date']) : ($cur['due_date'] ?? null);
        if ($due === null) throw new ServiceError('validation', 'missing_field', 'Give the milestone a date.');
        $stage = array_key_exists('stage_id', $in) ? self::stageOf($conn, $projectId, $in['stage_id']) : ($cur ? ($cur['stage_id'] !== null ? (int)$cur['stage_id'] : null) : null);
        $notes = array_key_exists('notes', $in) ? self::str($in['notes'], 500) : ($cur['notes'] ?? null);

        $doneDate = $cur['done_date'] ?? null;
        $doneBy = $cur['done_by_analyst_id'] ?? null;
        if (array_key_exists('done', $in)) {
            if (!empty($in['done'])) {
                $doneDate = self::date($in['done_date'] ?? null) ?? ($doneDate ?: gmdate('Y-m-d'));
                if (empty($cur['done_date'])) $doneBy = $ctx->actorId > 0 ? $ctx->actorId : null;
            } else {
                $doneDate = null; $doneBy = null;
            }
        }
        if ($doneDate !== null && $doneDate > gmdate('Y-m-d')) throw new ServiceError('validation', 'invalid_field', 'A milestone cannot be reached in the future.');

        if ($cur) {
            $conn->prepare("UPDATE project_milestones SET name = ?, due_date = ?, stage_id = ?, notes = ?, done_date = ?, done_by_analyst_id = ?, updated_datetime = UTC_TIMESTAMP() WHERE id = ?")
                 ->execute([$name, $due, $stage, $notes, $doneDate, $doneBy, $id]);
            if ($cur['due_date'] !== $due) ProjectsService::audit($conn, $projectId, $ctx->actorId, 'milestone_moved', $name . ': ' . $cur['due_date'], $name . ': ' . $due, self::src($ctx));
            if (empty($cur['done_date']) && $doneDate !== null) ProjectsService::audit($conn, $projectId, $ctx->actorId, 'milestone_reached', null, $name, self::src($ctx));
            if (!empty($cur['done_date']) && $doneDate === null) ProjectsService::audit($conn, $projectId, $ctx->actorId, 'milestone_reopened', null, $name, self::src($ctx));
        } else {
            $pos = $conn->prepare("SELECT COALESCE(MAX(position), 0) + 1 FROM project_milestones WHERE project_id = ?");
            $pos->execute([$projectId]);
            $conn->prepare("INSERT INTO project_milestones (project_id, stage_id, name, due_date, done_date, done_by_analyst_id, notes, position, created_by_analyst_id, created_datetime, updated_datetime)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())")
                 ->execute([$projectId, $stage, $name, $due, $doneDate, $doneBy, $notes, (int)$pos->fetchColumn(), $ctx->actorId > 0 ? $ctx->actorId : null]);
            $id = (int)$conn->lastInsertId();
            ProjectsService::audit($conn, $projectId, $ctx->actorId, 'milestone_added', null, $name, self::src($ctx));
        }
        ProjectsService::touchProject($conn, $projectId);
        ProjectsService::syncCalendar($conn);
        if (empty($cur['done_date']) && $doneDate !== null) {
            ProjectsService::milestoneReached($conn, $projectId, self::milestone($conn, $projectId, $id));
        }
        ProjectsService::afterChange($conn, $projectId);   // a missed milestone moves health
        return $id;
    }

    public static function deleteMilestone(PDO $conn, ActorContext $ctx, int $projectId, int $milestoneId): void
    {
        require_once __DIR__ . '/../projects/milestones.php';
        self::changeable($conn, $ctx, $projectId);
        $m = self::milestone($conn, $projectId, $milestoneId);
        $conn->prepare("DELETE FROM project_milestones WHERE id = ?")->execute([$milestoneId]);
        ProjectsService::audit($conn, $projectId, $ctx->actorId, 'milestone_removed', $m['name'], null, self::src($ctx));
        ProjectsService::touchProject($conn, $projectId);
        ProjectsService::syncCalendar($conn);
        ProjectsService::afterChange($conn, $projectId);
    }

    private static function milestone(PDO $conn, int $projectId, int $milestoneId): array
    {
        if (!projectMilestonesReady($conn)) throw new ServiceError('not_found', 'not_found', 'That milestone is not part of this project.');
        $st = $conn->prepare("SELECT * FROM project_milestones WHERE id = ? AND project_id = ?");
        $st->execute([$milestoneId, $projectId]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r) throw new ServiceError('not_found', 'not_found', 'That milestone is not part of this project.');
        return $r;
    }

    /**
     * Move a project task's dates from the Timeline: {start_date?, due_date?}.
     *
     * 🔑 Through TasksService - the one task write path - so the task's own
     * history, events and calendar sync happen as if it were moved on the Tasks
     * board. The Projects side only checks that the task IS in this project and
     * that the caller may change the project; Tasks access is not required, the
     * same as adding a task from the Plan.
     */
    public static function setTaskDates(PDO $conn, ActorContext $ctx, int $projectId, int $taskId, array $in): void
    {
        self::changeable($conn, $ctx, $projectId);
        $st = $conn->prepare("SELECT id FROM tasks WHERE id = ? AND project_id = ?");
        $st->execute([$taskId, $projectId]);
        if (!$st->fetchColumn()) throw new ServiceError('not_found', 'not_found', 'That task is not part of this project.');
        $upd = ['id' => $taskId];
        foreach (['start_date', 'due_date'] as $f) if (array_key_exists($f, $in)) $upd[$f] = self::date($in[$f]);
        if (count($upd) === 1) return;
        $s = $upd['start_date'] ?? null; $d = $upd['due_date'] ?? null;
        if ($s && $d && $d < $s) throw new ServiceError('validation', 'invalid_field', 'The due date is before the start date.');
        require_once __DIR__ . '/tasks.php';
        TasksService::saveTask($conn, $ctx, $upd);
        ProjectsService::touchProject($conn, $projectId);
        ProjectsService::afterChange($conn, $projectId);   // an overdue task moves health
    }

    /**
     * Set a project task's estimate from the Plan (3.3.0): {estimate_hours}
     * (empty = not estimated). Through TasksService, like setTaskDates() - the
     * task window and the REST API write the same column the same way.
     */
    public static function setTaskEstimate(PDO $conn, ActorContext $ctx, int $projectId, int $taskId, $hours): void
    {
        self::changeable($conn, $ctx, $projectId);
        $st = $conn->prepare("SELECT id FROM tasks WHERE id = ? AND project_id = ?");
        $st->execute([$taskId, $projectId]);
        if (!$st->fetchColumn()) throw new ServiceError('not_found', 'not_found', 'That task is not part of this project.');
        require_once __DIR__ . '/tasks.php';
        TasksService::saveTask($conn, $ctx, ['id' => $taskId, 'estimate_hours' => $hours]);
        ProjectsService::touchProject($conn, $projectId);
    }

    // ======================================================================
    //  Reads for the project page
    // ======================================================================

    /** Members with display names, roles and kinds, in order. */
    public static function members(PDO $conn, int $projectId): array
    {
        try {
            $st = $conn->prepare(
                "SELECT m.id, m.analyst_id, m.team_id, m.user_id, m.role_id, r.name AS role_name, m.notes, m.position,
                        COALESCE(a.full_name, tm.name, COALESCE(NULLIF(u.display_name, ''), u.email)) AS name,
                        CASE WHEN m.analyst_id IS NOT NULL THEN 'analyst' WHEN m.team_id IS NOT NULL THEN 'team' ELSE 'person' END AS kind,
                        COALESCE(a.email, u.email) AS email, u.job_title
                   FROM project_members m
              LEFT JOIN project_roles r ON r.id = m.role_id
              LEFT JOIN analysts a ON a.id = m.analyst_id
              LEFT JOIN teams tm ON tm.id = m.team_id
              LEFT JOIN users u ON u.id = m.user_id
                  WHERE m.project_id = ? ORDER BY m.position, m.id");
            $st->execute([$projectId]);
            return $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return [];
        }
    }

    public static function items(PDO $conn, int $projectId): array
    {
        try {
            $st = $conn->prepare("SELECT i.*, s.name AS stage_name FROM project_items i LEFT JOIN project_stages s ON s.id = i.stage_id
                                   WHERE i.project_id = ? ORDER BY i.position, i.id");
            $st->execute([$projectId]);
            return $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return [];
        }
    }

    /** {item_id: {member_id: letter}} */
    public static function raci(PDO $conn, int $projectId): array
    {
        $out = [];
        try {
            $st = $conn->prepare("SELECT item_id, member_id, letter FROM project_raci WHERE project_id = ?");
            $st->execute([$projectId]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $out[(int)$r['item_id']][(int)$r['member_id']] = $r['letter'];
        } catch (Throwable $e) { /* before Verification */ }
        return $out;
    }

    // ======================================================================
    //  Task dependencies (3.3.0) - includes/projects/dependencies.php
    // ======================================================================

    /** $taskId waits for $dependsOnId to finish (+ lag days). Both in this project; no loops. Returns its id. */
    public static function addTaskDependency(PDO $conn, ActorContext $ctx, int $projectId, int $taskId, int $dependsOnId, $lag): int
    {
        require_once __DIR__ . '/../projects/dependencies.php';
        self::changeable($conn, $ctx, $projectId);
        if (!projectDependenciesReady($conn)) throw new ServiceError('validation', 'not_ready', 'Run System - Database Verification first.');
        if ($taskId <= 0 || $dependsOnId <= 0) throw new ServiceError('validation', 'missing_field', 'Choose both tasks.');
        if ($taskId === $dependsOnId) throw new ServiceError('validation', 'invalid_field', 'A task cannot wait for itself.');
        $st = $conn->prepare("SELECT id, title FROM tasks WHERE id IN (?, ?) AND project_id = ?");
        $st->execute([$taskId, $dependsOnId, $projectId]);
        $titles = $st->fetchAll(PDO::FETCH_KEY_PAIR);
        if (count($titles) !== 2) throw new ServiceError('validation', 'invalid_field', 'Both tasks must be in this project.');
        $lag = ($lag === null || $lag === '') ? 0 : $lag;
        if (!preg_match('/^-?\d{1,3}$/', (string)$lag) || abs((int)$lag) > 365) throw new ServiceError('validation', 'invalid_field', 'The gap is a number of days, up to 365 either way.');
        if (projectDependencyMakesCycle(projectDependencies($conn, $projectId), $taskId, $dependsOnId)) {
            throw new ServiceError('validation', 'invalid_field', 'That would make a loop: "' . $titles[$dependsOnId] . '" already waits for "' . $titles[$taskId] . '".');
        }
        $conn->prepare("INSERT INTO task_dependencies (task_id, depends_on_id, lag_days, created_by_id, created_datetime) VALUES (?, ?, ?, ?, UTC_TIMESTAMP())
                        ON DUPLICATE KEY UPDATE lag_days = VALUES(lag_days)")
             ->execute([$taskId, $dependsOnId, (int)$lag, $ctx->actorId > 0 ? $ctx->actorId : null]);
        $st = $conn->prepare("SELECT id FROM task_dependencies WHERE task_id = ? AND depends_on_id = ?");
        $st->execute([$taskId, $dependsOnId]);
        ProjectsService::audit($conn, $projectId, $ctx->actorId, 'dependency_added', null, $titles[$taskId] . ' <- ' . $titles[$dependsOnId], self::src($ctx));
        ProjectsService::touchProject($conn, $projectId);
        return (int)$st->fetchColumn();
    }

    public static function removeTaskDependency(PDO $conn, ActorContext $ctx, int $projectId, int $depId): void
    {
        require_once __DIR__ . '/../projects/dependencies.php';
        self::changeable($conn, $ctx, $projectId);
        $mine = array_column(projectDependencies($conn, $projectId), null, 'id');
        if (!isset($mine[$depId])) throw new ServiceError('not_found', 'not_found', 'That dependency is not part of this project.');
        $conn->prepare("DELETE FROM task_dependencies WHERE id = ?")->execute([$depId]);
        ProjectsService::audit($conn, $projectId, $ctx->actorId, 'dependency_removed', null, null, self::src($ctx));
        ProjectsService::touchProject($conn, $projectId);
    }

    // ======================================================================
    //  Gate checklists (3.3.0) - includes/projects/gatecheck.php
    // ======================================================================

    /** Add (no id) or change a gate item. Returns its id. */
    public static function saveGateItem(PDO $conn, ActorContext $ctx, int $projectId, array $in): int
    {
        require_once __DIR__ . '/../projects/gatecheck.php';
        self::changeable($conn, $ctx, $projectId);
        if (!projectGateItemsReady($conn)) throw new ServiceError('validation', 'not_ready', 'Run System - Database Verification first.');
        $id = (int)($in['id'] ?? 0);
        $cur = $id > 0 ? self::gateItem($conn, $projectId, $id) : null;
        $stageId = $cur ? (int)$cur['stage_id'] : (int)(self::stageOf($conn, $projectId, $in['stage_id'] ?? null) ?? 0);
        if ($stageId <= 0) throw new ServiceError('validation', 'missing_field', 'Choose the gate.');
        $kind = $cur ? $cur['kind'] : (string)($in['kind'] ?? 'check');
        if (!in_array($kind, PROJECT_GATE_ITEM_KINDS, true)) throw new ServiceError('validation', 'invalid_field', 'Unknown kind of item.');
        $title = trim((string)($in['title'] ?? ($cur['title'] ?? '')));
        if ($title === '') throw new ServiceError('validation', 'missing_field', 'Say what has to be done.');
        if (mb_strlen($title) > 200) throw new ServiceError('validation', 'invalid_field', 'That is too long.');
        $analyst = $kind === 'signoff' ? (array_key_exists('analyst_id', $in) ? ((int)$in['analyst_id'] ?: null) : ($cur['analyst_id'] ?? null)) : null;
        if ($analyst) self::mustExist($conn, "SELECT 1 FROM analysts WHERE id = ? AND is_active = 1", (int)$analyst, 'That analyst does not exist or is inactive.');
        $change = $kind === 'change' ? (array_key_exists('change_id', $in) ? ((int)$in['change_id'] ?: null) : ($cur['change_id'] ?? null)) : null;
        if ($change && !in_array((int)$change, array_column(projectGateChanges($conn, $projectId), 'id'), true)) {
            throw new ServiceError('validation', 'invalid_field', 'Link the change to the project on the Connections tab first.');
        }
        $notes = array_key_exists('notes', $in) ? self::str($in['notes'], 500) : ($cur['notes'] ?? null);
        if ($cur) {
            // A different person to sign: the old sign-off no longer counts.
            $resign = $kind === 'signoff' && (int)$cur['analyst_id'] !== (int)$analyst;
            $conn->prepare("UPDATE project_gate_items SET title = ?, analyst_id = ?, change_id = ?, notes = ?" . ($resign ? ", done_by_id = NULL, done_datetime = NULL" : "") . " WHERE id = ?")
                 ->execute([$title, $analyst, $change, $notes, $id]);
            if ($resign && $analyst) ProjectsService::signoffEvent($conn, $projectId, $id);
        } else {
            $pos = (int)$conn->query("SELECT COALESCE(MAX(position), 0) + 1 FROM project_gate_items WHERE stage_id = " . $stageId)->fetchColumn();
            $conn->prepare("INSERT INTO project_gate_items (project_id, stage_id, kind, title, analyst_id, change_id, notes, position, created_by_id, created_datetime)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())")
                 ->execute([$projectId, $stageId, $kind, $title, $analyst, $change, $notes, $pos, $ctx->actorId > 0 ? $ctx->actorId : null]);
            $id = (int)$conn->lastInsertId();
            ProjectsService::audit($conn, $projectId, $ctx->actorId, 'gate_item_added', null, $title, self::src($ctx));
            if ($analyst) ProjectsService::signoffEvent($conn, $projectId, $id);
        }
        ProjectsService::touchProject($conn, $projectId);
        return $id;
    }

    public static function deleteGateItem(PDO $conn, ActorContext $ctx, int $projectId, int $itemId): void
    {
        self::changeable($conn, $ctx, $projectId);
        $i = self::gateItem($conn, $projectId, $itemId);
        $conn->prepare("DELETE FROM project_gate_items WHERE id = ?")->execute([$itemId]);
        ProjectsService::audit($conn, $projectId, $ctx->actorId, 'gate_item_removed', $i['title'], null, self::src($ctx));
        ProjectsService::touchProject($conn, $projectId);
    }

    /**
     * Tick or untick an item. A check: anybody who may change the project. A
     * sign-off: ONLY its named analyst. A document: choose one of the project's
     * documents (document_id; empty unticks). A change cannot be ticked - it is
     * done when the change is approved.
     */
    public static function tickGateItem(PDO $conn, ActorContext $ctx, int $projectId, int $itemId, array $in): void
    {
        require_once __DIR__ . '/../projects/gatecheck.php';
        $p = ProjectsService::loadForActor($conn, $ctx, $projectId);
        $i = self::gateItem($conn, $projectId, $itemId);
        $done = !empty($in['done']);
        $who = $ctx->actorId > 0 ? $ctx->actorId : null;
        switch ($i['kind']) {
            case 'change':
                throw new ServiceError('validation', 'invalid_field', 'This item is done when the change is approved, in Changes.');
            case 'signoff':
                if ((int)$i['analyst_id'] !== $ctx->actorId) throw new ServiceError('forbidden', 'forbidden', $i['analyst_id'] ? 'Only the person named can sign this off.' : 'Choose who signs this off first.');
                $conn->prepare("UPDATE project_gate_items SET done_by_id = ?, done_datetime = " . ($done ? 'UTC_TIMESTAMP()' : 'NULL') . ", notes = COALESCE(?, notes) WHERE id = ?")
                     ->execute([$done ? $who : null, self::str($in['notes'] ?? null, 500), $itemId]);
                break;
            case 'document':
                ProjectsService::assertCanChange($conn, $ctx, $p);
                $doc = !empty($in['document_id']) ? (int)$in['document_id'] : null;
                if ($doc && !in_array($doc, array_column(projectGateDocuments($conn, $projectId), 'id'), true)) {
                    throw new ServiceError('validation', 'invalid_field', 'Attach the document to the project on its Documents tab first.');
                }
                $conn->prepare("UPDATE project_gate_items SET document_id = ?, done_by_id = ?, done_datetime = " . ($doc ? 'UTC_TIMESTAMP()' : 'NULL') . " WHERE id = ?")
                     ->execute([$doc, $doc ? $who : null, $itemId]);
                $done = (bool)$doc;
                break;
            default:
                ProjectsService::assertCanChange($conn, $ctx, $p);
                $conn->prepare("UPDATE project_gate_items SET done_by_id = ?, done_datetime = " . ($done ? 'UTC_TIMESTAMP()' : 'NULL') . " WHERE id = ?")
                     ->execute([$done ? $who : null, $itemId]);
        }
        ProjectsService::audit($conn, $projectId, $ctx->actorId, $i['kind'] === 'signoff' ? ($done ? 'gate_signed' : 'gate_unsigned') : ($done ? 'gate_item_done' : 'gate_item_reopened'), null, $i['title'], self::src($ctx));
        ProjectsService::touchProject($conn, $projectId);
    }

    /** standard | golive. Making a gate go-live adds the starter items it does not already have. */
    public static function setGateKind(PDO $conn, ActorContext $ctx, int $projectId, int $stageId, string $kind): int
    {
        require_once __DIR__ . '/../projects/gatecheck.php';
        $p = self::changeable($conn, $ctx, $projectId);
        if (!in_array($kind, ['standard', 'golive'], true)) throw new ServiceError('validation', 'invalid_field', 'Choose a standard or a go-live gate.');
        $stageId = (int)self::stageOf($conn, $projectId, $stageId);
        if (!$stageId) throw new ServiceError('validation', 'missing_field', 'Choose the gate.');
        $conn->prepare("UPDATE project_stages SET gate_kind = ?, updated_datetime = UTC_TIMESTAMP() WHERE id = ?")->execute([$kind, $stageId]);
        $added = 0;
        if ($kind === 'golive') {
            $have = $conn->prepare("SELECT LOWER(title) FROM project_gate_items WHERE stage_id = ?");
            $have->execute([$stageId]);
            $titles = $have->fetchAll(PDO::FETCH_COLUMN);
            foreach (projectGoLiveStarter() as [$k, $title]) {
                // Skipped only when the gate already has it by name: a gate can need several sign-offs.
                if (in_array(mb_strtolower($title), $titles, true)) continue;
                self::saveGateItem($conn, $ctx, $projectId, ['stage_id' => $stageId, 'kind' => $k, 'title' => $title,
                    'analyst_id' => $k === 'signoff' ? ($p['owner_analyst_id'] ?? null) : null]);
                $added++;
            }
        }
        ProjectsService::audit($conn, $projectId, $ctx->actorId, 'gate_kind', null, $kind, self::src($ctx));
        ProjectsService::touchProject($conn, $projectId);
        return $added;
    }

    private static function gateItem(PDO $conn, int $projectId, int $itemId): array
    {
        try {
            $st = $conn->prepare("SELECT * FROM project_gate_items WHERE id = ? AND project_id = ?");
            $st->execute([$itemId, $projectId]);
            $r = $st->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            throw new ServiceError('validation', 'not_ready', 'Run System - Database Verification first.');
        }
        if (!$r) throw new ServiceError('not_found', 'not_found', 'That item is not on this project\'s gates.');
        return $r;
    }

    // ======================================================================
    //  Benefits (3.3.0) - includes/projects/benefits.php
    // ======================================================================

    /** Create (no id) or update a benefit. Returns its id. */
    public static function saveBenefit(PDO $conn, ActorContext $ctx, int $projectId, array $in): int
    {
        require_once __DIR__ . '/../projects/benefits.php';
        self::changeable($conn, $ctx, $projectId);
        if (!projectBenefitsReady($conn)) throw new ServiceError('validation', 'not_ready', 'Run System - Database Verification first.');
        $title = trim((string)($in['title'] ?? ''));
        if ($title === '') throw new ServiceError('validation', 'missing_field', 'Say what the benefit is.');
        if (mb_strlen($title) > 200) throw new ServiceError('validation', 'invalid_field', 'The benefit is too long.');
        $num = function ($v, string $what) {
            if ($v === null || trim((string)$v) === '') return null;
            $s = str_replace([',', ' '], '', trim((string)$v));
            if (!preg_match('/^-?\d{1,15}(\.\d{1,2})?$/', $s)) throw new ServiceError('validation', 'invalid_field', "The $what must be a number, like 12 or 12.5.");
            return $s;
        };
        $direction = ($in['direction'] ?? 'up') === 'down' ? 'down' : 'up';
        $months = $in['review_months'] ?? null;
        if ($months === null || $months === '') $months = (int)projectSetting($conn, 'project_benefit_review_months');
        elseif (!preg_match('/^\d+$/', (string)$months) || (int)$months > 24) throw new ServiceError('validation', 'invalid_field', 'Review every 0 to 24 months.');
        $status = ($in['status'] ?? 'open') === 'closed' ? 'closed' : 'open';
        $owner = !empty($in['owner_analyst_id']) ? (int)$in['owner_analyst_id'] : null;
        if ($owner) self::mustExist($conn, "SELECT 1 FROM analysts WHERE id = ? AND is_active = 1", $owner, 'That analyst does not exist or is inactive.');
        $vals = [
            $title, self::str($in['measure'] ?? null, 255), self::str($in['unit'] ?? null, 30), $direction,
            $num($in['baseline_value'] ?? null, 'baseline'), $num($in['target_value'] ?? null, 'target'), self::date($in['target_date'] ?? null),
            $owner, self::date($in['review_date'] ?? null), (int)$months, $status, self::str($in['notes'] ?? null, 5000),
        ];
        $id = (int)($in['id'] ?? 0);
        if ($id > 0) {
            $b = self::benefit($conn, $projectId, $id);
            $conn->prepare("UPDATE project_benefits SET title = ?, measure = ?, unit = ?, direction = ?, baseline_value = ?, target_value = ?, target_date = ?,
                                   owner_analyst_id = ?, review_date = ?, review_months = ?, status = ?, notes = ?, updated_datetime = UTC_TIMESTAMP() WHERE id = ?")
                 ->execute(array_merge($vals, [$id]));
            ProjectsService::audit($conn, $projectId, $ctx->actorId, 'benefit_changed', null, $title, self::src($ctx));
        } else {
            // A first review the setting's months from today, unless one was given.
            if ($vals[8] === null && (int)$months > 0) $vals[8] = projectBenefitNextReview(gmdate('Y-m-d'), (int)$months);
            $pos = (int)$conn->query("SELECT COALESCE(MAX(position), 0) + 1 FROM project_benefits WHERE project_id = " . $projectId)->fetchColumn();
            $conn->prepare("INSERT INTO project_benefits (title, measure, unit, direction, baseline_value, target_value, target_date, owner_analyst_id, review_date, review_months, status, notes,
                                                          project_id, position, created_by_id, created_datetime, updated_datetime)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())")
                 ->execute(array_merge($vals, [$projectId, $pos, $ctx->actorId > 0 ? $ctx->actorId : null]));
            $id = (int)$conn->lastInsertId();
            ProjectsService::audit($conn, $projectId, $ctx->actorId, 'benefit_added', null, $title, self::src($ctx));
        }
        ProjectsService::touchProject($conn, $projectId);
        return $id;
    }

    public static function deleteBenefit(PDO $conn, ActorContext $ctx, int $projectId, int $benefitId): void
    {
        self::changeable($conn, $ctx, $projectId);
        $b = self::benefit($conn, $projectId, $benefitId);
        $conn->prepare("DELETE FROM project_benefit_measures WHERE benefit_id = ?")->execute([$benefitId]);   // by hand: an install whose FK failed
        $conn->prepare("DELETE FROM project_benefits WHERE id = ?")->execute([$benefitId]);
        ProjectsService::audit($conn, $projectId, $ctx->actorId, 'benefit_removed', $b['title'], null, self::src($ctx));
        ProjectsService::touchProject($conn, $projectId);
    }

    /**
     * Record a measurement. One on or after (review date - 14 days) is the
     * review: the next one moves on by the benefit's review_months (none when 0).
     * Returns the measurement's id.
     */
    public static function addBenefitMeasure(PDO $conn, ActorContext $ctx, int $projectId, int $benefitId, array $in): int
    {
        require_once __DIR__ . '/../projects/benefits.php';
        self::changeable($conn, $ctx, $projectId);
        $b = self::benefit($conn, $projectId, $benefitId);
        $v = str_replace([',', ' '], '', trim((string)($in['value'] ?? '')));
        if (!preg_match('/^-?\d{1,15}(\.\d{1,2})?$/', $v)) throw new ServiceError('validation', 'invalid_field', 'Enter the value measured, like 12 or 12.5.');
        $date = self::date($in['measured_date'] ?? null) ?? gmdate('Y-m-d');
        if ($date > gmdate('Y-m-d')) throw new ServiceError('validation', 'invalid_field', 'A measurement cannot be in the future.');
        $conn->prepare("INSERT INTO project_benefit_measures (benefit_id, value, measured_date, note, recorded_by_id, created_datetime) VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP())")
             ->execute([$benefitId, $v, $date, self::str($in['note'] ?? null, 500), $ctx->actorId > 0 ? $ctx->actorId : null]);
        $id = (int)$conn->lastInsertId();
        $early = gmdate('Y-m-d', strtotime(($b['review_date'] ?: '9999-12-31') . ' 00:00:00 UTC') - PROJECT_BENEFIT_EARLY_DAYS * 86400);
        if ($b['status'] === 'open' && (!$b['review_date'] || $date >= $early)) {
            $next = projectBenefitNextReview(max($date, (string)$b['review_date']), $b['review_months'] !== null ? (int)$b['review_months'] : 0);
            $conn->prepare("UPDATE project_benefits SET review_date = ?, updated_datetime = UTC_TIMESTAMP() WHERE id = ?")->execute([$next, $benefitId]);
        }
        ProjectsService::audit($conn, $projectId, $ctx->actorId, 'benefit_measured', null, $b['title'] . ': ' . $v . ($b['unit'] ? ' ' . $b['unit'] : ''), self::src($ctx));
        ProjectsService::touchProject($conn, $projectId);
        return $id;
    }

    public static function deleteBenefitMeasure(PDO $conn, ActorContext $ctx, int $projectId, int $benefitId, int $measureId): void
    {
        self::changeable($conn, $ctx, $projectId);
        self::benefit($conn, $projectId, $benefitId);
        $st = $conn->prepare("DELETE FROM project_benefit_measures WHERE id = ? AND benefit_id = ?");
        $st->execute([$measureId, $benefitId]);
        if ($st->rowCount() !== 1) throw new ServiceError('not_found', 'not_found', 'That measurement is not part of this benefit.');
        ProjectsService::touchProject($conn, $projectId);
    }

    private static function benefit(PDO $conn, int $projectId, int $benefitId): array
    {
        try {
            $st = $conn->prepare("SELECT * FROM project_benefits WHERE id = ? AND project_id = ?");
            $st->execute([$benefitId, $projectId]);
            $r = $st->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            throw new ServiceError('validation', 'not_ready', 'Run System - Database Verification first.');
        }
        if (!$r) throw new ServiceError('not_found', 'not_found', 'That benefit is not part of this project.');
        return $r;
    }

    // ======================================================================
    //  Change control (3.3.0) - includes/projects/control.php
    // ======================================================================

    const CR_DECISIONS = ['approved', 'rejected'];

    /** Take a baseline of the plan as it is now, by hand. Returns its id. */
    public static function takeBaseline(PDO $conn, ActorContext $ctx, int $projectId, $label): int
    {
        require_once __DIR__ . '/../projects/control.php';
        self::changeable($conn, $ctx, $projectId);
        if (!projectControlReady($conn)) throw new ServiceError('validation', 'not_ready', 'Run System - Database Verification first.');
        $label = self::str($label, 150);
        $id = projectTakeBaseline($conn, $projectId, $ctx->actorId, 'manual', $label);
        $n = (int)$conn->query("SELECT number FROM project_baselines WHERE id = " . $id)->fetchColumn();
        ProjectsService::audit($conn, $projectId, $ctx->actorId, 'baseline_taken', null, 'Baseline ' . $n . ($label ? ': ' . $label : ''), self::src($ctx));
        ProjectsService::touchProject($conn, $projectId);
        return $id;
    }

    /**
     * Raise a change request (no id), or edit one still waiting for a decision -
     * by whoever raised it, or anybody who may change the project. Returns its id.
     */
    public static function saveChangeRequest(PDO $conn, ActorContext $ctx, int $projectId, array $in): int
    {
        require_once __DIR__ . '/../projects/control.php';
        require_once __DIR__ . '/../projects/budget.php';
        $id = (int)($in['id'] ?? 0);
        $cur = null;
        if ($id > 0) {
            $p = ProjectsService::loadForActor($conn, $ctx, $projectId);
            $cur = self::changeRequest($conn, $projectId, $id);
            if ($cur['status'] !== 'proposed') throw new ServiceError('validation', 'invalid_field', 'Only a request waiting for a decision can be changed.');
            if ((int)$cur['raised_by_id'] !== $ctx->actorId) ProjectsService::assertCanChange($conn, $ctx, $p);
        } else {
            self::changeable($conn, $ctx, $projectId);
        }
        if (!projectControlReady($conn)) throw new ServiceError('validation', 'not_ready', 'Run System - Database Verification first.');
        $title = trim((string)($in['title'] ?? ''));
        if ($title === '') throw new ServiceError('validation', 'missing_field', 'Say what the change is.');
        if (mb_strlen($title) > 200) throw new ServiceError('validation', 'invalid_field', 'The title is too long.');
        $days = $in['impact_days'] ?? null;
        if ($days === '' || $days === null) $days = null;
        elseif (!preg_match('/^[+-]?\d{1,4}$/', trim((string)$days)) || abs((int)$days) > 3650) throw new ServiceError('validation', 'invalid_field', 'The time impact is a number of days, up to 3650 either way.');
        else $days = (int)$days;
        $cost = projectMoney($in['impact_cost'] ?? null);
        $scope = self::str($in['impact_scope'] ?? null, 1000);
        $desc = self::str($in['description'] ?? null, 20000);
        $reason = self::str($in['reason'] ?? null, 20000);

        if ($cur) {
            $conn->prepare("UPDATE project_change_requests SET title = ?, description = ?, reason = ?, impact_days = ?, impact_cost = ?, impact_scope = ?, updated_datetime = UTC_TIMESTAMP() WHERE id = ?")
                 ->execute([$title, $desc, $reason, $days, $cost, $scope, $id]);
            ProjectsService::audit($conn, $projectId, $ctx->actorId, 'change_edited', null, 'CR-' . $cur['number'] . ': ' . $title, self::src($ctx));
            ProjectsService::touchProject($conn, $projectId);
            return $id;
        }
        $n = (int)$conn->query("SELECT COALESCE(MAX(number), 0) + 1 FROM project_change_requests WHERE project_id = " . $projectId)->fetchColumn();
        $conn->prepare("INSERT INTO project_change_requests (project_id, number, title, description, reason, impact_days, impact_cost, impact_scope, status, raised_by_id, raised_datetime, updated_datetime)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'proposed', ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())")
             ->execute([$projectId, $n, $title, $desc, $reason, $days, $cost, $scope, $ctx->actorId > 0 ? $ctx->actorId : null]);
        $id = (int)$conn->lastInsertId();
        ProjectsService::audit($conn, $projectId, $ctx->actorId, 'change_raised', null, 'CR-' . $n . ': ' . $title, self::src($ctx));
        ProjectsService::touchProject($conn, $projectId);
        ProjectsService::changeEvent($conn, $projectId, 'project.change_raised', self::changeRequest($conn, $projectId, $id));
        return $id;
    }

    /**
     * Approve or reject a change request. Approving takes a new baseline - the
     * changed plan becomes the agreed one - and, when project_change_apply is
     * "plan", first moves the target finish by the days and adds the cost as a
     * budget line, recording both in `applied`. Rejecting changes nothing.
     *
     * @return array{baseline_id:?int, applied:?array}
     */
    public static function decideChangeRequest(PDO $conn, ActorContext $ctx, int $projectId, int $crId, string $decision, $notes): array
    {
        require_once __DIR__ . '/../projects/control.php';
        $p = ProjectsService::loadForActor($conn, $ctx, $projectId);
        if (!in_array($decision, self::CR_DECISIONS, true)) throw new ServiceError('validation', 'invalid_field', 'Approve or reject.');
        $cr = self::changeRequest($conn, $projectId, $crId);
        if ($cr['status'] !== 'proposed') throw new ServiceError('validation', 'invalid_field', 'That request has already been decided.');
        if (!projectCanDecideChange($conn, $ctx->actorId, $p, $cr['raised_by_id'] !== null ? (int)$cr['raised_by_id'] : null)) {
            throw new ServiceError('forbidden', 'forbidden', (int)$cr['raised_by_id'] === $ctx->actorId && projectSetting($conn, 'project_change_self') !== '1'
                ? 'Somebody else has to decide a request you raised.' : 'You may not decide change requests on this project.');
        }
        $notes = self::str($notes, 20000);
        $label = 'CR-' . $cr['number'] . ': ' . $cr['title'];
        $baselineId = null; $applied = null; $moved = false;
        $conn->beginTransaction();
        try {
            // Compare-and-set: two people pressing Approve at once decide it once.
            $u = $conn->prepare("UPDATE project_change_requests SET status = ?, decided_by_id = ?, decided_datetime = UTC_TIMESTAMP(), decision_notes = ?, updated_datetime = UTC_TIMESTAMP()
                                  WHERE id = ? AND status = 'proposed'");
            $u->execute([$decision, $ctx->actorId > 0 ? $ctx->actorId : null, $notes, $crId]);
            if ($u->rowCount() !== 1) throw new ServiceError('validation', 'invalid_field', 'That request has already been decided.');
            if ($decision === 'approved') {
                if (projectSetting($conn, 'project_change_apply') === 'plan') {
                    $applied = [];
                    $days = $cr['impact_days'] !== null ? (int)$cr['impact_days'] : 0;
                    if ($days !== 0 && !empty($p['target_end_date'])) {
                        $to = gmdate('Y-m-d', strtotime($p['target_end_date'] . ' 00:00:00 UTC') + $days * 86400);
                        $conn->prepare("UPDATE projects SET target_end_date = ? WHERE id = ?")->execute([$to, $projectId]);
                        ProjectsService::audit($conn, $projectId, $ctx->actorId, 'target_end_date', $p['target_end_date'], $to, self::src($ctx));
                        $applied['target_from'] = $p['target_end_date']; $applied['target_to'] = $to;
                        $moved = true;
                    }
                    $cost = $cr['impact_cost'] !== null ? (float)$cr['impact_cost'] : 0.0;
                    require_once __DIR__ . '/../projects/budget.php';
                    if ($cost != 0 && projectBudgetReady($conn)) {
                        projectStampCurrency($conn, $projectId);
                        $pos = (int)$conn->query("SELECT COALESCE(MAX(position), 0) + 1 FROM project_budget_lines WHERE project_id = " . $projectId)->fetchColumn();
                        $conn->prepare("INSERT INTO project_budget_lines (project_id, title, category, planned_amount, notes, position, created_by_id, created_datetime, updated_datetime)
                                        VALUES (?, ?, 'other', ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())")
                             ->execute([$projectId, mb_substr($label, 0, 200), $cost, mb_substr('Approved change request CR-' . $cr['number'], 0, 500), $pos, $ctx->actorId > 0 ? $ctx->actorId : null]);
                        $applied['budget_line_id'] = (int)$conn->lastInsertId(); $applied['budget_amount'] = $cost;
                        ProjectsService::audit($conn, $projectId, $ctx->actorId, 'budget_line_added', null, mb_substr($label, 0, 200), self::src($ctx));
                    }
                    if (!$applied) $applied = null;
                }
                $baselineId = projectTakeBaseline($conn, $projectId, $ctx->actorId, 'change', null, null, $crId);
                $conn->prepare("UPDATE project_change_requests SET baseline_id = ?, applied = ? WHERE id = ?")
                     ->execute([$baselineId, $applied ? json_encode($applied) : null, $crId]);
            }
            $conn->commit();
        } catch (Throwable $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            throw $e;
        }
        ProjectsService::audit($conn, $projectId, $ctx->actorId, $decision === 'approved' ? 'change_approved' : 'change_rejected', null, $label, self::src($ctx));
        ProjectsService::touchProject($conn, $projectId);
        if ($moved) ProjectsService::syncCalendar($conn);
        ProjectsService::changeEvent($conn, $projectId, 'project.change_decided', self::changeRequest($conn, $projectId, $crId));
        ProjectsService::afterChange($conn, $projectId);   // a moved target or a new budget line can move health
        return ['baseline_id' => $baselineId, 'applied' => $applied];
    }

    /** Withdraw a request still waiting for a decision: whoever raised it, or the project's team. */
    public static function withdrawChangeRequest(PDO $conn, ActorContext $ctx, int $projectId, int $crId): void
    {
        $p = ProjectsService::loadForActor($conn, $ctx, $projectId);
        $cr = self::changeRequest($conn, $projectId, $crId);
        if ($cr['status'] !== 'proposed') throw new ServiceError('validation', 'invalid_field', 'That request has already been decided.');
        if ((int)$cr['raised_by_id'] !== $ctx->actorId) ProjectsService::assertCanChange($conn, $ctx, $p);
        $conn->prepare("UPDATE project_change_requests SET status = 'withdrawn', updated_datetime = UTC_TIMESTAMP() WHERE id = ? AND status = 'proposed'")->execute([$crId]);
        ProjectsService::audit($conn, $projectId, $ctx->actorId, 'change_withdrawn', null, 'CR-' . $cr['number'] . ': ' . $cr['title'], self::src($ctx));
        ProjectsService::touchProject($conn, $projectId);
    }

    private static function changeRequest(PDO $conn, int $projectId, int $crId): array
    {
        try {
            $st = $conn->prepare("SELECT * FROM project_change_requests WHERE id = ? AND project_id = ?");
            $st->execute([$crId, $projectId]);
            $r = $st->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            throw new ServiceError('validation', 'not_ready', 'Run System - Database Verification first.');
        }
        if (!$r) throw new ServiceError('not_found', 'not_found', 'That change request is not part of this project.');
        return $r;
    }

    // ======================================================================
    //  Helpers
    // ======================================================================

    private static function changeable(PDO $conn, ActorContext $ctx, int $projectId): array
    {
        $p = ProjectsService::loadForActor($conn, $ctx, $projectId);
        ProjectsService::assertCanChange($conn, $ctx, $p);
        return $p;
    }

    private static function member(PDO $conn, int $projectId, int $memberId): array
    {
        $st = $conn->prepare("SELECT * FROM project_members WHERE id = ? AND project_id = ?");
        $st->execute([$memberId, $projectId]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r) throw new ServiceError('not_found', 'not_found', 'They are not on this project.');
        return $r;
    }

    private static function item(PDO $conn, int $projectId, int $itemId): array
    {
        $st = $conn->prepare("SELECT * FROM project_items WHERE id = ? AND project_id = ?");
        $st->execute([$itemId, $projectId]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r) throw new ServiceError('not_found', 'not_found', 'That deliverable is not part of this project.');
        return $r;
    }

    private static function moscow($v): ?string
    {
        if ($v === null || $v === '') return null;
        $v = strtolower((string)$v);
        if (!in_array($v, self::MOSCOW, true)) throw new ServiceError('validation', 'invalid_field', 'Use Must, Should, Could or Won\'t.');
        return $v;
    }

    private static function stageOf(PDO $conn, int $projectId, $stageId): ?int
    {
        if ($stageId === null || $stageId === '' || (int)$stageId <= 0) return null;
        $st = $conn->prepare("SELECT id FROM project_stages WHERE id = ? AND project_id = ?");
        $st->execute([(int)$stageId, $projectId]);
        if (!$st->fetchColumn()) throw new ServiceError('validation', 'invalid_field', 'That stage is not part of this project.');
        return (int)$stageId;
    }

    private static function roleId(PDO $conn, $v): ?int
    {
        if ($v === null || $v === '' || (int)$v <= 0) return null;
        $st = $conn->prepare("SELECT id FROM project_roles WHERE id = ?");
        $st->execute([(int)$v]);
        if (!$st->fetchColumn()) throw new ServiceError('validation', 'invalid_field', 'Unknown role.');
        return (int)$v;
    }

    private static function roleName(PDO $conn, ?int $id): ?string
    {
        if (!$id) return null;
        $st = $conn->prepare("SELECT name FROM project_roles WHERE id = ?");
        $st->execute([$id]);
        return ($n = $st->fetchColumn()) !== false ? (string)$n : null;
    }

    private static function memberName(PDO $conn, int $memberId): string
    {
        $st = $conn->prepare("SELECT COALESCE(a.full_name, tm.name, COALESCE(NULLIF(u.display_name, ''), u.email)) FROM project_members m
                                LEFT JOIN analysts a ON a.id = m.analyst_id LEFT JOIN teams tm ON tm.id = m.team_id LEFT JOIN users u ON u.id = m.user_id
                               WHERE m.id = ?");
        $st->execute([$memberId]);
        return (string)($st->fetchColumn() ?: ('#' . $memberId));
    }

    private static function mustExist(PDO $conn, string $sql, int $id, string $msg): void
    {
        $st = $conn->prepare($sql);
        $st->execute([$id]);
        if (!$st->fetchColumn()) throw new ServiceError('validation', 'invalid_field', $msg);
    }

    private static function str($v, int $max): ?string
    {
        if ($v === null) return null;
        $s = trim((string)$v);
        return $s === '' ? null : mb_substr($s, 0, $max);
    }

    private static function src(ActorContext $ctx): string
    {
        return $ctx->source === 'api' ? 'api' : 'app';
    }

    // ======================================================================
    //  Budget (3.2.0) - includes/projects/budget.php
    // ======================================================================

    /** Create (no id) or update a budget line. Returns its id. */
    public static function saveBudgetLine(PDO $conn, ActorContext $ctx, int $projectId, array $in): int
    {
        require_once __DIR__ . '/../projects/budget.php';
        $p = self::changeable($conn, $ctx, $projectId);
        if (!projectBudgetReady($conn)) throw new ServiceError('validation', 'not_ready', 'Run System - Database Verification first.');
        $title = trim((string)($in['title'] ?? ''));
        if ($title === '') throw new ServiceError('validation', 'missing_field', 'Give the line a name.');
        if (mb_strlen($title) > 200) throw new ServiceError('validation', 'invalid_field', 'The name is too long.');
        $cat = (string)($in['category'] ?? 'other');
        if (!in_array($cat, PROJECT_BUDGET_CATEGORIES, true)) throw new ServiceError('validation', 'invalid_field', 'Unknown category.');
        $planned = projectMoney($in['planned'] ?? null);
        $actual  = projectMoney($in['actual'] ?? null);
        $notes = trim((string)($in['notes'] ?? '')) ?: null;
        if ($notes !== null && mb_strlen($notes) > 500) throw new ServiceError('validation', 'invalid_field', 'The notes are too long.');
        // 3.3.0: when the money goes out, when it went, and what the line is now expected to cost.
        $plannedDate = self::date($in['planned_date'] ?? null);
        $spentDate   = self::date($in['spent_date'] ?? null);
        if ($spentDate !== null && $spentDate > gmdate('Y-m-d')) throw new ServiceError('validation', 'invalid_field', 'The date it was spent cannot be in the future.');
        $forecast    = projectMoney($in['forecast'] ?? null);

        // A contract: one linked to the project on Connections, by somebody who can open Contracts.
        $contractId = !empty($in['contract_id']) ? (int)$in['contract_id'] : null;
        if ($contractId !== null) {
            if ($ctx->actorId > 0 && !analystCanAccessModule($conn, $ctx->actorId, 'contracts')) throw new ServiceError('forbidden', 'forbidden', 'You need Contracts to name a contract.');
            if (!in_array($contractId, array_column(projectBudgetContracts($conn, $projectId), 'id'), true)) {
                throw new ServiceError('validation', 'invalid_field', 'Link the contract to the project on the Connections tab first.');
            }
        }
        // A cost centre: active, and in the project's company.
        $ccId = !empty($in['cost_centre_id']) ? (int)$in['cost_centre_id'] : null;
        $id = (int)($in['id'] ?? 0);
        if ($ccId !== null && !in_array($ccId, array_column(projectBudgetCostCentres($conn, $p), 'id'), true)) {
            // An existing line keeps a cost centre that has since been switched off.
            $keep = $id > 0 ? $conn->prepare("SELECT 1 FROM project_budget_lines WHERE id = ? AND cost_centre_id = ?") : null;
            if (!$keep || !$keep->execute([$id, $ccId]) || !$keep->fetchColumn()) throw new ServiceError('validation', 'invalid_field', 'Choose an active cost centre of the project\'s company.');
        }
        projectStampCurrency($conn, $projectId);

        if ($id > 0) {
            $st = $conn->prepare("SELECT title FROM project_budget_lines WHERE id = ? AND project_id = ?");
            $st->execute([$id, $projectId]);
            if ($st->fetchColumn() === false) throw new ServiceError('not_found', 'not_found', 'That line is not part of this project.');
            $conn->prepare("UPDATE project_budget_lines SET title = ?, category = ?, planned_amount = ?, actual_amount = ?, contract_id = ?, cost_centre_id = ?, notes = ?, updated_datetime = UTC_TIMESTAMP() WHERE id = ?")
                 ->execute([$title, $cat, $planned, $actual, $contractId, $ccId, $notes, $id]);
            self::budgetLineWhen($conn, $id, $plannedDate, $spentDate, $forecast);
            ProjectsService::audit($conn, $projectId, $ctx->actorId, 'budget_line_changed', null, $title, self::src($ctx));
        } else {
            $pos = (int)$conn->query("SELECT COALESCE(MAX(position), 0) + 1 FROM project_budget_lines WHERE project_id = " . $projectId)->fetchColumn();
            $conn->prepare("INSERT INTO project_budget_lines (project_id, title, category, planned_amount, actual_amount, contract_id, cost_centre_id, notes, position, created_by_id, created_datetime, updated_datetime)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())")
                 ->execute([$projectId, $title, $cat, $planned, $actual, $contractId, $ccId, $notes, $pos, $ctx->actorId > 0 ? $ctx->actorId : null]);
            $id = (int)$conn->lastInsertId();
            self::budgetLineWhen($conn, $id, $plannedDate, $spentDate, $forecast);
            ProjectsService::audit($conn, $projectId, $ctx->actorId, 'budget_line_added', null, $title, self::src($ctx));
        }
        ProjectsService::touchProject($conn, $projectId);
        ProjectsService::afterChange($conn, $projectId);   // spend can breach the cost tolerance
        return $id;
    }

    /** The 3.3.0 columns, on their own: before Database Verification adds them the rest of the line still saves. */
    private static function budgetLineWhen(PDO $conn, int $lineId, ?string $plannedDate, ?string $spentDate, ?float $forecast): void
    {
        try {
            $conn->prepare("UPDATE project_budget_lines SET planned_date = ?, spent_date = ?, forecast_amount = ? WHERE id = ?")->execute([$plannedDate, $spentDate, $forecast, $lineId]);
        } catch (Throwable $e) {
            if ($plannedDate !== null || $spentDate !== null || $forecast !== null) throw new ServiceError('validation', 'not_ready', 'Run System - Database Verification to add dates and forecasts to budget lines.');
        }
    }

    public static function deleteBudgetLine(PDO $conn, ActorContext $ctx, int $projectId, int $lineId): void
    {
        self::changeable($conn, $ctx, $projectId);
        $st = $conn->prepare("SELECT title FROM project_budget_lines WHERE id = ? AND project_id = ?");
        $st->execute([$lineId, $projectId]);
        $title = $st->fetchColumn();
        if ($title === false) throw new ServiceError('not_found', 'not_found', 'That line is not part of this project.');
        $conn->prepare("DELETE FROM project_budget_lines WHERE id = ?")->execute([$lineId]);
        ProjectsService::audit($conn, $projectId, $ctx->actorId, 'budget_line_removed', $title, null, self::src($ctx));
        ProjectsService::touchProject($conn, $projectId);
        ProjectsService::afterChange($conn, $projectId);
    }

    /**
     * The project's own hourly rate from a date (labour mode "rate"), in the
     * project's currency. Each rate is a new row: the old one still prices the
     * time logged before the new one's date.
     */
    public static function addProjectRate(PDO $conn, ActorContext $ctx, int $projectId, $rate, ?string $from): void
    {
        require_once __DIR__ . '/../projects/budget.php';
        self::changeable($conn, $ctx, $projectId);
        if (projectSetting($conn, 'project_labour_mode') !== 'rate') throw new ServiceError('validation', 'invalid_field', 'Projects - Settings - Budget does not use a rate per project.');
        $r = projectMoney($rate);
        if ($r === null || $r < 0) throw new ServiceError('validation', 'invalid_field', 'Enter an hourly rate.');
        $from = $from ?: gmdate('Y-m-d');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) throw new ServiceError('validation', 'invalid_field', 'Enter the date it applies from.');
        projectStampCurrency($conn, $projectId);
        $conn->prepare("INSERT INTO project_labour_rates (scope, ref_id, hourly_rate, effective_from, created_by_id, created_datetime) VALUES ('project', ?, ?, ?, ?, UTC_TIMESTAMP())")
             ->execute([$projectId, $r, $from, $ctx->actorId > 0 ? $ctx->actorId : null]);
        projectLabourRatesReset();
        ProjectsService::audit($conn, $projectId, $ctx->actorId, 'labour_rate', null, $r . ' from ' . $from, self::src($ctx));
        ProjectsService::afterChange($conn, $projectId);
    }

    /** Remove one of the project's own rates (entered by mistake). */
    public static function deleteProjectRate(PDO $conn, ActorContext $ctx, int $projectId, string $from): void
    {
        require_once __DIR__ . '/../projects/budget.php';
        self::changeable($conn, $ctx, $projectId);
        $conn->prepare("DELETE FROM project_labour_rates WHERE scope = 'project' AND ref_id = ? AND effective_from = ?")->execute([$projectId, $from]);
        projectLabourRatesReset();
        ProjectsService::audit($conn, $projectId, $ctx->actorId, 'labour_rate_removed', 'from ' . $from, null, self::src($ctx));
        ProjectsService::afterChange($conn, $projectId);
    }

    /**
     * Change the project's currency - a RELABEL: amounts are not converted, and
     * the page says so before anyone presses Save. Only when Projects -> Settings
     * -> Budget lets projects choose.
     */
    public static function setCurrency(PDO $conn, ActorContext $ctx, int $projectId, string $code): void
    {
        require_once __DIR__ . '/../projects/budget.php';
        $p = self::changeable($conn, $ctx, $projectId);
        if (projectSetting($conn, 'project_currency_per_project') !== '1') throw new ServiceError('forbidden', 'forbidden', 'Every project uses the install\'s currency (Projects - Settings - Budget).');
        $code = strtoupper(trim($code));
        if (!projectValidCurrency($code)) throw new ServiceError('validation', 'invalid_field', 'Enter a three-letter currency code, like GBP, EUR or USD.');
        $old = projectCurrencyOf($conn, $p);
        if ($old === $code) return;
        $conn->prepare("UPDATE projects SET currency = ?, updated_datetime = UTC_TIMESTAMP() WHERE id = ?")->execute([$code, $projectId]);
        ProjectsService::audit($conn, $projectId, $ctx->actorId, 'currency', $old, $code, self::src($ctx));
        ProjectsService::afterChange($conn, $projectId);
    }

    // ======================================================================
    //  Going live safely: announce disruption on Service Status (3.2.0)
    // ======================================================================

    /**
     * Announce planned disruption from a project - "Move day: phones down
     * Saturday". How depends on Projects -> Settings -> General:
     *   planned  planned maintenance: upcoming until its start, then an incident
     *   now      the same, starting now - an incident straight away
     *   off      refused (the button is not shown)
     * Needs Service Status as well as being allowed to change the project.
     * Service Status is install-wide, like contracts: no company check on the
     * services. Returns the planned maintenance id.
     */
    public static function announce(PDO $conn, ActorContext $ctx, int $projectId, array $in): int
    {
        $p = self::changeable($conn, $ctx, $projectId);
        $mode = projectSetting($conn, 'project_disruption');
        if ($mode === 'off') throw new ServiceError('forbidden', 'forbidden', 'Announcing disruption from projects is switched off in Projects - Settings.');
        if ($ctx->actorId > 0 && !analystCanAccessModule($conn, $ctx->actorId, 'service-status')) {
            throw new ServiceError('forbidden', 'forbidden', 'You need Service Status to announce disruption.');
        }
        require_once __DIR__ . '/../service_status_planned.php';
        $data = [
            'title'      => $in['title'] ?? '',
            'comment'    => $in['comment'] ?? null,
            'start'      => $mode === 'now' ? gmdate('Y-m-d H:i:s') : ($in['start'] ?? null),
            'end'        => $in['end'] ?? null,
            'services'   => is_array($in['services'] ?? null) ? $in['services'] : [],
            'project_id' => $projectId,
        ];
        if ($mode === 'planned' && empty($data['start'])) throw new ServiceError('validation', 'missing_field', 'Say when it starts.');
        $id = statusPlannedSave($conn, $ctx, $data);
        ProjectsService::audit($conn, $projectId, $ctx->actorId, 'disruption_announced', null, trim((string)$data['title']), self::src($ctx));
        ProjectsService::touchProject($conn, $projectId);
        return $id;
    }

    /** Withdraw an announcement that has not started. It must belong to this project. */
    public static function withdrawAnnouncement(PDO $conn, ActorContext $ctx, int $projectId, int $plannedId): void
    {
        self::changeable($conn, $ctx, $projectId);
        if ($ctx->actorId > 0 && !analystCanAccessModule($conn, $ctx->actorId, 'service-status')) {
            throw new ServiceError('forbidden', 'forbidden', 'You need Service Status to change an announcement.');
        }
        require_once __DIR__ . '/../service_status_planned.php';
        $row = statusPlannedLoad($conn, $plannedId);
        if ((int)$row['project_id'] !== $projectId) throw new ServiceError('not_found', 'not_found', 'Planned maintenance not found.');
        statusPlannedCancel($conn, $ctx, $plannedId);
        ProjectsService::audit($conn, $projectId, $ctx->actorId, 'disruption_withdrawn', null, $row['title'], self::src($ctx));
    }

    /**
     * What the Connections tab needs: the mode, this project's announcements,
     * and the services and impact levels for the dialog. null when the analyst
     * cannot open Service Status - then the panel is not drawn at all.
     */
    public static function announcements(PDO $conn, int $analystId, int $projectId): ?array
    {
        if (!analystCanAccessModule($conn, $analystId, 'service-status')) return null;
        require_once __DIR__ . '/../service_status_planned.php';
        if (!statusPlannedReady($conn)) return null;
        statusPlannedDue($conn);
        return [
            'mode'     => projectSetting($conn, 'project_disruption'),
            'list'     => statusPlannedList($conn, ['project_id' => $projectId, 'states' => STATUS_PLANNED_STATES, 'limit' => 50]),
            'services' => $conn->query("SELECT id, name FROM status_services WHERE is_active = 1 ORDER BY display_order, name")->fetchAll(PDO::FETCH_ASSOC),
            'impacts'  => $conn->query("SELECT id, name, colour, is_default, counts_as_downtime, severity_order FROM service_impact_levels WHERE is_active = 1 ORDER BY severity_order")->fetchAll(PDO::FETCH_ASSOC),
        ];
    }
}
