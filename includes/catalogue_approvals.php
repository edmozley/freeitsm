<?php
/**
 * Catalogue-request approvals (#928).
 *
 * A self-service catalogue item is a form (`is_portal_visible = 1`). When that form is
 * marked `requires_approval` and has an `approver_id`, a portal submission does NOT go
 * straight to a ticket — it waits, `approval_status = 'pending'`, for the designated
 * approver to sign it off. Approve raises the ticket (the same way the portal's own
 * new-ticket path does, so tenancy and requester identity are correct) and stamps
 * `form_submissions.ticket_id`; reject records the decision and raises nothing.
 *
 * WHY A DESIGNATED ANALYST, NOT THE REQUESTER'S MANAGER
 * ----------------------------------------------------
 * The classic business version routes to the requester's line manager, but portal
 * users have no manager relationship in this app yet (only a company). So v1 reuses the
 * Change Management "single approver" idea: one analyst signs a catalogue item off. A
 * manager relationship — and manager-based routing — is a later slice.
 *
 * WHY THE APPROVER IS SNAPSHOTTED
 * -------------------------------
 * `form_submissions.approver_id` is copied from the form AT SUBMIT TIME. Editing the
 * catalogue item later re-routes FUTURE requests, never ones already waiting — the
 * person who was told "your manager will approve this" stays the one who can.
 */

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/tenancy.php';
require_once __DIR__ . '/ticket_numbering.php';

// The workflow engine powers the outcome notifications. Load it if nothing else has;
// dispatch is always guarded, so a missing engine degrades to "no notification", never
// a failed decision.
if (!class_exists('WorkflowEngine')) {
    $__wfEngine = __DIR__ . '/../workflow/includes/engine.php';
    if (is_file($__wfEngine)) {
        require_once $__wfEngine;
    }
}

/**
 * Should a submission of this form be gated behind approval, and by whom?
 *
 * Only PORTAL submissions are gated: the feature is about customer catalogue requests
 * that auto-raise a ticket for the requester. An analyst filling a form internally has
 * no portal requester to raise one for, so it is never gated.
 *
 * Two ways to decide (GH #220): a named person (approver_id, the original) or a TEAM
 * (approval_team_id), where approvals_required different members must approve. A form
 * flagged `requires_approval` with neither is treated as UNCONFIGURED - it must never
 * strand a request with nobody able to clear it. Returns what to snapshot onto the
 * submission, or null for "let it through as normal".
 *
 * @param array $form  the forms row being submitted
 * @param ?int $portalUserId  users.id of the portal requester; null when an analyst filled the form internally
 * @return ?array{approver_id:?int, approval_team_id:?int, approvals_required:?int, rejections_required:?int, approval_exclude_requester:int}
 */
function catalogueApprovalGate(array $form, ?int $portalUserId): ?array {
    if ($portalUserId === null) return null;
    if (empty($form['requires_approval'])) return null;
    $teamId = (int)($form['approval_team_id'] ?? 0);
    if ($teamId > 0) {
        return [
            'approver_id'                => null,
            'approval_team_id'           => $teamId,
            'approvals_required'         => max(1, (int)($form['approvals_required'] ?? 1)),
            'rejections_required'        => max(1, (int)($form['rejections_required'] ?? 1)),
            'approval_exclude_requester' => 1,   // always, in team mode - four eyes
        ];
    }
    if (empty($form['approver_id'])) return null;
    return [
        'approver_id'                => (int) $form['approver_id'],
        'approval_team_id'           => null,
        'approvals_required'         => null,
        'rejections_required'        => null,
        'approval_exclude_requester' => !empty($form['approval_exclude_requester']) ? 1 : 0,
    ];
}

/**
 * Are the group-approval columns there (GH #220)? Before Database Verification they
 * are not, and every form is then a named-person form, exactly as before.
 */
function catalogueTeamApprovalAvailable(PDO $conn): bool {
    static $known = null;
    if ($known !== null) return $known;
    try {
        $q = $conn->query(
            "SELECT (SELECT COUNT(*) FROM information_schema.columns
                      WHERE table_schema = DATABASE() AND table_name = 'form_submissions' AND column_name = 'approval_round')
                  + (SELECT COUNT(*) FROM information_schema.tables
                      WHERE table_schema = DATABASE() AND table_name = 'form_submission_votes')"
        );
        $known = (int)$q->fetchColumn() === 2;
    } catch (Throwable $e) {
        $known = false;
    }
    return $known;
}

/**
 * How a request is decided, from its SNAPSHOT - never from the form, so editing the
 * form never reroutes or lowers the bar for a request already waiting.
 *
 * @param array $sub  the form_submissions row
 * @return array{mode:string, team_id:?int, approver_id:?int, approvals_required:int, rejections_required:int, exclude_requester:bool, round:int}
 */
function catalogueApprovalPolicy(array $sub): array {
    $teamId = (int)($sub['approval_team_id'] ?? 0);
    return [
        'mode'                => $teamId > 0 ? 'team' : 'person',
        'team_id'             => $teamId > 0 ? $teamId : null,
        'approver_id'         => !empty($sub['approver_id']) ? (int)$sub['approver_id'] : null,
        'approvals_required'  => $teamId > 0 ? max(1, (int)($sub['approvals_required'] ?? 1)) : 1,
        'rejections_required' => $teamId > 0 ? max(1, (int)($sub['rejections_required'] ?? 1)) : 1,
        'exclude_requester'   => $teamId > 0 || !empty($sub['approval_exclude_requester']),
        'round'               => max(1, (int)($sub['approval_round'] ?? 1)),
    ];
}

/**
 * Is this analyst the person who made the request? The requester is a portal account
 * (users) and the approver a staff account (analysts), so the two are the same person
 * when they share an email address - a member of staff requesting through the portal.
 * Compared ignoring case; an empty address on either side never matches.
 *
 * @param int $analystId  the would-be approver
 * @param array $sub  the form_submissions row (submitted_by_user_id)
 */
