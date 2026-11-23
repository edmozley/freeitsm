<?php
/**
 * Approval steps decided by PEOPLE in the portal (GH #160 Part C2, #348 slice 2).
 *
 * C1's steps are decided by staff: a named analyst or a team. Managers and budget
 * holders usually are not staff - they are people (users), and FreeITSM keeps their
 * manager and their cost centres against that table. So two more kinds of step:
 *
 *   'manager'   the requester's manager (users.manager_id)
 *   'cc_owner'  the owner of a cost centre - the one chosen in a question, or the
 *               requester's own - and its deputy. The owner is a person or a people
 *               group (any member decides for the group); the deputy is a person.
 *
 * They decide on the portal's Approvals page (self-service/approvals.php).
 *
 * 🔑 WHO DECIDES IS WORKED OUT AT EVERY DECISION, NEVER STORED. The request keeps the
 * KIND of step (form_submissions.approver_kind) and, for an owner step, WHICH cost
 * centre (approval_cost_centre_id - fixed by the answers). Who the manager or the
 * owner is, is asked each time, the rule team membership already follows: a leaver
 * cannot approve, and their replacement can.
 *
 * The rules (wiki Financial-Approvals-Design 6.2):
 *   - the requester never decides their own step. If they own the cost centre, the
 *     deputy decides; with no deputy, the step's fallback; with none, Stuck;
 *   - the deputy is a setting (Forms -> Settings -> Approvals): ANY TIME (owner and
 *     deputy are equals, the first to decide settles it - the default) or ONLY AS
 *     COVER: when nobody else can, when the owner has marked themselves away, or
 *     after a chosen number of days without a decision;
 *   - nobody found at a step means its fallback (a staff person or team), never skipped;
 *   - somebody who approves one step and may also decide the next (a manager who owns
 *     the cost centre too) approves ONCE: the next step is recorded "Already approved
 *     by" and passes with it (decision 3).
 *
 * The staff side (catalogue_approvals.php) sees these as policy mode 'people': staff
 * cannot decide them, an administrator can Override or Move them, as any step.
 */

// t() for the approver's email and the ticket body - a portal endpoint may not have loaded it.
require_once __DIR__ . '/i18n.php';

/**
 * Are the C2 columns there? Before Database Verification they are not, and the two
 * new kinds of step cannot be chosen or reached.
 */
function cataloguePeopleAvailable(PDO $conn): bool {
    static $known = null;
    if ($known !== null) return $known;
    try {
        $known = (int)$conn->query(
            "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE()
               AND ((table_name = 'form_submissions' AND column_name IN ('approver_kind', 'approval_cost_centre_id',
                        'approval_decided_by_user_id', 'approval_step_datetime', 'approval_reminded_datetime', 'requester_cost_centre_id'))
                 OR (table_name = 'form_submission_approval_events' AND column_name = 'user_id')
                 OR (table_name = 'users' AND column_name = 'approvals_away_until'))"
        )->fetchColumn() === 8;
    } catch (Throwable $e) {
        $known = false;
    }
    return $known;
}

/** One Forms -> Settings -> Approvals value, or the default. */
function catalogueApprovalSetting(PDO $conn, string $key, string $default): string {
    try {
        $st = $conn->prepare('SELECT setting_value FROM system_settings WHERE setting_key = ?');
        $st->execute([$key]);
        $v = $st->fetchColumn();
        return ($v === false || $v === null || $v === '') ? $default : (string)$v;
    } catch (Throwable $e) {
        return $default;
    }
}

/**
 * Write one Forms -> Settings -> Approvals value.
 */
