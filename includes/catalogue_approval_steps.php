<?php
/**
 * Approval steps for catalogue requests (GH #160 Part C1).
 *
 * A form's approval used to be ONE policy: a named person, or a team with a number
 * of approvals. It can now be an ordered list of steps - "the IT manager, then the
 * finance team, then the board when the total is over 5,000" - either the form's own
 * (forms.approval_steps) or a named chain shared by many forms (form_approval_chains,
 * chosen by forms.approval_chain_id).
 *
 * 🔑 HOW IT REUSES THE ENGINE: the snapshot columns on form_submissions (approver_id,
 * approval_team_id, approvals_required, rejections_required, approval_exclude_requester)
 * ALWAYS hold the CURRENT step's policy. Passing a step copies the next one in and
 * bumps approval_round, and votes are counted per round - so votes, Stuck, Move,
 * Override and the row lock in catalogue_approvals.php work per step with no second
 * code path. The whole list is snapshotted onto the request too
 * (form_submissions.approval_steps), so changing the form or the chain affects new
 * requests only.
 *
 * The rules (wiki Financial-Approvals-Design, section 6.2):
 *   - steps run one at a time, in order; any rejection ends the request;
 *   - "Only when" is decided at SUBMISSION (the answers cannot change after that);
 *     a skipped step stays on the record with the reason;
 *   - a condition that cannot be read means the step RUNS - a blank or broken total
 *     must never skip the board;
 *   - a step that finds nobody goes to its fallback approver, else the install's
 *     default (Forms -> Settings), else it is Stuck for an administrator. Never skipped;
 *   - every step skipped = nothing to approve: the request is approved at once, as
 *     "No approval step applied", so the form's "when approved" actions still run.
 *
 * Step shape (one entry of the JSON list, as catalogueNormaliseSteps() returns it):
 *   {"name": "Finance", "who": "person"|"team"|"manager"|"cc_owner",
 *    "cc_question": "Cost centre" | "" (the requester's own),          (cc_owner - Part C2)
 *    "approver_id": 12, "exclude_requester": 0|1,                       (person)
 *    "team_id": 4, "approvals_required": 2, "rejections_required": 1,   (team)
 *    "when": null | {"match": "all"|"any", "rules": [{"question": "Order total", "op": "greater_than", "value": "5000"}]},
 *    "fallback": null | {"who": "person", "approver_id": 7} | {"who": "team", "team_id": 3, "approvals_required": 1, "rejections_required": 1}}
 *
 * A condition names its question by LABEL, not by form_fields.id: a chain is shared by
 * many forms, and a form's field ids change with every new version. A question that is
 * not on the form is an unreadable condition, so the step runs - the safe direction.
 * The snapshot adds "skipped" (bool) and "skip_detail" to each step, and to an owner
 * step the "cost_centre_id" its question's answer named (Part C2).
 *
 * "manager" and "cc_owner" (Part C2) are decided by PEOPLE in the portal - see
 * includes/catalogue_approval_people.php. A fallback is always staff.
 */

require_once __DIR__ . '/form_logic.php';
require_once __DIR__ . '/catalogue_approval_people.php';   // GH #160 Part C2

/** The operators a step condition may use - formLogicTestRule()'s. */
const CATALOGUE_STEP_OPS = ['equals', 'not_equals', 'contains', 'is_empty', 'is_not_empty',
                            'greater_than', 'less_than', 'is_after', 'is_before'];
const CATALOGUE_STEPS_MAX = 10;

/**
 * Are the step columns there? Before Database Verification they are not, and every
 * form is then a single-policy form, exactly as before.
 */
function catalogueStepsAvailable(PDO $conn): bool {
    static $known = null;
    if ($known !== null) return $known;
    try {
        $known = (int)$conn->query(
            "SELECT (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE()
                       AND ((table_name = 'forms' AND column_name IN ('approval_steps', 'approval_chain_id'))
                         OR (table_name = 'form_submissions' AND column_name IN ('approval_steps', 'approval_step_no'))))
                  + (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'form_approval_chains')"
        )->fetchColumn() === 5;
    } catch (Throwable $e) {
        $known = false;
    }
    return $known;
}