function catalogueAnalystIsRequester(PDO $conn, int $analystId, array $sub): bool {
    $userId = (int)($sub['submitted_by_user_id'] ?? 0);
    if ($userId <= 0 || $analystId <= 0) return false;
    $st = $conn->prepare(
        "SELECT LOWER(TRIM(a.email)) AS ae, LOWER(TRIM(u.email)) AS ue
           FROM analysts a, users u WHERE a.id = ? AND u.id = ?"
    );
    $st->execute([$analystId, $userId]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r && (string)$r['ae'] !== '' && $r['ae'] === $r['ue'];
}

/**
 * The people who may vote on a team-approved request right now: active members of the
 * (active) team, less the requester. Asked at every vote, so somebody who has left the
 * team since the request arrived cannot vote, and somebody who has joined can.
 *
 * @param array $sub  the form_submissions row
 * @return int[] analysts.id
 */
function catalogueEligibleMembers(PDO $conn, array $sub): array {
    $teamId = (int)($sub['approval_team_id'] ?? 0);
    if ($teamId <= 0) return [];
    $st = $conn->prepare(
        "SELECT a.id
           FROM analyst_teams at
           JOIN teams t    ON t.id = at.team_id AND t.is_active = 1
           JOIN analysts a ON a.id = at.analyst_id AND COALESCE(a.is_active, 1) = 1
          WHERE at.team_id = ?"
    );
    $st->execute([$teamId]);
    $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    return array_values(array_filter($ids, fn($id) => !catalogueAnalystIsRequester($conn, $id, $sub)));
}

/**
 * Where a request stands: the votes of its current round, the counts, who could still
 * vote, and whether it is STUCK - neither outcome can be reached any more (the team has
 * emptied, too few independent members, or the votes have split with nobody left).
 * A stuck request is never waved through: an administrator moves or overrides it.
 *
 * @param array $sub  the form_submissions row
 */
function catalogueApprovalProgress(PDO $conn, array $sub): array {
    $p = catalogueApprovalPolicy($sub);
    $out = ['mode' => $p['mode'], 'approvals_required' => $p['approvals_required'],
            'rejections_required' => $p['rejections_required'], 'approvals' => 0, 'rejections' => 0,
            'votes' => [], 'waiting_on' => [], 'stuck' => false, 'stuck_reason' => null];
    if ($p['mode'] === 'person') {
        if ($p['approver_id'] === null) {
            $out['stuck'] = true; $out['stuck_reason'] = 'no_approver';
        } elseif ($p['exclude_requester'] && catalogueAnalystIsRequester($conn, $p['approver_id'], $sub)) {
            $out['stuck'] = true; $out['stuck_reason'] = 'approver_is_requester';
        }
        return $out;
    }
    if (catalogueTeamApprovalAvailable($conn)) {
        $st = $conn->prepare(
            "SELECT v.analyst_id, v.decision, v.comment, v.was_member, v.voted_datetime, a.full_name
               FROM form_submission_votes v LEFT JOIN analysts a ON a.id = v.analyst_id
              WHERE v.submission_id = ? AND v.approval_round = ? ORDER BY v.voted_datetime, v.id"
        );
        $st->execute([(int)$sub['id'], $p['round']]);
        $out['votes'] = $st->fetchAll(PDO::FETCH_ASSOC);
    }
    $voted = [];
    foreach ($out['votes'] as $v) {
        $voted[(int)$v['analyst_id']] = true;
        if ($v['decision'] === 'approved') $out['approvals']++; else $out['rejections']++;
    }
    $out['waiting_on'] = array_values(array_filter(catalogueEligibleMembers($conn, $sub), fn($id) => !isset($voted[$id])));
    if (($sub['approval_status'] ?? '') === 'pending') {
        $left = count($out['waiting_on']);
        if ($out['approvals'] + $left < $p['approvals_required'] && $out['rejections'] + $left < $p['rejections_required']) {
            $out['stuck'] = true;
            $out['stuck_reason'] = $left === 0 && !$out['votes'] ? 'no_members' : 'not_enough_members';
        }
    }
    return $out;
}

/**
 * Approve or reject a pending catalogue request.
 *
 * NAMED PERSON (the original): the request's approver, or an administrator, decides it
 * outright. With approval_exclude_requester on, the approver may not decide a request
 * they made themselves.
 *
 * TEAM (GH #220): each call is ONE member's vote. It is recorded, and the request is
 * decided only when approvals_required different members have approved, or
 * rejections_required have rejected - then, exactly once, the ticket or the form's
 * own "when approved" list runs. A vote needs to be from a current, active member who
 * is not the requester; a rejection needs a reason. An administrator's click is not a
 * vote: catalogueApprovalOverride() is the separate, reasoned way round.
 *
 * TRAP: the whole decision runs holding the submission's row lock (SELECT ... FOR
 * UPDATE). Two final votes landing together would otherwise both see "one short",
 * both tip it, and raise two tickets; now the second waits, then sees it decided.
 *
 * @param int $actorId  analysts.id of the person deciding
 * @param int $submissionId  form_submissions.id of the pending request
 * @param string $decision 'approved' or 'rejected'
 * @param string $comment  the note, trimmed; empty is stored as NULL. Required to reject in team mode.
 * @return array { decision ('approved' / 'rejected', or 'recorded' for a vote that did not decide it), ticket_id, ticket_number, progress? }
 */
function catalogueApprovalDecide(PDO $conn, int $actorId, int $submissionId, string $decision, string $comment = ''): array {
    $decision = strtolower(trim($decision));
    if (!in_array($decision, ['approved', 'rejected'], true)) {
        throw new Exception('Decision must be approve or reject');
    }
    $comment = trim($comment);

    $conn->beginTransaction();
    try {
        $sub = catalogueLockSubmission($conn, $submissionId);
        $policy = catalogueApprovalPolicy($sub);

        if ($policy['mode'] === 'team') {
            if (!in_array($actorId, catalogueEligibleMembers($conn, $sub), true)) {
                throw new Exception(catalogueAnalystIsRequester($conn, $actorId, $sub)
                    ? 'You cannot approve a request you made yourself'
                    : 'Only current members of the approving team can decide this request');
            }
            if ($decision === 'rejected' && $comment === '') {
                throw new Exception('Give a reason for rejecting this request');
            }
            try {
                $conn->prepare(
                    "INSERT INTO form_submission_votes (submission_id, approval_round, analyst_id, decision, comment, was_member, voted_datetime)
                     VALUES (?, ?, ?, ?, ?, 1, UTC_TIMESTAMP())"
                )->execute([$submissionId, $policy['round'], $actorId, $decision, $comment !== '' ? $comment : null]);
            } catch (PDOException $e) {
                if ($e->getCode() === '23000') throw new Exception('You have already decided this request');
                throw $e;
            }
            $progress = catalogueApprovalProgress($conn, $sub);
            $final = $progress['approvals'] >= $policy['approvals_required'] ? 'approved'
                   : ($progress['rejections'] >= $policy['rejections_required'] ? 'rejected' : null);
            if ($final === null) {
                $conn->commit();
                return ['decision' => 'recorded', 'ticket_id' => null, 'ticket_number' => null, 'progress' => $progress];
            }
            $decision = $final;
        } else {
            // Only the assigned approver - or an administrator - may decide. The
            // requester was told this specific person would sign it off.
            $isAdmin = function_exists('sessionIsAdmin') ? sessionIsAdmin() : false;
            if ($policy['approver_id'] !== $actorId && !$isAdmin) {
                throw new Exception('Only the assigned approver can decide this request');
            }
            // GH #220: the form may say the requester cannot approve their own
            // request - administrators included, or the rule would mean nothing.
            if ($policy['exclude_requester'] && catalogueAnalystIsRequester($conn, $actorId, $sub)) {
                throw new Exception('You cannot approve a request you made yourself');
            }
        }

        $done = catalogueFinishDecision($conn, $sub, $decision, $actorId, $comment);
        $conn->commit();
    } catch (Exception $e) {
        if ($conn->inTransaction()) $conn->rollBack();
        throw $e;
    }

    return catalogueAfterDecision($conn, $sub, $decision, $comment, $done);
}

/**
 * An administrator decides a team-approved request WITHOUT the votes - for a request
 * that is stuck, or urgent. It needs a reason, is recorded as an override (never as
 * votes), and then runs exactly what a decision would.
 *
 * @param int $actorId  the administrator
 * @param string $decision 'approved' or 'rejected'
 * @param string $reason  why - required
 */
function catalogueApprovalOverride(PDO $conn, int $actorId, int $submissionId, string $decision, string $reason): array {
    $decision = strtolower(trim($decision));
    $reason = trim($reason);
    if (!in_array($decision, ['approved', 'rejected'], true)) throw new Exception('Decision must be approve or reject');
    if (!(function_exists('sessionIsAdmin') && sessionIsAdmin())) throw new Exception('Only an administrator can override an approval');
    if ($reason === '') throw new Exception('Give a reason for the override');

    $conn->beginTransaction();
    try {
        $sub = catalogueLockSubmission($conn, $submissionId);
        if (catalogueApprovalPolicy($sub)['mode'] !== 'team') {
            throw new Exception('Override is for team approvals - decide this request directly');
        }
        catalogueApprovalEvent($conn, $submissionId, $actorId, 'override_' . $decision, null, $reason);
        $done = catalogueFinishDecision($conn, $sub, $decision, $actorId, 'Override: ' . $reason);
        $conn->commit();
    } catch (Exception $e) {
        if ($conn->inTransaction()) $conn->rollBack();
        throw $e;
    }
    return catalogueAfterDecision($conn, $sub, $decision, 'Override: ' . $reason, $done);
}

/**
 * An administrator moves a waiting request to another team or person - the way out for
 * a stuck one. It needs a reason and is recorded. Votes already cast stay on record but
 * stop counting: a new round starts, so the new approvers decide it afresh.
 *
 * @param int $actorId  the administrator
 * @param array $to  ['team_id' => n, 'approvals_required' => n, 'rejections_required' => n] or ['approver_id' => n]
 * @param string $reason  why - required
 */
function catalogueApprovalMove(PDO $conn, int $actorId, int $submissionId, array $to, string $reason): void {
    $reason = trim($reason);
    if (!(function_exists('sessionIsAdmin') && sessionIsAdmin())) throw new Exception('Only an administrator can move a request');
    if ($reason === '') throw new Exception('Give a reason for moving the request');
    $teamId = (int)($to['team_id'] ?? 0);
    $approverId = (int)($to['approver_id'] ?? 0);
    if (($teamId > 0) === ($approverId > 0)) throw new Exception('Choose a team or a person to move the request to');

    $conn->beginTransaction();
    try {
        $sub = catalogueLockSubmission($conn, $submissionId);
        $from = catalogueRoutingLabel($conn, $sub);
        if ($teamId > 0) {
            $t = $conn->prepare("SELECT name FROM teams WHERE id = ? AND is_active = 1");
            $t->execute([$teamId]);
            if ($t->fetchColumn() === false) throw new Exception('That team was not found');
            $conn->prepare(
                "UPDATE form_submissions SET approval_team_id = ?, approver_id = NULL, approvals_required = ?,
                        rejections_required = ?, approval_exclude_requester = 1, approval_round = approval_round + 1
                  WHERE id = ?"
            )->execute([$teamId, max(1, (int)($to['approvals_required'] ?? $sub['approvals_required'] ?? 1)),
                        max(1, (int)($to['rejections_required'] ?? $sub['rejections_required'] ?? 1)), $submissionId]);
        } else {
            $a = $conn->prepare("SELECT id FROM analysts WHERE id = ? AND COALESCE(is_active, 1) = 1");
            $a->execute([$approverId]);
            if ($a->fetchColumn() === false) throw new Exception('That person was not found');
            $conn->prepare(
                "UPDATE form_submissions SET approver_id = ?, approval_team_id = NULL, approvals_required = NULL,
                        rejections_required = NULL, approval_round = approval_round + 1
                  WHERE id = ?"
            )->execute([$approverId, $submissionId]);
        }
        $after = catalogueLockSubmission($conn, $submissionId);
        catalogueApprovalEvent($conn, $submissionId, $actorId, 'moved', $from . ' -> ' . catalogueRoutingLabel($conn, $after), $reason);
        $conn->commit();
    } catch (Exception $e) {
        if ($conn->inTransaction()) $conn->rollBack();
        throw $e;
    }
}

/**
 * Load a pending request and hold its row lock for the rest of the transaction.
 *
 * @param int $submissionId  form_submissions.id
 */
function catalogueLockSubmission(PDO $conn, int $submissionId): array {
    $stmt = $conn->prepare(
        "SELECT s.*, f.title AS form_title
           FROM form_submissions s
           JOIN forms f ON f.id = s.form_id
          WHERE s.id = ? FOR UPDATE"
    );
    $stmt->execute([$submissionId]);
    $sub = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$sub)                                 throw new Exception('That request was not found');
    if ($sub['approval_status'] !== 'pending') throw new Exception('That request is not awaiting approval');
    return $sub;
}