function catalogueApprovalSettingSave(PDO $conn, string $key, string $value): void {
    $u = $conn->prepare('UPDATE system_settings SET setting_value = ?, updated_datetime = UTC_TIMESTAMP() WHERE setting_key = ?');
    $u->execute([$value, $key]);
    if ($u->rowCount()) return;
    $has = $conn->prepare('SELECT 1 FROM system_settings WHERE setting_key = ?');
    $has->execute([$key]);
    if ($has->fetchColumn()) return;   // same value as before
    $conn->prepare('INSERT INTO system_settings (setting_key, setting_value, updated_datetime) VALUES (?, ?, UTC_TIMESTAMP())')->execute([$key, $value]);
}

/**
 * The install's approval settings that C2 adds (Forms -> Settings -> Approvals).
 *
 * @return array{deputy_mode:string, deputy_days:int, mailbox_id:int, remind:bool}
 */
function catalogueApprovalOptions(PDO $conn): array {
    $mode = catalogueApprovalSetting($conn, 'forms_approval_deputy_mode', 'any');
    return [
        'deputy_mode' => $mode === 'cover' ? 'cover' : 'any',
        'deputy_days' => max(0, min(60, (int)catalogueApprovalSetting($conn, 'forms_approval_deputy_days', '0'))),
        'mailbox_id'  => (int)catalogueApprovalSetting($conn, 'forms_approval_mailbox_id', '0'),
        'remind'      => catalogueApprovalSetting($conn, 'forms_approval_remind', '1') === '1',
    ];
}

/**
 * The cost centre an owner step routes to, decided at submission: the answer to the
 * cost-centre question the step names (by label, like a condition), or - with none
 * named - the requester's own. Null when there is none: the step then finds nobody
 * and goes to its fallback.
 *
 * @param array $step  a normalised step (who = cc_owner, cc_question = label or '')
 * @param array $fieldsById  form_fields rows keyed by id (label, field_type)
 * @param array $valuesByFieldId  field id => stored answer
 */
function catalogueStepCostCentre(PDO $conn, array $step, array $fieldsById, array $valuesByFieldId, int $requesterId): ?int {
    $label = mb_strtolower(trim((string)($step['cc_question'] ?? '')));
    if ($label === '') {
        if ($requesterId <= 0) return null;
        $st = $conn->prepare('SELECT cost_centre_id FROM users WHERE id = ?');
        $st->execute([$requesterId]);
        $id = (int)$st->fetchColumn();
        return $id > 0 ? $id : null;
    }
    foreach ($fieldsById as $fid => $f) {
        if (($f['field_type'] ?? '') !== 'lookup' || mb_strtolower(trim((string)($f['label'] ?? ''))) !== $label) continue;
        $d = json_decode((string)($valuesByFieldId[$fid] ?? ''), true);
        $id = is_array($d) ? (int)($d['id'] ?? 0) : 0;
        return $id > 0 ? $id : null;   // the first question of that label, as conditions do
    }
    return null;
}

/**
 * The requester's cost centre now - snapshotted onto a new request (#348 slice 4).
 */
function catalogueRequesterCostCentre(PDO $conn, ?int $userId): ?int {
    if (!$userId) return null;
    try {
        $st = $conn->prepare('SELECT cost_centre_id FROM users WHERE id = ?');
        $st->execute([$userId]);
        $id = (int)$st->fetchColumn();
        return $id > 0 ? $id : null;
    } catch (Throwable $e) {
        return null;
    }
}