/**
 * Check and tidy a list of steps - for saving (strict: a problem is an error the
 * designer sees) and for reading (lenient: a broken step is kept so the request is
 * Stuck rather than silently going round it).
 *
 * @param mixed $raw  the list, or its JSON
 * @param bool $strict  throw on a problem (saving), or keep going (reading)
 * @return array the normalised steps (an empty list for nothing)
 */
function catalogueNormaliseSteps(PDO $conn, $raw, bool $strict = true): array {
    if (is_string($raw)) $raw = $raw === '' ? [] : json_decode($raw, true);
    if ($raw === null) return [];
    $fail = function (string $msg) use ($strict) {
        if ($strict) {
            if (class_exists('ServiceError')) throw new ServiceError('validation', 'invalid_field', $msg);
            throw new Exception($msg);
        }
    };
    if (!is_array($raw) || !array_is_list($raw)) { $fail('The approval steps must be a list.'); return []; }
    if (count($raw) > CATALOGUE_STEPS_MAX) $fail('A form can have at most ' . CATALOGUE_STEPS_MAX . ' approval steps.');

    $out = [];
    foreach (array_slice($raw, 0, CATALOGUE_STEPS_MAX) as $i => $s) {
        $n = $i + 1;
        if (!is_array($s)) { $fail("Step $n is not a step."); continue; }
        $name = trim((string)($s['name'] ?? ''));
        if (mb_strlen($name) > 100) $name = mb_substr($name, 0, 100);
        $policy = catalogueNormaliseStepPolicy($conn, $s, "Step $n", $fail, true);
        if ($policy === null) continue;
        $step = ['name' => $name] + $policy;
        $step['when'] = catalogueNormaliseStepWhen($s['when'] ?? null, "Step $n", $fail);
        $fb = $s['fallback'] ?? null;
        $step['fallback'] = (is_array($fb) && !empty($fb['who']))
            ? catalogueNormaliseStepPolicy($conn, $fb, "Step $n's fallback", $fail)
            : null;
        if ($step['fallback'] !== null) unset($step['fallback']['exclude_requester']);
        // A snapshot's owner step keeps the cost centre it was routed to.
        if ($policy['who'] === 'cc_owner' && !empty($s['cost_centre_id'])) $step['cost_centre_id'] = (int)$s['cost_centre_id'];
        $out[] = $step;
    }
    return $out;
}

/**
 * Who decides one step (or its fallback): a person or a team, checked to exist.
 *
 * @param array $s  the step (or fallback) as sent
 * @param string $what  "Step 2", for the message
 * @param callable $fail  reports a problem (throws when strict)
 * @param bool $allowPeople  a step may be the requester's manager or a cost centre's owner
 *                           (Part C2) - never a fallback, which must be somebody fixed
 */
function catalogueNormaliseStepPolicy(PDO $conn, array $s, string $what, callable $fail, bool $allowPeople = false): ?array {
    $who = (string)($s['who'] ?? '');
    if ($allowPeople && ($who === 'manager' || $who === 'cc_owner')) {
        if (!cataloguePeopleAvailable($conn)) $fail("$what: run Database Verification before choosing the manager or a cost centre owner.");
        $p = ['who' => $who, 'approver_id' => null, 'team_id' => null, 'approvals_required' => null,
              'rejections_required' => null, 'exclude_requester' => 1];
        if ($who === 'cc_owner') $p['cc_question'] = mb_substr(trim((string)($s['cc_question'] ?? '')), 0, 255);
        return $p;
    }
    if ($who === 'team') {
        $teamId = (int)($s['team_id'] ?? 0);
        $ok = false;
        if ($teamId > 0) {
            $t = $conn->prepare('SELECT 1 FROM teams WHERE id = ? AND is_active = 1');
            $t->execute([$teamId]);
            $ok = (bool)$t->fetchColumn();
        }
        if (!$ok) $fail("$what: choose a team.");
        $a = (int)($s['approvals_required'] ?? 1);
        $r = (int)($s['rejections_required'] ?? 1);
        if ($a < 1 || $a > 20 || $r < 1 || $r > 20) $fail("$what: approvals and rejections needed must be between 1 and 20.");
        return ['who' => 'team', 'approver_id' => null, 'team_id' => $teamId > 0 ? $teamId : null,
                'approvals_required' => max(1, min(20, $a)), 'rejections_required' => max(1, min(20, $r)),
                'exclude_requester' => 1];
    }
    if ($who === 'person') {
        $id = (int)($s['approver_id'] ?? 0);
        $ok = false;
        if ($id > 0) {
            $a = $conn->prepare('SELECT 1 FROM analysts WHERE id = ? AND COALESCE(is_active, 1) = 1');
            $a->execute([$id]);
            $ok = (bool)$a->fetchColumn();
        }
        if (!$ok) $fail("$what: choose a person.");
        return ['who' => 'person', 'approver_id' => $id > 0 ? $id : null, 'team_id' => null,
                'approvals_required' => null, 'rejections_required' => null,
                'exclude_requester' => !empty($s['exclude_requester']) ? 1 : 0];
    }
    $fail("$what: choose who decides.");
    return null;
}