/** "Team: Finance (2 approvals)" or "Priya Shah" - what a request is waiting on, for the record. */
function catalogueRoutingLabel(PDO $conn, array $sub): string {
    $p = catalogueApprovalPolicy($sub);
    if ($p['mode'] === 'team') {
        $t = $conn->prepare("SELECT name FROM teams WHERE id = ?");
        $t->execute([$p['team_id']]);
        return 'Team: ' . ($t->fetchColumn() ?: '#' . $p['team_id']) . ' (' . $p['approvals_required'] . ' approval' . ($p['approvals_required'] === 1 ? '' : 's') . ')';
    }
    $a = $conn->prepare("SELECT full_name FROM analysts WHERE id = ?");
    $a->execute([(int)$p['approver_id']]);
    return (string)($a->fetchColumn() ?: 'nobody');
}

/** Record an administrator's action on a request (override, move). */
function catalogueApprovalEvent(PDO $conn, int $submissionId, int $actorId, string $action, ?string $detail, string $reason): void {
    $conn->prepare(
        "INSERT INTO form_submission_approval_events (submission_id, analyst_id, action, detail, reason, event_datetime)
         VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP())"
    )->execute([$submissionId, $actorId ?: null, $action, $detail, $reason]);
}

/**
 * The decision itself, inside the caller's transaction: raise the ticket (unless the
 * form's own list owns that) and stamp the submission. Shared by a named approver's
 * decision, a team's deciding vote and an override, so they cannot drift apart.
 *
 * @param array $sub  the locked form_submissions row
 * @param string $decision 'approved' or 'rejected'
 * @param int $actorId  who decided (the last voter, for a team)
 * @param string $comment  the note stamped on the submission
 * @return array{ticket_id:?int, ticket_number:?string, list:?array}
 */
