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

    const RAID_TYPES = ['risk', 'assumption', 'issue', 'decision', 'lesson'];
    const RAID_RESPONSES = ['avoid', 'reduce', 'transfer', 'accept', 'share'];

    /**
     * Create or update a RAID entry. Probability is for risks only and impact for
     * risks and issues (both 1-5); a risk's score is probability x impact (1-25),
     * which is what the heat map and the risk tolerance read.
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
        if (!in_array($type, self::RAID_TYPES, true)) throw new ServiceError('validation', 'invalid_field', 'Choose risk, assumption, issue, decision or lesson.');
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
        if ($cur) {
            $closedSql = $status === 'closed' && $cur['status'] !== 'closed' ? ', closed_datetime = UTC_TIMESTAMP()' : ($status === 'open' ? ', closed_datetime = NULL' : '');
            $conn->prepare("UPDATE project_raid SET type = ?, title = ?, description = ?, probability = ?, impact = ?, response = ?, response_plan = ?,
                                   owner_analyst_id = ?, status = ?, due_date = ?, ticket_id = ?, updated_datetime = UTC_TIMESTAMP()$closedSql WHERE id = ?")
                 ->execute(array_merge($vals, [$id]));
            if ($cur['status'] !== $status) ProjectsService::audit($conn, $projectId, $ctx->actorId, 'raid_' . $status, null, $type . ': ' . $title, self::src($ctx));
            ProjectsService::touchProject($conn, $projectId);
            return $id;
        }
        $conn->prepare("INSERT INTO project_raid (type, title, description, probability, impact, response, response_plan, owner_analyst_id, status, due_date, ticket_id,
                                                  project_id, raised_by_id, raised_datetime, updated_datetime, closed_datetime)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP(), " . ($status === 'closed' ? 'UTC_TIMESTAMP()' : 'NULL') . ")")
             ->execute(array_merge($vals, [$projectId, $ctx->actorId > 0 ? $ctx->actorId : null]));
        $newId = (int)$conn->lastInsertId();
        ProjectsService::audit($conn, $projectId, $ctx->actorId, 'raid_added', null, $type . ': ' . $title, self::src($ctx));
        ProjectsService::touchProject($conn, $projectId);
        return $newId;
    }

    public static function deleteRaid(PDO $conn, ActorContext $ctx, int $projectId, int $raidId): void
    {
        self::changeable($conn, $ctx, $projectId);
        $st = $conn->prepare("SELECT type, title FROM project_raid WHERE id = ? AND project_id = ?");
        $st->execute([$raidId, $projectId]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r) throw new ServiceError('not_found', 'not_found', 'That entry is not part of this project.');
        $conn->prepare("DELETE FROM project_raid WHERE id = ?")->execute([$raidId]);
        ProjectsService::audit($conn, $projectId, $ctx->actorId, 'raid_removed', $r['type'] . ': ' . $r['title'], null, self::src($ctx));
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
        $rules = ['time' => [0, 365], 'risk' => [1, 25]];
        foreach ($rules as $dim => [$min, $max]) {
            if (!array_key_exists($dim, $in)) continue;
            $v = $in[$dim];
            if ($v === null || $v === '') {
                $conn->prepare("DELETE FROM project_tolerances WHERE project_id = ? AND stage_id IS NULL AND dimension = ?")->execute([$projectId, $dim]);
                continue;
            }
            if (!preg_match('/^\d+$/', (string)$v) || (int)$v < $min || (int)$v > $max) {
                throw new ServiceError('validation', 'invalid_field', $dim === 'time' ? 'Days late must be from 0 to 365.' : 'The risk score must be from 1 to 25.');
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
    }

    /** {time: int|null, risk: int|null} */
    public static function tolerances(PDO $conn, int $projectId): array
    {
        $out = ['time' => null, 'risk' => null];
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
        $conn->beginTransaction();
        try {
            $conn->prepare("UPDATE project_stages SET gate_decision = ?, gate_notes = ?, gate_decided_by = ?, gate_decided_datetime = UTC_TIMESTAMP(), updated_datetime = UTC_TIMESTAMP() WHERE id = ?")
                 ->execute([$decision, $notes, $ctx->actorId > 0 ? $ctx->actorId : null, $stageId]);
            $closed = false; $next = null;
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
                }
            }
            $conn->commit();
        } catch (Throwable $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            throw $e;
        }
        ProjectsService::audit($conn, $projectId, $ctx->actorId, 'gate', $stage['name'], $decision, self::src($ctx));
        ProjectsService::touchProject($conn, $projectId);
        return ['closed' => $closed, 'next' => $next];
    }

    public static function raid(PDO $conn, int $projectId): array
    {
        try {
            $st = $conn->prepare("SELECT r.*, a.full_name AS owner_name, t.ticket_number, t.subject AS ticket_subject,
                                         CASE WHEN r.type = 'risk' AND r.probability IS NOT NULL AND r.impact IS NOT NULL THEN r.probability * r.impact END AS score
                                    FROM project_raid r
                               LEFT JOIN analysts a ON a.id = r.owner_analyst_id
                               LEFT JOIN tickets t ON t.id = r.ticket_id
                                   WHERE r.project_id = ?
                                ORDER BY r.status = 'closed', score IS NULL, score DESC, r.raised_datetime DESC");
            $st->execute([$projectId]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            require_once __DIR__ . '/../entity_links.php';
            foreach ($rows as &$r) $r['ticket_url'] = $r['ticket_id'] ? entityLink('ticket', (int)$r['ticket_id']) : null;
            unset($r);
            return $rows;
        } catch (Throwable $e) {
            return [];
        }
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
}