/**
 * A step's "Only when": all or any of up to ten rules, each a question (by label),
 * an operator and a value. No rules = always.
 */
function catalogueNormaliseStepWhen($when, string $what, callable $fail): ?array {
    if (!is_array($when) || empty($when['rules']) || !is_array($when['rules'])) return null;
    $rules = [];
    foreach (array_slice($when['rules'], 0, 10) as $r) {
        if (!is_array($r)) continue;
        $q = trim((string)($r['question'] ?? ''));
        $op = (string)($r['op'] ?? '');
        if ($q === '') { $fail("$what: choose the question its condition looks at."); continue; }
        if (!in_array($op, CATALOGUE_STEP_OPS, true)) { $fail("$what: choose how its condition compares."); continue; }
        $rules[] = ['question' => mb_substr($q, 0, 255), 'op' => $op, 'value' => mb_substr(trim((string)($r['value'] ?? '')), 0, 255)];
    }
    if (!$rules) return null;
    return ['match' => ($when['match'] ?? 'all') === 'any' ? 'any' : 'all', 'rules' => $rules];
}

/**
 * The steps a form's requests follow: its chain's, or its own, or null for the single
 * policy (the form as it was before steps).
 *
 * A chain that has gone (it cannot be deleted while in use, so only by hand) returns
 * one step with nobody, so the request is STUCK - never waved through.
 *
 * @param array $form  the forms row
 * @return ?array{steps:array, chain:?string}
 */
function catalogueFormSteps(PDO $conn, array $form): ?array {
    if (!catalogueStepsAvailable($conn)) return null;
    $chainId = (int)($form['approval_chain_id'] ?? 0);
    if ($chainId > 0) {
        $st = $conn->prepare('SELECT name, steps FROM form_approval_chains WHERE id = ? AND is_active = 1');
        $st->execute([$chainId]);
        $chain = $st->fetch(PDO::FETCH_ASSOC);
        if (!$chain) {
            return ['chain' => null, 'steps' => [['name' => '', 'who' => 'person', 'approver_id' => null, 'team_id' => null,
                'approvals_required' => null, 'rejections_required' => null, 'exclude_requester' => 0, 'when' => null, 'fallback' => null]]];
        }
        $steps = catalogueNormaliseSteps($conn, $chain['steps'], false);
        return $steps ? ['chain' => (string)$chain['name'], 'steps' => $steps] : null;
    }
    $own = $form['approval_steps'] ?? null;
    if ($own === null || $own === '') return null;
    $steps = catalogueNormaliseSteps($conn, $own, false);
    return $steps ? ['chain' => null, 'steps' => $steps] : null;
}

/**
 * Does a step apply to these answers? Decided once, at submission.
 *
 * TRAP: formLogicTestRule() says "is more than 5000" is FALSE for a blank answer, which
 * would skip a threshold step exactly when the amount is missing. So a rule that cannot
 * be read - its question is not on the form, or a number or date comparison has no
 * number or date to compare - makes the whole step apply, whatever the other rules say.
 *
 * @param ?array $when  the step's condition (null = always)
 * @param array $answers  lower-cased trimmed question label => stored answer (first question of that label)
 * @param array $labels  lower-cased label => the label as written, for the record
 * @return array{0:bool, 1:string} applies, and the reason for the record ("Order total was 3000")
 */