function catalogueFinishDecision(PDO $conn, array $sub, string $decision, int $actorId, string $comment): array {
    // Does this form configure its own actions for this decision (#95)?
    //
    // 🔑 NULL means never configured, and the pre-#95 behaviour stands: approving
    // raises a ticket the hard-coded way. That is what makes this upgrade safe
    // with NO data migration — every catalogue form that existed before keeps
    // raising its ticket, untouched. Only a form somebody has actually configured
    // behaves differently, and then it does exactly what they configured.
    $formLists = catalogueFormActionLists($conn, (int)$sub['form_id']);
    $decisionList = $formLists[$decision] ?? null;
    $listOwnsTheTicket = ($decision === 'approved' && $decisionList !== null);

    $ticketId = null;
    $ticketNumber = null;
    if ($decision === 'approved') {
        // When the form owns this, the ticket (if any) is raised by its own
        // action list AFTER the commit — see catalogueAfterDecision(). Doing it
        // here would put an email send inside a transaction, and a rolled-back
        // email is still an email somebody received.
        if (!$listOwnsTheTicket) {
            [$ticketId, $ticketNumber] = catalogueCreateTicketFromSubmission($conn, $sub);
        }
        $conn->prepare(
            "UPDATE form_submissions
                SET approval_status = 'approved', approval_decided_by_id = ?,
                    approval_decided_datetime = UTC_TIMESTAMP(), approval_comment = ?, ticket_id = ?
              WHERE id = ?"
        )->execute([$actorId ?: null, $comment !== '' ? $comment : null, $ticketId, (int)$sub['id']]);
    } else {
        $conn->prepare(
            "UPDATE form_submissions
                SET approval_status = 'rejected', approval_decided_by_id = ?,
                    approval_decided_datetime = UTC_TIMESTAMP(), approval_comment = ?
              WHERE id = ?"
        )->execute([$actorId ?: null, $comment !== '' ? $comment : null, (int)$sub['id']]);
    }
    return ['ticket_id' => $ticketId, 'ticket_number' => $ticketNumber, 'list' => $decisionList];
}

/**
 * After the commit: tell the requester the outcome and run the form's own list for
 * the decision. Best-effort - a failure here never undoes a decision.
 *
 * @param array $sub  the form_submissions row
 * @param string $decision 'approved' or 'rejected'
 * @param string $comment  the decision's note
 * @param array $done  catalogueFinishDecision()'s result
 */
function catalogueAfterDecision(PDO $conn, array $sub, string $decision, string $comment, array $done): array {
    $submissionId = (int)$sub['id'];
    $ticketId     = $done['ticket_id'];
    $ticketNumber = $done['ticket_number'];
    $decisionList = $done['list'];

    // Tell the requester the outcome — best-effort, after commit, never fails a decision.
    if (class_exists('WorkflowEngine')) {
        try {
            // The answers travel with the decision. Without them a rule on this
            // event can say "your request was approved" but not *which* device or
            // start date was approved — and the requester has to go and look it
            // up, which defeats telling them at all.
            $answers = catalogueSubmissionAnswerMap($conn, $submissionId);
            WorkflowEngine::dispatch('catalogue_request.' . $decision, [
                'form'    => ['id' => (int)$sub['form_id'], 'name' => $sub['form_title']],
                'request' => [
                    'id'            => $submissionId,
                    'comment'       => $comment,
                    'ticket_id'     => $ticketId,
                    'ticket_number' => $ticketNumber,
                ],
                'submission' => [
                    'id'     => $submissionId,
                    'email'  => $answers['email'],
                    'fields' => $answers['fields'],
                ],
            ]);
        } catch (Exception) { /* notification is a bonus, not the mechanism */ }
    }

    // The form's own list for this decision. After the commit, so nothing it does
    // — least of all sending an email — can be undone by a rollback.
    if ($decisionList) {
        try {
            $answers = catalogueSubmissionAnswerMap($conn, $submissionId);
            $run = WorkflowEngine::runActionList(
                'Form: ' . $sub['form_title'] . ' — when ' . $decision,
                'catalogue_request.' . $decision,
                $decisionList,
                [
                    'form'    => ['id' => (int)$sub['form_id'], 'name' => $sub['form_title']],
                    'request' => ['id' => $submissionId, 'comment' => $comment],
                    'submission' => [
                        'id'     => $submissionId,
                        'email'  => $answers['email'],
                        'fields' => $answers['fields'],
                    ],
                ]
            );

            // If the list raised a ticket, the submission must point at it — that
            // column is what the requester's "Your requests" panel reads, and a
            // request showing no ticket after one was raised looks like a failure.
            foreach ($run['steps'] as $step) {
                if (($step['type'] ?? '') === 'create_ticket' && !empty($step['result']['ticket_id'])) {
                    $ticketId     = (int)$step['result']['ticket_id'];
                    $ticketNumber = (string)($step['result']['ticket_number'] ?? '');
                    $conn->prepare("UPDATE form_submissions SET ticket_id = ? WHERE id = ?")
                         ->execute([$ticketId, $submissionId]);
                    break;
                }
            }
        } catch (Exception $e) {
            error_log('Form action list error on ' . $decision . ': ' . $e->getMessage());
        }
    }

    return ['decision' => $decision, 'ticket_id' => $ticketId, 'ticket_number' => $ticketNumber];
}

/**
 * Raise the ticket an approved request becomes.
 *
 * Deliberately mirrors api/self-service/create_ticket.php — the CORRECT portal path:
 * requester resolved by users.id (never an email string), company taken from the
 * requester's own record, status 'Open', the install's default priority. The body is
 * the submitted answers rendered as an escaped table, so a customer's field values can
 * never inject markup into the analyst's reading pane.
 *
 * @param array $sub  the form_submissions row plus form_title, as loaded by catalogueApprovalDecide()
 * @param array $overrides  optional ticket settings that win over the submission: subject, body_html,
 *     status_id, priority_id, department_id, ticket_type_id, assigned_analyst_id, assigned_team_id,
 *     mailbox_id (the mailbox replies go from), from_email/from_name (requester when the submission
 *     has no account), audit_note
 * @return array [int ticketId, string ticketNumber]
 */