/** "0010 - Logistics", or '' when there is none. */
function catalogueCostCentreLabel(PDO $conn, ?int $id): string {
    if (!$id) return '';
    $st = $conn->prepare('SELECT code, name FROM cost_centres WHERE id = ?');
    $st->execute([$id]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r ? trim($r['code'] . ' - ' . $r['name']) : '';
}

/**
 * Who may decide a people step RIGHT NOW. Asked at every decision, every listing and
 * every reminder, so it is always today's manager and today's owner.
 *
 * @param array $sub  the form_submissions row (approver_kind, approval_cost_centre_id,
 *                    submitted_by_user_id, approval_step_datetime / submitted_date)
 * @return array{kind:string, decide:int[], owners:int[], deputy:?int, deputy_decides:bool, manager:?int,
 *               cost_centre:?array, reason:?string}
 *         reason (when decide is empty): no_manager | no_cost_centre | no_owner
 */
function catalogueStepPeople(PDO $conn, array $sub): array {
    $kind = (string)($sub['approver_kind'] ?? '');
    $requester = (int)($sub['submitted_by_user_id'] ?? 0);
    $out = ['kind' => $kind, 'decide' => [], 'owners' => [], 'deputy' => null, 'deputy_decides' => false,
            'manager' => null, 'cost_centre' => null, 'reason' => null];
    $active = function (?int $id) use ($conn, $requester): bool {
        if (!$id || $id === $requester) return false;   // never the requester
        $st = $conn->prepare('SELECT 1 FROM users WHERE id = ? AND is_active = 1');
        $st->execute([$id]);
        return (bool)$st->fetchColumn();
    };

    if ($kind === 'manager') {
        $st = $conn->prepare('SELECT manager_id FROM users WHERE id = ?');
        $st->execute([$requester]);
        $m = (int)$st->fetchColumn();
        if ($active($m)) { $out['manager'] = $m; $out['decide'] = [$m]; }
        else $out['reason'] = 'no_manager';
        return $out;
    }
    if ($kind !== 'cc_owner') return $out;

    $ccId = (int)($sub['approval_cost_centre_id'] ?? 0);
    $cc = null;
    if ($ccId > 0) {
        $st = $conn->prepare('SELECT id, code, name, owner_user_id, owner_group_id, deputy_user_id FROM cost_centres WHERE id = ?');
        $st->execute([$ccId]);
        $cc = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    if (!$cc) { $out['reason'] = 'no_cost_centre'; return $out; }
    $out['cost_centre'] = ['id' => (int)$cc['id'], 'code' => $cc['code'], 'name' => $cc['name']];

    $owners = [];
    if (!empty($cc['owner_user_id'])) {
        if ($active((int)$cc['owner_user_id'])) $owners[] = (int)$cc['owner_user_id'];
    } elseif (!empty($cc['owner_group_id'])) {
        // A people group: any current member who is a person decides for it. Staff
        // members of the group decide with their person record, if they have one.
        $st = $conn->prepare(
            "SELECT m.member_id FROM knowledge_user_group_members m
               JOIN knowledge_user_groups g ON g.id = m.group_id AND g.is_active = 1
               JOIN users u ON u.id = m.member_id AND u.is_active = 1
              WHERE m.group_id = ? AND m.member_type = 'user'
                AND (m.expires_at IS NULL OR m.expires_at > UTC_TIMESTAMP())"
        );
        $st->execute([(int)$cc['owner_group_id']]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) {
            if ((int)$id !== $requester) $owners[] = (int)$id;
        }
    }
    $owners = array_values(array_unique($owners));
    $deputy = (int)($cc['deputy_user_id'] ?? 0);
    $deputy = ($active($deputy) && !in_array($deputy, $owners, true)) ? $deputy : null;

    $deputyDecides = false;
    if ($deputy !== null) {
        $opt = catalogueApprovalOptions($conn);
        if ($opt['deputy_mode'] === 'any' || !$owners) {
            $deputyDecides = true;   // equals - or cover, and nobody else can (incl. the owner made the request)
        } else {
            // Cover: every owner who could decide is away...
            $in = implode(',', array_map('intval', $owners));
            $here = (int)$conn->query("SELECT COUNT(*) FROM users WHERE id IN ($in)
                                         AND (approvals_away_until IS NULL OR approvals_away_until < CURDATE())")->fetchColumn();
            if ($here === 0) $deputyDecides = true;
            // ...or the step has waited the chosen number of days.
            if (!$deputyDecides && $opt['deputy_days'] > 0) {
                $since = (string)($sub['approval_step_datetime'] ?? '') ?: (string)($sub['submitted_date'] ?? '');
                if ($since !== '' && strtotime($since . ' UTC') <= time() - $opt['deputy_days'] * 86400) $deputyDecides = true;
            }
        }
    }
    $out['owners'] = $owners;
    $out['deputy'] = $deputy;
    $out['deputy_decides'] = $deputyDecides;
    $out['decide'] = array_values(array_unique(array_merge($owners, $deputyDecides ? [$deputy] : [])));
    if (!$out['decide']) $out['reason'] = 'no_owner';
    return $out;
}

/**
 * May this person decide this request's current step, now?
 *
 * @param array $sub  the form_submissions row
 */
function catalogueUserMayDecide(PDO $conn, array $sub, int $userId): bool {
    if ($userId <= 0 || ($sub['approval_status'] ?? '') !== 'pending' || empty($sub['approver_kind'])) return false;
    return in_array($userId, catalogueStepPeople($conn, $sub)['decide'], true);
}

/**
 * Display names for people, by id.
 *
 * @param int[] $ids
 * @return array<int,string>
 */
function catalogueUserNames(PDO $conn, array $ids): array {
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (!$ids) return [];
    $st = $conn->query('SELECT id, COALESCE(NULLIF(display_name, \'\'), NULLIF(username, \'\'), email) AS n FROM users WHERE id IN (' . implode(',', $ids) . ')');
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $out[(int)$r['id']] = (string)$r['n'];
    return $out;
}

/**
 * "The requester's manager: Sam Jones" / "Owner of 0010 - Logistics: Priya Shah, deputy Tom Ng"
 * - what a people step is waiting on, for the record and the staff inbox.
 */
function cataloguePeopleRoutingLabel(PDO $conn, array $sub): string {
    $p = catalogueStepPeople($conn, $sub);
    $names = catalogueUserNames($conn, array_merge($p['decide'], $p['deputy'] ? [$p['deputy']] : []));
    $who = implode(', ', array_map(fn($id) => $names[$id] ?? ('#' . $id), $p['decide']));
    if ($p['kind'] === 'manager') {
        return "The requester's manager" . ($who !== '' ? ': ' . $who : ' (none)');
    }
    $cc = $p['cost_centre'] ? $p['cost_centre']['code'] . ' - ' . $p['cost_centre']['name'] : 'no cost centre';
    return 'Owner of ' . $cc . ($who !== '' ? ': ' . $who : ' (none)');
}

/**
 * A person decides the step a request is on, from the portal's Approvals page.
 * Mirrors catalogueApprovalDecide() for a named analyst: the same lock, the same
 * finish, the same after-commit work - recorded against the PERSON
 * (approval_decided_by_user_id, and the events' user_id).
 *
 * @param int $userId  users.id of the person signed in to the portal
 * @param string $decision 'approved' or 'rejected'
 * @param string $comment  the note; required to reject (the requester is told why)
 * @return array catalogueAfterDecision()'s result: decision approved / rejected / step_passed
 */
function catalogueDecideAsPerson(PDO $conn, int $userId, int $submissionId, string $decision, string $comment = ''): array {
    $decision = strtolower(trim($decision));
    if (!in_array($decision, ['approved', 'rejected'], true)) throw new Exception('Decision must be approve or reject');
    $comment = trim($comment);
    if ($decision === 'rejected' && $comment === '') throw new Exception('Say why you are rejecting this request');
    if (mb_strlen($comment) > 2000) throw new Exception('Keep the note under 2000 characters');

    $conn->beginTransaction();
    try {
        $sub = catalogueLockSubmission($conn, $submissionId);
        // A request id in a web address grants nothing: asked of THIS step, now.
        if (!catalogueUserMayDecide($conn, $sub, $userId)) throw new Exception('That request is not waiting for you');
        $done = catalogueFinishDecision($conn, $sub, $decision, 0, $comment, $userId);
        $conn->commit();
    } catch (Exception $e) {
        if ($conn->inTransaction()) $conn->rollBack();
        throw $e;
    }
    return catalogueAfterDecision($conn, $sub, $decision, $comment, $done);
}

/**
 * Has this person anything to do with approvals - the Approvals tab in the portal?
 * A manager of somebody, an owner or deputy of a cost centre (or in a group that
 * owns one), or somebody a waiting request is with. Fails closed.
 */
function catalogueUserIsApprover(PDO $conn, int $userId): bool {
    if ($userId <= 0 || !cataloguePeopleAvailable($conn)) return false;
    try {
        $st = $conn->prepare(
            "SELECT 1 FROM users WHERE manager_id = ? AND is_active = 1
             UNION SELECT 1 FROM cost_centres WHERE owner_user_id = ? OR deputy_user_id = ?
             UNION SELECT 1 FROM cost_centres c JOIN knowledge_user_group_members m
                     ON m.group_id = c.owner_group_id AND m.member_type = 'user' AND m.member_id = ?
             UNION SELECT 1 FROM form_submissions WHERE approval_decided_by_user_id = ?
             LIMIT 1"
        );
        $st->execute([$userId, $userId, $userId, $userId, $userId]);
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * The portal Approvals page: what waits for this person ('mine'), or what they have
 * decided ('decided'). Each item carries the answers, the price, the requester and
 * their cost centre, and where the request has got to - never who else could decide.
 *
 * @param string $filter 'mine' or 'decided'
 * @return array{items:array, counts:array{mine:int, decided:int}}
 */
function catalogueUserApprovalsList(PDO $conn, int $userId, string $filter = 'mine'): array {
    $base = "SELECT s.*, f.title AS form_title, u.display_name AS requester_name, u.username AS requester_username,
                    u.email AS requester_email, t.ticket_number
               FROM form_submissions s
               JOIN forms f ON f.id = s.form_id
               LEFT JOIN users u ON u.id = s.submitted_by_user_id
               LEFT JOIN tickets t ON t.id = s.ticket_id";
    // Candidates, then the live test - who may decide is never a column to filter on.
    $pending = $conn->query($base . " WHERE s.approval_status = 'pending' AND s.approver_kind IS NOT NULL ORDER BY s.submitted_date")->fetchAll(PDO::FETCH_ASSOC);
    $mine = array_values(array_filter($pending, fn($r) => catalogueUserMayDecide($conn, $r, $userId)));
    $decidedSql = " WHERE s.approval_decided_by_user_id = ?
                       OR s.id IN (SELECT submission_id FROM form_submission_approval_events WHERE user_id = ? AND action IN ('step_passed', 'step_already_approved'))";
    $cnt = $conn->prepare("SELECT COUNT(*) FROM form_submissions s" . $decidedSql);
    $cnt->execute([$userId, $userId]);
    $counts = ['mine' => count($mine), 'decided' => (int)$cnt->fetchColumn()];

    if ($filter === 'decided') {
        $st = $conn->prepare($base . $decidedSql . " ORDER BY COALESCE(s.approval_decided_datetime, s.submitted_date) DESC LIMIT 100");
        $st->execute([$userId, $userId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $rows = $mine;
    }
    require_once __DIR__ . '/catalogue_card.php';
    $prices = catalogueRequestPriceLabels($conn, array_column($rows, 'id'));
    $items = [];
    foreach ($rows as $r) {
        $p = catalogueStepProgress($r);
        $people = $r['approval_status'] === 'pending' && !empty($r['approver_kind']) ? catalogueStepPeople($conn, $r) : null;
        $items[] = [
            'id'             => (int)$r['id'],
            'form_title'     => $r['form_title'],
            'requester'      => $r['requester_name'] ?: ($r['requester_username'] ?: ($r['requester_email'] ?: '')),
            'submitted_date' => $r['submitted_date'],
            'status'         => $r['approval_status'],
            'decided_date'   => $r['approval_decided_datetime'],
            'comment'        => $r['approval_status'] === 'pending' ? null : $r['approval_comment'],
            'ticket_number'  => $r['ticket_number'],
            'price_label'    => $prices[(int)$r['id']] ?? '',
            'cost_centre'    => catalogueCostCentreLabel($conn, (int)($r['requester_cost_centre_id'] ?? 0) ?: null),
            'step'           => $p ? ['position' => $p['position'], 'count' => $p['count'], 'name' => $p['name']] : null,
            // Why it is with me: "as the requester's manager" / "as owner of 0010" / "as deputy".
            'as'             => $people ? ($people['kind'] === 'manager' ? 'manager'
                                    : (in_array($userId, $people['owners'], true) ? 'owner' : 'deputy')) : null,
            'step_cost_centre' => $people && $people['cost_centre'] ? $people['cost_centre']['code'] . ' - ' . $people['cost_centre']['name'] : '',
            'can_decide'     => $filter !== 'decided' || catalogueUserMayDecide($conn, $r, $userId),
            'answers'        => catalogueSubmissionAnswers($conn, (int)$r['id']),
        ];
    }
    return ['items' => $items, 'counts' => $counts];
}

/**
 * "I am away until" - the owner's way to let a cover-only deputy decide meanwhile.
 *
 * @param ?string $until  Y-m-d, or null / '' for "I am here"
 */
function catalogueSetAway(PDO $conn, int $userId, ?string $until): ?string {
    $until = trim((string)$until);
    if ($until !== '') {
        $d = DateTime::createFromFormat('!Y-m-d', $until);
        if (!$d || $d->format('Y-m-d') !== $until) throw new Exception('Choose a date');
        if ($until < gmdate('Y-m-d')) throw new Exception('Choose a date that is not in the past');
    }
    $conn->prepare('UPDATE users SET approvals_away_until = ? WHERE id = ?')->execute([$until !== '' ? $until : null, $userId]);
    return $until !== '' ? $until : null;
}

/** This person's "away until", or null. */
function catalogueAwayUntil(PDO $conn, int $userId): ?string {
    $st = $conn->prepare('SELECT approvals_away_until FROM users WHERE id = ? AND (approvals_away_until IS NULL OR approvals_away_until >= CURDATE())');
    $st->execute([$userId]);
    $v = $st->fetchColumn();
    return $v ? (string)$v : null;
}

/**
 * May the requester press Remind on this request now? Waiting for approval, Remind
 * switched on, and not reminded in the last day on this step.
 *
 * @param array $sub  the form_submissions row
 */
function catalogueCanRemind(PDO $conn, array $sub): bool {
    if (($sub['approval_status'] ?? '') !== 'pending' || !empty($sub['for_ticket_id']) || !empty($sub['task_id'])) return false;
    if (!cataloguePeopleAvailable($conn) || !catalogueApprovalOptions($conn)['remind']) return false;
    $last = (string)($sub['approval_reminded_datetime'] ?? '');
    return $last === '' || strtotime($last . ' UTC') <= time() - 86400;
}

/**
 * The requester's Remind: tell the people the step is waiting on again - the bell and
 * an email. At most once a day per step (catalogueCanRemind()), so it cannot become spam.
 *
 * @throws ServiceError not_found (not theirs) / conflict (too soon, or switched off)
 * @return int how many were told
 */
function catalogueRemind(PDO $conn, int $userId, int $submissionId): int {
    require_once __DIR__ . '/service_context.php';
    $conn->beginTransaction();
    try {
        $st = $conn->prepare('SELECT s.*, f.title AS form_title FROM form_submissions s JOIN forms f ON f.id = s.form_id WHERE s.id = ? FOR UPDATE');
        $st->execute([$submissionId]);
        $sub = $st->fetch(PDO::FETCH_ASSOC);
        if (!$sub || (int)$sub['submitted_by_user_id'] !== $userId || !empty($sub['for_ticket_id']) || !empty($sub['task_id'])) {
            throw new ServiceError('not_found', 'not_found', 'That request was not found.');
        }
        if (!catalogueCanRemind($conn, $sub)) {
            throw new ServiceError('conflict', 'conflict', ($sub['approval_status'] ?? '') !== 'pending'
                ? 'This request is no longer waiting for approval.'
                : 'A reminder has already been sent today. You can send another tomorrow.');
        }
        $conn->prepare('UPDATE form_submissions SET approval_reminded_datetime = UTC_TIMESTAMP() WHERE id = ?')->execute([$submissionId]);
        catalogueApprovalEvent($conn, $submissionId, 0, 'reminded', null, '', $userId);
        $conn->commit();
    } catch (Throwable $e) {
        if ($conn->inTransaction()) $conn->rollBack();
        throw $e;
    }
    return catalogueTellApprovers($conn, $sub, true);
}

/**
 * Tell the people who decide the step a request is on: staff by their bell, people by
 * the portal bell, and everybody by email when Forms -> Settings names a mailbox.
 * No approve button in the email - forwarding it must never hand over the decision;
 * it links to the page where the approver signs in and decides.
 *
 * @param array $sub  the form_submissions row with form_title, as it is now
 * @param bool $reminder  the requester pressed Remind
 * @return int how many people were told (bell or email)
 */
function catalogueTellApprovers(PDO $conn, array $sub, bool $reminder = false): int {
    if (($sub['approval_status'] ?? '') !== 'pending') return 0;
    require_once __DIR__ . '/services/notifications.php';
    $p = catalogueStepProgress($sub);
    $stepText = $p ? 'Step ' . $p['position'] . ' of ' . $p['count'] . ($p['name'] !== '' ? ': ' . $p['name'] : '') : '';
    $body = $reminder ? trim('Reminder. ' . $stepText) : $stepText;

    $staff = []; $people = [];
    if (!empty($sub['approver_kind'])) {
        $people = catalogueStepPeople($conn, $sub)['decide'];
    } elseif (!empty($sub['approval_team_id'])) {
        $staff = catalogueEligibleMembers($conn, $sub);
    } elseif (!empty($sub['approver_id'])) {
        $staff = (!empty($sub['approval_exclude_requester']) && catalogueAnalystIsRequester($conn, (int)$sub['approver_id'], $sub))
            ? [] : [(int)$sub['approver_id']];
    }
    $told = [];
    foreach (array_unique($staff) as $aid) {
        if (!NotificationsService::typeEnabled($conn, $aid, 'catalogue_request.awaiting')) continue;
        if (NotificationsService::notify($conn, [
            'analyst_id' => $aid, 'event_type' => 'catalogue_request.awaiting', 'entity_type' => 'catalogue_request',
            'entity_id' => (int)$sub['id'], 'entity_ref' => '#' . (int)$sub['id'],
            'title' => (string)($sub['form_title'] ?? ''), 'body' => $body, 'actor_id' => null, 'actor_name' => null,
        ])) $told['a' . $aid] = true;
    }
    foreach (array_unique($people) as $uid) {
        try {
            if (NotificationsService::notify($conn, [
                'portal_user_id' => $uid, 'event_type' => 'catalogue_request.awaiting', 'entity_type' => 'catalogue_request',
                'entity_id' => (int)$sub['id'], 'entity_ref' => '#' . (int)$sub['id'],
                'title' => (string)($sub['form_title'] ?? ''), 'body' => $body, 'actor_name' => null,
            ])) $told['u' . $uid] = true;
        } catch (Throwable $e) { error_log('Approval portal notice: ' . $e->getMessage()); }
    }

    // Email, when a mailbox is chosen. Best-effort: the bell has told them already.
    $mailboxId = cataloguePeopleAvailable($conn) ? catalogueApprovalOptions($conn)['mailbox_id'] : 0;
    if ($mailboxId > 0 && ($staff || $people)) {
        try {
            require_once __DIR__ . '/mailbox_send.php';
            require_once __DIR__ . '/mailbox_graph.php';
            require_once __DIR__ . '/public_url.php';
            $mb = $conn->prepare('SELECT * FROM target_mailboxes WHERE id = ? AND is_active = 1');
            $mb->execute([$mailboxId]);
            $row = $mb->fetch(PDO::FETCH_ASSOC);
            $mailbox = $row ? decryptMailboxRow($row) : null;
            if ($mailbox && mailboxCanSend($mailbox)) {
                $root = rtrim(publicBaseUrl($conn), '/');
                $req = $conn->prepare('SELECT COALESCE(NULLIF(display_name, \'\'), NULLIF(username, \'\'), email) FROM users WHERE id = ?');
                $req->execute([(int)$sub['submitted_by_user_id']]);
                $requester = (string)$req->fetchColumn();
                $to = [];
                if ($staff) {
                    $st = $conn->query('SELECT id, full_name, email FROM analysts WHERE id IN (' . implode(',', array_map('intval', $staff)) . ')');
                    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $a) $to[] = [$a['email'], $a['full_name'], $root . '/forms/approvals.php'];
                }
                if ($people) {
                    $st = $conn->query('SELECT id, COALESCE(NULLIF(display_name, \'\'), username) AS n, email FROM users WHERE id IN (' . implode(',', array_map('intval', $people)) . ')');
                    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $u) $to[] = [$u['email'], $u['n'], $root . '/self-service/approvals.php?id=' . (int)$sub['id']];
                }
                foreach ($to as [$email, $name, $link]) {
                    if (!filter_var((string)$email, FILTER_VALIDATE_EMAIL)) continue;
                    [$subject, $html] = catalogueApprovalEmail($conn, $sub, (string)$name, $requester, $stepText, $link, $reminder);
                    if (mailboxSendSimple($conn, $mailbox, (string)$email, $subject, $html, 'catalogue_approval') === null) $told['e' . $email] = true;
                }
            }
        } catch (Throwable $e) {
            error_log('Approval email: ' . $e->getMessage());
        }
    }
    return count($told);
}

/**
 * The email to an approver: what was asked for, by whom, which step - and a link to
 * where they sign in and decide. Never a button that decides.
 *
 * @return array{0:string, 1:string} subject and HTML body
 */
function catalogueApprovalEmail(PDO $conn, array $sub, string $name, string $requester, string $stepText, string $link, bool $reminder): array {
    $e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    $title = (string)($sub['form_title'] ?? '');
    $subject = t($reminder ? 'forms.approval_mail.subject_reminder' : 'forms.approval_mail.subject', ['form' => $title]);
    require_once __DIR__ . '/catalogue_card.php';
    $price = catalogueRequestPriceLabels($conn, [(int)$sub['id']])[(int)$sub['id']] ?? '';
    $html = '<p>' . $e(t('forms.approval_mail.hello', ['name' => $name])) . '</p>'
          . '<p>' . $e(t($reminder ? 'forms.approval_mail.intro_reminder' : 'forms.approval_mail.intro', ['requester' => $requester !== '' ? $requester : '-', 'form' => $title])) . '</p>'
          . ($stepText !== '' ? '<p><strong>' . $e($stepText) . '</strong></p>' : '')
          . ($price !== '' ? '<p>' . $e(t('common.catalogue_price.label')) . ': ' . $e($price) . '</p>' : '')
          . '<p><a href="' . $e($link) . '">' . $e(t('forms.approval_mail.open')) . '</a></p>'
          . '<p><small style="color:#666;">' . $e(t('forms.approval_mail.no_button')) . '</small></p>';
    return [$subject, $html];
}