function catalogueStepApplies(?array $when, array $answers, array $labels = []): array {
    if (!$when || empty($when['rules'])) return [true, ''];
    $results = [];
    $seen = [];
    foreach ($when['rules'] as $r) {
        $key = mb_strtolower(trim((string)$r['question']));
        if (!array_key_exists($key, $answers)) {
            return [true, 'the question "' . $r['question'] . '" is not on the form'];
        }
        $norm = formLogicNormaliseValue($answers[$key]);
        $scalar = $norm['scalar'];
        $op = (string)$r['op'];
        if (in_array($op, ['greater_than', 'less_than'], true) && !is_numeric($scalar)) {
            return [true, '"' . ($labels[$key] ?? $r['question']) . '" has no number to compare'];
        }
        if (in_array($op, ['is_after', 'is_before'], true) && $scalar === '') {
            return [true, '"' . ($labels[$key] ?? $r['question']) . '" has no date to compare'];
        }
        $results[] = formLogicTestRule(['field' => 1, 'op' => $op, 'value' => $r['value']], [1 => $answers[$key]]);
        $seen[$key] = ($labels[$key] ?? $r['question']) . ' was ' . ($scalar === '' ? 'blank' : $scalar);
    }
    $applies = ($when['match'] ?? 'all') === 'any' ? in_array(true, $results, true) : !in_array(false, $results, true);
    return [$applies, implode(', ', array_values($seen))];
}

/**
 * The answers by question label, for step conditions.
 *
 * @param array $fieldsById  form_fields rows keyed by id (label, field_type)
 * @param array $valuesByFieldId  field id => stored answer
 * @return array{0:array, 1:array} lower-cased label => answer, and lower-cased label => label
 */
function catalogueAnswersByLabel(array $fieldsById, array $valuesByFieldId): array {
    $answers = []; $labels = [];
    foreach ($fieldsById as $id => $f) {
        if (in_array($f['field_type'] ?? '', ['section', 'static', 'heading'], true)) continue;
        $key = mb_strtolower(trim((string)($f['label'] ?? '')));
        if ($key === '' || array_key_exists($key, $answers)) continue;   // the first question of a label wins
        $answers[$key] = $valuesByFieldId[$id] ?? '';
        $labels[$key] = trim((string)$f['label']);
    }
    return [$answers, $labels];
}

/**
 * The snapshot columns for one step's policy.
 *
 * @param array $p  a normalised step, or its fallback
 */
function catalogueStepColumns(array $p): array {
    $team = ($p['who'] ?? '') === 'team';
    $people = in_array($p['who'] ?? '', ['manager', 'cc_owner'], true);   // Part C2
    if ($people) {
        return ['approver_id' => null, 'approval_team_id' => null, 'approvals_required' => null, 'rejections_required' => null,
                'approval_exclude_requester' => 1, 'approver_kind' => $p['who'],
                'approval_cost_centre_id' => $p['who'] === 'cc_owner' ? ((int)($p['cost_centre_id'] ?? 0) ?: null) : null];
    }
    return [
        'approver_kind'              => null,
        'approval_cost_centre_id'    => null,
        'approver_id'                => $team ? null : ($p['approver_id'] ?? null),
        'approval_team_id'           => $team ? ($p['team_id'] ?? null) : null,
        'approvals_required'         => $team ? max(1, (int)($p['approvals_required'] ?? 1)) : null,
        'rejections_required'        => $team ? max(1, (int)($p['rejections_required'] ?? 1)) : null,
        'approval_exclude_requester' => $team ? 1 : (int)!empty($p['exclude_requester']),
    ];
}

/**
 * The install's default fallback (Forms -> Settings -> Approvals), or null for none.
 */