function catalogueCreateTicketFromSubmission(PDO $conn, array $sub, array $overrides = []): array {
    // --- who is asking ------------------------------------------------------
    // Two ways in. A portal catalogue request always carries the account that
    // raised it, which is the good case: a real users.id, and a company that
    // comes from their own record. A form filled in by somebody who is not
    // signed in has no account, only whatever email address they typed — so
    // that address is resolved to a user, creating one if need be. Anonymous
    // submissions were the ONLY case the workflow action ever handled, which is
    // why it produced the weaker ticket even for portal requests.
    $userId = (int)($sub['submitted_by_user_id'] ?? 0);
    $fallbackEmail = trim((string)($overrides['from_email'] ?? ''));
    $fallbackName  = trim((string)($overrides['from_name']  ?? ''));

    if (!$userId && $fallbackEmail !== '') {
        $look = $conn->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
        $look->execute([$fallbackEmail]);
        $userId = (int)$look->fetchColumn();
        if (!$userId) {
            $conn->prepare('INSERT INTO users (email, display_name, created_at) VALUES (?, ?, UTC_TIMESTAMP())')
                 ->execute([$fallbackEmail, $fallbackName !== '' ? $fallbackName : $fallbackEmail]);
            $userId = (int)$conn->lastInsertId();
        }
    }
    if (!$userId) throw new Exception('This request has no requester to raise a ticket for');

    $u = $conn->prepare("SELECT email, username, display_name, tenant_id FROM users WHERE id = ?");
    $u->execute([$userId]);
    $user = $u->fetch(PDO::FETCH_ASSOC);
    if (!$user) throw new Exception('The requester account no longer exists');

    // NULL, not '' — a directory requester with no mailbox has no address, and '' would
    // look like one everywhere downstream. The name carries their identity.
    $fromEmail = ($user['email'] !== null && $user['email'] !== '') ? $user['email'] : null;
    $fromName  = $user['display_name'] ?: ($user['username'] ?: ($fromEmail ?: ('#' . $userId)));

    // Company = whose account raised it. No company on file → NULL (triage) on a
    // multi-company install, Default on a single-company one — exactly as the portal's
    // own new-ticket path decides.
    $tenantId = $user['tenant_id'] !== null
        ? (int) $user['tenant_id']
        : (isMultiTenant($conn) ? null : getDefaultTenantId($conn));

    // --- what the ticket says -----------------------------------------------
    // An override always wins; the submission fills in whatever was left blank.
    // That is the whole rule, and it is what lets one function serve both the
    // approval path (which configures nothing) and a form's own action list
    // (which may configure everything).
    $subject = trim((string)($overrides['subject'] ?? ''));
    if ($subject === '') $subject = trim((string)($sub['form_title'] ?? '')) ?: 'Service request';
    if (mb_strlen($subject) > 255) $subject = mb_substr($subject, 0, 255);

    // A blank body means "show me what they asked for", not "send an empty
    // ticket" — so the escaped answer table is the default rather than a
    // special case somebody has to know to ask for.
    $bodyHtml = (string)($overrides['body_html'] ?? '');
    if (trim($bodyHtml) === '') {
        // The FORM's name, never $subject. Once a subject override existed, passing
        // $subject made the body's opening line repeat the subject already at the
        // top of the ticket — losing the one thing that line is for, which is
        // saying which form this came from.
        $bodyHtml = catalogueSubmissionBodyHtml(
            $conn,
            (int)$sub['id'],
            trim((string)($sub['form_title'] ?? '')) ?: 'a form'
        );
    }
    $bodyPreview = mb_substr(trim(preg_replace('/\s+/', ' ', strip_tags($bodyHtml))), 0, 200);

    // --- routing ------------------------------------------------------------
    $statusId   = catalogueOverrideOrDefault($conn, $overrides, 'status_id',   'ticket_statuses');
    $priorityId = catalogueOverrideOrDefault($conn, $overrides, 'priority_id', 'ticket_priorities');
    $departmentId = isset($overrides['department_id'])       && $overrides['department_id']       ? (int)$overrides['department_id']       : null;
    $typeId       = isset($overrides['ticket_type_id'])      && $overrides['ticket_type_id']      ? (int)$overrides['ticket_type_id']      : null;
    $analystId    = isset($overrides['assigned_analyst_id']) && $overrides['assigned_analyst_id'] ? (int)$overrides['assigned_analyst_id'] : null;
    // #1566. The workflow "Create a ticket" action can drop a form submission
    // straight into a team's queue — "guest Wi-Fi requests go to Infrastructure"
    // — without naming a person. Null unless the rule set one.
    $teamId       = isset($overrides['assigned_team_id'])    && $overrides['assigned_team_id']    ? (int)$overrides['assigned_team_id']    : null;
    // GH #216. The mailbox on the opening message is the one every reply goes
    // from; with none, the ticket cannot be emailed. Checked by the caller.
    $mailboxId    = isset($overrides['mailbox_id'])          && $overrides['mailbox_id']          ? (int)$overrides['mailbox_id']          : null;

    // GH #230: no type configured on the form or the rule, so the type rules /
    // default may give one - before the number, which can carry it. A workflow
    // raising from a form says so through '_channel'; a form's own action is 'catalogue'.
    // GH #218: none set by the catalogue item, so the company's default department -
    // before the type rules, which can match on the department.
    require_once __DIR__ . '/tenant_settings.php';
    [$departmentId, $departmentNote] = ticketDepartmentForNewTicket($conn, $tenantId !== null ? (int)$tenantId : null, $departmentId);
    $typePick = null;
    if ($typeId === null) {
        require_once __DIR__ . '/ticket_type_rules.php';
        $typePick = ticketTypeAutoPick($conn, $tenantId !== null ? (int)$tenantId : null, [
            'channel' => ($overrides['_channel'] ?? '') === 'workflow' ? 'workflow' : 'catalogue',
            'mailbox_id' => $mailboxId, 'department_id' => $departmentId, 'subject' => $subject, 'requester_email' => $fromEmail,
        ]);
        $typeId = $typePick['type_id'] ?? null;
    }

    // TRAP: the install's numbering, never a number made here - see
    //   messagingGenerateTicketNumber() for what went wrong when it was.
    $ticketNumber = TicketNumbering::next($conn, $typeId, $tenantId !== null ? (int)$tenantId : null);

    // owner_id with assigned_analyst_id, as every path that creates or assigns a
    // ticket sets both: the Owner field reads owner_id (GH #216).
    $conn->prepare(
        "INSERT INTO tickets (ticket_number, subject, status_id, priority_id,
                              department_id, ticket_type_id, assigned_team_id,
                              assigned_analyst_id, owner_id,
                              user_id, tenant_id, created_datetime, updated_datetime)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())"
    )->execute([
        $ticketNumber, $subject, $statusId, $priorityId,
        $departmentId, $typeId, $teamId, $analystId, $analystId,
        $userId, $tenantId,
    ]);
    $ticketId = (int)$conn->lastInsertId();
    ticketDepartmentNote($conn, $ticketId, $departmentNote);
    if ($typePick) ticketTypeAutoRecord($conn, $ticketId, $typePick);
    // Confidential defaults (discussion #62). Every path that creates a ticket
    // calls this - see the header of includes/ticket_sensitivity.php for why.
    require_once __DIR__ . '/ticket_sensitivity.php';
    ticketSensitivityApplyDefaults($conn, $ticketId);

    $conn->prepare(
        "INSERT INTO emails (subject, from_address, from_name, to_recipients, received_datetime,
             body_preview, body_content, body_type, has_attachments, importance, is_read, ticket_id, is_initial, direction, mailbox_id)
         VALUES (?, ?, ?, ?, UTC_TIMESTAMP(), ?, ?, 'html', 0, 'normal', 0, ?, 1, 'Portal', ?)"
    )->execute([$subject, $fromEmail, $fromName, $fromEmail, $bodyPreview, $bodyHtml, $ticketId, $mailboxId]);

    // Provenance. Without this a ticket raised from a form is indistinguishable
    // from one somebody typed, and the first question asked about a surprising
    // ticket is always "where did this come from?". analyst_id NULL is the
    // established marker for "the system did this, not a person".
    $conn->prepare(
        "INSERT INTO ticket_audit (ticket_id, analyst_id, field_name, old_value, new_value, created_datetime)
         VALUES (?, NULL, 'Ticket Created', NULL, ?, UTC_TIMESTAMP())"
    )->execute([$ticketId, (string)($overrides['audit_note'] ?? 'Raised from a form submission')]);

    return [$ticketId, $ticketNumber];
}

/**
 * An overridden lookup id, or the install's default for that table.
 *
 * The default is resolved in PHP rather than left as a subselect inside the
 * INSERT so that both branches produce a plain integer — a column that is
 * sometimes a value and sometimes a subquery is the kind of thing that works
 * until the day somebody adds a second caller.
 *
 * @param array $overrides  the caller's ticket overrides, keyed by column name
 * @param string $key  the override key to look for, e.g. 'status_id'
 * @param string $table  the lookup table to take the default from (trusted - interpolated into the SQL),
 *     e.g. 'ticket_statuses'; needs is_active, is_default and display_order columns
 */
function catalogueOverrideOrDefault(PDO $conn, array $overrides, string $key, string $table): ?int {
    if (!empty($overrides[$key])) return (int)$overrides[$key];
    $id = $conn->query(
        "SELECT id FROM {$table} WHERE is_active = 1 ORDER BY is_default DESC, display_order, id LIMIT 1"
    )->fetchColumn();
    return $id !== false ? (int)$id : null;
}

/**
 * Raise a ticket from a submission id, loading the row the way the approval
 * path does. The entry point for callers that hold an id rather than a row —
 * the workflow engine's create_ticket action, and (next) a form's own action
 * list.
 *
 * @param int $submissionId  form_submissions.id to raise the ticket from
 * @param array $overrides  ticket settings that win over the submission - see catalogueCreateTicketFromSubmission()
 * @return array{ticket_id:int, ticket_number:string}
 */
function catalogueRaiseTicketFromSubmissionId(PDO $conn, int $submissionId, array $overrides = []): array {
    $stmt = $conn->prepare(
        "SELECT s.*, f.title AS form_title
           FROM form_submissions s
           JOIN forms f ON f.id = s.form_id
          WHERE s.id = ?"
    );
    $stmt->execute([$submissionId]);
    $sub = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$sub) throw new Exception('That submission no longer exists');

    // 🔴 Never past an approval. A workflow rule "on catalogue_request.submitted,
    // create a ticket" reached this while the request was still pending - raising
    // the ticket the approver had not yet agreed to - and a rule on .rejected raised
    // one for a request that was refused. The help said that could not happen.
    // Reported privately, October 2026. And a ticket already raised (by the
    // approval itself, or a rule on .approved) is returned, not raised twice.
    $status = (string)($sub['approval_status'] ?? 'not_required');
    if ($status === 'pending' || $status === 'rejected') {
        throw new Exception($status === 'pending'
            ? 'That request is still awaiting approval, so no ticket was raised'
            : 'That request was rejected, so no ticket was raised');
    }
    if (!empty($sub['ticket_id'])) {
        $tn = $conn->prepare("SELECT ticket_number FROM tickets WHERE id = ?");
        $tn->execute([(int)$sub['ticket_id']]);
        $num = $tn->fetchColumn();
        if ($num !== false) return ['ticket_id' => (int)$sub['ticket_id'], 'ticket_number' => (string)$num];
    }

    [$ticketId, $ticketNumber] = catalogueCreateTicketFromSubmission($conn, $sub, $overrides);
    if (!empty($sub['id'])) {
        $conn->prepare("UPDATE form_submissions SET ticket_id = ? WHERE id = ? AND ticket_id IS NULL")
             ->execute([$ticketId, (int)$sub['id']]);
    }
    return ['ticket_id' => $ticketId, 'ticket_number' => $ticketNumber];
}

/**
 * One stored answer as the words a person would use.
 *
 * A `checkbox` is stored as 1 or 0, which read as "1" and "0" wherever an answer
 * is shown back - on the approval card and in the raised ticket. An approver
 * seeing "My budget holder has approved this spend: 1" has to know the storage
 * format to read their own inbox. `checkboxes` (plural) is a different field and
 * is stored as a JSON array of the chosen labels.
 *
 * @param ?string $raw  the stored form_submission_data.field_value; null reads as empty
 * @param ?string $fieldType  form_fields.field_type, e.g. 'checkbox', 'checkboxes', 'grid'
 * @param array $field  the form_fields row (label, config) - only used to label a 'grid' answer's columns
 */
function catalogueAnswerText(?string $raw, ?string $fieldType, array $field = []): string {
    $val = (string)$raw;

    if ($fieldType === 'checkbox') {
        if ($val === '') return 'No';
        return in_array(strtolower($val), ['1', 'true', 'yes', 'on'], true) ? 'Yes' : 'No';
    }

    /* 🔴 A TABLE'S ANSWER IS A LIST OF OBJECTS, not a list of strings, and the
       implode below cannot render it. It produced "Array, Array" AND emitted a
       PHP warning per row — and on an install with display_errors on, that
       warning is printed into the response body before the JSON, which is not
       JSON any more. The approvals inbox then showed a bare "Error" while its
       counts (fetched by the one filter with no rows to flatten) looked fine.

       ⚠️ Reported by a user; not reproducible on an install whose pending
       requests happen to contain no table question.

       🔑 FormsService::gridToText() is the existing renderer for exactly this —
       one line per row, cells labelled from the form's own column definitions.
       There is no second flattener here. The require is lazy for the reason
       given on catalogueFormActionLists() below: forms.php pulls in the
       workflow engine at file level, and the engine lazily requires this file.

       This same mistake is documented in FormsService::submitForm(), which
       special-cases 'grid' before its own implode. The note was there; this
       copy simply never received it. */
    if ($fieldType === 'grid') {
        try {
            require_once __DIR__ . '/services/forms.php';
            return FormsService::gridToText($field + ['config' => null], $raw);
        } catch (Throwable $e) {
            // A table we cannot label is still better said than crashed.
            error_log('catalogueAnswerText: could not render a table answer: ' . $e->getMessage());
            $rows = json_decode($val, true);
            return is_array($rows) ? count($rows) . ' row(s)' : $val;
        }
    }

    $decoded = json_decode($val, true);          // checkboxes are stored as a JSON array
    if (is_array($decoded)) {
        /* Defence in depth, for a field type nobody has taught this function
           yet. A nested value must never reach implode(): the warning it emits
           breaks the JSON of every endpoint that calls this, which is how one
           unhandled type took out a whole screen. */
        $decoded = array_map(
            fn($v) => is_scalar($v) || $v === null ? (string)$v : json_encode($v),
            $decoded
        );
        $val = implode(', ', $decoded);
    }
    return $val;
}

/**
 * A form's three action lists, loaded lazily.
 *
 * The require is deliberately inside the function. `includes/services/forms.php`
 * requires the workflow engine at file level, and the engine lazily requires
 * THIS file — pulling forms.php in at the top would make that ring tighter than
 * it needs to be for one lookup.
 *
 * @param int $formId  forms.id whose action lists to load
 * @return array{submitted: ?array, approved: ?array, rejected: ?array}
 */