function catalogueDefaultFallback(PDO $conn): ?array {
    try {
        $st = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'forms_approval_fallback'");
        $st->execute();
        $v = json_decode((string)$st->fetchColumn(), true);
        if (!is_array($v) || empty($v['who'])) return null;
        return catalogueNormaliseStepPolicy($conn, $v, 'The default fallback', function () {}) ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Would nobody be able to decide this policy? The same test as Stuck, asked of a step
 * as it is reached: a person who has left, or who made the request themselves when
 * that is not allowed; a team with too few independent members.
 *
 * @param array $cols  catalogueStepColumns()
 * @param array $sub  the request (submitted_by_user_id)
 */
function catalogueStepFindsNobody(PDO $conn, array $cols, array $sub): bool {
    if (!empty($cols['approver_kind'])) {
        // Part C2: the manager or the owner as they are NOW; the step was just reached.
        $row = array_merge($sub, $cols, ['approval_step_datetime' => gmdate('Y-m-d H:i:s')]);
        return !catalogueStepPeople($conn, $row)['decide'];
    }
    if (empty($cols['approver_id']) && empty($cols['approval_team_id'])) return true;
    if (!empty($cols['approver_id'])) {
        $a = $conn->prepare('SELECT 1 FROM analysts WHERE id = ? AND COALESCE(is_active, 1) = 1');
        $a->execute([(int)$cols['approver_id']]);
        if (!$a->fetchColumn()) return true;
    }
    $row = array_merge($sub, $cols, ['id' => 0, 'approval_status' => 'pending', 'approval_round' => 1]);
    return catalogueApprovalProgress($conn, $row)['stuck'];
}

/**
 * Reach a step: its own policy, or - when that finds nobody - the step's fallback, or
 * the install's default. With neither, the step's own policy stands and the request is
 * Stuck, exactly as it would be without steps.
 *
 * @param array $step  a snapshot step
 * @param array $sub  the request (submitted_by_user_id)
 * @return array{cols:array, fallback:?string} the columns to store, and the fallback used ("step" / "default") or null
 */
function catalogueReachStep(PDO $conn, array $step, array $sub): array {
    $cols = catalogueStepColumns($step);
    if (!catalogueStepFindsNobody($conn, $cols, $sub)) return ['cols' => $cols, 'fallback' => null];
    foreach (['step' => $step['fallback'] ?? null, 'default' => catalogueDefaultFallback($conn)] as $kind => $fb) {
        if (!$fb) continue;
        $fbCols = catalogueStepColumns($fb);
        if (!catalogueStepFindsNobody($conn, $fbCols, $sub)) return ['cols' => $fbCols, 'fallback' => $kind];
    }
    return ['cols' => $cols, 'fallback' => null];
}

/**
 * The plan for a new request: which steps apply, which is first, and what to snapshot.
 * Called by catalogueApprovalGate() when the form has steps.
 *
 * @param array $form  the forms row
 * @param array $sub  what is known of the request so far (submitted_by_user_id)
 * @param array $fieldsById  form_fields rows keyed by id
 * @param array $valuesByFieldId  field id => stored answer
 * @return array the gate (snapshot columns) plus steps (annotated), step_no (null = every step skipped), chain, fallback
 */
function catalogueStepsPlan(PDO $conn, array $form, array $sub, array $fieldsById, array $valuesByFieldId): ?array {
    $found = catalogueFormSteps($conn, $form);
    if ($found === null) return null;
    [$answers, $labels] = catalogueAnswersByLabel($fieldsById, $valuesByFieldId);
    $steps = [];
    $first = null;
    foreach ($found['steps'] as $i => $s) {
        [$applies, $why] = catalogueStepApplies($s['when'] ?? null, $answers, $labels);
        // Part C2: which cost centre an owner step goes to is fixed by the answers, now.
        if (($s['who'] ?? '') === 'cc_owner') {
            $s['cost_centre_id'] = catalogueStepCostCentre($conn, $s, $fieldsById, $valuesByFieldId, (int)($sub['submitted_by_user_id'] ?? 0));
        }
        $s['skipped'] = !$applies;
        $s['skip_detail'] = $applies ? null : $why;
        $steps[] = $s;
        if ($applies && $first === null) $first = $i;
    }
    $plan = ['steps' => $steps, 'step_no' => $first === null ? null : $first + 1, 'chain' => $found['chain'], 'fallback' => null];
    if ($first === null) {
        // Nothing to approve. The request still goes through the decision, so the
        // form's "when approved" list and the ticket happen as they always do.
        return $plan + catalogueStepColumns(['who' => 'person', 'approver_id' => null]);
    }
    $reach = catalogueReachStep($conn, $steps[$first], $sub);
    $plan['fallback'] = $reach['fallback'];
    return $plan + $reach['cols'];
}

/**
 * Record a new request's steps on it, inside submitForm()'s transaction: the skipped
 * steps with their reasons, a fallback used for the first step, and - when every step
 * was skipped - the decision itself.
 *
 * @param array $plan  catalogueStepsPlan()
 * @param int $submissionId  the request just inserted
 * @return ?array catalogueFinishDecision()'s result when it was approved there and then, else null
 */
function catalogueStepsRecordNew(PDO $conn, array $plan, int $submissionId): ?array {
    $total = count($plan['steps']);
    foreach ($plan['steps'] as $i => $s) {
        if (!empty($s['skipped'])) {
            catalogueApprovalEvent($conn, $submissionId, 0, 'step_skipped',
                catalogueStepLabel($s, $i + 1, $total) . ': ' . $s['skip_detail'], '');
        }
    }
    if (!empty($plan['fallback']) && $plan['step_no'] !== null) {
        $sub = catalogueLockSubmission($conn, $submissionId);
        catalogueApprovalEvent($conn, $submissionId, 0, 'step_fallback',
            catalogueStepLabel($plan['steps'][$plan['step_no'] - 1], $plan['step_no'], $total) . ' -> ' . catalogueRoutingLabel($conn, $sub),
            $plan['fallback'] === 'default' ? 'Nobody could decide it; sent to the default approver' : 'Nobody could decide it; sent to its fallback approver');
    }
    if ($plan['step_no'] !== null) return null;
    $sub = catalogueLockSubmission($conn, $submissionId);
    catalogueApprovalEvent($conn, $submissionId, 0, 'auto_approved', null, 'No approval step applied');
    return catalogueFinishDecision($conn, $sub, 'approved', 0, 'No approval step applied');
}

/**
 * Tell the people who decide the step a request has just reached (GH #160 Part C1):
 * the named approver, or each member of the approving team who may vote. The staff
 * bell, with each person's own switch (Preferences). Called after the commit, at
 * submission and each time a step passes. A chain cannot live without it - every
 * step would wait silently for somebody who does not know it has arrived.
 *
 * Requests without steps are left exactly as they were (told by a workflow rule, if any).
 *
 * @param array $sub  the form_submissions row (with form_title), as it is now
 * @return int how many were told
 */
function catalogueTellStepApprovers(PDO $conn, array $sub): int {
    if (($sub['approval_status'] ?? '') !== 'pending' || catalogueSubmissionSteps($sub) === null) return 0;
    // Part C2: people in the portal too, and email - one function, shared with Remind.
    return catalogueTellApprovers($conn, $sub);
}

/**
 * "Step 2 of 3: Finance" - for the record and the requester.
 */
function catalogueStepLabel(array $step, int $no, int $total): string {
    $name = trim((string)($step['name'] ?? ''));
    return 'Step ' . $no . ' of ' . $total . ($name !== '' ? ': ' . $name : '');
}

/**
 * The steps a request follows, from its snapshot, or null for a single-policy request.
 *
 * @param array $sub  the form_submissions row
 */
function catalogueSubmissionSteps(array $sub): ?array {
    $raw = $sub['approval_steps'] ?? null;
    if ($raw === null || $raw === '') return null;
    $steps = json_decode((string)$raw, true);
    return is_array($steps) && $steps ? $steps : null;
}

/**
 * Where a request with steps has got to - for the requester, the approver and the record.
 *
 * @param array $sub  the form_submissions row
 * @return ?array{step_no:int, total:int, position:int, count:int, name:string, steps:array}
 *         position / count number only the steps that apply ("step 2 of 3" when one was skipped)
 */
function catalogueStepProgress(array $sub): ?array {
    $steps = catalogueSubmissionSteps($sub);
    if ($steps === null) return null;
    $no = (int)($sub['approval_step_no'] ?? 0);
    $applying = 0; $position = 0;
    foreach ($steps as $i => $s) {
        if (!empty($s['skipped'])) continue;
        $applying++;
        if ($i + 1 === $no) $position = $applying;
    }
    $out = [];
    foreach ($steps as $i => $s) {
        $out[] = ['no' => $i + 1, 'name' => (string)($s['name'] ?? ''), 'who' => $s['who'] ?? '',
                  'skipped' => !empty($s['skipped']), 'skip_detail' => $s['skip_detail'] ?? null,
                  'state' => !empty($s['skipped']) ? 'skipped' : ($i + 1 < $no ? 'passed' : ($i + 1 === $no ? 'current' : 'waiting'))];
    }
    return ['step_no' => $no, 'total' => count($steps), 'position' => $position, 'count' => $applying,
            'name' => $no > 0 ? (string)($steps[$no - 1]['name'] ?? '') : '', 'steps' => $out];
}

/**
 * A step has been approved: move the request on to the next step that applies,
 * inside the decision's transaction and row lock - so two final votes can never
 * advance it twice. Returns null when that was the last step (the caller then
 * approves the request, as it always has).
 *
 * @param array $sub  the LOCKED form_submissions row
 * @param int $actorId  who decided the step (0 = an override's administrator is passed in)
 * @param string $comment  the step decision's note
 * @param int $actorUserId  a person (users) who decided it in the portal (Part C2), else 0
 * @return ?array{from:string, to:string, step_no:int, position:int, count:int, name:string, fallback:?string}
 */
function catalogueAdvanceStep(PDO $conn, array $sub, int $actorId, string $comment, int $actorUserId = 0): ?array {
    $steps = catalogueSubmissionSteps($sub);
    if ($steps === null) return null;
    $no = (int)($sub['approval_step_no'] ?? 0);
    $next = null;
    for ($i = $no; $i < count($steps); $i++) {   // $no is 1-based, so $i starts at the step after it
        if (empty($steps[$i]['skipped'])) { $next = $i; break; }
    }
    if ($next === null) return null;

    $total = count($steps);
    $fromLabel = catalogueStepLabel($steps[$no - 1] ?? [], $no, $total);
    $reach = catalogueReachStep($conn, $steps[$next], $sub);
    $c = $reach['cols'];
    $now = gmdate('Y-m-d H:i:s');
    if (cataloguePeopleAvailable($conn)) {
        // Part C2: the kind of step, its cost centre, and a fresh clock for the deputy and Remind.
        $conn->prepare(
            'UPDATE form_submissions SET approver_id = ?, approval_team_id = ?, approvals_required = ?, rejections_required = ?,
                    approval_exclude_requester = ?, approver_kind = ?, approval_cost_centre_id = ?, approval_step_no = ?,
                    approval_round = approval_round + 1, approval_step_datetime = ?, approval_reminded_datetime = NULL
              WHERE id = ?'
        )->execute([$c['approver_id'], $c['approval_team_id'], $c['approvals_required'], $c['rejections_required'],
                    $c['approval_exclude_requester'], $c['approver_kind'], $c['approval_cost_centre_id'], $next + 1, $now, (int)$sub['id']]);
    } else {
        $conn->prepare(
            'UPDATE form_submissions SET approver_id = ?, approval_team_id = ?, approvals_required = ?, rejections_required = ?,
                    approval_exclude_requester = ?, approval_step_no = ?, approval_round = approval_round + 1
              WHERE id = ?'
        )->execute([$c['approver_id'], $c['approval_team_id'], $c['approvals_required'], $c['rejections_required'],
                    $c['approval_exclude_requester'], $next + 1, (int)$sub['id']]);
    }
    $after = array_merge($sub, $c, ['approval_step_no' => $next + 1, 'approval_round' => (int)$sub['approval_round'] + 1,
                                    'approval_step_datetime' => $now, 'approval_reminded_datetime' => null]);
    $toLabel = catalogueStepLabel($steps[$next], $next + 1, $total);
    catalogueApprovalEvent($conn, (int)$sub['id'], $actorId, 'step_passed',
        $fromLabel . ' -> ' . $toLabel . ' (' . catalogueRoutingLabel($conn, $after) . ')', $comment, $actorUserId);
    if ($reach['fallback'] !== null) {
        catalogueApprovalEvent($conn, (int)$sub['id'], 0, 'step_fallback', $toLabel . ' -> ' . catalogueRoutingLabel($conn, $after),
            $reach['fallback'] === 'default' ? 'Nobody could decide it; sent to the default approver' : 'Nobody could decide it; sent to its fallback approver');
    }
    $p = catalogueStepProgress($after);
    return ['from' => $fromLabel, 'to' => $toLabel, 'step_no' => $next + 1, 'position' => $p['position'], 'count' => $p['count'],
            'name' => (string)($steps[$next]['name'] ?? ''), 'fallback' => $reach['fallback'], 'sub' => $after];
}