function catalogueFormActionLists(PDO $conn, int $formId): array {
    try {
        require_once __DIR__ . '/services/forms.php';
        return FormsService::actionLists($conn, $formId);
    } catch (Throwable $e) {
        // Unreadable configuration must never block an approval decision. NULLs
        // mean "not configured", which is the safe pre-#95 behaviour.
        error_log('Could not read form action lists for form ' . $formId . ': ' . $e->getMessage());
        return ['submitted' => null, 'approved' => null, 'rejected' => null];
    }
}

/**
 * A submission's answers as a label-keyed map, plus the first email answer.
 *
 * The shape deliberately matches what `FormsService::submitForm()` puts on
 * `form.submitted`, so `{{submission.fields.Device type}}` means the same thing
 * whether a rule hangs off the submission or off the approval decision. A merge
 * code that resolved on one event and silently blanked on another would be worse
 * than not offering it at all.
 *
 * ⚠️ Values go through catalogueAnswerText(), so a checkbox reads "Yes"/"No"
 * rather than the stored 1/0. `form.submitted` still flattens raw — see the note
 * in the developer guide; unifying that changes what existing rules receive and
 * is a deliberate decision, not a tidy-up to slip in here.
 *
 * ⚠️ NOT derivable from catalogueSubmissionAnswers() below, and vice versa. That
 * one returns an ORDERED LIST because the approval card renders every row; this
 * returns a MAP keyed by label because a merge code addresses answers by name.
 * A map cannot represent two fields sharing a label — the second would silently
 * swallow the first — so the card must keep its list. Same data, two shapes,
 * both needed.
 *
 * @param int $submissionId  form_submissions.id whose answers to read
 * @return array{fields: array<string,string>, email: string}
 */
function catalogueSubmissionAnswerMap(PDO $conn, int $submissionId): array {
    $stmt = $conn->prepare(
        "SELECT ff.label, ff.field_type, ff.config, sd.field_value
           FROM form_submission_data sd
           JOIN form_fields ff ON ff.id = sd.field_id
          WHERE sd.submission_id = ?
       ORDER BY ff.sort_order, ff.id"
    );
    $stmt->execute([$submissionId]);

    $fields = [];
    $email  = '';
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $text = catalogueAnswerText($r['field_value'], $r['field_type'], $r);
        $fields[$r['label']] = $text;
        if ($email === '' && $r['field_type'] === 'email' && $text !== '') {
            $email = $text;
        }
    }

    return ['fields' => $fields, 'email' => $email];
}

/**
 * The submitted answers as a safe (fully-escaped) HTML summary for the ticket body.
 *
 * @param int $submissionId  form_submissions.id whose answers to render
 * @param string $formTitle  the form's name for the opening line; escaped here, so raw text is fine
 */
function catalogueSubmissionBodyHtml(PDO $conn, int $submissionId, string $formTitle): string {
    $stmt = $conn->prepare(
        "SELECT ff.label, ff.field_type, ff.config, sd.field_value
           FROM form_submission_data sd
           JOIN form_fields ff ON ff.id = sd.field_id
          WHERE sd.submission_id = ?
       ORDER BY ff.sort_order, ff.id"
    );
    $stmt->execute([$submissionId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $esc  = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    // Neutral wording: this is reached from the catalogue's approval path AND
    // from an ordinary form that has no approval gate at all, and telling
    // somebody their Guest Wi-Fi request came "from the catalogue" when there is
    // no catalogue involved is just wrong.
    $html = '<p>Submitted via the <strong>' . $esc($formTitle) . '</strong> form.</p>';
    $html .= '<table style="border-collapse:collapse;">';
    foreach ($rows as $r) {
        $val = catalogueAnswerText($r['field_value'], $r['field_type'], $r);
        $html .= '<tr>'
               . '<td style="padding:4px 14px 4px 0;vertical-align:top;color:#666;"><strong>' . $esc($r['label']) . '</strong></td>'
               . '<td style="padding:4px 0;">' . nl2br($esc($val)) . '</td>'
               . '</tr>';
    }
    $html .= '</table>';
    return $html;
}


/**
 * The approver's inbox list.
 *
 * @param string $filter 'mine' (pending, assigned to me) | 'all' (every pending) |
 *                       'decided' (recently decided by me)
 * @return array { items: [...], counts: { mine, all, decided } }
 */
/**
 * May this analyst open the catalogue approvals inbox (#180 Part 3)? Forms module
 * access, OR being named as the approver of a form that needs approval or of a
 * submission. An approver no longer has to be given the whole Forms module - the
 * designer, every form's submissions, delete - to decide the requests sent to them.
 *
 * TRAP: entry only. Deciding is still catalogueApprovalDecide()'s approver_id check,
 * and an approver without Forms never sees "All pending" (catalogue_approvals.php).
 *
 * @param int $analystId  the analyst
 */
function catalogueApproverCanEnter(PDO $conn, int $analystId): bool {
    if ($analystId <= 0) return false;
    if (!moduleIsEnabled($conn, 'forms')) return false;   // GH #217: not into a switched-off Forms
    if (analystCanAccessModule($conn, $analystId, 'forms')) return true;
    try {
        $st = $conn->prepare(
            "SELECT 1 FROM forms WHERE approver_id = ? AND requires_approval = 1
             UNION SELECT 1 FROM form_submissions WHERE approver_id = ? LIMIT 1"
        );
        $st->execute([$analystId, $analystId]);
        if ($st->fetchColumn()) return true;
        // GH #220: a member of a team that approves - on a form, or on a request
        // still waiting - has the same door, and nothing more.
        return catalogueTeamApprovalAvailable($conn) && catalogueAnalystApprovesAsTeam($conn, $analystId);
    } catch (Throwable) {
        return false;
    }
}

/**
 * Is this analyst in a team that approves catalogue requests (GH #220)? The door to
 * the approvals inbox, for somebody without the Forms module.
 *
 * @param int $analystId  the analyst
 */
function catalogueAnalystApprovesAsTeam(PDO $conn, int $analystId): bool {
    $st = $conn->prepare(
        "SELECT 1 FROM analyst_teams at JOIN teams t ON t.id = at.team_id AND t.is_active = 1
          WHERE at.analyst_id = ?
            AND (EXISTS (SELECT 1 FROM forms f WHERE f.approval_team_id = at.team_id AND f.requires_approval = 1)
              OR EXISTS (SELECT 1 FROM form_submissions s WHERE s.approval_team_id = at.team_id AND s.approval_status = 'pending'))
          LIMIT 1"
    );
    $st->execute([$analystId]);
    return (bool)$st->fetchColumn();
}

/**
 * The approvals inbox: "mine" (waiting on me), "all" (every pending request - Forms
 * users only, checked by the endpoint) and "decided" (what I decided, or voted on).
 *
 * "Mine" (GH #220) is a named-person request whose approver I am, or a team request I
 * can still vote on: I am a current member, not the requester, and have not voted this
 * round. Each item carries its progress (catalogueApprovalProgress()) and the
 * administrator's actions on it, so the card can show "1 of 2 approvals", who decided
 * what, and a request that is stuck.
 *
 * @param int $analystId  the analyst the inbox is for
 * @param string $filter  'mine', 'all' or 'decided'
 */
function catalogueApprovalsList(PDO $conn, int $analystId, string $filter = 'mine'): array {
    $team = catalogueTeamApprovalAvailable($conn);
    $teamCols = $team
        ? ", s.approval_team_id, s.approvals_required, s.rejections_required, s.approval_exclude_requester, s.approval_round,
             tm.name AS approval_team_name"
        : '';
    $teamJoin = $team ? ' LEFT JOIN teams tm ON tm.id = s.approval_team_id ' : '';
    $base =
        "SELECT s.id, s.form_id, f.title AS form_title, s.submitted_date, s.submitted_by_user_id,
                s.approval_status, s.approver_id, s.approval_decided_datetime, s.approval_comment,
                s.ticket_id, t.ticket_number,
                u.display_name AS requester_name, u.username AS requester_username,
                ap.full_name AS approver_name, dc.full_name AS decided_by_name{$teamCols}
           FROM form_submissions s
           JOIN forms f       ON f.id = s.form_id
           LEFT JOIN users u  ON u.id = s.submitted_by_user_id
           LEFT JOIN analysts ap ON ap.id = s.approver_id
           LEFT JOIN analysts dc ON dc.id = s.approval_decided_by_id
           LEFT JOIN tickets t   ON t.id = s.ticket_id {$teamJoin}";

    $myTeams = $team ? catalogueAnalystTeamIds($conn, $analystId) : [];
    $teamIn = $myTeams ? implode(',', array_map('intval', $myTeams)) : '0';
    $minePerson = $team ? "s.approver_id = ? AND s.approval_team_id IS NULL" : "s.approver_id = ?";
    $mineSql = "s.approval_status = 'pending' AND (($minePerson)" . ($team ? " OR s.approval_team_id IN ($teamIn)" : '') . ")";
    $decidedSql = "(s.approval_status IN ('approved','rejected') AND s.approval_decided_by_id = ?)"
        . ($team ? " OR s.id IN (SELECT submission_id FROM form_submission_votes WHERE analyst_id = ?)" : '');
    $decidedArgs = $team ? [$analystId, $analystId] : [$analystId];

    if ($filter === 'all') {
        $where = "WHERE s.approval_status = 'pending' ORDER BY s.submitted_date ASC";
        $params = [];
    } elseif ($filter === 'decided') {
        $where = "WHERE $decidedSql ORDER BY COALESCE(s.approval_decided_datetime, s.submitted_date) DESC LIMIT 100";
        $params = $decidedArgs;
    } else { // mine
        $where = "WHERE $mineSql ORDER BY s.submitted_date ASC";
        $params = [$analystId];
    }

    $stmt = $conn->prepare($base . $where);
    $stmt->execute($params);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $isAdmin = function_exists('sessionIsAdmin') ? sessionIsAdmin() : false;
    $mineCount = 0;
    $out = [];
    foreach ($items as $it) {
        $it['progress'] = catalogueApprovalProgress($conn, $it);
        $policy = catalogueApprovalPolicy($it);
        $pending = $it['approval_status'] === 'pending';
        $it['mode'] = $policy['mode'];
        $it['can_vote'] = $pending && $policy['mode'] === 'team' && in_array($analystId, $it['progress']['waiting_on'], true);
        $it['can_decide'] = $pending && $policy['mode'] === 'person'
            && ($policy['approver_id'] === $analystId || $isAdmin)
            && !($policy['exclude_requester'] && catalogueAnalystIsRequester($conn, $analystId, $it));
        $it['can_override'] = $pending && $isAdmin && $policy['mode'] === 'team';
        $it['can_move'] = $pending && $isAdmin;
        $it['events'] = $team ? catalogueApprovalEvents($conn, (int)$it['id']) : [];
        // "Mine" lists only what I can act on: a team request I have voted on, or am
        // excluded from as its requester, is not waiting on me.
        if ($filter === 'mine' && $policy['mode'] === 'team' && !$it['can_vote']) continue;
        $it['answers'] = catalogueSubmissionAnswers($conn, (int)$it['id']);
        $out[] = $it;
    }

    // "Mine" is counted the same way it is listed, so the tab never promises more than it shows.
    if ($filter === 'mine') {
        $mineCount = count($out);
    } else {
        $st = $conn->prepare("SELECT s.* FROM form_submissions s WHERE $mineSql");
        $st->execute([$analystId]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (catalogueApprovalPolicy($row)['mode'] === 'person'
                || in_array($analystId, catalogueApprovalProgress($conn, $row)['waiting_on'], true)) {
                $mineCount++;
            }
        }
    }

    $counts = [
        'mine'    => $mineCount,
        'all'     => (int)catalogueScalar($conn, "SELECT COUNT(*) FROM form_submissions WHERE approval_status='pending'", []),
        'decided' => (int)catalogueScalar($conn, "SELECT COUNT(*) FROM form_submissions s WHERE $decidedSql", $decidedArgs),
    ];

    return ['items' => $out, 'counts' => $counts];
}

/**
 * The active teams an analyst is in (GH #220).
 *
 * @param int $analystId  the analyst
 * @return int[]
 */
function catalogueAnalystTeamIds(PDO $conn, int $analystId): array {
    $st = $conn->prepare(
        "SELECT at.team_id FROM analyst_teams at JOIN teams t ON t.id = at.team_id AND t.is_active = 1 WHERE at.analyst_id = ?"
    );
    $st->execute([$analystId]);
    return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * An administrator's overrides and moves on a request (GH #220), oldest first.
 *
 * @param int $submissionId  form_submissions.id
 */
function catalogueApprovalEvents(PDO $conn, int $submissionId): array {
    $st = $conn->prepare(
        "SELECT e.action, e.detail, e.reason, e.event_datetime, a.full_name
           FROM form_submission_approval_events e LEFT JOIN analysts a ON a.id = e.analyst_id
          WHERE e.submission_id = ? ORDER BY e.event_datetime, e.id"
    );
    $st->execute([$submissionId]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * label + value pairs for one submission (checkboxes flattened).
 *
 * @param int $submissionId  form_submissions.id whose answers to list
 */
function catalogueSubmissionAnswers(PDO $conn, int $submissionId): array {
    $stmt = $conn->prepare(
        "SELECT ff.label, ff.field_type, ff.config, sd.field_value
           FROM form_submission_data sd
           JOIN form_fields ff ON ff.id = sd.field_id
          WHERE sd.submission_id = ?
       ORDER BY ff.sort_order, ff.id"
    );
    $stmt->execute([$submissionId]);
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[] = ['label' => $r['label'], 'value' => catalogueAnswerText($r['field_value'], $r['field_type'], $r)];
    }
    return $out;
}

/**
 * Runs a prepared query and returns its first column of the first row (false when there is no row).
 *
 * @param string $sql  the SQL with ? placeholders (trusted - written by the caller)
 * @param array $params  values bound to the placeholders, in order
 */
function catalogueScalar(PDO $conn, string $sql, array $params) {
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchColumn();
}
